<?php
require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/auth.php';
statuspigeon_admin_require_page();

$hostId = isset($_POST['host_id']) ? (int) $_POST['host_id'] : (isset($_GET['id']) ? (int) $_GET['id'] : 0);
$hostLocation = 'host.php?id=' . $hostId;
$action = isset($_POST['action']) ? (string) $_POST['action'] : '';
if ($action === 'update_host_category') {
    if (!statuspigeon_admin_csrf_ok()) {
        statuspigeon_admin_redirect('请求校验失败', true, $hostLocation);
    }
    $categoryId = isset($_POST['category_id']) ? (int) $_POST['category_id'] : 0;
    try {
        statuspigeon_set_device_category($pdo, $hostId, $categoryId);
        statuspigeon_admin_redirect('主机分类已更新', false, $hostLocation);
    } catch (Exception $e) {
        statuspigeon_admin_redirect('更新分类失败：' . $e->getMessage(), true, $hostLocation);
    }
}

$flash = isset($_SESSION['statuspigeon_flash']) ? $_SESSION['statuspigeon_flash'] : null;
unset($_SESSION['statuspigeon_flash']);
$csrf = statuspigeon_admin_csrf_token();
$device = $hostId > 0 ? statuspigeon_device($pdo, $hostId) : false;
$categories = $device ? statuspigeon_categories($pdo) : array();
$currentCategoryId = $device && $device['category_id'] !== null ? (int) $device['category_id'] : 0;
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
    <?php if ($flash): ?>
      <div class="admin-message <?php echo !empty($flash['error']) ? 'admin-error' : 'admin-success'; ?>">
        <?php echo statuspigeon_admin_escape($flash['message']); ?>
      </div>
    <?php endif; ?>
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
        <div class="chart-box"><h3>系统负载 (load1)</h3><div id="chart-load" class="chart"></div></div>
        <div class="chart-box"><h3>内存使用率 (%)</h3><div id="chart-mem" class="chart"></div></div>
        <div class="chart-box"><h3>磁盘占用率 (%)</h3><div id="chart-disk" class="chart"></div></div>
      </main>
      <aside class="detail-sidebar">
        <section class="info-card">
          <h2>系统基础信息</h2>
          <dl class="system-info">
            <div class="system-info-row"><dt>操作系统</dt><dd id="host-os">—</dd></div>
            <div class="system-info-row"><dt>系统版本</dt><dd id="host-os-version">—</dd></div>
            <div class="system-info-row"><dt>内核</dt><dd id="host-kernel">—</dd></div>
            <div class="system-info-row"><dt>架构</dt><dd id="host-arch">—</dd></div>
            <div class="system-info-row"><dt>型号</dt><dd id="host-cpu-model">—</dd></div>
            <div class="system-info-row"><dt>内存大小</dt><dd id="host-memory-total">—</dd></div>
            <div class="system-info-row"><dt>磁盘大小</dt><dd id="host-disk-total">—</dd></div>
            <div class="system-info-row"><dt>磁盘占用率</dt><dd id="host-disk-used-pct">—</dd></div>
            <div class="system-info-row"><dt>运行时间</dt><dd id="host-uptime">—</dd></div>
            <div class="system-info-row"><dt>最近接收</dt><dd id="host-last-report">—</dd></div>
            <div class="system-info-row"><dt>IPv4</dt><dd id="host-ipv4">—</dd></div>
            <div class="system-info-row"><dt>IPv6</dt><dd id="host-ipv6">—</dd></div>
          </dl>
          <div class="host-category">
            <h3>主机分类</h3>
            <?php if (!$device): ?>
              <p class="host-meta">主机不存在或已被删除，无法设置分类。</p>
            <?php else: ?>
              <form method="post" action="<?php echo statuspigeon_admin_escape($hostLocation); ?>" class="host-category-form">
                <input type="hidden" name="action" value="update_host_category">
                <input type="hidden" name="csrf" value="<?php echo statuspigeon_admin_escape($csrf); ?>">
                <input type="hidden" name="host_id" value="<?php echo (int) $hostId; ?>">
                <label for="host-category-select">分类</label>
                <select id="host-category-select" name="category_id">
                  <option value="0" <?php echo $currentCategoryId === 0 ? 'selected' : ''; ?>>未分类</option>
                  <?php foreach ($categories as $category): ?>
                    <option value="<?php echo (int) $category['id']; ?>" <?php echo $currentCategoryId === (int) $category['id'] ? 'selected' : ''; ?>><?php echo statuspigeon_admin_escape($category['name']); ?></option>
                  <?php endforeach; ?>
                </select>
                <button type="submit">更新分类</button>
              </form>
            <?php endif; ?>
          </div>
        </section>
      </aside>
    </div>
  </div>
  <div id="chart-tooltip" class="chart-tooltip" hidden></div>
  <script src="assets/host.js"></script>
</body>
</html>
