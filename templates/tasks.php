<?php $title = '任务列表'; $current = 'tasks'; ob_start(); ?>

<h1>📋 任务列表</h1>

<div class="flex-row mb-20">
    <a href="?status=" class="btn btn-outline btn-sm">全部</a>
    <a href="?status=pending" class="btn btn-outline btn-sm">待处理</a>
    <a href="?status=processing" class="btn btn-outline btn-sm">处理中</a>
    <a href="?status=completed" class="btn btn-outline btn-sm">已完成</a>
    <a href="?status=failed" class="btn btn-outline btn-sm">失败</a>
    <a href="/tasks/new" class="btn btn-primary btn-sm" style="margin-left: auto;">➕ 新建任务</a>
</div>

<?php if (empty($tasks)): ?>
<div class="empty-state">
    <p>暂无任务</p>
    <a href="/tasks/new" class="btn btn-primary">创建任务</a>
</div>
<?php else: ?>
<table>
    <thead>
        <tr>
            <th>ID</th>
            <th>类型</th>
            <th>应用</th>
            <th>状态</th>
            <th>进度</th>
            <th>重试</th>
            <th>更新时间</th>
            <th>操作</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($tasks as $t): ?>
        <tr>
            <td class="mono">#<?= $t['id'] ?></td>
            <td>
                <?= match($t['type']) {
                    'sign_only' => '仅签名',
                    'upload_only' => '仅上传',
                    'sign_and_upload' => '签名+上传',
                    default => $t['type'],
                } ?>
            </td>
            <td><?= htmlspecialchars($t['app_name'] ?: '-') ?></td>
            <td><span class="badge badge-<?= $t['status'] ?>"><?= $t['status'] ?></span></td>
            <td>
                <div style="width: 80px;">
                    <div class="progress-bar">
                        <div class="progress-fill" style="width: <?= $t['progress'] ?>%"></div>
                    </div>
                    <span class="text-muted"><?= $t['progress'] ?>%</span>
                </div>
            </td>
            <td class="text-muted"><?= (int)$t['retries'] ?>/<?= (int)$t['max_retries'] ?></td>
            <td class="text-muted" style="font-size:0.8rem;"><?= $t['updated_at'] ?></td>
            <td>
                <div class="flex-row">
                    <a href="/tasks/<?= $t['id'] ?>" class="btn btn-outline btn-sm">详情</a>
                    <?php if ($t['status'] === 'failed'): ?>
                    <a href="/tasks/<?= $t['id'] ?>/retry" class="btn btn-primary btn-sm">重试</a>
                    <?php endif; ?>
                    <button onclick="deleteTask(<?= $t['id'] ?>)" class="btn btn-danger btn-sm">删除</button>
                </div>
            </td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>

<script>
async function deleteTask(id) {
    if (!confirm('确定要删除该任务吗？')) return;
    const resp = await fetch('/tasks/' + id, { method: 'DELETE' });
    if (resp.ok) location.reload();
    else alert('删除失败');
}
</script>

<?php $content = ob_get_clean(); require __DIR__ . '/layout.php'; ?>
