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
      <div class="title-row">
        <h1>Status Pigeon</h1>
        <a class="admin-link" href="admin.php">管理</a>
      </div>
      <p class="subtitle">服务器状态监控</p>
      <div id="overall" class="banner banner-loading" hidden>加载中…</div>
    </header>
    <nav id="host-tabs" class="host-tabs" aria-label="按状态筛选" hidden></nav>
    <main id="host-list" class="host-list">
      <p class="empty">正在加载主机数据…</p>
    </main>
  </div>
  <div id="tooltip" class="tooltip" hidden></div>
  <script src="assets/lucide.min.js"></script>
  <script src="assets/status.js"></script>
</body>
</html>
