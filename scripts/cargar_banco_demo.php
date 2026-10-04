<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
if (($argv[1] ?? '') !== '--demo') {
    fwrite(STDERR, "Uso: php scripts/cargar_banco_demo.php --demo\n");
    exit(1);
}
require_once __DIR__ . "/../config/database.php";
$clave = claveCifradoEcoFauna();
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function cifrarTarjetaDemo(string $dato, string $clave): string
{
    $iv = random_bytes(12);
    $tag = '';
    $cifrado = openssl_encrypt($dato, 'aes-256-gcm', hash('sha256', $clave, true), OPENSSL_RAW_DATA, $iv, $tag);
    if ($cifrado === false) {
        throw new RuntimeException('No fue posible cifrar los datos de prueba.');
    }
    return base64_encode($iv . $tag . $cifrado);
}

try {
    mysqli_begin_transaction($conexion);
    $cantidad = mysqli_fetch_row(mysqli_query($conexion, 'SELECT COUNT(*) FROM tarjeta_banco'))[0];
    if ((int) $cantidad > 0) {
        mysqli_rollback($conexion);
        fwrite(STDERR, "El banco ya contiene registros; no se modificaron.\n");
        exit(1);
    }
    $stmt = mysqli_prepare($conexion, 'INSERT INTO tarjeta_banco (banco, numero_tarjeta, titular, fecha_vencimiento, tipo_tarjeta, cvc, estado) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $banco = 'Banco Demo';
    $numero = cifrarTarjetaDemo('4111111111111111', $clave);
    $titular = 'Cliente Demo';
    $fecha = '2035-12-01';
    $tipo = 'Credito';
    $cvc = cifrarTarjetaDemo('123', $clave);
    $estado = 'Activa';
    mysqli_stmt_bind_param($stmt, 'sssssss', $banco, $numero, $titular, $fecha, $tipo, $cvc, $estado);
    mysqli_stmt_execute($stmt);
    mysqli_commit($conexion);
    echo "Tarjeta ficticia creada: 4111111111111111; Cliente Demo; 12/2035; CVC 123.\n";
} catch (Throwable $error) {
    mysqli_rollback($conexion);
    fwrite(STDERR, "No fue posible cargar el banco de demo. Revisa la configuración y las tablas.\n");
    exit(1);
}
