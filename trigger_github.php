<?php
/**
 * Trigger GitHub Actions upload workflow (local signing already done)
 * Usage: php trigger_github.php <task_id>
 * Note: Task must have been signed locally first (output_ipa must exist)
 */

$autoloadPaths = [__DIR__ . "/vendor/autoload.php", __DIR__ . "/../../vendor/autoload.php"];
foreach ($autoloadPaths as $path) { if (file_exists($path)) { require $path; break; } }
if (!class_exists(\TfSigner\Core\App::class)) {
    spl_autoload_register(function ($class) {
        $prefix = "TfSigner\\";
        $baseDir = __DIR__ . "/src/";
        $len = strlen($prefix);
        if (strncmp($prefix, $class, $len) !== 0) return;
        $relativeClass = substr($class, $len);
        $file = $baseDir . str_replace("\\", "/", $relativeClass) . ".php";
        if (file_exists($file)) require $file;
    });
}

\TfSigner\Core\App::boot();

$taskId = (int)($argv[1] ?? 0);
if ($taskId <= 0) {
    die("Usage: php trigger_github.php <task_id>\n");
}

$pdo = \TfSigner\Core\Database::connection();
$task = $pdo->prepare("SELECT * FROM tasks WHERE id = ?");
$task->execute([$taskId]);
$task = $task->fetch();

if (!$task) die("Task not found: {$taskId}\n");

// Use signed IPA if available, otherwise input IPA
$ipaPath = $task["output_ipa"] ?? $task["input_ipa"] ?? '';
if (empty($ipaPath) || !file_exists($ipaPath)) {
    die("IPA file not found\n");
}

// Resolve Apple credentials
$appleId = $task["apple_id"] ?? "";
$appPassword = $task["app_password"] ?? "";
if (!empty($task["apple_account_id"])) {
    $acct = $pdo->prepare("SELECT apple_id, app_password, status FROM apple_accounts WHERE id = ?");
    $acct->execute([(int)$task["apple_account_id"]]);
    $acctData = $acct->fetch();
    if ($acctData) {
        if (($acctData["status"] ?? "active") === "blocked") {
            die("Apple account is blocked\n");
        }
        $appleId = $acctData["apple_id"];
        $appPassword = $acctData["app_password"];
    }
}

$baseUrl = \TfSigner\Core\Config::get("app.url", "https://bsj.appssign.cc");

$gh = new \TfSigner\Services\GitHubService('sdx2026/sdx', 'upload_only.yml');
$payload = $gh->buildUploadOnlyPayload([
    "task_id"        => (string)$taskId,
    "signed_ipa_url" => $baseUrl . "/download/" . basename($ipaPath) . "?task_id=" . $taskId,
    "apple_id"       => $appleId,
    "app_password"   => $appPassword,
]);

try {
    $gh->dispatch($payload);
    echo "OK: GitHub Actions upload triggered for task #{$taskId}\n";
} catch (\Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
