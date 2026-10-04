<?php

require_once __DIR__ . "/../../soporte/sesion.php";
require_once __DIR__ . "/../../../config/database.php";
require_once __DIR__ . "/../../soporte/funciones_tienda.php";

/** @var mysqli $conexion */

/*
|--------------------------------------------------------------------------
| Registrar acción en bitácora
|--------------------------------------------------------------------------
*/
function registrarBitacoraCarrito(
    mysqli $conexion,
    string $accion,
    string $tablaAfectada,
    ?int $registroId,
    string $detalles
): void {
    $usuario = $_SESSION["usuario"] ?? "Usuario no identificado";

    /*
     * Obtener IP del cliente.
     * Se limita a 45 caracteres porque la columna IP es VARCHAR(45)
     * y permite IPv4 e IPv6.
     */
    $ip = $_SERVER["REMOTE_ADDR"] ?? "127.0.0.1";

    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        $ip = "127.0.0.1";
    }

    $stmt = mysqli_prepare(
        $conexion,
        "INSERT INTO bitacora
            (usuario, accion, tabla_afectada, registro_id, detalles, ip)
         VALUES (?, ?, ?, ?, ?, ?)"
    );

    if (!$stmt) {
        error_log(
            "Error preparando registro de bitácora: " .
            mysqli_error($conexion)
        );
        return;
    }

    mysqli_stmt_bind_param(
        $stmt,
        "sssiss",
        $usuario,
        $accion,
        $tablaAfectada,
        $registroId,
        $detalles,
        $ip
    );

    if (!mysqli_stmt_execute($stmt)) {
        error_log(
            "Error registrando acción en bitácora: " .
            mysqli_stmt_error($stmt)
        );
    }

    mysqli_stmt_close($stmt);
}

/*
|--------------------------------------------------------------------------
| Respuesta del carrito
|--------------------------------------------------------------------------
*/
$esAjax = ($_POST["ajax"] ?? "") === "1";

function responderCarrito(
    bool $esAjax,
    string $estado,
    string $mensaje,
    int $codigoHttp = 200,
): void {
    if ($esAjax) {
        http_response_code($codigoHttp);
        header("Content-Type: application/json; charset=UTF-8");

        echo json_encode(
            [
                "status" => $estado,
                "totalItems" => contarUnidadesCarritoTienda(
                    $_SESSION["carrito"] ?? [],
                ),
                "message" => $mensaje,
            ],
            JSON_UNESCAPED_UNICODE,
        );

        exit();
    }

    $_SESSION["mensaje_tienda"] = $mensaje;
    $_SESSION["tipo_mensaje_tienda"] =
        $estado === "success" ? "success" : "danger";

    header("Location: cliente.php");
    exit();
}

/*
|--------------------------------------------------------------------------
| Solo se permiten solicitudes POST
|--------------------------------------------------------------------------
*/
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: cliente.php");
    exit();
}

/*
|--------------------------------------------------------------------------
| Verificar autenticación del cliente
|--------------------------------------------------------------------------
*/
if (!clienteTiendaAutenticado()) {
    if ($esAjax) {
        responderCarrito(
            true,
            "error",
            "Debes iniciar sesión como cliente.",
            401,
        );
    }

    header("Location: ../index.php");
    exit();
}

/*
|--------------------------------------------------------------------------
| Verificar token CSRF
|--------------------------------------------------------------------------
*/
if (!tokenCsrfSesionValido("carrito", $_POST["csrf_token"] ?? null)) {
    responderCarrito(
        $esAjax,
        "error",
        "La solicitud expiró. Actualiza la página e inténtalo nuevamente.",
        403,
    );
}

/*
|--------------------------------------------------------------------------
| Validar producto
|--------------------------------------------------------------------------
*/
$idProducto = filter_var(
    $_POST["id_producto"] ?? null,
    FILTER_VALIDATE_INT,
    ["options" => ["min_range" => 1]],
);

/*
|--------------------------------------------------------------------------
| Validar cantidad
|--------------------------------------------------------------------------
*/
$cantidad = filter_var(
    $_POST["cantidad"] ?? null,
    FILTER_VALIDATE_INT,
    ["options" => ["min_range" => 1, "max_range" => 99]],
);

if ($idProducto === false || $cantidad === false) {
    responderCarrito(
        $esAjax,
        "error",
        "El producto o la cantidad seleccionada no es válida.",
        422,
    );
}

/*
|--------------------------------------------------------------------------
| Consultar producto y stock
|--------------------------------------------------------------------------
*/
$stmt = mysqli_prepare(
    $conexion,
    "SELECT p.nombre_producto, it.stock
     FROM Producto p
     INNER JOIN inventario_tienda it
         ON it.id_producto = p.id_Producto
     WHERE p.id_Producto = ?
       AND p.estado = 'Activo'
     LIMIT 1",
);

if (!$stmt) {
    error_log(
        "Error preparando el producto del carrito: " .
        mysqli_error($conexion)
    );

    responderCarrito(
        $esAjax,
        "error",
        "No fue posible agregar el producto. Inténtalo nuevamente.",
        500,
    );
}

mysqli_stmt_bind_param($stmt, "i", $idProducto);

if (!mysqli_stmt_execute($stmt)) {
    error_log(
        "Error consultando el producto del carrito: " .
        mysqli_stmt_error($stmt)
    );

    mysqli_stmt_close($stmt);

    responderCarrito(
        $esAjax,
        "error",
        "No fue posible agregar el producto. Inténtalo nuevamente.",
        500,
    );
}

$resultado = mysqli_stmt_get_result($stmt);

$producto = mysqli_fetch_assoc($resultado);

mysqli_stmt_close($stmt);

/*
|--------------------------------------------------------------------------
| Verificar que el producto exista y esté disponible
|--------------------------------------------------------------------------
*/
if (!$producto) {
    responderCarrito(
        $esAjax,
        "error",
        "El producto seleccionado ya no está disponible.",
        404,
    );
}

/*
|--------------------------------------------------------------------------
| Obtener carrito actual
|--------------------------------------------------------------------------
*/
$carrito = normalizarCarritoTienda(
    $_SESSION["carrito"] ?? []
);

$cantidadActual = $carrito[(int) $idProducto] ?? 0;

$cantidadNueva = $cantidadActual + (int) $cantidad;

$stockDisponible = (int) $producto["stock"];

/*
|--------------------------------------------------------------------------
| Validar máximo de unidades
|--------------------------------------------------------------------------
*/
if ($cantidadNueva > 99) {
    responderCarrito(
        $esAjax,
        "error",
        "Puedes agregar como máximo 99 unidades del mismo producto.",
        422,
    );
}

/*
|--------------------------------------------------------------------------
| Validar stock
|--------------------------------------------------------------------------
*/
if ($cantidadNueva > $stockDisponible) {
    responderCarrito(
        $esAjax,
        "error",
        "Solo hay {$stockDisponible} unidad(es) disponibles de este producto.",
        422,
    );
}

/*
|--------------------------------------------------------------------------
| Actualizar carrito
|--------------------------------------------------------------------------
*/
$carrito[(int) $idProducto] = $cantidadNueva;

$_SESSION["carrito"] = $carrito;

/*
|--------------------------------------------------------------------------
| Registrar acción exitosa en bitácora
|--------------------------------------------------------------------------
|
| Se registra solamente después de que el producto fue agregado
| correctamente al carrito.
|
*/
$nombreProducto = (string) $producto["nombre_producto"];

$detallesBitacora =
    "Producto agregado al carrito. " .
    "Producto: {$nombreProducto}. " .
    "ID producto: {$idProducto}. " .
    "Cantidad agregada: {$cantidad}. " .
    "Cantidad total en carrito: {$cantidadNueva}.";

registrarBitacoraCarrito(
    $conexion,
    "AGREGAR_CARRITO",
    "carrito",
    (int) $idProducto,
    $detallesBitacora
);

/*
|--------------------------------------------------------------------------
| Responder al cliente
|--------------------------------------------------------------------------
*/
responderCarrito(
    $esAjax,
    "success",
    "¡Producto agregado al carrito!",
);