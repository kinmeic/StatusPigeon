<?php
/**
 * SQLite storage and query helpers for the PHP 7.2 hub.
 *
 * The schema intentionally mirrors hub/store.go so a database can be moved
 * between the Go and PHP hub implementations when the SQLite versions are
 * compatible.
 */

function statuspigeon_db($config)
{
    $path = (string) $config['db_path'];
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new RuntimeException('Unable to create database directory');
    }

    $pdo = new PDO('sqlite:' . $path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA busy_timeout=5000');
    $pdo->exec('PRAGMA foreign_keys=ON');
    // WAL is useful on shared hosting where the status page is read while an
    // agent is posting.  Older SQLite builds may reject it; the database is
    // still usable without WAL, so do not make it a hard dependency.
    try {
        $pdo->exec('PRAGMA journal_mode=WAL');
        $pdo->exec('PRAGMA synchronous=NORMAL');
    } catch (Exception $ignored) {
        // Keep the default journal mode.
    }

    $schema = array(
        'CREATE TABLE IF NOT EXISTS hosts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            device_id TEXT UNIQUE NOT NULL,
            hostname TEXT NOT NULL,
            agent_version TEXT,
            os TEXT,
            kernel TEXT,
            arch TEXT,
            last_seen INTEGER NOT NULL DEFAULT 0,
            created_at INTEGER NOT NULL,
            last_status TEXT,
            last_summary TEXT,
            source TEXT NOT NULL DEFAULT \'push\'
        )',
        'CREATE TABLE IF NOT EXISTS metrics_raw (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            host_id INTEGER NOT NULL,
            ts INTEGER NOT NULL,
            cpu_usage REAL,
            mem_usage REAL,
            load1 REAL,
            payload TEXT NOT NULL,
            FOREIGN KEY(host_id) REFERENCES hosts(id)
        )',
        'CREATE INDEX IF NOT EXISTS idx_raw_host_ts ON metrics_raw(host_id, ts)',
        'CREATE TABLE IF NOT EXISTS uptime_daily (
            host_id INTEGER NOT NULL,
            date TEXT NOT NULL,
            status TEXT NOT NULL,
            uptime_pct REAL NOT NULL DEFAULT 0,
            total_samples INTEGER NOT NULL DEFAULT 0,
            degraded_samples INTEGER NOT NULL DEFAULT 0,
            down_samples INTEGER NOT NULL DEFAULT 0,
            UNIQUE(host_id, date),
            FOREIGN KEY(host_id) REFERENCES hosts(id)
        )',
        'CREATE INDEX IF NOT EXISTS idx_daily_host_date ON uptime_daily(host_id, date)',
    );
    foreach ($schema as $statement) {
        $pdo->exec($statement);
    }
    statuspigeon_migrate_host_identity($pdo);
    // Existing databases may still contain legacy I/O columns.  They are
    // intentionally left in place for non-destructive upgrades, but no new
    // report reads or writes those columns.
    return $pdo;
}

function statuspigeon_migrate_host_identity($pdo)
{
    $columns = $pdo->query('PRAGMA table_info(hosts)')->fetchAll();
    $columnNames = array();
    foreach ($columns as $column) {
        if (isset($column['name'])) {
            $columnNames[(string) $column['name']] = true;
        }
    }
    $hasDeviceId = isset($columnNames['device_id']);
    if ($hasDeviceId && !statuspigeon_hosts_have_unique_hostname($pdo)) {
        return;
    }

    $pdo->exec('PRAGMA foreign_keys=OFF');
    try {
        $pdo->beginTransaction();
        $pdo->exec(
            'CREATE TABLE hosts_new (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                device_id TEXT NOT NULL,
                hostname TEXT NOT NULL,
                agent_version TEXT,
                os TEXT,
                kernel TEXT,
                arch TEXT,
                last_seen INTEGER NOT NULL DEFAULT 0,
                created_at INTEGER NOT NULL,
                last_status TEXT,
                last_summary TEXT,
                source TEXT NOT NULL DEFAULT \'push\'
            )'
        );
        $deviceExpression = $hasDeviceId
            ? "CASE WHEN device_id IS NULL OR device_id = '' THEN ('legacy-hostname:' || hostname) ELSE device_id END"
            : "('legacy-hostname:' || hostname)";
        $pdo->exec(
            'INSERT INTO hosts_new
             (id, device_id, hostname, agent_version, os, kernel, arch, last_seen,
              created_at, last_status, last_summary, source)
             SELECT id, ' . $deviceExpression . ', hostname, agent_version, os,
                    kernel, arch, last_seen, created_at, last_status, last_summary,
                    source FROM hosts'
        );
        $pdo->exec('DROP TABLE hosts');
        $pdo->exec('ALTER TABLE hosts_new RENAME TO hosts');
        $pdo->exec('CREATE UNIQUE INDEX idx_hosts_device_id ON hosts(device_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_hosts_hostname ON hosts(hostname)');
        $pdo->commit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    } finally {
        $pdo->exec('PRAGMA foreign_keys=ON');
    }
}

function statuspigeon_hosts_have_unique_hostname($pdo)
{
    $indexes = $pdo->query('PRAGMA index_list(hosts)')->fetchAll();
    foreach ($indexes as $index) {
        if (!isset($index['unique']) || (int) $index['unique'] !== 1) {
            continue;
        }
        $name = isset($index['name']) ? (string) $index['name'] : '';
        if ($name === '') {
            continue;
        }
        $quoted = '"' . str_replace('"', '""', $name) . '"';
        $info = $pdo->query('PRAGMA index_info(' . $quoted . ')')->fetchAll();
        if (count($info) === 1 && isset($info[0]['name']) && $info[0]['name'] === 'hostname') {
            return true;
        }
    }
    return false;
}

function statuspigeon_metric_number($value, $default)
{
    if (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))) {
        $number = (float) $value;
        if (is_finite($number)) {
            return $number;
        }
    }
    return (float) $default;
}

function statuspigeon_metric_int($value, $default)
{
    if (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))) {
        return (int) $value;
    }
    return (int) $default;
}

function statuspigeon_string($value, $default)
{
    return is_scalar($value) ? trim((string) $value) : (string) $default;
}

function statuspigeon_string_array($value)
{
    $out = array();
    if (!is_array($value)) {
        return $out;
    }
    foreach ($value as $item) {
        if (is_scalar($item) && trim((string) $item) !== '') {
            $out[] = trim((string) $item);
        }
    }
    return $out;
}

/** Normalize the shared Go Report JSON to a predictable PHP array shape. */
function statuspigeon_normalize_report($input)
{
    $input = is_array($input) ? $input : array();
    $rawMetrics = isset($input['metrics']) && is_array($input['metrics'])
        ? $input['metrics'] : array();
    $rawOS = isset($rawMetrics['os']) && is_array($rawMetrics['os'])
        ? $rawMetrics['os'] : array();
    $rawCPU = isset($rawMetrics['cpu']) && is_array($rawMetrics['cpu'])
        ? $rawMetrics['cpu'] : array();
    $rawMem = isset($rawMetrics['mem']) && is_array($rawMetrics['mem'])
        ? $rawMetrics['mem'] : array();

    return array(
        'agent_version' => statuspigeon_string(isset($input['agent_version']) ? $input['agent_version'] : '', ''),
        'device_id' => statuspigeon_string(isset($input['device_id']) ? $input['device_id'] : '', ''),
        'hostname' => statuspigeon_string(isset($input['hostname']) ? $input['hostname'] : '', ''),
        'timestamp' => statuspigeon_metric_int(isset($input['timestamp']) ? $input['timestamp'] : 0, 0),
        'metrics' => array(
            'os' => array(
                'os' => statuspigeon_string(isset($rawOS['os']) ? $rawOS['os'] : '', ''),
                'version' => statuspigeon_string(isset($rawOS['version']) ? $rawOS['version'] : '', ''),
                'kernel' => statuspigeon_string(isset($rawOS['kernel']) ? $rawOS['kernel'] : '', ''),
                'arch' => statuspigeon_string(isset($rawOS['arch']) ? $rawOS['arch'] : '', ''),
                'uptime' => statuspigeon_metric_int(isset($rawOS['uptime']) ? $rawOS['uptime'] : 0, 0),
                'ipv4' => statuspigeon_string_array(isset($rawOS['ipv4']) ? $rawOS['ipv4'] : array()),
                'ipv6' => statuspigeon_string_array(isset($rawOS['ipv6']) ? $rawOS['ipv6'] : array()),
            ),
            'cpu' => array(
                'load1' => statuspigeon_metric_number(isset($rawCPU['load1']) ? $rawCPU['load1'] : 0, 0),
                'load5' => statuspigeon_metric_number(isset($rawCPU['load5']) ? $rawCPU['load5'] : 0, 0),
                'load15' => statuspigeon_metric_number(isset($rawCPU['load15']) ? $rawCPU['load15'] : 0, 0),
                'usage' => statuspigeon_metric_number(isset($rawCPU['usage']) ? $rawCPU['usage'] : 0, 0),
            ),
            'mem' => array(
                'total' => statuspigeon_metric_int(isset($rawMem['total']) ? $rawMem['total'] : 0, 0),
                'used' => statuspigeon_metric_int(isset($rawMem['used']) ? $rawMem['used'] : 0, 0),
                'available' => statuspigeon_metric_int(isset($rawMem['available']) ? $rawMem['available'] : 0, 0),
                'used_pct' => statuspigeon_metric_number(isset($rawMem['used_pct']) ? $rawMem['used_pct'] : 0, 0),
                'swap_total' => statuspigeon_metric_int(isset($rawMem['swap_total']) ? $rawMem['swap_total'] : 0, 0),
                'swap_used' => statuspigeon_metric_int(isset($rawMem['swap_used']) ? $rawMem['swap_used'] : 0, 0),
            ),
        ),
    );
}

function statuspigeon_report_device_id($report)
{
    $deviceID = isset($report['device_id']) ? trim((string) $report['device_id']) : '';
    if ($deviceID !== '') {
        return $deviceID;
    }
    return 'legacy-hostname:' . (isset($report['hostname']) ? trim((string) $report['hostname']) : '');
}

function statuspigeon_status_for_report($report, $config)
{
    $cpu = $report['metrics']['cpu']['usage'];
    $mem = $report['metrics']['mem']['used_pct'];
    if ($cpu > (float) $config['degraded_cpu'] || $mem > (float) $config['degraded_mem']) {
        return 'degraded';
    }
    return 'operational';
}

function statuspigeon_summary($metrics)
{
    $summary = array(
        'cpu' => round((float) $metrics['cpu']['usage'], 1),
        'mem' => round((float) $metrics['mem']['used_pct'], 1),
        'load1' => round((float) $metrics['cpu']['load1'], 2),
        'uptime' => (int) $metrics['os']['uptime'],
        'os' => (string) $metrics['os']['os'],
        'os_version' => (string) $metrics['os']['version'],
        'ipv4' => $metrics['os']['ipv4'],
        'ipv6' => $metrics['os']['ipv6'],
    );
    return json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function statuspigeon_json_encode($value)
{
    $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('Unable to encode JSON');
    }
    return $json;
}

function statuspigeon_ingest($pdo, $report, $config)
{
    $status = statuspigeon_status_for_report($report, $config);
    $metrics = $report['metrics'];
    $summary = statuspigeon_summary($metrics);
    $now = time();
    $date = date('Y-m-d', $report['timestamp']);
    $payload = statuspigeon_json_encode($report);
    $deviceId = statuspigeon_report_device_id($report);

    $pdo->beginTransaction();
    try {
        $findHost = $pdo->prepare('SELECT id FROM hosts WHERE device_id = :device_id');
        $findHost->execute(array(':device_id' => $deviceId));
        $hostId = (int) $findHost->fetchColumn();
        if ($hostId <= 0) {
            // Adopt a legacy row when the first device-aware report arrives
            // after an agent upgrade, preserving its existing history.
            $findLegacy = $pdo->prepare(
                'SELECT id FROM hosts
                 WHERE device_id = :legacy_id AND hostname = :hostname'
            );
            $findLegacy->execute(array(
                ':legacy_id' => 'legacy-hostname:' . $report['hostname'],
                ':hostname' => $report['hostname'],
            ));
            $hostId = (int) $findLegacy->fetchColumn();
            if ($hostId > 0) {
                $adopt = $pdo->prepare('UPDATE hosts SET device_id=:device_id WHERE id=:id');
                $adopt->execute(array(':device_id' => $deviceId, ':id' => $hostId));
            }
        }
        if ($hostId <= 0) {
            $insertHost = $pdo->prepare(
                'INSERT INTO hosts
                 (device_id, hostname, agent_version, os, kernel, arch, last_seen,
                  created_at, last_status, last_summary, source)
                 VALUES (:device_id, :hostname, :agent_version, :os, :kernel, :arch,
                         :last_seen, :created_at, :last_status, :last_summary, :source)'
            );
            $insertHost->execute(array(
                ':device_id' => $deviceId,
                ':hostname' => $report['hostname'],
                ':agent_version' => $report['agent_version'],
                ':os' => $metrics['os']['os'],
                ':kernel' => $metrics['os']['kernel'],
                ':arch' => $metrics['os']['arch'],
                ':last_seen' => $now,
                ':created_at' => $now,
                ':last_status' => $status,
                ':last_summary' => $summary,
                ':source' => 'push',
            ));
            $hostId = (int) $pdo->lastInsertId();
        }
        if ($hostId <= 0) {
            throw new RuntimeException('Unable to create host record');
        }

        $updateHost = $pdo->prepare(
            'UPDATE hosts SET agent_version=:agent_version, os=:os, kernel=:kernel,
             arch=:arch, last_seen=:last_seen, last_status=:last_status,
             last_summary=:last_summary, source=:source WHERE id=:id'
        );
        $updateHost->execute(array(
            ':agent_version' => $report['agent_version'],
            ':os' => $metrics['os']['os'],
            ':kernel' => $metrics['os']['kernel'],
            ':arch' => $metrics['os']['arch'],
            ':last_seen' => $now,
            ':last_status' => $status,
            ':last_summary' => $summary,
            ':source' => 'push',
            ':id' => $hostId,
        ));

        $insertMetric = $pdo->prepare(
            'INSERT INTO metrics_raw
             (host_id, ts, cpu_usage, mem_usage, load1, payload)
             VALUES (:host_id, :ts, :cpu, :mem, :load1, :payload)'
        );
        $insertMetric->execute(array(
            ':host_id' => $hostId,
            ':ts' => $report['timestamp'],
            ':cpu' => $metrics['cpu']['usage'],
            ':mem' => $metrics['mem']['used_pct'],
            ':load1' => $metrics['cpu']['load1'],
            ':payload' => $payload,
        ));

        $findDaily = $pdo->prepare(
            'SELECT total_samples, degraded_samples FROM uptime_daily
             WHERE host_id=:host_id AND date=:date'
        );
        $findDaily->execute(array(':host_id' => $hostId, ':date' => $date));
        $daily = $findDaily->fetch();
        $isDegraded = $status === 'degraded' ? 1 : 0;
        if ($daily) {
            $updateDaily = $pdo->prepare(
                'UPDATE uptime_daily SET total_samples=total_samples+1,
                 degraded_samples=degraded_samples+:degraded WHERE host_id=:host_id AND date=:date'
            );
            $updateDaily->execute(array(
                ':degraded' => $isDegraded,
                ':host_id' => $hostId,
                ':date' => $date,
            ));
        } else {
            $insertDaily = $pdo->prepare(
                'INSERT INTO uptime_daily
                 (host_id, date, status, uptime_pct, total_samples, degraded_samples, down_samples)
                 VALUES (:host_id, :date, :status, :uptime, 1, :degraded, 0)'
            );
            $insertDaily->execute(array(
                ':host_id' => $hostId,
                ':date' => $date,
                ':status' => $status,
                ':uptime' => $isDegraded ? 0 : 100,
                ':degraded' => $isDegraded,
            ));
        }
        statuspigeon_recompute_daily($pdo, $hostId, $date);
        $pdo->commit();
        return $hostId;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function statuspigeon_recompute_daily($pdo, $hostId, $date)
{
    $stmt = $pdo->prepare(
        'SELECT total_samples, degraded_samples FROM uptime_daily
         WHERE host_id=:host_id AND date=:date'
    );
    $stmt->execute(array(':host_id' => $hostId, ':date' => $date));
    $row = $stmt->fetch();
    if (!$row || (int) $row['total_samples'] <= 0) {
        return;
    }
    $total = (int) $row['total_samples'];
    $degraded = (int) $row['degraded_samples'];
    $status = $degraded > 0 ? 'degraded' : 'operational';
    $uptime = ($total - $degraded) / $total * 100.0;
    $update = $pdo->prepare(
        'UPDATE uptime_daily SET status=:status, uptime_pct=:uptime
         WHERE host_id=:host_id AND date=:date'
    );
    $update->execute(array(
        ':status' => $status,
        ':uptime' => $uptime,
        ':host_id' => $hostId,
        ':date' => $date,
    ));
}

/** Run maintenance opportunistically because PHP shared hosting has no daemon. */
function statuspigeon_maintenance($pdo, $config)
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $period = max(1, (int) $config['report_interval'])
        * max(1, (int) $config['offline_periods']);
    $threshold = time() - $period;
    $pdo->beginTransaction();
    try {
        $offline = $pdo->prepare(
            'SELECT id, last_status FROM hosts WHERE last_seen < :threshold'
        );
        $offline->execute(array(':threshold' => $threshold));
        $hosts = $offline->fetchAll();
        $today = date('Y-m-d');
        foreach ($hosts as $host) {
            if ($host['last_status'] !== 'down') {
                $update = $pdo->prepare('UPDATE hosts SET last_status=\'down\' WHERE id=:id');
                $update->execute(array(':id' => (int) $host['id']));
            }
            // Idempotent: repeated page views must not inflate down_samples.
            $insert = $pdo->prepare(
                'INSERT OR IGNORE INTO uptime_daily
                 (host_id, date, status, uptime_pct, total_samples, degraded_samples, down_samples)
                 VALUES (:host_id, :date, \'down\', 0, 1, 0, 1)'
            );
            $insert->execute(array(':host_id' => (int) $host['id'], ':date' => $today));
            $mark = $pdo->prepare(
                'UPDATE uptime_daily SET status=\'down\', uptime_pct=0,
                 total_samples=CASE WHEN total_samples < 1 THEN 1 ELSE total_samples END,
                 down_samples=CASE WHEN down_samples < 1 THEN 1 ELSE down_samples END
                 WHERE host_id=:host_id AND date=:date'
            );
            $mark->execute(array(':host_id' => (int) $host['id'], ':date' => $today));
        }
        $pdo->commit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Status Pigeon offline maintenance failed: ' . $e->getMessage());
    }

    $retention = max(1, (int) $config['retention_days']);
    $cutoff = time() - ($retention * 86400);
    $cutoffDate = date('Y-m-d', $cutoff);
    try {
        $raw = $pdo->prepare('DELETE FROM metrics_raw WHERE ts < :cutoff');
        $raw->execute(array(':cutoff' => $cutoff));
        $daily = $pdo->prepare('DELETE FROM uptime_daily WHERE date < :cutoff_date');
        $daily->execute(array(':cutoff_date' => $cutoffDate));
    } catch (Exception $e) {
        error_log('Status Pigeon cleanup failed: ' . $e->getMessage());
    }
}

function statuspigeon_hosts($pdo)
{
    $query = $pdo->query(
        'SELECT id, COALESCE(device_id, \'\') AS device_id, hostname, COALESCE(os, \'\') AS os,
                COALESCE(kernel, \'\') AS kernel, COALESCE(arch, \'\') AS arch,
                COALESCE(agent_version, \'\') AS agent_version, last_seen,
                COALESCE(last_status, \'\') AS last_status,
                COALESCE(last_summary, \'{}\') AS last_summary, source
         FROM hosts ORDER BY hostname'
    );
    $out = array();
    foreach ($query->fetchAll() as $row) {
        $summary = json_decode((string) $row['last_summary'], true);
        $summary = is_array($summary) ? $summary : array();
        $out[] = array(
            'id' => (int) $row['id'],
            'device_id' => (string) $row['device_id'],
            'hostname' => (string) $row['hostname'],
            'os' => (string) $row['os'],
            'kernel' => (string) $row['kernel'],
            'arch' => (string) $row['arch'],
            'agent_version' => (string) $row['agent_version'],
            'last_seen' => (int) $row['last_seen'],
            'last_status' => (string) $row['last_status'],
            'last_summary' => (string) $row['last_summary'],
            'os_version' => isset($summary['os_version']) ? (string) $summary['os_version'] : '',
            'ipv4' => isset($summary['ipv4']) && is_array($summary['ipv4'])
                ? $summary['ipv4'] : array(),
            'ipv6' => isset($summary['ipv6']) && is_array($summary['ipv6'])
                ? $summary['ipv6'] : array(),
            'source' => (string) $row['source'],
        );
    }
    return $out;
}

function statuspigeon_recent_reports($pdo, $limit)
{
    $limit = max(1, min(500, (int) $limit));
    $query = $pdo->query(
        'SELECT metrics_raw.ts, metrics_raw.cpu_usage, metrics_raw.mem_usage,
                metrics_raw.load1, hosts.hostname, hosts.last_status
         FROM metrics_raw
         INNER JOIN hosts ON hosts.id = metrics_raw.host_id
         ORDER BY metrics_raw.ts DESC, metrics_raw.id DESC
         LIMIT ' . $limit
    );
    return $query->fetchAll();
}

function statuspigeon_daily($pdo, $hostId, $days)
{
    $days = max(1, min(365, (int) $days));
    $start = new DateTime('today');
    $start->modify('-' . ($days - 1) . ' days');
    $startDate = $start->format('Y-m-d');

    $stmt = $pdo->prepare(
        'SELECT date, status, uptime_pct FROM uptime_daily
         WHERE host_id=:host_id AND date >= :start_date ORDER BY date'
    );
    $stmt->execute(array(':host_id' => (int) $hostId, ':start_date' => $startDate));
    $byDate = array();
    foreach ($stmt->fetchAll() as $row) {
        $byDate[$row['date']] = array(
            'date' => (string) $row['date'],
            'status' => (string) $row['status'],
            'uptime' => (float) $row['uptime_pct'],
        );
    }

    $out = array();
    for ($i = 0; $i < $days; $i++) {
        $day = clone $start;
        if ($i > 0) {
            $day->modify('+' . $i . ' days');
        }
        $date = $day->format('Y-m-d');
        $out[] = isset($byDate[$date]) ? $byDate[$date] : array(
            'date' => $date,
            'status' => 'no-data',
            'uptime' => 0,
        );
    }
    return $out;
}

function statuspigeon_metrics_series($pdo, $hostId, $fromTs)
{
    $stmt = $pdo->prepare(
        'SELECT ts, cpu_usage, mem_usage, load1
         FROM metrics_raw
         WHERE host_id=:host_id AND ts >= :from_ts ORDER BY ts ASC LIMIT 10000'
    );
    $stmt->execute(array(':host_id' => (int) $hostId, ':from_ts' => (int) $fromTs));
    $out = array();
    foreach ($stmt->fetchAll() as $row) {
        $out[] = array(
            'ts' => (int) $row['ts'],
            'cpu' => $row['cpu_usage'] === null ? null : (float) $row['cpu_usage'],
            'mem' => $row['mem_usage'] === null ? null : (float) $row['mem_usage'],
            'load1' => $row['load1'] === null ? null : (float) $row['load1'],
        );
    }
    return $out;
}
