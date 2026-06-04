<?php
/**
 * 触发 GitHub Actions 签名任务
 * 用法: php trigger_github.php <task_id>
 */

require_once __DIR__ . "/public/index.php";

// Re-bootstrap for CLI
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

$baseUrl = "http://38.246.249.155:8088";

$payload = [
    "ref" => "main",
    "inputs" => [
        "task_id" => (string)$taskId,
        "ipa_url" => $baseUrl . "/download/" . basename($task["input_ipa"]),
        "cert_url" => $baseUrl . "/download/" . basename($cert["cert_path"]),
        "key_url" => $baseUrl . "/download/" . basename($cert["key_path"]),
        "profile_url" => $baseUrl . "/download/" . basename($profile["profile_path"]),
        "bundle_id" => $task["bundle_id"] ?? "",
        "apple_id" => $task["apple_id"] ?? "",
        "apple_password" => $task["app_password"] ?? "",
        "key_password" => $cert["password"] ?? "",
    ],
];

$token = getenv("GITHUB_TOKEN") ?: "";
if (!$token) {
    die("Error: GITHUB_TOKEN environment variable not set\n");
}

$ch = curl_init("https://api.github.com/repos/sdx2026/sdx/actions/workflows/sign.yml/dispatches");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($payload),
    CURLOPT_HTTPHEADER => [
        "Authorization: Bearer {$token}",
        "Accept: application/vnd.github+json",
        "X-GitHub-Api-Version: 2022-11-28",
        "Content-Type: application/json",
        "User-Agent: TF-Signer/1.0",
    ],
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode === 204) {
    echo "✅ GitHub Actions triggered for task #{$taskId}\n";
} else {
    echo "❌ Failed (HTTP {$httpCode}): {$response}\n";
}
