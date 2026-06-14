<?php
namespace TfSigner\Services;

use TfSigner\Core\Config;
use TfSigner\Core\Logger;

class UploadService
{
    public function upload(string $ipaPath, string $appleId, string $appPassword, ?callable $progressCallback = null): array
    {
        $reportProgress = function(int $pct, string $msg) use ($progressCallback) {
            Logger::info("Upload progress: {$pct}% - {$msg}");
            if ($progressCallback) $progressCallback($pct, $msg);
        };

        if (!file_exists($ipaPath)) {
            throw new \RuntimeException("IPA file not found: {$ipaPath}");
        }

        $fileSize = filesize($ipaPath);
        $reportProgress(5, "IPA size: " . round($fileSize / 1024 / 1024, 2) . " MB");

        $altoolPath = $this->findAltool();
        if (!$altoolPath) {
            throw new \RuntimeException('[E4001] xcrun altool not found — macOS required for App Store upload. Use "🚀 GitHub Actions" task type instead.');
        }

        $reportProgress(10, 'Validating IPA...');

        // Step 1: Validate
        $validateCmd = sprintf(
            '%s --validate-app -f %s -t ios -u %s -p %s --output-format json 2>&1',
            $altoolPath,
            escapeshellarg($ipaPath),
            escapeshellarg($appleId),
            escapeshellarg($appPassword)
        );

        exec($validateCmd, $validateOutput, $validateCode);
        $validateResult = implode("\n", $validateOutput);
        Logger::info("Validate result", ['code' => $validateCode, 'output' => $validateResult]);

        $validateJson = json_decode($validateResult, true);

        $validateJson = is_array($validateJson) ? $validateJson : [];
        if ($validateCode !== 0) {
            return [
                'success' => false,
                'stage' => 'validation',            'error' => $validateResult,
                'raw' => $validateResult,
            ];
        }

        $reportProgress(40, 'Validation passed, uploading...');

        // Step 2: Upload
        $uploadCmd = sprintf(
            '%s --upload-app -f %s -t ios -u %s -p %s --output-format json 2>&1',
            $altoolPath,
            escapeshellarg($ipaPath),
            escapeshellarg($appleId),
            escapeshellarg($appPassword)
        );

        $descriptorspec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($uploadCmd, $descriptorspec, $pipes);

        if (!is_resource($process)) {
            throw new \RuntimeException("Failed to start upload process");
        }

        // Close stdin immediately
        fclose($pipes[0]);

        $uploadOutput = '';
        $lastProgress = 40;

        // Read both stdout and stderr to prevent pipe deadlock
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        while (true) {
            $status = proc_get_status($process);
            if (!$status['running']) break;

            // Read stdout (prevent buffer fill deadlock)
            $out = fread($pipes[1], 8192);
            if ($out !== false && strlen($out) > 0) {
                $uploadOutput .= $out;
            }

            // Read stderr for progress info
            $line = fread($pipes[2], 8192);
            if ($line !== false && strlen($line) > 0) {
                $uploadOutput .= $line;
                if (preg_match('/([\d.]+)%/', $line, $m)) {
                    $pct = min(95, 40 + (int)((float)$m[1] * 0.55));
                    if ($pct > $lastProgress) {
                        $lastProgress = $pct;
                        $reportProgress($lastProgress, 'Uploading... ' . $m[1] . '%');
                    }
                }
            }

            usleep(100000);
        }

        // Drain remaining output
        while (($out = fread($pipes[1], 8192)) !== false && strlen($out) > 0) {
            $uploadOutput .= $out;
        }
        while (($line = fread($pipes[2], 8192)) !== false && strlen($line) > 0) {
            $uploadOutput .= $line;
        }

        fclose($pipes[1]);
        fclose($pipes[2]);
        $uploadCode = proc_close($process);

        Logger::info("Upload result", ['code' => $uploadCode]);

        $reportProgress(95, 'Processing upload result...');

        return [
            'success' => $uploadCode === 0,

            'stage' => 'upload',
            'error' => $uploadCode === 0 ? null : $uploadOutput,
            'code' => $uploadCode,
            'output' => $uploadOutput,
            'data' => json_decode($uploadOutput, true),
        ];
    }

    private function findAltool(): ?string
    {
        exec('which xcrun 2>/dev/null', $output, $code);
        if ($code === 0 && !empty($output[0])) {
            return trim($output[0]) . ' altool';
        }

        $transporterPath = '/Applications/Transporter.app/Contents/itms/bin/iTMSTransporter';
        if (file_exists($transporterPath)) {
            return $transporterPath;
        }

        exec('which altool 2>/dev/null', $output, $code);
        if ($code === 0 && !empty($output[0])) {
            return trim($output[0]);
        }

        return null;
    }

    public function pollBuildStatus(AppleApi $api, string $appId, string $version, int $maxWait = 1800): array
    {
        $start = time();
        $interval = 30;

        while (time() - $start < $maxWait) {
            $result = $api->getBuildStatus($appId, $version);
            $builds = $result['data'] ?? [];

            foreach ($builds as $build) {
                $state = $build['attributes']['processingState'] ?? '';
                $buildVersion = $build['attributes']['version'] ?? '';

                if ($buildVersion === $version) {
                    if ($state === 'VALID') {
                        return ['status' => 'ready', 'build' => $build];
                    }
                    if ($state === 'INVALID') {
                        return ['status' => 'failed', 'build' => $build];
                    }
                    Logger::info("Build processing: {$state}", ['version' => $version]);
                }
            }

            sleep($interval);
        }

        return ['status' => 'timeout', 'message' => 'Build processing timed out'];
    }
}
