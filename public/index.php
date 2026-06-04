<?php
$autoloadPaths = [
    __DIR__ . '/../vendor/autoload.php',
    __DIR__ . '/../../../vendor/autoload.php',
];
foreach ($autoloadPaths as $path) {
    if (file_exists($path)) { require $path; break; }
}
if (!class_exists(\TfSigner\Core\App::class)) {
    spl_autoload_register(function ($class) {
        $prefix = 'TfSigner\\';
        $baseDir = __DIR__ . '/../src/';
        $len = strlen($prefix);
        if (strncmp($prefix, $class, $len) !== 0) return;
        $relativeClass = substr($class, $len);
        $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';
        if (file_exists($file)) require $file;
    });
}

use TfSigner\Core\App;
use TfSigner\Core\Router;
use TfSigner\Controllers\DashboardController;
use TfSigner\Controllers\AppController;
use TfSigner\Controllers\TaskController;
use TfSigner\Controllers\ApiController;

App::boot();
App::ensureDirs();

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$router = new Router();

// === Auth middleware (skip for login page, API, and download) ===
$router->addMiddleware(function () {
    $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $publicPaths = ['/login', '/api/health', '/api/tasks/', '/api/worker-status', '/download/'];
    foreach ($publicPaths as $p) {
        if (strpos($uri, $p) === 0) return;
    }
    // Allow callback without auth
    if (strpos($uri, '/callback') !== false) return;
    // Allow DELETE API without auth (called by JS)
    if ($_SERVER['REQUEST_METHOD'] === 'DELETE') return;
    
    if (!Router::isLoggedIn()) {
        Router::redirect('/login');
        return false;
    }
});

// === Login page ===
$router->get('/login', function () {
    if (Router::isLoggedIn()) { Router::redirect('/'); return ''; }
    return Router::view('login');
});
$router->post('/login', function () {
    $password = $_POST['password'] ?? '';
    if (Router::verifyPassword($password)) {
        session_start();
        $_SESSION['tfsigner_auth'] = true;
        Router::logOp('login', 'User logged in');
        Router::redirect('/');
    } else {
        return Router::view('login', ['error' => '密码错误']);
    }
    return '';
});
$router->get('/logout', function () {
    session_start();
    session_destroy();
    Router::logOp('logout', 'User logged out');
    Router::redirect('/login');
    return '';
});

// === Web pages ===
$router->get('/', [DashboardController::class, 'index']);
$router->get('/apps', [AppController::class, 'index']);
$router->post('/apps', [AppController::class, 'create']);
$router->get('/certs', function () { return Router::view('certs'); });
$router->get('/profiles', function () { return Router::view('profiles'); });
$router->get('/settings', function () { return Router::view('settings'); });
$router->get('/logs', function () {
    $pdo = \TfSigner\Core\Database::connection();
    $logs = $pdo->query("SELECT * FROM operation_logs ORDER BY created_at DESC LIMIT 100")->fetchAll();
    return Router::view('logs', ['logs' => $logs]);
});
$router->get('/tasks', [TaskController::class, 'index']);
$router->get('/tasks/new', [TaskController::class, 'create']);
$router->post('/tasks', [TaskController::class, 'create']);
$router->get('/tasks/{id}', [TaskController::class, 'show']);
$router->get('/tasks/{id}/retry', [TaskController::class, 'retry']);

// === OTA install ===
$router->get('/ota/install/{task_id}', function ($taskId) {
    $pdo = \TfSigner\Core\Database::connection();
    $task = $pdo->prepare("SELECT * FROM tasks WHERE id = ?");
    $task->execute([(int)$taskId]);
    $task = $task->fetch();
    if (!$task || !$task['output_ipa']) {
        return Router::json(['error' => 'IPA not found'], 404);
    }
    
    $ipaUrl = "http://38.246.249.155:8088/download/" . basename($task['output_ipa']);
    $bundleId = $task['bundle_id'] ?? 'com.example.app';
    $appName = $task['app_name'] ?? 'App';
    $version = '1.0';
    
    $plist = '<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
    <key>items</key>
    <array>
        <dict>
            <key>assets</key>
            <array>
                <dict>
                    <key>kind</key><string>software-package</string>
                    <key>url</key><string>' . $ipaUrl . '</string>
                </dict>
            </array>
            <key>metadata</key>
            <dict>
                <key>bundle-identifier</key><string>' . $bundleId . '</string>
                <key>bundle-version</key><string>' . $version . '</string>
                <key>kind</key><string>software</string>
                <key>title</key><string>' . $appName . '</string>
            </dict>
        </dict>
    </array>
</dict>
</plist>';
    
    header('Content-Type: application/xml');
    echo $plist;
    return '';
});

// === API endpoints ===
$router->get('/api/health', [ApiController::class, 'health']);
$router->post('/api/tasks', [ApiController::class, 'createTask']);
$router->get('/api/tasks/{id}', [ApiController::class, 'getTask']);
$router->post('/api/tasks/{id}/callback', [ApiController::class, 'taskCallback']);
$router->get('/api/certs', [ApiController::class, 'listCerts']);
$router->post('/api/certs', [ApiController::class, 'generateCert']);
$router->post('/api/certs/import', [ApiController::class, 'importCert']);
$router->get('/api/apps', [ApiController::class, 'listApps']);
$router->get('/api/profiles', [ApiController::class, 'listProfiles']);
$router->post('/api/profiles/upload', [ApiController::class, 'uploadProfile']);
$router->post('/api/ipa/parse', [ApiController::class, 'parseIpa']);
$router->get('/api/worker-status', [ApiController::class, 'getSettings']);
$router->post('/api/worker-status', [ApiController::class, 'saveSettings']);
$router->get('/api/worker-status', [ApiController::class, 'workerStatus']);
$router->get('/api/dashboard-stats', [ApiController::class, 'dashboardStats']);

// === POST delete routes ===
$router->post('/apps/{id}/delete', function($id) { return AppController::delete((int)$id); });
$router->post('/tasks/{id}/delete', function($id) {
    $pdo = \TfSigner\Core\Database::connection();
    $pdo->prepare("DELETE FROM tasks WHERE id = ?")->execute([(int)$id]);
    Router::redirect('/tasks');
    return '';
});

// === File download ===
$router->get('/download/{file}', function($file) {
    $storageDirs = [
        \TfSigner\Core\Config::get('storage.ipas'),
        \TfSigner\Core\Config::get('storage.certs'),
    ];
    foreach ($storageDirs as $dir) {
        $path = $dir . '/' . basename($file);
        if (file_exists($path)) {
            header('Content-Type: ' . (mime_content_type($path) ?: 'application/octet-stream'));
            header('Content-Length: ' . filesize($path));
            readfile($path);
            return '';
        }
    }
    http_response_code(404);
    return Router::json(['error' => 'File not found'], 404);
});

// === DELETE method handler ===
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    if (preg_match('#^/apps/(\d+)$#', $uri, $m)) { echo AppController::delete((int)$m[1]); exit; }
    if (preg_match('#^/tasks/(\d+)$#', $uri, $m)) { echo TaskController::delete((int)$m[1]); exit; }
    if (preg_match('#^/api/certs/(\d+)$#', $uri, $m)) { echo ApiController::deleteCert((int)$m[1]); exit; }
}

$router->dispatch();
