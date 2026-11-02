<?php
/**
 * Direct management page: /admin.php
 *
 * It is intentionally a real PHP file so it works on a virtual host without
 * URL rewriting.  The first login may use the current API key; after that an
 * independent password can be stored in config.local.php.
 */
require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/auth.php';
statuspigeon_admin_session_start();

function statuspigeon_admin_mask_key($key)
{
    $key = (string) $key;
    $length = strlen($key);
    if ($length <= 12) {
        return str_repeat('*', $length);
    }
    return substr($key, 0, 8) . str_repeat('*', $length - 12) . substr($key, -4);
}

function statuspigeon_admin_local_path()
{
    return __DIR__ . '/config.local.php';
}

function statuspigeon_admin_read_local()
{
    $path = statuspigeon_admin_local_path();
    if (!is_file($path)) {
        return array();
    }
    $value = require $path;
    if (!is_array($value)) {
        throw new RuntimeException('config.local.php must return an array');
    }
    return $value;
}

function statuspigeon_admin_write_local($changes)
{
    if (!is_dir(__DIR__) || !is_writable(__DIR__)) {
        throw new RuntimeException('PHP 对网站目录没有写权限，无法保存配置');
    }
    $path = statuspigeon_admin_local_path();
    if (is_file($path) && !is_writable($path)) {
        throw new RuntimeException('config.local.php 没有写权限');
    }

    $current = statuspigeon_admin_read_local();
    $merged = array_merge($current, $changes);
    $contents = "<?php\nreturn " . var_export($merged, true) . ";\n";
    $temporary = tempnam(__DIR__, '.statuspigeon-config-');
    if ($temporary === false) {
        throw new RuntimeException('无法创建临时配置文件');
    }
    if (file_put_contents($temporary, $contents, LOCK_EX) === false) {
        @unlink($temporary);
        throw new RuntimeException('无法写入临时配置文件');
    }
    // Some shared hosts list chmod in disable_functions; calling it there
    // is a fatal Error on PHP 8+, which the @ operator cannot suppress.
    if (function_exists('chmod')) {
        @chmod($temporary, 0640);
    }
    if (!@rename($temporary, $path)) {
        @unlink($temporary);
        throw new RuntimeException('无法替换 config.local.php');
    }
    if (function_exists('chmod')) {
        @chmod($path, 0640);
    }
}

function statuspigeon_admin_format_bytes($bytes)
{
    $bytes = max(0, (int) $bytes);
    $units = array('B', 'KB', 'MB', 'GB', 'TB');
    $value = (float) $bytes;
    $unit = 0;
    while ($value >= 1024 && $unit < count($units) - 1) {
        $value /= 1024;
        $unit++;
    }
    return number_format($value, $unit === 0 ? 0 : 2) . ' ' . $units[$unit];
}

function statuspigeon_admin_credential_ok($credential, $config)
{
    $credential = (string) $credential;
    $hash = trim((string) $config['admin_password_hash']);
    if ($hash !== '') {
        return password_verify($credential, $hash);
    }
    $apiKey = (string) $config['api_key'];
    return $apiKey !== '' && hash_equals($apiKey, $credential);
}

$flash = isset($_SESSION['statuspigeon_flash']) ? $_SESSION['statuspigeon_flash'] : null;
unset($_SESSION['statuspigeon_flash']);
$action = isset($_POST['action']) ? (string) $_POST['action'] : '';
$returnCandidate = isset($_POST['return']) ? $_POST['return'] : (isset($_GET['return']) ? $_GET['return'] : '');
$returnUrl = statuspigeon_admin_return_url($returnCandidate);
$adminLocation = 'admin.php' . ($returnUrl === 'admin.php' ? '' : '?return=' . rawurlencode($returnUrl));

if ($action === 'login') {
    $credential = isset($_POST['credential']) ? $_POST['credential'] : '';
    $directIp = statuspigeon_admin_direct_ip();
    $auditIp = statuspigeon_admin_audit_ip();
    $now = time();
    $rate = statuspigeon_admin_login_rate_status($pdo, $directIp, $now, $config);
    if ($rate['limited']) {
        // Do not append an audit row for every request during a lockout; that
        // would let an attacker turn the security log into a storage sink.
        session_write_close();
        statuspigeon_admin_rate_limited_response($rate['retry_after'], $returnUrl);
    }

    if (!statuspigeon_admin_csrf_ok()) {
        $failure = statuspigeon_admin_login_record_failure($pdo, $directIp, $now, $config);
        statuspigeon_admin_login_audit(
            $pdo,
            $auditIp,
            $failure['locked'] ? 'locked' : 'csrf',
            $failure['failed_count'],
            $failure['retry_after']
        );
        if ($failure['locked']) {
            session_write_close();
            statuspigeon_admin_login_sleep($failure['delay_ms']);
            statuspigeon_admin_rate_limited_response($failure['retry_after'], $returnUrl);
        }
        $_SESSION['statuspigeon_flash'] = array(
            'message' => '登录页面已过期，请刷新后重试',
            'error' => true,
        );
        session_write_close();
        statuspigeon_admin_login_sleep($failure['delay_ms']);
        header('Location: ' . $adminLocation);
        exit;
    }

    if (!statuspigeon_admin_credential_ok($credential, $config)) {
        $failure = statuspigeon_admin_login_record_failure($pdo, $directIp, $now, $config);
        statuspigeon_admin_login_audit(
            $pdo,
            $auditIp,
            $failure['locked'] ? 'locked' : 'failure',
            $failure['failed_count'],
            $failure['retry_after']
        );
        if ($failure['locked']) {
            session_write_close();
            statuspigeon_admin_login_sleep($failure['delay_ms']);
            statuspigeon_admin_rate_limited_response($failure['retry_after'], $returnUrl);
        }
        $_SESSION['statuspigeon_flash'] = array(
            'message' => '凭据不正确',
            'error' => true,
        );
        session_write_close();
        statuspigeon_admin_login_sleep($failure['delay_ms']);
        header('Location: ' . $adminLocation);
        exit;
    }

    statuspigeon_admin_login_reset($pdo, $directIp);
    statuspigeon_admin_login_audit($pdo, $auditIp, 'success', 0, 0);
    session_regenerate_id(true);
    $_SESSION['statuspigeon_admin_logged_in'] = true;
    statuspigeon_admin_redirect('登录成功', false, $returnUrl);
}

if ($action === 'logout') {
    if (!statuspigeon_admin_csrf_ok()) {
        statuspigeon_admin_redirect('请求校验失败', true, 'admin.php');
    }
    $_SESSION = array();
	if (ini_get('session.use_cookies')) {
		$params = session_get_cookie_params();
		setcookie(session_name(), '', time() - 42000,
			$params['path'], $params['domain'], $params['secure'], $params['httponly']);
	}
    session_destroy();
    header('Location: admin.php');
    exit;
}

$loggedIn = !empty($_SESSION['statuspigeon_admin_logged_in']);
if ($loggedIn && $action === 'generate_api_key') {
    if (!statuspigeon_admin_csrf_ok()) {
        statuspigeon_admin_redirect('请求校验失败', true, 'admin.php');
    }
    try {
        $newKey = bin2hex(random_bytes(32));
        statuspigeon_admin_write_local(array('api_key' => $newKey));
        $_SESSION['statuspigeon_generated_key'] = $newKey;
        statuspigeon_admin_redirect('新的 API key 已保存', false, 'admin.php?section=api');
    } catch (Exception $e) {
        statuspigeon_admin_redirect($e->getMessage(), true, 'admin.php');
    }
}

if ($loggedIn && $action === 'set_admin_password') {
    if (!statuspigeon_admin_csrf_ok()) {
        statuspigeon_admin_redirect('请求校验失败', true, 'admin.php');
    }
    $password = isset($_POST['admin_password']) ? (string) $_POST['admin_password'] : '';
    $confirm = isset($_POST['admin_password_confirm']) ? (string) $_POST['admin_password_confirm'] : '';
    if (strlen($password) < 12) {
        statuspigeon_admin_redirect('管理密码至少需要 12 个字符', true, 'admin.php');
    }
    if ($password !== $confirm) {
        statuspigeon_admin_redirect('两次输入的管理密码不一致', true, 'admin.php');
    }
    $hash = password_hash($password, PASSWORD_DEFAULT);
    if ($hash === false) {
        statuspigeon_admin_redirect('无法生成管理密码 hash', true, 'admin.php');
    }
    try {
        statuspigeon_admin_write_local(array('admin_password_hash' => $hash));
        statuspigeon_admin_redirect('管理密码已更新', false, 'admin.php?section=password');
    } catch (Exception $e) {
        statuspigeon_admin_redirect($e->getMessage(), true, 'admin.php');
    }
}

if ($loggedIn && $action === 'delete_device') {
    if (!statuspigeon_admin_csrf_ok()) {
        statuspigeon_admin_redirect('请求校验失败', true, 'admin.php?section=devices');
    }
    $hostId = isset($_POST['host_id']) ? (int) $_POST['host_id'] : 0;
    $hostname = isset($_POST['hostname']) ? trim((string) $_POST['hostname']) : '';
    try {
        statuspigeon_delete_device($pdo, $hostId);
        statuspigeon_admin_redirect(
            '设备' . ($hostname === '' ? '' : '「' . $hostname . '」') . '及其全部数据已删除',
            false,
            'admin.php?section=devices'
        );
    } catch (Exception $e) {
        statuspigeon_admin_redirect('删除失败：' . $e->getMessage(), true, 'admin.php?section=devices');
    }
}

if ($loggedIn && $action === 'create_category') {
    if (!statuspigeon_admin_csrf_ok()) {
        statuspigeon_admin_redirect('请求校验失败', true, 'admin.php?section=categories');
    }
    try {
        statuspigeon_create_category($pdo, isset($_POST['name']) ? $_POST['name'] : '');
        statuspigeon_admin_redirect('分类已新增', false, 'admin.php?section=categories');
    } catch (Exception $e) {
        statuspigeon_admin_redirect('新增分类失败：' . $e->getMessage(), true, 'admin.php?section=categories');
    }
}

if ($loggedIn && $action === 'update_category') {
    if (!statuspigeon_admin_csrf_ok()) {
        statuspigeon_admin_redirect('请求校验失败', true, 'admin.php?section=categories');
    }
    $categoryId = isset($_POST['category_id']) ? (int) $_POST['category_id'] : 0;
    try {
        statuspigeon_update_category($pdo, $categoryId, isset($_POST['name']) ? $_POST['name'] : '');
        statuspigeon_admin_redirect('分类名称已更新', false, 'admin.php?section=categories');
    } catch (Exception $e) {
        statuspigeon_admin_redirect('编辑分类失败：' . $e->getMessage(), true, 'admin.php?section=categories');
    }
}

if ($loggedIn && $action === 'delete_category') {
    if (!statuspigeon_admin_csrf_ok()) {
        statuspigeon_admin_redirect('请求校验失败', true, 'admin.php?section=categories');
    }
    $categoryId = isset($_POST['category_id']) ? (int) $_POST['category_id'] : 0;
    try {
        statuspigeon_delete_category($pdo, $categoryId);
        statuspigeon_admin_redirect('分类已删除，关联主机已恢复为未分类', false, 'admin.php?section=categories');
    } catch (Exception $e) {
        statuspigeon_admin_redirect('删除分类失败：' . $e->getMessage(), true, 'admin.php?section=categories');
    }
}

if ($loggedIn && $action === 'set_device_enabled') {
    if (!statuspigeon_admin_csrf_ok()) {
        statuspigeon_admin_redirect('请求校验失败', true, 'admin.php?section=devices');
    }
    $hostId = isset($_POST['host_id']) ? (int) $_POST['host_id'] : 0;
    $enabled = isset($_POST['enabled']) ? (string) $_POST['enabled'] : '';
    if (!in_array($enabled, array('0', '1'), true)) {
        statuspigeon_admin_redirect('无效的设备状态', true, 'admin.php?section=devices');
    }
    try {
        statuspigeon_set_device_enabled($pdo, $hostId, $enabled === '1');
        statuspigeon_admin_redirect($enabled === '1' ? '设备已启用' : '设备已禁用，后续上报将被丢弃', false, 'admin.php?section=devices');
    } catch (Exception $e) {
        statuspigeon_admin_redirect('更新设备状态失败：' . $e->getMessage(), true, 'admin.php?section=devices');
    }
}

if ($loggedIn && $action === 'purge_device_reports') {
    if (!statuspigeon_admin_csrf_ok()) {
        statuspigeon_admin_redirect('请求校验失败', true, 'admin.php?section=maintenance');
    }
    $hostId = isset($_POST['host_id']) ? (int) $_POST['host_id'] : 0;
    $hostname = isset($_POST['hostname']) ? trim((string) $_POST['hostname']) : '';
    try {
        $deleted = statuspigeon_purge_device_reports($pdo, $hostId);
        statuspigeon_admin_redirect(
            '已清空设备' . ($hostname === '' ? '' : '「' . $hostname . '」') . '的 ' . $deleted . ' 条上报明细，其他数据均已保留',
            false,
            'admin.php?section=maintenance'
        );
    } catch (Exception $e) {
        statuspigeon_admin_redirect('清空明细失败：' . $e->getMessage(), true, 'admin.php?section=maintenance');
    }
}

if ($loggedIn && $action === 'optimize_database') {
    if (!statuspigeon_admin_csrf_ok()) {
        statuspigeon_admin_redirect('请求校验失败', true, 'admin.php?section=maintenance');
    }
    try {
        $before = statuspigeon_storage_stats($pdo, $config);
        statuspigeon_optimize_database($pdo);
        $after = statuspigeon_storage_stats($pdo, $config);
        statuspigeon_admin_redirect(
            '数据库压缩与优化已完成，大小由 ' . statuspigeon_admin_format_bytes($before['total_bytes'])
                . ' 变为 ' . statuspigeon_admin_format_bytes($after['total_bytes']),
            false,
            'admin.php?section=maintenance'
        );
    } catch (Exception $e) {
        statuspigeon_admin_redirect('数据库优化失败：' . $e->getMessage(), true, 'admin.php?section=maintenance');
    }
}

$loggedIn = !empty($_SESSION['statuspigeon_admin_logged_in']);
$csrf = statuspigeon_admin_csrf_token();
$section = isset($_GET['section']) ? (string) $_GET['section'] : 'api';
if (!in_array($section, array('api', 'logs', 'password', 'devices', 'categories', 'maintenance'), true)) {
    $section = 'api';
}
$generatedKey = isset($_SESSION['statuspigeon_generated_key'])
    ? (string) $_SESSION['statuspigeon_generated_key'] : '';
unset($_SESSION['statuspigeon_generated_key']);
$localPath = statuspigeon_admin_local_path();
$localWritable = is_file($localPath) ? is_writable($localPath) : is_writable(__DIR__);
$basePath = isset($_SERVER['SCRIPT_NAME']) ? dirname((string) $_SERVER['SCRIPT_NAME']) : '';
$basePath = $basePath === '/' || $basePath === '.' ? '' : rtrim($basePath, '/');
$configuredBaseUrl = isset($config['public_base_url']) ? trim((string) $config['public_base_url']) : '';
if ($configuredBaseUrl !== '') {
    $publicBaseUrl = rtrim($configuredBaseUrl, '/');
} else {
	$scheme = statuspigeon_request_is_https() ? 'https' : 'http';
    $host = isset($_SERVER['HTTP_HOST']) ? trim((string) $_SERVER['HTTP_HOST']) : 'localhost';
	if (!preg_match('/^(?:[A-Za-z0-9.-]+|\[[0-9A-Fa-f:.]+\])(?::[0-9]{1,5})?$/', $host)) {
		$host = 'localhost';
	}
    $publicBaseUrl = $scheme . '://' . $host . $basePath;
}
$reportUrl = rtrim($publicBaseUrl, '/') . '/report/';
$currentKey = (string) $config['api_key'];
$adminPasswordConfigured = trim((string) $config['admin_password_hash']) !== '';
$logReports = array();
$logError = '';
$logPageSize = 20;
$logPage = isset($_GET['page']) ? (int) $_GET['page'] : 1;
$logPage = max(1, $logPage);
$logTotal = 0;
$logPageCount = 1;
$loginAudit = array();
$loginAuditError = '';
$loginAuditTotal = 0;
$loginAuditLimit = 50;
if ($loggedIn && $section === 'logs') {
    try {
        $logTotal = statuspigeon_reports_count($pdo);
        $logPageCount = max(1, (int) ceil($logTotal / $logPageSize));
        if ($logPage > $logPageCount) {
            $logPage = $logPageCount;
        }
        $logReports = statuspigeon_recent_reports($pdo, $logPageSize, ($logPage - 1) * $logPageSize);
    } catch (Exception $e) {
        $logError = '日志查询失败';
        error_log('Status Pigeon log query failed: ' . $e->getMessage());
    }
    try {
        $loginAuditTotal = statuspigeon_admin_login_audit_count($pdo);
        $loginAudit = statuspigeon_admin_recent_login_audit($pdo, $loginAuditLimit);
    } catch (Exception $e) {
        $loginAuditError = '登录安全日志查询失败';
        error_log('Status Pigeon login audit query failed: ' . $e->getMessage());
    }
}
$devices = array();
$devicesError = '';
if ($loggedIn && $section === 'devices') {
    try {
        $devices = statuspigeon_devices($pdo);
    } catch (Exception $e) {
        $devicesError = '设备列表查询失败';
        error_log('Status Pigeon device query failed: ' . $e->getMessage());
    }
}
$categories = array();
$categoriesError = '';
$uncategorizedCount = 0;
if ($loggedIn && $section === 'categories') {
    try {
        $categories = statuspigeon_categories($pdo);
        $uncategorizedCount = statuspigeon_uncategorized_count($pdo);
    } catch (Exception $e) {
        $categoriesError = '分类列表查询失败';
        error_log('Status Pigeon category query failed: ' . $e->getMessage());
    }
}
$storageStats = null;
$storageError = '';
if ($loggedIn && $section === 'maintenance') {
    try {
        $storageStats = statuspigeon_storage_stats($pdo, $config);
    } catch (Exception $e) {
        $storageError = '数据库容量统计查询失败';
        error_log('Status Pigeon storage stats query failed: ' . $e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Status Pigeon — 管理</title>
  <link rel="stylesheet" href="assets/style.css">
</head>
<body>
  <div class="container admin-container">
    <div class="admin-nav">
      <a class="back-link" href="index.php">← 返回状态总览</a>
      <?php if ($loggedIn): ?>
        <form method="post" class="inline-form">
          <input type="hidden" name="action" value="logout">
          <input type="hidden" name="csrf" value="<?php echo statuspigeon_admin_escape($csrf); ?>">
          <button type="submit" class="link-button">退出</button>
        </form>
      <?php endif; ?>
    </div>

    <?php if ($flash): ?>
      <div class="admin-message <?php echo !empty($flash['error']) ? 'admin-error' : 'admin-success'; ?>">
        <?php echo statuspigeon_admin_escape($flash['message']); ?>
      </div>
    <?php endif; ?>

    <?php if (!$loggedIn): ?>
      <section class="admin-card">
        <h1>Status Pigeon 管理</h1>
        <p class="host-meta">输入当前 API key 登录。首次登录后可以生成新 API key，并设置独立的管理密码。</p>
        <?php if ($currentKey === '' && !$adminPasswordConfigured): ?>
          <div class="admin-message admin-error">尚未配置 API key 或管理密码，请先编辑 config.php。</div>
        <?php endif; ?>
        <form method="post" class="admin-form">
          <input type="hidden" name="action" value="login">
          <input type="hidden" name="csrf" value="<?php echo statuspigeon_admin_escape($csrf); ?>">
          <input type="hidden" name="return" value="<?php echo statuspigeon_admin_escape($returnUrl); ?>">
          <label for="credential">API key / 管理密码</label>
          <input id="credential" name="credential" type="password" autocomplete="current-password" required autofocus>
          <button type="submit">登录</button>
        </form>
      </section>
    <?php else: ?>
      <header class="admin-head">
        <h1>Status Pigeon 管理</h1>
        <p class="host-meta">完整接收地址：<code class="break-code"><?php echo statuspigeon_admin_escape($reportUrl); ?></code></p>
      </header>

      <?php if (!$localWritable): ?>
        <div class="admin-message admin-error">
          PHP 无法写入当前网站目录，生成的 key 或管理密码无法保存。请给 PHP-FPM 用户配置目录写权限，或手动创建可写的 config.local.php。
        </div>
      <?php endif; ?>

      <div class="admin-layout">
        <nav class="admin-menu" aria-label="管理菜单">
          <a class="<?php echo $section === 'api' ? 'active' : ''; ?>" href="admin.php?section=api">API Key 管理</a>
          <a class="<?php echo $section === 'logs' ? 'active' : ''; ?>" href="admin.php?section=logs">日志查询</a>
          <a class="<?php echo $section === 'devices' ? 'active' : ''; ?>" href="admin.php?section=devices">设备管理</a>
          <a class="<?php echo $section === 'categories' ? 'active' : ''; ?>" href="admin.php?section=categories">分类管理</a>
          <a class="<?php echo $section === 'maintenance' ? 'active' : ''; ?>" href="admin.php?section=maintenance">数据维护</a>
          <a class="<?php echo $section === 'password' ? 'active' : ''; ?>" href="admin.php?section=password">密码管理</a>
        </nav>
        <main class="admin-content">
          <?php if ($section === 'api'): ?>
            <?php if ($generatedKey !== ''): ?>
              <section class="admin-card admin-key-result">
                <h2>新的 API key</h2>
                <p>请立即复制并更新所有 agent；出于安全原因，刷新页面后不会再次显示完整 key。</p>
                <div class="api-key-copy-row">
                  <code id="generated-api-key" class="api-key-value"><?php echo statuspigeon_admin_escape($generatedKey); ?></code>
                  <button type="button" id="copy-api-key" class="copy-button">复制</button>
                </div>
                <p id="copy-api-key-status" class="copy-status" aria-live="polite"></p>
              </section>
            <?php endif; ?>

            <section class="admin-card">
              <h2>API Key 管理</h2>
              <?php if ($currentKey === ''): ?>
                <p>当前 key：<em>未配置</em></p>
              <?php else: ?>
                <p>当前 API key（已脱敏）：</p>
                <div class="api-key-copy-row">
                  <code id="current-api-key" class="api-key-value"><?php echo statuspigeon_admin_escape(statuspigeon_admin_mask_key($currentKey)); ?></code>
                </div>
              <?php endif; ?>
              <p class="host-meta">完整接收地址：<code class="break-code"><?php echo statuspigeon_admin_escape($reportUrl); ?></code></p>
              <div class="api-actions">
                <form method="post" action="admin.php?section=api" onsubmit="return confirm('生成新 API key 后，旧 key 会立即失效。确定继续吗？');">
                  <input type="hidden" name="action" value="generate_api_key">
                  <input type="hidden" name="csrf" value="<?php echo statuspigeon_admin_escape($csrf); ?>">
                  <button type="submit" class="danger-button">生成新 API key</button>
                </form>
                <?php if ($currentKey !== ''): ?>
                  <button type="button" id="copy-current-api-key" class="copy-button" data-copy-value="<?php echo statuspigeon_admin_escape($currentKey); ?>">复制当前 API key</button>
                <?php endif; ?>
              </div>
              <?php if ($currentKey !== ''): ?>
                <p id="copy-current-api-key-status" class="copy-status" aria-live="polite"></p>
              <?php endif; ?>
            </section>
          <?php elseif ($section === 'logs'): ?>
            <section class="admin-card">
              <h2>日志查询</h2>
              <p class="host-meta">共 <?php echo (int) $logTotal; ?> 条已接收 report 的记录，每页 <?php echo (int) $logPageSize; ?> 条。</p>
              <?php if ($logError !== ''): ?>
                <div class="admin-message admin-error"><?php echo statuspigeon_admin_escape($logError); ?></div>
              <?php elseif (!$logReports): ?>
                <p class="empty">暂无接收记录。</p>
              <?php else: ?>
                <div class="log-table-wrap">
                  <table class="log-table">
                    <thead>
                      <tr><th>时间</th><th>主机</th><th>MEM</th><th>主机状态</th></tr>
                    </thead>
                    <tbody>
                      <?php foreach ($logReports as $log): ?>
                        <?php
                        $logStatus = (string) $log['last_status'];
                        $logStatusLabel = $logStatus === 'degraded' ? '性能降级'
                            : ($logStatus === 'operational' ? '运行正常' : ($logStatus ?: '未知'));
                        ?>
                        <tr>
                          <td class="log-time" data-timestamp="<?php echo (int) $log['ts']; ?>"><?php echo statuspigeon_admin_escape(date('Y-m-d H:i:s', (int) $log['ts'])); ?></td>
                          <td><?php echo statuspigeon_admin_escape($log['hostname']); ?></td>
                          <td><?php echo statuspigeon_admin_escape(number_format((float) $log['mem_usage'], 1)); ?>%</td>
                          <td><span class="badge badge-<?php echo statuspigeon_admin_escape($logStatus === 'operational' || $logStatus === 'degraded' ? $logStatus : 'no-data'); ?>"><?php echo statuspigeon_admin_escape($logStatusLabel); ?></span></td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
                <?php if ($logPageCount > 1): ?>
                  <?php
                  $logWindow = 2;
                  $logPages = array(1);
                  for ($i = max(1, $logPage - $logWindow); $i <= min($logPageCount, $logPage + $logWindow); $i++) {
                      $logPages[] = $i;
                  }
                  $logPages[] = $logPageCount;
                  $logPages = array_values(array_unique($logPages));
                  sort($logPages);
                  ?>
                  <nav class="log-pager" aria-label="日志分页">
                    <?php if ($logPage > 1): ?>
                      <a class="log-pager-link" href="admin.php?section=logs&amp;page=<?php echo $logPage - 1; ?>">上一页</a>
                    <?php else: ?>
                      <span class="log-pager-link disabled">上一页</span>
                    <?php endif; ?>
                    <?php $logPrevPage = 0; ?>
                    <?php foreach ($logPages as $logPageNumber): ?>
                      <?php if ($logPrevPage > 0 && $logPageNumber - $logPrevPage > 1): ?>
                        <span class="log-pager-ellipsis">…</span>
                      <?php endif; ?>
                      <?php if ($logPageNumber === $logPage): ?>
                        <span class="log-pager-link current"><?php echo $logPageNumber; ?></span>
                      <?php else: ?>
                        <a class="log-pager-link" href="admin.php?section=logs&amp;page=<?php echo $logPageNumber; ?>"><?php echo $logPageNumber; ?></a>
                      <?php endif; ?>
                      <?php $logPrevPage = $logPageNumber; ?>
                    <?php endforeach; ?>
                    <?php if ($logPage < $logPageCount): ?>
                      <a class="log-pager-link" href="admin.php?section=logs&amp;page=<?php echo $logPage + 1; ?>">下一页</a>
                    <?php else: ?>
                      <span class="log-pager-link disabled">下一页</span>
                    <?php endif; ?>
                  </nav>
                <?php endif; ?>
              <?php endif; ?>
            </section>
            <section class="admin-card">
              <h2>登录安全审计</h2>
              <p class="host-meta">共 <?php echo (int) $loginAuditTotal; ?> 条登录事件，显示最近 <?php echo (int) $loginAuditLimit; ?> 条。不会记录提交的 API key 或管理密码。</p>
              <?php if ($loginAuditError !== ''): ?>
                <div class="admin-message admin-error"><?php echo statuspigeon_admin_escape($loginAuditError); ?></div>
              <?php elseif (!$loginAudit): ?>
                <p class="empty">暂无登录事件。</p>
              <?php else: ?>
                <div class="log-table-wrap">
                  <table class="log-table">
                    <thead>
                      <tr><th>时间</th><th>来源 IP</th><th>结果</th><th>失败次数</th><th>重试等待</th><th>User-Agent</th></tr>
                    </thead>
                    <tbody>
                      <?php foreach ($loginAudit as $event): ?>
                        <?php
                        $eventOutcome = (string) $event['outcome'];
                        $eventLabels = array(
                            'success' => '登录成功',
                            'failure' => '登录失败',
                            'locked' => '触发临时锁定',
                            'blocked' => '锁定中拦截',
                            'csrf' => 'CSRF 校验失败',
                        );
                        $eventClasses = array(
                            'success' => 'operational',
                            'failure' => 'down',
                            'locked' => 'down',
                            'blocked' => 'degraded',
                            'csrf' => 'degraded',
                        );
                        $eventLabel = isset($eventLabels[$eventOutcome]) ? $eventLabels[$eventOutcome] : '未知事件';
                        $eventClass = isset($eventClasses[$eventOutcome]) ? $eventClasses[$eventOutcome] : 'no-data';
                        $eventUserAgent = (string) $event['user_agent'];
                        $eventUserAgentShort = strlen($eventUserAgent) > 48
                            ? substr($eventUserAgent, 0, 45) . '…' : $eventUserAgent;
                        ?>
                        <tr>
                          <td class="log-time" data-timestamp="<?php echo (int) $event['ts']; ?>"><?php echo statuspigeon_admin_escape(date('Y-m-d H:i:s', (int) $event['ts'])); ?></td>
                          <td><?php echo statuspigeon_admin_escape($event['ip']); ?></td>
                          <td><span class="badge badge-<?php echo statuspigeon_admin_escape($eventClass); ?>"><?php echo statuspigeon_admin_escape($eventLabel); ?></span></td>
                          <td><?php echo (int) $event['failed_count']; ?></td>
                          <td><?php echo (int) $event['retry_after']; ?> 秒</td>
                          <td title="<?php echo statuspigeon_admin_escape($eventUserAgent); ?>"><?php echo statuspigeon_admin_escape($eventUserAgentShort); ?></td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              <?php endif; ?>
            </section>
          <?php elseif ($section === 'devices'): ?>
            <section class="admin-card">
              <h2>设备管理</h2>
              <p class="host-meta">禁用设备会丢弃后续上报且不在首页显示，但保留历史数据。删除设备则会同时清除其全部历史数据。</p>
              <?php if ($devicesError !== ''): ?>
                <div class="admin-message admin-error"><?php echo statuspigeon_admin_escape($devicesError); ?></div>
              <?php elseif (!$devices): ?>
                <p class="empty">暂无设备。</p>
              <?php else: ?>
                <div class="log-table-wrap">
                  <table class="log-table">
                    <thead>
                      <tr><th>主机</th><th>设备 ID</th><th>来源</th><th>分类</th><th>启用状态</th><th>主机状态</th><th>最近接收</th><th>操作</th></tr>
                    </thead>
                    <tbody>
                      <?php foreach ($devices as $device): ?>
                        <?php
                        $deviceStatus = (string) $device['last_status'];
                        $deviceStatusLabel = $deviceStatus === 'degraded' ? '性能降级'
                            : ($deviceStatus === 'operational' ? '运行正常'
                            : ($deviceStatus === 'down' ? '离线' : ($deviceStatus ?: '未知')));
                        $deviceId = (string) $device['device_id'];
                        $deviceIdShort = strlen($deviceId) > 20
                            ? substr($deviceId, 0, 12) . '…' . substr($deviceId, -6) : $deviceId;
                        ?>
                        <tr>
                          <td><?php echo statuspigeon_admin_escape($device['hostname']); ?></td>
                          <td><code title="<?php echo statuspigeon_admin_escape($deviceId); ?>"><?php echo statuspigeon_admin_escape($deviceIdShort); ?></code></td>
                          <td><?php echo statuspigeon_admin_escape($device['source']); ?></td>
                          <td><?php echo statuspigeon_admin_escape($device['category_name'] === '' ? '未分类' : $device['category_name']); ?></td>
                          <td><span class="badge <?php echo $device['enabled'] ? 'badge-enabled' : 'badge-disabled'; ?>"><?php echo $device['enabled'] ? '已启用' : '已禁用'; ?></span></td>
                          <td><span class="badge badge-<?php echo statuspigeon_admin_escape(in_array($deviceStatus, array('operational', 'degraded', 'down'), true) ? $deviceStatus : 'no-data'); ?>"><?php echo statuspigeon_admin_escape($deviceStatusLabel); ?></span></td>
                          <td class="log-time" data-timestamp="<?php echo (int) $device['last_seen']; ?>"><?php echo statuspigeon_admin_escape(date('Y-m-d H:i:s', (int) $device['last_seen'])); ?></td>
                          <td>
                            <div class="device-actions">
                              <form method="post" action="admin.php?section=devices" class="inline-form">
                                <input type="hidden" name="action" value="set_device_enabled">
                                <input type="hidden" name="csrf" value="<?php echo statuspigeon_admin_escape($csrf); ?>">
                                <input type="hidden" name="host_id" value="<?php echo (int) $device['id']; ?>">
                                <input type="hidden" name="enabled" value="<?php echo $device['enabled'] ? '0' : '1'; ?>">
                                <button type="submit" class="device-action-button"><?php echo $device['enabled'] ? '禁用' : '启用'; ?></button>
                              </form>
                              <form method="post" action="admin.php?section=devices" class="inline-form" onsubmit="return confirm('确定删除这台设备？\n其全部历史数据将一并删除，且无法恢复。');">
                                <input type="hidden" name="action" value="delete_device">
                                <input type="hidden" name="csrf" value="<?php echo statuspigeon_admin_escape($csrf); ?>">
                                <input type="hidden" name="host_id" value="<?php echo (int) $device['id']; ?>">
                                <input type="hidden" name="hostname" value="<?php echo statuspigeon_admin_escape($device['hostname']); ?>">
                                <button type="submit" class="danger-button device-action-button">删除</button>
                              </form>
                            </div>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              <?php endif; ?>
            </section>
          <?php elseif ($section === 'categories'): ?>
            <section class="admin-card">
              <h2>分类管理</h2>
              <p class="host-meta">分类用于首页标签筛选。“未分类”是内置默认分类，不可编辑或删除；删除自定义分类不会删除主机或历史数据。</p>
              <form method="post" action="admin.php?section=categories" class="admin-form">
                <input type="hidden" name="action" value="create_category">
                <input type="hidden" name="csrf" value="<?php echo statuspigeon_admin_escape($csrf); ?>">
                <label for="new-category-name">新增分类名称</label>
                <div class="category-form-row">
                  <input id="new-category-name" name="name" type="text" maxlength="80" required placeholder="例如：核心路由器">
                  <button type="submit">新增分类</button>
                </div>
              </form>
            </section>
            <section class="admin-card">
              <h2>现有分类</h2>
              <?php if ($categoriesError !== ''): ?>
                <div class="admin-message admin-error"><?php echo statuspigeon_admin_escape($categoriesError); ?></div>
              <?php else: ?>
                <div class="log-table-wrap">
                  <table class="log-table">
                    <thead><tr><th>分类名称</th><th>主机数量</th><th>操作</th></tr></thead>
                    <tbody>
                      <tr>
                        <td><strong>未分类</strong> <span class="category-default">默认</span></td>
                        <td><?php echo (int) $uncategorizedCount; ?></td>
                        <td class="host-meta">内置分类，不可编辑或删除</td>
                      </tr>
                      <?php foreach ($categories as $category): ?>
                        <tr>
                          <td>
                            <form method="post" action="admin.php?section=categories" class="category-edit-form">
                              <input type="hidden" name="action" value="update_category">
                              <input type="hidden" name="csrf" value="<?php echo statuspigeon_admin_escape($csrf); ?>">
                              <input type="hidden" name="category_id" value="<?php echo (int) $category['id']; ?>">
                              <input name="name" type="text" maxlength="80" required aria-label="分类名称" value="<?php echo statuspigeon_admin_escape($category['name']); ?>">
                              <button type="submit" class="device-action-button">保存</button>
                            </form>
                          </td>
                          <td><?php echo (int) $category['host_count']; ?></td>
                          <td>
                            <form method="post" action="admin.php?section=categories" class="inline-form" onsubmit="return confirm('确定删除这个分类？\n关联主机会恢复为未分类，主机及历史数据不受影响。');">
                              <input type="hidden" name="action" value="delete_category">
                              <input type="hidden" name="csrf" value="<?php echo statuspigeon_admin_escape($csrf); ?>">
                              <input type="hidden" name="category_id" value="<?php echo (int) $category['id']; ?>">
                              <button type="submit" class="danger-button device-action-button">删除</button>
                            </form>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              <?php endif; ?>
            </section>
          <?php elseif ($section === 'maintenance'): ?>
            <section class="admin-card">
              <h2>数据维护</h2>
              <p class="host-meta">数据库总大小按主文件和 WAL 文件的实际磁盘占用计算。清空明细不会自动缩小文件，可在清理后执行压缩优化。</p>
              <?php if ($storageError !== ''): ?>
                <div class="admin-message admin-error"><?php echo statuspigeon_admin_escape($storageError); ?></div>
              <?php else: ?>
                <div class="storage-summary">
                  <div class="storage-stat"><span>数据库总大小（含 WAL）</span><strong><?php echo statuspigeon_admin_escape(statuspigeon_admin_format_bytes($storageStats['total_bytes'])); ?></strong></div>
                  <div class="storage-stat"><span>主文件</span><strong><?php echo statuspigeon_admin_escape(statuspigeon_admin_format_bytes($storageStats['main_bytes'])); ?></strong></div>
                  <div class="storage-stat"><span>WAL 文件</span><strong><?php echo statuspigeon_admin_escape(statuspigeon_admin_format_bytes($storageStats['wal_bytes'])); ?></strong></div>
                  <div class="storage-stat"><span>空闲页空间</span><strong><?php echo statuspigeon_admin_escape(statuspigeon_admin_format_bytes($storageStats['free_bytes'])); ?></strong></div>
                </div>
                <p class="host-meta">页大小 <?php echo (int) $storageStats['page_size']; ?> B，共 <?php echo (int) $storageStats['page_count']; ?> 页，其中 <?php echo (int) $storageStats['free_pages']; ?> 个空闲页可由 VACUUM 回收。</p>
                <form method="post" action="admin.php?section=maintenance" onsubmit="return confirm('压缩优化期间可能短暂锁定数据库，并需要额外临时磁盘空间。确定继续吗？');">
                  <input type="hidden" name="action" value="optimize_database">
                  <input type="hidden" name="csrf" value="<?php echo statuspigeon_admin_escape($csrf); ?>">
                  <button type="submit">压缩并优化数据库</button>
                </form>
                <p class="host-meta">强制执行 VACUUM、查询优化和 WAL checkpoint。优化过程中请保持其他管理操作等待完成。</p>
              <?php endif; ?>
            </section>
            <?php if ($storageStats): ?>
              <section class="admin-card">
                <h2>主机上报明细</h2>
                <p class="host-meta">主机占用为按原始明细行及存储字段计算的估算值，不包含日聚合、索引和空闲页。清空操作仅删除 metrics_raw 明细，保留主机、分类、启停状态、最新摘要和每日可用性历史。</p>
                <?php if (!$storageStats['hosts']): ?>
                  <p class="empty">暂无设备。</p>
                <?php else: ?>
                  <div class="log-table-wrap">
                    <table class="log-table">
                      <thead><tr><th>主机</th><th>状态</th><th>明细条数</th><th>估算占用</th><th>操作</th></tr></thead>
                      <tbody>
                        <?php foreach ($storageStats['hosts'] as $storageHost): ?>
                          <tr>
                            <td><?php echo statuspigeon_admin_escape($storageHost['hostname']); ?></td>
                            <td><span class="badge <?php echo $storageHost['enabled'] ? 'badge-enabled' : 'badge-disabled'; ?>"><?php echo $storageHost['enabled'] ? '已启用' : '已禁用'; ?></span></td>
                            <td><?php echo (int) $storageHost['report_count']; ?></td>
                            <td><?php echo statuspigeon_admin_escape(statuspigeon_admin_format_bytes($storageHost['estimated_bytes'])); ?></td>
                            <td>
                              <form method="post" action="admin.php?section=maintenance" class="inline-form" onsubmit="return confirm('确定清空这台主机的全部上报明细？\n此操作无法恢复；其他主机、主机设置和每日可用性历史会保留。');">
                                <input type="hidden" name="action" value="purge_device_reports">
                                <input type="hidden" name="csrf" value="<?php echo statuspigeon_admin_escape($csrf); ?>">
                                <input type="hidden" name="host_id" value="<?php echo (int) $storageHost['id']; ?>">
                                <input type="hidden" name="hostname" value="<?php echo statuspigeon_admin_escape($storageHost['hostname']); ?>">
                                <button type="submit" class="danger-button device-action-button" <?php echo $storageHost['report_count'] > 0 ? '' : 'disabled'; ?>>清空上报明细</button>
                              </form>
                            </td>
                          </tr>
                        <?php endforeach; ?>
                      </tbody>
                    </table>
                  </div>
                <?php endif; ?>
              </section>
            <?php endif; ?>
          <?php else: ?>
            <section class="admin-card">
              <h2>密码管理</h2>
              <p class="host-meta">设置后，下一次登录使用管理密码，不再依赖 API key。</p>
              <form method="post" action="admin.php?section=password" class="admin-form">
                <input type="hidden" name="action" value="set_admin_password">
                <input type="hidden" name="csrf" value="<?php echo statuspigeon_admin_escape($csrf); ?>">
                <label for="admin_password">新管理密码</label>
                <input id="admin_password" name="admin_password" type="password" minlength="12" autocomplete="new-password" required>
                <label for="admin_password_confirm">再次输入</label>
                <input id="admin_password_confirm" name="admin_password_confirm" type="password" minlength="12" autocomplete="new-password" required>
                <button type="submit">保存管理密码</button>
              </form>
            </section>
          <?php endif; ?>
        </main>
      </div>
    <?php endif; ?>
  </div>
  <?php if ($loggedIn): ?>
  <script>
    (function () {
      function fallbackCopy(text, copied, failed) {
        if (navigator.clipboard && window.isSecureContext) {
          navigator.clipboard.writeText(text).then(copied, function () {
            fallbackCopyWithTextarea(text, copied, failed);
          });
          return;
        }
        fallbackCopyWithTextarea(text, copied, failed);
      }

      function fallbackCopyWithTextarea(text, copied, failed) {
        var input = document.createElement('textarea');
        input.value = text;
        input.setAttribute('readonly', '');
        input.style.position = 'fixed';
        input.style.opacity = '0';
        document.body.appendChild(input);
        input.select();
        try {
          if (document.execCommand('copy')) copied();
          else failed();
        } catch (error) {
          failed();
        }
        document.body.removeChild(input);
      }

      function bindCopy(buttonId, valueId, statusId) {
        var button = document.getElementById(buttonId);
        var value = document.getElementById(valueId);
        var status = document.getElementById(statusId);
        if (!button || !value || !status) return;

        button.addEventListener('click', function () {
          var text = button.getAttribute('data-copy-value') || value.textContent || '';
          function copied() {
            status.textContent = '已复制完整 API key';
            button.textContent = '已复制';
            window.setTimeout(function () { button.textContent = '复制'; }, 1800);
          }
          function failed() {
            status.textContent = '复制失败，请手动选择并复制';
          }
          fallbackCopy(text, copied, failed);
        });
      }

      bindCopy('copy-current-api-key', 'current-api-key', 'copy-current-api-key-status');
      bindCopy('copy-api-key', 'generated-api-key', 'copy-api-key-status');

      Array.prototype.forEach.call(document.querySelectorAll('.log-time[data-timestamp]'), function (cell) {
        var timestamp = Number(cell.getAttribute('data-timestamp') || 0);
        if (!timestamp) return;
        var date = new Date(timestamp * 1000);
        if (isNaN(date.getTime())) return;
        try {
          cell.textContent = new Intl.DateTimeFormat('zh-CN', {
            year: 'numeric', month: '2-digit', day: '2-digit',
            hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false
          }).format(date).replace(/\//g, '-');
        } catch (error) {
          cell.textContent = date.toLocaleString();
        }
      });
    }());
  </script>
  <?php endif; ?>
</body>
</html>
