<?php
/** Small session helpers shared by the admin and protected detail pages. */

function statuspigeon_admin_session_start()
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
		@ini_set('session.use_only_cookies', '1');
		@ini_set('session.use_strict_mode', '1');
		@ini_set('session.cookie_httponly', '1');
		@ini_set('session.cookie_samesite', 'Lax');
        session_name('statuspigeon_admin');
		$secure = function_exists('statuspigeon_request_is_https') && statuspigeon_request_is_https();
		// PHP 7.2 has no array-form SameSite option. The path suffix is the
		// compatible fallback; newer runtimes also honor session.cookie_samesite.
		if (PHP_VERSION_ID >= 70300) {
			@session_set_cookie_params(array(
				'lifetime' => 0,
				'path' => '/',
				'domain' => '',
				'secure' => $secure,
				'httponly' => true,
				'samesite' => 'Lax',
			));
		} else {
			@session_set_cookie_params(0, '/; samesite=Lax', '', $secure, true);
		}
		if (!@session_start()) {
			statuspigeon_text_error('unable to start admin session', 500);
		}
		header('Cache-Control: no-store, private');
		header('Pragma: no-cache');
    }
}

function statuspigeon_admin_logged_in()
{
    statuspigeon_admin_session_start();
    return !empty($_SESSION['statuspigeon_admin_logged_in']);
}

/** Allow only a local PHP page as a post-login destination. */
function statuspigeon_admin_return_url($value)
{
    $value = trim((string) $value);
    if ($value === '' || strpos($value, "\n") !== false || strpos($value, "\r") !== false) {
        return 'admin.php';
    }
    if ($value[0] === '/' || preg_match('/^[a-z][a-z0-9+.-]*:/i', $value)) {
        return 'admin.php';
    }
    return $value;
}

function statuspigeon_admin_require_page()
{
    statuspigeon_admin_session_start();
    if (statuspigeon_admin_logged_in()) {
        return;
    }

    $script = isset($_SERVER['SCRIPT_NAME']) ? basename((string) $_SERVER['SCRIPT_NAME']) : 'admin.php';
    $query = isset($_SERVER['QUERY_STRING']) ? trim((string) $_SERVER['QUERY_STRING']) : '';
    $returnUrl = $script . ($query === '' ? '' : '?' . $query);
    header('Location: admin.php?return=' . rawurlencode(statuspigeon_admin_return_url($returnUrl)));
    exit;
}

function statuspigeon_admin_require_api()
{
	header('Cache-Control: no-store, private');
    if (!statuspigeon_admin_logged_in()) {
        statuspigeon_text_error('unauthorized', 401);
    }
}

/**
 * Return bounded admin-login settings. Keep the bounds here even when a
 * deployment overrides config.php so a typo cannot disable the protection or
 * make a single request sleep for an unreasonable amount of time.
 */
function statuspigeon_admin_login_policy($config)
{
    $read = function ($key, $default, $minimum, $maximum) use ($config) {
        $value = isset($config[$key]) ? (int) $config[$key] : (int) $default;
        return min((int) $maximum, max((int) $minimum, $value));
    };
    return array(
        'max_failures' => $read('login_max_failures', 5, 1, 100),
        'window_seconds' => $read('login_window_seconds', 900, 60, 86400),
        'lockout_seconds' => $read('login_lockout_seconds', 900, 60, 604800),
        'delay_base_ms' => $read('login_delay_base_ms', 250, 0, 5000),
        'delay_max_ms' => $read('login_delay_max_ms', 8000, 0, 30000),
    );
}

/** Return the directly connected peer address used for rate limiting. */
function statuspigeon_admin_direct_ip()
{
    $ip = isset($_SERVER['REMOTE_ADDR']) ? trim((string) $_SERVER['REMOTE_ADDR']) : '';
    return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : 'unknown';
}

/** Return the best operator-visible address without trusting it for limits. */
function statuspigeon_admin_audit_ip()
{
    if (function_exists('statuspigeon_request_remote_ip')) {
        $observed = statuspigeon_request_remote_ip();
        if ($observed !== '') {
            return $observed;
        }
    }
    return statuspigeon_admin_direct_ip();
}

function statuspigeon_admin_ip_hash($ip)
{
    return hash('sha256', 'statuspigeon-admin-login:' . (string) $ip);
}

function statuspigeon_admin_login_rate_row($pdo, $ip)
{
    $stmt = $pdo->prepare(
        'SELECT ip, failed_count, window_started_at, last_failed_at, locked_until
         FROM admin_login_rate_limits WHERE ip_hash=:ip_hash LIMIT 1'
    );
    $stmt->execute(array(':ip_hash' => statuspigeon_admin_ip_hash($ip)));
    $row = $stmt->fetch();
    return $row ? $row : null;
}

/**
 * Check whether a source is currently locked without changing its state.
 * The source key is the direct peer address, not a user-controlled proxy
 * header, so X-Forwarded-For cannot be used to evade the limit.
 */
function statuspigeon_admin_login_rate_status($pdo, $ip, $now, $config)
{
    $now = (int) $now;
    $policy = statuspigeon_admin_login_policy($config);
    $row = statuspigeon_admin_login_rate_row($pdo, $ip);
    if (!$row) {
        return array(
            'limited' => false,
            'failed_count' => 0,
            'retry_after' => 0,
        );
    }

    $lockedUntil = (int) $row['locked_until'];
    if ($lockedUntil > $now) {
        return array(
            'limited' => true,
            'failed_count' => (int) $row['failed_count'],
            'retry_after' => max(1, $lockedUntil - $now),
        );
    }

    $windowStarted = (int) $row['window_started_at'];
    if ($windowStarted <= 0 || $now - $windowStarted >= $policy['window_seconds']) {
        return array(
            'limited' => false,
            'failed_count' => 0,
            'retry_after' => 0,
        );
    }

    return array(
        'limited' => false,
        'failed_count' => (int) $row['failed_count'],
        'retry_after' => 0,
    );
}

function statuspigeon_admin_login_delay_ms($failedCount, $policy)
{
    $delay = (int) $policy['delay_base_ms'];
    $maximum = (int) $policy['delay_max_ms'];
    $failedCount = max(1, (int) $failedCount);
    for ($index = 1; $index < $failedCount && $delay < $maximum; $index++) {
        $delay = min($maximum, $delay * 2);
    }
    return max(0, $delay);
}

/** Record one bad credential and atomically update the source lock state. */
function statuspigeon_admin_login_record_failure($pdo, $ip, $now, $config)
{
    $now = (int) $now;
    $policy = statuspigeon_admin_login_policy($config);
    $ip = (string) $ip;
    $ipHash = statuspigeon_admin_ip_hash($ip);

    // BEGIN IMMEDIATE serializes concurrent attempts from the same SQLite
    // database, preventing two workers from both accepting the same count.
    $pdo->exec('BEGIN IMMEDIATE TRANSACTION');
    try {
        $row = statuspigeon_admin_login_rate_row($pdo, $ip);
        if ($row && (int) $row['locked_until'] > $now) {
            $retryAfter = max(1, (int) $row['locked_until'] - $now);
            $pdo->commit();
            return array(
                'failed_count' => (int) $row['failed_count'],
                'delay_ms' => 0,
                'locked' => true,
                'retry_after' => $retryAfter,
            );
        }

        $windowStarted = $row ? (int) $row['window_started_at'] : 0;
        if (!$row || $windowStarted <= 0 || $now - $windowStarted >= $policy['window_seconds']) {
            $failedCount = 1;
            $windowStarted = $now;
        } else {
            $failedCount = (int) $row['failed_count'] + 1;
        }

        $locked = $failedCount >= $policy['max_failures'];
        $lockedUntil = $locked ? $now + $policy['lockout_seconds'] : 0;
        $delayMs = statuspigeon_admin_login_delay_ms($failedCount, $policy);
        if ($row) {
            $update = $pdo->prepare(
                'UPDATE admin_login_rate_limits
                 SET ip=:ip, failed_count=:failed_count, window_started_at=:window_started_at,
                     last_failed_at=:last_failed_at, locked_until=:locked_until
                 WHERE ip_hash=:ip_hash'
            );
            $update->execute(array(
                ':ip' => $ip,
                ':failed_count' => $failedCount,
                ':window_started_at' => $windowStarted,
                ':last_failed_at' => $now,
                ':locked_until' => $lockedUntil,
                ':ip_hash' => $ipHash,
            ));
        } else {
            $insert = $pdo->prepare(
                'INSERT INTO admin_login_rate_limits
                 (ip_hash, ip, failed_count, window_started_at, last_failed_at, locked_until)
                 VALUES (:ip_hash, :ip, :failed_count, :window_started_at, :last_failed_at, :locked_until)'
            );
            $insert->execute(array(
                ':ip_hash' => $ipHash,
                ':ip' => $ip,
                ':failed_count' => $failedCount,
                ':window_started_at' => $windowStarted,
                ':last_failed_at' => $now,
                ':locked_until' => $lockedUntil,
            ));
        }
        $pdo->commit();
        return array(
            'failed_count' => $failedCount,
            'delay_ms' => $delayMs,
            'locked' => $locked,
            'retry_after' => $locked ? $policy['lockout_seconds'] : 0,
        );
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function statuspigeon_admin_login_reset($pdo, $ip)
{
    $stmt = $pdo->prepare('DELETE FROM admin_login_rate_limits WHERE ip_hash=:ip_hash');
    $stmt->execute(array(':ip_hash' => statuspigeon_admin_ip_hash($ip)));
}

/** Store an audit event without ever persisting the submitted credential. */
function statuspigeon_admin_login_audit($pdo, $ip, $outcome, $failedCount, $retryAfter)
{
    $userAgent = isset($_SERVER['HTTP_USER_AGENT'])
        ? (string) $_SERVER['HTTP_USER_AGENT'] : '';
    $userAgent = substr($userAgent, 0, 255);
    $ip = substr((string) $ip, 0, 128);
    $outcome = preg_replace('/[^a-z_]/', '', (string) $outcome);
    if ($outcome === '') {
        $outcome = 'unknown';
    }
    $stmt = $pdo->prepare(
        'INSERT INTO admin_login_audit
         (ts, ip, outcome, failed_count, retry_after, user_agent)
         VALUES (:ts, :ip, :outcome, :failed_count, :retry_after, :user_agent)'
    );
    $stmt->execute(array(
        ':ts' => time(),
        ':ip' => $ip === '' ? 'unknown' : $ip,
        ':outcome' => $outcome,
        ':failed_count' => max(0, (int) $failedCount),
        ':retry_after' => max(0, (int) $retryAfter),
        ':user_agent' => $userAgent,
    ));
}

function statuspigeon_admin_login_audit_count($pdo)
{
    $query = $pdo->query('SELECT COUNT(*) FROM admin_login_audit');
    return (int) $query->fetchColumn();
}

function statuspigeon_admin_recent_login_audit($pdo, $limit)
{
    $limit = max(1, min(500, (int) $limit));
    $query = $pdo->query(
        'SELECT ts, ip, outcome, failed_count, retry_after, user_agent
         FROM admin_login_audit
         ORDER BY ts DESC, id DESC LIMIT ' . $limit
    );
    return $query->fetchAll();
}

function statuspigeon_admin_login_sleep($milliseconds)
{
    $milliseconds = max(0, (int) $milliseconds);
    if ($milliseconds > 0) {
        usleep($milliseconds * 1000);
    }
}

/** Return a browser-readable 429 response instead of hiding it behind a 302. */
function statuspigeon_admin_rate_limited_response($retryAfter, $returnUrl)
{
    $retryAfter = max(1, (int) $retryAfter);
    $returnUrl = statuspigeon_admin_return_url($returnUrl);
    $loginUrl = 'admin.php' . ($returnUrl === 'admin.php'
        ? '' : '?return=' . rawurlencode($returnUrl));
    $safeLoginUrl = htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8');
    http_response_code(429);
    header('Retry-After: ' . $retryAfter);
    header('Cache-Control: no-store, private');
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1.0">';
    echo '<title>Status Pigeon — 请求过于频繁</title></head><body>';
    echo '<main style="max-width:560px;margin:12vh auto;padding:24px;font-family:system-ui,sans-serif;line-height:1.7">';
    echo '<h1>请求过于频繁</h1>';
    echo '<p>登录失败次数过多，当前来源已临时锁定。请在约 ' . $retryAfter . ' 秒后重试。</p>';
    echo '<p><a href="' . $safeLoginUrl . '">返回登录页面</a></p>';
    echo '</main></body></html>';
    exit;
}
