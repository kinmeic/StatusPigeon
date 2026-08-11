<?php
require_once dirname(__DIR__) . '/lib/bootstrap.php';
statuspigeon_require_get();
statuspigeon_json_response(statuspigeon_hosts($pdo), 200);
