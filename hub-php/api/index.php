<?php
/** Optional single-file API dispatcher for hosts without directory aliases. */
$resource = isset($_GET['resource']) ? strtolower((string) $_GET['resource']) : 'status';
switch ($resource) {
    case 'hosts':
        require __DIR__ . '/hosts.php';
        break;
    case 'metrics':
        require __DIR__ . '/metrics.php';
        break;
    case 'status':
    default:
        require __DIR__ . '/status.php';
        break;
}
