<?php
namespace TfSigner\Services;

use TfSigner\Core\Config;
use TfSigner\Core\Database;
use TfSigner\Core\Logger;

class TaskService
{
    /**
     * Create a new task
     */
    public function create(array $params): array
    {
        $pdo = Database::connection();

        $stmt = $pdo->prepare("
            INSERT INTO tasks (app_id, type, input_ipa, cert_id, profile_id, apple_id, app_password, priority)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
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
        ]);

        $taskId = $pdo->lastInsertId();

        // Trigger webhook if configured
        $this->triggerWebhook('task.created', ['task_id' => $taskId]);

        Logger::info("Task created", ['id' => $taskId, 'type' => $params['type'] ?? 'sign_and_upload']);

        return $this->get($taskId);
    }

    /**
     * Get task by ID
     */
    public function get(int $id): ?array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare("SELECT * FROM tasks WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * List tasks with optional filters
     */
    public function list(array $filters = []): array
    {
        $pdo = Database::connection();
        $where = [];
        $params = [];

        if (!empty($filters['status'])) {
            $where[] = 'status = ?';
            $params[] = $filters['status'];
        }
        if (!empty($filters['app_id'])) {
            $where[] = 'app_id = ?';
            $params[] = $filters['app_id'];
        }

        $sql = "SELECT t.*, a.name as app_name FROM tasks t LEFT JOIN apps a ON t.app_id = a.id";
        if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
        $sql .= " ORDER BY priority DESC, created_at DESC LIMIT 100";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Get next pending task for processing
     */
    public function getNextPending(): ?array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare("
            SELECT * FROM tasks 
            WHERE status = 'pending' AND retries < max_retries 
            ORDER BY priority DESC, created_at ASC 
            LIMIT 1
        ");
        $stmt->execute();
        return $stmt->fetch() ?: null;
    }

    /**
     * Update task status
     */
    public function updateStatus(int $id, string $status, ?string $error = null, ?string $result = null, int $progress = 0): void
    {
        $pdo = Database::connection();
        $now = date('Y-m-d H:i:s');

        $fields = ['status = ?', 'progress = ?', 'updated_at = ?'];
        $values = [$status, $progress, $now];

        if ($error !== null) {
            $fields[] = 'error = ?';
            $values[] = $error;
        }
        if ($result !== null) {
            $fields[] = 'result = ?';
            $values[] = $result;
        }
        if ($status === 'processing') {
            $fields[] = 'started_at = ?';
            $values[] = $now;
        }
        if (in_array($status, ['completed', 'failed'])) {
            $fields[] = 'finished_at = ?';
            $values[] = $now;
        }

        $values[] = $id;
        $sql = "UPDATE tasks SET " . implode(', ', $fields) . " WHERE id = ?";
        $pdo->prepare($sql)->execute($values);

        $this->triggerWebhook("task.{$status}", ['task_id' => $id, 'error' => $error]);
        Logger::info("Task status updated", ['id' => $id, 'status' => $status, 'progress' => $progress]);
    }

    /**
     * Process a single task
     */
    public function process(int $taskId): array
    {
        $task = $this->get($taskId);
        if (!$task) throw new \RuntimeException("Task not found: {$taskId}");

        $this->updateStatus($taskId, 'processing', progress: 0);

        try {
            $progressCallback = function(int $pct, string $msg) use ($taskId) {
                $this->updateStatus($taskId, 'processing', progress: $pct);
            };

            $result = null;

            switch ($task['type']) {
                case 'sign_only':
                    $result = $this->processSignOnly($task, $progressCallback);
                    break;
                case 'upload_only':
                    $result = $this->processUploadOnly($task, $progressCallback);
                    break;
                case 'github_sign':
                    $result = $this->processGitHubSign($task, $progressCallback);
                    break;
                case 'sign_and_upload':
                default:
                    $result = $this->processSignAndUpload($task, $progressCallback);
                    break;
            }

            $this->updateStatus($taskId, 'completed', result: json_encode($result), progress: 100);

            return [
                'success' => true,
                'task_id' => $taskId,
                'result' => $result,
            ];

        } catch (\Throwable $e) {
            Logger::error("Task failed", ['id' => $taskId, 'error' => $e->getMessage()]);

            $retries = ($task['retries'] ?? 0) + 1;
            $pdo = Database::connection();

            if ($retries >= ($task['max_retries'] ?? 3)) {
                $this->updateStatus($taskId, 'failed', error: $e->getMessage());
            } else {
                $pdo->prepare("UPDATE tasks SET status = 'pending', retries = ?, error = ? WHERE id = ?")
                    ->execute([$retries, $e->getMessage(), $taskId]);
            }

            return [
                'success' => false,
                'task_id' => $taskId,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Sign only processing
     */
    private function processGitHubSign(array $task, callable $progressCallback): array
    {
        $progressCallback(10, 'è§¦å‘ GitHub Actions...');
        
        $cmd = sprintf(
            'GITHUB_TOKEN=$GITHUB_TOKEN php %s/trigger_github.php %d 2>private function processSignOnly1',
            __DIR__ . '/../..',
            (int)$task['id']
        );
        
        $token = TfSignerCoreConfig::get('github.token', '');
        if (!$token) {
            throw new RuntimeException("GITHUB_TOKEN not configured in config.php");
        }
        
        putenv("GITHUB_TOKEN=$token");
        exec($cmd, $output, $code);
        $result = implode("
", $output);
        
        TfSignerCoreogger::info("github trigger result", ['code' => $code, 'output' => $result]);
        
        if ($code !== 0) {
            throw new runtimeexception("failed to trigger github actions: $result");
        }
        
        $progresscallback(20, 'github actions ÿÿÿÿÿÿÿÿÿÿÿÿÿÿÿÿÿÿ mac ÿÿÿÿÿÿÿÿÿÿÿÿÿÿÿ...');
        
        return [
            'success' => true,
            'message' => 'github actions triggered, waiting for callback',
            'output' => $result,
        ];
    }

    private function processsignonly(array $task, callable $progressCallback): array
    {
        $signer = new SigningService();

        // Use task-specified output path or auto-generate
        if (!empty($task['output_ipa'])) {
            $outputIpa = $task['output_ipa'];
        } else {
            $inputName = pathinfo($task['input_ipa'], PATHINFO_FILENAME);
            $outputIpa = Config::get('storage.ipas') . '/' . $inputName . '_signed_' . time() . '.ipa';
        }

        $result = $signer->resign(
            $task['input_ipa'],
            $outputIpa,
            $task['cert_id'],
            $task['profile_id'],
            $progressCallback
        );

        // Update output path
        $pdo = Database::connection();
        $pdo->prepare("UPDATE tasks SET output_ipa = ? WHERE id = ?")
            ->execute([$outputIpa, $task['id']]);

        return $result;
    }

    /**
     * Upload only processing
     */
    private function processUploadOnly(array $task, callable $progressCallback): array
    {
        $uploader = new UploadService();
        return $uploader->upload(
            $task['input_ipa'],
            $task['apple_id'],
            $task['app_password'],
            $progressCallback
        );
    }

    /**
     * Sign + Upload pipeline
     */
    private function processSignAndUpload(array $task, callable $progressCallback): array
    {
        // Phase 1: Sign (0-80%)
        $signCallback = function(int $pct, string $msg) use ($progressCallback) {
            $progressCallback((int)($pct * 0.8), '[Sign] ' . $msg);
        };

        $signer = new SigningService();
        if (!empty($task['output_ipa'])) {
            $outputIpa = $task['output_ipa'];
        } else {
            $outputIpa = Config::get('storage.ipas') . '/signed_' . $task['id'] . '_' . time() . '.ipa';
        }

        $signResult = $signer->resign(
            $task['input_ipa'],
            $outputIpa,
            $task['cert_id'],
            $task['profile_id'],
            $signCallback
        );

        // Update output path
        $pdo = Database::connection();
        $pdo->prepare("UPDATE tasks SET output_ipa = ? WHERE id = ?")
            ->execute([$outputIpa, $task['id']]);

        // Phase 2: Upload (80-100%)
        $uploadCallback = function(int $pct, string $msg) use ($progressCallback) {
            $progressCallback(80 + (int)($pct * 0.2), '[Upload] ' . $msg);
        };

        $uploader = new UploadService();
        $uploadResult = $uploader->upload(
            $outputIpa,
            $task['apple_id'],
            $task['app_password'],
            $uploadCallback
        );

        return [
            'sign' => $signResult,
            'upload' => $uploadResult,
            'output_ipa' => $outputIpa,
        ];
    }

    /**
     * Delete a task
     */
    public function delete(int $id): bool
    {
        $task = $this->get($id);
        if (!$task) return false;

        // Clean up output IPA
        if ($task['output_ipa'] && file_exists($task['output_ipa'])) {
            @unlink($task['output_ipa']);
        }

        $pdo = Database::connection();
        $pdo->prepare("DELETE FROM tasks WHERE id = ?")->execute([$id]);

        return true;
    }

    /**
     * Trigger webhook
     */
    private function triggerWebhook(string $event, array $data): void
    {
        $enabled = Config::get('webhook.enabled', false);
        if (!$enabled) return;

        $url = Config::get('webhook.url');
        $secret = Config::get('webhook.secret');

        if (!$url) return;

        $payload = json_encode([
            'event' => $event,
            'data' => $data,
            'timestamp' => date('c'),
        ]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-TF-Signature: ' . ($secret ? hash_hmac('sha256', $payload, $secret) : ''),
            ],
            CURLOPT_TIMEOUT => 10,
        ]);

        $response = curl_exec($ch);
        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // Log webhook
        $pdo = Database::connection();
        $pdo->prepare("INSERT INTO webhook_logs (event, url, status_code, response) VALUES (?, ?, ?, ?)")
            ->execute([$event, $url, $statusCode, substr($response, 0, 5000)]);
    }
}
