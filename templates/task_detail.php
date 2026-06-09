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
                itms-services://?action=download-manifest&url=<?= \TfSigner\Core\Config::get('app.url', 'https://bsj.appssign.cc') ?>/ota/install/<?= $task['id'] ?>
            </code>
        </div>
    </div>
</div>
<?php endif; ?>

<?php 
// Show result with TestFlight link if available
$resultText = $task['result'] ?? '';
$tfLink = '';
if ($resultText && preg_match('/https:\/\/testflight\.apple\.com\/join\/[A-Za-z0-9]+/', $resultText, $m)) {
    $tfLink = $m[0];
}
?>
<?php if ($tfLink): ?>
<div class="card" style="border-color: var(--accent);background:rgba(59,130,246,0.08);">
    <h2 style="color: var(--accent);">🚀 TestFlight 公开链接</h2>
    <div style="background:var(--surface2);padding:16px;border-radius:var(--radius);margin-top:8px;">
        <p class="text-muted" style="margin-bottom:8px;">审核通过后用户可通过此链接安装测试：</p>
        <a href="<?= htmlspecialchars($tfLink) ?>" target="_blank" style="color:var(--accent);font-size:1.1rem;word-break:break-all;">
            <?= htmlspecialchars($tfLink) ?>
        </a>
        <button onclick="navigator.clipboard.writeText('<?= htmlspecialchars($tfLink) ?>');this.textContent='已复制!';setTimeout(()=>this.textContent='复制',2000)" 
            class="btn btn-primary btn-sm" style="margin-left:12px;">复制</button>
    </div>
    <p style="margin-top:8px;color:var(--amber);font-size:0.85rem;">⏳ Apple 审核通常需要24-48小时，审核通过后链接自动生效</p>
</div>
<?php elseif ($task['status'] === 'completed' && $resultText): ?>
<div class="card" style="border-color: var(--green);">
    <h2 style="color: var(--green);">✅ 结果</h2>
    <pre style="white-space:pre-wrap;word-break:break-all;color:var(--text);margin:8px 0;"><?= htmlspecialchars($resultText) ?></pre>
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
        <?php if (!empty($task['apple_account_id'])): 
            $acct = \TfSigner\Core\Database::connection()->prepare("SELECT apple_id, note FROM apple_accounts WHERE id = ?");
            $acct->execute([$task['apple_account_id']]); $a = $acct->fetch();
        ?>
        <tr><td class="text-muted">Apple 账号</td><td><?= htmlspecialchars($a['apple_id'] ?? '#'.$task['apple_account_id']) ?> <?= $a['note'] ? '(' . htmlspecialchars($a['note']) . ')' : '' ?></td></tr>
        <?php endif; ?>
        <?php if (!empty($task['api_key_id'])): 
            $key = \TfSigner\Core\Database::connection()->prepare("SELECT key_id, note FROM api_keys WHERE id = ?");
            $key->execute([$task['api_key_id']]); $k = $key->fetch();
        ?>
        <tr><td class="text-muted">API 密钥</td><td><?= htmlspecialchars($k['key_id'] ?? '#'.$task['api_key_id']) ?> <?= $k['note'] ? '(' . htmlspecialchars($k['note']) . ')' : '' ?></td></tr>
        <?php endif; ?>
        <?php if (!empty($task['result'])): ?>
        <tr><td class="text-muted">结果</td><td style="color:var(--green);"><?= htmlspecialchars(mb_substr($task['result'], 0, 200)) ?><?= mb_strlen($task['result']) > 200 ? '...' : '' ?></td></tr>
        <?php endif; ?>
    </table>
</div>

<?php if ($task['error']): 
$errText = $task['error']; 
preg_match('/\[E(\d+)\]/', $errText, $codeMatch);
$errCode = $codeMatch[1] ?? '9999';
$hasCode = !empty($codeMatch);
?>
<div class="card" style="border-color: var(--red);">
    <h2 style="color: var(--red);">❌ 错误<?php if($hasCode): ?> <code style="background:var(--red);color:#fff;padding:2px 10px;border-radius:4px;font-size:1rem;">E<?= $errCode ?></code><?php endif; ?></h2>
    <div style="background:rgba(239,68,68,0.08);padding:14px;border-radius:var(--radius);margin-bottom:12px;">
        <pre style="white-space:pre-wrap;word-break:break-all;color:var(--text);margin:0;font-size:0.9rem;"><?= htmlspecialchars($errText) ?></pre>
    </div>
    <div style="margin-top:12px;display:flex;gap:8px;">
        <a href="/tasks/<?= $task['id'] ?>/retry" class="btn btn-primary btn-sm">🔄 重试任务</a>
        <a href="/help" class="btn btn-outline btn-sm">📖 查看错误代码对照表</a>
    </div>
</div>
<?php endif; ?>

<?php if ($task['status'] === 'processing'): ?>
<script>setTimeout(()=>location.reload(),3000);</script>
<?php endif; ?>

<script>
async function deleteTask(id){if(!confirm('确定删除？'))return;await fetch('/tasks/'+id,{method:'DELETE'});location.href='/tasks';}
</script>

<?php $content = ob_get_clean(); require __DIR__ . '/layout.php'; ?>
