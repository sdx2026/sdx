<?php
namespace TfSigner\Core;

class App
{
    public static function boot(): void
    {
        // Error reporting
        error_reporting(E_ALL);
        ini_set('display_errors', '0');
        date_default_timezone_set(Config::get('app.timezone', 'UTC'));

        // Load config
        Config::load(__DIR__ . '/../../config/config.php');

        // Init database
        Database::init();

        // Init logger
        Logger::init();
    }

    public static function ensureDirs(): void
    {
        $dirs = [
            Config::get('storage.ipas'),
            Config::get('storage.certs'),
            Config::get('storage.logs'),
        ];
        foreach ($dirs as $dir) {
            if (!is_dir($dir)) mkdir($dir, 0755, true);
        }
    }
}
