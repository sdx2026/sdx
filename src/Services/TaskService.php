<?php
namespace TfSigner\Services;

use TfSigner\Core\Config;
use TfSigner\Core\Database;
use TfSigner\Core\Logger;
use TfSigner\Core\ErrorCodes;

class TaskService
{
    public function create(array $params): array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare("
            INSERT INTO tasks (app_id, type, input_ipa, cert_id, profile_id, apple_id, app_password, priority, override_version, override_build, apple_account_id, api_key_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $params['app_id'] ?? null,
            $params['type'] ?? 'sign_and_upload',
            $params['input_ipa'],
            $params['cert_id'] ?? null,
            $params['profile_id'] ?? null,
            $params['apple_id'] ?? '',
            $params['app_password'] ?? '',
            $params['priority'] ?? 0,
            $params['override_version'] ?? '',
            $params['override_build'] ?? '',
            $params['apple_account_id'] ?? null,
            $params['api_key_id'] ?? null,
        ]);
        $taskId = (int)$pdo->lastInsertId();
        $this->notifyAll('task.created', ['task_id' => $taskId]);
        Logger::info("Task created", ['id' => $taskId, 'type' => $params['type'] ?? 'sign_and_upload']);
        return $this->get($taskId);
    }

    public function get(int $id): ?array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare("SELECT t.*, a.name as app_name FROM tasks t LEFT JOIN apps a ON t.app_id = a.id WHERE t.id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public function list(array $filters = []): array
    {
        $pdo = Database::connection();
        $where = [];
        $params = [];

        if (!empty($filters['status'])) {
            $where[] = 't.status = ?';
            $params[] = $filters['status'];
        }
        if (!empty($filters['app_id'])) {
            $where[] = 't.app_id = ?';
            $params[] = $filters['app_id'];
        }
        if (!empty($filters['search'])) {
            $where[] = '(a.name LIKE ? OR t.id LIKE ? OR t.type LIKE ?)';
            $s = '%' . $filters['search'] . '%';
            $params[] = $s; $params[] = $s; $params[] = $s;
        }
        if (!empty($filters['date_from'])) {
            $where[] = 't.created_at >= ?';
            $params[] = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where[] = 't.created_at <= ?';
            $params[] = $filters['date_to'] . ' 23:59:59';
        }

        $sql = "SELECT t.*, a.name as app_name FROM tasks t LEFT JOIN apps a ON t.app_id = a.id";
        if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
        $sql .= " ORDER BY t.priority DESC, t.created_at DESC";

        $page = max(1, (int)($filters['page'] ?? 1));
        $perPage = min(50, max(10, (int)($filters['per_page'] ?? 20)));
        $offset = ($page - 1) * $perPage;

        $countSql = "SELECT COUNT(*) FROM tasks t LEFT JOIN apps a ON t.app_id = a.id";
        if ($where) $countSql .= ' WHERE ' . implode(' AND ', $where);
        $countStmt = $pdo->prepare($countSql);
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $sql .= " LIMIT {$perPage} OFFSET {$offset}";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return [
            'items' => $stmt->fetchAll(),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => max(1, (int)ceil($total / $perPage)),
        ];
    }

    public function getNextPending(): ?array
    {
        $pdo = Database::connection();
        $pdo->exec("BEGIN IMMEDIATE");
        try {
            // Recover orphaned processing tasks: increment retries, mark as failed if exhausted
            $pdo->exec("UPDATE tasks SET status = 'failed', error = 'Orphaned and max retries exhausted' WHERE status = 'processing' AND started_at < datetime('now', '-30 minutes') AND retries >= max_retries - 1");
            $pdo->exec("UPDATE tasks SET status = 'pending', error = 'Recovered from orphaned state', retries = retries + 1 WHERE status = 'processing' AND started_at < datetime('now', '-30 minutes') AND retries < max_retries - 1");
            
            $stmt = $pdo->prepare("
                SELECT id FROM tasks 
                WHERE status = 'pending' AND retries < max_retries 
                ORDER BY priority DESC, created_at ASC 
                LIMIT 1
            ");
            $stmt->execute();
            $row = $stmt->fetch();
            if (!$row) {
                $pdo->exec("COMMIT");
                return null;
            }
            $now = date('Y-m-d H:i:s');
            $pdo->prepare("UPDATE tasks SET status = 'processing', started_at = ?, updated_at = ? WHERE id = ?")
                ->execute([$now, $now, $row['id']]);
            $pdo->exec("COMMIT");
            
            return $this->get((int)$row['id']);
        } catch (\Throwable $e) {
            $pdo->exec("ROLLBACK");
            throw $e;
        }
    }

    public function updateStatus(int $id, string $status, ?string $error = null, ?string $result = null, int $progress = 0): void
    {
        $pdo = Database::connection();
        $now = date('Y-m-d H:i:s');
        $fields = ['status = ?', 'progress = ?', 'updated_at = ?'];
        $values = [$status, $progress, $now];
        if ($error !== null) { $fields[] = 'error = ?'; $values[] = $error; }
        if ($result !== null) { $fields[] = 'result = ?'; $values[] = $result; }
        if ($status === 'processing') { $fields[] = 'started_at = ?'; $values[] = $now; }
        if (in_array($status, ['completed', 'failed'])) { $fields[] = 'finished_at = ?'; $values[] = $now; }
        $values[] = $id;
        $pdo->prepare("UPDATE tasks SET " . implode(', ', $fields) . " WHERE id = ?")->execute($values);
        $this->notifyAll("task.{$status}", ['task_id' => $id, 'error' => $error]);
        Logger::info("Task status updated", ['id' => $id, 'status' => $status]);
    }

    public function delete(int $id): bool
    {
        $pdo = Database::connection();
        $task = $this->get($id);
        if ($task && !empty($task['input_ipa'])) @unlink($task['input_ipa']);
        if ($task && !empty($task['output_ipa'])) @unlink($task['output_ipa']);
        $pdo->prepare("DELETE FROM tasks WHERE id = ?")->execute([$id]);
        Logger::info("Task deleted", ['id' => $id]);
        return true;
    }

    private function notifyAll(string $event, array $data): void
    {
        // Read notification settings from DB (not Config)
        $pdo = \TfSigner\Core\Database::connection();
        $settings = [];
        foreach ($pdo->query("SELECT key, value FROM settings") as $r) {
            $settings[$r['key']] = $r['value'];
        }

        // WeChat enterprise webhook (企业微信)
        $url = $settings['wechat_webhook'] ?? '';
        if ($url) {
            $secret = $settings['wechat_secret'] ?? '';
            $payload = json_encode(['msgtype' => 'markdown', 'markdown' => ['content' => '**' . $event . '**
任务ID: ' . ($data['task_id'] ?? '?') . (empty($data['error']) ? '' : '
错误: ' . $data['error'])]], JSON_UNESCAPED_UNICODE);
            $ch = curl_init($url);
            $headers = ['Content-Type: application/json'];
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_TIMEOUT => 10,
            ]);
            curl_exec($ch);
            curl_close($ch);
        }

        // Generic webhook
        $url = $settings['webhook_url'] ?? '';
        if ($url) {
            $secret = $settings['webhook_secret'] ?? '';
            $payload = json_encode(['event' => $event, 'data' => $data, 'timestamp' => time()], JSON_UNESCAPED_UNICODE);
            $ch = curl_init($url);
            $headers = ['Content-Type: application/json'];
            if ($secret) $headers[] = 'X-Signature: ' . hash_hmac('sha256', $payload, $secret);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_TIMEOUT => 10,
            ]);
            curl_exec($ch);
            curl_close($ch);
        }

        // WxPusher notification
        $wxpusherToken = $settings['wxpusher_token'] ?? '';
        $wxpusherUid = $settings['wxpusher_uid'] ?? '';
        if ($wxpusherToken && $wxpusherUid) {
            $statusMap = [
                'task.created' => '📦 新任务创建',
                'task.processing' => '⚙️ 任务处理中',
                'task.completed' => '✅ 任务完成',
                'task.failed' => '❌ 任务失败',
            ];
            $title = $statusMap[$event] ?? $event;
            $content = $title . "\n任务ID: " . ($data['task_id'] ?? '?');
            if (!empty($data['error'])) $content .= "\n错误: " . $data['error'];
            $ch = curl_init('https://wxpusher.zjiecode.com/api/send/message');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode([
                    'appToken' => $wxpusherToken,
                    'content' => $content,
                    'contentType' => 1,
                    'uids' => [$wxpusherUid],
                ]),
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_TIMEOUT => 10,
            ]);
            curl_exec($ch);
            curl_close($ch);
        }
    }

    public function process(int $taskId): array
    {
        $task = $this->get($taskId);
        if (!$task) throw new \RuntimeException("Task not found: {$taskId}");

        $type = $task['type'] ?? 'sign_and_upload';

        try {
            switch ($type) {
                case 'github_sign':
                    return $this->processGithubSign($taskId, $task);
                case 'sign_and_upload':
                case 'upload_only':
                case 'sign_only':
                    return $this->processSignAndUpload($taskId, $task);
                default:
                    return $this->processSignAndUpload($taskId, $task);
            }
        } catch (\RuntimeException $e) {
            $errMsg = $e->getMessage();
            if (strpos($errMsg, '[E') === false) {
                $errMsg = ErrorCodes::detectAndFormat($errMsg);
            }
            $this->updateStatus($taskId, 'failed', error: $errMsg);
            throw $e;
        }
    }

    private function processSignAndUpload(int $taskId, array $task): array
    {
        $type = $task['type'] ?? 'sign_and_upload';
        $this->updateStatus($taskId, 'processing', progress: 10);

        // Sign step
        if (in_array($type, ['sign_and_upload', 'sign_only'])) {
            $signer = new SigningService();
            $outputIpa = Config::get('storage.ipas') . '/output_' . $taskId . '_' . time() . '.ipa';

            $certId = (int)($task['cert_id'] ?? 0);
            $profileId = (int)($task['profile_id'] ?? 0);
            if ($certId <= 0) throw new \RuntimeException('[E1001] No signing certificate selected');
            if ($profileId <= 0) throw new \RuntimeException('[E2001] No provisioning profile selected');

            $signer->resign(
                $task['input_ipa'],
                $outputIpa,
                $certId,
                $profileId,
                $task['override_version'] ?? '',
                $task['override_build'] ?? '',
                function (int $pct, string $msg) use ($taskId) {
                    $this->updateStatus($taskId, 'processing', progress: min(10 + (int)($pct * 0.6), 70), result: $msg);
                }
            );

            // Update task with output IPA
            $pdo = Database::connection();
            $pdo->prepare("UPDATE tasks SET output_ipa = ? WHERE id = ?")->execute([$outputIpa, $taskId]);
            $task['output_ipa'] = $outputIpa;
            $this->updateStatus($taskId, 'processing', progress: 70, result: 'Signing complete');
        }

        // Upload step
        if (in_array($type, ['sign_and_upload', 'upload_only'])) {
            $ipaPath = $task['output_ipa'] ?? $task['input_ipa'];
            if (!file_exists($ipaPath)) {
                throw new \RuntimeException('[E4002] IPA file not found for upload: ' . $ipaPath);
            }

            // Resolve credentials: when apple_account_id is set, ALWAYS use DB creds
            $appleId = $task['apple_id'] ?? '';
            $appPassword = $task['app_password'] ?? '';
            $pdo = Database::connection();
            // Ignore masked/form values when a saved account is selected
            if (!empty($task['apple_account_id'])) {
                $acct = $pdo->prepare("SELECT apple_id, app_password, status FROM apple_accounts WHERE id = ?");
                $acct->execute([(int)$task['apple_account_id']]);
                $acctData = $acct->fetch();
                if ($acctData) {
                    if ($acctData['status'] === 'blocked') {
                        throw new \RuntimeException('[E5002] Apple 账号已被系统禁用，请在设置页重试后重新创建任务');
                    }
                    $appleId = $acctData['apple_id'];
                    $appPassword = $acctData['app_password'];
                }
            }
            if (empty($appleId) || empty($appPassword)) {
                $appleId = $appleId ?: ($pdo->query("SELECT value FROM settings WHERE key='apple_id'")->fetchColumn() ?: '');
                $appPassword = $appPassword ?: ($pdo->query("SELECT value FROM settings WHERE key='app_password'")->fetchColumn() ?: '');
            }

            $uploader = new UploadService();
            $this->updateStatus($taskId, 'processing', progress: 70, result: 'Starting upload...');

            $uploadResult = $uploader->upload(
                $ipaPath,
                $appleId,
                $appPassword,
                function (int $pct, string $msg) use ($taskId) {
                    $this->updateStatus($taskId, 'processing', progress: min(70 + (int)($pct * 0.25), 95), result: $msg);
                }
            );

            if (!$uploadResult['success']) {
                $errMsg = $uploadResult['error'] ?? 'Upload failed';
                // Mark Apple account as potentially blocked on auth failures
                if (stripos($errMsg, '401') !== false || stripos($errMsg, '403') !== false || stripos($errMsg, 'auth') !== false || stripos($errMsg, '-29023') !== false) {
                    if (!empty($task['apple_account_id'])) {
                        $pdo->prepare("UPDATE apple_accounts SET status = 'blocked', last_error = ? WHERE id = ?")
                            ->execute([substr($errMsg, 0, 500), (int)$task['apple_account_id']]);
                    }
                }
                $errMsg = ErrorCodes::parseITMSError($errMsg);
                throw new \RuntimeException('[E5001] Upload failed: ' . $errMsg);
            }

            $this->updateStatus($taskId, 'completed', result: 'Signed and uploaded to App Store Connect ✅', progress: 100);
            // Trigger auto-submit to TestFlight in background
            $this->triggerAutoSubmit($taskId);
            return $uploadResult;
        }

        $this->updateStatus($taskId, 'completed', result: 'Task completed', progress: 100);
        return ['success' => true];
    }

    private function processGithubSign(int $taskId, array $task): array
    {
        $pdo = Database::connection();

        // ── Validate cert and profile exist ──
        $certId = (int)($task['cert_id'] ?? 0);
        $profileId = (int)($task['profile_id'] ?? 0);
        if ($certId <= 0) throw new \RuntimeException('[E1001] No signing certificate selected. Please select a certificate when creating task');
        if ($profileId <= 0) throw new \RuntimeException('[E2001] No provisioning profile selected. Please select a profile when creating task');
        $certCheck = $pdo->prepare("SELECT id FROM certificates WHERE id = ?");
        $certCheck->execute([$certId]);
        if (!$certCheck->fetch()) throw new \RuntimeException("[E1001] Certificate not found: {$certId}");
        $profileCheck = $pdo->prepare("SELECT id FROM provisioning_profiles WHERE id = ?");
        $profileCheck->execute([$profileId]);
        if (!$profileCheck->fetch()) throw new \RuntimeException("[E2001] Profile not found: {$profileId}");

        // ── Step 1: Local signing ──
        $this->updateStatus($taskId, 'processing', progress: 5, result: 'Starting local signing...');

        $signer = new SigningService();
        $outputIpa = Config::get('storage.ipas') . '/signed_' . $taskId . '_' . time() . '.ipa';

        $signer->resign(
            $task['input_ipa'],
            $outputIpa,
            (int)($task['cert_id'] ?? 0),
            (int)($task['profile_id'] ?? 0),
            $task['override_version'] ?? '',
            $task['override_build'] ?? '',
            function (int $pct, string $msg) use ($taskId) {
                $this->updateStatus($taskId, 'processing', progress: min(5 + (int)($pct * 0.5), 55), result: $msg);
            }
        );

        $pdo->prepare("UPDATE tasks SET output_ipa = ? WHERE id = ?")->execute([$outputIpa, $taskId]);
        $this->updateStatus($taskId, 'processing', progress: 55, result: 'Local signing complete, dispatching to GitHub for upload...');

        // ── Step 2: Dispatch to GitHub Actions for upload only ──
        $appleId = $task['apple_id'] ?? '';
        $appPassword = $task['app_password'] ?? '';
        if (!empty($task['apple_account_id'])) {
            $acct = $pdo->prepare("SELECT apple_id, app_password, status FROM apple_accounts WHERE id = ?");
            $acct->execute([(int)$task['apple_account_id']]);
            $acctData = $acct->fetch();
            if ($acctData) {
                if ($acctData['status'] === 'blocked') {
                    throw new \RuntimeException('[E5002] Apple 账号已被系统禁用，请在设置页重试后重新创建任务');
                }
                $appleId = $acctData['apple_id'];
                $appPassword = $acctData['app_password'];
            }
        }
        if (empty($appleId) || empty($appPassword)) {
            $appleId = $appleId ?: ($pdo->query("SELECT value FROM settings WHERE key='apple_id'")->fetchColumn() ?: '');
            $appPassword = $appPassword ?: ($pdo->query("SELECT value FROM settings WHERE key='app_password'")->fetchColumn() ?: '');
        }

        $baseUrl = Config::get('app.url', 'https://bsj.appssign.cc');

        $gh = new \TfSigner\Services\GitHubService('sdx2026/sdx', 'upload_only.yml');
        $payload = $gh->buildUploadOnlyPayload([
            'task_id'        => (string)$taskId,
            'signed_ipa_url' => $baseUrl . '/download/' . basename($outputIpa) . '?task_id=' . $taskId,
            'apple_id'       => $appleId,
            'app_password'   => $appPassword,
        ]);

        $result = $gh->dispatch($payload);
        $this->updateStatus($taskId, 'processing', result: 'GitHub Actions upload triggered', progress: 60);
        return $result;
    }

    public function computeStats(): array
    {
        $pdo = Database::connection();
        $total = (int)$pdo->query("SELECT COUNT(*) FROM tasks")->fetchColumn();
        $completed = (int)$pdo->query("SELECT COUNT(*) FROM tasks WHERE status = 'completed'")->fetchColumn();
        $failed = (int)$pdo->query("SELECT COUNT(*) FROM tasks WHERE status = 'failed'")->fetchColumn();
        $pending = (int)$pdo->query("SELECT COUNT(*) FROM tasks WHERE status = 'pending'")->fetchColumn();
        $processing = (int)$pdo->query("SELECT COUNT(*) FROM tasks WHERE status = 'processing'")->fetchColumn();
        return [
            'total' => $total,
            'completed' => $completed,
            'failed' => $failed,
            'pending' => $pending,
            'processing' => $processing,
        ];
    }


    /**
     * Trigger auto-submit to TestFlight in background (non-blocking)
     */
    private function triggerAutoSubmit(int $taskId): void
    {
        $scriptPath = realpath(__DIR__ . '/../../autosubmit.php');
        $logFile = \TfSigner\Core\Config::get('storage.logs') . '/autosubmit.log';
        if ($scriptPath) {
            exec(sprintf('nohup php %s %d >> %s 2>&1 &', escapeshellarg($scriptPath), $taskId, escapeshellarg($logFile)));
        }
    }

}
