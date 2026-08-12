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
	if (is_file($path)) {
		@chmod($path, 0640);
	}
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
            remote_ip TEXT,
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
        'CREATE TABLE IF NOT EXISTS admin_login_rate_limits (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ip_hash TEXT UNIQUE NOT NULL,
            ip TEXT NOT NULL,
            failed_count INTEGER NOT NULL DEFAULT 0,
            window_started_at INTEGER NOT NULL DEFAULT 0,
            last_failed_at INTEGER NOT NULL DEFAULT 0,
            locked_until INTEGER NOT NULL DEFAULT 0
        )',
        'CREATE INDEX IF NOT EXISTS idx_admin_login_rate_cleanup
            ON admin_login_rate_limits(locked_until, last_failed_at)',
        'CREATE TABLE IF NOT EXISTS admin_login_audit (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ts INTEGER NOT NULL,
            ip TEXT NOT NULL,
            outcome TEXT NOT NULL,
            failed_count INTEGER NOT NULL DEFAULT 0,
            retry_after INTEGER NOT NULL DEFAULT 0,
            user_agent TEXT NOT NULL DEFAULT \'\'
        )',
        'CREATE INDEX IF NOT EXISTS idx_admin_login_audit_ts ON admin_login_audit(ts)',
        'CREATE INDEX IF NOT EXISTS idx_admin_login_audit_ip_ts ON admin_login_audit(ip, ts)',
    );
    foreach ($schema as $statement) {
        $pdo->exec($statement);
    }
    statuspigeon_migrate_host_identity($pdo);
    statuspigeon_ensure_host_remote_ip($pdo);
	statuspigeon_ensure_metric_uniqueness($pdo);
    // Existing databases may still contain legacy I/O columns.  They are
    // intentionally left in place for non-destructive upgrades, but no new
    // report reads or writes those columns.
    return $pdo;
}

function statuspigeon_ensure_metric_uniqueness($pdo)
{
	$indexes = $pdo->query('PRAGMA index_list(metrics_raw)')->fetchAll();
	foreach ($indexes as $index) {
		if (isset($index['name']) && $index['name'] === 'idx_raw_host_ts_payload_unique') {
			return;
		}
	}
	$pdo->exec('DROP INDEX IF EXISTS idx_raw_host_ts_unique');
	$pdo->exec(
		'DELETE FROM metrics_raw
		 WHERE id NOT IN (SELECT MIN(id) FROM metrics_raw GROUP BY host_id, ts, payload)'
	);
	$pdo->exec(
		'CREATE UNIQUE INDEX IF NOT EXISTS idx_raw_host_ts_payload_unique
		 ON metrics_raw(host_id, ts, payload)'
	);
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
    $hasRemoteIp = isset($columnNames['remote_ip']);
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
                remote_ip TEXT,
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
        $remoteIpExpression = $hasRemoteIp ? 'remote_ip' : 'NULL';
        $pdo->exec(
            'INSERT INTO hosts_new
             (id, device_id, hostname, agent_version, os, kernel, arch, remote_ip, last_seen,
              created_at, last_status, last_summary, source)
             SELECT id, ' . $deviceExpression . ', hostname, agent_version, os,
                    kernel, arch, ' . $remoteIpExpression . ', last_seen, created_at, last_status, last_summary,
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

function statuspigeon_ensure_host_remote_ip($pdo)
{
    $columns = $pdo->query('PRAGMA table_info(hosts)')->fetchAll();
    foreach ($columns as $column) {
        if (isset($column['name']) && $column['name'] === 'remote_ip') {
            return;
        }
    }
    $pdo->exec('ALTER TABLE hosts ADD COLUMN remote_ip TEXT');
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

/**
 * Return a globally routable IP address, optionally restricted to one family.
 * Private/reserved addresses are not useful as a public Hub observation and
 * are commonly produced by an internal reverse proxy or application gateway.
 */
function statuspigeon_public_ip($value, $family = 0)
{
    $value = trim((string) $value);
    if ($value === '') {
        return '';
    }
    $flags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
    if ((int) $family === 4) {
        $flags |= FILTER_FLAG_IPV4;
    } elseif ((int) $family === 6) {
        $flags |= FILTER_FLAG_IPV6;
    }
    $validated = filter_var($value, FILTER_VALIDATE_IP, $flags);
    return $validated === false ? '' : (string) $validated;
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

function statuspigeon_ip_address_part($value)
{
    $value = trim((string) $value);
    $separator = strrpos($value, '@');
    if ($separator !== false && $separator > 0) {
        return trim(substr($value, 0, $separator));
    }
    return $value;
}

/** Merge agent addresses with the PHP server's observed source address. */
function statuspigeon_host_ip_list($values, $remoteIp, $family)
{
    $out = array();
    $seen = array();
    foreach (is_array($values) ? $values : array() as $value) {
        $value = trim((string) $value);
        $address = statuspigeon_ip_address_part($value);
        if ($address === '') {
            continue;
        }
        $flag = $family === 6 ? FILTER_FLAG_IPV6 : FILTER_FLAG_IPV4;
        if (filter_var($address, FILTER_VALIDATE_IP, $flag) === false) {
            continue;
        }
        $key = strtolower($address);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $out[] = $value;
    }

    $remoteIp = statuspigeon_public_ip($remoteIp, $family);
    if ($remoteIp !== '') {
        $key = strtolower($remoteIp);
        if (!isset($seen[$key])) {
            // The actual network interface is unknown to the Hub. @hub marks
            // a public address observed through the Hub/proxy chain.
			// Preserve the agent's WAN-first interface ordering. The Hub-observed
			// address is a fallback observation and belongs at the end.
			$out[] = $remoteIp . '@hub';
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
                'cpu_model' => statuspigeon_string(isset($rawOS['cpu_model']) ? $rawOS['cpu_model'] : '', ''),
                'memory_total' => statuspigeon_metric_int(
                    isset($rawOS['memory_total']) ? $rawOS['memory_total']
                        : (isset($rawMem['total']) ? $rawMem['total'] : 0),
                    0
                ),
                'disk_total' => array_key_exists('disk_total', $rawOS)
                    ? statuspigeon_metric_int($rawOS['disk_total'], 0) : null,
                'disk_used_pct' => array_key_exists('disk_used_pct', $rawOS)
                    ? statuspigeon_metric_number($rawOS['disk_used_pct'], 0) : null,
                'ipv4' => statuspigeon_string_array(isset($rawOS['ipv4']) ? $rawOS['ipv4'] : array()),
                'ipv6' => statuspigeon_string_array(isset($rawOS['ipv6']) ? $rawOS['ipv6'] : array()),
            ),
            'cpu' => array(
                'load1' => statuspigeon_metric_number(isset($rawCPU['load1']) ? $rawCPU['load1'] : 0, 0),
                'load5' => statuspigeon_metric_number(isset($rawCPU['load5']) ? $rawCPU['load5'] : 0, 0),
                'load15' => statuspigeon_metric_number(isset($rawCPU['load15']) ? $rawCPU['load15'] : 0, 0),
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

function statuspigeon_validate_string_field($name, $value, $max, $required)
{
	$value = (string) $value;
	if ($required && trim($value) === '') {
		return $name . ' is required';
	}
	if (strlen($value) > (int) $max) {
		return $name . ' is too long';
	}
	if (preg_match('//u', $value) !== 1) {
		return $name . ' is not valid UTF-8';
	}
	if (preg_match('/[\x00-\x1F\x7F]/', $value)) {
		return $name . ' contains control characters';
	}
	return '';
}

function statuspigeon_validate_ip_list($name, $values, $family)
{
	if (!is_array($values) || count($values) > 64) {
		return $name . ' has too many entries';
	}
	foreach ($values as $value) {
		$error = statuspigeon_validate_string_field($name, $value, 192, false);
		if ($error !== '') {
			return $error;
		}
		$address = statuspigeon_ip_address_part($value);
		$flag = (int) $family === 6 ? FILTER_FLAG_IPV6 : FILTER_FLAG_IPV4;
		if (filter_var($address, FILTER_VALIDATE_IP, $flag) === false) {
			return $name . ' contains an invalid address';
		}
	}
	return '';
}

/** Validate normalized, untrusted Report fields. Returns an empty string on success. */
function statuspigeon_validate_report($report)
{
	$checks = array(
		array('hostname', $report['hostname'], 255, true),
		array('device_id', $report['device_id'], 512, false),
		array('agent_version', $report['agent_version'], 128, false),
		array('metrics.os.os', $report['metrics']['os']['os'], 512, false),
		array('metrics.os.version', $report['metrics']['os']['version'], 512, false),
		array('metrics.os.kernel', $report['metrics']['os']['kernel'], 512, false),
		array('metrics.os.arch', $report['metrics']['os']['arch'], 512, false),
		array('metrics.os.cpu_model', $report['metrics']['os']['cpu_model'], 512, false),
	);
	foreach ($checks as $check) {
		$error = statuspigeon_validate_string_field($check[0], $check[1], $check[2], $check[3]);
		if ($error !== '') {
			return $error;
		}
	}
	$percentages = array(
		'metrics.mem.used_pct' => $report['metrics']['mem']['used_pct'],
		'metrics.os.disk_used_pct' => $report['metrics']['os']['disk_used_pct'],
	);
	foreach ($percentages as $name => $value) {
		if ($value !== null && ((float) $value < 0 || (float) $value > 100)) {
			return $name . ' is out of range';
		}
	}
	foreach (array('load1', 'load5', 'load15') as $field) {
		$value = (float) $report['metrics']['cpu'][$field];
		if ($value < 0 || $value > 1000000) {
			return 'metrics.cpu.' . $field . ' is out of range';
		}
	}
	$error = statuspigeon_validate_ip_list('metrics.os.ipv4', $report['metrics']['os']['ipv4'], 4);
	if ($error !== '') {
		return $error;
	}
	return statuspigeon_validate_ip_list('metrics.os.ipv6', $report['metrics']['os']['ipv6'], 6);
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
    $mem = $report['metrics']['mem']['used_pct'];
    if ($mem > (float) $config['degraded_mem']) {
        return 'degraded';
    }
    return 'operational';
}

function statuspigeon_summary($metrics)
{
    $summary = array(
        'mem' => round((float) $metrics['mem']['used_pct'], 1),
        'load1' => round((float) $metrics['cpu']['load1'], 2),
        'uptime' => (int) $metrics['os']['uptime'],
        'os' => (string) $metrics['os']['os'],
        'os_version' => (string) $metrics['os']['version'],
        'cpu_model' => (string) $metrics['os']['cpu_model'],
        'memory_total' => (int) $metrics['os']['memory_total'],
        'disk_total' => $metrics['os']['disk_total'] === null
            ? null : (int) $metrics['os']['disk_total'],
        'disk_used_pct' => $metrics['os']['disk_used_pct'] === null
            ? null : round((float) $metrics['os']['disk_used_pct'], 1),
        'ipv4' => $metrics['os']['ipv4'],
        'ipv6' => $metrics['os']['ipv6'],
    );
    return json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function statuspigeon_public_summary($rawSummary)
{
	$summary = json_decode((string) $rawSummary, true);
	if (!is_array($summary)) {
		return '{}';
	}
	$public = array(
		'mem' => isset($summary['mem']) ? (float) $summary['mem'] : 0.0,
		'load1' => isset($summary['load1']) ? (float) $summary['load1'] : 0.0,
		'os' => isset($summary['os']) ? (string) $summary['os'] : '',
	);
	return statuspigeon_json_encode($public);
}

function statuspigeon_json_encode($value)
{
    $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('Unable to encode JSON');
    }
    return $json;
}

function statuspigeon_ingest($pdo, $report, $config, $remoteIp = '')
{
    $status = statuspigeon_status_for_report($report, $config);
    $metrics = $report['metrics'];
    $summary = statuspigeon_summary($metrics);
    $now = time();
    $date = date('Y-m-d', $report['timestamp']);
    $payload = statuspigeon_json_encode($report);
    $deviceId = statuspigeon_report_device_id($report);
    $remoteIp = statuspigeon_public_ip($remoteIp);
    if ($remoteIp === '') {
        $remoteIp = null;
    }

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
                 (device_id, hostname, agent_version, os, kernel, arch, remote_ip, last_seen,
                  created_at, last_status, last_summary, source)
                 VALUES (:device_id, :hostname, :agent_version, :os, :kernel, :arch, :remote_ip,
                         :last_seen, :created_at, :last_status, :last_summary, :source)'
            );
            $insertHost->execute(array(
                ':device_id' => $deviceId,
                ':hostname' => $report['hostname'],
                ':agent_version' => $report['agent_version'],
                ':os' => $metrics['os']['os'],
                ':kernel' => $metrics['os']['kernel'],
                ':arch' => $metrics['os']['arch'],
                ':remote_ip' => $remoteIp,
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

		// Retries with the same host/timestamp are idempotent. Without this,
		// network retries inflate daily sample counts and distort uptime.
		$findMetric = $pdo->prepare(
			'SELECT id FROM metrics_raw
			 WHERE host_id=:host_id AND ts=:ts AND payload=:payload LIMIT 1'
		);
		$findMetric->execute(array(
			':host_id' => $hostId,
			':ts' => $report['timestamp'],
			':payload' => $payload,
		));
		if ($findMetric->fetchColumn() !== false) {
			$pdo->commit();
			return $hostId;
		}

        $updateHost = $pdo->prepare(
			'UPDATE hosts SET hostname=:hostname, agent_version=:agent_version, os=:os, kernel=:kernel,
             arch=:arch, remote_ip=:remote_ip, last_seen=:last_seen, last_status=:last_status,
             last_summary=:last_summary, source=:source WHERE id=:id'
        );
        $updateHost->execute(array(
			':hostname' => $report['hostname'],
            ':agent_version' => $report['agent_version'],
            ':os' => $metrics['os']['os'],
            ':kernel' => $metrics['os']['kernel'],
            ':arch' => $metrics['os']['arch'],
            ':remote_ip' => $remoteIp,
            ':last_seen' => $now,
            ':last_status' => $status,
            ':last_summary' => $summary,
            ':source' => 'push',
            ':id' => $hostId,
        ));

        $insertMetric = $pdo->prepare(
			'INSERT OR IGNORE INTO metrics_raw
             (host_id, ts, cpu_usage, mem_usage, load1, payload)
             VALUES (:host_id, :ts, :cpu, :mem, :load1, :payload)'
        );
        $insertMetric->execute(array(
            ':host_id' => $hostId,
            ':ts' => $report['timestamp'],
            // cpu_usage is a legacy column; new reports do not store CPU
            // percentage even when an older client included that field.
            ':cpu' => null,
            ':mem' => $metrics['mem']['used_pct'],
            ':load1' => $metrics['cpu']['load1'],
            ':payload' => $payload,
        ));

        $findDaily = $pdo->prepare(
		'SELECT total_samples, degraded_samples, down_samples FROM uptime_daily
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
		'SELECT total_samples, degraded_samples, down_samples FROM uptime_daily
         WHERE host_id=:host_id AND date=:date'
    );
    $stmt->execute(array(':host_id' => $hostId, ':date' => $date));
    $row = $stmt->fetch();
    if (!$row || (int) $row['total_samples'] <= 0) {
        return;
    }
	$total = (int) $row['total_samples'];
	$degraded = (int) $row['degraded_samples'];
	$down = (int) $row['down_samples'];
	$status = $degraded > 0 ? 'degraded' : 'operational';
	if ($down > 0) {
		$status = 'down';
	}
	$uptime = max(0, $total - $degraded - $down) / $total * 100.0;
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

	$reportInterval = min(2592000, max(1, (int) $config['report_interval']));
	$offlinePeriods = min(1000, max(1, (int) $config['offline_periods']));
	$period = $reportInterval * $offlinePeriods;
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
				 total_samples=CASE WHEN down_samples < 1 THEN total_samples + 1 ELSE total_samples END,
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

	$retention = min(36500, max(1, (int) $config['retention_days']));
    $cutoff = time() - ($retention * 86400);
    $cutoffDate = date('Y-m-d', $cutoff);
	try {
		$raw = $pdo->prepare('DELETE FROM metrics_raw WHERE ts < :cutoff');
		$raw->execute(array(':cutoff' => $cutoff));
		$daily = $pdo->prepare('DELETE FROM uptime_daily WHERE date < :cutoff_date');
		$daily->execute(array(':cutoff_date' => $cutoffDate));
		$loginAuditRetention = isset($config['login_audit_retention_days'])
			? (int) $config['login_audit_retention_days'] : $retention;
		$loginAuditRetention = min(3650, max(1, $loginAuditRetention));
		$loginAuditCutoff = time() - ($loginAuditRetention * 86400);
		$loginAudit = $pdo->prepare('DELETE FROM admin_login_audit WHERE ts < :cutoff');
		$loginAudit->execute(array(':cutoff' => $loginAuditCutoff));
		$loginRate = $pdo->prepare(
			'DELETE FROM admin_login_rate_limits
			 WHERE locked_until < :now AND last_failed_at < :cutoff'
		);
		$loginRate->execute(array(':now' => time(), ':cutoff' => $loginAuditCutoff));
	} catch (Exception $e) {
        error_log('Status Pigeon cleanup failed: ' . $e->getMessage());
    }
}

function statuspigeon_hosts($pdo)
{
    $query = $pdo->query(
        'SELECT id, COALESCE(device_id, \'\') AS device_id, hostname, COALESCE(os, \'\') AS os,
                COALESCE(kernel, \'\') AS kernel, COALESCE(arch, \'\') AS arch,
                COALESCE(agent_version, \'\') AS agent_version, COALESCE(remote_ip, \'\') AS remote_ip,
                last_seen,
                COALESCE(last_status, \'\') AS last_status,
                COALESCE(last_summary, \'{}\') AS last_summary, source
         FROM hosts ORDER BY hostname'
    );
    $out = array();
    foreach ($query->fetchAll() as $row) {
        $summary = json_decode((string) $row['last_summary'], true);
        $summary = is_array($summary) ? $summary : array();
        $agentIPv4 = isset($summary['ipv4']) && is_array($summary['ipv4'])
            ? $summary['ipv4'] : array();
        $agentIPv6 = isset($summary['ipv6']) && is_array($summary['ipv6'])
            ? $summary['ipv6'] : array();
        $out[] = array(
            'id' => (int) $row['id'],
            'device_id' => (string) $row['device_id'],
            'hostname' => (string) $row['hostname'],
            'os' => (string) $row['os'],
            'kernel' => (string) $row['kernel'],
            'arch' => (string) $row['arch'],
            'agent_version' => (string) $row['agent_version'],
            'remote_ip' => (string) $row['remote_ip'],
            'last_seen' => (int) $row['last_seen'],
            'last_status' => (string) $row['last_status'],
            'last_summary' => (string) $row['last_summary'],
            'os_version' => isset($summary['os_version']) ? (string) $summary['os_version'] : '',
            'cpu_model' => isset($summary['cpu_model']) ? (string) $summary['cpu_model'] : '',
            'memory_total' => isset($summary['memory_total']) ? (int) $summary['memory_total'] : 0,
            'disk_total' => isset($summary['disk_total']) ? (int) $summary['disk_total'] : 0,
            'disk_used_pct' => isset($summary['disk_used_pct']) ? (float) $summary['disk_used_pct'] : null,
            'ipv4' => statuspigeon_host_ip_list($agentIPv4, $row['remote_ip'], 4),
            'ipv6' => statuspigeon_host_ip_list($agentIPv6, $row['remote_ip'], 6),
            'source' => (string) $row['source'],
        );
    }
    return $out;
}

function statuspigeon_recent_reports($pdo, $limit, $offset = 0)
{
    $limit = max(1, min(500, (int) $limit));
    $offset = max(0, (int) $offset);
    $query = $pdo->query(
        'SELECT metrics_raw.ts, metrics_raw.mem_usage, metrics_raw.load1,
                hosts.hostname, hosts.last_status
         FROM metrics_raw
         INNER JOIN hosts ON hosts.id = metrics_raw.host_id
         ORDER BY metrics_raw.ts DESC, metrics_raw.id DESC
         LIMIT ' . $limit . ' OFFSET ' . $offset
    );
    return $query->fetchAll();
}

function statuspigeon_reports_count($pdo)
{
    $query = $pdo->query('SELECT COUNT(*) FROM metrics_raw');
    return (int) $query->fetchColumn();
}

/** List every known device for the admin device-management page. */
function statuspigeon_devices($pdo)
{
    $query = $pdo->query(
        'SELECT id, device_id, hostname, agent_version, os, remote_ip,
                last_seen, last_status, source, created_at
         FROM hosts ORDER BY hostname COLLATE NOCASE, id'
    );
    return $query->fetchAll();
}

/**
 * Delete a device and every row derived from its reports.  A later report
 * from the same device re-registers it as a brand-new host.
 */
function statuspigeon_delete_device($pdo, $hostId)
{
    $hostId = (int) $hostId;
    if ($hostId <= 0) {
        throw new InvalidArgumentException('invalid host id');
    }
    $pdo->beginTransaction();
    try {
        foreach (array('metrics_raw', 'uptime_daily') as $table) {
            $stmt = $pdo->prepare('DELETE FROM ' . $table . ' WHERE host_id = :host_id');
            $stmt->execute(array(':host_id' => $hostId));
        }
        $stmt = $pdo->prepare('DELETE FROM hosts WHERE id = :host_id');
        $stmt->execute(array(':host_id' => $hostId));
        if ($stmt->rowCount() === 0) {
            throw new RuntimeException('设备不存在或已被删除');
        }
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
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
        'SELECT ts, mem_usage, load1, payload
         FROM metrics_raw
         WHERE host_id=:host_id AND ts >= :from_ts ORDER BY ts ASC LIMIT 10000'
    );
    $stmt->execute(array(':host_id' => (int) $hostId, ':from_ts' => (int) $fromTs));
    $out = array();
    foreach ($stmt->fetchAll() as $row) {
        $payload = json_decode((string) $row['payload'], true);
        $payload = is_array($payload) ? $payload : array();
        $os = isset($payload['metrics']['os']) && is_array($payload['metrics']['os'])
            ? $payload['metrics']['os'] : array();
        $out[] = array(
            'ts' => (int) $row['ts'],
            'mem' => $row['mem_usage'] === null ? null : (float) $row['mem_usage'],
            'load1' => $row['load1'] === null ? null : (float) $row['load1'],
            'disk_used_pct' => isset($os['disk_used_pct'])
                ? statuspigeon_metric_number($os['disk_used_pct'], 0) : null,
        );
    }
    return $out;
}
