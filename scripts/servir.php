<?php
// Router para el servidor local de PHP; usa public/ como raíz pública.
if (PHP_SAPI !== 'cli-server') {
    http_response_code(404);
    exit;
}
$_SERVER['SCRIPT_NAME'] = '/index.php';
require dirname(__DIR__) . '/public/index.php';
