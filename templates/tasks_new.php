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
            <input type="file" name="ipa_files[]" id="ipaFiles" accept=".ipa" multiple style="display:none;">
            <input type="file" name="ipa_file" id="ipaFileSingle" accept=".ipa">
            <div class="parse-result" id="parseResult" style="margin-top:8px;padding:8px;background:var(--surface2);border-radius:var(--radius);display:none;"></div>
        </div>

        <div style="display:flex;gap:10px;align-items:center;margin-bottom:16px;">
            <label style="display:flex;align-items:center;gap:6px;cursor:pointer;">
                <input type="checkbox" name="batch" value="1" id="batchMode">
                <span>批量模式（一次上传多个 IPA）</span>
            </label>
        </div>

        
        <h2 class="mt-20">📱 版本信息</h2>
        <p class="text-muted">上架 TestFlight 每次必须递增构建号 (Build)，版本号 (Version) 按需修改</p>
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
            <div class="form-group">
                <label>版本号 (Version)</label>
                <input type="text" name="override_version" id="overrideVersion" placeholder="自动从 IPA 读取">
            </div>
            <div class="form-group">
                <label>构建号 (Build) ⭐ 每次上架必须递增</label>
                <input type="text" name="override_build" id="overrideBuild" placeholder="自动从 IPA 读取">
            </div>
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

        <div style="margin-top:16px;padding:12px;background:rgba(34,197,94,0.08);border:1px dashed var(--green);border-radius:var(--radius);">
            <div class="flex-row" style="align-items:center;gap:12px;">
                <button type="button" id="autoFetchBtn" class="btn btn-primary" style="background:var(--green);" onclick="autoFetchCertAndProfile()">
                    🍎 一键获取签名证书 & 描述文件
                </button>
                <span id="autoFetchStatus" class="text-muted" style="font-size:0.85rem;">自动调 Apple API 生成 App Store 分发证书和描述文件</span>
            </div>
            <p class="text-muted" style="margin-top:6px;font-size:0.78rem;">⚠️ 需先在「设置」页配置 App Store Connect API Key，自动生成的是 <b>分发证书 (IOS_DISTRIBUTION)</b> + <b>App Store 描述文件</b></p>
        </div>

        <h2 class="mt-20">🍎 Apple 账号 & 密钥</h2>
        <p class="text-muted">选择在「设置」页面已添加的账号和密钥，或手动输入</p>
        
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
            <div class="form-group">
                <label>🍎 选择预设 Apple 账号 <span class="text-muted">(推荐)</span></label>
                <select name="apple_account_id" id="appleAccountSelect" onchange="onAccountSelect()">
                    <option value="">-- 手动输入或自动读取 --</option>
                    <?php foreach ($appleAccounts as $acct): ?>
                    <option value="<?= $acct['id'] ?>" data-apple-id="<?= htmlspecialchars($acct['apple_id']) ?>" <?= ($acct['status'] ?? 'active') === 'blocked' ? 'disabled style="color:#ef4444;"' : '' ?>>
                        <?= ($acct['status'] ?? 'active') === 'blocked' ? '🚫' : '✅' ?> <?= htmlspecialchars($acct['apple_id']) ?> <?= $acct['note'] ? '(' . htmlspecialchars($acct['note']) . ')' : '' ?> <?= ($acct['status'] ?? 'active') === 'blocked' ? '(已禁用)' : '' ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>🔑 选择预设 API 密钥 <span class="text-muted">(用于自动审核提交)</span></label>
                <select name="api_key_id" id="apiKeySelect">
                    <option value="">-- 不使用 API 密钥 --</option>
                    <?php foreach ($apiKeys as $key): ?>
                    <option value="<?= $key['id'] ?>">
                        <?= htmlspecialchars($key['key_id']) ?> <?= $key['note'] ? '(' . htmlspecialchars($key['note']) . ')' : '' ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;" id="manualAppleFields">
            <div class="form-group">
                <label>Apple ID (邮箱) <span class="text-muted">(手动填写)</span></label>
                <input type="email" name="apple_id" id="appleIdField" placeholder="未选预设账号时填写">
            </div>
            <div class="form-group">
                <label>App 专用密码</label>
                <input type="password" name="app_password" id="applePassField" placeholder="未选预设账号时填写">
            </div>
        </div>

        <button type="submit" class="btn btn-primary" style="margin-top: 16px;">🚀 创建任务</button>
        <span id="uploadStatus" class="text-muted" style="margin-left:12px;"></span>
    </form>
</div>

<script>
// Store detected bundle ID
let detectedBundleId = '';

// Auto-fetch cert and profile from Apple API
async function autoFetchCertAndProfile() {
    const btn = document.getElementById('autoFetchBtn');
    const st = document.getElementById('autoFetchStatus');
    btn.disabled = true;
    
    // Determine bundle ID
    let bundleId = detectedBundleId || '';
    if (!bundleId) {
        const appSel = document.getElementById('appSelect');
        const opt = appSel.options[appSel.selectedIndex];
        bundleId = opt && opt.dataset ? (opt.dataset.bundle || '') : '';
    }
    if (!bundleId) {
        st.textContent = '❌ 请先上传 IPA 文件或选择关联应用以获取 Bundle ID';
        st.style.color = 'var(--red)';
        btn.disabled = false;
        return;
    }
    
    try {
        // Step 1: Create distribution certificate
        st.textContent = '⏳ 正在生成分发证书...';
        st.style.color = 'var(--amber)';
        
        const certResp = await fetch('/api/certs/apple-generate', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({name: 'Auto Dist Cert ' + new Date().toISOString().slice(0,10), type: 'IOS_DISTRIBUTION', email: 'dev@example.com'})
        });
        const certData = await certResp.json();
        if (!certData.success) throw new Error(certData.error || '证书生成失败');
        
        st.textContent = '✅ 证书已生成，正在创建描述文件...';
        
        // Step 2: Create App Store provisioning profile
        const profResp = await fetch('/api/profiles/apple-generate', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({name: 'Auto App Store Profile ' + new Date().toISOString().slice(0,10), bundle_id: bundleId, cert_id: certData.id})
        });
        const profData = await profResp.json();
        if (!profData.success) throw new Error(profData.error || '描述文件生成失败');
        
        st.textContent = '✅ 证书和描述文件已自动生成！刷新选择中...';
        st.style.color = 'var(--green)';
        
        // Step 3: Reload cert and profile lists and auto-select
        const [certsResp, profsResp] = await Promise.all([
            fetch('/api/certs'),
            fetch('/api/profiles')
        ]);
        const certs = await certsResp.json();
        const profs = await profsResp.json();
        
        // Update cert dropdown
        const certSel = document.querySelector('select[name="cert_id"]');
        certSel.innerHTML = '<option value="">-- 选择证书 --</option>' + 
            certs.map(c => `<option value="${c.id}" ${c.id == certData.id ? 'selected' : ''}>${esc(c.name)} (${c.type})</option>`).join('');
        
        // Update profile dropdown
        const profSel = document.querySelector('select[name="profile_id"]');
        profSel.innerHTML = '<option value="">-- 选择描述文件 --</option>' + 
            profs.map(p => `<option value="${p.id}" ${p.id == profData.id ? 'selected' : ''}>${esc(p.name)}</option>`).join('');
        
        st.textContent = '🎉 完成！分发证书和 App Store 描述文件已就绪，证书ID=' + certData.id + ' 描述文件ID=' + profData.id;
        st.style.color = 'var(--green)';
    } catch(err) {
        st.textContent = '❌ ' + (err.message || '操作失败，请确认已配置 API Key');
        st.style.color = 'var(--red)';
    }
    btn.disabled = false;
}

function esc(s) { const d = document.createElement('div'); d.textContent = s || ''; return d.innerHTML; }

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
            div.innerHTML = '<strong>📦 ' + data.name + '</strong> v' + data.version + ' (Build: ' + (data.build || '-') + ') <span class="mono">(' + data.bundle_id + ')</span> | ' + data.file_size;
            document.getElementById('overrideVersion').value = data.version || '';
            document.getElementById('overrideBuild').value = data.build || '';
            detectedBundleId = data.bundle_id || '';
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

// Handle saved account selection - auto-fill credentials
function onAccountSelect() {
    const sel = document.getElementById('appleAccountSelect');
    const opt = sel.options[sel.selectedIndex];
    if (opt && opt.dataset.appleId) {
        document.getElementById('appleIdField').value = opt.dataset.appleId;
        document.getElementById('applePassField').value = '••••••••'; // Mask - will use saved password from DB
        document.getElementById('manualAppleFields').style.opacity = '0.5';
    } else {
        document.getElementById('appleIdField').value = '';
        document.getElementById('applePassField').value = '';
        document.getElementById('manualAppleFields').style.opacity = '1';
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
