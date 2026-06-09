<?php
namespace TfSigner\Core;

class ErrorCodes
{
    // Certificate errors (1xxx)
    public const CERT_NOT_FOUND = 1001;
    public const CERT_EXPIRED = 1002;
    public const CERT_INVALID = 1003;
    public const CERT_PASSWORD_WRONG = 1004;
    public const CERT_NOT_TRUSTED = 1005;
    public const CERT_TEAM_MISMATCH = 1006;

    // Profile errors (2xxx)
    public const PROFILE_NOT_FOUND = 2001;
    public const PROFILE_EXPIRED = 2002;
    public const PROFILE_INVALID = 2003;
    public const PROFILE_APP_MISMATCH = 2004;
    public const PROFILE_CERT_MISMATCH = 2005;

    // IPA errors (3xxx)
    public const IPA_NOT_FOUND = 3001;
    public const IPA_INVALID = 3002;
    public const IPA_TOO_LARGE = 3003;
    public const IPA_NO_PAYLOAD = 3004;
    public const IPA_CORRUPT = 3005;

    // Signing errors (4xxx)
    public const SIGN_TOOL_NOT_FOUND = 4001;
    public const SIGN_FAILED = 4002;
    public const SIGN_TIMEOUT = 4003;
    public const SIGN_PERMISSION_DENIED = 4004;

    // Upload errors (5xxx)
    public const UPLOAD_FAILED = 5001;
    public const UPLOAD_AUTH_FAILED = 5002;
    public const UPLOAD_NETWORK = 5003;
    public const UPLOAD_DUPLICATE = 5004;

    // System errors (9xxx)
    public const SYSTEM_DISK_FULL = 9001;
    public const SYSTEM_DB_ERROR = 9002;
    public const SYSTEM_WORKER_STOPPED = 9003;
    public const GITHUB_TOKEN_MISSING = 9004;
    public const GITHUB_TRIGGER_FAILED = 9005;

    private static array $messages = [
        // Cert
        1001 => '证书文件未找到，请检查证书是否已导入',
        1002 => '证书已过期，请重新生成或导入新证书',
        1003 => '证书格式无效，请确认是 .p12 或 .pem 格式',
        1004 => '证书密码错误，请检查 P12 密码',
        1005 => '证书不受信任，请使用 Apple Developer 签发的证书',
        1006 => '证书 Team ID 与应用不匹配',
        // Profile
        2001 => '描述文件未找到，请上传 .mobileprovision 文件',
        2002 => '描述文件已过期，请在 Apple Developer 重新生成',
        2003 => '描述文件格式无效',
        2004 => '描述文件 App ID 与当前应用不匹配',
        2005 => '描述文件包含的证书与当前签名证书不匹配',
        // IPA
        3001 => 'IPA 文件未找到，请重新上传',
        3002 => 'IPA 文件格式无效，不是有效的 iOS 安装包',
        3003 => 'IPA 文件过大（超过 500MB），请检查文件',
        3004 => 'IPA 文件中未找到 Payload，文件可能损坏',
        3005 => 'IPA 文件损坏，无法解压',
        // Sign
        4001 => '未找到签名工具，请安装 zsign（yum install zsign）',
        4002 => '签名过程失败，请检查证书和描述文件是否匹配',
        4003 => '签名超时，IPA 文件可能过大或证书有问题',
        4004 => '签名权限不足，请检查文件权限',
        // Upload
        5001 => '上传 App Store Connect 失败',
        5002 => 'Apple ID 或密码验证失败，请检查账号密码',
        5003 => '上传网络错误，请检查服务器网络连接',
        5004 => '该构建号已存在，请递增构建号后重试',
        // System
        9001 => '服务器磁盘空间不足，请清理后重试',
        9002 => '数据库错误，请联系管理员',
        9003 => '后台 Worker 已停止，请重启 Worker',
        9004 => 'GitHub Token 未配置，请在设置页添加',
        9005 => 'GitHub Actions 触发失败，请检查 Token 和仓库配置',
    ];

    private static array $fixes = [
        1001 => '去「证书管理」页面导入证书',
        1002 => '去 Apple Developer 生成新证书后导入',
        1003 => '确认是有效的 .p12 或 PEM 证书',
        1004 => '检查导入时填写的 P12 密码是否正确',
        2001 => '去「描述文件」页面上传对应 App 的 .mobileprovision',
        2002 => '去 Apple Developer → Profiles 重新生成',
        2004 => '描述文件 Bundle ID 必须与 App 一致',
        3001 => '重新上传 IPA 文件',
        3002 => '确认是 Xcode Archive 导出的 .ipa 文件',
        4001 => 'SSH 登录服务器执行: yum install zsign',
        4002 => '确认证书和描述文件属于同一个 App ID',
        5002 => '去 appleid.apple.com 生成 App 专用密码',
        5004 => '构建号每次上架必须递增，去新建任务填入更大的构建号',
        9003 => 'SSH 登录执行: cd /www/wwwroot/tfsigner && nohup php worker.php &',
        9004 => '去设置 → GitHub Token 粘贴你的 Personal Access Token',
        9005 => '检查 GitHub Token 权限（需要 workflow 权限），确认仓库名和分支正确',
    ];

    public static function getMessage(int $code): string
    {
        return self::$messages[$code] ?? "未知错误 (代码: {$code})";
    }

    public static function getFix(int $code): string
    {
        return self::$fixes[$code] ?? '请查看使用教程或联系管理员';
    }

    public static function format(int $code, string $detail = ''): string
    {
        $msg = self::getMessage($code);
        $fix = self::getFix($code);
        return "[E{$code}] {$msg}" . ($detail ? " — {$detail}" : '') . "\n💡 解决方法: {$fix}";
    }

    public static function detectAndFormat(string $rawError): string
    {
        $detections = [
            "/certificate.*not found/i" => self::CERT_NOT_FOUND,
            "/cert.*not found/i" => self::CERT_NOT_FOUND,
            "/profile.*not found/i" => self::PROFILE_NOT_FOUND,
            "/provisioning.*not found/i" => self::PROFILE_NOT_FOUND,
            "/no signing tool/i" => self::SIGN_TOOL_NOT_FOUND,
            "/signing failed/i" => self::SIGN_FAILED,
            "/zsign.*failed/i" => self::SIGN_FAILED,
            "/no .app found/i" => self::IPA_NO_PAYLOAD,
            "/failed to open ipa/i" => self::IPA_CORRUPT,
            "/invalid ipa/i" => self::IPA_INVALID,
            "/too large/i" => self::IPA_TOO_LARGE,
            "/disk.*(full|space)/i" => self::SYSTEM_DISK_FULL,
            "/permission denied/i" => self::SIGN_PERMISSION_DENIED,
            "/expired/i" => self::CERT_EXPIRED,
            "/xcrun.*not found/i" => self::SIGN_TOOL_NOT_FOUND,
            "/altool.*not found/i" => self::SIGN_TOOL_NOT_FOUND,
        ];
        foreach ($detections as $pattern => $code) {
            if (preg_match($pattern, $rawError)) {
                return self::format($code, $rawError);
            }
        }
        return "[E9999] 未知错误 — " . $rawError . "\n💡 解决方法: 查看任务日志或联系管理员";
    }


    // ===== Apple App Store Connect / TestFlight 真实错误代码 =====
    private static array $itmsMessages = [
        "ITMS-9000" => "Info.plist 缺少必填字段（如 CFBundleIdentifier、CFBundleVersion 等）",
        "ITMS-90022" => "缺少 57x57 的 App 图标（CFBundleIconFiles）",
        "ITMS-90023" => "缺少 512x512 的 App Store 图标",
        "ITMS-90034" => "签名证书缺失或不受信任",
        "ITMS-90035" => "签名无效，证书或描述文件过期/不匹配",
        "ITMS-90046" => "应用包含未签名的二进制文件",
        "ITMS-90080" => "无法读取 Provisioning Profile",
        "ITMS-90085" => "二进制文件中没有找到正确的签名",
        "ITMS-90096" => "二进制文件格式无效",
        "ITMS-90161" => "描述文件无效或已过期",
        "ITMS-90164" => "缺少必需的架构（如 arm64）",
        "ITMS-90166" => "缺少必需的 64-bit 支持",
        "ITMS-90167" => "App 切片信息无效",
        "ITMS-90171" => "Bundle 结构无效",
        "ITMS-90179" => "代码签名无效",
        "ITMS-90186" => "SDK 版本低于最低要求",
        "ITMS-90191" => "缺少必需的推送通知权限描述",
        "ITMS-90207" => "IPA 文件损坏或格式无效",
        "ITMS-90208" => "Bundle 中包含无效文件",
        "ITMS-90209" => "Bundle 中包含符号链接",
        "ITMS-90227" => "缺少必需的配置文件",
        "ITMS-90230" => "Info.plist 中 CFBundleVersion 格式无效",
        "ITMS-90239" => "Bundle 中包含不被允许的文件类型",
        "ITMS-90240" => "应用包含不支持的架构",
        "ITMS-90283" => "缺少 CFBundleVersion（构建号）",
        "ITMS-90284" => "CFBundleShortVersionString（版本号）格式无效",
        "ITMS-90338" => "使用了非公开 API",
        "ITMS-90362" => "Info.plist 值无效",
        "ITMS-90426" => "找不到匹配的 Provisioning Profile",
        "ITMS-90428" => "无效的 Swift 支持文件",
        "ITMS-90474" => "CFBundleVersion 与已有版本冲突（构建号未递增）",
        "ITMS-90475" => "应用需要更新以适配新版本 iOS",
        "ITMS-90511" => "应用使用了已废弃的 API",
        "ITMS-90529" => "提交的 .ipa 包有问题",
        "ITMS-90534" => "应用安装验证失败",
        "ITMS-90535" => "Bundle ID 与实际应用不匹配",
        "ITMS-90635" => "Info.plist 缺少 UIRequiredDeviceCapabilities",
        "ITMS-90683" => "Info.plist 缺少隐私权限描述（如 NSPhotoLibraryUsageDescription）",
        "ITMS-90685" => "缺少必需的启动图",
        "ITMS-90704" => "缺少 App Store 图标（1024x1024）",
        "ITMS-90713" => "缺少 iPad 支持（如应用声明支持 iPad 但缺少相应资源）",
        "ITMS-90717" => "App Store 图标尺寸不符（必须 1024x1024，无圆角）",
        "ITMS-90809" => "使用了 UIWebView（已废弃）",
        "ITMS-90893" => "应用使用了过期/废弃的库",
        "ITMS-90967" => "应用内购买配置有问题",
    ];

    public static function getITMSMessage(string $code): string
    {
        return self::$itmsMessages[$code] ?? "未知 App Store 错误 {$code}";
    }

    public static function parseITMSError(string $rawError): string
    {
        if (preg_match('/(ITMS-\d+)/', $rawError, $m)) {
            $code = $m[1];
            return "[{$code}] " . self::getITMSMessage($code) . "\n💡 搜索 $code 并查看 Apple 官方文档";
        }
        return $rawError;
    }

}
