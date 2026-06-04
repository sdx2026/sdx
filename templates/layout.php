<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title ?? 'TF 签名系统') ?> — TF 自动签名上架</title>
    <style>
        :root{--bg:#0d0d0d;--surface:#1a1a1a;--surface2:#252525;--border:#333;--text:#e0e0e0;--text2:#999;--accent:#3b82f6;--accent2:#2563eb;--green:#22c55e;--red:#ef4444;--amber:#f59e0b;--radius:8px}
        *{margin:0;padding:0;box-sizing:border-box}
        body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:var(--bg);color:var(--text);line-height:1.6;min-height:100vh}
        .layout{display:flex;min-height:100vh}
        .sidebar{width:240px;background:var(--surface);border-right:1px solid var(--border);padding:20px;display:flex;flex-direction:column;position:fixed;height:100vh}
        .sidebar-logo{font-size:1.2rem;font-weight:700;margin-bottom:30px;color:var(--accent)}
        .sidebar-logo span{color:var(--text2);font-size:0.75rem;display:block}
        .sidebar-nav{list-style:none;flex:1}
        .sidebar-nav li{margin-bottom:4px}
        .sidebar-nav a{display:block;padding:10px 14px;color:var(--text2);text-decoration:none;border-radius:var(--radius);font-size:0.9rem;transition:all 0.15s}
        .sidebar-nav a:hover,.sidebar-nav a.active{background:var(--surface2);color:var(--text)}
        .sidebar-nav a.active{border-left:3px solid var(--accent);padding-left:11px}
        .main{margin-left:240px;flex:1;padding:30px 40px;max-width:1200px}
        h1{font-size:1.5rem;margin-bottom:20px}h2{font-size:1.2rem;margin-bottom:15px}
        .stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:16px;margin-bottom:30px}
        .stat-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:20px}
        .stat-card .stat-value{font-size:2rem;font-weight:700;margin-bottom:4px}
        .stat-card .stat-label{color:var(--text2);font-size:0.85rem}
        .stat-card.pending .stat-value{color:var(--amber)}.stat-card.processing .stat-value{color:var(--accent)}
        .stat-card.completed .stat-value{color:var(--green)}.stat-card.failed .stat-value{color:var(--red)}
        table{width:100%;border-collapse:collapse;background:var(--surface);border-radius:var(--radius);overflow:hidden;border:1px solid var(--border)}
        th,td{padding:12px 16px;text-align:left;border-bottom:1px solid var(--border)}
        th{background:var(--surface2);font-weight:600;font-size:0.85rem;color:var(--text2);text-transform:uppercase}
        tr:hover{background:var(--surface2)}
        .badge{display:inline-block;padding:3px 10px;border-radius:20px;font-size:0.78rem;font-weight:600}
        .badge-pending{background:rgba(245,158,11,0.15);color:var(--amber)}.badge-processing{background:rgba(59,130,246,0.15);color:var(--accent)}
        .badge-completed{background:rgba(34,197,94,0.15);color:var(--green)}.badge-failed{background:rgba(239,68,68,0.15);color:var(--red)}
        .btn{display:inline-block;padding:8px 18px;border-radius:var(--radius);border:none;font-size:0.9rem;cursor:pointer;text-decoration:none;font-weight:500;transition:all 0.15s}
        .btn-primary{background:var(--accent);color:#fff}.btn-primary:hover{background:var(--accent2)}
        .btn-danger{background:var(--red);color:#fff}.btn-danger:hover{opacity:0.85}
        .btn-sm{padding:4px 12px;font-size:0.8rem}
        .btn-outline{background:transparent;border:1px solid var(--border);color:var(--text)}
        .btn-outline:hover{border-color:var(--accent);color:var(--accent)}
        .form-group{margin-bottom:16px}.form-group label{display:block;margin-bottom:6px;font-size:0.85rem;color:var(--text2);font-weight:500}
        .form-group input,.form-group select,.form-group textarea{width:100%;padding:10px 14px;background:var(--surface2);border:1px solid var(--border);border-radius:var(--radius);color:var(--text);font-size:0.9rem;outline:none}
        .form-group input:focus,.form-group select:focus{border-color:var(--accent)}
        .card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:24px;margin-bottom:20px}
        .progress-bar{height:6px;background:var(--surface2);border-radius:3px;overflow:hidden;margin-top:6px}
        .progress-fill{height:100%;background:var(--accent);border-radius:3px;transition:width 0.3s}
        .flex-row{display:flex;gap:12px;align-items:center;flex-wrap:wrap}
        .mt-20{margin-top:20px}.mb-20{margin-bottom:20px}
        .text-muted{color:var(--text2);font-size:0.85rem}
        .mono{font-family:monospace;font-size:0.85rem}
        .empty-state{text-align:center;padding:60px 20px;color:var(--text2)}
        .empty-state p{margin-bottom:16px}
        .parse-result{background:var(--surface2);border:1px solid var(--green);border-radius:var(--radius);padding:12px 16px;margin-top:8px;display:none}
    </style>
</head>
<body>
<div class="layout">
    <aside class="sidebar">
        <div class="sidebar-logo">🚀 TF Signer<span>自动签名上架系统</span></div>
        <ul class="sidebar-nav">
<?php $userPerms = json_decode($_SESSION['tfsigner_perms'] ?? '[]', true); $isAdmin = ($_SESSION['tfsigner_role'] ?? 'admin') === 'admin'; ?>
            <li><a href="/" class="<?= ($current ?? '') === 'dashboard' ? 'active' : '' ?>">📊 仪表盘</a></li>
            <li><a href="/tasks" class="<?= ($current ?? '') === 'tasks' ? 'active' : '' ?>">📋 任务列表</a></li>
            <li><a href="/tasks/new" class="<?= ($current ?? '') === 'tasks_new' ? 'active' : '' ?>">➕ 新建任务</a></li>
            <?php if ($isAdmin || in_array("ipas", $userPerms)): ?><li><a href="/ipas" class="<?= ($current ?? '') === 'ipas' ? 'active' : '' ?>">📦 IPA 管理</a></li><?php endif; ?>
            <?php if ($isAdmin || in_array("apps", $userPerms)): ?><li><a href="/apps" class="<?= ($current ?? '') === 'apps' ? 'active' : '' ?>">📱 应用管理</a></li><?php endif; ?>
            <?php if ($isAdmin || in_array("certs", $userPerms)): ?><li><a href="/certs" class="<?= ($current ?? '') === 'certs' ? 'active' : '' ?>">🔐 证书管理</a></li><?php endif; ?>
            <?php if ($isAdmin || in_array("profiles", $userPerms)): ?><li><a href="/profiles" class="<?= ($current ?? '') === 'profiles' ? 'active' : '' ?>">📄 描述文件</a></li><?php endif; ?>
            <?php if ($isAdmin || in_array("settings", $userPerms)): ?><li><a href="/settings" class="<?= ($current ?? '') === 'settings' ? 'active' : '' ?>">⚙️ 设置</a></li><?php endif; ?>
<?php if ($isAdmin || in_array("stats", $userPerms)): ?><li><a href="/stats" class="<?= ($current ?? '') === 'stats' ? 'active' : '' ?>">📈 统计图表</a></li><?php endif; ?>            <?php if ($isAdmin || in_array("users", $userPerms)): ?><li><a href="/users" class="<?= ($current ?? '') === 'users' ? 'active' : '' ?>">👥 用户管理</a></li><?php endif; ?>
            <?php if ($isAdmin || in_array("logs", $userPerms)): ?><li><a href="/logs" class="<?= ($current ?? '') === 'logs' ? 'active' : '' ?>">📝 操作日志</a></li><?php endif; ?>
        </ul>
        <li><a href="/help" class="<?= ($current ?? '') === 'help' ? 'active' : '' ?>">📖 使用教程</a></li>
        <a href="/logout" class="btn btn-outline btn-sm" style="margin-top:auto;text-align:center;">🚪 退出登录</a>
    </aside>
    <main class="main"><?= $content ?? '' ?></main>
</div>
</body>
</html>
