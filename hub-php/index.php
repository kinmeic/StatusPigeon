<!DOCTYPE html>
<html lang="zh-CN">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Status Pigeon — 系统状态</title>
  <link rel="stylesheet" href="assets/style.css">
</head>
<body>
  <div class="container">
    <header class="page-head">
      <h1>Status Pigeon</h1>
      <p class="subtitle">服务器状态监控</p>
      <div id="overall" class="banner banner-loading">加载中…</div>
    </header>
    <main id="host-list" class="host-list">
      <p class="empty">正在加载主机数据…</p>
    </main>
    <footer class="page-foot">
      <span id="updated-at"></span>
      <span class="footer-separator"> · </span>
      <a href="admin.php">管理</a>
    </footer>
  </div>
  <div id="tooltip" class="tooltip" hidden></div>
  <script src="assets/status.js"></script>
</body>
</html>
