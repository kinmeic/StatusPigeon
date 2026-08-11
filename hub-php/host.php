<!DOCTYPE html>
<html lang="zh-CN">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Status Pigeon — 主机详情</title>
  <link rel="stylesheet" href="assets/style.css">
</head>
<body>
  <div class="container">
    <a class="back-link" href="index.php">← 返回状态总览</a>
    <header class="detail-head">
      <h1 id="host-title">主机详情</h1>
      <div id="host-badge"></div>
    </header>
    <div class="range-tabs">
      <button data-range="1h">1 小时</button>
      <button data-range="24h" class="active">24 小时</button>
      <button data-range="7d">7 天</button>
    </div>
    <div class="chart-box"><h3>CPU 使用率 (%)</h3><div id="chart-cpu" class="chart"></div></div>
    <div class="chart-box"><h3>内存使用率 (%)</h3><div id="chart-mem" class="chart"></div></div>
    <div class="chart-box"><h3>系统负载 (load1)</h3><div id="chart-load" class="chart"></div></div>
  </div>
  <script src="assets/host.js"></script>
</body>
</html>
