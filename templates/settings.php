<?php $title = '系统设置'; $current = 'settings'; ob_start(); ?>

<h1>⚙️ 系统设置</h1>

<div class="card">
    <h2>🍎 Apple 开发者账号</h2>
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

        <h3 style="margin-top:16px;">🔑 App Store Connect API 密钥</h3>
        <p class="text-muted">用于自动创建证书和描述文件（App Store Connect → 用户和访问 → 密钥 → API）</p>
        <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px;">
            <div class="form-group">
                <label>Issuer ID</label>
                <input type="text" name="apple_issuer_id" id="apple_issuer_id" placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx">
            </div>
            <div class="form-group">
                <label>Key ID</label>
                <input type="text" name="apple_key_id" id="apple_key_id" placeholder="XXXXXXXXXX">
            </div>
            <div class="form-group">
                <label>API Key 文件 (.p8)</label>
                <textarea name="apple_api_key_content" id="apple_api_key_content" rows="6" placeholder="粘贴 .p8 文件内容，或直接在此输入"></textarea>
            </div>
        </div>

        <h2 style="margin-top:24px;">🔧 GitHub 配置</h2>
        <div class="form-group">
            <label>GitHub Personal Access Token</label>
            <input type="password" name="github_token" id="github_token" placeholder="ghp_xxxxxxxxxxxx">
        </div>

        <h2 style="margin-top:24px;">📢 通知配置</h2>
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

        <div class="form-group">
            <label>企业微信机器人 Webhook</label>
            <input type="url" name="wechat_webhook" id="wechat_webhook" placeholder="https://qyapi.weixin.qq.com/cgi-bin/webhook/send?key=...">
        </div>
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
            <div class="form-group">
                <label>钉钉机器人 Webhook</label>
                <input type="url" name="dingtalk_webhook" id="dingtalk_webhook" placeholder="https://oapi.dingtalk.com/robot/send?access_token=...">
            </div>
            <div class="form-group">
                <label>钉钉签名密钥</label>
                <input type="text" name="dingtalk_secret" id="dingtalk_secret" placeholder="加签密钥">
            </div>
        </div>
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
            <div class="form-group">
                <label>Telegram Bot Token</label>
                <input type="text" name="telegram_bot_token" id="telegram_bot_token" placeholder="123456:ABC-DEF...">
            </div>
            <div class="form-group">
                <label>Telegram Chat ID</label>
                <input type="text" name="telegram_chat_id" id="telegram_chat_id" placeholder="-1001234567890">
            </div>
        </div>

        <h2 style="margin-top:24px;">🔒 修改密码</h2>
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
            <div class="form-group">
                <label>新密码</label>
                <input type="password" name="new_password" id="new_password" placeholder="留空不修改">
            </div>
            <div class="form-group">
                <label>确认密码</label>
                <input type="password" name="confirm_password" id="confirm_password" placeholder="留空不修改">
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
    new FormData(e.target).forEach((v, k) => { if (v) data[k] = v; });
    if (data.new_password && data.new_password !== data.confirm_password) {
        alert('两次密码不一致'); return;
    }
    const resp = await fetch('/api/settings', { method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(data) });
    const result = await resp.json();
    const st = document.getElementById('saveStatus');
    st.textContent = result.success ? '✅ 保存成功' : '❌ 失败';
    st.style.color = result.success ? 'var(--green)' : 'var(--red)';
    if (result.success) { e.target.reset(); loadSettings(); setTimeout(() => st.textContent = '', 3000); }
});
loadSettings();
</script>

<?php $content = ob_get_clean(); require __DIR__ . '/layout.php'; ?>
