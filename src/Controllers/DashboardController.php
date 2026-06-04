<?php
namespace TfSigner\Controllers;

use TfSigner\Core\Database;
use TfSigner\Core\Router;

class DashboardController
{
    public static function index(): string
    {
        $pdo = Database::connection();

        $taskCounts = $pdo->query("
            SELECT status, COUNT(*) as cnt FROM tasks GROUP BY status
        ")->fetchAll();

        $counts = ['pending' => 0, 'processing' => 0, 'completed' => 0, 'failed' => 0];
        foreach ($taskCounts as $row) {
            $counts[$row['status']] = $row['cnt'];
        }

        $recentTasks = $pdo->query("
            SELECT t.*, a.name as app_name 
            FROM tasks t LEFT JOIN apps a ON t.app_id = a.id 
            ORDER BY t.updated_at DESC LIMIT 10
        ")->fetchAll();

        $appCount = $pdo->query("SELECT COUNT(*) FROM apps")->fetchColumn();
        $certCount = $pdo->query("SELECT COUNT(*) FROM certificates")->fetchColumn();

        return Router::view('dashboard', [
            'counts' => $counts,
            'recentTasks' => $recentTasks,
            'appCount' => $appCount,
            'certCount' => $certCount,
        ]);
    }
}
