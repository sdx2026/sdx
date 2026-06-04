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
        $service = new TaskService();
        $task = $service->create([
            'app_id' => $input['app_id'] ?? null,
            'type' => $input['type'] ?? 'sign_and_upload',
            'input_ipa' => $input['input_ipa'],
            'cert_id' => $input['cert_id'] ?? null,
            'profile_id' => $input['profile_id'] ?? null,
            'apple_id' => $input['apple_id'] ?? '',
            'app_password' => $input['app_password'] ?? '',
            'priority' => $input['priority'] ?? 0,
        ]);
        return Router::json(['success' => true, 'task' => $task]);
    }

    public static function getTask(int $id): string
    {
        $service = new TaskService();
        $task = $service->get($id);
        if (!$task) return Router::json(['error' => 'Task not found'], 404);
        return Router::json($task);
    }

    public static function listCerts(): string
    {
        $service = new CertificateService();
        return Router::json($service->listAll());
    }

    public static function generateCert(): string
    {
        $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        $service = new CertificateService();
        try {
            $result = $service->generate($input);
            return Router::json(['success' => true, 'certificate' => $result]);
        } catch (\Throwable $e) {
            return Router::json(['error' => $e->getMessage()], 500);
        }
    }

    public static function importCert(): string
    {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input) return Router::json(['error' => 'Invalid JSON'], 400);
        $service = new CertificateService();
        try {
            $result = $service->import($input);
            return Router::json(['success' => true, 'certificate' => $result]);
        } catch (\Throwable $e) {
            return Router::json(['error' => $e->getMessage()], 500);
        }
    }

    public static function deleteCert(int $id): string
    {
        $service = new CertificateService();
        $service->delete($id);
        return Router::json(['success' => true]);
    }

    public static function listApps(): string
    {
        $pdo = Database::connection();
        $apps = $pdo->query("SELECT * FROM apps ORDER BY name")->fetchAll();
        return Router::json($apps);
    }

    public static function listProfiles(): string
    {
        $pdo = Database::connection();
        return Router::json($pdo->query("
            SELECT pp.*, a.name as app_name, c.name as cert_name 
            FROM provisioning_profiles pp 
            LEFT JOIN apps a ON pp.app_id = a.id 
            LEFT JOIN certificates c ON pp.cert_id = c.id 
            ORDER BY pp.created_at DESC
        ")->fetchAll());
    }

    public static function uploadProfile(): string
    {
        if (empty($_FILES['profile_file'])) {
            return Router::json(['error' => 'Profile file required'], 400);
        }
        $appId = (int)($_POST['app_id'] ?? 0);
        $certId = !empty($_POST['cert_id']) ? (int)$_POST['cert_id'] : null;
        $name = $_POST['name'] ?? 'Imported Profile';
        if (!$appId) return Router::json(['error' => 'App is required'], 400);

        $uploadDir = Config::get('storage.certs');
        $destName = 'profile_' . time() . '_' . basename($_FILES['profile_file']['name']);
        $profilePath = $uploadDir . '/' . $destName;
        move_uploaded_file($_FILES['profile_file']['tmp_name'], $profilePath);

        $bundleId = '';
        $content = file_get_contents($profilePath);
        if (preg_match('/<key>application-identifier<\/key>\s*<string>(.+?)<\/string>/s', $content, $m)) {
            $parts = explode('.', $m[1]);
            $bundleId = $parts[1] ?? $m[1];
        }

        $pdo = Database::connection();
        $stmt = $pdo->prepare("INSERT INTO provisioning_profiles (app_id, cert_id, name, uuid, profile_path, bundle_id, profile_type) VALUES (?, ?, ?, '', ?, ?, 'app-store')");
        $stmt->execute([$appId, $certId, $name, $profilePath, $bundleId]);
        return Router::json(['success' => true, 'id' => $pdo->lastInsertId(), 'bundle_id' => $bundleId]);
    }

    public static function parseIpa(): string
    {
        if (empty($_FILES['ipa_file'])) {
            return Router::json(['error' => 'IPA file required'], 400);
        }

        $tmpFile = $_FILES['ipa_file']['tmp_name'];
        $fileSize = filesize($tmpFile);
        if ($fileSize > 500 * 1024 * 1024) {
            return Router::json(['error' => 'IPA too large (max 500MB)'], 400);
        }

        $zip = new \ZipArchive();
        if ($zip->open($tmpFile) !== true) {
            return Router::json(['error' => 'Invalid IPA file'], 400);
        }

        // Find Info.plist inside Payload/*.app
        $infoPlist = null;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (preg_match('#^Payload/[^/]+\.app/Info\.plist$#', $name)) {
                $infoPlist = $zip->getFromIndex($i);
                break;
            }
        }
        $zip->close();

        if (!$infoPlist) {
            return Router::json(['error' => 'Info.plist not found in IPA'], 400);
        }

        // Parse plist (binary or XML)
        $data = [];
        $tmpPlist = sys_get_temp_dir() . '/tfsigner_plist_' . uniqid();
        file_put_contents($tmpPlist, $infoPlist);

        if (PHP_OS_FAMILY === 'Darwin') {
            $json = shell_exec("plutil -convert json -o - " . escapeshellarg($tmpPlist) . " 2>/dev/null");
            if ($json) $data = json_decode($json, true) ?: [];
        }

        if (empty($data)) {
            // Simple XML plist fallback
            if (preg_match('/<key>CFBundleName<\/key>\s*<string>(.+?)<\/string>/s', $infoPlist, $m)) $data['CFBundleName'] = $m[1];
            if (preg_match('/<key>CFBundleDisplayName<\/key>\s*<string>(.+?)<\/string>/s', $infoPlist, $m)) $data['CFBundleDisplayName'] = $m[1];
            if (preg_match('/<key>CFBundleIdentifier<\/key>\s*<string>(.+?)<\/string>/s', $infoPlist, $m)) $data['CFBundleIdentifier'] = $m[1];
            if (preg_match('/<key>CFBundleShortVersionString<\/key>\s*<string>(.+?)<\/string>/s', $infoPlist, $m)) $data['CFBundleShortVersionString'] = $m[1];
            if (preg_match('/<key>CFBundleVersion<\/key>\s*<string>(.+?)<\/string>/s', $infoPlist, $m)) $data['CFBundleVersion'] = $m[1];
            if (preg_match('/<key>MinimumOSVersion<\/key>\s*<string>(.+?)<\/string>/s', $infoPlist, $m)) $data['MinimumOSVersion'] = $m[1];
        }

        @unlink($tmpPlist);

        return Router::json([
            'success' => true,
            'file_size' => round($fileSize / 1024 / 1024, 2) . ' MB',
            'name' => $data['CFBundleDisplayName'] ?? $data['CFBundleName'] ?? 'Unknown',
            'bundle_id' => $data['CFBundleIdentifier'] ?? '',
            'version' => $data['CFBundleShortVersionString'] ?? '',
            'build' => $data['CFBundleVersion'] ?? '',
            'min_os' => $data['MinimumOSVersion'] ?? '',
        ]);
    }

    public static function getSettings(): string
    {
        $pdo = Database::connection();
        $settings = [];
        foreach ($pdo->query("SELECT * FROM settings") as $row) {
            $settings[$row['key']] = $row['value'];
        }
        // Merge with config defaults
        $defaults = [
            'apple_id' => '',
            'github_token' => Config::get('github.token', ''),
            'webhook_url' => Config::get('webhook.url', ''),
            'webhook_secret' => Config::get('webhook.secret', ''),
        ];
        return Router::json(array_merge($defaults, $settings));
    }

    public static function saveSettings(): string
    {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input) return Router::json(['error' => 'Invalid input'], 400);

        $pdo = Database::connection();
        foreach ($input as $key => $value) {
            $pdo->prepare("INSERT OR REPLACE INTO settings (key, value, updated_at) VALUES (?, ?, datetime('now'))")
                ->execute([$key, $value]);
        }
        return Router::json(['success' => true]);
    }

    public static function taskCallback(int $id): string
    {
        $input = json_decode(file_get_contents('php://input'), true);
        $service = new TaskService();
        $status = $input['status'] ?? 'completed';
        $error = $input['error'] ?? null;
        $result = $input['result'] ?? null;
        $service->updateStatus($id, $status, error: $error, result: $result, progress: 100);
        return Router::json(['success' => true, 'task_id' => $id, 'status' => $status]);
    }

    public static function health(): string
    {
        $pdo = Database::connection();
        $dbOk = false;
        try { $pdo->query("SELECT 1"); $dbOk = true; } catch (\Throwable $e) {}
        return Router::json([
            'status' => $dbOk ? 'ok' : 'degraded',
            'version' => Config::get('app.version'),
            'php' => PHP_VERSION,
            'database' => $dbOk ? 'connected' : 'error',
            'time' => date('c'),
        ]);
    }
}
