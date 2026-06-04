<?php $title = '新建任务'; $current = 'tasks_new'; ob_start(); ?>

<h1>➕ 新建签名任务</h1>

<div class="card">
    <form method="POST" action="/tasks" enctype="multipart/form-data" id="taskForm">
        <div class="form-group">
            <label>任务类型</label>
            <select name="type">
                <option value="github_sign">🚀 GitHub Actions 签名 + 上传</option>
                <option value="sign_and_upload">签名 + 上传 (macOS本地)</option>
                <option value="sign_only">仅签名</option>
                <option value="upload_only">仅上传</option>
            </select>
        </div>

        <div class="form-group">
            <label>IPA 文件 *</label>
            <input type="file" name="ipa_file" id="ipaFile" accept=".ipa" required>
            <div class="parse-result" id="parseResult">
                <strong>📦 <span id="parseName"></span></strong>
                <span class="text-muted"> v<span id="parseVersion"></span></span>
                <span class="mono"> (<span id="parseBundle"></span>)</span>
                <span class="text-muted"> | <span id="parseSize"></span></span>
            </div>
        </div>

        <h2 class="mt-20">关联信息</h2>
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
            <div class="form-group">
                <label>关联应用 <span class="text-muted">(根据IPA自动匹配)</span></label>
                <select name="app_id" id="appSelect">
                    <option value="">-- 选择应用 --</option>
                    <?php foreach ($apps as $app): ?>
                    <option value="<?= $app['id'] ?>" data-bundle="<?= htmlspecialchars($app['bundle_id']) ?>">
                        <?= htmlspecialchars($app['name']) ?> (<?= htmlspecialchars($app['bundle_id']) ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>签名证书</label>
                <select name="cert_id" id="certSelect">
                    <option value="">-- 选择证书 --</option>
                    <?php foreach ($certs as $cert): ?>
                    <option value="<?= $cert['id'] ?>"><?= htmlspecialchars($cert['name']) ?> (<?= $cert['type'] ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>描述文件</label>
                <select name="profile_id" id="profileSelect">
                    <option value="">-- 选择描述文件 --</option>
                    <?php foreach ($profiles as $p): ?>
                    <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <h2 class="mt-20">Apple 账号</h2>
        <p class="text-muted">用于上传 App Store Connect（可先在 设置页面 预设）</p>
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
            <div class="form-group">
                <label>Apple ID (邮箱)</label>
                <input type="email" name="apple_id" id="appleIdField" placeholder="your@email.com">
            </div>
            <div class="form-group">
                <label>App 专用密码</label>
                <input type="password" name="app_password" id="applePassField" placeholder="xxxx-xxxx-xxxx-xxxx">
            </div>
        </div>

        <button type="submit" class="btn btn-primary" style="margin-top: 16px;">🚀 创建任务</button>
    </form>
</div>

<script>
// Auto-parse IPA on file select
document.getElementById('ipaFile').addEventListener('change', async function() {
    const file = this.files[0];
    if (!file) return;
    
    const fd = new FormData();
    fd.append('ipa_file', file);
    
    document.getElementById('parseResult').style.display = 'block';
    document.getElementById('parseName').textContent = '解析中...';
    
    try {
        const resp = await fetch('/api/ipa/parse', { method: 'POST', body: fd });
        const data = await resp.json();
        if (data.success) {
            document.getElementById('parseName').textContent = data.name;
            document.getElementById('parseVersion').textContent = data.version;
            document.getElementById('parseBundle').textContent = data.bundle_id;
            document.getElementById('parseSize').textContent = data.file_size;
            
            // Auto-select matching app
            const sel = document.getElementById('appSelect');
            for (let o of sel.options) {
                if (o.dataset.bundle === data.bundle_id) { sel.value = o.value; break; }
            }
        } else {
            document.getElementById('parseName').textContent = '解析失败: ' + (data.error || '未知');
        }
    } catch(e) {
        document.getElementById('parseName').textContent = '网络错误';
    }
});

// Load saved settings (auto-fill Apple ID)
(async function() {
    try {
        const resp = await fetch('/api/settings');
        const data = await resp.json();
        if (data.apple_id) document.getElementById('appleIdField').value = data.apple_id;
        if (data.app_password) document.getElementById('applePassField').value = data.app_password;
    } catch(e) {}
})();
</script>

<?php $content = ob_get_clean(); require __DIR__ . '/layout.php'; ?>
