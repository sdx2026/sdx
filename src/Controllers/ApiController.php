<?php
namespace TfSigner\Controllers;

use TfSigner\Core\Config;
use TfSigner\Core\Database;
use TfSigner\Core\Router;
use TfSigner\Services\CertificateService;
use TfSigner\Services\TaskService;

class ApiController
{
    /**
     * Create task via API (for automation)
     */
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

    /**
     * Get task status via API
     */
    public static function getTask(int $id): string
    {
        $service = new TaskService();
        $task = $service->get($id);
        if (!$task) {
            return Router::json(['error' => 'Task not found'], 404);
        }
        return Router::json($task);
    }

    /**
     * List certificates
     */
    public static function listCerts(): string
    {
        $service = new CertificateService();
        return Router::json($service->listAll());
    }

    /**
     * Generate certificate
     */
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

    /**
     * Import certificate
     */
    public static function importCert(): string
    {
        $input = json_decode(file_get_contents('php://input'), true);

        if (!$input) {
            return Router::json(['error' => 'Invalid JSON'], 400);
        }

        $service = new CertificateService();
        try {
            $result = $service->import($input);
            return Router::json(['success' => true, 'certificate' => $result]);
        } catch (\Throwable $e) {
            return Router::json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Delete certificate
     */
    public static function deleteCert(int $id): string
    {
        $service = new CertificateService();
        $service->delete($id);
        return Router::json(['success' => true]);
    }

    /**
     * List apps
     */
    public static function listApps(): string
    {
        $pdo = Database::connection();
        $apps = $pdo->query("SELECT * FROM apps ORDER BY name")->fetchAll();
        return Router::json($apps);
    }

    /**
     * List provisioning profiles
     */
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

    /**
     * Health check
     */
    public static function taskCallback(int $id): string
    {
        $input = json_decode(file_get_contents('php://input'), true);
        $service = new TfSignerServicesTaskService();
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
        try {
            $pdo->query("SELECT 1");
            $dbOk = true;
        } catch (\Throwable $e) {}

        return Router::json([
            'status' => $dbOk ? 'ok' : 'degraded',
            'version' => Config::get('app.version'),
            'php' => PHP_VERSION,
            'database' => $dbOk ? 'connected' : 'error',
            'time' => date('c'),
        ]);
    }
}
