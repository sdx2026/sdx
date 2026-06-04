<?php
namespace TfSigner\Controllers;

use TfSigner\Core\Config;
use TfSigner\Core\Database;
use TfSigner\Core\Router;
use TfSigner\Services\CertificateService;
use TfSigner\Services\TaskService;

class ApiController
{
    public static function createTask(): string
    {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input || empty($input['input_ipa'])) {
            return Router::json(['error' => 'input_ipa is required'], 400);
        }
        Router::logOp('task_create', 'Type: ' . ($input['type'] ?? 'sign_and_upload'));
        $s = new TaskService();
        $task = $s->create($input);
        return Router::json(['success' => true, 'task' => $task]);
    }

    public static function getTask(int $id): string
    {
        $s = new TaskService();
        $task = $s->get($id);
        if (!$task) return Router::json(['error' => 'Task not found'], 404);
        return Router::json($task);
    }

    public static function listCerts(): string
    {
        return Router::json((new CertificateService())->listAll());
    }

    public static function generateCert(): string
    {
        $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        try {
            $r = (new CertificateService())->generate($input);
            Router::logOp('cert_generate', $input['name'] ?? '');
            return Router::json(['success' => true, 'certificate' => $r]);
        } catch (\Throwable $e) {
            return Router::json(['error' => $e->getMessage()], 500);
        }
    }

    public static function importCert(): string
    {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input) return Router::json(['error' => 'Invalid JSON'], 400);
        try {
            $r = (new CertificateService())->import($input);
            Router::logOp('cert_import', $input['name'] ?? '');
            return Router::json(['success' => true, 'certificate' => $r]);
        } catch (\Throwable $e) {
            return Router::json(['error' => $e->getMessage()], 500);
        }
    }

    public static function deleteCert(int $id): string
    {
        (new CertificateService())->delete($id);
        Router::logOp('cert_delete', 'ID: ' . $id);
        return Router::json(['success' => true]);
    }

    public static function listApps(): string
    {
        return Router::json(Database::connection()->query("SELECT * FROM apps ORDER BY name")->fetchAll());
    }

    public static function listProfiles(): string
    {
        return Router::json(Database::connection()->query("
            SELECT pp.*, a.name as app_name, c.name as cert_name FROM provisioning_profiles pp 
            LEFT JOIN apps a ON pp.app_id = a.id LEFT JOIN certificates c ON pp.cert_id = c.id 
            ORDER BY pp.created_at DESC
        ")->fetchAll());
    }

    public static function uploadProfile(): string
    {
        if (empty($_FILES['profile_file'])) return Router::json(['error' => 'Profile file required'], 400);
        $appId = (int)($_POST['app_id'] ?? 0);
        $certId = !empty($_POST['cert_id']) ? (int)$_POST['cert_id'] : null;
        $name = $_POST['name'] ?? 'Imported Profile';
        if (!$appId) return Router::json(['error' => 'App is required'], 400);
        $dir = Config::get('storage.certs');
        $dest = 'profile_' . time() . '_' . basename($_FILES['profile_file']['name']);
        $path = $dir . '/' . $dest;
        move_uploaded_file($_FILES['profile_file']['tmp_name'], $path);
        $bid = '';
        $content = file_get_contents($path);
        if (preg_match('/<key>application-identifier<\/key>\s*<string>(.+?)<\/string>/s', $content, $m)) {
            $parts = explode('.', $m[1]); $bid = $parts[1] ?? $m[1];
        }
        $pdo = Database::connection();
        $pdo->prepare("INSERT INTO provisioning_profiles (app_id, cert_id, name, uuid, profile_path, bundle_id, profile_type) VALUES (?, ?, ?, '', ?, ?, 'app-store')")
            ->execute([$appId, $certId, $name, $path, $bid]);
        Router::logOp('profile_upload', $name);
        return Router::json(['success' => true, 'id' => $pdo->lastInsertId(), 'bundle_id' => $bid]);
    }

    public static function parseIpa(): string
    {
        if (empty($_FILES['ipa_file'])) return Router::json(['error' => 'IPA file required'], 400);
        $tmp = $_FILES['ipa_file']['tmp_name'];
        $size = filesize($tmp);
        if ($size > 500 * 1024 * 1024) return Router::json(['error' => 'IPA too large'], 400);
        $zip = new \ZipArchive();
        if ($zip->open($tmp) !== true) return Router::json(['error' => 'Invalid IPA'], 400);
        $plist = null;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            if (preg_match('#^Payload/[^/]+\.app/Info\.plist$#', $zip->getNameIndex($i))) {
                $plist = $zip->getFromIndex($i); break;
            }
        }
        $zip->close();
        if (!$plist) return Router::json(['error' => 'Info.plist not found'], 400);
        $data = [];
        if (PHP_OS_FAMILY === 'Darwin') {
            $tmpf = sys_get_temp_dir() . '/p_' . uniqid();
            file_put_contents($tmpf, $plist);
            $json = shell_exec("plutil -convert json -o - " . escapeshellarg($tmpf) . " 2>/dev/null");
            if ($json) $data = json_decode($json, true) ?: [];
            @unlink($tmpf);
        }
        if (empty($data)) {
            if (preg_match('/<key>CFBundleIdentifier<\/key>\s*<string>(.+?)<\/string>/s', $plist, $m)) $data['CFBundleIdentifier'] = $m[1];
            if (preg_match('/<key>CFBundleShortVersionString<\/key>\s*<string>(.+?)<\/string>/s', $plist, $m)) $data['CFBundleShortVersionString'] = $m[1];
            if (preg_match('/<key>CFBundleDisplayName<\/key>\s*<string>(.+?)<\/string>/s', $plist, $m)) $data['CFBundleDisplayName'] = $m[1];
            if (preg_match('/<key>CFBundleName<\/key>\s*<string>(.+?)<\/string>/s', $plist, $m)) $data['CFBundleName'] = $m[1];
        }
        return Router::json([
            'success' => true, 'file_size' => round($size / 1024 / 1024, 2) . ' MB',
            'name' => $data['CFBundleDisplayName'] ?? $data['CFBundleName'] ?? 'Unknown',
            'bundle_id' => $data['CFBundleIdentifier'] ?? '', 'version' => $data['CFBundleShortVersionString'] ?? '',
        ]);
    }

    public static function getSettings(): string
    {
        $pdo = Database::connection();
        $s = [];
        foreach ($pdo->query("SELECT * FROM settings") as $row) $s[$row['key']] = $row['value'];
        return Router::json(array_merge([
            'apple_id' => '', 'app_password' => '', 'github_token' => Config::get('github.token', ''),
            'webhook_url' => Config::get('webhook.url', ''),
        ], $s));
    }

    public static function saveSettings(): string
    {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input) return Router::json(['error' => 'Invalid'], 400);
        $pdo = Database::connection();
        foreach ($input as $k => $v) {
            $pdo->prepare("INSERT OR REPLACE INTO settings (key, value, updated_at) VALUES (?, ?, datetime('now'))")
                ->execute([$k, $v]);
        }
        Router::logOp('settings_save', implode(',', array_keys($input)));
        return Router::json(['success' => true]);
    }

    public static function workerStatus(): string
    {
        $running = !empty(trim(shell_exec("pgrep -f 'tfsigner/worker' 2>/dev/null") ?: ""));
        return Router::json(['running' => $running]);
    }

    public static function dashboardStats(): string
    {
        $pdo = Database::connection();
        $stats = [];
        foreach ($pdo->query("SELECT status, COUNT(*) as cnt FROM tasks GROUP BY status") as $r) $stats[$r['status']] = $r['cnt'];
        
        $stats += ['pending' => 0, 'processing' => 0, 'completed' => 0, 'failed' => 0];
        $apps = $pdo->query("SELECT COUNT(*) FROM apps")->fetchColumn();
        $workerRunning = !empty(trim(shell_exec("pgrep -f 'tfsigner/worker' 2>/dev/null") ?: ""));
        
        // Expiry alerts
        $alerts = [];
        foreach ($pdo->query("SELECT name, type, expires_at FROM certificates WHERE is_active = 1 AND expires_at IS NOT NULL") as $c) {
            if (!$c['expires_at']) continue;
            $days = (int)((strtotime($c['expires_at']) - time()) / 86400);
            if ($days < 30) $alerts[] = ['name' => $c['name'], 'type' => '证书', 'days' => $days, 'urgent' => $days < 7];
        }
        foreach ($pdo->query("SELECT pp.name, pp.expires_at, a.name as app_name FROM provisioning_profiles pp LEFT JOIN apps a ON pp.app_id = a.id WHERE pp.is_active = 1 AND pp.expires_at IS NOT NULL") as $p) {
            if (!$p['expires_at']) continue;
            $days = (int)((strtotime($p['expires_at']) - time()) / 86400);
            if ($days < 30) $alerts[] = ['name' => ($p['app_name'] ?: '') . ' ' . $p['name'], 'type' => '描述文件', 'days' => $days, 'urgent' => $days < 7];
        }
        
        $recent = $pdo->query("SELECT t.*, a.name as app_name FROM tasks t LEFT JOIN apps a ON t.app_id = a.id ORDER BY t.updated_at DESC LIMIT 10")->fetchAll();
        
        return Router::json(['stats' => $stats, 'apps' => $apps, 'worker' => ['running' => $workerRunning], 'alerts' => $alerts, 'recent_tasks' => $recent]);
    }

    public static function taskCallback(int $id): string
    {
        $input = json_decode(file_get_contents('php://input'), true);
        $s = new TaskService();
        $st = $input['status'] ?? 'completed';
        $s->updateStatus($id, $st, error: $input['error'] ?? null, result: $input['result'] ?? null, progress: 100);
        Router::logOp('task_callback', "Task #{$id} -> {$st}");
        return Router::json(['success' => true, 'task_id' => $id, 'status' => $st]);
    }

    public static function health(): string
    {
        $pdo = Database::connection();
        $dbOk = false;
        try { $pdo->query("SELECT 1"); $dbOk = true; } catch (\Throwable $e) {}
        return Router::json(['status' => $dbOk ? 'ok' : 'degraded', 'version' => Config::get('app.version'), 'php' => PHP_VERSION, 'database' => $dbOk ? 'connected' : 'error']);
    }
}
