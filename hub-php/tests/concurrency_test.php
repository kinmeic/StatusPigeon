<?php
/**
 * Concurrent ingest + maintenance must not deadlock on the SQLite write
 * lock.  Each PHP-FPM worker opens its own connection per request, so this
 * spawns real processes that reconnect every iteration; the offline-host
 * marker keeps the maintenance write path active while other workers post
 * reports.  A deferred read-then-write transaction deadlocks here and
 * fails with "database is locked" instead of waiting out busy_timeout.
 */
require_once dirname(__DIR__) . '/lib/database.php';

$config = array(
	'degraded_mem' => 95,
	'report_interval' => 300,
	'offline_periods' => 3,
	'retention_days' => 30,
	'uptime_bar_days' => 60,
	'vacuum_interval_days' => 7,
);

$workerSource = <<<'PHP'
<?php
require_once $argv[1] . '/lib/database.php';
$config = array(
	'degraded_mem' => 95,
	'report_interval' => 300,
	'offline_periods' => 3,
	'retention_days' => 30,
	'uptime_bar_days' => 60,
	'vacuum_interval_days' => 7,
);
$root = $argv[1];
$dbPath = $argv[2];
$workerId = (int) $argv[3];
$iterations = (int) $argv[4];

function concurrency_report($deviceId, $ts, $memPct)
{
	return statuspigeon_normalize_report(array(
		'device_id' => $deviceId,
		'hostname' => 'host-' . $deviceId,
		'timestamp' => $ts,
		'metrics' => array(
			'os' => array('os' => 'OpenWrt', 'disk_used_pct' => 25),
			'cpu' => array('load1' => 0.5, 'load5' => 0.5, 'load15' => 0.5),
			'mem' => array('total' => 1, 'used' => 1, 'available' => 0, 'used_pct' => $memPct),
		),
	));
}

for ($i = 0; $i < $iterations; $i++) {
	// A fresh "request": reconnect, run opportunistic maintenance, then
	// post one report — the same mix as page views racing an agent push.
	$pdo = statuspigeon_db(array('db_path' => $dbPath));
	statuspigeon_maintenance($pdo, $config);
	statuspigeon_ingest(
		$pdo,
		concurrency_report('device-' . $workerId, time() + $workerId * 100 + $i, 40),
		$config
	);
	$pdo = null;
}
PHP;

$path = sys_get_temp_dir() . '/statuspigeon-concurrency-'
	. bin2hex(random_bytes(8)) . '.sqlite';
$workerFile = $path . '-worker.php';
file_put_contents($workerFile, $workerSource);

try {
	// A host whose last_seen is far past the offline threshold keeps the
	// maintenance write path (offline marking) active in every worker.
	$pdo = statuspigeon_db(array('db_path' => $path));
	$pdo->exec(
		"INSERT INTO hosts (device_id, hostname, created_at, last_seen, last_status, source)
		 VALUES ('offline-device', 'offline-host', 0, 0, 'operational', 'push')"
	);
	$pdo = null;

	$root = dirname(__DIR__);
	$workers = 8;
	$iterations = 15;
	$processes = array();
	$pipes = array();
	for ($id = 0; $id < $workers; $id++) {
		$spec = array(1 => array('pipe', 'w'), 2 => array('pipe', 'w'));
		$cmd = array(
			PHP_BINARY,
			$workerFile,
			escapeshellarg($root),
			escapeshellarg($path),
			(string) $id,
			(string) $iterations,
		);
		$processes[$id] = proc_open(implode(' ', $cmd), $spec, $pipes[$id]);
		if (!is_resource($processes[$id])) {
			fwrite(STDERR, "FAIL: unable to spawn worker\n");
			exit(1);
		}
	}

	$failed = false;
	for ($id = 0; $id < $workers; $id++) {
		$output = stream_get_contents($pipes[$id][1]);
		$errors = stream_get_contents($pipes[$id][2]);
		fclose($pipes[$id][1]);
		fclose($pipes[$id][2]);
		$exit = proc_close($processes[$id]);
		if ($exit !== 0) {
			fwrite(STDERR, "FAIL: worker $id exited with $exit\n$errors");
			$failed = true;
		}
		if (strpos($output . $errors, 'database is locked') !== false) {
			fwrite(STDERR, "FAIL: worker $id hit a lock error\n$output$errors");
			$failed = true;
		}
	}
	if ($failed) {
		exit(1);
	}

	$pdo = statuspigeon_db(array('db_path' => $path));
	$samples = (int) $pdo->query('SELECT COUNT(*) FROM metrics_raw')->fetchColumn();
	if ($samples !== $workers * $iterations) {
		fwrite(STDERR, "FAIL: expected {$workers}x{$iterations} samples, got $samples\n");
		exit(1);
	}
	$offline = $pdo->query(
		"SELECT last_status, down_samples FROM hosts
		 JOIN uptime_daily ON uptime_daily.host_id = hosts.id
		 WHERE hosts.device_id = 'offline-device' AND uptime_daily.date = date('now')"
	)->fetch();
	if (!$offline || $offline['last_status'] !== 'down' || (int) $offline['down_samples'] !== 1) {
		fwrite(STDERR, "FAIL: offline host was not marked down exactly once\n");
		exit(1);
	}
	$pdo = null;
} finally {
	@unlink($path);
	@unlink($path . '-wal');
	@unlink($path . '-shm');
	@unlink($workerFile);
}

echo "PHP concurrency tests passed\n";
