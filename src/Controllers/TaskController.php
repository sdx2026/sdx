<?php
namespace TfSigner\Controllers;

use TfSigner\Core\Router;
use TfSigner\Services\TaskService;

class TaskController
{
    private static TaskService $service;

    private static function service(): TaskService
    {
        if (!isset(self::$service)) {
            self::$service = new TaskService();
        }
        return self::$service;
    }

    public static function index(): string
    {
        $status = $_GET['status'] ?? '';
        $tasks = self::service()->list($status ? ['status' => $status] : []);
        return Router::view('tasks', ['tasks' => $tasks, 'status' => $status]);
    }

    public static function create(): string
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return Router::view('tasks_new', [
                'apps' => self::getAppsList(),
                'certs' => self::getCertsList(),
                'profiles' => self::getProfilesList(),
            ]);
        }

        $appId = $_POST['app_id'] ?? null;
        $type = $_POST['type'] ?? 'sign_and_upload';
        $certId = $_POST['cert_id'] ?? null;
        $profileId = $_POST['profile_id'] ?? null;
        $appleId = $_POST['apple_id'] ?? '';
        $appPassword = $_POST['app_password'] ?? '';

        // Handle IPA upload
        $inputIpa = '';
        if (!empty($_FILES['ipa_file']['tmp_name'])) {
            $uploadDir = \TfSigner\Core\Config::get('storage.ipas');
            $destName = 'input_' . time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $_FILES['ipa_file']['name']);
            $inputIpa = $uploadDir . '/' . $destName;
            move_uploaded_file($_FILES['ipa_file']['tmp_name'], $inputIpa);
        }

        if (!$inputIpa) {
            return Router::json(['error' => 'IPA file required'], 400);
        }

        $task = self::service()->create([
            'app_id' => $appId,
            'type' => $type,
            'input_ipa' => $inputIpa,
            'cert_id' => $certId,
            'profile_id' => $profileId,
            'apple_id' => $appleId,
            'app_password' => $appPassword,
        ]);

        Router::redirect('/tasks');
        return '';
    }

    public static function show(int $id): string
    {
        $task = self::service()->get($id);
        if (!$task) {
            return Router::json(['error' => 'Task not found'], 404);
        }
        return Router::view('task_detail', ['task' => $task]);
    }

    public static function status(int $id): string
    {
        $task = self::service()->get($id);
        if (!$task) {
            return Router::json(['error' => 'Task not found'], 404);
        }
        return Router::json($task);
    }

    public static function delete(int $id): string
    {
        self::service()->delete($id);
        return Router::json(['success' => true]);
    }

    public static function retry(int $id): string
    {
        $pdo = \TfSigner\Core\Database::connection();
        $pdo->prepare("UPDATE tasks SET status = 'pending', error = NULL, progress = 0, retries = 0 WHERE id = ?")
            ->execute([$id]);
        Router::redirect('/tasks');
        return '';
    }

    private static function getAppsList(): array
    {
        $pdo = \TfSigner\Core\Database::connection();
        return $pdo->query("SELECT id, name, bundle_id FROM apps ORDER BY name")->fetchAll();
    }

    private static function getCertsList(): array
    {
        $pdo = \TfSigner\Core\Database::connection();
        return $pdo->query("SELECT id, name, type FROM certificates WHERE is_active = 1 ORDER BY name")->fetchAll();
    }

    private static function getProfilesList(): array
    {
        $pdo = \TfSigner\Core\Database::connection();
        return $pdo->query("SELECT id, name, bundle_id, profile_type FROM provisioning_profiles WHERE is_active = 1 ORDER BY name")->fetchAll();
    }
}
