<?php $title = '登录'; ob_start(); ?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>登录 — TF 签名系统</title>
    <style>
        *{margin:0;padding:0;box-sizing:border-box}
        body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:#0d0d0d;color:#e0e0e0;display:flex;align-items:center;justify-content:center;min-height:100vh}
        .login-box{background:#1a1a1a;border:1px solid #333;border-radius:12px;padding:40px;width:380px;text-align:center}
        .login-box h1{font-size:1.4rem;margin-bottom:8px;color:#3b82f6}
        .login-box p{color:#999;font-size:0.85rem;margin-bottom:24px}
        .login-box input{width:100%;padding:12px;background:#252525;border:1px solid #333;border-radius:8px;color:#e0e0e0;font-size:1rem;outline:none;margin-bottom:10px;transition:border-color 0.15s}
        .login-box input:focus{border-color:#3b82f6}
        .login-box button{width:100%;padding:12px;background:#3b82f6;color:#fff;border:none;border-radius:8px;font-size:1rem;cursor:pointer;font-weight:600}
        .login-box button:hover{background:#2563eb}
        .error{color:#ef4444;font-size:0.85rem;margin-bottom:12px}
    </style>
</head>
<body>
<div class="login-box">
    <h1>🚀 TF Signer</h1>
    <p>自动签名上架系统</p>
    <?php if (!empty($error)): ?>
    <div class="error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <form method="POST" action="/login">
        <input type="text" name="username" placeholder="用户名" autofocus>
        <input type="password" name="password" placeholder="密码" required>
        <button type="submit">登 录</button>
    </form>

</div>
</body>
</html>
<?php $content = ob_get_clean(); echo $content; ?>
