<?php

require_once __DIR__ . "/../../soporte/sesion.php";
require_once __DIR__ . "/../../../config/database.php";
require_once __DIR__ . "/../../soporte/funciones_tienda.php";

/** @var mysqli $conexion */


/* =====================================================
   VALIDAR SESIÓN
===================================================== */

if (!clienteTiendaAutenticado()) {

    header("Location: ../index.php");
    exit();

}


/* =====================================================
   VALIDAR MÉTODO
===================================================== */

if ($_SERVER["REQUEST_METHOD"] !== "POST") {

    header("Location: carrito.php");
    exit();

}


/* =====================================================
   VALIDAR CSRF
===================================================== */

if (!tokenCsrfSesionValido(
    "carrito",
    $_POST["csrf_token"] ?? null
)) {

    $_SESSION["mensaje_tienda"] =
        "La solicitud expiró. Actualiza la página e inténtalo nuevamente.";

    $_SESSION["tipo_mensaje_tienda"] = "danger";

    header("Location: carrito.php");
    exit();

}


/* =====================================================
   OBTENER CARRITO ANTES DE VACIARLO
===================================================== */

$carrito = normalizarCarritoTienda(
    $_SESSION["carrito"] ?? []
);


/* =====================================================
   CONTAR PRODUCTOS Y UNIDADES
===================================================== */

$cantidadProductos = count($carrito);

$cantidadUnidades = 0;

foreach ($carrito as $cantidad) {

    $cantidadUnidades += (int) $cantidad;

}


/* =====================================================
   VACIAR CARRITO
===================================================== */

unset($_SESSION["carrito"]);


/* =====================================================
   RENOVAR TOKEN
===================================================== */

renovarTokenCsrfSesion("carrito");


/* =====================================================
   REGISTRAR EN BITÁCORA
===================================================== */

$detalleBitacora = sprintf(
    "Carrito vaciado. Productos diferentes: %d. Unidades eliminadas: %d.",
    $cantidadProductos,
    $cantidadUnidades
);

$registroBitacora = registrarBitacora(
    $_SESSION["usuario"],
    "VACIAR CARRITO",
    "carrito",
    $detalleBitacora,
    null
);


/* =====================================================
   VERIFICAR BITÁCORA
===================================================== */

if (!$registroBitacora) {

    error_log(
        "No fue posible registrar en bitácora la acción VACIAR CARRITO. Usuario: " .
        $_SESSION["usuario"]
    );

}


/* =====================================================
   MENSAJE
===================================================== */

$_SESSION["mensaje_tienda"] =
    "El carrito se vació correctamente.";

$_SESSION["tipo_mensaje_tienda"] =
    "success";


/* =====================================================
   REDIRECCIÓN
===================================================== */

header("Location: carrito.php");
exit();