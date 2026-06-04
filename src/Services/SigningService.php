<?php
namespace TfSigner\Services;

use TfSigner\Core\Config;
use TfSigner\Core\Logger;

class SigningService
{
    private string $workDir;
    private string $codesignPath;

    public function __construct()
    {
        $this->codesignPath = Config::get('signing.codesign_path', '/usr/bin/codesign');
        $this->workDir = sys_get_temp_dir() . '/tfsigner_' . uniqid();
    }

    public function resign(
        string $inputIpa,
        string $outputIpa,
        int $certId,
        int $profileId,
        ?callable $progressCallback = null
    ): array {
        $reportProgress = function(int $pct, string $msg) use ($progressCallback) {
            Logger::info("Signing progress: {$pct}% - {$msg}");
            if ($progressCallback) $progressCallback($pct, $msg);
        };

        $reportProgress(5, 'Preparing workspace');

        $extractDir = $this->workDir . '/extract';
        $payloadDir = $extractDir . '/Payload';
        mkdir($payloadDir, 0755, true);

        $pdo = \TfSigner\Core\Database::connection();

        $cert = $pdo->prepare("SELECT * FROM certificates WHERE id = ?");
        $cert->execute([$certId]);
        $certData = $cert->fetch();

        $profile = $pdo->prepare("SELECT * FROM provisioning_profiles WHERE id = ?");
        $profile->execute([$profileId]);
        $profileData = $profile->fetch();

        if (!$certData) throw new \RuntimeException("Certificate not found: {$certId}");
        if (!$profileData) throw new \RuntimeException("Profile not found: {$profileId}");

        $reportProgress(10, 'Extracting IPA');

        $zip = new \ZipArchive();
        if ($zip->open($inputIpa) !== true) {
            throw new \RuntimeException("Failed to open IPA: {$inputIpa}");
        }
        $zip->extractTo($extractDir);
        $zip->close();

        $appBundles = glob($payloadDir . '/*.app');
        if (empty($appBundles)) {
            $this->cleanup();
            throw new \RuntimeException("No .app found in IPA Payload");
        }
        $appPath = $appBundles[0];
        $appName = basename($appPath);

        $reportProgress(20, "Found app: {$appName}");

        $this->removeOldSignature($appPath);
        $reportProgress(30, 'Old signature removed');

        copy($profileData['profile_path'], $appPath . '/embedded.mobileprovision');
        $reportProgress(40, 'Provisioning profile installed');

        $entitlements = $this->extractEntitlements($profileData['profile_path']);
        $entitlementsFile = $this->workDir . '/entitlements.plist';
        $this->writePlist($entitlementsFile, $entitlements);
        $reportProgress(50, 'Entitlements extracted');

        $infoPlist = $appPath . '/Info.plist';
        $targetBundleId = $profileData['bundle_id'];
        $this->patchInfoPlistBundleId($infoPlist, $targetBundleId);
        $reportProgress(55, 'Info.plist updated');

        $this->signFrameworks($appPath, $certData, $entitlementsFile);
        $reportProgress(70, 'Frameworks signed');

        $this->signApp($appPath, $certData, $entitlementsFile);
        $reportProgress(85, 'Main binary signed');

        $this->createIpa($extractDir, $outputIpa);
        $reportProgress(95, 'IPA repackaged');

        $this->cleanup();
        $reportProgress(100, 'Signing complete');

        return [
            'success' => true,
            'output_ipa' => $outputIpa,
            'app_name' => $appName,
            'bundle_id' => $targetBundleId,
        ];
    }

    private function removeOldSignature(string $appPath): void
    {
        $sigDir = $appPath . '/_CodeSignature';
        if (is_dir($sigDir)) $this->rmdirRecursive($sigDir);

        $codeResources = $appPath . '/CodeResources';
        if (file_exists($codeResources)) unlink($codeResources);

        $embedded = $appPath . '/embedded.mobileprovision';
        if (file_exists($embedded)) unlink($embedded);

        // Also clean nested frameworks/plugins signatures
        foreach (['Frameworks', 'PlugIns'] as $subDir) {
            $path = $appPath . '/' . $subDir;
            if (!is_dir($path)) continue;

            foreach (glob($path . '/*') as $item) {
                $sigDir = $item . '/_CodeSignature';
                if (is_dir($sigDir)) $this->rmdirRecursive($sigDir);
                $cr = $item . '/CodeResources';
                if (file_exists($cr)) unlink($cr);
                $emb = $item . '/embedded.mobileprovision';
                if (file_exists($emb)) unlink($emb);
            }
        }
    }

    private function signFrameworks(string $appPath, array $certData, string $entitlementsFile): void
    {
        $identity = $this->getSigningIdentity($certData);

        foreach (['Frameworks', 'PlugIns'] as $subDir) {
            $path = $appPath . '/' . $subDir;
            if (!is_dir($path)) continue;

            // Only sign top-level items (framework bundles, appex bundles, dylibs)
            foreach (glob($path . '/*') as $item) {
                $basename = basename($item);
                $isFramework = is_dir($item) && substr($basename, -10) === '.framework';
                $isAppex = is_dir($item) && substr($basename, -6) === '.appex';
                $isBundle = is_dir($item) && substr($basename, -7) === '.bundle';
                $isDylib = !is_dir($item) && substr($basename, -6) === '.dylib';

                if ($isFramework || $isAppex || $isBundle || $isDylib) {
                    $this->runCodesign($item, $identity, $entitlementsFile);
                }
            }
        }
    }

    private function signApp(string $appPath, array $certData, string $entitlementsFile): void
    {
        $identity = $this->getSigningIdentity($certData);
        $this->runCodesign($appPath, $identity, $entitlementsFile);
    }

    private function getSigningIdentity(array $certData): string
    {
        if (file_exists($certData['cert_path'])) {
            $certContent = file_get_contents($certData['cert_path']);
            $certInfo = openssl_x509_parse($certContent);
            $commonName = $certInfo['subject']['CN'] ?? '';

            if ($commonName && PHP_OS_FAMILY === 'Darwin') {
                $this->importToKeychain($certData);
                return $commonName;
            }
        }

        return 'file:' . $certData['cert_path'];
    }

    private function importToKeychain(array $certData): void
    {
        $keychain = Config::get('signing.keychain');
        if (!$keychain || PHP_OS_FAMILY !== 'Darwin') return;

        $p12Path = $this->workDir . '/temp.p12';
        $certContent = file_get_contents($certData['cert_path']);
        $keyContent = file_get_contents($certData['key_path']);

        openssl_pkcs12_export(
            $certContent, $p12Content, $keyContent,
            $certData['password'] ?: 'temp',
            ['friendly_name' => 'TF Signer Temp']
        );
        file_put_contents($p12Path, $p12Content);

        $cmd = sprintf(
            'security import %s -k %s -P %s -T %s 2>&1',
            escapeshellarg($p12Path),
            escapeshellarg($keychain),
            escapeshellarg($certData['password'] ?: 'temp'),
            escapeshellarg($this->codesignPath)
        );
        exec($cmd, $outputLines, $code);

        @unlink($p12Path);
    }

    private function runCodesign(string $path, string $identity, string $entitlementsFile): void
    {
        if (PHP_OS_FAMILY === 'Darwin' && file_exists($this->codesignPath)) {
            $cmd = sprintf(
                '%s --force --sign %s --entitlements %s --timestamp=none %s 2>&1',
                $this->codesignPath,
                escapeshellarg($identity),
                escapeshellarg($entitlementsFile),
                escapeshellarg($path)
            );
        } else {
            $cmd = sprintf(
                'zsign -k %s -m %s -o %s %s 2>&1',
                escapeshellarg(str_replace('file:', '', $identity)),
                escapeshellarg($entitlementsFile),
                escapeshellarg($path),
                escapeshellarg($path)
            );
        }

        exec($cmd, $outputLines, $code);
        $output = implode("\n", $outputLines);

        if ($code !== 0) {
            Logger::warning("Codesign warning/error", ['code' => $code, 'output' => $output, 'path' => $path]);
        }
    }

    private function createIpa(string $extractDir, string $outputIpa): void
    {
        $outputDir = dirname($outputIpa);
        if (!is_dir($outputDir)) mkdir($outputDir, 0755, true);

        if (file_exists($outputIpa)) unlink($outputIpa);

        $zip = new \ZipArchive();
        if ($zip->open($outputIpa, \ZipArchive::CREATE) !== true) {
            throw new \RuntimeException("Failed to create output IPA: {$outputIpa}");
        }

        $payloadDir = $extractDir . '/Payload';
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($payloadDir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($files as $file) {
            $localPath = 'Payload/' . substr($file->getPathname(), strlen($payloadDir) + 1);

            if ($file->isDir()) {
                $zip->addEmptyDir($localPath);
            } else {
                $zip->addFile($file->getPathname(), $localPath);
            }
        }

        $swiftSupport = $extractDir . '/SwiftSupport';
        if (is_dir($swiftSupport)) {
            $swiftFiles = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($swiftSupport, \RecursiveDirectoryIterator::SKIP_DOTS)
            );
            foreach ($swiftFiles as $file) {
                if (!$file->isDir()) {
                    $localPath = 'SwiftSupport/' . $file->getBasename();
                    $zip->addFile($file->getPathname(), $localPath);
                }
            }
        }

        $zip->close();
    }

    /**
     * Extract entitlements from provisioning profile.
     * Handles both CMS-signed binary profiles (DER) and XML plists.
     */
    private function extractEntitlements(string $profilePath): array
    {
        $content = file_get_contents($profilePath);

        // Try to find plist XML inside CMS/PKCS7 envelope
        // Provisioning profiles are DER-encoded CMS SignedData
        // The plist content is typically between XML tags
        if (preg_match('/<\?xml.*<\/plist>/s', $content, $matches)) {
            return $this->parsePlistData($matches[0]);
        }

        // Try matching plist without XML declaration
        if (preg_match('/<plist.*<\/plist>/s', $content, $matches)) {
            return $this->parsePlistData($matches[0]);
        }

        // Fallback: try to extract from binary using system tools
        $tmpFile = $this->workDir . '/temp_profile.plist';
        file_put_contents($tmpFile, $content);

        $result = [];
        if (PHP_OS_FAMILY === 'Darwin') {
            // Use security command to decode CMS
            $xml = shell_exec("security cms -D -i " . escapeshellarg($tmpFile) . " 2>/dev/null");
            if ($xml && preg_match('/<plist.*<\/plist>/s', $xml, $m)) {
                $result = $this->parsePlistData($m[0]);
            }
        }

        @unlink($tmpFile);
        return $result;
    }

    /**
     * Parse plist XML data using available tools or fallback parser
     */
    private function parsePlistData(string $plistXml): array
    {
        // Try plutil first (macOS)
        if (PHP_OS_FAMILY === 'Darwin') {
            $tmpFile = $this->workDir . '/temp_ent.plist';
            file_put_contents($tmpFile, $plistXml);
            $json = shell_exec("plutil -convert json -o - " . escapeshellarg($tmpFile) . " 2>/dev/null");
            @unlink($tmpFile);
            if ($json) {
                $data = json_decode($json, true);
                if (is_array($data)) return $data['Entitlements'] ?? $data;
            }
        }

        // Fallback: simple XML parser
        return $this->simplePlistParse($plistXml);
    }

    private function patchInfoPlistBundleId(string $plistPath, string $bundleId): void
    {
        if (!file_exists($plistPath)) return;

        $data = [];
        if (PHP_OS_FAMILY === 'Darwin') {
            $json = shell_exec("plutil -convert json -o - " . escapeshellarg($plistPath) . " 2>/dev/null");
            if ($json) $data = json_decode($json, true) ?: [];
        }

        if (empty($data)) {
            $data = $this->simplePlistParse(file_get_contents($plistPath));
        }

        if (empty($data)) return;

        $data['CFBundleIdentifier'] = $bundleId;
        $this->writePlist($plistPath, $data);
    }

    private function writePlist(string $path, array $data): void
    {
        $xml = $this->buildPlistXml($data);
        file_put_contents($path, $xml);

        if (PHP_OS_FAMILY === 'Darwin') {
            exec("plutil -convert binary1 " . escapeshellarg($path) . " 2>/dev/null");
        }
    }

    private function buildPlistXml(array $data): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">' . "\n";
        $xml .= '<plist version="1.0">' . "\n";
        $xml .= $this->buildPlistValue($data);
        $xml .= '</plist>';
        return $xml;
    }

    private function buildPlistValue($value): string
    {
        if (is_array($value) && array_keys($value) === range(0, count($value) - 1)) {
            $xml = "<array>\n";
            foreach ($value as $item) {
                $xml .= $this->buildPlistValue($item);
            }
            $xml .= "</array>\n";
            return $xml;
        } elseif (is_array($value)) {
            $xml = "<dict>\n";
            foreach ($value as $key => $item) {
                $xml .= "<key>" . htmlspecialchars((string)$key) . "</key>\n";
                $xml .= $this->buildPlistValue($item);
            }
            $xml .= "</dict>\n";
            return $xml;
        } elseif (is_bool($value)) {
            return $value ? "<true/>\n" : "<false/>\n";
        } elseif (is_int($value)) {
            return "<integer>{$value}</integer>\n";
        } elseif (is_float($value)) {
            return "<real>{$value}</real>\n";
        } else {
            return "<string>" . htmlspecialchars((string)$value) . "</string>\n";
        }
    }

    private function simplePlistParse(string $content): array
    {
        $result = [];
        if (!preg_match('/<dict>(.*?)<\/dict>/s', $content, $dictMatch)) {
            return $result;
        }

        preg_match_all(
            '/<key>(.*?)<\/key>\s*(<string>(.*?)<\/string>|<true\/>|<false\/>|<integer>(.*?)<\/integer>|<real>(.*?)<\/real>|<array>(.*?)<\/array>|<dict>(.*?)<\/dict>)/s',
            $dictMatch[1],
            $matches,
            PREG_SET_ORDER
        );

        foreach ($matches as $m) {
            $key = trim($m[1]);
            if (!empty($m[3])) {
                $result[$key] = trim($m[3]);
            } elseif (!empty($m[5])) {
                $result[$key] = (int)trim($m[5]);
            } elseif (!empty($m[6])) {
                $result[$key] = (float)trim($m[6]);
            } elseif (strpos($m[2], '<true/>') !== false) {
                $result[$key] = true;
            } elseif (strpos($m[2], '<false/>') !== false) {
                $result[$key] = false;
            }
        }

        return $result;
    }

    private function cleanup(): void
    {
        if (is_dir($this->workDir)) {
            $this->rmdirRecursive($this->workDir);
        }
    }

    private function rmdirRecursive(string $dir): void
    {
        if (!is_dir($dir)) return;
        $items = scandir($dir);
        if ($items === false) return;
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->rmdirRecursive($path) : unlink($path);
        }
        rmdir($dir);
    }
}
