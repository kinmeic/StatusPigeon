<?php
require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/auth.php';
statuspigeon_admin_require_page();
?>
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
    <div class="detail-layout">
      <main class="detail-main">
        <div class="range-tabs">
          <button data-range="1h">1 小时</button>
          <button data-range="24h" class="active">24 小时</button>
          <button data-range="7d">7 天</button>
        </div>
        <div class="chart-box"><h3>CPU 使用率 (%)</h3><div id="chart-cpu" class="chart"></div></div>
        <div class="chart-box"><h3>内存使用率 (%)</h3><div id="chart-mem" class="chart"></div></div>
        <div class="chart-box"><h3>系统负载 (load1)</h3><div id="chart-load" class="chart"></div></div>
        <div class="chart-box"><h3>磁盘 I/O（bytes/s）</h3><div id="chart-disk" class="chart"></div></div>
        <div class="chart-box"><h3>网络速度（bytes/s）</h3><div id="chart-network" class="chart"></div></div>
      </main>
      <aside class="detail-sidebar">
        <section class="info-card">
          <h2>系统基础信息</h2>
          <dl class="system-info">
            <div class="system-info-row"><dt>操作系统</dt><dd id="host-os">—</dd></div>
            <div class="system-info-row"><dt>内核</dt><dd id="host-kernel">—</dd></div>
            <div class="system-info-row"><dt>架构</dt><dd id="host-arch">—</dd></div>
            <div class="system-info-row"><dt>Agent</dt><dd id="host-agent">—</dd></div>
            <div class="system-info-row"><dt>运行时间</dt><dd id="host-uptime">—</dd></div>
            <div class="system-info-row"><dt>最近接收 report</dt><dd id="host-last-report">—</dd></div>
            <div class="system-info-row"><dt>IPv4</dt><dd id="host-ipv4">—</dd></div>
            <div class="system-info-row"><dt>IPv6</dt><dd id="host-ipv6">—</dd></div>
          </dl>
        </section>
      </aside>
    </div>
  </div>
  <script src="assets/host.js"></script>
</body>
</html>
