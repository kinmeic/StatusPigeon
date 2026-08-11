<?php
/** POST /report/ or /report/index.php — shared Report JSON receiver. */
require_once dirname(__DIR__) . '/lib/bootstrap.php';

if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    statuspigeon_text_error('method not allowed', 405);
}
statuspigeon_require_api_key($config);
statuspigeon_require_json_content_type();

$body = statuspigeon_request_body($config);
$decoded = json_decode($body, true);
if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
    statuspigeon_text_error('invalid json', 400);
}

$report = statuspigeon_normalize_report($decoded);
if ($report['hostname'] === '' || $report['timestamp'] <= 0) {
    statuspigeon_text_error('missing hostname/timestamp', 400);
}
$validationError = statuspigeon_validate_report($report);
if ($validationError !== '') {
	statuspigeon_text_error('invalid report: ' . $validationError, 400);
}
if (abs(time() - $report['timestamp']) > 300) {
    statuspigeon_text_error('timestamp out of range', 400);
}

try {
    statuspigeon_ingest($pdo, $report, $config, statuspigeon_request_remote_ip());
} catch (Exception $e) {
    error_log('Status Pigeon ingest failed: ' . $e->getMessage());
    statuspigeon_text_error('ingest failed', 500);
}

statuspigeon_json_response(array('status' => 'ok'), 200);
