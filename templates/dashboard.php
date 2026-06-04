<?php $title = '仪表盘'; $current = 'dashboard'; ob_start(); ?>

<h1>📊 仪表盘</h1>

<div class="stats">
    <div class="stat-card pending">
        <div class="stat-value"><?= $counts['pending'] ?></div>
        <div class="stat-label">待处理任务</div>
    </div>
    <div class="stat-card processing">
        <div class="stat-value"><?= $counts['processing'] ?></div>
        <div class="stat-label">处理中</div>
    </div>
    <div class="stat-card completed">
        <div class="stat-value"><?= $counts['completed'] ?></div>
        <div class="stat-label">已完成</div>
    </div>
    <div class="stat-card failed">
        <div class="stat-value"><?= $counts['failed'] ?></div>
        <div class="stat-label">失败</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?= $appCount ?></div>
        <div class="stat-label">应用数量</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?= $certCount ?></div>
        <div class="stat-label">证书数量</div>
    </div>
</div>

<h2>📋 最近任务</h2>

<?php if (empty($recentTasks)): ?>
<div class="empty-state">
    <p>暂无任务记录</p>
    <a href="/tasks/new" class="btn btn-primary">创建第一个任务</a>
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
            <th>时间</th>
            <th>操作</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($recentTasks as $t): ?>
        <tr>
            <td class="mono">#<?= $t['id'] ?></td>
            <td>
                <?php
                $typeLabels = [
                    'sign_only' => '仅签名',
                    'upload_only' => '仅上传',
                    'sign_and_upload' => '签名+上传',
                ];
                echo $typeLabels[$t['type']] ?? $t['type'];
                ?>
            </td>
            <td><?= htmlspecialchars($t['app_name'] ?: '-') ?></td>
            <td><span class="badge badge-<?= $t['status'] ?>"><?= $t['status'] ?></span></td>
            <td>
                <div class="progress-bar">
                    <div class="progress-fill" style="width: <?= $t['progress'] ?>%"></div>
                </div>
                <span class="text-muted"><?= $t['progress'] ?>%</span>
            </td>
            <td class="text-muted"><?= $t['updated_at'] ?></td>
            <td>
                <a href="/tasks/<?= $t['id'] ?>" class="btn btn-outline btn-sm">详情</a>
            </td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>

<?php $content = ob_get_clean(); require __DIR__ . '/layout.php'; ?>
