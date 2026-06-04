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
        ];
        foreach ($detections as $pattern => $code) {
            if (preg_match($pattern, $rawError)) {
                return self::format($code, $rawError);
            }
        }
        return "[E9999] 未知错误 — " . $rawError . "\n💡 解决方法: 查看任务日志或联系管理员";
    }

}
