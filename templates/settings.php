<?php $title = '系统设置'; $current = 'settings'; ob_start(); ?>

<h1>⚙️ 系统设置</h1>

<!-- ====== 批量 Apple 开发者账号 ====== -->
<div class="card">
    <h2>🍎 Apple 开发者账号（批量管理）</h2>
    <p class="text-muted">支持多账号，创建任务时可选择不同账号上传</p>
    
    <form id="addAppleAccount">
        <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 12px;">
            <div class="form-group"><label>Apple ID (邮箱)</label><input type="email" name="apple_id" required placeholder="account@email.com"></div>
            <div class="form-group"><label>App 专用密码</label><input type="password" name="app_password" required placeholder="xxxx-xxxx-xxxx-xxxx"></div>
            <div class="form-group"><label>备注</label><input type="text" name="note" placeholder="主账号/备用"></div>
        </div>
        <button type="submit" class="btn btn-primary btn-sm">➕ 添加账号</button>
        <span class="text-muted" style="margin-left:8px;">支持粘贴多行批量导入：每行格式 apple_id,password,备注</span>
    </form>
    
    <div class="form-group" style="margin-top:8px;">
        <textarea id="batchAppleImport" rows="3" placeholder="批量导入：apple1@email.com,pass1,主账号&#10;apple2@email.com,pass2,备用1&#10;apple3@email.com,pass3,备用2" style="font-size:0.8rem;"></textarea>
        <button onclick="batchImportApple()" class="btn btn-outline btn-sm" style="margin-top:4px;">📥 批量导入</button>
    </div>
    
    <h3 style="margin-top:16px;">已有账号</h3>
    <div id="appleAccountList">加载中...</div>
</div>

<!-- ====== 批量 API 密钥 ====== -->
<div class="card">
    <h2>🔑 App Store Connect API 密钥（批量管理）</h2>
    <p class="text-muted">支持多组 API Key，用于不同 Team 的自动证书/描述文件创建</p>
    
    <form id="addApiKey">
        <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 12px;">
            <div class="form-group"><label>Issuer ID</label><input type="text" name="issuer_id" required placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx"></div>
            <div class="form-group"><label>Key ID</label><input type="text" name="key_id" required placeholder="XXXXXXXXXX"></div>
            <div class="form-group"><label>备注</label><input type="text" name="note" placeholder="Team A / Team B"></div>
        </div>
        <div class="form-group"><label>API Key 内容 (.p8)</label><textarea name="key_content" rows="3" required placeholder="粘贴 .p8 文件全部内容"></textarea></div>
        <button type="submit" class="btn btn-primary btn-sm">➕ 添加密钥</button>
    </form>
    
    <h3 style="margin-top:16px;">已有密钥</h3>
    <div id="apiKeyList">加载中...</div>
</div>

<!-- ====== 通知配置 ====== -->
<div class="card">
    <h2>📢 通知配置</h2>
    <form id="settingsForm">
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
            <div class="form-group"><label>Webhook URL</label><input type="url" name="webhook_url" id="webhook_url"></div>
            <div class="form-group"><label>Webhook 密钥</label><input type="text" name="webhook_secret" id="webhook_secret"></div>
        </div>
        <div class="form-group"><label>企业微信机器人</label><input type="url" name="wechat_webhook" id="wechat_webhook"></div>
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
            <div class="form-group"><label>钉钉 Webhook</label><input type="url" name="dingtalk_webhook" id="dingtalk_webhook"></div>
            <div class="form-group"><label>钉钉密钥</label><input type="text" name="dingtalk_secret" id="dingtalk_secret"></div>
        </div>
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
            <div class="form-group"><label>Telegram Bot Token</label><input type="text" name="telegram_bot_token" id="telegram_bot_token"></div>
            <div class="form-group"><label>Telegram Chat ID</label><input type="text" name="telegram_chat_id" id="telegram_chat_id"></div>
        </div>

        <h3 style="margin-top:16px;">🔧 GitHub</h3>
        <div class="form-group"><label>GitHub Token</label><input type="password" name="github_token" id="github_token"></div>

        <h3 style="margin-top:16px;">🔒 修改密码</h3>
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
            <div class="form-group"><label>新密码</label><input type="password" name="new_password" id="new_password" placeholder="留空不修改"></div>
            <div class="form-group"><label>确认密码</label><input type="password" name="confirm_password" id="confirm_password" placeholder="留空不修改"></div>
        </div>
        <button type="submit" class="btn btn-primary">💾 保存设置</button>
        <span id="saveStatus" class="text-muted" style="margin-left:12px;"></span>
    </form>
</div>

<script>
// === Apple Accounts ===
async function loadAppleAccounts() {
    const resp = await fetch('/api/apple-accounts');
    const list = await resp.json();
    const el = document.getElementById('appleAccountList');
    if (!list.length) { el.innerHTML = '<div class="text-muted">暂无账号</div>'; return; }
    let html = '<table><thead><tr><th>ID</th><th>Apple ID</th><th>状态</th><th>备注</th><th>错误</th><th>操作</th></tr></thead><tbody>';
    for (const a of list) {
        const statusBadge = a.status === 'blocked' 
            ? '<span class="badge badge-failed" title="上传失败已禁用">🚫 异常</span>' 
            : '<span class="badge badge-completed">✅ 正常</span>';
        const errorCell = a.last_error ? '<span class="text-muted" style="font-size:0.75rem;max-width:150px;display:inline-block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="'+esc(a.last_error)+'">'+esc(a.last_error.substring(0,30))+'</span>' : '<span class="text-muted">-</span>';
        const actionBtn = a.status === 'blocked'
            ? '<button onclick="retryApple('+a.id+')" class="btn btn-primary btn-sm">🔄 重试</button> <button onclick="delApple('+a.id+')" class="btn btn-danger btn-sm">删除</button>'
            : '<button onclick="delApple('+a.id+')" class="btn btn-danger btn-sm">删除</button>';
        html += '<tr><td class="mono">#'+a.id+'</td><td>'+esc(a.apple_id)+'</td><td>'+statusBadge+'</td><td>'+esc(a.note||'-')+'</td><td>'+errorCell+'</td><td>'+actionBtn+'</td></tr>';
    }
    html += '</tbody></table>';
    el.innerHTML = html;
}
function esc(s) { const d = document.createElement('div'); d.textContent = s; return d.innerHTML; }
async function delApple(id) { if(!confirm('确定删除该账号？关联任务将清空引用。')) return; await fetch('/api/apple-accounts/'+id,{method:'DELETE'}); loadAppleAccounts(); }
async function retryApple(id) { await fetch('/api/apple-accounts/'+id+'/retry',{method:'POST'}); alert('已重置账号状态为正常'); loadAppleAccounts(); }
document.getElementById('addAppleAccount').addEventListener('submit', async (e) => {
    e.preventDefault();
    const data = Object.fromEntries(new FormData(e.target));
    const resp = await fetch('/api/apple-accounts', { method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify(data) });
    const r = await resp.json();
    if(r.success) { e.target.reset(); loadAppleAccounts(); } else alert('失败: '+r.error);
});
async function batchImportApple() {
    const text = document.getElementById('batchAppleImport').value.trim();
    if(!text) return;
    const lines = text.split('\n').filter(l=>l.trim());
    let ok=0, fail=0;
    for(const line of lines) {
        const [apple_id, app_password, note] = line.split(',');
        if(!apple_id || !app_password) { fail++; continue; }
        const resp = await fetch('/api/apple-accounts', { method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({apple_id:apple_id.trim(), app_password:app_password.trim(), note:(note||'').trim()}) });
        if((await resp.json()).success) ok++; else fail++;
    }
    alert('导入完成：成功 '+ok+'，失败 '+fail);
    document.getElementById('batchAppleImport').value = '';
    loadAppleAccounts();
}

// === API Keys ===
async function loadApiKeys() {
    const resp = await fetch('/api/api-keys');
    const list = await resp.json();
    const el = document.getElementById('apiKeyList');
    if (!list.length) { el.innerHTML = '<div class="text-muted">暂无密钥</div>'; return; }
    el.innerHTML = '<table><thead><tr><th>ID</th><th>Issuer ID</th><th>Key ID</th><th>备注</th><th>操作</th></tr></thead><tbody>' +
        list.map(k => '<tr><td class="mono">#'+k.id+'</td><td style="font-size:0.8rem;">'+esc(k.issuer_id)+'</td><td>'+esc(k.key_id)+'</td><td>'+esc(k.note||'-')+'</td><td><button onclick="delKey('+k.id+')" class="btn btn-danger btn-sm">删除</button></td></tr>').join('') + '</tbody></table>';
}
async function delKey(id) { if(!confirm('删除?')) return; await fetch('/api/api-keys/'+id,{method:'DELETE'}); loadApiKeys(); }
document.getElementById('addApiKey').addEventListener('submit', async (e) => {
    e.preventDefault();
    const data = Object.fromEntries(new FormData(e.target));
    const resp = await fetch('/api/api-keys', { method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify(data) });
    const r = await resp.json();
    if(r.success) { e.target.reset(); loadApiKeys(); } else alert('失败: '+r.error);
});

// === Settings ===
async function loadSettings() {
    const resp = await fetch('/api/settings');
    const data = await resp.json();
    Object.keys(data).forEach(k => { const el = document.getElementById(k); if(el) el.value = data[k]||''; });
}
document.getElementById('settingsForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const data = {};
    new FormData(e.target).forEach((v,k) => { if(v) data[k]=v; });
    if(data.new_password && data.new_password !== data.confirm_password) { alert('两次密码不一致'); return; }
    const resp = await fetch('/api/settings', { method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify(data) });
    const r = await resp.json();
    const st = document.getElementById('saveStatus');
    st.textContent = r.success ? '✅ 保存成功' : '❌ 失败';
    st.style.color = r.success ? 'var(--green)' : 'var(--red)';
    if(r.success) { e.target.reset(); loadSettings(); setTimeout(()=>st.textContent='',3000); }
});

loadAppleAccounts(); loadApiKeys(); loadSettings();
</script>

<?php $content = ob_get_clean(); require __DIR__ . '/layout.php'; ?>
