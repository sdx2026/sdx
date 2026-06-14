<?php $title = 'IPA 管理'; $current = 'ipas'; ob_start(); ?>

<h1>📦 IPA 文件管理</h1>

<div class="card" style="margin-bottom:16px;">
    <form id="uploadForm" enctype="multipart/form-data">
        <label>📤 上传 IPA 文件</label>
        <div style="display:flex;gap:10px;align-items:center;margin-top:8px;">
            <input type="file" name="ipa_file" id="ipaFile" accept=".ipa" required style="flex:1;">
            <button type="submit" class="btn btn-primary">上传</button>
        </div>
        <div id="uploadStatus" style="margin-top:8px;"></div>
    </form>
</div>

<div class="card">
    <div id="ipaList">加载中...</div>
</div>

<script>
function goCreate(encodedName) {
    location.href = '/tasks/new?ipa=' + encodedName;
}

async function loadIpas() {
    try {
        const resp = await fetch('/api/ipas');
        const data = await resp.json();
        const list = document.getElementById('ipaList');
        if (!data.ipa_files || !data.ipa_files.length) {
            list.innerHTML = '<div class="empty-state"><p>暂无上传的 IPA 文件</p></div>';
            return;
        }
        let html = '<table><thead><tr><th>文件名</th><th>大小</th><th>修改时间</th><th>操作</th></tr></thead><tbody>';
        for (const f of data.ipa_files) {
            const enc = encodeURIComponent(f.name);
            html += '<tr>';
            html += '<td><strong>' + esc(f.name) + '</strong></td>';
            html += '<td class="mono">' + f.size + '</td>';
            html += '<td class="text-muted">' + f.mtime + '</td>';
            html += '<td>';
            html += '<button onclick="goCreate(\'' + enc + '\')" class="btn btn-primary btn-sm">🚀 用此创建任务</button> ';
            html += '<button onclick="deleteIpa(\'' + esc(f.name) + '\')" class="btn btn-danger btn-sm">删除</button>';
            html += '</td></tr>';
        }
        html += '</tbody></table>';
        list.innerHTML = html;
    } catch(e) {
        document.getElementById('ipaList').innerHTML = '<div class="empty-state"><p>加载失败</p></div>';
    }
}

function esc(s) { const d = document.createElement('div'); d.textContent = s || ''; return d.innerHTML; }

async function deleteIpa(name) {
    if (!confirm('确定删除 ' + name + '？')) return;
    try {
        const resp = await fetch('/api/ipas/delete', { method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({filename: name}) });
        const data = await resp.json();
        if (data.success) loadIpas(); else alert('删除失败: ' + (data.error || '未知'));
    } catch(e) { alert('网络错误'); }
}

document.getElementById('uploadForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const st = document.getElementById('uploadStatus');
    const file = document.getElementById('ipaFile').files[0];
    if (!file) { st.innerHTML = '<span style="color:var(--red);">请选择文件</span>'; return; }
    st.innerHTML = '<span style="color:var(--blue);">上传中... ' + (file.size/1024/1024).toFixed(1) + ' MB</span>';
    const fd = new FormData();
    fd.append('ipa_file', file);
    try {
        const resp = await fetch('/api/ipas/upload', { method: 'POST', body: fd });
        const data = await resp.json();
        if (data.success) {
            st.innerHTML = '<span style="color:var(--green);">✅ 上传成功！</span> <button onclick="goCreate(\'' + encodeURIComponent(data.filename) + '\')" class="btn btn-primary btn-sm">🚀 创建任务</button>';
            loadIpas();
        } else {
            st.innerHTML = '<span style="color:var(--red);">❌ ' + (data.error || '上传失败') + '</span>';
        }
    } catch(err) {
        st.innerHTML = '<span style="color:var(--red);">❌ 网络错误</span>';
    }
});

loadIpas();
</script>

<?php $content = ob_get_clean(); require __DIR__ . '/layout.php'; ?>
