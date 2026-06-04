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

        if ($useZsign) {
            return $this->resignWithZsign($inputIpa, $outputIpa, $certData, $profileData, $overrideVersion, $overrideBuild, $reportProgress);
        } else {
            return $this->resignWithCodesign($inputIpa, $outputIpa, $certData, $profileData, $reportProgress);
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
        $cmd .= ' ' . escapeshellarg($inputIpa);
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
        if ($zip->open($inputIpa) === true) {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if (preg_match('#^Payload/(.+)\.app/$#', $name, $m)) {
                    $appName = $m[1];
                    break;
                }
            }
            $zip->close();
        }

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
        callable $reportProgress
    ): array {
        $reportProgress(15, 'Signing with macOS codesign');

        $extractDir = $this->workDir . '/extract';
        $payloadDir = $extractDir . '/Payload';
        mkdir($payloadDir, 0755, true);

        $reportProgress(20, 'Extracting IPA');
        $zip = new \ZipArchive();
        if ($zip->open($inputIpa) !== true) {
            throw new \RuntimeException("Failed to open IPA: {$inputIpa}");
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
        foreach (['Frameworks', 'PlugIns'] as $sub) {
            $p = $appPath . '/' . $sub;
            if (!is_dir($p)) continue;
            foreach (glob($p . '/*') as $item) {
                if (!is_dir($item)) continue;
                exec('codesign --force --sign - --timestamp=none ' . escapeshellarg($item) . ' 2>/dev/null');
            }
        }
    }

    private function signApp(string $appPath, array $certData, string $entitlementsFile): void
    {
        exec('codesign --force --sign - --entitlements ' . escapeshellarg($entitlementsFile) . ' --timestamp=none ' . escapeshellarg($appPath) . ' 2>/dev/null');
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
        $entitlements = [];
        if (preg_match('/<key>Entitlements<\/key>\s*<dict>(.*?)<\/dict>/s', $content, $m)) {
            $entitlements = $this->simplePlistParse("<dict>{$m[1]}</dict>");
        }
        return $entitlements ?: ['application-identifier' => '', 'get-task-allow' => false];
    }

    private function patchInfoPlistBundleId(string $plistPath, string $bundleId): void
    {
        if (!file_exists($plistPath)) return;
        $data = $this->simplePlistParse(file_get_contents($plistPath));
        if (empty($data)) return;
        $data['CFBundleIdentifier'] = $bundleId;
        $this->writePlist($plistPath, $data);
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

    private function simplePlistParse(string $content): array
    {
        $result = [];
        if (!preg_match('/<dict>(.*?)<\/dict>/s', $content, $m)) return $result;
        preg_match_all(
            '/<key>(.*?)<\/key>\s*(<string>(.*?)<\/string>|<true\/>|<false\/>|<integer>(.*?)<\/integer>|<real>(.*?)<\/real>)/s',
            $m[1], $matches, PREG_SET_ORDER
        );
        foreach ($matches as $match) {
            $key = trim($match[1]);
            if (!empty($match[3])) $result[$key] = trim($match[3]);
            elseif (!empty($match[5])) $result[$key] = (int)trim($match[5]);
            elseif (!empty($match[6])) $result[$key] = (float)trim($match[6]);
            elseif (strpos($match[2], '<true/>') !== false) $result[$key] = true;
            elseif (strpos($match[2], '<false/>') !== false) $result[$key] = false;
        }
        return $result;
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
