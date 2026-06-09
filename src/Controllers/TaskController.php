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
        $filters = [
            'status' => $_GET['status'] ?? '',
            'search' => $_GET['search'] ?? '',
            'app_id' => $_GET['app_id'] ?? '',
            'date_from' => $_GET['date_from'] ?? '',
            'date_to' => $_GET['date_to'] ?? '',
            'page' => $_GET['page'] ?? 1,
            'per_page' => $_GET['per_page'] ?? 20,
        ];
        $result = self::service()->list($filters);
        $apps = self::getAppsList();
        return Router::view('tasks', [
            'tasks' => $result['items'],
            'total' => $result['total'],
            'page' => $result['page'],
            'total_pages' => $result['total_pages'],
            'filters' => $filters,
            'apps' => $apps,
        ]);
    }

    public static function create(): string
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return Router::view('tasks_new', [
                'apps' => self::getAppsList(),
                'certs' => self::getCertsList(),
                'profiles' => self::getProfilesList(),
                'appleAccounts' => self::getAppleAccounts(),
                'apiKeys' => self::getApiKeys(),
            ]);
        }

        $type = $_POST['type'] ?? 'sign_and_upload';
        $certId = $_POST['cert_id'] ?? null;
        $profileId = $_POST['profile_id'] ?? null;
        $appleId = $_POST['apple_id'] ?? '';
        $appPassword = $_POST['app_password'] ?? '';
        $appleAccountId = $_POST['apple_account_id'] ?? null;
        $apiKeyId = $_POST['api_key_id'] ?? null;
        $uploadDir = \TfSigner\Core\Config::get('storage.ipas');

        // Handle batch upload (multiple files)
        $files = $_FILES['ipa_files'] ?? null;
        $singleFile = $_FILES['ipa_file'] ?? null;
        $created = [];
        $errors = [];

        $fileEntries = [];
        if (!empty($files['tmp_name']) && is_array($files['tmp_name'])) {
            for ($i = 0; $i < count($files['tmp_name']); $i++) {
                if (!empty($files['tmp_name'][$i])) {
                    $fileEntries[] = [
                        'tmp_name' => $files['tmp_name'][$i],
                        'name' => $files['name'][$i],
                    ];
                }
            }
        } elseif (!empty($singleFile['tmp_name'])) {
            $fileEntries[] = $singleFile;
        }

        if (empty($fileEntries)) {
            return Router::json(['error' => 'IPA file required'], 400);
        }

        // Check if it's a batch submit
        $isBatch = count($fileEntries) > 1 || isset($_POST['batch']);

        foreach ($fileEntries as $entry) {
            try {
                $destName = 'input_' . time() . '_' . rand(1000, 9999) . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $entry['name']);
                $inputIpa = $uploadDir . '/' . $destName;
                move_uploaded_file($entry['tmp_name'], $inputIpa);

                // Try to auto-detect app by parsing IPA
                $appId = $_POST['app_id'] ?? null;
                if (!$appId) {
                    $appId = self::detectAppFromIpa($inputIpa);
                }

                $task = self::service()->create([
                    'app_id' => $appId,
                    'type' => $type,
                    'input_ipa' => $inputIpa,
                    'cert_id' => $certId,
                    'profile_id' => $profileId,
                    'apple_id' => $appleAccountId ? '' : $appleId,
                    'app_password' => $appleAccountId ? '' : $appPassword,
                    'override_version' => $_POST['override_version'] ?? '',
                    'override_build' => $_POST['override_build'] ?? '',
                    'apple_account_id' => $appleAccountId ? (int)$appleAccountId : null,
                    'api_key_id' => $apiKeyId ? (int)$apiKeyId : null,
                ]);
                $created[] = $task['id'];
            } catch (\Throwable $e) {
                $errors[] = $entry['name'] . ': ' . $e->getMessage();
            }
        }

        if ($isBatch) {
            return Router::json([
                'success' => count($errors) === 0,
                'created' => $created,
                'errors' => $errors,
                'message' => '创建 ' . count($created) . ' 个任务' . (count($errors) ? '，' . count($errors) . ' 个失败' : ''),
            ]);
        }

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

    private static function detectAppFromIpa(string $ipaPath): ?int
    {
        try {
            $zip = new \ZipArchive();
            if ($zip->open($ipaPath) !== true) return null;
            $plist = null;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                if (preg_match('#^Payload/[^/]+\.app/Info\.plist$#', $zip->getNameIndex($i))) {
                    $plist = $zip->getFromIndex($i);
                    break;
                }
            }
            $zip->close();
            if (!$plist) return null;
            if (preg_match('/<key>CFBundleIdentifier<\/key>\s*<string>(.+?)<\/string>/s', $plist, $m)) {
                $bundleId = $m[1];
                $pdo = \TfSigner\Core\Database::connection();
                $stmt = $pdo->prepare("SELECT id FROM apps WHERE bundle_id = ?");
                $stmt->execute([$bundleId]);
                $row = $stmt->fetch();
                return $row ? (int)$row['id'] : null;
            }
        } catch (\Throwable $e) {}
        return null;
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

    private static function getAppleAccounts(): array
    {
        $pdo = \TfSigner\Core\Database::connection();
        $pdo->exec("CREATE TABLE IF NOT EXISTS apple_accounts (id INTEGER PRIMARY KEY AUTOINCREMENT, apple_id TEXT NOT NULL UNIQUE, app_password TEXT NOT NULL, note TEXT DEFAULT '', created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
        return $pdo->query("SELECT id, apple_id, note, status FROM apple_accounts ORDER BY id")->fetchAll();
    }

    private static function getApiKeys(): array
    {
        $pdo = \TfSigner\Core\Database::connection();
        $pdo->exec("CREATE TABLE IF NOT EXISTS api_keys (id INTEGER PRIMARY KEY AUTOINCREMENT, issuer_id TEXT NOT NULL, key_id TEXT NOT NULL, key_content TEXT NOT NULL, note TEXT DEFAULT '', created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
        return $pdo->query("SELECT id, issuer_id, key_id, note FROM api_keys ORDER BY id")->fetchAll();
    }
}
