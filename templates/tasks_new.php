<?php $title = '新建任务'; $current = 'tasks_new'; ob_start(); ?>

<h1>➕ 新建签名任务</h1>

<div class="card">
    <form method="POST" action="/tasks" enctype="multipart/form-data" id="taskForm">
        <div class="form-group">
            <label>任务类型</label>
            <select name="type">
                <option value="sign_and_upload">签名 + 上传 (本地zsign)</option>
                <option value="github_sign">🚀 GitHub Actions 签名 + 上传 (macOS)</option>
                <option value="sign_only">仅签名</option>
                <option value="upload_only">仅上传</option>
            </select>
        </div>

        <div class="form-group">
            <label>IPA 文件 * <span class="text-muted">(可选多个文件批量上传)</span></label>
            <input type="file" name="ipa_files[]" id="ipaFiles" accept=".ipa" multiple>
            <input type="file" name="ipa_file" id="ipaFileSingle" accept=".ipa" style="display:none;">
            <div class="parse-result" id="parseResult" style="margin-top:8px;padding:8px;background:var(--surface2);border-radius:var(--radius);display:none;"></div>
        </div>

        <div style="display:flex;gap:10px;align-items:center;margin-bottom:16px;">
            <label style="display:flex;align-items:center;gap:6px;cursor:pointer;">
                <input type="checkbox" name="batch" value="1" id="batchMode">
                <span>批量模式（一次上传多个 IPA）</span>
            </label>
        </div>

        <h2 class="mt-20">关联信息</h2>
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
            <div class="form-group">
                <label>关联应用 <span class="text-muted">(自动根据Bundle ID匹配)</span></label>
                <select name="app_id" id="appSelect">
                    <option value="">-- 自动匹配 --</option>
                    <?php foreach ($apps as $app): ?>
                    <option value="<?= $app['id'] ?>" data-bundle="<?= htmlspecialchars($app['bundle_id']) ?>">
                        <?= htmlspecialchars($app['name']) ?> (<?= htmlspecialchars($app['bundle_id']) ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
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
        <span id="uploadStatus" class="text-muted" style="margin-left:12px;"></span>
    </form>
</div>

<script>
// Toggle batch/normal mode
document.getElementById('batchMode').addEventListener('change', function() {
    if (this.checked) {
        document.getElementById('ipaFiles').style.display = 'block';
        document.getElementById('ipaFileSingle').style.display = 'none';
        document.getElementById('ipaFiles').setAttribute('required', 'required');
        document.getElementById('ipaFileSingle').removeAttribute('required');
    } else {
        document.getElementById('ipaFiles').style.display = 'none';
        document.getElementById('ipaFileSingle').style.display = 'block';
        document.getElementById('ipaFileSingle').setAttribute('required', 'required');
        document.getElementById('ipaFiles').removeAttribute('required');
    }
});

// Auto-parse IPA on file select
document.getElementById('ipaFileSingle').addEventListener('change', parseFile);
document.getElementById('ipaFiles').addEventListener('change', async function() {
    const files = this.files;
    const div = document.getElementById('parseResult');
    div.style.display = 'block';
    let html = '<strong>📦 已选择 ' + files.length + ' 个文件:</strong><br>';
    for (const f of files) {
        html += '<span style="color:var(--text2);">• ' + f.name + ' (' + (f.size/1024/1024).toFixed(1) + ' MB)</span><br>';
    }
    div.innerHTML = html;
});

async function parseFile() {
    const file = this.files[0];
    if (!file) return;
    const fd = new FormData();
    fd.append('ipa_file', file);
    const div = document.getElementById('parseResult');
    div.style.display = 'block';
    div.innerHTML = '<strong>📦 解析中...</strong>';
    try {
        const resp = await fetch('/api/ipa/parse', { method: 'POST', body: fd });
        const data = await resp.json();
        if (data.success) {
            div.innerHTML = '<strong>📦 ' + data.name + '</strong> v' + data.version + ' <span class="mono">(' + data.bundle_id + ')</span> | ' + data.file_size;
            const sel = document.getElementById('appSelect');
            for (let o of sel.options) {
                if (o.dataset.bundle === data.bundle_id) { sel.value = o.value; break; }
            }
        } else {
            div.innerHTML = '<strong>❌ 解析失败:</strong> ' + (data.error || '未知');
        }
    } catch(e) {
        div.innerHTML = '<strong>❌ 网络错误</strong>';
    }
}

// Load saved settings
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
