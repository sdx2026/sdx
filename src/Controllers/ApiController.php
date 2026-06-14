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

    public static function deleteProfile(int $id): string
    {
        $pdo = \TfSigner\Core\Database::connection();
        $stmt = $pdo->prepare("SELECT * FROM provisioning_profiles WHERE id = ?");
        $stmt->execute([$id]);
        $profile = $stmt->fetch();
        if (!$profile) return Router::json(['error' => 'Profile not found'], 404);
        
        // Nullify profile references in tasks
        $pdo->prepare("UPDATE tasks SET profile_id = NULL WHERE profile_id = ?")->execute([$id]);
        // Delete file
        @unlink($profile['profile_path']);
        // Delete record
        $pdo->prepare("DELETE FROM provisioning_profiles WHERE id = ?")->execute([$id]);
        Router::logOp('profile_delete', 'ID: ' . $id);
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
        $expiresAt = '';
        $content = file_get_contents($path);
        if (preg_match('/<key>application-identifier<\/key>\s*<string>(.+?)<\/string>/s', $content, $m)) {
            // Format: TEAMID.com.example.app — skip the Team ID prefix
            $fullId = $m[1];
            $dotPos = strpos($fullId, '.');
            $bid = $dotPos !== false ? substr($fullId, $dotPos + 1) : $fullId;
        }
        if (preg_match('/<key>ExpirationDate<\/key>\s*<date>(.+?)<\/date>/s', $content, $m)) {
            $expiresAt = $m[1];
        }
        $pdo = Database::connection();
        $pdo->prepare("INSERT INTO provisioning_profiles (app_id, cert_id, name, uuid, profile_path, bundle_id, profile_type, expires_at) VALUES (?, ?, ?, '', ?, ?, 'app-store', ?)")
            ->execute([$appId, $certId, $name, $path, $bid, $expiresAt]);
        Router::logOp('profile_upload', $name);
        return Router::json(['success' => true, 'id' => $pdo->lastInsertId(), 'bundle_id' => $bid]);
    }

    /**
     * Parse plist data, supporting both XML and binary (bplist) formats.
     * Falls back to Python plistlib for binary plists.
     */
    public static function parsePlistData(string $plistBytes): array
    {
        // Try XML plist first
        $data = [];
        if (preg_match('/<key>CFBundleIdentifier<\/key>\s*<string>(.+?)<\/string>/s', $plistBytes, $m)) $data['CFBundleIdentifier'] = $m[1];
        if (preg_match('/<key>CFBundleShortVersionString<\/key>\s*<string>(.+?)<\/string>/s', $plistBytes, $m)) $data['CFBundleShortVersionString'] = $m[1];
        if (preg_match('/<key>CFBundleVersion<\/key>\s*<string>(.+?)<\/string>/s', $plistBytes, $m)) $data['CFBundleVersion'] = $m[1];
        if (preg_match('/<key>CFBundleDisplayName<\/key>\s*<string>(.+?)<\/string>/s', $plistBytes, $m)) $data['CFBundleDisplayName'] = $m[1];
        if (preg_match('/<key>CFBundleName<\/key>\s*<string>(.+?)<\/string>/s', $plistBytes, $m)) $data['CFBundleName'] = $m[1];
        
        // If XML parsing succeeded, return immediately
        if (!empty($data)) return $data;
        
        // Binary plist detection: starts with bplist magic bytes
        if (substr($plistBytes, 0, 6) !== 'bplist') return $data;
        
        // Use Python plistlib to parse binary plist
        $tmpFile = tempnam(sys_get_temp_dir(), 'plist_');
        file_put_contents($tmpFile, $plistBytes);
        try {
            $script = 'import plistlib,json,sys; d=plistlib.load(open(sys.argv[1],"rb")); print(json.dumps({k:d.get(k,"") for k in ["CFBundleIdentifier","CFBundleShortVersionString","CFBundleVersion","CFBundleDisplayName","CFBundleName"]}))';
            $json = shell_exec('python3 -c ' . escapeshellarg($script) . ' ' . escapeshellarg($tmpFile) . ' 2>/dev/null');
            if ($json) {
                $parsed = json_decode($json, true);
                if (is_array($parsed)) $data = $parsed;
            }
        } catch (\Throwable $e) {}
        @unlink($tmpFile);
        return $data;
    }

    public static function parseIpa(): string
    {
        if (empty($_FILES['ipa_file'])) return Router::json(['error' => 'IPA file required'], 400);
        $tmp = $_FILES['ipa_file']['tmp_name'];
        $size = filesize($tmp);
        if ($size > 500 * 1024 * 1024) return Router::json(['error' => 'IPA too large'], 400);
        // Fast extraction: unzip -p streams only Info.plist without decompressing the whole archive
        $plist = shell_exec('unzip -p ' . escapeshellarg($tmp) . ' Payload/*.app/Info.plist 2>/dev/null');
        $data = $plist ? self::parsePlistData($plist) : [];
        return Router::json([
            'success' => true, 'file_size' => round($size / 1024 / 1024, 2) . ' MB',
            'name' => $data['CFBundleDisplayName'] ?? $data['CFBundleName'] ?? 'Unknown',
            'bundle_id' => $data['CFBundleIdentifier'] ?? '', 'version' => $data['CFBundleShortVersionString'] ?? '',
            'build' => $data['CFBundleVersion'] ?? '',
        ]);
    }

    public static function getSettings(): string
    {
        $pdo = Database::connection();
        $s = [];
        foreach ($pdo->query("SELECT * FROM settings") as $row) $s[$row['key']] = $row['value'];
unset($s["admin_password"]);
        return Router::json(array_merge([
            'apple_id' => '', 'app_password' => '', 'github_token' => Config::get('github.token', ''),
            'webhook_url' => '', 'webhook_secret' => '',
            'wechat_webhook' => '', 'dingtalk_webhook' => '', 'dingtalk_secret' => '',
            'telegram_bot_token' => '', 'telegram_chat_id' => '',
        ], $s));
    }

    public static function saveSettings(): string
    {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input) return Router::json(['error' => 'Invalid'], 400);
        $pdo = Database::connection();

        // Handle password change
        if (!empty($input['new_password'])) {
            $hash = password_hash($input['new_password'], PASSWORD_BCRYPT);
            $pdo->prepare("INSERT OR REPLACE INTO settings (key, value, updated_at) VALUES ('admin_password', ?, datetime('now'))")
                ->execute([$hash]);
            // Also sync to users table for admin accounts
            $pdo->prepare("UPDATE users SET password_hash = ? WHERE role = 'admin'")
                ->execute([$hash]);
            unset($input['new_password'], $input['confirm_password']);
            Router::logOp('password_change', 'Password updated');
        }

        foreach ($input as $k => $v) {
            $pdo->prepare("INSERT OR REPLACE INTO settings (key, value, updated_at) VALUES (?, ?, datetime('now'))")
                ->execute([$k, $v]);
        }
        Router::logOp('settings_save', implode(',', array_keys($input)));
        return Router::json(['success' => true]);
    }

    public static function clearLogs(): string
    {
        $pdo = \TfSigner\Core\Database::connection();
        $pdo->exec("DELETE FROM operation_logs");
        \TfSigner\Core\Router::logOp('logs_clear', 'All logs cleared');
        return \TfSigner\Core\Router::json(['success' => true, 'message' => 'All logs cleared']);
    }

    public static function workerStatus(): string
    {
        $running = !empty(trim(shell_exec("pgrep -f [w]orker.php 2>/dev/null") ?: ""));
        return Router::json(['running' => $running]);
    }

    public static function dashboardStats(): string
    {
        $pdo = Database::connection();
        $stats = [];
        foreach ($pdo->query("SELECT status, COUNT(*) as cnt FROM tasks GROUP BY status") as $r) $stats[$r['status']] = $r['cnt'];
        $stats += ['pending' => 0, 'processing' => 0, 'completed' => 0, 'failed' => 0];
        $apps = (int) $pdo->query("SELECT COUNT(*) FROM apps")->fetchColumn();
        $workerRunning = !empty(trim(shell_exec("pgrep -f '[w]orker.php' 2>/dev/null") ?: ""));
        $alerts = [];
        foreach ($pdo->query("SELECT name, type, expires_at FROM certificates WHERE is_active = 1 AND expires_at IS NOT NULL AND expires_at != ''") as $c) {
            if (!$c['expires_at']) continue;
            $days = (int)((strtotime($c['expires_at']) - time()) / 86400);
            if ($days < 30) $alerts[] = ['name' => $c['name'], 'type' => '证书', 'days' => $days, 'urgent' => $days < 7];
        }
        foreach ($pdo->query("SELECT pp.name, pp.expires_at, a.name as app_name FROM provisioning_profiles pp LEFT JOIN apps a ON pp.app_id = a.id WHERE pp.is_active = 1 AND pp.expires_at IS NOT NULL AND pp.expires_at != ''") as $p) {
            if (!$p['expires_at']) continue;
            $days = (int)((strtotime($p['expires_at']) - time()) / 86400);
            if ($days < 30) $alerts[] = ['name' => ($p['app_name'] ?: '') . ' ' . $p['name'], 'type' => '描述文件', 'days' => $days, 'urgent' => $days < 7];
        }
        $recent = $pdo->query("SELECT t.*, a.name as app_name FROM tasks t LEFT JOIN apps a ON t.app_id = a.id ORDER BY t.updated_at DESC LIMIT 10")->fetchAll();
        // Account health summary
        $accountHealth = ["total" => 0, "active" => 0, "blocked" => 0, "accounts" => []];
        $pdo->exec("CREATE TABLE IF NOT EXISTS apple_accounts (id INTEGER PRIMARY KEY AUTOINCREMENT, apple_id TEXT NOT NULL UNIQUE, app_password TEXT NOT NULL, note TEXT DEFAULT '', status TEXT DEFAULT 'active', last_error TEXT DEFAULT '', created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
        $accts = $pdo->query("SELECT id, apple_id, note, status, last_error FROM apple_accounts ORDER BY id")->fetchAll();
        foreach ($accts as $a) {
            $accountHealth["total"]++;
            if ($a["status"] === "active") $accountHealth["active"]++;
            else $accountHealth["blocked"]++;
            $accountHealth["accounts"][] = $a;
        }
        return Router::json(["stats" => $stats, "apps" => $apps, "worker" => ["running" => $workerRunning], "alerts" => $alerts, "recent_tasks" => $recent, "account_health" => $accountHealth]);
    }

    public static function taskCallback(int $id): string
    {
        $input = json_decode(file_get_contents('php://input'), true);
        $s = new TaskService();
        $st = $input['status'] ?? 'completed';
        if (!in_array($st, ['completed', 'failed'])) {
            return Router::json(['error' => 'Invalid status'], 400);
        }
        $s->updateStatus($id, $st, error: $input['error'] ?? null, result: $input['result'] ?? null, progress: 100);
        Router::logOp('task_callback', "Task #{$id} -> {$st}");
        
        // Trigger auto-submit to TestFlight in background after successful upload
        if ($st === 'completed') {
            $scriptPath = realpath(__DIR__ . '/../../autosubmit.php');
            $logFile = \TfSigner\Core\Config::get('storage.logs') . '/autosubmit.log';
            if ($scriptPath) {
                exec(sprintf('nohup php %s %d >> %s 2>&1 &', escapeshellarg($scriptPath), $id, escapeshellarg($logFile)));
            }
        }
        
        return Router::json(['success' => true, 'task_id' => $id, 'status' => $st]);
    }

    /**
     * Auto-submit to TestFlight after upload succeeds (called from autosubmit.php)
     */
    public static function autoSubmitAfterUpload(int $taskId): array
    {
        $pdo = Database::connection();
        $task = $pdo->prepare("SELECT t.*, a.bundle_id, a.name as app_name FROM tasks t LEFT JOIN apps a ON t.app_id = a.id WHERE t.id = ?");
        $task->execute([$taskId]);
        $task = $task->fetch();
        
        if (!$task || !in_array($task['type'] ?? '', ['github_sign', 'sign_and_upload', 'sign_only', 'upload_only'])) {
            return ['success' => false, 'message' => 'Task not eligible for auto-submit'];
        }
        
        $apiKeyId = (int)($task["api_key_id"] ?? 0);
        if ($apiKeyId > 0) {
            $akStmt = $pdo->prepare("SELECT * FROM api_keys WHERE id = ?");
            $akStmt->execute([$apiKeyId]);
            $apiKey = $akStmt->fetch();
        }
        if (empty($apiKey)) {
            $apiKey = $pdo->query("SELECT * FROM api_keys LIMIT 1")->fetch();
        }
        if (!$apiKey) {
            \TfSigner\Core\Logger::warning('No API key configured, skipping auto-submit');
            return ['success' => false, 'message' => 'No App Store Connect API key configured'];
        }
        
        $keyPath = tempnam(sys_get_temp_dir(), 'apikey_');
        file_put_contents($keyPath, $apiKey['key_content']);
        
        try {
            $api = new \TfSigner\Services\AppleApi($apiKey['issuer_id'], $apiKey['key_id'], $keyPath);
            
            $bundleId = $task['bundle_id'] ?? '';
            if (empty($bundleId)) {
                return ['success' => false, 'message' => 'Bundle ID missing from task'];
            }
            
            $app = $api->getAppByBundleId($bundleId);
            if (!$app) {
                return ['success' => false, 'message' => 'App not found in App Store Connect for bundle: ' . $bundleId];
            }
            
                        // Version: from override > IPA metadata > empty (no filter)
            $version = $task['override_version'] ?? '';
            if (empty($version)) {
                $ipaPath = $task['output_ipa'] ?? '';
                if (empty($ipaPath) || !file_exists($ipaPath)) {
                    $ipaPath = $task['input_ipa'] ?? '';
                }
                if (!empty($ipaPath) && file_exists($ipaPath)) {
                    $infoPlist = shell_exec("unzip -p " . escapeshellarg($ipaPath) . " Payload/*.app/Info.plist 2>/dev/null");
                    if ($infoPlist && preg_match("/<key>CFBundleShortVersionString<\/key>\s*<string>(.+?)<\/string>/s", $infoPlist, $vm)) {
                        $version = $vm[1];
                    }
                }
            }
            $result = $api->autoSubmitToTestFlight($app['id'], $version ?: '', 'Auto TF Group');
            
            $link = $result['public_link'] ?? '';
            if ($link) {
                $msg = 'Uploaded to App Store Connect. TestFlight: ' . $link . ' (pending review)';
                $s = new \TfSigner\Services\TaskService();
                $s->updateStatus($taskId, 'completed', result: $msg, progress: 100);
            }
            
            \TfSigner\Core\Logger::info('Auto-submit completed', ['task_id' => $taskId, 'link' => $link]);
            return $result;
        } catch (\Throwable $e) {
            \TfSigner\Core\Logger::error('Auto-submit failed', ['task_id' => $taskId, 'error' => $e->getMessage()]);
            try {
                $s = new \TfSigner\Services\TaskService();
                $s->updateStatus($taskId, 'completed', result: 'Uploaded to App Store Connect (⚠️ auto TF submit failed: ' . substr($e->getMessage(), 0, 200) . ')', progress: 100);
            } catch (\Throwable $ie) {}
            return ['success' => false, 'message' => $e->getMessage()];
        } finally {
            @unlink($keyPath);
        }
    }

    public static function health(): string
    {
        $pdo = Database::connection();
        $dbOk = false;
        try { $pdo->query("SELECT 1"); $dbOk = true; } catch (\Throwable $e) {}
        return Router::json(['status' => $dbOk ? 'ok' : 'degraded', 'version' => Config::get('app.version'), 'php' => PHP_VERSION, 'database' => $dbOk ? 'connected' : 'error']);
    }

    // === IPA Management ===
    public static function listIpas(): string
    {
        $ipaDir = Config::get('storage.ipas');
        $files = [];
        $totalSize = 0;
        if (is_dir($ipaDir)) {
            $pdo = Database::connection();
            foreach (scandir($ipaDir) ?: [] as $f) {
                if ($f === '.' || $f === '..') continue;
                $path = $ipaDir . '/' . $f;
                if (!is_file($path)) continue;
                if (pathinfo($path, PATHINFO_EXTENSION) !== 'ipa') continue;
                $size = filesize($path);
                $totalSize += $size;
                $taskCount = $pdo->prepare("SELECT COUNT(*) FROM tasks WHERE input_ipa = ? OR output_ipa = ?");
                $taskCount->execute([$path, $path]);
                $files[] = [
                    'name' => $f,
                    'size' => self::formatBytes($size),
                    'size_bytes' => $size,
                    'mtime' => date('Y-m-d H:i:s', filemtime($path)),
                    'task_count' => (int)$taskCount->fetchColumn(),
                ];
            }
        }
        usort($files, fn($a, $b) => $b['size_bytes'] <=> $a['size_bytes']);
        return Router::json([
            'ipa_files' => $files,
            'total_size' => self::formatBytes($totalSize),
            'count' => count($files),
        ]);
    }


    public static function uploadIpa(): string
    {
        if (empty($_FILES["ipa_file"])) return Router::json(["error" => "IPA file required"], 400);
        $file = $_FILES["ipa_file"];
        if (pathinfo($file["name"], PATHINFO_EXTENSION) !== "ipa") return Router::json(["error" => "Only .ipa files allowed"], 400);
        $destName = "input_" . time() . "_" . rand(1000,9999) . "_" . preg_replace("/[^a-zA-Z0-9._-]/", "_", $file["name"]);
        $ipaDir = Config::get("storage.ipas");
        $destPath = $ipaDir . "/" . $destName;
        if (!move_uploaded_file($file["tmp_name"], $destPath)) {
            return Router::json(["error" => "Failed to save file"], 500);
        }
        Router::logOp("ipa_upload", $destName . " (" . round(filesize($destPath)/1048576,1) . "MB)");
        return Router::json(["success" => true, "filename" => $destName, "size" => filesize($destPath)]);
    }
    public static function deleteIpa(): string
    {
        $input = json_decode(file_get_contents('php://input'), true);
        $filename = $input['filename'] ?? '';
        if (!$filename || !preg_match('/\.ipa$/i', $filename)) return Router::json(['error' => 'Valid .ipa filename required'], 400);
        $ipaDir = Config::get('storage.ipas');
        $path = $ipaDir . '/' . basename($filename);
        if (!file_exists($path)) return Router::json(['error' => 'File not found'], 404);
        
        // Nullify references in tasks so they don't point to deleted file
        $pdo = Database::connection();
        $pdo->prepare("UPDATE tasks SET input_ipa = NULL WHERE input_ipa = ?")->execute([$path]);
        $pdo->prepare("UPDATE tasks SET output_ipa = NULL WHERE output_ipa = ?")->execute([$path]);
        
        unlink($path);
        Router::logOp('ipa_delete', $filename);
        return Router::json(['success' => true]);
    }

    private static function formatBytes(int $bytes): string
    {
        if ($bytes >= 1073741824) return round($bytes / 1073741824, 2) . ' GB';
        if ($bytes >= 1048576) return round($bytes / 1048576, 2) . ' MB';
        if ($bytes >= 1024) return round($bytes / 1024, 2) . ' KB';
        return $bytes . ' B';
    }

    // === Statistics ===
    public static function getStats(): string
    {
        $period = (int)($_GET['period'] ?? 30);
        $pdo = Database::connection();

        $summary = ['pending' => 0, 'processing' => 0, 'completed' => 0, 'failed' => 0, 'total' => 0];
        foreach ($pdo->query("SELECT status, COUNT(*) as cnt FROM tasks GROUP BY status") as $r) {
            $summary[$r['status']] = (int)$r['cnt'];
            $summary['total'] += (int)$r['cnt'];
        }

        $stmt = $pdo->prepare("
            SELECT date(created_at) as d,
                SUM(CASE WHEN status='completed' THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN status='failed' THEN 1 ELSE 0 END) as failed,
                COUNT(*) as total
            FROM tasks WHERE created_at >= datetime('now', '-' || ? || ' days')
            GROUP BY d ORDER BY d
        ");
        $stmt->execute([$period]);
        $daily = $stmt->fetchAll();

        $byApp = $pdo->query("
            SELECT COALESCE(a.name, 'Unknown') as name, COUNT(*) as count
            FROM tasks t LEFT JOIN apps a ON t.app_id = a.id
            GROUP BY a.name ORDER BY count DESC LIMIT 10
        ")->fetchAll();

        $avgTime = $pdo->query("
            SELECT ROUND(AVG((julianday(finished_at) - julianday(started_at)) * 1440), 1)
            FROM tasks WHERE started_at IS NOT NULL AND finished_at IS NOT NULL
        ")->fetchColumn();

        return Router::json([
            'summary' => $summary,
            'daily' => $daily,
            'by_app' => $byApp,
            'avg_time_minutes' => $avgTime ? round((float)$avgTime, 1) . ' min' : '-',
        ]);
    }

    // === Users management ===
    public static function listUsers(): string
    {
        $pdo = Database::connection();
        $pdo->exec("CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT UNIQUE NOT NULL,
            password_hash TEXT NOT NULL,
            role TEXT DEFAULT 'user',
            permissions TEXT DEFAULT '[]',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            last_login DATETIME
        )");
        $count = (int) $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
        if ($count == 0) {
            $hash = $pdo->query("SELECT value FROM settings WHERE key='admin_password'")->fetchColumn();
            $pdo->prepare("INSERT INTO users (username, password_hash, role) VALUES ('admin', ?, 'admin')")
                ->execute([$hash ?: password_hash('admin123', PASSWORD_BCRYPT)]);
        }
        return Router::json($pdo->query("SELECT id, username, role, permissions, created_at, last_login FROM users ORDER BY id")->fetchAll());
    }

    public static function createUser(): string
    {
        $input = json_decode(file_get_contents('php://input'), true);
        if (empty($input['username']) || empty($input['password'])) {
            return Router::json(['error' => 'Username and password required'], 400);
        }
        $pdo = Database::connection();
        $pdo->exec("CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT UNIQUE NOT NULL,
            password_hash TEXT NOT NULL,
            role TEXT DEFAULT 'user',
            permissions TEXT DEFAULT '[]',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            last_login DATETIME
        )");
        try {
            $permissions = json_encode($input['permissions'] ?? [], JSON_UNESCAPED_UNICODE);
            $stmt = $pdo->prepare("INSERT INTO users (username, password_hash, role, permissions) VALUES (?, ?, ?, ?)");
            $stmt->execute([$input['username'], password_hash($input['password'], PASSWORD_BCRYPT), $input['role'] ?? 'user', $permissions]);
            Router::logOp('user_create', $input['username']);
            return Router::json(['success' => true, 'id' => $pdo->lastInsertId()]);
        } catch (\Throwable $e) {
            return Router::json(['error' => 'Username already exists'], 400);
        }
    }

    public static function deleteUser(int $id): string
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
        $stmt->execute([$id]);
        $user = $stmt->fetch();
        if (!$user) return Router::json(['error' => 'Not found'], 404);
        $adminCount = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role='admin'")->fetchColumn();
        if ($user['role'] === 'admin' && $adminCount <= 1) {
            return Router::json(['error' => 'Cannot delete last admin'], 400);
        }
        $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$id]);
        Router::logOp('user_delete', 'ID: ' . $id);
        return Router::json(['success' => true]);
    }


    public static function updateUser(int $id): string
    {
        $input = json_decode(file_get_contents("php://input"), true);
        if (!$input) return Router::json(["error" => "Invalid JSON"], 400);
        $pdo = Database::connection();
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$id]);
        $user = $stmt->fetch();
        if (!$user) return Router::json(["error" => "User not found"], 404);

        $changes = [];
        $params = [];

        if (isset($input["username"])) {
            $changes[] = "username = ?";
            $params[] = $input["username"];
        }
        if (!empty($input["password"])) {
            $changes[] = "password_hash = ?";
            $params[] = password_hash($input["password"], PASSWORD_BCRYPT);
        }
        if (isset($input["role"])) {
            if ($user["role"] === "admin" && $input["role"] !== "admin") {
                $adminCount = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role='admin'")->fetchColumn();
                if ($adminCount <= 1) {
                    return Router::json(["error" => "Cannot change role of last admin"], 400);
                }
            }
            $changes[] = "role = ?";
            $params[] = $input["role"];
        }
        if (isset($input["permissions"])) {
            $changes[] = "permissions = ?";
            $params[] = json_encode($input["permissions"], JSON_UNESCAPED_UNICODE);
        }

        if (empty($changes)) {
            return Router::json(["error" => "No fields to update"], 400);
        }

        $params[] = $id;
        $sql = "UPDATE users SET " . implode(", ", $changes) . " WHERE id = ?";
        $pdo->prepare($sql)->execute($params);

        if ($user["role"] === "admin" && !empty($input["password"])) {
            $pdo->prepare("INSERT OR REPLACE INTO settings (key, value, updated_at) VALUES ('admin_password', ?, datetime('now'))")
                ->execute([password_hash($input["password"], PASSWORD_BCRYPT)]);
        }

        Router::logOp("user_update", "ID: " . $id);
        return Router::json(["success" => true]);
    }

    // === Apple API: auto-generate certificate ===
    public static function appleGenerateCert(): string
    {
        $input = json_decode(file_get_contents('php://input'), true);
        $type = $input['type'] ?? 'IOS_DISTRIBUTION';
        $name = !empty($input['name']) ? $input['name'] : 'Auto Cert ' . date('Ymd');
        $pdo = Database::connection();
        
        $apiKeyId = (int)($input["api_key_id"] ?? 0);
        if ($apiKeyId > 0) {
            $akStmt = $pdo->prepare("SELECT * FROM api_keys WHERE id = ?");
            $akStmt->execute([$apiKeyId]);
            $apiKey = $akStmt->fetch();
        }
        if (empty($apiKey)) {
            $apiKey = $pdo->query("SELECT * FROM api_keys LIMIT 1")->fetch();
        }
        $issuerId = $apiKey['issuer_id'] ?? null;
        $keyId = $apiKey['key_id'] ?? null;
        $keyContent = $apiKey['key_content'] ?? null;
        
        if (!$issuerId || !$keyId || !$keyContent) {
            return Router::json(['error' => 'Please configure Apple API Key in Settings first'], 400);
        }
        
        $keyPath = sys_get_temp_dir() . '/apple_key_' . uniqid() . '.p8';
        file_put_contents($keyPath, $keyContent);
        chmod($keyPath, 0600);
        
        try {
            $api = new \TfSigner\Services\AppleApi($issuerId, $keyId, $keyPath);
            
            // Generate key + CSR using PHP OpenSSL extension (exec is disabled in FPM)
            $cn = trim(preg_replace('/[\x00-\x1f\x7f]/', '', $name ?: 'Auto Generated Certificate'));
            if (empty($cn)) $cn = 'Auto Generated Certificate';
            
            // Minimal DN — only commonName to avoid OpenSSL field-length quirks
            $dn = ['commonName' => $cn];
            $privKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            if (!$privKey) throw new \RuntimeException('OpenSSL keygen failed: ' . openssl_error_string());
            openssl_pkey_export($privKey, $privKeyOut);
            
            $sslConfig = '/etc/pki/tls/openssl.cnf';
            if (!file_exists($sslConfig)) $sslConfig = '/etc/ssl/openssl.cnf';
            $csrOpts = ['digest_alg' => 'sha256'];
            if (file_exists($sslConfig)) $csrOpts['config'] = $sslConfig;
            
            $csr = openssl_csr_new($dn, $privKey, $csrOpts);
            if (!$csr) throw new \RuntimeException('OpenSSL CSR failed: ' . openssl_error_string() . ' | dn=' . json_encode($dn));
            openssl_csr_export($csr, $csrOut);
            
            $result = $api->createCertificate($csrOut, $type);
            $certContent = $result['data']['attributes']['certificateContent'] ?? '';
            if (!$certContent) return Router::json(['error' => 'Apple API returned no certificate'], 500);
            
            // Apple API returns raw Base64 DER — wrap in PEM for OpenSSL parsing
            if (strpos($certContent, '-----BEGIN') === false) {
                $certPem = "-----BEGIN CERTIFICATE-----\n" . chunk_split($certContent, 64, "\n") . "-----END CERTIFICATE-----\n";
            } else {
                $certPem = $certContent;
            }
            
            $certsDir = \TfSigner\Core\Config::get('storage.certs');
            $baseName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $name) . '_' . time();
            $certPath = $certsDir . '/' . $baseName . '.pem';
            $keyPathLocal = $certsDir . '/' . $baseName . '.key';
            file_put_contents($certPath, $certPem);
            file_put_contents($keyPathLocal, $privKeyOut);
            chmod($keyPathLocal, 0600);
            
            $certInfo = openssl_x509_parse($certPem);
            $serial = $certInfo['serialNumber'] ?? '';
            $expiresAt = date('Y-m-d H:i:s', $certInfo['validTo_time_t'] ?? time());
            
            $appleCertResourceId = $result['data']['id'] ?? '';
            $acct = $pdo->query("SELECT note FROM apple_accounts WHERE status = 'active' LIMIT 1")->fetch();
            $accountTeamId = $acct['note'] ?? '';
            $pdo->prepare("INSERT INTO certificates (name, type, cert_path, key_path, password, serial, expires_at, team_id, is_active, apple_cert_id) VALUES (?, ?, ?, ?, '', ?, ?, ?, 1, ?)")
                ->execute([$name, $type === 'IOS_DISTRIBUTION' ? 'distribution' : 'development', $certPath, $keyPathLocal, $serial, $expiresAt, $accountTeamId, $appleCertResourceId]);
            
            Router::logOp('cert_apple_gen', $name);
            return Router::json(['success' => true, 'id' => $pdo->lastInsertId(), 'name' => $name, 'expires_at' => $expiresAt]);
        } catch (\Throwable $e) {
            // 409 = certificate already exists — reuse the existing one
            if (strpos($e->getMessage(), "409") !== false || strpos($e->getMessage(), "already have a current") !== false) {
                $existingCerts = $api->listCertificates($type);
                $appleCert = $existingCerts["data"][0] ?? null;
                if ($appleCert) {
                    $localCert = $pdo->prepare("SELECT id, name, cert_path, key_path FROM certificates WHERE apple_cert_id = ? AND is_active = 1");
                    $localCert->execute([$appleCert["id"]]);
                    $local = $localCert->fetch();
                    if ($local) {
                        Router::logOp("cert_apple_reuse", $local["name"]);
                        return Router::json(["success" => true, "id" => $local["id"], "name" => $local["name"], "reused" => true]);
                    }
                    return Router::json(["error" => "Apple 账号已存在分发证书，但本地未同步。请前往 Apple Developer 下载后手动导入，或删除本地证书后重试。"], 409);
                }
            }
            return Router::json(["error" => $e->getMessage()], 500);
        } finally {
            @unlink($keyPath);
        }
    }

    public static function appleGenerateProfile(): string
    {
        $input = json_decode(file_get_contents('php://input'), true);
        $bundleId = $input['bundle_id'] ?? '';
        $certId = $input['cert_id'] ?? null;
        $name = !empty($input['name']) ? $input['name'] : 'Auto Profile ' . date('Ymd_His');
        if (!$bundleId) return Router::json(['error' => 'Bundle ID required'], 400);
        if (!$certId) return Router::json(['error' => 'Certificate ID required'], 400);
        
        $pdo = Database::connection();
        $cert = $pdo->prepare("SELECT * FROM certificates WHERE id = ?");
        $cert->execute([$certId]);
        $certData = $cert->fetch();
        if (!$certData) return Router::json(['error' => 'Certificate not found'], 404);
        
        $apiKeyId = (int)($input["api_key_id"] ?? 0);
        if ($apiKeyId > 0) {
            $akStmt = $pdo->prepare("SELECT * FROM api_keys WHERE id = ?");
            $akStmt->execute([$apiKeyId]);
            $apiKey = $akStmt->fetch();
        }
        if (empty($apiKey)) {
            $apiKey = $pdo->query("SELECT * FROM api_keys LIMIT 1")->fetch();
        }
        $issuerId = $apiKey['issuer_id'] ?? null;
        $keyId = $apiKey['key_id'] ?? null;
        $keyContent = $apiKey['key_content'] ?? null;
        if (!$issuerId || !$keyId || !$keyContent) {
            return Router::json(['error' => 'Please configure Apple API Key in Settings first'], 400);
        }
        
        $keyPath = sys_get_temp_dir() . '/apple_key_' . uniqid() . '.p8';
        file_put_contents($keyPath, $keyContent);
        chmod($keyPath, 0600);
        
        try {
            $api = new \TfSigner\Services\AppleApi($issuerId, $keyId, $keyPath);
            // Look up Bundle ID resource (not App Store app)
            $bundle = $api->getBundleIdByIdentifier($bundleId);
            if (!$bundle) $bundle = $api->createBundleId($bundleId, $bundleId, 'IOS');
            $bundleIdApi = $bundle['data']['id'] ?? ($bundle['id'] ?? '');
                        // Use Apple resource ID if available (from Apple API generated cert), otherwise fallback
            $appleCertRef = !empty($certData['apple_cert_id']) ? $certData['apple_cert_id'] : (string)($certData['serial'] ?: $certId);
            $profile = $api->createProfile($name, $bundleIdApi, $appleCertRef);
            $profileContent = base64_decode($profile['data']['attributes']['profileContent'] ?? '') ?: '';
            if (!$profileContent) return Router::json(['error' => 'Apple API returned no profile'], 500);
            
            $certsDir = \TfSigner\Core\Config::get('storage.certs');
            $fname = 'profile_apple_' . time() . '.mobileprovision';
            $path = $certsDir . '/' . $fname;
            file_put_contents($path, $profileContent);
            
            $profileType = !empty($input['profile_type']) ? $input['profile_type'] : 'app-store';
            $expiresAt = $profile['data']['attributes']['expirationDate'] ?? '';
            
            $pdo->prepare("INSERT INTO provisioning_profiles (app_id, cert_id, name, uuid, profile_path, bundle_id, profile_type, expires_at, is_active) VALUES (?, ?, ?, '', ?, ?, ?, ?, 1)")
                ->execute([$input['app_id'] ?? null, $certId, $name, $path, $bundleId, $profileType, $expiresAt]);
            
            Router::logOp('profile_apple_gen', $name);
            return Router::json(['success' => true, 'id' => $pdo->lastInsertId(), 'name' => $name]);
        } catch (\Throwable $e) {
            return Router::json(['error' => $e->getMessage()], 500);
        } finally {
            @unlink($keyPath);
        }
    }



    // === Apple Accounts management ===
    public static function listAppleAccounts(): string
    {
        $pdo = Database::connection();
        $pdo->exec("CREATE TABLE IF NOT EXISTS apple_accounts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            apple_id TEXT NOT NULL UNIQUE,
            app_password TEXT NOT NULL,
            note TEXT DEFAULT '',
            status TEXT DEFAULT 'active',
            last_error TEXT DEFAULT '',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        return Router::json($pdo->query("SELECT id, apple_id, note, status, last_error, created_at FROM apple_accounts ORDER BY id")->fetchAll());
    }

    public static function createAppleAccount(): string
    {
        $input = json_decode(file_get_contents('php://input'), true);
        if (empty($input['apple_id']) || empty($input['app_password'])) {
            return Router::json(['error' => 'Apple ID and password required'], 400);
        }
        $pdo = Database::connection();
        $pdo->exec("CREATE TABLE IF NOT EXISTS apple_accounts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            apple_id TEXT NOT NULL UNIQUE,
            app_password TEXT NOT NULL,
            note TEXT DEFAULT '',
            status TEXT DEFAULT 'active',
            last_error TEXT DEFAULT '',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        $existing = $pdo->prepare("SELECT id FROM apple_accounts WHERE apple_id = ?");
        $existing->execute([$input['apple_id']]);
        if ($existing->fetch()) {
            return Router::json(['error' => 'Account already exists'], 400);
        }
        try {
            $stmt = $pdo->prepare("INSERT INTO apple_accounts (apple_id, app_password, note) VALUES (?, ?, ?)");
            $stmt->execute([$input['apple_id'], $input['app_password'], $input['note'] ?? '']);
            Router::logOp('apple_account_add', $input['apple_id']);
            return Router::json(['success' => true, 'id' => $pdo->lastInsertId()]);
        } catch (\Throwable $e) {
            return Router::json(['error' => $e->getMessage()], 400);
        }
    }

    public static function deleteAppleAccount(int $id): string
    {
        $pdo = Database::connection();
        // Nullify references in tasks
        $pdo->prepare("UPDATE tasks SET apple_account_id = NULL WHERE apple_account_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM apple_accounts WHERE id = ?")->execute([$id]);
        Router::logOp('apple_account_delete', 'ID: ' . $id);
        return Router::json(['success' => true]);
    }

    public static function retryAppleAccount(int $id): string
    {
        $pdo = Database::connection();
        $pdo->prepare("UPDATE apple_accounts SET status = 'active', last_error = '' WHERE id = ?")->execute([$id]);
        Router::logOp('apple_account_retry', 'ID: ' . $id);
        return Router::json(['success' => true]);
    }

    // === API Keys management ===
    public static function listApiKeys(): string
    {
        $pdo = Database::connection();
        $pdo->exec("CREATE TABLE IF NOT EXISTS api_keys (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            issuer_id TEXT NOT NULL,
            key_id TEXT NOT NULL,
            key_content TEXT NOT NULL,
            note TEXT DEFAULT '',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        return Router::json($pdo->query("SELECT id, issuer_id, key_id, note, created_at FROM api_keys ORDER BY id")->fetchAll());
    }

    public static function createApiKey(): string
    {
        $input = json_decode(file_get_contents('php://input'), true);
        if (empty($input['issuer_id']) || empty($input['key_id']) || empty($input['key_content'])) {
            return Router::json(['error' => 'Issuer ID, Key ID, and Key content required'], 400);
        }
        $pdo = Database::connection();
        $pdo->exec("CREATE TABLE IF NOT EXISTS api_keys (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            issuer_id TEXT NOT NULL,
            key_id TEXT NOT NULL,
            key_content TEXT NOT NULL,
            note TEXT DEFAULT '',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        $existing = $pdo->prepare("SELECT id FROM api_keys WHERE issuer_id = ? AND key_id = ?");
        $existing->execute([$input['issuer_id'], $input['key_id']]);
        if ($existing->fetch()) {
            return Router::json(['error' => 'API Key already exists'], 400);
        }
        try {
            $stmt = $pdo->prepare("INSERT INTO api_keys (issuer_id, key_id, key_content, note) VALUES (?, ?, ?, ?)");
            $stmt->execute([$input['issuer_id'], $input['key_id'], $input['key_content'], $input['note'] ?? '']);
            Router::logOp('api_key_add', $input['key_id']);
            return Router::json(['success' => true, 'id' => $pdo->lastInsertId()]);
        } catch (\Throwable $e) {
            return Router::json(['error' => $e->getMessage()], 400);
        }
    }

    public static function deleteApiKey(int $id): string
    {
        $pdo = Database::connection();
        // Nullify references in tasks
        $pdo->prepare("UPDATE tasks SET api_key_id = NULL WHERE api_key_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM api_keys WHERE id = ?")->execute([$id]);
        Router::logOp('api_key_delete', 'ID: ' . $id);
        return Router::json(['success' => true]);
    }

}
