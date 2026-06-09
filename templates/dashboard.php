<?php $title = '仪表盘'; $current = 'dashboard'; ob_start(); ?>
<h1>📊 仪表盘</h1>
<div id="dashboardContent">
<div class="stats" id="statCards">
    <div class="stat-card pending"><div class="stat-value">-</div><div class="stat-label">待处理</div></div>
    <div class="stat-card processing"><div class="stat-value">-</div><div class="stat-label">处理中</div></div>
    <div class="stat-card completed"><div class="stat-value">-</div><div class="stat-label">已完成</div></div>
    <div class="stat-card failed"><div class="stat-value">-</div><div class="stat-label">失败</div></div>
    <div class="stat-card apps"><div class="stat-value">-</div><div class="stat-label">应用</div></div>
    <div class="stat-card"><div class="stat-value" id="workerStatus">-</div><div class="stat-label" id="workerLabel">Worker</div></div>
</div>
<div id="accountHealth"></div>
<div id="alertArea"></div>
<h2>📋 最近任务</h2>
<div id="recentTasks">加载中...</div>
</div>

<script>
function esc(s) { const d = document.createElement("div"); d.textContent = s; return d.innerHTML; }

async function refresh() {
    // Fetch dashboard stats
    let data;
    try {
        const resp = await fetch('/api/dashboard-stats');
        if (!resp.ok) throw new Error("HTTP " + resp.status);
        data = await resp.json();
    } catch (e) {
        document.getElementById("recentTasks").innerHTML = '<div class="empty-state"><p>⚠️ 数据加载失败: ' + e.message + '</p><p class="text-muted">请确认已登录，或 <a href="/login">点此重新登录</a></p></div>';
        return;
    }
    const s = data.stats || {};
    document.querySelector('.pending .stat-value').textContent = s.pending || 0;
    document.querySelector('.processing .stat-value').textContent = s.processing || 0;
    document.querySelector('.completed .stat-value').textContent = s.completed || 0;
    document.querySelector('.failed .stat-value').textContent = s.failed || 0;
    document.querySelector('.apps .stat-value').textContent = data.apps || 0;
    
    // Worker status
    const workerEl = document.getElementById('workerStatus');
    const workerLabel = document.getElementById('workerLabel');
    const ws = data.worker || {};
    if (ws.running) {
        workerEl.textContent = '✅';
        workerLabel.textContent = '运行中';
        workerEl.style.color = 'var(--green)';
    } else {
        workerEl.textContent = '❌';
        workerLabel.textContent = '已停止';
        workerEl.style.color = 'var(--red)';
    }
    
    // Expiry alerts
    const alerts = data.alerts || [];
    let alertHtml = '';
    if (alerts.length > 0) {
        alertHtml = '<div class="card" style="border-color: var(--amber);"><h2 style="color: var(--amber);">⚠️ 过期提醒</h2><ul style="list-style:none;padding:0">';
        for (const a of alerts) {
            alertHtml += '<li style="padding:6px 0;border-bottom:1px solid var(--border);"><span class="badge ' + (a.urgent ? 'badge-failed' : 'badge-pending') + '">' + a.days + '天</span> ' + a.name + ' <span class="text-muted">(' + a.type + ')</span></li>';
        }
        alertHtml += '</ul></div>';
    }
        // Account health
    const ah = data.account_health || {total:0, active:0, blocked:0};
    const ahEl = document.getElementById('accountHealth');
    if (ah.total > 0) {
        let ahHtml = '<div class="card" style="margin-bottom:20px;"><h2>🍎 开发者账号健康度</h2><div style="display:flex;gap:16px;margin:12px 0;">';
        ahHtml += '<div style="flex:1;background:var(--surface2);padding:12px;border-radius:var(--radius);text-align:center;"><div style="font-size:1.5rem;color:var(--green);">' + ah.active + '</div><div class="text-muted">正常</div></div>';
        ahHtml += '<div style="flex:1;background:var(--surface2);padding:12px;border-radius:var(--radius);text-align:center;"><div style="font-size:1.5rem;color:' + (ah.blocked>0?'var(--red)':'var(--text2)') + ';">' + ah.blocked + '</div><div class="text-muted">异常</div></div>';
        ahHtml += '</div>';
        if (ah.accounts) {
            ahHtml += '<table style="margin-top:8px;"><thead><tr><th>账号</th><th>备注</th><th>状态</th></tr></thead><tbody>';
            for (const a of ah.accounts) {
                ahHtml += '<tr><td>' + esc(a.apple_id) + '</td><td>' + esc(a.note||'-') + '</td><td>' + (a.status==='active'?'<span class="badge badge-completed">✅ 正常</span>':'<span class="badge badge-failed">🚫 异常</span>') + '</td></tr>';
            }
            ahHtml += '</tbody></table>';
        }
        ahHtml += '</div>';
        ahEl.innerHTML = ahHtml;
    } else {
        ahEl.innerHTML = '<div class="card" style="margin-bottom:20px;border-color:var(--amber);"><h2>🍎 开发者账号健康度</h2><div class="empty-state" style="padding:20px;"><p>暂未添加 Apple 开发者账号</p><a href="/settings" class="btn btn-primary btn-sm">去设置页添加</a></div></div>';
    }
    
    document.getElementById('alertArea').innerHTML = alertHtml;
    
    // Recent tasks
    const tasks = data.recent_tasks || [];
    let taskHtml = '';
    if (tasks.length === 0) {
        taskHtml = '<div class="empty-state"><p>暂无任务</p><a href="/tasks/new" class="btn btn-primary">创建任务</a></div>';
    } else {
        taskHtml = '<table><thead><tr><th>ID</th><th>类型</th><th>应用</th><th>状态</th><th>进度</th><th>时间</th><th>操作</th></tr></thead><tbody>';
        for (const t of tasks) {
            const typeMap = {sign_only:'仅签名',upload_only:'仅上传',sign_and_upload:'签名+上传',github_sign:'🚀 GitHub'};
            taskHtml += '<tr>' +
                '<td class="mono">#' + t.id + '</td>' +
                '<td>' + (typeMap[t.type] || t.type) + '</td>' +
                '<td>' + (t.app_name || '-') + '</td>' +
                '<td><span class="badge badge-' + t.status + '">' + t.status + '</span></td>' +
                '<td><div class="progress-bar"><div class="progress-fill" style="width:' + t.progress + '%"></div></div><span class="text-muted">' + t.progress + '%</span></td>' +
                '<td class="text-muted">' + t.updated_at + '</td>' +
                '<td><a href="/tasks/' + t.id + '" class="btn btn-outline btn-sm">详情</a></td>' +
                '</tr>';
        }
        taskHtml += '</tbody></table>';
    }
    document.getElementById('recentTasks').innerHTML = taskHtml;
}

refresh();
setInterval(refresh, 10000); // Auto-refresh every 10s
</script>
<?php $content = ob_get_clean(); require __DIR__ . '/layout.php'; ?>
