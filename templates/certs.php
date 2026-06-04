<?php $title = '证书管理'; $current = 'certs'; ob_start(); ?>

<h1>🔐 证书管理</h1>

<div class="card">
    <h2>生成新证书</h2>
    <form id="certForm">
        <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px;">
            <div class="form-group">
                <label>证书名称 *</label>
                <input type="text" name="name" required placeholder="例如: My Distribution Cert">
            </div>
            <div class="form-group">
                <label>Team ID</label>
                <input type="text" name="team_id" placeholder="Apple Team ID">
            </div>
            <div class="form-group">
                <label>类型</label>
                <select name="type">
                    <option value="distribution">distribution</option>
                    <option value="development">development</option>
                </select>
            </div>
            <div class="form-group">
                <label>通用名称 (CN)</label>
                <input type="text" name="common_name" placeholder="Common Name">
            </div>
            <div class="form-group">
                <label>邮箱</label>
                <input type="email" name="email" placeholder="email@example.com">
            </div>
            <div class="form-group">
                <label>密码</label>
                <input type="text" name="password" placeholder="证书密码（可选）">
            </div>
        </div>
        <button type="submit" class="btn btn-primary">生成证书</button>
    </form>
</div>

<div class="card">
    <h2>导入证书 (P12)</h2>
    <form id="importForm">
        <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px;">
            <div class="form-group">
                <label>证书名称 *</label>
                <input type="text" name="name" required placeholder="证书名称">
            </div>
            <div class="form-group">
                <label>Team ID</label>
                <input type="text" name="team_id" placeholder="Team ID">
            </div>
            <div class="form-group">
                <label>P12 密码</label>
                <input type="text" name="password" placeholder="P12 密码">
            </div>
        </div>
        <div class="form-group">
            <label>P12 证书内容 (Base64) *</label>
            <textarea name="p12_data" rows="4" placeholder="将 .p12 文件 Base64 编码后粘贴到这里"></textarea>
        </div>
        <button type="submit" class="btn btn-primary">导入证书</button>
    </form>
</div>

<h2>已有证书</h2>
<div id="certList">加载中...</div>

<script>
async function loadCerts() {
    const resp = await fetch('/api/certs');
    const certs = await resp.json();
    const list = document.getElementById('certList');
    if (!certs.length) {
        list.innerHTML = '<div class="empty-state"><p>暂无证书</p></div>';
        return;
    }
    list.innerHTML = `<table>
        <thead><tr><th>ID</th><th>名称</th><th>类型</th><th>Team</th><th>过期时间</th><th>操作</th></tr></thead>
        <tbody>${certs.map(c => `
            <tr>
                <td class="mono">#${c.id}</td>
                <td>${esc(c.name)}</td>
                <td><span class="badge badge-completed">${c.type}</span></td>
                <td>${esc(c.team_id || '-')}</td>
                <td class="${c.expires_at && c.expires_at < new Date().toISOString() ? 'text-muted' : ''}">${c.expires_at || '-'}</td>
                <td>
                    <button onclick="deleteCert(${c.id})" class="btn btn-danger btn-sm">删除</button>
                </td>
            </tr>`).join('')}
        </tbody></table>`;
}

function esc(s) { const d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

async function deleteCert(id) {
    if (!confirm('确定删除该证书？')) return;
    await fetch('/api/certs/' + id, { method: 'DELETE' });
    loadCerts();
}

document.getElementById('certForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const data = Object.fromEntries(new FormData(e.target));
    const resp = await fetch('/api/certs', { 
        method: 'POST', 
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify(data)
    });
    const result = await resp.json();
    if (result.success) { alert('证书生成成功！ID: ' + result.certificate.id); loadCerts(); e.target.reset(); }
    else alert('失败: ' + (result.error || '未知错误'));
});

document.getElementById('importForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const data = Object.fromEntries(new FormData(e.target));
    const resp = await fetch('/api/certs/import', { 
        method: 'POST', 
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify(data)
    });
    const result = await resp.json();
    if (result.success) { alert('证书导入成功！ID: ' + result.certificate.id); loadCerts(); e.target.reset(); }
    else alert('失败: ' + (result.error || '未知错误'));
});

loadCerts();
</script>

<?php $content = ob_get_clean(); require __DIR__ . '/layout.php'; ?>
