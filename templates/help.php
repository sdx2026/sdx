<?php $title = '使用教程'; $current = 'help'; ob_start(); ?>

<style>
.tutorial h2{color:var(--accent);margin-top:30px;padding-bottom:8px;border-bottom:1px solid var(--border);}
.tutorial h3{margin-top:20px;color:var(--text);}
.tutorial .step{background:var(--surface2);border-radius:var(--radius);padding:12px 16px;margin:8px 0;}
.tutorial .step-num{display:inline-block;width:24px;height:24px;background:var(--accent);color:#fff;border-radius:50%;text-align:center;line-height:24px;font-size:0.8rem;margin-right:8px;}
.tutorial code{background:var(--bg);padding:2px 6px;border-radius:4px;font-size:0.85rem;color:var(--green);}
.tutorial .tip{background:rgba(59,130,246,0.1);border-left:3px solid var(--accent);padding:10px 14px;margin:10px 0;border-radius:0 var(--radius) var(--radius) 0;font-size:0.9rem;}
.tutorial .warn{background:rgba(245,158,11,0.1);border-left:3px solid var(--amber);padding:10px 14px;margin:10px 0;border-radius:0 var(--radius) var(--radius) 0;font-size:0.9rem;}
.tutorial table{width:100%;margin:12px 0;}
.tutorial table th{text-align:left;}
</style>

<div class="tutorial">

<h1>📖 使用教程</h1>

<!-- ====== 快速开始 ====== -->
<h2>🚀 快速开始</h2>

<div class="step"><span class="step-num">1</span> 打开 <code>https://bsj.appssign.cc</code>，默认账号 <code>admin</code> 密码 <code>admin123</code></div>
<div class="step"><span class="step-num">2</span> 去「设置」页面配置 Apple 账号和 GitHub Token</div>
<div class="step"><span class="step-num">3</span> 去「应用管理」添加你的 App（Bundle ID 必须和 Xcode 项目一致）</div>
<div class="step"><span class="step-num">4</span> 去「证书管理」导入签名证书</div>
<div class="step"><span class="step-num">5</span> 去「描述文件」上传 .mobileprovision</div>
<div class="step"><span class="step-num">6</span> 去「新建任务」上传 IPA → 填构建号 → 创建 → 等待签名完成</div>

<!-- ====== 证书获取教程 ====== -->
<h2>🔐 如何获取签名证书 (.p12)</h2>

<h3>方式一：Mac 钥匙串导出（推荐）</h3>
<div class="step"><span class="step-num">1</span> Mac 打开「钥匙串访问」→ 菜单栏「证书助理」→「从证书颁发机构请求证书」</div>
<div class="step"><span class="step-num">2</span> 填邮箱，选「存储到磁盘」，保存 <code>CertificateSigningRequest.certSigningRequest</code></div>
<div class="step"><span class="step-num">3</span> 去 <a href="https://developer.apple.com/account/resources/certificates" target="_blank">Apple Developer → Certificates</a> → 点 + → 选「iOS Distribution (App Store Connect)」→ 上传刚才的文件</div>
<div class="step"><span class="step-num">4</span> 下载 .cer 文件，双击安装到钥匙串</div>
<div class="step"><span class="step-num">5</span> 钥匙串中找到该证书 → 右键 → 导出 → 格式选「个人信息交换 (.p12)」→ 设密码 → 保存</div>

<div class="tip">💡 <b>没有 Mac？</b> 可以在设置页配置 API Key，用「证书管理」页的 Apple API 一键自动生成证书。</div>

<h3>方式二：Apple API 自动生成（需付费开发者 $99/年）</h3>
<div class="step"><span class="step-num">1</span> 去 <a href="https://appstoreconnect.apple.com/access/integrations/api" target="_blank">App Store Connect → 用户和访问 → 密钥</a></div>
<div class="step"><span class="step-num">2</span> 生成 API Key，下载 .p8 文件，记录 Issuer ID 和 Key ID</div>
<div class="step"><span class="step-num">3</span> 去系统「设置」页填入三者</div>
<div class="step"><span class="step-num">4</span> 去「证书管理」→ 点「Apple API 生成」按钮 → 自动创建并下载证书</div>

<!-- ====== 描述文件获取 ====== -->
<h2>📄 如何获取描述文件 (.mobileprovision)</h2>

<div class="step"><span class="step-num">1</span> 去 <a href="https://developer.apple.com/account/resources/profiles" target="_blank">Apple Developer → Profiles</a> → 点 +</div>
<div class="step"><span class="step-num">2</span> 类型选「App Store」→ 选对应 App ID → 选证书 → 命名 → 下载</div>
<div class="step"><span class="step-num">3</span> 去系统「描述文件」页面上传 .mobileprovision 文件</div>

<div class="tip">💡 配置了 API Key 后，可在「描述文件」页一键自动创建。</div>

<!-- ====== 导入证书到系统 ====== -->
<h2>📥 如何导入证书到系统</h2>

<div class="step"><span class="step-num">1</span> 打开终端，将 .p12 文件转 base64：<code>base64 -i 证书.p12</code></div>
<div class="step"><span class="step-num">2</span> 复制输出的全部内容</div>
<div class="step"><span class="step-num">3</span> 去系统「证书管理」→「导入证书 (P12)」→ 粘贴 Base64 内容 → 填名称和密码 → 导入</div>

<div class="tip">💡 也可以用 PEM 格式：分别粘贴证书内容和密钥内容。</div>

<!-- ====== 签名上架流程 ====== -->
<h2>✍️ IPA 签名 + TestFlight 上架</h2>

<h3>完整流程</h3>
<div class="step"><span class="step-num">1</span> 确保已完成：添加应用、导入证书、上传描述文件</div>
<div class="step"><span class="step-num">2</span> 去「新建任务」→ 上传 IPA 文件</div>
<div class="step"><span class="step-num">3</span> <b>填写构建号 (Build) ⭐</b>：每次上架必须比上次 +1（如上次 42，这次填 43）</div>
<div class="step"><span class="step-num">4</span> 选择证书和描述文件 → 选 Apple 账号 → 创建任务</div>
<div class="step"><span class="step-num">5</span> Worker 自动用 zsign 签名（Linux 服务器本地签名，无需 Mac）</div>
<div class="step"><span class="step-num">6</span> 签名完成后去 App Store Connect → TestFlight → 找到对应版本 → 提交审核</div>

<h3>两种签名模式</h3>
<table>
<tr><th>模式</th><th>说明</th><th>适用</th></tr>
<tr><td>签名+上传 (本地zsign)</td><td>服务器直接用 zsign 签名，速度快</td><td>✅ 推荐</td></tr>
<tr><td>GitHub Actions</td><td>走 GitHub macOS 虚拟机签名上传，需配置 Token</td><td>备用</td></tr>
<tr><td>仅签名</td><td>只签名不上传，手动去 App Store Connect 上传</td><td>特殊需求</td></tr>
</table>

<div class="warn">⚠️ <b>构建号规则</b>：App Store Connect 要求每次上传的构建号必须递增。比如已经上传过 Build 42，下次必须是 43 或更大。</div>

<!-- ====== 批量功能 ====== -->
<h2>📦 批量操作</h2>

<h3>批量 Apple 账号</h3>
<div class="step"><span class="step-num">1</span> 去「设置」→「Apple 开发者账号（批量管理）」</div>
<div class="step"><span class="step-num">2</span> 支持两种方式：单个添加表单 / 批量导入文本框</div>
<div class="step"><span class="step-num">3</span> 批量导入格式：每行 <code>邮箱,密码,备注</code></div>
<div class="step"><span class="step-num">4</span> 创建任务时会列出所有已添加账号供选择</div>

<h3>批量任务</h3>
<div class="step"><span class="step-num">1</span> 去「新建任务」→ 勾选「批量模式」</div>
<div class="step"><span class="step-num">2</span> 选择多个 IPA 文件一次上传</div>
<div class="step"><span class="step-num">3</span> 系统自动为每个 IPA 创建独立任务</div>

<h3>批量 API 密钥</h3>
<div class="step"><span class="step-num">1</span> 去「设置」→「API 密钥（批量管理）」</div>
<div class="step"><span class="step-num">2</span> 支持多组 Issuer ID + Key ID + .p8</div>
<div class="step"><span class="step-num">3</span> 适用于管理多个 Apple Developer Team</div>

<!-- ====== 用户权限 ====== -->
<h2>👥 用户权限管理</h2>

<div class="tip">💡 管理员（admin）默认拥有全部菜单权限，无需额外设置。</div>

<h3>给普通用户分配权限</h3>
<div class="step"><span class="step-num">1</span> 去「用户管理」→ 填写用户名和密码</div>
<div class="step"><span class="step-num">2</span> 角色选「普通用户」→ 勾选允许访问的菜单</div>
<div class="step"><span class="step-num">3</span> 例如：只勾选「📋 任务列表」和「➕ 新建任务」，用户就只能看到这两个页面</div>
<div class="step"><span class="step-num">4</span> 用户登录后侧边栏自动隐藏无权限菜单</div>

<!-- ====== 通知配置 ====== -->
<h2>📢 通知配置</h2>

<table>
<tr><th>渠道</th><th>配置方式</th></tr>
<tr><td>企业微信</td><td>群设置 → 群机器人 → 添加 → 复制 Webhook 地址 → 填入设置</td></tr>
<tr><td>钉钉</td><td>群设置 → 智能群助手 → 添加机器人 → 复制 Webhook → 填入设置</td></tr>
<tr><td>Telegram</td><td>创建 Bot 获取 Token + 获取 Chat ID → 填入设置</td></tr>
<tr><td>通用 Webhook</td><td>任意支持 Webhook 的平台（Slack 等）→ 填入 URL 和密钥</td></tr>
</table>

<div class="tip">💡 配置后，任务创建/完成/失败时自动推送到对应渠道。</div>

<!-- ====== GitHub Actions ====== -->
<h2>🔧 GitHub Actions 模式</h2>

<div class="step"><span class="step-num">1</span> 去 GitHub → Settings → Developer settings → Personal access tokens → 生成 Token</div>
<div class="step"><span class="step-num">2</span> 勾选 <code>workflow</code> 权限</div>
<div class="step"><span class="step-num">3</span> 去系统「设置」填入 Token</div>
<div class="step"><span class="step-num">4</span> 创建任务时选择「GitHub Actions」模式</div>

<div class="warn">⚠️ GitHub Actions 使用 macOS 虚拟机（免费额度 2000 分钟/月），签名+上传均在 GitHub 执行，结果回调系统。</div>

<!-- ====== OTA 分发 ====== -->
<h2>📲 OTA 分发（用户扫码安装）</h2>

<div class="step"><span class="step-num">1</span> 任务签名完成后，任务详情页会显示 OTA 安装链接</div>
<div class="step"><span class="step-num">2</span> 格式：<code>itms-services://?action=download-manifest&url=https://bsj.appssign.cc/ota/install/任务ID</code></div>
<div class="step"><span class="step-num">3</span> 生成二维码后，用户用 iPhone 扫码即可安装</div>

<div class="warn">⚠️ OTA 分发仅适用于企业证书签名的 IPA，App Store 证书签名的需要通过 TestFlight。</div>

<!-- ====== 统计图表 ====== -->
<h2>📈 统计图表</h2>

<p>「统计图表」页面提供：</p>
<ul style="margin-left:20px;color:var(--text2);">
<li>每日任务趋势（近 30 天柱状图）</li>
<li>任务状态分布（饼图）</li>
<li>各应用任务数排行（条形图）</li>
<li>平均处理时长</li>
</ul>

<!-- ====== IPA 管理 ====== -->
<h2>📦 IPA 文件管理</h2>

<p>「IPA 管理」页面可查看所有已上传的 IPA 文件：</p>
<ul style="margin-left:20px;color:var(--text2);">
<li>查看文件名、大小、上传时间</li>
<li>查看每个 IPA 关联的任务数</li>
<li>删除不再需要的 IPA 释放磁盘空间</li>
</ul>

<!-- ====== 常见问题 ====== -->
<h2>❓ 常见问题</h2>

<p><b>Q: 签名失败怎么办？</b><br>
<span class="text-muted">检查证书是否过期、描述文件是否匹配当前 App ID、IPA 是否完整。可在任务详情查看错误日志。</span></p>

<p><b>Q: TestFlight 上传后看不到构建版本？</b><br>
<span class="text-muted">上传后 Apple 需要几分钟处理。去 App Store Connect → TestFlight → 等待 Processing 完成。如果一直不出来，检查构建号是否递增。</span></p>

<p><b>Q: Worker 显示已停止？</b><br>
<span class="text-muted">SSH 登录服务器执行：<code>cd /www/wwwroot/tfsigner && nohup php worker.php > /dev/null 2>&1 &</code></span></p>

<p><b>Q: 可以不用 Mac 吗？</b><br>
<span class="text-muted">
<h2 id="itms">🍎 App Store Connect 上传错误 (ITMS)</h2>
<p>真正从 Apple 服务器返回的上传错误代码，<b>往往错误原因是什么，一般提示什么就是什么</b>。对照如下：</p>

<h3>签名/证书 (ITMS-900xx)</h3>
<table>
<tr><th>代码</th><th>含义</th><th>最可能原因</th></tr>
<tr><td><code>ITMS-90034</code></td><td>签名证书缺失</td><td>证书未导入或过期</td></tr>
<tr><td><code>ITMS-90035</code></td><td>签名无效</td><td>证书和描述文件不匹配</td></tr>
<tr><td><code>ITMS-90046</code></td><td>二进制未签名</td><td>Frameworks 未签</td></tr>
<tr><td><code>ITMS-90179</code></td><td>代码签名无效</td><td>签名工具链问题</td></tr>
</table>

<h3>IPA/包结构 (ITMS-901xx~902xx)</h3>
<table>
<tr><th>代码</th><th>含义</th><th>最可能原因</th></tr>
<tr><td><code>ITMS-90161</code></td><td>描述文件无效</td><td>已过期或 App ID 不匹配</td></tr>
<tr><td><code>ITMS-90207</code></td><td>IPA 损坏</td><td>文件不完整或格式错误</td></tr>
<tr><td><code>ITMS-90208</code></td><td>无效文件</td><td>包里有多余文件</td></tr>
<tr><td><code>ITMS-90171</code></td><td>Bundle 结构无效</td><td>Payload 目录结构异常</td></tr>
</table>

<h3>版本/构建号 (ITMS-902xx~904xx)</h3>
<table>
<tr><th>代码</th><th>含义</th><th>最可能原因</th></tr>
<tr><td><code>ITMS-90230</code></td><td>构建号格式无效</td><td>CFBundleVersion 非数字</td></tr>
<tr><td><code>ITMS-90283</code></td><td>缺少构建号</td><td>Info.plist 无 CFBundleVersion</td></tr>
<tr><td><code>ITMS-90474</code></td><td>💀 构建号重复</td><td>⚠️ 必须递增！已存在同号版本</td></tr>
<tr><td><code>ITMS-90284</code></td><td>版本号无效</td><td>CFBundleShortVersionString 格式不对</td></tr>
</table>

<h3>图标/资源 (ITMS-90022~907xx)</h3>
<table>
<tr><th>代码</th><th>含义</th><th>最可能原因</th></tr>
<tr><td><code>ITMS-90022</code></td><td>缺 57x57 图标</td><td>CFBundleIconFiles 未配</td></tr>
<tr><td><code>ITMS-90023</code></td><td>缺 512x512 图标</td><td>iTunesArtwork 缺失</td></tr>
<tr><td><code>ITMS-90704</code></td><td>缺 1024x1024 图标</td><td>App Store 必须</td></tr>
<tr><td><code>ITMS-90717</code></td><td>图标尺寸错误</td><td>1024 且不带圆角</td></tr>
</table>

<h3>配置/权限 (ITMS-905xx~906xx)</h3>
<table>
<tr><th>代码</th><th>含义</th><th>最可能原因</th></tr>
<tr><td><code>ITMS-90535</code></td><td>Bundle ID 不匹配</td><td>IPA 内 ID ≠ 描述文件 ID</td></tr>
<tr><td><code>ITMS-90683</code></td><td>缺隐私权限描述</td><td>Info.plist 加 NS***UsageDescription</td></tr>
<tr><td><code>ITMS-90809</code></td><td>用了 UIWebView</td><td>换成 WKWebView</td></tr>
</table>


<p><span class="text-muted">可以！系统已内置 zsign，在 Linux 服务器上直接签名。获取证书需要 Mac 或 Apple API Key。</span></p>



<!-- ====== 错误代码对照表 ====== -->
<h2 id="errors">🚨 错误代码对照表</h2>

<p>任务失败时，详情页会显示错误代码（如 <code style="background:var(--red);color:#fff;padding:1px 6px;border-radius:3px;">E4002</code>）和解决方法。以下是完整对照表：</p>

<h3>证书相关 (1xxx)</h3>
<table>
<tr><th>代码</th><th>错误</th><th>解决</th></tr>
<tr><td><code>E1001</code></td><td>证书文件未找到</td><td>导入证书</td></tr>
<tr><td><code>E1002</code></td><td>证书已过期</td><td>重新生成证书</td></tr>
<tr><td><code>E1003</code></td><td>证书格式无效</td><td>确认 .p12 或 PEM 格式</td></tr>
<tr><td><code>E1004</code></td><td>证书密码错误</td><td>检查 P12 密码</td></tr>
</table>

<h3>描述文件 (2xxx)</h3>
<table>
<tr><th>代码</th><th>错误</th><th>解决</th></tr>
<tr><td><code>E2001</code></td><td>描述文件未找到</td><td>上传 .mobileprovision</td></tr>
<tr><td><code>E2002</code></td><td>描述文件已过期</td><td>重新生成</td></tr>
<tr><td><code>E2004</code></td><td>App ID 不匹配</td><td>Bundle ID 须一致</td></tr>
</table>

<h3>IPA 相关 (3xxx)</h3>
<table>
<tr><th>代码</th><th>错误</th><th>解决</th></tr>
<tr><td><code>E3001</code></td><td>IPA 未找到</td><td>重新上传</td></tr>
<tr><td><code>E3002</code></td><td>IPA 格式无效</td><td>确认 Xcode 导出</td></tr>
<tr><td><code>E3004</code></td><td>无 Payload</td><td>IPA 可能损坏</td></tr>
<tr><td><code>E3005</code></td><td>IPA 损坏</td><td>重新导出</td></tr>
</table>

<h3>签名相关 (4xxx)</h3>
<table>
<tr><th>代码</th><th>错误</th><th>解决</th></tr>
<tr><td><code>E4001</code></td><td>签名工具未找到</td><td>安装 zsign</td></tr>
<tr><td><code>E4002</code></td><td>签名失败</td><td>检查证书+描述文件匹配</td></tr>
<tr><td><code>E4003</code></td><td>签名超时</td><td>IPA 过大或证书问题</td></tr>
</table>

<h3>上传相关 (5xxx)</h3>
<table>
<tr><th>代码</th><th>错误</th><th>解决</th></tr>
<tr><td><code>E5001</code></td><td>上传失败</td><td>检查网络</td></tr>
<tr><td><code>E5002</code></td><td>Apple 认证失败</td><td>生成 App 专用密码</td></tr>
<tr><td><code>E5004</code></td><td>构建号重复</td><td>递增 Build 号</td></tr>
</table>

<h3>系统相关 (9xxx)</h3>
<table>
<tr><th>代码</th><th>错误</th><th>解决</th></tr>
<tr><td><code>E9001</code></td><td>磁盘满</td><td>清理旧文件</td></tr>
<tr><td><code>E9003</code></td><td>Worker 停止</td><td>重启 Worker</td></tr>
<tr><td><code>E9999</code></td><td>未知错误</td><td>联系管理员</td></tr>
</table>

</div>

<?php $content = ob_get_clean(); require __DIR__ . '/layout.php'; ?>
