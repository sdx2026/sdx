<?php
return [
    'app' => [
        'name' => 'TF 自动签名上架系统',
        'version' => '1.0.0',
        'url' => 'http://localhost:8080',
        'timezone' => 'Asia/Shanghai',
    ],

    'database' => [
        'driver' => 'sqlite',
        'path' => __DIR__ . '/../storage/tf_signer.db',
    ],

    'storage' => [
        'ipas' => __DIR__ . '/../storage/ipas',
        'certs' => __DIR__ . '/../storage/certs',
        'logs' => __DIR__ . '/../storage/logs',
    ],

    'apple' => [
        'api_base' => 'https://api.appstoreconnect.apple.com/v1',
        'token_expiry' => 1200, // 20 minutes
    ],

    'signing' => [
        'keychain' => null, // macOS only; set to keychain path or null for Linux
        'codesign_path' => '/usr/bin/codesign',
        'security_path' => '/usr/bin/security',
    ],

    'worker' => [
        'sleep_interval' => 5,      // seconds between task polls
        'max_retries' => 3,
        'retry_delay' => 60,
    ],

    'queue' => [
        'max_concurrent' => 2,
    ],

    'github' => [
        'token' => '',
    ],

    'webhook' => [
        'enabled' => false,
        'url' => '',
        'secret' => '',
    ],
];
