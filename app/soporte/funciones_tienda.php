<?php

const TAMANO_MAXIMO_IMAGEN_PRODUCTO = 20 * 1024 * 1024;
const DIMENSION_MAXIMA_IMAGEN_PRODUCTO = 4000;

/**
 * Comprueba que la sesión pertenezca a un cliente autenticado.
 */
function clienteTiendaAutenticado(): bool
{
    return isset(
        $_SESSION["usuario"],
        $_SESSION["rol"],
        $_SESSION["id_login"],
        $_SESSION["id_usuario"],
    ) &&
        $_SESSION["rol"] === "Cliente" &&
        (int) $_SESSION["id_usuario"] > 0;
}

/**
 * Elimina identificadores y cantidades inválidas del carrito.
 */
function normalizarCarritoTienda(mixed $carrito): array
{
    if (!is_array($carrito)) {
        return [];
    }

    $carritoNormalizado = [];

    foreach ($carrito as $idProducto => $cantidad) {
        $id = filter_var(
            $idProducto,
            FILTER_VALIDATE_INT,
        );

        $unidades = filter_var(
            $cantidad,
            FILTER_VALIDATE_INT,
        );

        if (
            $id === false ||
            $id <= 0 ||
            $unidades === false ||
            $unidades <= 0 ||
            $unidades > 99
        ) {
            continue;
        }

        $carritoNormalizado[(int) $id] = (int) $unidades;
    }

    return $carritoNormalizado;
}

/**
 * Cuenta todas las unidades almacenadas en el carrito.
 */
function contarUnidadesCarritoTienda(mixed $carrito): int
{
    return array_sum(
        normalizarCarritoTienda($carrito)
    );
}
/**
 * Valida y normaliza los datos comunes de los formularios de productos.
 */
function validarDatosFormularioProducto(array $entrada): array
{
    $nombre = trim((string) ($entrada["nombre_producto"] ?? ""));
    $tipo = trim((string) ($entrada["tipo_producto"] ?? ""));
    $fecha = trim((string) ($entrada["fecha_ingreso"] ?? ""));
    $stock = filter_var(
        $entrada["stock"] ?? null,
        FILTER_VALIDATE_INT,
        ["options" => ["min_range" => 0, "max_range" => 1000000]],
    );
    $precioCompra = filter_var(
        $entrada["precio_compra"] ?? null,
        FILTER_VALIDATE_FLOAT,
    );
    $precioVenta = filter_var(
        $entrada["precio_venta"] ?? null,
        FILTER_VALIDATE_FLOAT,
    );

    $longitudNombre = function_exists("mb_strlen")
        ? mb_strlen($nombre, "UTF-8")
        : strlen($nombre);

    if ($nombre === "" || $longitudNombre > 100) {
        throw new DomainException(
            "El nombre del producto es obligatorio y admite hasta 100 caracteres.",
        );
    }

    $tiposPermitidos = ["Recuerdo", "Alimento", "Ropa", "Juguete", "Otro"];

    if (!in_array($tipo, $tiposPermitidos, true)) {
        throw new DomainException("El tipo de producto seleccionado no es válido.");
    }

    $fechaObjeto = DateTime::createFromFormat("!Y-m-d", $fecha);

    if (
        $fechaObjeto === false ||
        $fechaObjeto->format("Y-m-d") !== $fecha
    ) {
        throw new DomainException("La fecha de ingreso no es válida.");
    }

    if ($stock === false) {
        throw new DomainException("El stock debe ser un número entero no negativo.");
    }

    if (
        $precioCompra === false ||
        $precioCompra < 0 ||
        $precioCompra > 99999999.99
    ) {
        throw new DomainException("El precio de compra no es válido.");
    }

    if (
        $precioVenta === false ||
        $precioVenta <= 0 ||
        $precioVenta > 99999999.99
    ) {
        throw new DomainException("El precio de venta debe ser mayor que cero.");
    }

    return [
        "nombre" => $nombre,
        "tipo" => $tipo,
        "fecha" => $fecha,
        "stock" => (int) $stock,
        "precio_compra" => round((float) $precioCompra, 2),
        "precio_venta" => round((float) $precioVenta, 2),
    ];
}

/**
 * Comprueba el token usado por los formularios administrativos de productos.
 */
function csrfProductosValido(): bool
{
    $tokenSesion = $_SESSION["csrf_productos"] ?? "";
    $tokenFormulario = $_POST["csrf_token"] ?? "";

    return is_string($tokenSesion) &&
        is_string($tokenFormulario) &&
        $tokenSesion !== "" &&
        $tokenFormulario !== "" &&
        hash_equals($tokenSesion, $tokenFormulario);
}

/**
 * Lee una imagen subida y devuelve sus bytes para guardarlos como LONGBLOB.
 * Si el campo está vacío, devuelve null.
 */
function obtenerImagenProductoSubida(string $campo): ?string
{
    if (!isset($_FILES[$campo])) {
        return null;
    }

    $archivo = $_FILES[$campo];

    if (
        !is_array($archivo) ||
        !isset(
            $archivo["name"],
            $archivo["tmp_name"],
            $archivo["error"],
        ) ||
        is_array($archivo["name"]) ||
        is_array($archivo["tmp_name"]) ||
        is_array($archivo["error"])
    ) {
        throw new DomainException("La imagen seleccionada no es válida.");
    }

    $errorSubida = (int) $archivo["error"];

    if ($errorSubida === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    if (
        $errorSubida === UPLOAD_ERR_INI_SIZE ||
        $errorSubida === UPLOAD_ERR_FORM_SIZE
    ) {
        throw new DomainException("La imagen no puede superar los 2 MB.");
    }

    if ($errorSubida === UPLOAD_ERR_PARTIAL) {
        throw new DomainException(
            "La imagen no terminó de cargarse. Intenta nuevamente.",
        );
    }

    if ($errorSubida !== UPLOAD_ERR_OK) {
        throw new RuntimeException(
            "El servidor no pudo recibir correctamente la imagen.",
        );
    }

    $rutaTemporal = (string) $archivo["tmp_name"];

    if ($rutaTemporal === "" || !is_uploaded_file($rutaTemporal)) {
        throw new DomainException("El archivo recibido no es una subida válida.");
    }

    $tamanoReal = filesize($rutaTemporal);

    if ($tamanoReal === false || $tamanoReal <= 0) {
        throw new DomainException("La imagen seleccionada está vacía.");
    }

    if ($tamanoReal > TAMANO_MAXIMO_IMAGEN_PRODUCTO) {
        throw new DomainException("La imagen no puede superar los 2 MB.");
    }

    if (!class_exists(finfo::class)) {
        throw new RuntimeException(
            "El servidor no dispone del validador de archivos requerido.",
        );
    }

    $detectorMime = new finfo(FILEINFO_MIME_TYPE);
    $mime = $detectorMime->file($rutaTemporal);

    $tiposPermitidos = [
        "image/jpeg" => [
            "extensiones" => ["jpg", "jpeg"],
            "tipo_imagen" => IMAGETYPE_JPEG,
        ],
        "image/png" => [
            "extensiones" => ["png"],
            "tipo_imagen" => IMAGETYPE_PNG,
        ],
        "image/webp" => [
            "extensiones" => ["webp"],
            "tipo_imagen" => IMAGETYPE_WEBP,
        ],
    ];

    if (!is_string($mime) || !isset($tiposPermitidos[$mime])) {
        throw new DomainException(
            "La imagen debe estar en formato JPG, PNG o WebP.",
        );
    }

    $extension = strtolower(
        pathinfo((string) $archivo["name"], PATHINFO_EXTENSION),
    );
    $configuracionTipo = $tiposPermitidos[$mime];

    if (!in_array($extension, $configuracionTipo["extensiones"], true)) {
        throw new DomainException(
            "La extensión del archivo no coincide con el contenido de la imagen.",
        );
    }

    $dimensiones = @getimagesize($rutaTemporal);

    if (
        $dimensiones === false ||
        ($dimensiones[2] ?? null) !== $configuracionTipo["tipo_imagen"]
    ) {
        throw new DomainException("El contenido del archivo no es una imagen válida.");
    }

    $ancho = (int) ($dimensiones[0] ?? 0);
    $alto = (int) ($dimensiones[1] ?? 0);

    if (
        $ancho <= 0 ||
        $alto <= 0 ||
        $ancho > DIMENSION_MAXIMA_IMAGEN_PRODUCTO ||
        $alto > DIMENSION_MAXIMA_IMAGEN_PRODUCTO
    ) {
        throw new DomainException(
            "La imagen no puede superar los 4000 × 4000 píxeles.",
        );
    }

    $contenido = file_get_contents($rutaTemporal);

    if ($contenido === false) {
        throw new RuntimeException("No fue posible leer la imagen seleccionada.");
    }

    return $contenido;
}

/**
 * Construye una URL de datos segura para mostrar una imagen almacenada como BLOB.
 */
function crearDataUriImagenProducto(?string $contenido): ?string
{
    if ($contenido === null || $contenido === "" || !class_exists(finfo::class)) {
        return null;
    }

    $detectorMime = new finfo(FILEINFO_MIME_TYPE);
    $mime = $detectorMime->buffer($contenido);
    $mimesPermitidos = ["image/jpeg", "image/png", "image/webp"];

    if (!is_string($mime) || !in_array($mime, $mimesPermitidos, true)) {
        return null;
    }

    return "data:" . $mime . ";base64," . base64_encode($contenido);
}