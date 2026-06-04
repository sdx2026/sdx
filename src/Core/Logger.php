<?php
namespace TfSigner\Core;

class Logger
{
    private static $instance = null;
    private static bool $fallbackMode = false;
    private static string $logFile = '';

    public static function init(): void
    {
        $logDir = Config::get('storage.logs');
        if (!is_dir($logDir)) mkdir($logDir, 0755, true);

        if (class_exists('Monolog\Logger')) {
            $logger = new \Monolog\Logger('tfsigner');
            $logger->pushHandler(new \Monolog\Handler\RotatingFileHandler(
                $logDir . '/app.log',
                30,
                \Monolog\Level::Debug
            ));
            $logger->pushHandler(new \Monolog\Handler\StreamHandler('php://stdout', \Monolog\Level::Info));
            self::$instance = $logger;
            self::$fallbackMode = false;
        } else {
            self::$fallbackMode = true;
            self::$logFile = $logDir . '/app.log';
        }
    }

    public static function instance()
    {
        if (self::$instance === null && !self::$fallbackMode) {
            self::init();
        }
        return self::$instance;
    }

    public static function __callStatic(string $name, array $args): void
    {
        // Ensure logger is initialized on first call
        if (self::$instance === null && !self::$fallbackMode) {
            self::init();
        }

        if (self::$fallbackMode) {
            $message = $args[0] ?? '';
            $context = $args[1] ?? [];
            $contextStr = $context ? ' ' . json_encode($context, JSON_UNESCAPED_UNICODE) : '';
            $line = sprintf("[%s] %s.%s: %s%s\n", date('Y-m-d H:i:s'), 'tfsigner', strtoupper($name), $message, $contextStr);
            @file_put_contents(self::$logFile, $line, FILE_APPEND | LOCK_EX);
            return;
        }

        if (self::$instance !== null) {
            self::$instance->$name(...$args);
        }
    }
}
