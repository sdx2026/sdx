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

<div class="card" style="border-color: var(--accent);">
    <h2>🍎 Apple API 一键生成描述文件 <span class="text-muted" style="font-size:0.8rem;">(需先在设置页配置 API Key)</span></h2>
    <p class="text-muted">自动调用 App Store Connect API 创建描述文件，无需 Mac 操作</p>
    <form id="appleProfileForm">
        <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px;">
            <div class="form-group">
                <label>名称</label>
                <input type="text" name="name" placeholder="例如: Auto App Store Profile">
            </div>
            <div class="form-group">
                <label>Bundle ID *</label>
                <input type="text" name="bundle_id" required placeholder="com.example.app">
            </div>
            <div class="form-group">
                <label>关联应用</label>
                <select name="app_id" id="appleAppSelect">
                    <option value="">-- 可选 --</option>
                </select>
            </div>
            <div class="form-group">
                <label>关联证书 *</label>
                <select name="cert_id" id="appleCertSelect" required>
                    <option value="">-- 选择证书 --</option>
                </select>
            </div>
        </div>
        <button type="submit" class="btn btn-primary" style="background:var(--green);">🍎 一键生成描述文件</button>
        <span id="appleProfileStatus" class="text-muted" style="margin-left:8px;"></span>
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
                    ${p.profile_path ? '<a href="/download/' + p.profile_path.split('/').pop() + '" class="btn btn-outline btn-sm" style="margin-left:4px;" download>⬇ 下载</a>' : ''}
                    <button onclick="deleteProfile(${p.id})" class="btn btn-danger btn-sm" style="margin-left:4px;">删除</button>
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

// Load apps/certs for the Apple API form
async function loadAppleFormData() {
    const [apps, certs] = await Promise.all([
        fetch('/api/apps').then(r => r.json()),
        fetch('/api/certs').then(r => r.json())
    ]);
    document.getElementById('appleAppSelect').innerHTML = 
        '<option value="">-- 可选 --</option>' + 
        apps.map(a => `<option value="${a.id}" data-bundle="${esc(a.bundle_id)}">${esc(a.name)} (${esc(a.bundle_id)})</option>`).join('');
    document.getElementById('appleCertSelect').innerHTML = 
        '<option value="">-- 选择证书 --</option>' + 
        certs.map(c => `<option value="${c.id}">${esc(c.name)} (${c.type})</option>`).join('');
    // Auto-fill bundle_id when app selected
    document.getElementById('appleAppSelect').addEventListener('change', function() {
        const opt = this.options[this.selectedIndex];
        if (opt && opt.dataset.bundle) {
            document.querySelector('#appleProfileForm input[name="bundle_id"]').value = opt.dataset.bundle;
        }
    });
}

// Apple API auto-generate
document.getElementById('appleProfileForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const btn = e.target.querySelector('button');
    const st = document.getElementById('appleProfileStatus');
    btn.disabled = true; st.textContent = '⏳ 正在调用 Apple API...';
    try {
        const data = Object.fromEntries(new FormData(e.target));
        const resp = await fetch('/api/profiles/apple-generate', { method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify(data) });
        const r = await resp.json();
        if (r.success) { st.textContent = '✅ 描述文件已生成！'; st.style.color = 'var(--green)'; loadProfiles(); e.target.reset(); }
        else { st.textContent = '❌ ' + (r.error || '失败'); st.style.color = 'var(--red)'; }
    } catch(err) { st.textContent = '❌ 网络错误'; st.style.color = 'var(--red)'; }
    btn.disabled = false;
});

async function deleteProfile(id) {
    if (!confirm('确定删除该描述文件？关联任务将清空引用。')) return;
    const resp = await fetch('/api/profiles/' + id, { method: 'DELETE' });
    const r = await resp.json();
    if (r.success) { loadProfiles(); } else { alert('删除失败: ' + r.error); }
}

loadAppleFormData();
loadProfiles();
</script>

<?php $content = ob_get_clean(); require __DIR__ . '/layout.php'; ?>
