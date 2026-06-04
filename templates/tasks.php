<?php $title = '任务列表'; $current = 'tasks'; ob_start(); ?>

<h1>📋 任务列表</h1>

<div class="card" style="padding:16px 20px;">
    <form method="GET" action="/tasks" class="flex-row" style="gap:8px;flex-wrap:wrap;">
        <input type="text" name="search" value="<?= htmlspecialchars($filters['search'] ?? '') ?>" placeholder="🔍 搜索任务ID/应用名..." style="width:200px;padding:8px 12px;background:var(--bg);border:1px solid var(--border);border-radius:var(--radius);color:var(--text);">
        <select name="status" style="padding:8px 12px;background:var(--bg);border:1px solid var(--border);border-radius:var(--radius);color:var(--text);">
            <option value="">全部状态</option>
            <option value="pending" <?= ($filters['status'] ?? '') === 'pending' ? 'selected' : '' ?>>待处理</option>
            <option value="processing" <?= ($filters['status'] ?? '') === 'processing' ? 'selected' : '' ?>>处理中</option>
            <option value="completed" <?= ($filters['status'] ?? '') === 'completed' ? 'selected' : '' ?>>已完成</option>
            <option value="failed" <?= ($filters['status'] ?? '') === 'failed' ? 'selected' : '' ?>>失败</option>
        </select>
        <select name="app_id" style="padding:8px 12px;background:var(--bg);border:1px solid var(--border);border-radius:var(--radius);color:var(--text);">
            <option value="">全部应用</option>
            <?php foreach ($apps as $app): ?>
            <option value="<?= $app['id'] ?>" <?= ($filters['app_id'] ?? '') == $app['id'] ? 'selected' : '' ?>><?= htmlspecialchars($app['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <input type="date" name="date_from" value="<?= htmlspecialchars($filters['date_from'] ?? '') ?>" style="padding:8px;background:var(--bg);border:1px solid var(--border);border-radius:var(--radius);color:var(--text);" title="开始日期">
        <input type="date" name="date_to" value="<?= htmlspecialchars($filters['date_to'] ?? '') ?>" style="padding:8px;background:var(--bg);border:1px solid var(--border);border-radius:var(--radius);color:var(--text);" title="结束日期">
        <button type="submit" class="btn btn-primary btn-sm">筛选</button>
        <a href="/tasks" class="btn btn-outline btn-sm">重置</a>
        <a href="/tasks/new" class="btn btn-primary btn-sm" style="margin-left:auto;">➕ 新建任务</a>
        <a href="/ipas" class="btn btn-outline btn-sm">📦 IPA 管理</a>
    </form>
</div>

<div class="text-muted mb-20">共 <?= $total ?> 个任务，第 <?= $page ?>/<?= $total_pages ?> 页</div>

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
                    'github_sign' => 'GitHub签名',
                    default => htmlspecialchars($t['type']),
                } ?>
            </td>
            <td><?= htmlspecialchars($t['app_name'] ?: '-') ?></td>
            <td><span class="badge badge-<?= $t['status'] ?>"><?= $t['status'] ?></span></td>
            <td>
                <div style="width:80px;">
                    <div class="progress-bar"><div class="progress-fill" style="width:<?= $t['progress'] ?>%"></div></div>
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

<!-- Pagination -->
<?php if ($total_pages > 1): ?>
<div class="flex-row" style="justify-content:center;margin-top:20px;gap:4px;">
    <?php if ($page > 1): ?>
    <a href="?<?= http_build_query(array_merge($filters, ['page' => $page - 1])) ?>" class="btn btn-outline btn-sm">◀ 上一页</a>
    <?php endif; ?>
    <?php 
    $start = max(1, $page - 2);
    $end = min($total_pages, $page + 2);
    for ($i = $start; $i <= $end; $i++): 
    ?>
    <a href="?<?= http_build_query(array_merge($filters, ['page' => $i])) ?>" class="btn <?= $i === $page ? 'btn-primary' : 'btn-outline' ?> btn-sm"><?= $i ?></a>
    <?php endfor; ?>
    <?php if ($page < $total_pages): ?>
    <a href="?<?= http_build_query(array_merge($filters, ['page' => $page + 1])) ?>" class="btn btn-outline btn-sm">下一页 ▶</a>
    <?php endif; ?>
</div>
<?php endif; ?>
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
