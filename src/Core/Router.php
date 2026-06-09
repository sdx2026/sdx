<?php
namespace TfSigner\Core;

class Router
{
    private array $routes = [];
    private array $middlewares = [];

    public function get(string $path, callable $handler): self
    {
        $this->routes['GET'][$path] = $handler;
        return $this;
    }

    public function post(string $path, callable $handler): self
    {
        $this->routes['POST'][$path] = $handler;
        return $this;
    }

    public function addMiddleware(callable $mw): self
    {
        $this->middlewares[] = $mw;
        return $this;
    }

    public function dispatch(): void
    {
        $method = $_SERVER['REQUEST_METHOD'];
        $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $uri = rtrim($uri, '/') ?: '/';

        // Run middlewares
        foreach ($this->middlewares as $mw) {
            if ($mw() === false) return;
        }

        // Static file serving
        if (preg_match('#^/assets/#', $uri)) {
            $file = __DIR__ . '/../../public' . $uri;
            if (file_exists($file)) {
                $mime = match (pathinfo($file, PATHINFO_EXTENSION)) {
                    'css' => 'text/css',
                    'js' => 'application/javascript',
                    'png' => 'image/png',
                    'jpg', 'jpeg' => 'image/jpeg',
                    'svg' => 'image/svg+xml',
                    default => 'application/octet-stream',
                };
                header("Content-Type: {$mime}");
                readfile($file);
                return;
            }
        }

        // Match route
        $handlers = $this->routes[$method] ?? [];
        foreach ($handlers as $pattern => $handler) {
            $regex = '#^' . preg_replace('/\{(\w+)\}/', '([^/]+)', $pattern) . '$#';
            if (preg_match($regex, $uri, $matches)) {
                array_shift($matches);
                echo $handler(...$matches);
                return;
            }
        }

        // 404
        http_response_code(404);
        echo json_encode(['error' => 'Not found']);
    }

    public static function json(mixed $data, int $code = 200): string
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    public static function redirect(string $url): void
    {
        header("Location: {$url}");
        exit;
    }

    public static function view(string $template, array $data = []): string
    {
        extract($data);
        ob_start();
        $file = __DIR__ . '/../../templates/' . $template . '.php';
        if (!file_exists($file)) {
            return "<h3>Template not found: {$template}</h3>";
        }
        require $file;
        return ob_get_clean();
    }

    /**
     * Check if user is logged in
     */
    public static function isLoggedIn(): bool
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        return !empty($_SESSION['tfsigner_auth']);
    }

    /**
     * Verify password
     */
    public static function verifyPassword(string $password): bool
    {
        $pdo = \TfSigner\Core\Database::connection();
        $stmt = $pdo->prepare("SELECT value FROM settings WHERE key = 'admin_password'");
        $stmt->execute();
        $hash = $stmt->fetchColumn();
        return $hash && password_verify($password, $hash);
    }

    /**
     * Log an operation
     */
    public static function logOp(string $action, string $detail = ''): void
    {
        try {
            $pdo = \TfSigner\Core\Database::connection();
            $pdo->prepare("INSERT INTO operation_logs (action, detail, ip) VALUES (?, ?, ?)")
                ->execute([$action, $detail, $_SERVER['REMOTE_ADDR'] ?? '']);
        } catch (\Throwable $e) {}
    }
}
