<?php
/**
 * Status Pigeon PHP Hub configuration.
 *
 * Copy this file to config.php and change api_key before deploying.  The file
 * is PHP rather than JSON so it can be kept outside the public document root
 * when the hosting provider allows that.
 */
return array(
    // Must match the Authorization: Bearer ... value used by an agent.
    'api_key' => 'change-me-to-a-long-random-secret',

    // Optional independent admin password hash.  If empty, the current
    // api_key is accepted as the first admin credential.  The admin page can
    // create this hash after the first login.
    'admin_password_hash' => '',

    // Prefer a path outside the public document root.
    'db_path' => dirname(__DIR__) . '/statuspigeon-data/statuspigeon.sqlite',

    'timezone' => 'UTC',
    'retention_days' => 90,
    'uptime_bar_days' => 90,
    'degraded_cpu' => 90.0,
    'degraded_mem' => 95.0,
    'report_interval' => 300,
    'offline_periods' => 3,
    'max_report_bytes' => 1048576,
);
