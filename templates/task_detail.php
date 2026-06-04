<?php $title = "任务 #{$task['id']}"; $current = 'tasks'; ob_start(); ?>

<h1>📄 任务 #<?= $task['id'] ?></h1>

<div class="flex-row mb-20">
    <span class="badge badge-<?= $task['status'] ?>"><?= $task['status'] ?></span>
    <span class="text-muted">类型: <?= match($task['type']) {
        'sign_only' => '仅签名', 'upload_only' => '仅上传', 'sign_and_upload' => '签名+上传',
        default => $task['type'],
    } ?></span>
    <span class="text-muted">优先级: <?= $task['priority'] ?></span>
    <?php if ($task['status'] === 'failed'): ?>
    <a href="/tasks/<?= $task['id'] ?>/retry" class="btn btn-primary btn-sm">重试</a>
    <?php endif; ?>
    <button onclick="deleteTask(<?= $task['id'] ?>)" class="btn btn-danger btn-sm">删除</button>
</div>

<div class="stats" style="grid-template-columns: 1fr;">
    <div class="stat-card">
        <div class="stat-label">进度</div>
        <div class="progress-bar" style="margin-top: 10px;">
            <div class="progress-fill" style="width: <?= $task['progress'] ?>%"></div>
        </div>
        <div class="stat-value" style="font-size: 3rem;"><?= $task['progress'] ?>%</div>
    </div>
</div>

<div class="card">
    <h2>基本信息</h2>
    <table>
        <tr><td width="140" class="text-muted">任务ID</td><td>#<?= $task['id'] ?></td></tr>
        <tr><td class="text-muted">类型</td><td><?= $task['type'] ?></td></tr>
        <tr><td class="text-muted">状态</td><td><span class="badge badge-<?= $task['status'] ?>"><?= $task['status'] ?></span></td></tr>
        <tr><td class="text-muted">重试次数</td><td><?= $task['retries'] ?>/<?= $task['max_retries'] ?></td></tr>
        <tr><td class="text-muted">输入IPA</td><td class="mono"><?= htmlspecialchars($task['input_ipa']) ?></td></tr>
        <tr><td class="text-muted">输出IPA</td><td class="mono"><?= htmlspecialchars($task['output_ipa'] ?: '-') ?></td></tr>
        <tr><td class="text-muted">创建时间</td><td><?= $task['created_at'] ?></td></tr>
        <tr><td class="text-muted">开始时间</td><td><?= $task['started_at'] ?: '-' ?></td></tr>
        <tr><td class="text-muted">完成时间</td><td><?= $task['finished_at'] ?: '-' ?></td></tr>
    </table>
</div>

<?php if ($task['result']): ?>
<div class="card">
<?php if ($task['output_ipa'] && $task['status'] === 'completed'): ?>
<div class="card" style="border-color: var(--green);">
    <h2 style="color: var(--green);">✅ 签名完成</h2>
    <p>签名好的 IPA 可下载:</p>
    <a href="/download/<?= basename($task['output_ipa']) ?>" class="btn btn-primary">📥 下载签名 IPA</a>
</div>
<?php endif; ?>
    <h2>执行结果</h2>
    <pre style="white-space: pre-wrap; word-break: break-all; font-size: 0.85rem; line-height: 1.5; background: var(--bg); padding: 16px; border-radius: var(--radius);"><?= htmlspecialchars(json_encode(json_decode($task['result']), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
</div>
<?php endif; ?>

<?php if ($task['error']): ?>
<div class="card" style="border-color: var(--red);">
    <h2 style="color: var(--red);">❌ 错误信息</h2>
    <pre style="white-space: pre-wrap; word-break: break-all; color: var(--red); background: var(--bg); padding: 16px; border-radius: var(--radius);"><?= htmlspecialchars($task['error']) ?></pre>
</div>
<?php endif; ?>

<?php if ($task['status'] === 'processing'): ?>
<script>
setTimeout(() => location.reload(), 3000);
</script>
<?php endif; ?>

<script>
async function deleteTask(id) {
    if (!confirm('确定要删除该任务吗？')) return;
    const resp = await fetch('/tasks/' + id, { method: 'DELETE' });
    if (resp.ok) location.href = '/tasks';
    else alert('删除失败');
}
</script>

<?php $content = ob_get_clean(); require __DIR__ . '/layout.php'; ?>
