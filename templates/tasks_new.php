<?php $title = '新建任务'; $current = 'tasks_new'; ob_start(); ?>

<h1>➕ 新建签名任务</h1>

<div class="card">
    <form method="POST" action="/tasks" enctype="multipart/form-data">
        <h2>基本信息</h2>
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
            <div class="form-group">
                <label>任务类型</label>
                <select name="type">
                    <option value="sign_and_upload">签名 + 上传</option>
                    <option value="github_sign">🚀 GitHub Actions 签名 + 上传</option>
                    <option value="sign_only">仅签名</option>
                    <option value="upload_only">仅上传</option>
                </select>
            </div>
            <div class="form-group">
                <label>关联应用</label>
                <select name="app_id">
                    <option value="">-- 不关联 --</option>
                    <?php foreach ($apps as $app): ?>
                    <option value="<?= $app['id'] ?>"><?= htmlspecialchars($app['name']) ?> (<?= htmlspecialchars($app['bundle_id']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="form-group">
            <label>IPA 文件 *</label>
            <input type="file" name="ipa_file" accept=".ipa" required>
        </div>

        <h2 class="mt-20">签名配置</h2>
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
            <div class="form-group">
                <label>签名证书</label>
                <select name="cert_id">
                    <option value="">-- 选择证书 --</option>
                    <?php foreach ($certs as $cert): ?>
                    <option value="<?= $cert['id'] ?>"><?= htmlspecialchars($cert['name']) ?> (<?= $cert['type'] ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>描述文件</label>
                <select name="profile_id">
                    <option value="">-- 选择描述文件 --</option>
                    <?php foreach ($profiles as $p): ?>
                    <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?> (<?= $p['profile_type'] ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <h2 class="mt-20">上传配置</h2>
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
            <div class="form-group">
                <label>Apple ID (邮箱)</label>
                <input type="email" name="apple_id" placeholder="your@email.com">
            </div>
            <div class="form-group">
                <label>App 专用密码</label>
                <input type="password" name="app_password" placeholder="App-Specific Password">
            </div>
        </div>

        <button type="submit" class="btn btn-primary" style="margin-top: 16px;">🚀 创建并执行任务</button>
    </form>
</div>

<?php $content = ob_get_clean(); require __DIR__ . '/layout.php'; ?>
