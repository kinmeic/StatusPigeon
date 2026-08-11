<?php
require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once dirname(__DIR__) . '/lib/auth.php';
statuspigeon_admin_require_api();
statuspigeon_require_get();
statuspigeon_json_response(statuspigeon_hosts($pdo), 200);
