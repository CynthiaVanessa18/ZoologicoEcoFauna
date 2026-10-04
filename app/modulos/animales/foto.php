<?php
require_once __DIR__ . '/../../soporte/sesion.php';
if (!isset($_SESSION['usuario'], $_SESSION['id_login']) || !in_array($_SESSION['rol'] ?? '', ['Administrador', 'Cliente'], true)) {
    http_response_code(403);
    exit;
}
$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$id) {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/funciones.php';
try {
    $stmt = consultaAnimal($conexion, 'SELECT foto FROM animal WHERE id_animal=?', 'i', [$id]);
    $animal = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    $foto = $animal['foto'] ?? '';
    $mime = validarContenidoFotoAnimal($foto);
    header('Content-Type: ' . $mime);
    header('X-Content-Type-Options: nosniff');
    header('Content-Length: ' . strlen($foto));
    echo $foto;
} catch (DomainException $excepcion) {
    http_response_code(404);
} catch (Throwable $excepcion) {
    error_log('Fotografía de animal: ' . $excepcion->getMessage());
    http_response_code(500);
}
