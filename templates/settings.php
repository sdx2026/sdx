<?php $title = '系统设置'; $current = 'settings'; ob_start(); ?>

<h1>⚙️ 系统设置</h1>

<div class="card">
    <h2>Apple 开发者账号</h2>
    <form id="settingsForm">
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
            <div class="form-group">
                <label>Apple ID (邮箱)</label>
                <input type="email" name="apple_id" id="apple_id" placeholder="your@email.com">
            </div>
            <div class="form-group">
                <label>App 专用密码</label>
                <input type="password" name="app_password" id="app_password" placeholder="xxxx-xxxx-xxxx-xxxx">
                <span class="text-muted">去 appleid.apple.com 生成</span>
            </div>
        </div>

        <h2 style="margin-top:24px;">GitHub 配置</h2>
        <div class="form-group">
            <label>GitHub Personal Access Token</label>
            <input type="password" name="github_token" id="github_token" placeholder="ghp_xxxxxxxxxxxx">
            <span class="text-muted">在 github.com/settings/tokens 生成，勾选 workflow 权限</span>
        </div>

        <h2 style="margin-top:24px;">Webhook 通知 (可选)</h2>
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
            <div class="form-group">
                <label>Webhook URL</label>
                <input type="url" name="webhook_url" id="webhook_url" placeholder="https://hooks.slack.com/...">
            </div>
            <div class="form-group">
                <label>Webhook 密钥</label>
                <input type="text" name="webhook_secret" id="webhook_secret" placeholder="签名密钥">
            </div>
        </div>

        <button type="submit" class="btn btn-primary">💾 保存设置</button>
        <span id="saveStatus" class="text-muted" style="margin-left:12px;"></span>
    </form>
</div>

<script>
async function loadSettings() {
    const resp = await fetch('/api/settings');
    const data = await resp.json();
    Object.keys(data).forEach(k => {
        const el = document.getElementById(k);
        if (el) el.value = data[k] || '';
    });
}

document.getElementById('settingsForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const data = {};
    new FormData(e.target).forEach((v, k) => data[k] = v);
    const resp = await fetch('/api/settings', { 
        method: 'POST', 
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify(data)
    });
    const result = await resp.json();
    const st = document.getElementById('saveStatus');
    st.textContent = result.success ? '✅ 保存成功' : '❌ 保存失败';
    st.style.color = result.success ? 'var(--green)' : 'var(--red)';
    setTimeout(() => st.textContent = '', 3000);
});

loadSettings();
</script>

<?php $content = ob_get_clean(); require __DIR__ . '/layout.php'; ?>
