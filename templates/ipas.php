<?php $title = 'IPA 管理'; $current = 'ipas'; ob_start(); ?>

<h1>📦 IPA 文件管理</h1>

<div class="card">
    <div id="ipaList">加载中...</div>
</div>

<script>
async function loadIpas() {
    try {
        const resp = await fetch('/api/ipas');
        const data = await resp.json();
        const list = document.getElementById('ipaList');
        if (!data.ipa_files || !data.ipa_files.length) {
            list.innerHTML = '<div class="empty-state"><p>暂无上传的 IPA 文件</p><a href="/tasks/new" class="btn btn-primary">创建任务</a></div>';
            return;
        }
        let html = '<table><thead><tr><th>文件名</th><th>大小</th><th>修改时间</th><th>关联任务</th><th>操作</th></tr></thead><tbody>';
        for (const f of data.ipa_files) {
            html += '<tr>';
            html += '<td><strong>' + esc(f.name) + '</strong></td>';
            html += '<td class="mono">' + f.size + '</td>';
            html += '<td class="text-muted">' + f.mtime + '</td>';
            html += '<td>' + (f.task_count > 0 ? '<span class="badge badge-processing">' + f.task_count + ' 个任务</span>' : '<span class="text-muted">无</span>') + '</td>';
            html += '<td><button onclick="deleteIpa(\'' + esc(f.name) + '\')" class="btn btn-danger btn-sm">删除</button></td>';
            html += '</tr>';
        }
        html += '</tbody></table>';
        html += '<div class="text-muted mt-20">共 ' + data.ipa_files.length + ' 个 IPA 文件 | 总大小: ' + data.total_size + '</div>';
        list.innerHTML = html;
    } catch(e) {
        document.getElementById('ipaList').innerHTML = '<div class="empty-state"><p>加载失败</p></div>';
    }
}

function esc(s) { const d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

async function deleteIpa(name) {
    if (!confirm('确定删除 ' + name + '？\n注意：关联任务将无法下载原始IPA。')) return;
    try {
        const resp = await fetch('/api/ipas/delete', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({filename: name})
        });
        const data = await resp.json();
        if (data.success) { loadIpas(); }
        else alert('删除失败: ' + (data.error || '未知'));
    } catch(e) { alert('网络错误'); }
}

loadIpas();
</script>

<?php $content = ob_get_clean(); require __DIR__ . '/layout.php'; ?>
