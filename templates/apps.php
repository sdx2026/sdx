<?php $title = '应用管理'; $current = 'apps'; ob_start(); ?>

<h1>📱 应用管理</h1>

<div class="card">
    <h2>添加应用</h2>
    <form method="POST" action="/apps" enctype="multipart/form-data">
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
            <div class="form-group">
                <label>应用名称 *</label>
                <input type="text" name="name" required placeholder="例如: 我的应用">
            </div>
            <div class="form-group">
                <label>Bundle ID *</label>
                <input type="text" name="bundle_id" required placeholder="例如: com.example.app">
            </div>
            <div class="form-group">
                <label>Team ID</label>
                <input type="text" name="team_id" placeholder="Apple Team ID">
            </div>
            <div class="form-group">
                <label>Team 名称</label>
                <input type="text" name="team_name" placeholder="Developer Team Name">
            </div>
        </div>
        <button type="submit" class="btn btn-primary">添加应用</button>
    </form>
</div>

<h2>已有应用</h2>

<?php if (empty($apps)): ?>
<div class="empty-state">
    <p>暂无应用，请先添加</p>
</div>
<?php else: ?>
<table>
    <thead>
        <tr>
            <th>ID</th>
            <th>名称</th>
            <th>Bundle ID</th>
            <th>Team ID</th>
            <th>创建时间</th>
            <th>操作</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($apps as $app): ?>
        <tr>
            <td class="mono">#<?= $app['id'] ?></td>
            <td><?= htmlspecialchars($app['name']) ?></td>
            <td class="mono"><?= htmlspecialchars($app['bundle_id']) ?></td>
            <td><?= htmlspecialchars($app['team_id'] ?: '-') ?></td>
            <td class="text-muted"><?= $app['created_at'] ?></td>
            <td>
                <button onclick="deleteApp(<?= $app['id'] ?>)" class="btn btn-danger btn-sm">删除</button>
            </td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>

<script>
async function deleteApp(id) {
    if (!confirm('确定要删除该应用吗？')) return;
    const resp = await fetch('/apps/' + id, { method: 'DELETE' });
    if (resp.ok) location.reload();
    else alert('删除失败');
}
</script>

<?php $content = ob_get_clean(); require __DIR__ . '/layout.php'; ?>
