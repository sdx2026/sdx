<?php
namespace TfSigner\Controllers;

use TfSigner\Core\Database;
use TfSigner\Core\Router;

class AppController
{
    public static function index(): string
    {
        $pdo = Database::connection();
        $apps = $pdo->query("SELECT * FROM apps ORDER BY created_at DESC")->fetchAll();
        return Router::view('apps', ['apps' => $apps]);
    }

    public static function create(): string
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return Router::json(['error' => 'Method not allowed'], 405);
        }

        $name = $_POST['name'] ?? '';
        $bundleId = $_POST['bundle_id'] ?? '';
        $teamId = $_POST['team_id'] ?? '';
        $teamName = $_POST['team_name'] ?? '';

        if (!$name || !$bundleId) {
            return Router::json(['error' => 'Name and bundle ID required'], 400);
        }

        $pdo = Database::connection();
        $stmt = $pdo->prepare("INSERT INTO apps (name, bundle_id, team_id, team_name) VALUES (?, ?, ?, ?)");
        $stmt->execute([$name, $bundleId, $teamId, $teamName]);

        $id = $pdo->lastInsertId();
        return Router::json(['success' => true, 'id' => $id]);
    }

    public static function delete(int $id): string
    {
        $pdo = Database::connection();
        $pdo->prepare("DELETE FROM apps WHERE id = ?")->execute([$id]);
        return Router::json(['success' => true]);
    }

    public static function profiles(int $appId): string
    {
        $pdo = Database::connection();
        $profiles = $pdo->prepare("
            SELECT pp.*, c.name as cert_name 
            FROM provisioning_profiles pp 
            LEFT JOIN certificates c ON pp.cert_id = c.id 
            WHERE pp.app_id = ? 
            ORDER BY pp.created_at DESC
        ");
        $profiles->execute([$appId]);
        return Router::json($profiles->fetchAll());
    }

    public static function addProfile(): string
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return Router::json(['error' => 'Method not allowed'], 405);
        }

        $appId = $_POST['app_id'] ?? 0;
        $certId = $_POST['cert_id'] ?? null;
        $name = $_POST['name'] ?? '';
        $uuid = $_POST['uuid'] ?? '';
        $teamId = $_POST['team_id'] ?? '';
        $bundleId = $_POST['bundle_id'] ?? '';
        $profileType = $_POST['profile_type'] ?? 'app-store';

        // Handle file upload
        $profilePath = '';
        if (!empty($_FILES['profile_file']['tmp_name'])) {
            $uploadDir = \TfSigner\Core\Config::get('storage.certs');
            $destName = 'profile_' . time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $_FILES['profile_file']['name']);
            $profilePath = $uploadDir . '/' . $destName;
            move_uploaded_file($_FILES['profile_file']['tmp_name'], $profilePath);
        }

        if (!$profilePath) {
            return Router::json(['error' => 'Profile file required'], 400);
        }

        $pdo = Database::connection();
        $stmt = $pdo->prepare("
            INSERT INTO provisioning_profiles (app_id, cert_id, name, uuid, profile_path, bundle_id, team_id, profile_type)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$appId, $certId ?: null, $name, $uuid, $profilePath, $bundleId, $teamId, $profileType]);

        return Router::json(['success' => true, 'id' => $pdo->lastInsertId()]);
    }
}
