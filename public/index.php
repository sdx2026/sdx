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

// === Web pages ===
$router->get('/', [DashboardController::class, 'index']);
$router->get('/apps', [AppController::class, 'index']);
$router->post('/apps', [AppController::class, 'create']);
$router->get('/certs', function () { return Router::view('certs'); });
$router->get('/profiles', function () { return Router::view('profiles'); });
$router->get('/settings', function () { return Router::view('settings'); });
$router->get('/tasks', [TaskController::class, 'index']);
$router->get('/tasks/new', [TaskController::class, 'create']);
$router->post('/tasks', [TaskController::class, 'create']);
$router->get('/tasks/{id}', [TaskController::class, 'show']);
$router->get('/tasks/{id}/retry', [TaskController::class, 'retry']);

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
$router->get('/api/settings', [ApiController::class, 'getSettings']);
$router->post('/api/settings', [ApiController::class, 'saveSettings']);

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
