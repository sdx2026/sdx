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
        $profiles = $pdo->query("
            SELECT pp.*, a.name as app_name, c.name as cert_name 
            FROM provisioning_profiles pp 
            LEFT JOIN apps a ON pp.app_id = a.id 
            LEFT JOIN certificates c ON pp.cert_id = c.id 
            ORDER BY pp.created_at DESC
        ")->fetchAll();
        return Router::json($profiles);
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

        // Try to extract bundle_id from profile
        $bundleId = '';
        $content = file_get_contents($profilePath);
        if (preg_match('/<key>application-identifier<\/key>\s*<string>(.+?)<\/string>/s', $content, $m)) {
            $parts = explode('.', $m[1]);
            $bundleId = $parts[1] ?? $m[1];
        }
        if (empty($bundleId)) {
            if (preg_match('/<key>Name<\/key>\s*<string>(.+?)<\/string>/s', $content, $m)) {
                $bundleId = $m[1];
            }
        }

        $pdo = Database::connection();
        $stmt = $pdo->prepare("INSERT INTO provisioning_profiles (app_id, cert_id, name, uuid, profile_path, bundle_id, profile_type) VALUES (?, ?, ?, '', ?, ?, 'app-store')");
        $stmt->execute([$appId, $certId, $name, $profilePath, $bundleId]);
        return Router::json(['success' => true, 'id' => $pdo->lastInsertId(), 'bundle_id' => $bundleId]);
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
