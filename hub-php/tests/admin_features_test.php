<?php
require_once dirname(__DIR__) . '/lib/database.php';

function admin_feature_assert($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: " . $message . "\n");
        exit(1);
    }
}

function admin_feature_report($deviceId, $hostname, $timestamp, $memPct)
{
    return statuspigeon_normalize_report(array(
        'agent_version' => 'test',
        'device_id' => $deviceId,
        'hostname' => $hostname,
        'timestamp' => $timestamp,
        'metrics' => array(
            'os' => array(
                'os' => 'OpenWrt',
                'version' => '24.10.4',
                'kernel' => '6.6',
                'arch' => 'x86_64',
                'uptime' => 60,
                'cpu_model' => 'test-cpu',
                'memory_total' => 536870912,
                'disk_total' => 1073741824,
                'disk_used_pct' => 25,
                'ipv4' => array('192.168.1.1@lan'),
                'ipv6' => array(),
            ),
            'cpu' => array('load1' => 0.5, 'load5' => 0.5, 'load15' => 0.5),
            'mem' => array('total' => 536870912, 'used' => 1, 'available' => 2, 'used_pct' => $memPct),
        ),
    ));
}

function admin_feature_scalar($pdo, $sql, $hostId)
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array(':host_id' => $hostId));
    return $stmt->fetchColumn();
}

function admin_feature_cleanup($path)
{
    @unlink($path);
    @unlink($path . '-wal');
    @unlink($path . '-shm');
}

$config = array(
    'degraded_mem' => 95,
    'report_interval' => 1,
    'offline_periods' => 1,
    'retention_days' => 90,
    'uptime_bar_days' => 60,
    'vacuum_interval_days' => 7,
);
$path = sys_get_temp_dir() . '/statuspigeon-admin-features-' . bin2hex(random_bytes(8)) . '.sqlite';
$legacyPath = sys_get_temp_dir() . '/statuspigeon-admin-legacy-' . bin2hex(random_bytes(8)) . '.sqlite';
$pdo = null;
$legacy = null;
try {
    $config['db_path'] = $path;
    $pdo = statuspigeon_db($config);
    $now = time();
    $hostA = statuspigeon_ingest(
        $pdo,
        admin_feature_report('feature-a', 'router-a', $now, 40),
        $config
    );
    $hostB = statuspigeon_ingest(
        $pdo,
        admin_feature_report('feature-b', 'router-b', $now, 50),
        $config
    );
    admin_feature_assert($hostA > 0 && $hostB > 0 && $hostA !== $hostB, 'test hosts were not created');

    $categoryId = statuspigeon_create_category($pdo, '核心设备');
    $duplicateRejected = false;
    try {
        statuspigeon_create_category($pdo, '核心设备');
    } catch (RuntimeException $expected) {
        $duplicateRejected = true;
    }
    admin_feature_assert($duplicateRejected, 'duplicate category name was accepted');
    $reservedRejected = false;
    try {
        statuspigeon_create_category($pdo, '未分类');
    } catch (InvalidArgumentException $expected) {
        $reservedRejected = true;
    }
    admin_feature_assert($reservedRejected, 'reserved uncategorized name was accepted');
    statuspigeon_update_category($pdo, $categoryId, '核心路由');
    statuspigeon_set_device_category($pdo, $hostA, $categoryId);
    $deviceA = statuspigeon_device($pdo, $hostA);
    admin_feature_assert((int) $deviceA['category_id'] === $categoryId, 'host category was not assigned');
    $categories = statuspigeon_categories($pdo);
    admin_feature_assert(count($categories) === 1
        && $categories[0]['name'] === '核心路由'
        && $categories[0]['host_count'] === 1,
        'category rename or usage count failed');

    $before = $pdo->query(
        'SELECT last_seen, last_status, last_summary FROM hosts WHERE id=' . (int) $hostA
    )->fetch();
    $metricsBefore = (int) admin_feature_scalar(
        $pdo,
        'SELECT COUNT(*) FROM metrics_raw WHERE host_id=:host_id',
        $hostA
    );
    $dailyRowsBefore = (int) admin_feature_scalar(
        $pdo,
        'SELECT COUNT(*) FROM uptime_daily WHERE host_id=:host_id',
        $hostA
    );
    $dailySamplesBefore = (int) admin_feature_scalar(
        $pdo,
        'SELECT COALESCE(SUM(total_samples), 0) FROM uptime_daily WHERE host_id=:host_id',
        $hostA
    );
    statuspigeon_set_device_enabled($pdo, $hostA, false);
    statuspigeon_ingest(
        $pdo,
        admin_feature_report('feature-a', 'router-a-renamed', $now + 60, 99),
        $config,
        '203.0.113.10'
    );
    $after = $pdo->query(
        'SELECT last_seen, last_status, last_summary FROM hosts WHERE id=' . (int) $hostA
    )->fetch();
    admin_feature_assert($before === $after, 'disabled report changed host state or summary');
    admin_feature_assert((int) admin_feature_scalar(
        $pdo,
        'SELECT COUNT(*) FROM metrics_raw WHERE host_id=:host_id',
        $hostA
    ) === $metricsBefore, 'disabled report inserted raw metrics');
    admin_feature_assert((int) admin_feature_scalar(
        $pdo,
        'SELECT COUNT(*) FROM uptime_daily WHERE host_id=:host_id',
        $hostA
    ) === $dailyRowsBefore && (int) admin_feature_scalar(
        $pdo,
        'SELECT COALESCE(SUM(total_samples), 0) FROM uptime_daily WHERE host_id=:host_id',
        $hostA
    ) === $dailySamplesBefore, 'disabled report changed daily aggregates');

    $publicHosts = statuspigeon_hosts($pdo);
    $adminHosts = statuspigeon_hosts($pdo, true);
    admin_feature_assert(count($publicHosts) === 1 && (int) $publicHosts[0]['id'] === $hostB,
        'disabled host leaked into public host query');
    admin_feature_assert(count($adminHosts) === 2, 'disabled host disappeared from admin host query');

    // Disabled hosts are paused for offline maintenance as well as ingestion.
    $pdo->exec('UPDATE hosts SET last_seen=1');
    statuspigeon_maintenance($pdo, $config);
    $statusA = (string) admin_feature_scalar($pdo, 'SELECT last_status FROM hosts WHERE id=:host_id', $hostA);
    $statusB = (string) admin_feature_scalar($pdo, 'SELECT last_status FROM hosts WHERE id=:host_id', $hostB);
    admin_feature_assert($statusA === $before['last_status'] && $statusB === 'down',
        'offline maintenance did not respect the enabled flag');

    statuspigeon_set_device_enabled($pdo, $hostA, true);
    statuspigeon_ingest(
        $pdo,
        admin_feature_report('feature-a', 'router-a', $now + 120, 60),
        $config
    );
    $deviceA = statuspigeon_device($pdo, $hostA);
    admin_feature_assert((int) $deviceA['enabled'] === 1
        && (int) $deviceA['category_id'] === $categoryId,
        'reenabling or ingesting reset host admin settings');
    admin_feature_assert((int) admin_feature_scalar(
        $pdo,
        'SELECT COUNT(*) FROM metrics_raw WHERE host_id=:host_id',
        $hostA
    ) === $metricsBefore + 1, 'report was not stored after reenabling');

    $dailyRowsBeforePurge = (int) admin_feature_scalar(
        $pdo,
        'SELECT COUNT(*) FROM uptime_daily WHERE host_id=:host_id',
        $hostA
    );
    $metricsB = (int) admin_feature_scalar(
        $pdo,
        'SELECT COUNT(*) FROM metrics_raw WHERE host_id=:host_id',
        $hostB
    );
    $deleted = statuspigeon_purge_device_reports($pdo, $hostA);
    admin_feature_assert($deleted === $metricsBefore + 1, 'purge returned the wrong deleted row count');
    admin_feature_assert((int) admin_feature_scalar(
        $pdo,
        'SELECT COUNT(*) FROM metrics_raw WHERE host_id=:host_id',
        $hostA
    ) === 0 && (int) admin_feature_scalar(
        $pdo,
        'SELECT COUNT(*) FROM metrics_raw WHERE host_id=:host_id',
        $hostB
    ) === $metricsB, 'purge deleted the wrong host metrics');
    admin_feature_assert((int) admin_feature_scalar(
        $pdo,
        'SELECT COUNT(*) FROM uptime_daily WHERE host_id=:host_id',
        $hostA
    ) === $dailyRowsBeforePurge, 'purge deleted daily uptime history');
    $deviceA = statuspigeon_device($pdo, $hostA);
    admin_feature_assert($deviceA && (int) $deviceA['category_id'] === $categoryId,
        'purge deleted host identity or category');

    $stats = statuspigeon_storage_stats($pdo, $config);
    admin_feature_assert($stats['total_bytes'] > 0 && count($stats['hosts']) === 2,
        'storage totals or host statistics are missing');
    foreach ($stats['hosts'] as $storageHost) {
        if ($storageHost['id'] === $hostA) {
            admin_feature_assert($storageHost['report_count'] === 0 && $storageHost['estimated_bytes'] === 0,
                'cleared host still has estimated raw storage');
        }
        if ($storageHost['id'] === $hostB) {
            admin_feature_assert($storageHost['report_count'] === $metricsB && $storageHost['estimated_bytes'] > 0,
                'remaining host storage estimate is incorrect');
        }
    }
    statuspigeon_optimize_database($pdo);
    admin_feature_assert((int) statuspigeon_meta_get($pdo, 'last_vacuum_ts') > 0,
        'manual optimization did not update the vacuum timestamp');

    statuspigeon_delete_category($pdo, $categoryId);
    $deviceA = statuspigeon_device($pdo, $hostA);
    admin_feature_assert($deviceA && $deviceA['category_id'] === null,
        'deleting a category did not reset its host to uncategorized');
    admin_feature_assert((int) admin_feature_scalar(
        $pdo,
        'SELECT COUNT(*) FROM uptime_daily WHERE host_id=:host_id',
        $hostA
    ) === $dailyRowsBeforePurge, 'deleting a category deleted host history');
    admin_feature_assert(statuspigeon_uncategorized_count($pdo) === 2,
        'uncategorized count is incorrect after category deletion');
    $pdo = null;
    admin_feature_cleanup($path);

    // Upgrade a pre-feature hosts table in place; existing rows stay enabled
    // and uncategorized without a destructive schema rebuild.
    $legacy = new PDO('sqlite:' . $legacyPath);
    $legacy->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $legacy->exec(
        'CREATE TABLE hosts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            device_id TEXT UNIQUE NOT NULL,
            hostname TEXT NOT NULL,
            agent_version TEXT,
            os TEXT,
            kernel TEXT,
            arch TEXT,
            remote_ip TEXT,
            last_seen INTEGER NOT NULL DEFAULT 0,
            created_at INTEGER NOT NULL,
            last_status TEXT,
            last_summary TEXT,
            source TEXT NOT NULL DEFAULT \'push\'
        )'
    );
    $legacy->exec(
        "INSERT INTO hosts (device_id, hostname, created_at, source)
         VALUES ('legacy-feature', 'legacy-host', 1, 'push')"
    );
    $legacy = null;
    $legacy = statuspigeon_db(array('db_path' => $legacyPath));
    $legacyHost = $legacy->query(
        "SELECT category_id, enabled FROM hosts WHERE device_id='legacy-feature'"
    )->fetch();
    admin_feature_assert($legacyHost && $legacyHost['category_id'] === null && (int) $legacyHost['enabled'] === 1,
        'legacy host migration did not apply additive defaults');
} finally {
    $pdo = null;
    $legacy = null;
    admin_feature_cleanup($path);
    admin_feature_cleanup($legacyPath);
}

echo "PHP admin feature tests passed\n";
