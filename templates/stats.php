<?php $title = '统计图表'; $current = 'stats'; ob_start(); ?>

<h1>📈 统计图表</h1>

<div class="stats" id="summaryStats">
    <div class="stat-card pending"><div class="stat-value" id="sPending">-</div><div class="stat-label">待处理</div></div>
    <div class="stat-card processing"><div class="stat-value" id="sProcessing">-</div><div class="stat-label">处理中</div></div>
    <div class="stat-card completed"><div class="stat-value" id="sCompleted">-</div><div class="stat-label">已完成</div></div>
    <div class="stat-card failed"><div class="stat-value" id="sFailed">-</div><div class="stat-label">失败</div></div>
    <div class="stat-card"><div class="stat-value" id="sRate">-</div><div class="stat-label">成功率</div></div>
    <div class="stat-card"><div class="stat-value" id="sTotal">-</div><div class="stat-label">总任务</div></div>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px;">
    <div class="card"><h2>📊 每日任务趋势（近30天）</h2><canvas id="dailyChart" height="200"></canvas></div>
    <div class="card"><h2>🥧 任务状态分布</h2><canvas id="pieChart" height="200"></canvas></div>
    <div class="card"><h2>📱 各应用任务数 Top 10</h2><canvas id="appChart" height="200"></canvas></div>
    <div class="card"><h2>⏱ 平均处理时长（分钟）</h2><div id="avgTime" style="font-size:2rem;text-align:center;padding:40px;">-</div></div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>
<script>
async function loadStats() {
    const resp = await fetch('/api/stats?period=30');
    const data = await resp.json();
    
    document.getElementById('sPending').textContent = data.summary.pending || 0;
    document.getElementById('sProcessing').textContent = data.summary.processing || 0;
    document.getElementById('sCompleted').textContent = data.summary.completed || 0;
    document.getElementById('sFailed').textContent = data.summary.failed || 0;
    document.getElementById('sTotal').textContent = data.summary.total || 0;
    const rate = data.summary.total > 0 ? Math.round(data.summary.completed / data.summary.total * 100) : 0;
    document.getElementById('sRate').textContent = rate + '%';
    document.getElementById('avgTime').textContent = data.avg_time_minutes || '-';
    
    // Daily chart
    new Chart(document.getElementById('dailyChart'), {
        type: 'bar',
        data: {
            labels: data.daily.map(d => d.date),
            datasets: [
                { label: '已完成', data: data.daily.map(d => d.completed), backgroundColor: '#22c55e' },
                { label: '失败', data: data.daily.map(d => d.failed), backgroundColor: '#ef4444' },
            ]
        },
        options: { responsive: true, plugins: { legend: { labels: { color: '#e0e0e0' } } }, scales: { x: { ticks: { color: '#999' } }, y: { ticks: { color: '#999' } } } }
    });
    
    // Pie chart
    new Chart(document.getElementById('pieChart'), {
        type: 'doughnut',
        data: {
            labels: ['已完成', '失败', '待处理', '处理中'],
            datasets: [{ data: [data.summary.completed, data.summary.failed, data.summary.pending, data.summary.processing], backgroundColor: ['#22c55e','#ef4444','#f59e0b','#3b82f6'] }]
        },
        options: { responsive: true, plugins: { legend: { labels: { color: '#e0e0e0' } } } }
    });
    
    // App chart
    const apps = data.by_app || [];
    new Chart(document.getElementById('appChart'), {
        type: 'bar',
        data: {
            labels: apps.map(a => a.name),
            datasets: [{ label: '任务数', data: apps.map(a => a.count), backgroundColor: '#3b82f6' }]
        },
        options: { indexAxis: 'y', responsive: true, plugins: { legend: { labels: { color: '#e0e0e0' } } }, scales: { x: { ticks: { color: '#999' } }, y: { ticks: { color: '#999' } } } }
    });
}
loadStats();
</script>

<?php $content = ob_get_clean(); require __DIR__ . '/layout.php'; ?>
