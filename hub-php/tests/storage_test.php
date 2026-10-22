<?php
require_once dirname(__DIR__) . '/lib/database.php';

function storage_assert($condition, $message)
{
	if (!$condition) {
		fwrite(STDERR, "FAIL: " . $message . "\n");
		exit(1);
	}
}

function storage_report($ts, $memPct)
{
	return statuspigeon_normalize_report(array(
		'agent_version' => 'test',
		'device_id' => 'device-storage',
		'hostname' => 'storage-host',
		'timestamp' => $ts,
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

function storage_index_names($pdo)
{
	$names = array();
	foreach ($pdo->query('PRAGMA index_list(metrics_raw)')->fetchAll() as $index) {
		$names[] = (string) $index['name'];
	}
	return $names;
}

$config = array(
	'degraded_mem' => 95,
	'report_interval' => 300,
	'offline_periods' => 3,
	'retention_days' => 30,
	'uptime_bar_days' => 60,
	'vacuum_interval_days' => 7,
);

$path = sys_get_temp_dir() . '/statuspigeon-storage-' . bin2hex(random_bytes(8)) . '.sqlite';
try {
	$pdo = statuspigeon_db(array('db_path' => $path));

	// One sample per host and second: a differing payload in the same second
	// is a retry, not a second sample.
	$now = time();
	statuspigeon_ingest($pdo, storage_report($now, 40), $config);
	statuspigeon_ingest($pdo, storage_report($now, 55), $config);
	$count = (int) $pdo->query('SELECT COUNT(*) FROM metrics_raw')->fetchColumn();
	$samples = (int) $pdo->query('SELECT total_samples FROM uptime_daily')->fetchColumn();
	storage_assert($count === 1 && $samples === 1, 'same-second report was stored twice');
	$mem = (float) $pdo->query('SELECT mem_usage FROM metrics_raw')->fetchColumn();
	storage_assert($mem === 40.0, 'retry overwrote the first sample');

	// The unique index rejects a second row for the same host and second.
	$rejected = false;
	try {
		$pdo->exec(
			"INSERT INTO metrics_raw (host_id, ts, cpu_usage, mem_usage, load1, payload)
			 SELECT host_id, ts, NULL, 99, 0, '{\"retry\":true}' FROM metrics_raw LIMIT 1"
		);
	} catch (PDOException $expected) {
		$rejected = true;
	}
	storage_assert($rejected, 'unique index does not cover (host_id, ts)');

	// Reopen a legacy schema that still deduplicates on the full payload and
	// holds two samples for one second; the migration must collapse them.
	$pdo->exec('DROP INDEX idx_raw_host_ts_unique');
	$pdo->exec('CREATE INDEX idx_raw_host_ts ON metrics_raw(host_id, ts)');
	$pdo->exec('CREATE UNIQUE INDEX idx_raw_host_ts_payload_unique ON metrics_raw(host_id, ts, payload)');
	$row = $pdo->query(
		'SELECT host_id, ts, cpu_usage, mem_usage, load1, payload FROM metrics_raw LIMIT 1'
	)->fetch();
	$altered = json_decode((string) $row['payload'], true);
	$altered['metrics']['mem']['used_pct'] = 66;
	$insert = $pdo->prepare(
		'INSERT INTO metrics_raw (host_id, ts, cpu_usage, mem_usage, load1, payload)
		 VALUES (:host_id, :ts, :cpu, :mem, :load1, :payload)'
	);
	$insert->execute(array(
		':host_id' => $row['host_id'],
		':ts' => $row['ts'],
		':cpu' => $row['cpu_usage'],
		':mem' => 66,
		':load1' => $row['load1'],
		':payload' => statuspigeon_json_encode($altered),
	));
	$pdo = null;
	$pdo = statuspigeon_db(array('db_path' => $path));
	$names = storage_index_names($pdo);
	storage_assert(in_array('idx_raw_host_ts_unique', $names, true), 'migration did not create the (host_id, ts) index');
	storage_assert(!in_array('idx_raw_host_ts_payload_unique', $names, true), 'payload-based index survived the migration');
	storage_assert(!in_array('idx_raw_host_ts', $names, true), 'redundant plain index survived the migration');
	$count = (int) $pdo->query('SELECT COUNT(*) FROM metrics_raw')->fetchColumn();
	$mem = (float) $pdo->query('SELECT mem_usage FROM metrics_raw')->fetchColumn();
	storage_assert($count === 1 && $mem === 40.0, 'migration kept more than the earliest sample');
	statuspigeon_ingest($pdo, storage_report($now + 3600, 50), $config);
	$count = (int) $pdo->query('SELECT COUNT(*) FROM metrics_raw')->fetchColumn();
	storage_assert($count === 2, 'ingest broken after migration');

	// Retention deletes raw rows past the window while daily aggregates
	// survive up to the uptime-bar span, and VACUUM returns the freed pages
	// to the filesystem instead of leaving the file at its high-water mark.
	// Checkpoint first so the measured size includes WAL content.
	$old = time() - 40 * 86400;
	for ($i = 0; $i < 300; $i++) {
		statuspigeon_ingest($pdo, storage_report($old + $i, 40), $config);
	}
	$hostId = (int) $pdo->query('SELECT id FROM hosts LIMIT 1')->fetchColumn();
	$pdo->exec(
		"INSERT OR IGNORE INTO uptime_daily
		 (host_id, date, status, uptime_pct, total_samples, degraded_samples, down_samples)
		 VALUES (" . $hostId . ", date('now', '-70 days'), 'operational', 100, 1, 0, 0)"
	);
	$pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');
	clearstatcache();
	$sizeBefore = (int) filesize($path);
	statuspigeon_maintenance($pdo, $config);
	$count = (int) $pdo->query('SELECT COUNT(*) FROM metrics_raw')->fetchColumn();
	$oldDaily = (int) $pdo->query(
		"SELECT COUNT(*) FROM uptime_daily WHERE date < date('now', '-30 days')"
	)->fetchColumn();
	$ancientDaily = (int) $pdo->query(
		"SELECT COUNT(*) FROM uptime_daily WHERE date < date('now', '-60 days')"
	)->fetchColumn();
	storage_assert($count === 2 && $oldDaily === 1 && $ancientDaily === 0,
		'retention dropped live rows, killed bar-span aggregates, or kept expired aggregates');
	storage_assert((int) statuspigeon_meta_get($pdo, 'last_vacuum_ts') > 0, 'vacuum attempt was not recorded');
	clearstatcache();
	$sizeAfter = (int) filesize($path);
	storage_assert($sizeAfter < $sizeBefore, 'vacuum did not shrink the database file');
} finally {
	$pdo = null;
	@unlink($path);
	@unlink($path . '-wal');
	@unlink($path . '-shm');
}

echo "PHP storage tests passed\n";
