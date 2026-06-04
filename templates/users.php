<?php $title = '用户管理'; $current = 'users'; ob_start(); ?>

<h1>👥 用户管理</h1>

<div class="card">
    <h2>添加用户</h2>
    <form id="addUserForm">
        <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px;">
            <div class="form-group">
                <label>用户名 *</label>
                <input type="text" name="username" required placeholder="登录用户名">
            </div>
            <div class="form-group">
                <label>密码 *</label>
                <input type="password" name="password" required placeholder="最少6位">
            </div>
            <div class="form-group">
                <label>角色</label>
                <select name="role">
                    <option value="user">普通用户</option>
                    <option value="admin">管理员</option>
                </select>
            </div>
        </div>
        <button type="submit" class="btn btn-primary">添加用户</button>
    </form>
</div>

<h2>已有用户</h2>
<div id="userList">加载中...</div>

<script>
async function loadUsers() {
    const resp = await fetch('/api/users');
    const users = await resp.json();
    const list = document.getElementById('userList');
    if (!users.length) { list.innerHTML = '<div class="empty-state"><p>暂无其他用户</p></div>'; return; }
    list.innerHTML = '<table><thead><tr><th>ID</th><th>用户名</th><th>角色</th><th>创建时间</th><th>最后登录</th><th>操作</th></tr></thead><tbody>' +
        users.map(u => '<tr><td class="mono">#' + u.id + '</td><td><strong>' + esc(u.username) + '</strong></td><td><span class="badge ' + (u.role === 'admin' ? 'badge-failed' : 'badge-completed') + '">' + u.role + '</span></td><td class="text-muted">' + (u.created_at || '-') + '</td><td class="text-muted">' + (u.last_login || '-') + '</td><td><button onclick="deleteUser(' + u.id + ')" class="btn btn-danger btn-sm">删除</button></td></tr>').join('') +
        '</tbody></table>';
}
function esc(s) { const d = document.createElement('div'); d.textContent = s; return d.innerHTML; }
async function deleteUser(id) {
    if (!confirm('确定删除该用户？')) return;
    await fetch('/api/users/' + id, { method: 'DELETE' });
    loadUsers();
}
document.getElementById('addUserForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const data = Object.fromEntries(new FormData(e.target));
    const resp = await fetch('/api/users', { method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(data) });
    const r = await resp.json();
    if (r.success) { alert('用户创建成功'); loadUsers(); e.target.reset(); }
    else alert('失败: ' + (r.error || '未知'));
});
loadUsers();
</script>

<?php $content = ob_get_clean(); require __DIR__ . '/layout.php'; ?>
