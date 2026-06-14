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
    
    // Public paths: no auth needed
    $publicPaths = ['/login', '/api/health', '/api/tasks/', '/api/worker-status', '/download/', '/ota/'];
    foreach ($publicPaths as $p) {
        if (strpos($uri, $p) === 0) return;
    }
    // Allow callback without auth (GitHub Actions webhook)
    if (strpos($uri, '/callback') !== false) return;
    
    // Must be logged in first
    if (!Router::isLoggedIn()) {
        Router::redirect('/login');
        return false;
    }
    
    // Check user permissions for restricted pages (non-admin only)
    $role = $_SESSION['tfsigner_role'] ?? 'user';
    if ($role !== 'admin') {
        $perms = json_decode($_SESSION['tfsigner_perms'] ?? '[]', true) ?: [];
        $menuMap = [
            '/' => 'dashboard', '/tasks' => 'tasks', '/tasks/new' => 'tasks_new',
            '/ipas' => 'ipas', '/apps' => 'apps', '/certs' => 'certs',
            '/profiles' => 'profiles', '/stats' => 'stats', '/settings' => 'settings',
            '/users' => 'users', '/logs' => 'logs',
        ];
// Normalize URI for sub-page matching (e.g. /tasks/42 => /tasks)
        $normUri = preg_replace("#^(/tasks)/[0-9]+$#", "$1", $uri);
        $permKey = $menuMap[$normUri] ?? $menuMap[$uri] ?? null;
        if ($permKey && !in_array($permKey, $perms)) {
            http_response_code(403);
            echo '<h2 style="text-align:center;margin-top:100px;color:#ef4444;">⛔ No permission</h2>';
            return false;
        }
    }
});


// === Login page ===
$router->get('/login', function () {
    if (Router::isLoggedIn()) { Router::redirect('/'); return ''; }
    return Router::view('login');
});
$router->post('/login', function () {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    $pdo = \TfSigner\Core\Database::connection();
    
    // Try users table first
    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch();
    
    if ($user && password_verify($password, $user['password_hash'])) {
        session_start();
        session_regenerate_id(true);
        $_SESSION['tfsigner_auth'] = true;
        $_SESSION['tfsigner_role'] = $user['role'] ?? 'user';
        $_SESSION['tfsigner_perms'] = $user['permissions'] ?? '[]';
        $pdo->prepare("UPDATE users SET last_login = datetime('now') WHERE id = ?")->execute([$user['id']]);
        Router::logOp('login', $username);
        Router::redirect('/');
        return '';
    }
    
    // Fallback: admin password from settings
    if (Router::verifyPassword($password)) {
        if (session_status() === PHP_SESSION_NONE) session_start();
        session_regenerate_id(true);
        $_SESSION['tfsigner_auth'] = true;
        $_SESSION['tfsigner_role'] = 'admin';
        $_SESSION['tfsigner_perms'] = '[]';
        Router::logOp('login', 'admin (legacy)');
        Router::redirect('/');
        return '';
    }
    
    return Router::view('login', ['error' => '用户名或密码错误']);
});
$router->get('/logout', function () {
    if (session_status() === PHP_SESSION_NONE) session_start();
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
$router->get('/ipas', function () { return Router::view('ipas'); });
$router->get('/stats', function () { return Router::view('stats'); });
$router->get('/users', function () { return Router::view('users'); });
$router->get('/help', function () { return Router::view('help'); });
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
    $task = $pdo->prepare("SELECT t.*, a.bundle_id, a.name as app_name FROM tasks t LEFT JOIN apps a ON t.app_id = a.id WHERE t.id = ?");
    $task->execute([(int)$taskId]);
    $task = $task->fetch();
    if (!$task || !$task['output_ipa']) {
        return Router::json(['error' => 'IPA not found'], 404);
    }
    
    $baseUrl = \TfSigner\Core\Config::get('app.url', 'https://bsj.appssign.cc');
    $ipaUrl = $baseUrl . "/download/" . basename($task["output_ipa"]) . "?task_id=" . $taskId;
    $bundleId = $task['bundle_id'] ?? 'com.example.app';
    $appName = $task['app_name'] ?? 'App';
    $version = $task['override_version'] ?? '1.0';
    
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
                <key>bundle-identifier</key><string>' . htmlspecialchars($bundleId, ENT_XML1) . '</string>
                <key>bundle-version</key><string>' . htmlspecialchars($version, ENT_XML1) . '</string>
                <key>kind</key><string>software</string>
                <key>title</key><string>' . htmlspecialchars($appName, ENT_XML1) . '</string>
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
$router->get('/api/stats', [ApiController::class, 'getStats']);
$router->get('/api/users', [ApiController::class, 'listUsers']);
$router->post('/api/users', [ApiController::class, 'createUser']);
$router->post('/api/certs/apple-generate', [ApiController::class, 'appleGenerateCert']);
$router->post('/api/profiles/apple-generate', [ApiController::class, 'appleGenerateProfile']);
$router->get('/api/apple-accounts', [ApiController::class, 'listAppleAccounts']);
$router->post('/api/apple-accounts', [ApiController::class, 'createAppleAccount']);
$router->post('/api/apple-accounts/{id}/retry', [ApiController::class, 'retryAppleAccount']);
$router->get('/api/api-keys', [ApiController::class, 'listApiKeys']);
$router->post('/api/api-keys', [ApiController::class, 'createApiKey']);
$router->get('/api/ipas', [ApiController::class, 'listIpas']);
$router->post('/api/ipas/delete', [ApiController::class, 'deleteIpa']);
    $router->post("/api/ipas/upload", [ApiController::class, "uploadIpa"]);
$router->get('/api/settings', [ApiController::class, 'getSettings']);
$router->post('/api/settings', [ApiController::class, 'saveSettings']);
$router->get('/api/worker-status', [ApiController::class, 'workerStatus']);
$router->post('/api/logs/clear', [ApiController::class, 'clearLogs']);
$router->get('/api/dashboard-stats', [ApiController::class, 'dashboardStats']);

// === POST delete routes ===
$router->post('/apps/{id}/delete', function($id) { return AppController::delete((int)$id); });
$router->post('/tasks/{id}/delete', function($id) {
    $pdo = \TfSigner\Core\Database::connection();
    $pdo->prepare("DELETE FROM tasks WHERE id = ?")->execute([(int)$id]);
    Router::redirect('/tasks');
    return '';
});

// === File download (with task_id token auth for GitHub Actions) ===
$router->get('/download/{file}', function($file) {
    $storageDirs = [
        \TfSigner\Core\Config::get('storage.ipas'),
        \TfSigner\Core\Config::get('storage.certs'),
    ];
    $safeName = basename($file);
    if ($safeName === '.' || $safeName === '..' || empty($safeName)) {
        http_response_code(400);
        return Router::json(['error' => 'Invalid filename'], 400);
    }
    // Auth: allow if user is logged in OR valid task_id token provided
    $isAuthorized = Router::isLoggedIn();
    if (!$isAuthorized && !empty($_GET['task_id'])) {
        $tid = (int)$_GET['task_id'];
        $pdo = \TfSigner\Core\Database::connection();
        $taskRow = $pdo->prepare("SELECT id FROM tasks WHERE id = ? AND status IN ('pending','processing','completed') LIMIT 1");
        $taskRow->execute([$tid]);
        if ($taskRow->fetch()) {
            $isAuthorized = true;
        }
    }
    if (!$isAuthorized) {
        http_response_code(403);
        return Router::json(['error' => 'Forbidden: login or valid task token required'], 403);
    }
    foreach ($storageDirs as $dir) {
        $path = realpath($dir) . '/' . $safeName;
        if (file_exists($path) && strpos(realpath($path), realpath($dir)) === 0) {
            // Determine content type from extension
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            $mimeMap = ['ipa' => 'application/octet-stream', 'pem' => 'application/x-pem-file', 'p12' => 'application/x-pkcs12', 'key' => 'application/pkcs8', 'mobileprovision' => 'application/x-apple-aspen-config'];
            header('Content-Type: ' . ($mimeMap[$ext] ?? 'application/octet-stream'));
            header('Content-Length: ' . filesize($path));
            header('Content-Disposition: attachment; filename="' . $safeName . '"');
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
    if (preg_match('#^/api/users/(\d+)$#', $uri, $m)) { echo ApiController::deleteUser((int)$m[1]); exit; }
    if (preg_match('#^/api/apple-accounts/(\d+)$#', $uri, $m)) { echo ApiController::deleteAppleAccount((int)$m[1]); exit; }
    if (preg_match('#^/api/api-keys/(\d+)$#', $uri, $m)) { echo ApiController::deleteApiKey((int)$m[1]); exit; }
    if (preg_match('#^/api/profiles/(\d+)$#', $uri, $m)) { echo ApiController::deleteProfile((int)$m[1]); exit; }
}

// === PUT method handler ===
if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    if (preg_match('#^/api/users/(\d+)$#', $uri, $m)) { echo ApiController::updateUser((int)$m[1]); exit; }
}


$router->dispatch();
