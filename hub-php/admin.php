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

if (empty($_SESSION['statuspigeon_csrf'])) {
    $_SESSION['statuspigeon_csrf'] = bin2hex(random_bytes(16));
}

function statuspigeon_admin_escape($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function statuspigeon_admin_mask_key($key)
{
    $key = (string) $key;
    $length = strlen($key);
    if ($length <= 12) {
        return str_repeat('*', $length);
    }
    return substr($key, 0, 8) . str_repeat('*', $length - 12) . substr($key, -4);
}

function statuspigeon_admin_redirect($message, $isError, $location)
{
    $_SESSION['statuspigeon_flash'] = array(
        'message' => (string) $message,
        'error' => (bool) $isError,
    );
    header('Location: ' . $location);
    exit;
}

function statuspigeon_admin_csrf_ok()
{
    return isset($_POST['csrf'], $_SESSION['statuspigeon_csrf'])
        && hash_equals((string) $_SESSION['statuspigeon_csrf'], (string) $_POST['csrf']);
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
    @chmod($temporary, 0640);
    if (!@rename($temporary, $path)) {
        @unlink($temporary);
        throw new RuntimeException('无法替换 config.local.php');
    }
    @chmod($path, 0640);
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

$loggedIn = !empty($_SESSION['statuspigeon_admin_logged_in']);
$csrf = (string) $_SESSION['statuspigeon_csrf'];
$section = isset($_GET['section']) ? (string) $_GET['section'] : 'api';
if (!in_array($section, array('api', 'logs', 'password', 'devices'), true)) {
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
          <a class="<?php echo $section === 'password' ? 'active' : ''; ?>" href="admin.php?section=password">密码管理</a>
          <a class="<?php echo $section === 'devices' ? 'active' : ''; ?>" href="admin.php?section=devices">设备管理</a>
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
              <p class="host-meta">删除设备会同时清除其历史数据；该设备下次上报时会作为全新设备重新出现。</p>
              <?php if ($devicesError !== ''): ?>
                <div class="admin-message admin-error"><?php echo statuspigeon_admin_escape($devicesError); ?></div>
              <?php elseif (!$devices): ?>
                <p class="empty">暂无设备。</p>
              <?php else: ?>
                <div class="log-table-wrap">
                  <table class="log-table">
                    <thead>
                      <tr><th>主机</th><th>设备 ID</th><th>来源</th><th>主机状态</th><th>最近接收</th><th>操作</th></tr>
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
                          <td><span class="badge badge-<?php echo statuspigeon_admin_escape(in_array($deviceStatus, array('operational', 'degraded', 'down'), true) ? $deviceStatus : 'no-data'); ?>"><?php echo statuspigeon_admin_escape($deviceStatusLabel); ?></span></td>
                          <td class="log-time" data-timestamp="<?php echo (int) $device['last_seen']; ?>"><?php echo statuspigeon_admin_escape(date('Y-m-d H:i:s', (int) $device['last_seen'])); ?></td>
                          <td>
                            <form method="post" action="admin.php?section=devices" class="inline-form" onsubmit="return confirm('确定删除设备「<?php echo statuspigeon_admin_escape($device['hostname']); ?>」？\n其历史数据将一并删除，且无法恢复。');">
                              <input type="hidden" name="action" value="delete_device">
                              <input type="hidden" name="csrf" value="<?php echo statuspigeon_admin_escape($csrf); ?>">
                              <input type="hidden" name="host_id" value="<?php echo (int) $device['id']; ?>">
                              <input type="hidden" name="hostname" value="<?php echo statuspigeon_admin_escape($device['hostname']); ?>">
                              <button type="submit" class="danger-button device-delete-button">删除</button>
                            </form>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              <?php endif; ?>
            </section>
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
