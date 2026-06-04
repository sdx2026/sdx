<?php
namespace TfSigner\Services;

use TfSigner\Core\Config;
use TfSigner\Core\Database;
use TfSigner\Core\Logger;

class TaskService
{
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
        $this->triggerWebhook('task.created', ['task_id' => $taskId]);
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

        // Pagination
        $page = max(1, (int)($filters['page'] ?? 1));
        $perPage = min(50, max(10, (int)($filters['per_page'] ?? 20)));
        $offset = ($page - 1) * $perPage;

        // Count total
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
        $stmt = $pdo->prepare("
            SELECT * FROM tasks 
            WHERE status = 'pending' AND retries < max_retries 
            ORDER BY priority DESC, created_at ASC 
            LIMIT 1
        ");
        $stmt->execute();
        return $stmt->fetch() ?: null;
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
        $this->triggerWebhook("task.{$status}", ['task_id' => $id, 'error' => $error]);
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

    private function triggerWebhook(string $event, array $data): void
    {
        $url = Config::get('webhook.url', '');
        if (!$url) return;
        $secret = Config::get('webhook.secret', '');
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
}
