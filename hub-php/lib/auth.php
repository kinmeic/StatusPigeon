<?php
/** Small session helpers shared by the admin and protected detail pages. */

function statuspigeon_admin_session_start()
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_name('statuspigeon_admin');
        @session_start();
    }
}

function statuspigeon_admin_logged_in()
{
    statuspigeon_admin_session_start();
    return !empty($_SESSION['statuspigeon_admin_logged_in']);
}

/** Allow only a local PHP page as a post-login destination. */
function statuspigeon_admin_return_url($value)
{
    $value = trim((string) $value);
    if ($value === '' || strpos($value, "\n") !== false || strpos($value, "\r") !== false) {
        return 'admin.php';
    }
    if ($value[0] === '/' || preg_match('/^[a-z][a-z0-9+.-]*:/i', $value)) {
        return 'admin.php';
    }
    return $value;
}

function statuspigeon_admin_require_page()
{
    statuspigeon_admin_session_start();
    if (statuspigeon_admin_logged_in()) {
        return;
    }

    $script = isset($_SERVER['SCRIPT_NAME']) ? basename((string) $_SERVER['SCRIPT_NAME']) : 'admin.php';
    $query = isset($_SERVER['QUERY_STRING']) ? trim((string) $_SERVER['QUERY_STRING']) : '';
    $returnUrl = $script . ($query === '' ? '' : '?' . $query);
    header('Location: admin.php?return=' . rawurlencode(statuspigeon_admin_return_url($returnUrl)));
    exit;
}

function statuspigeon_admin_require_api()
{
    if (!statuspigeon_admin_logged_in()) {
        statuspigeon_text_error('unauthorized', 401);
    }
}
