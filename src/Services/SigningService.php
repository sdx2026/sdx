<?php
namespace TfSigner\Services;

use TfSigner\Core\Config;
use TfSigner\Core\Logger;

class SigningService
{
    private string $workDir;

    public function __construct()
    {
        $this->workDir = sys_get_temp_dir() . '/tfsigner_' . uniqid();
    }

    public function resign(
        string $inputIpa,
        string $outputIpa,
        int $certId,
        int $profileId,
        string $overrideVersion = '',
        string $overrideBuild = '',
        ?callable $progressCallback = null
    ): array {
        $reportProgress = function(int $pct, string $msg) use ($progressCallback) {
            Logger::info("Signing progress: {$pct}% - {$msg}");
            if ($progressCallback) $progressCallback($pct, $msg);
        };

        $reportProgress(5, 'Preparing workspace');
        mkdir($this->workDir, 0755, true);

        $pdo = \TfSigner\Core\Database::connection();

        $cert = $pdo->prepare("SELECT * FROM certificates WHERE id = ?");
        $cert->execute([$certId]);
        $certData = $cert->fetch();

        $profile = $pdo->prepare("SELECT * FROM provisioning_profiles WHERE id = ?");
        $profile->execute([$profileId]);
        $profileData = $profile->fetch();

        if (!$certData) throw new \RuntimeException("Certificate not found: {$certId}");
        if (!$profileData) throw new \RuntimeException("Profile not found: {$profileId}");

        $reportProgress(10, 'Detecting signing tool');

        // Detect available signing tool
        $useZsign = $this->detectZsign();
        $useCodesign = !$useZsign && $this->detectCodesign();

        if (!$useZsign && !$useCodesign) {
            throw new \RuntimeException('No signing tool available. Install zsign (Linux) or Xcode (macOS).');
        }

        try {
            if ($useZsign) {
                return $this->resignWithZsign($inputIpa, $outputIpa, $certData, $profileData, $overrideVersion, $overrideBuild, $reportProgress);
            } else {
                return $this->resignWithCodesign($inputIpa, $outputIpa, $certData, $profileData, $overrideVersion, $overrideBuild, $reportProgress);
            }
        } catch (\Throwable $e) {
            $this->cleanup();
            throw $e;
        }
    }

    private function detectZsign(): bool
    {
        $out = trim(shell_exec('which zsign 2>/dev/null') ?: '');
        return !empty($out);
    }

    private function detectCodesign(): bool
    {
        $out = trim(shell_exec('which codesign 2>/dev/null') ?: '');
        return !empty($out);
    }

    private function resignWithZsign(
        string $inputIpa,
        string $outputIpa,
        array $certData,
        array $profileData,
        string $overrideVersion,
        string $overrideBuild,
        callable $reportProgress
    ): array {
        $reportProgress(15, 'Signing with zsign');

        $certPath = $certData['cert_path'] ?? '';
        $keyPath = $certData['key_path'] ?? '';
        $certPassword = $certData['password'] ?? '';
        $profilePath = $profileData['profile_path'] ?? '';
        $bundleId = $profileData['bundle_id'] ?? '';

        if (!file_exists($certPath)) throw new \RuntimeException("Certificate file not found: {$certPath}");
        if (!file_exists($profilePath)) throw new \RuntimeException("Profile file not found: {$profilePath}");

        // Handle override_version and override_build by pre-processing IPA
        $signInputIpa = $inputIpa;
        if ($overrideVersion || $overrideBuild) {
            $signInputIpa = $this->applyOverridesToIpa($inputIpa, $overrideVersion, $overrideBuild);
        }

        $isP12 = (pathinfo($certPath, PATHINFO_EXTENSION) === 'p12');

        $cmd = 'zsign';
        if ($isP12) {
            $cmd .= ' -k ' . escapeshellarg($certPath);
            if ($certPassword) $cmd .= ' -p ' . escapeshellarg($certPassword);
        } else {
            $cmd .= ' -c ' . escapeshellarg($certPath);
            if ($keyPath && file_exists($keyPath)) {
                $cmd .= ' -k ' . escapeshellarg($keyPath);
                if ($certPassword) $cmd .= ' -p ' . escapeshellarg($certPassword);
            }
        }

        $cmd .= ' -m ' . escapeshellarg($profilePath);
        $cmd .= ' -o ' . escapeshellarg($outputIpa);
        if ($bundleId) $cmd .= ' -b ' . escapeshellarg($bundleId);
        // Apply version/build overrides via zsign options
        if ($overrideBuild) $cmd .= ' -r ' . escapeshellarg($overrideBuild);  // zsign -r = --bundle_version = CFBundleVersion
        $cmd .= ' ' . escapeshellarg($signInputIpa);
        $cmd .= ' 2>&1';

        $reportProgress(30, 'Running zsign...');
        Logger::info("zsign command: {$cmd}");

        $output = [];
        $returnCode = 0;
        exec($cmd, $output, $returnCode);

        $reportProgress(90, 'zsign completed');

        if ($returnCode !== 0 || !file_exists($outputIpa)) {
            $errMsg = implode("\n", $output);
            Logger::error("zsign failed: {$errMsg}");
            throw new \RuntimeException("zsign signing failed (code {$returnCode}): {$errMsg}");
        }

        $reportProgress(100, 'Signing complete (zsign)');

        $appName = 'Signed App';
        $zip = new \ZipArchive();
        if ($zip->open($outputIpa) === true) {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if (preg_match('#^Payload/([^/]+)\.app/#', $name, $m)) {
                    $appName = $m[1];
                    break;
                }
            }
            $zip->close();
        }

        $this->cleanup();

        return [
            'success' => true,
            'output_ipa' => $outputIpa,
            'app_name' => $appName,
            'bundle_id' => $bundleId,
            'tool' => 'zsign',
        ];
    }

    private function resignWithCodesign(
        string $inputIpa,
        string $outputIpa,
        array $certData,
        array $profileData,
        string $overrideVersion,
        string $overrideBuild,
        callable $reportProgress
    ): array {
        $reportProgress(15, 'Signing with macOS codesign');

        // Apply version/build overrides before extraction
        $signInputIpa = $inputIpa;
        if ($overrideVersion || $overrideBuild) {
            $signInputIpa = $this->applyOverridesToIpa($inputIpa, $overrideVersion, $overrideBuild);
        }

        $extractDir = $this->workDir . '/extract';
        $payloadDir = $extractDir . '/Payload';
        mkdir($payloadDir, 0755, true);

        $reportProgress(20, 'Extracting IPA');
        $zip = new \ZipArchive();
        if ($zip->open($signInputIpa) !== true) {
            throw new \RuntimeException("Failed to open IPA: {$signInputIpa}");
        }
        $zip->extractTo($extractDir);
        $zip->close();

        $appBundles = glob($payloadDir . '/*.app');
        if (empty($appBundles)) {
            $this->cleanup();
            throw new \RuntimeException('No .app found in IPA Payload');
        }
        $appPath = $appBundles[0];
        $appName = basename($appPath);

        $reportProgress(30, "Found app: {$appName}");
        $this->removeOldSignature($appPath);

        copy($profileData['profile_path'], $appPath . '/embedded.mobileprovision');
        $reportProgress(40, 'Profile installed');

        $entitlements = $this->extractEntitlements($profileData['profile_path']);
        $entitlementsFile = $this->workDir . '/entitlements.plist';
        $this->writePlist($entitlementsFile, $entitlements);
        $reportProgress(50, 'Entitlements extracted');

        $targetBundleId = $profileData['bundle_id'];
        $this->patchInfoPlistBundleId($appPath . '/Info.plist', $targetBundleId);

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
            'tool' => 'codesign',
        ];
    }

    private function removeOldSignature(string $appPath): void
    {
        foreach (['_CodeSignature', 'CodeResources', 'embedded.mobileprovision'] as $f) {
            $p = $appPath . '/' . $f;
            if (is_dir($p)) $this->rmdirRecursive($p);
            elseif (file_exists($p)) unlink($p);
        }
        foreach (['Frameworks', 'PlugIns'] as $sub) {
            $p = $appPath . '/' . $sub;
            if (!is_dir($p)) continue;
            foreach (glob($p . '/*') as $item) {
                foreach (['_CodeSignature', 'CodeResources', 'embedded.mobileprovision'] as $f) {
                    $fp = $item . '/' . $f;
                    if (is_dir($fp)) $this->rmdirRecursive($fp);
                    elseif (file_exists($fp)) unlink($fp);
                }
            }
        }
    }

    private function signFrameworks(string $appPath, array $certData, string $entitlementsFile): void
    {
        $certId = escapeshellarg($certData['name'] ?? $certData['cert_path'] ?? '-');
        foreach (['Frameworks', 'PlugIns'] as $sub) {
            $p = $appPath . '/' . $sub;
            if (!is_dir($p)) continue;
            foreach (glob($p . '/*') as $item) {
                if (!is_dir($item)) continue;
                exec('codesign --force --sign ' . $certId . ' --timestamp=none ' . escapeshellarg($item) . ' 2>/dev/null');
            }
        }
    }

    private function signApp(string $appPath, array $certData, string $entitlementsFile): void
    {
        $certId = escapeshellarg($certData['name'] ?? $certData['cert_path'] ?? '-');
        exec('codesign --force --sign ' . $certId . ' --entitlements ' . escapeshellarg($entitlementsFile) . ' --timestamp=none ' . escapeshellarg($appPath) . ' 2>/dev/null');
    }

    private function createIpa(string $extractDir, string $outputIpa): void
    {
        $cwd = getcwd();
        chdir($extractDir);
        exec('zip -qr ' . escapeshellarg($outputIpa) . ' Payload 2>/dev/null');
        chdir($cwd);
    }

    private function extractEntitlements(string $profilePath): array
    {
        $content = file_get_contents($profilePath);
        // Mobileprovision files are CMS-encoded (PKCS#7 DER). Decode first if needed.
        if (strpos($content, '<?xml') !== 0 && strpos($content, '<!DOCTYPE') !== 0) {
            // Try openssl smime decode (Linux), then security cms (macOS)
            $decoded = shell_exec('openssl smime -inform der -verify -in ' . escapeshellarg($profilePath) . ' -noverify 2>/dev/null');
            if (empty($decoded) || strpos($decoded, '<?xml') === false) {
                $decoded = shell_exec('security cms -D -i ' . escapeshellarg($profilePath) . ' 2>/dev/null');
            }
            if (!empty($decoded) && strpos($decoded, '<?xml') !== false) {
                $content = $decoded;
            }
        }
        $entitlements = [];
        if (preg_match('/<key>Entitlements<\/key>\s*<dict>(.*?)<\/dict>/s', $content, $m)) {
            $entitlements = $this->simplePlistParse("<dict>{$m[1]}</dict>");
        }
        return $entitlements ?: ['application-identifier' => '', 'get-task-allow' => false];
    }

    private function patchInfoPlistBundleId(string $plistPath, string $bundleId): void
    {
        if (!file_exists($plistPath)) return;
        $original = file_get_contents($plistPath);
        if (empty($original)) return;
        // Use regex replace to preserve original plist structure (including nested dicts/arrays)
        $replaced = preg_replace(
            '/(<key>CFBundleIdentifier<\/key>\s*<string>)[^<]*(<\/string>)/s',
            '$1' . htmlspecialchars($bundleId, ENT_XML1) . '$2',
            $original,
            -1,
            $count
        );
        if ($count > 0) {
            file_put_contents($plistPath, $replaced);
        } else {
            // Fallback: use buildPlistXml only if key not found in original
            $data = $this->simplePlistParse($original);
            if (empty($data)) return;
            $data['CFBundleIdentifier'] = $bundleId;
            $this->writePlist($plistPath, $data);
        }
    }

    private function writePlist(string $path, array $data): void
    {
        $xml = $this->buildPlistXml($data);
        file_put_contents($path, $xml);
        if (PHP_OS_FAMILY === 'Darwin') {
            exec('plutil -convert binary1 ' . escapeshellarg($path) . ' 2>/dev/null');
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
            foreach ($value as $item) $xml .= $this->buildPlistValue($item);
            $xml .= "</array>\n";
            return $xml;
        } elseif (is_array($value)) {
            $xml = "<dict>\n";
            foreach ($value as $key => $item) {
                $xml .= '<key>' . htmlspecialchars((string)$key) . '</key>' . "\n";
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
        }
        return '<string>' . htmlspecialchars((string)$value) . '</string>' . "\n";
    }

    /**
     * Parse a plist dict with full support for nested dicts and arrays.
     */
    private function simplePlistParse(string $content): array
    {
        $result = [];
        if (!preg_match('/<dict>(.*)<\/dict>/s', $content, $m)) return $result;
        return self::parsePlistDict($m[1]);
    }

    private static function parsePlistDict(string $content): array
    {
        $result = [];
        $len = strlen($content);
        $pos = 0;
        while ($pos < $len) {
            if (!preg_match('/<key>([^<]*)<\/key>/s', $content, $km, PREG_OFFSET_CAPTURE, $pos)) break;
            $key = trim($km[1][0]);
            $vpos = $km[0][1] + strlen($km[0][0]);
            $value = self::parsePlistValue($content, $vpos, $endPos);
            if ($endPos > $vpos) {
                $result[$key] = $value;
                $pos = $endPos;
            } else {
                break;
            }
        }
        return $result;
    }

    private static function parsePlistValue(string $content, int $start, ?int &$end): mixed
    {
        $end = $start;
        // Skip whitespace
        while ($end < strlen($content) && ctype_space($content[$end])) $end++;

        if (substr($content, $end, 6) === '<dict>') {
            $inner = $end + 6;
            $closePos = self::findClosingTag($content, 'dict', $inner);
            if ($closePos === false) return null;
            $result = self::parsePlistDict(substr($content, $inner, $closePos - $inner));
            $end = $closePos + 7; // past </dict>
            return $result;
        }
        if (substr($content, $end, 7) === '<array>') {
            $inner = $end + 7;
            $closePos = self::findClosingTag($content, 'array', $inner);
            if ($closePos === false) return null;
            $arrContent = substr($content, $inner, $closePos - $inner);
            $result = [];
            $apos = 0;
            while ($apos < strlen($arrContent)) {
                $val = self::parsePlistValue($arrContent, $apos, $aend);
                if ($aend > $apos) {
                    $result[] = $val;
                    $apos = $aend;
                } else break;
            }
            $end = $closePos + 8;
            return $result;
        }
        if (preg_match('/^<string>([^<]*)<\/string>/s', substr($content, $end), $sm)) {
            $end += strlen($sm[0]);
            return trim($sm[1]);
        }
        if (preg_match('/^<integer>(-?\d+)<\/integer>/s', substr($content, $end), $im)) {
            $end += strlen($im[0]);
            return (int)$im[1];
        }
        if (preg_match('/^<real>([\d.]+)<\/real>/s', substr($content, $end), $rm)) {
            $end += strlen($rm[0]);
            return (float)$rm[1];
        }
        if (substr($content, $end, 6) === '<true/') {
            $end += 7;
            return true;
        }
        if (substr($content, $end, 7) === '<false/') {
            $end += 8;
            return false;
        }
        if (substr($content, $end, 6) === '<data>') {
            $closePos = self::findClosingTag($content, 'data', $end + 6);
            $end = $closePos !== false ? $closePos + 7 : $end + 1;
            return '';
        }
        return null;
    }

    private static function findClosingTag(string $content, string $tag, int $start): int|false
    {
        $depth = 1;
        $pos = $start;
        $len = strlen($content);
        while ($pos < $len && $depth > 0) {
            $openPos = strpos($content, '<' . $tag . '>', $pos);
            $closePos = strpos($content, '</' . $tag . '>', $pos);
            if ($closePos === false) return false;
            if ($openPos !== false && $openPos < $closePos) {
                $depth++;
                $pos = $openPos + strlen($tag) + 2;
            } else {
                $depth--;
                if ($depth === 0) return $closePos;
                $pos = $closePos + strlen($tag) + 3;
            }
        }
        return false;
    }

    /**
     * Apply version/build overrides to an IPA by modifying Info.plist
     */
    private function applyOverridesToIpa(string $ipaPath, string $version, string $build): string
    {
        $tmpExtract = $this->workDir . '/override_extract';
        mkdir($tmpExtract, 0755, true);

        // Extract IPA
        $zip = new \ZipArchive();
        if ($zip->open($ipaPath) !== true) {
            return $ipaPath; // Can't extract, return original
        }
        $zip->extractTo($tmpExtract);
        $zip->close();

        // Find Info.plist
        $infoPlistPath = null;
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($tmpExtract, \RecursiveDirectoryIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            if ($file->getFilename() === 'Info.plist') {
                $infoPlistPath = $file->getPathname();
                break;
            }
        }

        if ($infoPlistPath && file_exists($infoPlistPath)) {
            // Parse and modify Info.plist (binary plist on macOS, XML on Linux)
            $plistContent = file_get_contents($infoPlistPath);

            $modified = false;

            // Replace CFBundleShortVersionString
            if ($version) {
                $plistContent = preg_replace(
                    '/(<key>CFBundleShortVersionString<\/key>\s*<string>)[^<]*(<\/string>)/s',
                    '$1' . $version . '$2',
                    $plistContent,
                    -1,
                    $count
                );
                if ($count > 0) $modified = true;
            }

            // Replace CFBundleVersion (build number)
            if ($build) {
                $plistContent = preg_replace(
                    '/(<key>CFBundleVersion<\/key>\s*<string>)[^<]*(<\/string>)/s',
                    '$1' . $build . '$2',
                    $plistContent,
                    -1,
                    $count
                );
                if ($count > 0) $modified = true;
            }

            if ($modified) {
                file_put_contents($infoPlistPath, $plistContent);
            }
        }

        // Re-zip IPA
        $outputIpa = $tmpExtract . '.ipa';
        $zip = new \ZipArchive();
        if ($zip->open($outputIpa, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) === true) {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($tmpExtract, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST
            );
            foreach ($files as $file) {
                $filePath = $file->getRealPath();
                $relativePath = substr($filePath, strlen($tmpExtract) + 1);
                if (is_dir($filePath)) {
                    $zip->addEmptyDir($relativePath);
                } else {
                    $zip->addFile($filePath, $relativePath);
                }
            }
            $zip->close();
        }

        // Cleanup extract dir
        $this->rmdirRecursive($tmpExtract);

        return file_exists($outputIpa) ? $outputIpa : $ipaPath;
    }

    private function cleanup(): void
    {
        if (is_dir($this->workDir)) $this->rmdirRecursive($this->workDir);
    }

    private function rmdirRecursive(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->rmdirRecursive($path) : unlink($path);
        }
        rmdir($dir);
    }
}
