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
    if (!statuspigeon_admin_csrf_ok()) {
        statuspigeon_admin_redirect('登录页面已过期，请重试', true, $adminLocation);
    }
    $credential = isset($_POST['credential']) ? $_POST['credential'] : '';
    if (!statuspigeon_admin_credential_ok($credential, $config)) {
        statuspigeon_admin_redirect('凭据不正确', true, $adminLocation);
    }
    session_regenerate_id(true);
    $_SESSION['statuspigeon_admin_logged_in'] = true;
    statuspigeon_admin_redirect('登录成功', false, $returnUrl);
}

if ($action === 'logout') {
    if (!statuspigeon_admin_csrf_ok()) {
        statuspigeon_admin_redirect('请求校验失败', true, 'admin.php');
    }
    $_SESSION = array();
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

$loggedIn = !empty($_SESSION['statuspigeon_admin_logged_in']);
$csrf = (string) $_SESSION['statuspigeon_csrf'];
$section = isset($_GET['section']) ? (string) $_GET['section'] : 'api';
if (!in_array($section, array('api', 'logs', 'password'), true)) {
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
    $scheme = 'https';
    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
        $forwardedProto = trim(explode(',', (string) $_SERVER['HTTP_X_FORWARDED_PROTO'])[0]);
        if (preg_match('/^https?$/i', $forwardedProto)) {
            $scheme = strtolower($forwardedProto);
        }
    } elseif (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        $scheme = 'https';
    } elseif (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 80) {
        $scheme = 'http';
    }
    $host = isset($_SERVER['HTTP_HOST']) ? trim((string) $_SERVER['HTTP_HOST']) : 'localhost';
    $publicBaseUrl = $scheme . '://' . $host . $basePath;
}
$reportUrl = rtrim($publicBaseUrl, '/') . '/report/';
$currentKey = (string) $config['api_key'];
$adminPasswordConfigured = trim((string) $config['admin_password_hash']) !== '';
$logReports = array();
$logError = '';
if ($loggedIn && $section === 'logs') {
    try {
        $logReports = statuspigeon_recent_reports($pdo, 100);
    } catch (Exception $e) {
        $logError = '日志查询失败';
        error_log('Status Pigeon log query failed: ' . $e->getMessage());
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
              <p>当前 key：<?php echo $currentKey === '' ? '<em>未配置</em>' : '<code>' . statuspigeon_admin_escape(substr($currentKey, 0, 6)) . '••••••••</code>'; ?></p>
              <p class="host-meta">完整接收地址：<code class="break-code"><?php echo statuspigeon_admin_escape($reportUrl); ?></code></p>
              <form method="post" action="admin.php?section=api" onsubmit="return confirm('生成新 API key 后，旧 key 会立即失效。确定继续吗？');">
                <input type="hidden" name="action" value="generate_api_key">
                <input type="hidden" name="csrf" value="<?php echo statuspigeon_admin_escape($csrf); ?>">
                <button type="submit" class="danger-button">生成新 API key</button>
              </form>
            </section>
          <?php elseif ($section === 'logs'): ?>
            <section class="admin-card">
              <h2>日志查询</h2>
              <p class="host-meta">显示最近 100 条已接收 report 的记录。</p>
              <?php if ($logError !== ''): ?>
                <div class="admin-message admin-error"><?php echo statuspigeon_admin_escape($logError); ?></div>
              <?php elseif (!$logReports): ?>
                <p class="empty">暂无接收记录。</p>
              <?php else: ?>
                <div class="log-table-wrap">
                  <table class="log-table">
                    <thead>
                      <tr><th>时间</th><th>主机</th><th>CPU</th><th>MEM</th><th>主机状态</th></tr>
                    </thead>
                    <tbody>
                      <?php foreach ($logReports as $log): ?>
                        <?php
                        $logStatus = (string) $log['last_status'];
                        $logStatusLabel = $logStatus === 'degraded' ? '性能降级'
                            : ($logStatus === 'operational' ? '运行正常' : ($logStatus ?: '未知'));
                        ?>
                        <tr>
                          <td><?php echo statuspigeon_admin_escape(date('Y-m-d H:i:s', (int) $log['ts'])); ?></td>
                          <td><?php echo statuspigeon_admin_escape($log['hostname']); ?></td>
                          <td><?php echo statuspigeon_admin_escape(number_format((float) $log['cpu_usage'], 1)); ?>%</td>
                          <td><?php echo statuspigeon_admin_escape(number_format((float) $log['mem_usage'], 1)); ?>%</td>
                          <td><span class="badge badge-<?php echo statuspigeon_admin_escape($logStatus === 'operational' || $logStatus === 'degraded' ? $logStatus : 'no-data'); ?>"><?php echo statuspigeon_admin_escape($logStatusLabel); ?></span></td>
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
  <?php if ($generatedKey !== ''): ?>
  <script>
    (function () {
      var button = document.getElementById('copy-api-key');
      var value = document.getElementById('generated-api-key');
      var status = document.getElementById('copy-api-key-status');
      if (!button || !value || !status) return;

      function copied() {
        status.textContent = '已复制完整 API key';
        button.textContent = '已复制';
        window.setTimeout(function () { button.textContent = '复制'; }, 1800);
      }

      function failed() {
        status.textContent = '复制失败，请手动选择并复制';
      }

      button.addEventListener('click', function () {
        var text = value.textContent || '';
        if (navigator.clipboard && window.isSecureContext) {
          navigator.clipboard.writeText(text).then(copied, failed);
          return;
        }
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
      });
    }());
  </script>
  <?php endif; ?>
</body>
</html>
