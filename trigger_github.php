<?php
/**
 * Trigger GitHub Actions signing workflow
 * Usage: php trigger_github.php <task_id>
 */

// Autoload
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
$task = $pdo->prepare("SELECT t.*, a.bundle_id FROM tasks t LEFT JOIN apps a ON t.app_id = a.id WHERE t.id = ?");
$task->execute([$taskId]);
$task = $task->fetch();

if (!$task) {
    die("Task not found: {$taskId}\n");
}

$cert = $pdo->prepare("SELECT * FROM certificates WHERE id = ?");
$cert->execute([$task["cert_id"]]);
$cert = $cert->fetch();

$profile = $pdo->prepare("SELECT * FROM provisioning_profiles WHERE id = ?");
$profile->execute([$task["profile_id"]]);
$profile = $profile->fetch();

if (!$cert || !$profile) {
    die("Certificate or profile not found\n");
}

// Resolve Apple credentials: when apple_account_id is set, fetch from DB (same as TaskService)
$appleId = $task["apple_id"] ?? "";
$appPassword = $task["app_password"] ?? "";
if (!empty($task["apple_account_id"])) {
    $acct = $pdo->prepare("SELECT apple_id, app_password, status FROM apple_accounts WHERE id = ?");
    $acct->execute([(int)$task["apple_account_id"]]);
    $acctData = $acct->fetch();
    if ($acctData) {
        $appleId = $acctData["apple_id"];
        $appPassword = $acctData["app_password"];
    }
}

$baseUrl = \TfSigner\Core\Config::get("app.url", "https://bsj.appssign.cc");

// Delegate to GitHubService - single source of truth
$gh = new \TfSigner\Services\GitHubService();
$payload = $gh->buildPayload([
    "task_id"          => (string)$taskId,
    "ipa_url"          => $baseUrl . "/download/" . basename($task["input_ipa"]),
    "cert_url"         => $baseUrl . "/download/" . basename($cert["cert_path"]),
    "key_url"          => $baseUrl . "/download/" . basename($cert["key_path"]),
    "profile_url"      => $baseUrl . "/download/" . basename($profile["profile_path"]),
    "bundle_id"        => $task["bundle_id"] ?? "",
    "apple_id"         => $appleId,
    "app_password"     => $appPassword,
    "override_version" => $task["override_version"] ?? "",
    "override_build"   => $task["override_build"] ?? "",
]);

try {
    $gh->dispatch($payload);
    echo "OK: GitHub Actions triggered for task #{$taskId}\n";
} catch (\Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
