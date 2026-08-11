<?php
require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once dirname(__DIR__) . '/lib/auth.php';
statuspigeon_admin_require_api();
statuspigeon_require_get();

$id = isset($_GET['id']) && is_numeric($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id <= 0) {
    statuspigeon_text_error('invalid id', 400);
}
$range = isset($_GET['range']) ? $_GET['range'] : '24h';
$from = time() - statuspigeon_range_seconds($range);
try {
    statuspigeon_json_response(statuspigeon_metrics_series($pdo, $id, $from), 200);
} catch (Exception $e) {
    error_log('Status Pigeon metrics query failed: ' . $e->getMessage());
    statuspigeon_json_response(array('error' => 'query failed'), 500);
}
