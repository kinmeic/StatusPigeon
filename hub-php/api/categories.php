<?php
require_once dirname(__DIR__) . '/lib/bootstrap.php';
statuspigeon_require_get();

$out = array();
foreach (statuspigeon_categories($pdo) as $category) {
    $out[] = array(
        'id' => (int) $category['id'],
        'name' => (string) $category['name'],
    );
}
statuspigeon_json_response($out, 200);
