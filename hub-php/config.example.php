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

	// Secure default: an empty api_key rejects reports. Set this to true only
	// for an isolated development network that deliberately has no auth.
	'allow_unauthenticated_reports' => false,

    // Optional independent admin password hash.  If empty, the current
    // api_key is accepted as the first admin credential.  The admin page can
    // create this hash after the first login.
    'admin_password_hash' => '',

    // Optional public URL used by the admin page to display the full report
    // address. Leave empty to detect it from the current request.
    'public_base_url' => '',

    // Prefer a path outside the public document root.
    'db_path' => dirname(__DIR__) . '/statuspigeon-data/statuspigeon.sqlite',

    // Report timestamps are displayed in this timezone.
    'timezone' => 'Asia/Shanghai',
    // Raw samples are kept this long; the metrics charts look back at most
    // 7 days, so 7 is the smallest useful value. Daily uptime aggregates
    // are kept for uptime_bar_days regardless of this setting.
    'retention_days' => 30,
    'uptime_bar_days' => 30,
    // Deprecated compatibility setting; CPU percentage is no longer used.
    'degraded_cpu' => 90.0,
    'degraded_mem' => 95.0,
    'report_interval' => 300,
    'offline_periods' => 3,
    'max_report_bytes' => 1048576,

    // Deleted rows stay inside the SQLite file until a VACUUM rewrites it;
    // this controls how often that happens (1-3650 days).
    'vacuum_interval_days' => 7,

    // Admin login protection. Five failures from one source within 15 minutes
    // trigger a 15-minute temporary lock; failed attempts also get a capped
    // exponential delay. The security log is retained for 90 days.
    'login_max_failures' => 5,
    'login_window_seconds' => 900,
    'login_lockout_seconds' => 900,
    'login_delay_base_ms' => 250,
    'login_delay_max_ms' => 8000,
    'login_audit_retention_days' => 90,
);
