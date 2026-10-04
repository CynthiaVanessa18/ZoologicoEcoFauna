<?php

require_once dirname(__DIR__) . '/app/soporte/router.php';

$base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/index.php')), '/.');
$solicitud = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
if ($base !== '' && !str_starts_with($solicitud, $base . '/')) {
    http_response_code(404);
    exit('Página no encontrada.');
}
$ruta = ltrim(substr($solicitud, strlen($base)), '/');
if (str_contains($ruta, "\0") || str_contains($ruta, '\\') || preg_match('~(^|/)\.\.?(/|$)~', $ruta)) {
    http_response_code(404);
    exit('Página no encontrada.');
}
$ruta = $ruta === '' ? 'index.php' : $ruta;
if ($ruta === 'estilo.css') {
    header('Location: ' . $base . '/assets/css/estilo.css', true, 302);
    exit;
}
$archivo = recursoPublico($ruta);
if ($archivo !== null) {
    $tipos = ['css' => 'text/css; charset=UTF-8', 'js' => 'text/javascript; charset=UTF-8', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp', 'avif' => 'image/avif', 'ico' => 'image/x-icon', 'woff' => 'font/woff', 'woff2' => 'font/woff2', 'ttf' => 'font/ttf'];
    header('Content-Type: ' . $tipos[strtolower(pathinfo($archivo, PATHINFO_EXTENSION))]);
    header('X-Content-Type-Options: nosniff');
    header('Content-Length: ' . filesize($archivo));
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') {
        readfile($archivo);
    }
    exit;
}
$rutas = require dirname(__DIR__) . '/config/rutas.php';
if (!isset($rutas[$ruta])) {
    http_response_code(404);
    exit('Página no encontrada.');
}
// Mantiene los formularios y redirecciones que usan la URL de la página actual.
$_SERVER['PHP_SELF'] = $base . '/' . $ruta;
require dirname(__DIR__) . '/' . $rutas[$ruta];
