#!/usr/bin/env php
<?php
/**
 * TF Signer 安装脚本
 * 
 * 运行: php install.php
 */

echo "\n╔══════════════════════════════════════╗\n";
echo "║   🚀 TF 自动签名上架系统 - 安装向导   ║\n";
echo "╚══════════════════════════════════════╝\n\n";

// Check PHP version
$phpVersion = PHP_VERSION;
$requiredVersion = '8.1.0';
echo "✓ PHP 版本: {$phpVersion}";
if (version_compare($phpVersion, $requiredVersion, '>=')) {
    echo " ✅\n";
} else {
    echo " ❌ (需要 >= {$requiredVersion})\n";
    exit(1);
}

// Check extensions
$requiredExtensions = [
    'curl' => 'ext-curl',
    'zip' => 'ext-zip',
    'openssl' => 'ext-openssl',
    'pdo_sqlite' => 'ext-pdo_sqlite',
    'sqlite3' => 'ext-sqlite3',
    'json' => 'ext-json',
    'fileinfo' => 'ext-fileinfo',
];

echo "\n检查 PHP 扩展:\n";
$allOk = true;
foreach ($requiredExtensions as $ext => $package) {
    $loaded = extension_loaded($ext);
    echo "  " . ($loaded ? "✓" : "✗") . " {$ext}";
    if ($loaded) {
        echo " ✅\n";
    } else {
        echo " ❌ 请安装 {$package}\n";
        $allOk = false;
    }
}

if (!$allOk) {
    echo "\n请安装缺少的扩展后重新运行。\n";
    exit(1);
}

// Create directories
echo "\n创建目录结构...\n";
$dirs = [
    __DIR__ . '/storage/ipas',
    __DIR__ . '/storage/certs',
    __DIR__ . '/storage/logs',
];
foreach ($dirs as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
        echo "  ✓ 创建: {$dir}\n";
    } else {
        echo "  → 已存在: {$dir}\n";
    }
}

// Initialize database
echo "\n初始化数据库...\n";
try {
    $dbPath = __DIR__ . '/storage/tf_signer.db';
    $pdo = new PDO("sqlite:{$dbPath}");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $pdo->exec("SELECT 1");
    echo "  ✓ 数据库连接正常: {$dbPath}\n";
    
    // Load config and run migrations
    require __DIR__ . '/src/Core/Config.php';
    require __DIR__ . '/src/Core/Database.php';
    
    \TfSigner\Core\Config::load(__DIR__ . '/config/config.php');
    \TfSigner\Core\Database::init();
    echo "  ✓ 数据库表创建完成\n";
    
} catch (\Throwable $e) {
    echo "  ✗ 数据库初始化失败: {$e->getMessage()}\n";
    exit(1);
}

// Check Composer autoload
echo "\n检查 Composer...\n";
$autoloadPath = __DIR__ . '/vendor/autoload.php';
if (file_exists($autoloadPath)) {
    echo "  ✓ Composer autoload 已就绪\n";
} else {
    echo "  ⚠ Composer autoload 未找到，将使用内置 autoload\n";
    echo "    建议运行: composer install\n";
}

// Check tools
echo "\n检查签名工具...\n";

$tools = [
    'codesign' => '代码签名 (macOS)',
    'xcrun altool' => 'App Store 上传 (macOS)',
    'zsign' => '代码签名 (Linux 备选)',
];

foreach ($tools as $tool => $desc) {
    if ($tool === 'xcrun altool') {
        exec('which xcrun 2>/dev/null', $out, $code);
        if ($code === 0) {
            echo "  ✓ xcrun altool - {$desc} ✅\n";
        } else {
            echo "  ⚠ xcrun altool - {$desc} (仅 macOS)\n";
        }
    } else {
        exec("which {$tool} 2>/dev/null", $out, $code);
        if ($code === 0) {
            echo "  ✓ {$tool} - {$desc} ✅\n";
        } else {
            echo "  ⚠ {$tool} - {$desc} (可选)\n";
        }
    }
}

// Final instructions
echo "\n╔══════════════════════════════════════╗\n";
echo "║           ✅ 安装完成!                ║\n";
echo "╚══════════════════════════════════════╝\n\n";

echo "启动方式:\n";
echo "  1. Web 服务:\n";
echo "     php -S localhost:8080 -t public/\n\n";
echo "  2. 后台 Worker:\n";
echo "     php worker.php\n\n";
echo "  3. 访问地址:\n";
echo "     http://localhost:8080\n\n";

echo "API 端点:\n";
echo "  POST /api/tasks          - 创建签名任务\n";
echo "  GET  /api/tasks/{id}     - 查询任务状态\n";
echo "  POST /api/certs          - 生成证书\n";
echo "  GET  /api/health         - 健康检查\n\n";

echo "系统依赖:\n";
echo "  - macOS: Xcode Command Line Tools (用于 codesign 和 altool)\n";
echo "  - Linux: zsign (用于 IPA 签名)\n\n";
