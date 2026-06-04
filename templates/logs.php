<?php $title = '操作日志'; $current = 'logs'; ob_start(); ?>
<h1>📝 操作日志</h1>
<div class="flex-row mb-20">
    <span class="text-muted">最近 100 条记录</span>
    <button onclick="location.reload()" class="btn btn-outline btn-sm" style="margin-left:auto;">🔄 刷新</button>
</div>
<table>
    <thead><tr><th>ID</th><th>操作</th><th>详情</th><th>IP</th><th>时间</th></tr></thead>
    <tbody>
        <?php foreach ($logs as $log): ?>
        <tr>
            <td class="mono">#<?= $log['id'] ?></td>
            <td><?= htmlspecialchars($log['action']) ?></td>
            <td class="text-muted"><?= htmlspecialchars($log['detail'] ?: '-') ?></td>
            <td class="mono"><?= htmlspecialchars($log['ip'] ?: '-') ?></td>
            <td class="text-muted"><?= $log['created_at'] ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($logs)): ?>
        <tr><td colspan="5" class="empty-state">暂无日志</td></tr>
        <?php endif; ?>
    </tbody>
</table>
<?php $content = ob_get_clean(); require __DIR__ . '/layout.php'; ?>
