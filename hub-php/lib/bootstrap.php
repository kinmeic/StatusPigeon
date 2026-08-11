<?php
/** Shared bootstrap for every PHP endpoint. PHP 7.2 compatible. */

if (defined('STATUSPIGEON_BOOTSTRAPPED')) {
    return;
}
define('STATUSPIGEON_BOOTSTRAPPED', true);

$config = array(
    'api_key' => '',
    'admin_password_hash' => '',
    'db_path' => dirname(__DIR__) . '/../statuspigeon-data/statuspigeon.sqlite',
    'timezone' => 'UTC',
    'retention_days' => 90,
    'uptime_bar_days' => 90,
    'degraded_cpu' => 90.0,
    'degraded_mem' => 95.0,
    'report_interval' => 300,
    'offline_periods' => 3,
    'max_report_bytes' => 1048576,
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
        return;
    }
    $provided = statuspigeon_request_key();
    if ($provided === '' || !hash_equals($expected, $provided)) {
        statuspigeon_text_error('unauthorized', 401);
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
