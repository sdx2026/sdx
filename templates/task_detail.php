<?php $title = "任务 #{$task['id']}"; $current = 'tasks'; ob_start(); ?>

<h1>📄 任务 #<?= $task['id'] ?></h1>

<div class="flex-row mb-20">
    <span class="badge badge-<?= $task['status'] ?>"><?= $task['status'] ?></span>
    <span class="text-muted">类型: <?= match($task['type']) {'sign_only'=>'仅签名','upload_only'=>'仅上传','sign_and_upload'=>'签名+上传','github_sign'=>'🚀 GitHub', default=>$task['type']} ?></span>
    <?php if ($task['status'] === 'failed'): ?>
    <a href="/tasks/<?= $task['id'] ?>/retry" class="btn btn-primary btn-sm">重试</a>
    <?php endif; ?>
    <button onclick="deleteTask(<?= $task['id'] ?>)" class="btn btn-danger btn-sm">删除</button>
    <?php if ($task['status'] === 'processing'): ?>
    <span class="text-muted" style="margin-left:12px;">🔄 自动刷新中...</span>
    <?php endif; ?>
</div>

<?php if ($task['output_ipa'] && $task['status'] === 'completed'): ?>
<div class="card" style="border-color: var(--green);">
    <h2 style="color: var(--green);">✅ 签名完成 - 分发方式</h2>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-top:12px;">
        <div style="background:var(--surface2);padding:16px;border-radius:var(--radius);">
            <strong>📥 直接下载</strong>
            <p class="text-muted" style="margin:8px 0;">下载 IPA 文件，用其他工具分发</p>
            <a href="/download/<?= basename($task['output_ipa']) ?>" class="btn btn-primary btn-sm">下载 IPA</a>
        </div>
        <div style="background:var(--surface2);padding:16px;border-radius:var(--radius);">
            <strong>📱 OTA 安装</strong>
            <p class="text-muted" style="margin:8px 0;">iPhone 扫码直接安装（需企业证书）</p>
            <code class="mono" style="word-break:break-all;font-size:0.75rem;background:var(--bg);padding:6px;display:block;border-radius:4px;">
                itms-services://?action=download-manifest&url=http://38.246.249.155:8088/ota/install/<?= $task['id'] ?>
            </code>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="stats" style="grid-template-columns: 1fr;">
    <div class="stat-card">
        <div class="stat-label">进度</div>
        <div class="progress-bar" style="margin-top:10px;"><div class="progress-fill" id="progressBar" style="width:<?= $task['progress'] ?>%"></div></div>
        <div class="stat-value" id="progressText" style="font-size:3rem;"><?= $task['progress'] ?>%</div>
    </div>
</div>

<div class="card" id="taskInfo">
    <h2>基本信息</h2>
    <table>
        <tr><td width="140" class="text-muted">任务ID</td><td>#<?= $task['id'] ?></td></tr>
        <tr><td class="text-muted">类型</td><td><?= $task['type'] ?></td></tr>
        <tr><td class="text-muted">状态</td><td><span class="badge badge-<?= $task['status'] ?>" id="taskStatus"><?= $task['status'] ?></span></td></tr>
        <tr><td class="text-muted">重试</td><td><?= $task['retries'] ?>/<?= $task['max_retries'] ?></td></tr>
        <tr><td class="text-muted">输入IPA</td><td class="mono"><?= htmlspecialchars($task['input_ipa']) ?></td></tr>
        <tr><td class="text-muted">输出IPA</td><td class="mono"><?= htmlspecialchars($task['output_ipa'] ?: '-') ?></td></tr>
        <tr><td class="text-muted">创建</td><td><?= $task['created_at'] ?></td></tr>
        <tr><td class="text-muted">开始</td><td><?= $task['started_at'] ?: '-' ?></td></tr>
        <tr><td class="text-muted">完成</td><td><?= $task['finished_at'] ?: '-' ?></td></tr>
    </table>
</div>

<?php if ($task['error']): ?>
<div class="card" style="border-color: var(--red);">
    <h2 style="color: var(--red);">❌ 错误</h2>
    <pre style="white-space:pre-wrap;word-break:break-all;color:var(--red);background:var(--bg);padding:16px;border-radius:var(--radius);"><?= htmlspecialchars($task['error']) ?></pre>
</div>
<?php endif; ?>

<?php if ($task['status'] === 'processing'): ?>
<script>setTimeout(()=>location.reload(),3000);</script>
<?php endif; ?>

<script>
async function deleteTask(id){if(!confirm('确定删除？'))return;await fetch('/tasks/'+id,{method:'DELETE'});location.href='/tasks';}
</script>

<?php $content = ob_get_clean(); require __DIR__ . '/layout.php'; ?>
