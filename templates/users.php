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

        <h3 style="margin-top:16px;">🔒 菜单权限</h3>
        <p class="text-muted">管理员默认拥有全部权限，以下针对普通用户</p>
        <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 8px;" id="permCheckboxes">
            <label style="display:flex;align-items:center;gap:6px;cursor:pointer;color:var(--text2);font-size:0.85rem;">
                <input type="checkbox" name="perms[]" value="dashboard" checked> 📊 仪表盘
            </label>
            <label style="display:flex;align-items:center;gap:6px;cursor:pointer;color:var(--text2);font-size:0.85rem;">
                <input type="checkbox" name="perms[]" value="tasks" checked> 📋 任务列表
            </label>
            <label style="display:flex;align-items:center;gap:6px;cursor:pointer;color:var(--text2);font-size:0.85rem;">
                <input type="checkbox" name="perms[]" value="tasks_new" checked> ➕ 新建任务
            </label>
            <label style="display:flex;align-items:center;gap:6px;cursor:pointer;color:var(--text2);font-size:0.85rem;">
                <input type="checkbox" name="perms[]" value="ipas"> 📦 IPA 管理
            </label>
            <label style="display:flex;align-items:center;gap:6px;cursor:pointer;color:var(--text2);font-size:0.85rem;">
                <input type="checkbox" name="perms[]" value="apps"> 📱 应用管理
            </label>
            <label style="display:flex;align-items:center;gap:6px;cursor:pointer;color:var(--text2);font-size:0.85rem;">
                <input type="checkbox" name="perms[]" value="certs"> 🔐 证书管理
            </label>
            <label style="display:flex;align-items:center;gap:6px;cursor:pointer;color:var(--text2);font-size:0.85rem;">
                <input type="checkbox" name="perms[]" value="profiles"> 📄 描述文件
            </label>
            <label style="display:flex;align-items:center;gap:6px;cursor:pointer;color:var(--text2);font-size:0.85rem;">
                <input type="checkbox" name="perms[]" value="stats"> 📈 统计图表
            </label>
            <label style="display:flex;align-items:center;gap:6px;cursor:pointer;color:var(--text2);font-size:0.85rem;">
                <input type="checkbox" name="perms[]" value="settings"> ⚙️ 设置
            </label>
            <label style="display:flex;align-items:center;gap:6px;cursor:pointer;color:var(--text2);font-size:0.85rem;">
                <input type="checkbox" name="perms[]" value="users"> 👥 用户管理
            </label>
            <label style="display:flex;align-items:center;gap:6px;cursor:pointer;color:var(--text2);font-size:0.85rem;">
                <input type="checkbox" name="perms[]" value="logs"> 📝 操作日志
            </label>
        </div>
        <button type="submit" class="btn btn-primary" style="margin-top:12px;">添加用户</button>
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
    let html = '<table><thead><tr><th>ID</th><th>用户名</th><th>角色</th><th>菜单权限</th><th>创建时间</th><th>最后登录</th><th>操作</th></tr></thead><tbody>';
    for (const u of users) {
        const perms = u.permissions ? JSON.parse(u.permissions) : [];
        const permNames = {dashboard:'📊',tasks:'📋',tasks_new:'➕',ipas:'📦',apps:'📱',certs:'🔐',profiles:'📄',stats:'📈',settings:'⚙️',users:'👥',logs:'📝'};
        const permStr = u.role === 'admin' ? '全部' : (perms.length ? perms.map(p => permNames[p]||p).join(' ') : '无');
        html += '<tr><td class="mono">#' + u.id + '</td><td><strong>' + esc(u.username) + '</strong></td><td><span class="badge ' + (u.role==='admin'?'badge-failed':'badge-completed') + '">' + u.role + '</span></td><td style="font-size:0.8rem;">' + permStr + '</td><td class="text-muted">' + (u.created_at||'-') + '</td><td class="text-muted">' + (u.last_login||'-') + '</td><td><button onclick="deleteUser(' + u.id + ')" class="btn btn-danger btn-sm">删除</button></td></tr>';
    }
    html += '</tbody></table>';
    list.innerHTML = html;
}
function esc(s) { const d = document.createElement('div'); d.textContent = s; return d.innerHTML; }
async function deleteUser(id) {
    if (!confirm('确定删除该用户？')) return;
    await fetch('/api/users/' + id, { method: 'DELETE' });
    loadUsers();
}
document.getElementById('addUserForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const fd = new FormData(e.target);
    const data = { username: fd.get('username'), password: fd.get('password'), role: fd.get('role') };
    data.permissions = fd.getAll('perms[]');
    const resp = await fetch('/api/users', { method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(data) });
    const r = await resp.json();
    if (r.success) { alert('用户创建成功'); loadUsers(); e.target.reset(); }
    else alert('失败: ' + (r.error || '未知'));
});
loadUsers();
</script>

<?php $content = ob_get_clean(); require __DIR__ . '/layout.php'; ?>
