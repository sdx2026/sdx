<?php $title = '描述文件管理'; $current = 'profiles'; ob_start(); ?>

<h1>📄 描述文件管理</h1>

<div class="card">
    <h2>上传描述文件</h2>
    <form id="profileForm" enctype="multipart/form-data">
        <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px;">
            <div class="form-group">
                <label>名称 *</label>
                <input type="text" name="name" required placeholder="例如: My App Distribution">
            </div>
            <div class="form-group">
                <label>关联应用 *</label>
                <select name="app_id" required id="appSelect">
                    <option value="">-- 选择应用 --</option>
                </select>
            </div>
            <div class="form-group">
                <label>关联证书</label>
                <select name="cert_id" id="certSelect">
                    <option value="">-- 选择证书 --</option>
                </select>
            </div>
        </div>
        <div class="form-group">
            <label>.mobileprovision 文件 *</label>
            <input type="file" name="profile_file" accept=".mobileprovision" required>
        </div>
        <button type="submit" class="btn btn-primary">上传描述文件</button>
    </form>
</div>

<h2>已有描述文件</h2>
<div id="profileList">加载中...</div>

<script>
async function loadProfiles() {
    const [profiles, apps, certs] = await Promise.all([
        fetch('/api/profiles').then(r => r.json()),
        fetch('/api/apps').then(r => r.json()),
        fetch('/api/certs').then(r => r.json())
    ]);
    
    // Populate selects
    document.getElementById('appSelect').innerHTML = 
        '<option value="">-- 选择应用 --</option>' + 
        apps.map(a => `<option value="${a.id}">${esc(a.name)} (${esc(a.bundle_id)})</option>`).join('');
    
    document.getElementById('certSelect').innerHTML = 
        '<option value="">-- 选择证书 --</option>' + 
        certs.map(c => `<option value="${c.id}">${esc(c.name)} (${c.type})</option>`).join('');
    
    // Show list
    const list = document.getElementById('profileList');
    if (!profiles.length) {
        list.innerHTML = '<div class="empty-state"><p>暂无描述文件，请先上传</p></div>';
        return;
    }
    
    list.innerHTML = `<table>
        <thead><tr><th>ID</th><th>名称</th><th>类型</th><th>Bundle ID</th><th>应用</th><th>证书</th><th>操作</th></tr></thead>
        <tbody>${profiles.map(p => `
            <tr>
                <td class="mono">#${p.id}</td>
                <td>${esc(p.name)}</td>
                <td><span class="badge badge-processing">${esc(p.profile_type || 'app-store')}</span></td>
                <td class="mono">${esc(p.bundle_id || '-')}</td>
                <td>${esc(p.app_name || '-')}</td>
                <td>${esc(p.cert_name || '-')}</td>
                <td>
                    <span class="text-muted" style="font-size:0.75rem">${p.expires_at || ''}</span>
                </td>
            </tr>`).join('')}
        </tbody></table>`;
}

function esc(s) { const d = document.createElement('div'); d.textContent = s || ''; return d.innerHTML; }

document.getElementById('profileForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const fd = new FormData(e.target);
    const resp = await fetch('/api/profiles/upload', { method: 'POST', body: fd });
    const result = await resp.json();
    if (result.success) { alert('描述文件上传成功！'); loadProfiles(); e.target.reset(); }
    else alert('失败: ' + (result.error || '未知错误'));
});

loadProfiles();
</script>

<?php $content = ob_get_clean(); require __DIR__ . '/layout.php'; ?>
