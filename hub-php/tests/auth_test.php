<?php
require_once dirname(__DIR__) . '/lib/database.php';
require_once dirname(__DIR__) . '/lib/auth.php';

function auth_assert($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: " . $message . "\n");
        exit(1);
    }
}

$path = sys_get_temp_dir() . '/statuspigeon-auth-' . bin2hex(random_bytes(8)) . '.sqlite';
$pdo = null;
try {
    $pdo = statuspigeon_db(array('db_path' => $path));
    $config = array(
        'login_max_failures' => 3,
        'login_window_seconds' => 60,
        'login_lockout_seconds' => 120,
        'login_delay_base_ms' => 250,
        'login_delay_max_ms' => 1000,
    );
    $now = 1700000000;
    $ip = '192.0.2.10';

    $first = statuspigeon_admin_login_record_failure($pdo, $ip, $now, $config);
    auth_assert($first['failed_count'] === 1 && $first['delay_ms'] === 250,
        'first failure did not use the base delay');
    $second = statuspigeon_admin_login_record_failure($pdo, $ip, $now + 1, $config);
    auth_assert($second['failed_count'] === 2 && $second['delay_ms'] === 500,
        'second failure did not use exponential delay');
    $third = statuspigeon_admin_login_record_failure($pdo, $ip, $now + 2, $config);
    auth_assert($third['failed_count'] === 3 && $third['locked'] === true,
        'threshold failure did not lock the source');
    auth_assert($third['retry_after'] === 120, 'lockout duration is incorrect');

    $blocked = statuspigeon_admin_login_rate_status($pdo, $ip, $now + 3, $config);
    auth_assert($blocked['limited'] === true && $blocked['retry_after'] === 119,
        'locked source was not blocked');

    statuspigeon_admin_login_audit($pdo, $ip, 'failure', 1, 0);
    statuspigeon_admin_login_audit($pdo, $ip, 'locked', 3, 120);
    auth_assert(statuspigeon_admin_login_audit_count($pdo) === 2,
        'login audit events were not stored');
    $events = statuspigeon_admin_recent_login_audit($pdo, 10);
    auth_assert(count($events) === 2 && $events[0]['outcome'] === 'locked',
        'recent login audit ordering is incorrect');

    statuspigeon_admin_login_reset($pdo, $ip);
    $reset = statuspigeon_admin_login_rate_status($pdo, $ip, $now + 3, $config);
    auth_assert($reset['limited'] === false && $reset['failed_count'] === 0,
        'successful login reset did not clear the lock state');
} finally {
    $pdo = null;
    @unlink($path);
    @unlink($path . '-wal');
    @unlink($path . '-shm');
}

echo "PHP auth protection tests passed\n";
