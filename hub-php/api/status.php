<?php
require_once dirname(__DIR__) . '/lib/bootstrap.php';
statuspigeon_require_get();

$days = isset($_GET['days']) && is_numeric($_GET['days']) ? (int) $_GET['days'] : (int) $config['uptime_bar_days'];
$days = max(1, min(365, $days));
$out = array();
foreach (statuspigeon_hosts($pdo) as $host) {
    $daily = statuspigeon_daily($pdo, $host['id'], $days);
    $total = 0.0;
    $uptime = 0.0;
    foreach ($daily as $point) {
        if ($point['status'] === 'no-data') {
            continue;
        }
        $total += 1.0;
        if ($point['status'] === 'operational') {
            $uptime += 1.0;
		} elseif ($point['status'] === 'degraded' || $point['status'] === 'down') {
            $uptime += ((float) $point['uptime']) / 100.0;
        }
    }
	$out[] = array(
		'id' => (int) $host['id'],
		'hostname' => (string) $host['hostname'],
		'last_seen' => (int) $host['last_seen'],
		'last_status' => (string) $host['last_status'],
		'last_summary' => statuspigeon_public_summary($host['last_summary']),
		'daily' => $daily,
		'uptime_pct' => $total > 0 ? $uptime / $total * 100.0 : 0.0,
	);
}
statuspigeon_json_response($out, 200);
