<?php
/** Shared bootstrap for every PHP endpoint. PHP 7.2 compatible. */

if (defined('STATUSPIGEON_BOOTSTRAPPED')) {
    return;
}
define('STATUSPIGEON_BOOTSTRAPPED', true);

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');

$config = array(
    'api_key' => '',
	'allow_unauthenticated_reports' => false,
    'admin_password_hash' => '',
    'public_base_url' => '',
    'db_path' => dirname(__DIR__) . '/../statuspigeon-data/statuspigeon.sqlite',
    // The Hub displays report timestamps in the operator's local timezone.
    // Deployments can still override this value in config.php or with
    // STATUSPIGEON_TIMEZONE.
    'timezone' => 'Asia/Shanghai',
    'retention_days' => 90,
    'uptime_bar_days' => 60,
    // Deprecated compatibility setting; CPU percentage is no longer used.
    'degraded_cpu' => 90.0,
    'degraded_mem' => 95.0,
    'report_interval' => 300,
    'offline_periods' => 3,
    'max_report_bytes' => 1048576,
    // Admin login protection: five failures in 15 minutes trigger a 15-minute
    // per-source lockout. Failed attempts also use capped exponential delay.
    'login_max_failures' => 5,
    'login_window_seconds' => 900,
    'login_lockout_seconds' => 900,
    'login_delay_base_ms' => 250,
    'login_delay_max_ms' => 8000,
    'login_audit_retention_days' => 90,
);

$configFile = dirname(__DIR__) . '/config.php';
if (is_file($configFile)) {
    $localConfig = require $configFile;
    if (is_array($localConfig)) {
        $config = array_merge($config, $localConfig);
    }
}

// The admin page writes only overrides here, leaving the deployment-supplied
// config.php intact.  This also lets shared hosting persist a generated key
// without requiring a shell or a database migration.
$localConfigFile = dirname(__DIR__) . '/config.local.php';
if (is_file($localConfigFile)) {
    $localConfig = require $localConfigFile;
    if (is_array($localConfig)) {
        $config = array_merge($config, $localConfig);
    }
}

$environment = array(
    'api_key' => 'STATUSPIGEON_API_KEY',
    'db_path' => 'STATUSPIGEON_DB_PATH',
    'timezone' => 'STATUSPIGEON_TIMEZONE',
);
foreach ($environment as $key => $name) {
    $value = getenv($name);
    if ($value !== false && $value !== '') {
        $config[$key] = $value;
    }
}
$allowUnauthenticated = getenv('STATUSPIGEON_ALLOW_UNAUTHENTICATED_REPORTS');
if ($allowUnauthenticated !== false && $allowUnauthenticated !== '') {
	$config['allow_unauthenticated_reports'] = filter_var(
		$allowUnauthenticated,
		FILTER_VALIDATE_BOOLEAN
	);
}

if (!empty($config['timezone'])) {
    @date_default_timezone_set((string) $config['timezone']);
}

require_once __DIR__ . '/database.php';

if (!class_exists('PDO') || !in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Status Pigeon requires the PHP PDO SQLite extension.';
    exit;
}

try {
    $pdo = statuspigeon_db($config);
    statuspigeon_maintenance($pdo, $config);
} catch (Exception $e) {
    error_log('Status Pigeon bootstrap failed: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Status Pigeon storage is unavailable.';
    exit;
}

function statuspigeon_json_response($value, $statusCode)
{
    http_response_code((int) $statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo statuspigeon_json_encode($value);
    exit;
}

function statuspigeon_text_error($message, $statusCode)
{
    http_response_code((int) $statusCode);
    header('Content-Type: text/plain; charset=utf-8');
    echo $message;
    exit;
}

function statuspigeon_require_get()
{
    if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] !== 'GET') {
        statuspigeon_text_error('method not allowed', 405);
    }
}

function statuspigeon_request_key()
{
    $authorization = isset($_SERVER['HTTP_AUTHORIZATION'])
        ? trim((string) $_SERVER['HTTP_AUTHORIZATION']) : '';
    // Apache mod_rewrite/CGI setups can surface the stripped header under a
    // REDIRECT_ prefix or only via getallheaders().
    if ($authorization === '' && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        $authorization = trim((string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
    }
    if ($authorization === '' && function_exists('getallheaders')) {
        foreach ((array) getallheaders() as $name => $value) {
            if (strcasecmp((string) $name, 'Authorization') === 0) {
                $authorization = trim((string) $value);
                break;
            }
        }
    }
    if (stripos($authorization, 'Bearer ') === 0) {
        return trim(substr($authorization, 7));
    }
    if (isset($_SERVER['HTTP_X_API_KEY'])) {
        return trim((string) $_SERVER['HTTP_X_API_KEY']);
    }
    return '';
}

function statuspigeon_require_api_key($config)
{
    $expected = (string) $config['api_key'];
    if ($expected === '') {
		if (!empty($config['allow_unauthenticated_reports'])) {
			return;
		}
		statuspigeon_text_error('report authentication is not configured', 503);
    }
    $provided = statuspigeon_request_key();
    if ($provided === '' || !hash_equals($expected, $provided)) {
        statuspigeon_text_error('unauthorized', 401);
    }
}

function statuspigeon_require_json_content_type()
{
	$contentType = isset($_SERVER['CONTENT_TYPE']) ? trim((string) $_SERVER['CONTENT_TYPE']) : '';
	$mediaType = strtolower(trim(explode(';', $contentType, 2)[0]));
	if ($mediaType !== 'application/json') {
		statuspigeon_text_error('content type must be application/json', 415);
	}
}

function statuspigeon_request_body($config)
{
    $max = max(1024, (int) $config['max_report_bytes']);
    if (isset($_SERVER['CONTENT_LENGTH']) && (int) $_SERVER['CONTENT_LENGTH'] > $max) {
        statuspigeon_text_error('request body too large', 413);
    }
    $body = file_get_contents('php://input');
    if ($body === false || strlen($body) > $max) {
        statuspigeon_text_error('request body too large', 413);
    }
    return $body;
}

/**
 * Return a public client IP observed by the PHP server or its trusted proxy.
 * A private REMOTE_ADDR is normally the reverse proxy/application gateway;
 * in that case inspect common proxy headers, but never let those headers
 * override a directly connected public peer.
 */
function statuspigeon_request_remote_ip()
{
    $direct = isset($_SERVER['REMOTE_ADDR']) ? trim((string) $_SERVER['REMOTE_ADDR']) : '';
    $directPublic = statuspigeon_public_ip($direct);
    if ($directPublic !== '') {
        return $directPublic;
    }
	if ($direct === '' || filter_var($direct, FILTER_VALIDATE_IP) === false) {
		return '';
	}

    if (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $forwarded = explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR']);
		// Walk from the trusted proxy end. If an upstream appends the real
		// address to a client-supplied header, the leftmost value is spoofable.
		for ($index = count($forwarded) - 1; $index >= 0; $index--) {
			$candidate = $forwarded[$index];
            $candidate = trim($candidate);
            $candidate = trim($candidate, " \t\r\n\"'");
            $candidate = preg_replace('/^for=/i', '', $candidate);
            $candidate = trim($candidate, " \t\r\n\"'");
            if (strpos($candidate, '[') === 0 && strpos($candidate, ']') !== false) {
                $candidate = substr($candidate, 1, strpos($candidate, ']') - 1);
            }
            $candidate = statuspigeon_public_ip($candidate);
            if ($candidate !== '') {
                return $candidate;
            }
        }
    }

	// Only consult forwarding headers when the immediate peer is not public.
	// These single-value headers are fallbacks for proxies without XFF.
	$headers = array('HTTP_X_REAL_IP', 'HTTP_CF_CONNECTING_IP', 'HTTP_TRUE_CLIENT_IP');
	foreach ($headers as $header) {
		if (!isset($_SERVER[$header])) {
			continue;
		}
		$candidate = statuspigeon_public_ip($_SERVER[$header]);
		if ($candidate !== '') {
			return $candidate;
		}
	}

    // Do not persist/display a private gateway address as @hub.
    return '';
}

function statuspigeon_request_is_https()
{
	if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
		return true;
	}
	if (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443) {
		return true;
	}
	$direct = isset($_SERVER['REMOTE_ADDR']) ? trim((string) $_SERVER['REMOTE_ADDR']) : '';
	if ($direct === '' || statuspigeon_public_ip($direct) !== '') {
		return false;
	}
	if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
		$values = explode(',', strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']));
		$proto = trim($values[count($values) - 1]);
		return $proto === 'https';
	}
	return false;
}

function statuspigeon_range_seconds($range)
{
    switch (strtolower(trim((string) $range))) {
        case '1h':
            return 3600;
        case '7d':
            return 7 * 86400;
        case '24h':
        default:
            return 86400;
    }
}
