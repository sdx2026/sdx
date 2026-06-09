#!/usr/bin/env php
<?php
/**
 * Background auto-submit to TestFlight
 * Called from ApiController::taskCallback
 * Usage: php autosubmit.php <task_id>
 */

$taskId = (int)($argv[1] ?? 0);
if ($taskId <= 0) { echo "Usage: php autosubmit.php <task_id>\n"; exit(1); }

// Autoload
$autoloadPaths = [__DIR__ . '/vendor/autoload.php', __DIR__ . '/../../vendor/autoload.php'];
foreach ($autoloadPaths as $path) { if (file_exists($path)) { require $path; break; } }
if (!class_exists(\TfSigner\Core\App::class)) {
    spl_autoload_register(function ($class) {
        $prefix = 'TfSigner\\';
        $baseDir = __DIR__ . '/src/';
        $len = strlen($prefix);
        if (strncmp($prefix, $class, $len) !== 0) return;
        $file = $baseDir . str_replace('\\', '/', substr($class, $len)) . '.php';
        if (file_exists($file)) require $file;
    });
}

\TfSigner\Core\App::boot();

echo "Auto-submit starting for task #{$taskId}\n";
try {
    $result = \TfSigner\Controllers\ApiController::autoSubmitAfterUpload($taskId);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
} catch (\Throwable $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
