<?php
namespace TfSigner\Core;

class Database
{
    private static ?\PDO $pdo = null;

    public static function init(): void
    {
        if (self::$pdo !== null) return;

        $path = Config::get('database.path');
        $dir = dirname($path);
        if (!is_dir($dir)) mkdir($dir, 0755, true);

        self::$pdo = new \PDO("sqlite:{$path}");
        self::$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        self::$pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        self::$pdo->exec('PRAGMA journal_mode=WAL');
        self::$pdo->exec('PRAGMA foreign_keys=ON');

        self::migrate();
    }

    public static function connection(): \PDO
    {
        if (self::$pdo === null) self::init();
        return self::$pdo;
    }

    private static function migrate(): void
    {
        $pdo = self::$pdo;

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS apps (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                bundle_id TEXT NOT NULL UNIQUE,
                team_id TEXT NOT NULL DEFAULT '',
                team_name TEXT NOT NULL DEFAULT '',
                app_store_id TEXT NOT NULL DEFAULT '',
                platform TEXT NOT NULL DEFAULT 'ios',
                icon TEXT DEFAULT '',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS certificates (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                type TEXT NOT NULL DEFAULT 'distribution',
                cert_path TEXT NOT NULL,
                key_path TEXT NOT NULL,
                password TEXT NOT NULL DEFAULT '',
                serial TEXT NOT NULL DEFAULT '',
                expires_at DATETIME,
                team_id TEXT NOT NULL DEFAULT '',
                is_active INTEGER DEFAULT 1,
                apple_cert_id TEXT DEFAULT '',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS provisioning_profiles (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                app_id INTEGER NOT NULL,
                cert_id INTEGER,
                name TEXT NOT NULL,
                uuid TEXT NOT NULL DEFAULT '',
                profile_path TEXT NOT NULL,
                bundle_id TEXT NOT NULL,
                team_id TEXT NOT NULL DEFAULT '',
                profile_type TEXT NOT NULL DEFAULT 'app-store',
                expires_at DATETIME,
                is_active INTEGER DEFAULT 1,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (app_id) REFERENCES apps(id),
                FOREIGN KEY (cert_id) REFERENCES certificates(id)
            )
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS tasks (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                app_id INTEGER,
                type TEXT NOT NULL DEFAULT 'sign_and_upload',
                status TEXT NOT NULL DEFAULT 'pending',
                priority INTEGER NOT NULL DEFAULT 0,
                input_ipa TEXT,
                output_ipa TEXT,
                cert_id INTEGER,
                profile_id INTEGER,
                apple_id TEXT NOT NULL DEFAULT '',
                app_password TEXT NOT NULL DEFAULT '',
                result TEXT,
                error TEXT,
                progress INTEGER DEFAULT 0,
                retries INTEGER DEFAULT 0,
                max_retries INTEGER DEFAULT 3,
                started_at DATETIME,
                finished_at DATETIME,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                override_version TEXT DEFAULT '',
                override_build TEXT DEFAULT '',
                apple_account_id INTEGER,
                api_key_id INTEGER,
                FOREIGN KEY (app_id) REFERENCES apps(id),
                FOREIGN KEY (cert_id) REFERENCES certificates(id),
                FOREIGN KEY (profile_id) REFERENCES provisioning_profiles(id)
            )
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS settings (
                key TEXT PRIMARY KEY,
                value TEXT,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS webhook_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                event TEXT NOT NULL,
                url TEXT NOT NULL,
                status_code INTEGER,
                response TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT UNIQUE NOT NULL,
                password_hash TEXT NOT NULL,
                role TEXT DEFAULT 'user',
                permissions TEXT DEFAULT '[]',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                last_login DATETIME
            )
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS apple_accounts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                apple_id TEXT NOT NULL UNIQUE,
                app_password TEXT NOT NULL,
                note TEXT DEFAULT '',
                status TEXT DEFAULT 'active',
                last_error TEXT DEFAULT '',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS api_keys (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                issuer_id TEXT NOT NULL,
                key_id TEXT NOT NULL,
                key_content TEXT NOT NULL,
                note TEXT DEFAULT '',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS operation_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                action TEXT NOT NULL,
                detail TEXT,
                ip TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");

        // Insert default admin if no users exist
        $count = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
        if ($count == 0) {
            $pdo->prepare("INSERT INTO users (username, password_hash, role) VALUES ('admin', ?, 'admin')")
                ->execute([password_hash('admin123', PASSWORD_BCRYPT)]);
        }
    }
}
