#!/usr/bin/env php
<?php
/**
 * TF 自动签名上架系统 - 后台任务处理器
 * 
 * 运行方式:
 *   php worker.php
 *   nohup php worker.php > /dev/null 2>&1 &
 * 
 * 建议使用 systemd 或 supervisor 管理:
 *   [Unit]
 *   Description=TF Signer Worker
 *   After=network.target
 *   
 *   [Service]
 *   ExecStart=/usr/bin/php /path/to/php-tf/worker.php
 *   Restart=always
 *   RestartSec=10
 *   
 *   [Install]
 *   WantedBy=multi-user.target
 */

declare(ticks=1);

// Autoload
$autoloadPaths = [
    __DIR__ . '/vendor/autoload.php',
    __DIR__ . '/../../vendor/autoload.php',
];
foreach ($autoloadPaths as $path) {
    if (file_exists($path)) { require $path; break; }
}

// Fallback autoloader
if (!class_exists(\TfSigner\Core\App::class)) {
    spl_autoload_register(function ($class) {
        $prefix = 'TfSigner\\';
        $baseDir = __DIR__ . '/src/';
        $len = strlen($prefix);
        if (strncmp($prefix, $class, $len) !== 0) return;
        $relativeClass = substr($class, $len);
        $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';
        if (file_exists($file)) require $file;
    });
}

use TfSigner\Core\App;
use TfSigner\Core\Config;
use TfSigner\Core\Logger;
use TfSigner\Services\TaskService;

// Bootstrap
App::boot();
App::ensureDirs();

$running = true;
$sleepInterval = Config::get('worker.sleep_interval', 5);

// Signal handling for graceful shutdown
pcntl_signal(SIGINT, function () use (&$running) {
    Logger::info("Worker received SIGINT, shutting down...");
    $running = false;
});
pcntl_signal(SIGTERM, function () use (&$running) {
    Logger::info("Worker received SIGTERM, shutting down...");
    $running = false;
});

Logger::info("TF Signer Worker started", ['pid' => getmypid()]);

$taskService = new TaskService();
$processedCount = 0;

while ($running) {
    try {
        $task = $taskService->getNextPending();

        if ($task) {
            Logger::info("Processing task", ['id' => $task['id'], 'type' => $task['type']]);
            
            $result = $taskService->process($task['id']);
            $processedCount++;

            Logger::info(
                $result['success'] ? "Task completed" : "Task failed",
                ['id' => $task['id'], 'success' => $result['success']]
            );

            // No sleep between tasks when there's work
            pcntl_signal_dispatch();
        } else {
            // No pending tasks, sleep
            sleep($sleepInterval);
            pcntl_signal_dispatch();
        }
    } catch (\Throwable $e) {
        Logger::error("Worker error", ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
        
        // Mark failed tasks
        if (isset($task)) {
            try {
                $taskService->updateStatus($task['id'], 'failed', error: $e->getMessage());
            } catch (\Throwable $ie) {
                Logger::error("Failed to update task status", ['error' => $ie->getMessage()]);
            }
        }

        sleep(5);
        pcntl_signal_dispatch();
    }
}

Logger::info("Worker stopped", ['processed' => $processedCount]);
