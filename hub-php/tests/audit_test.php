<?php
require_once dirname(__DIR__) . '/lib/database.php';

function audit_assert($condition, $message)
{
	if (!$condition) {
		fwrite(STDERR, "FAIL: " . $message . "\n");
		exit(1);
	}
}

$input = array(
	'agent_version' => 'test',
	'device_id' => 'device-a',
	'hostname' => 'router',
	'timestamp' => time(),
	'metrics' => array(
		'os' => array(
			'os' => 'OpenWrt',
			'version' => '24.10.4',
			'kernel' => '6.6',
			'arch' => 'aarch64',
			'uptime' => 120,
			'cpu_model' => 'private-model',
			'memory_total' => 536870912,
			'disk_total' => 1073741824,
			'disk_used_pct' => 25,
			'ipv4' => array('192.168.1.1@lan'),
			'ipv6' => array('2001:db8::1@wan6'),
		),
		'cpu' => array('load1' => 1, 'load5' => 2, 'load15' => 3),
		'mem' => array('total' => 536870912, 'used' => 1, 'available' => 2, 'used_pct' => 50),
	),
);

$report = statuspigeon_normalize_report($input);
audit_assert(statuspigeon_validate_report($report) === '', 'valid report rejected');
$invalid = $report;
$invalid['hostname'] = "router\nforged";
audit_assert(statuspigeon_validate_report($invalid) !== '', 'control character accepted');
$invalid = $report;
$invalid['metrics']['mem']['used_pct'] = 101;
audit_assert(statuspigeon_validate_report($invalid) !== '', 'invalid percentage accepted');

$path = sys_get_temp_dir() . '/statuspigeon-audit-' . bin2hex(random_bytes(8)) . '.sqlite';
try {
	$pdo = statuspigeon_db(array('db_path' => $path));
	$config = array(
		'degraded_mem' => 95,
		'report_interval' => 1,
		'offline_periods' => 1,
		'retention_days' => 90,
	);
	statuspigeon_ingest($pdo, $report, $config, '203.0.113.10');
	statuspigeon_ingest($pdo, $report, $config, '203.0.113.10');
	$metricCount = (int) $pdo->query('SELECT COUNT(*) FROM metrics_raw')->fetchColumn();
	$sampleCount = (int) $pdo->query('SELECT total_samples FROM uptime_daily')->fetchColumn();
	audit_assert($metricCount === 1 && $sampleCount === 1, 'duplicate report was not idempotent');
	$renamed = $report;
	$renamed['hostname'] = 'renamed-router';
	$renamed['timestamp']++;
	statuspigeon_ingest($pdo, $renamed, $config, '');
	$storedHostname = (string) $pdo->query("SELECT hostname FROM hosts WHERE device_id='device-a'")->fetchColumn();
	audit_assert($storedHostname === 'renamed-router', 'hostname display label did not update');
	$pdo->exec('UPDATE hosts SET last_seen=1');
	statuspigeon_maintenance($pdo, $config);
	$recovered = $renamed;
	$recovered['timestamp']++;
	statuspigeon_ingest($pdo, $recovered, $config, '');
	$daily = $pdo->query('SELECT status, down_samples FROM uptime_daily')->fetch();
	audit_assert($daily['status'] === 'down' && (int) $daily['down_samples'] === 1,
		'offline sample was lost or duplicated after recovery');

	$hosts = statuspigeon_hosts($pdo);
	$public = statuspigeon_public_summary($hosts[0]['last_summary']);
	audit_assert(strpos($public, 'private-model') === false, 'public summary leaked CPU model');
	audit_assert(strpos($public, '192.168.1.1') === false, 'public summary leaked IP');
} finally {
	$pdo = null;
	@unlink($path);
	@unlink($path . '-wal');
	@unlink($path . '-shm');
}

echo "PHP audit tests passed\n";
