<?php

require_once __DIR__ . "/../../soporte/sesion.php";
require_once __DIR__ . "/../../../config/database.php";
require_once __DIR__ . "/../../soporte/funciones_tienda.php";

/** @var mysqli $conexion */

if (
    !isset($_SESSION["usuario"], $_SESSION["rol"], $_SESSION["id_login"]) ||
    $_SESSION["rol"] !== "Administrador"
) {
    header("Location: ../index.php");
    exit();
}

function enviarMensajeProductoAdmin(
    string $mensaje,
    string $tipo = "success",
): void {
    $_SESSION["mensaje_producto"] = $mensaje;
    $_SESSION["tipo_mensaje_producto"] = $tipo;

    header("Location: productos.php");
    exit();
}

$_SESSION["csrf_productos"] ??= bin2hex(random_bytes(32));

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (!csrfProductosValido()) {
        enviarMensajeProductoAdmin(
            "La sesión del formulario venció. Intenta nuevamente.",
            "danger",
        );
    }

    $idProducto = filter_input(
        INPUT_POST,
        "id_producto",
        FILTER_VALIDATE_INT,
        ["options" => ["min_range" => 1]],
    );

    if (!$idProducto) {
        enviarMensajeProductoAdmin(
            "El producto seleccionado no es válido.",
            "danger",
        );
    }

    $enTransaccion = false;

    try {
        if (!mysqli_begin_transaction($conexion)) {
            throw new RuntimeException(mysqli_error($conexion));
        }

        $enTransaccion = true;

        $stmt = mysqli_prepare(
            $conexion,
            "SELECT nombre_producto, estado
             FROM Producto
             WHERE id_Producto = ?
             LIMIT 1
             FOR UPDATE",
        );

        if (!$stmt) {
            throw new RuntimeException(mysqli_error($conexion));
        }

        mysqli_stmt_bind_param($stmt, "i", $idProducto);

        if (!mysqli_stmt_execute($stmt)) {
            throw new RuntimeException(mysqli_stmt_error($stmt));
        }

        $producto = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);

        if (!$producto) {
            throw new DomainException("El producto no existe.");
        }

        if (strtolower((string) $producto["estado"]) === "inactivo") {
            throw new DomainException("El producto ya se encuentra oculto.");
        }

        $stmt = mysqli_prepare(
            $conexion,
            "UPDATE Producto
             SET estado = 'Inactivo'
             WHERE id_Producto = ?",
        );

        if (!$stmt) {
            throw new RuntimeException(mysqli_error($conexion));
        }

        mysqli_stmt_bind_param($stmt, "i", $idProducto);

        if (!mysqli_stmt_execute($stmt)) {
            throw new RuntimeException(mysqli_stmt_error($stmt));
        }

        mysqli_stmt_close($stmt);

        if (
            !registrarBitacora(
                $_SESSION["usuario"],
                "OCULTAR PRODUCTO",
                "Producto",
                "Se ocultó el producto: " .
                    $producto["nombre_producto"] .
                    ".",
                $idProducto,
            )
        ) {
            throw new RuntimeException(
                "No fue posible registrar la acción en la bitácora.",
            );
        }

        if (!mysqli_commit($conexion)) {
            throw new RuntimeException(mysqli_error($conexion));
        }

        $enTransaccion = false;
        $_SESSION["csrf_productos"] = bin2hex(random_bytes(32));

        enviarMensajeProductoAdmin("Producto ocultado correctamente.");
    } catch (DomainException $error) {
        if ($enTransaccion) {
            mysqli_rollback($conexion);
        }

        enviarMensajeProductoAdmin($error->getMessage(), "warning");
    } catch (Throwable $error) {
        if ($enTransaccion) {
            mysqli_rollback($conexion);
        }

        error_log("Error ocultando producto: " . $error->getMessage());

        enviarMensajeProductoAdmin(
            "No fue posible ocultar el producto. Intenta nuevamente.",
            "danger",
        );
    }
}

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
    enviarMensajeProductoAdmin("La solicitud no es válida.", "danger");
}

$idProducto = filter_input(
    INPUT_GET,
    "id",
    FILTER_VALIDATE_INT,
    ["options" => ["min_range" => 1]],
);

if (!$idProducto) {
    enviarMensajeProductoAdmin(
        "El producto seleccionado no es válido.",
        "danger",
    );
}

$stmt = mysqli_prepare(
    $conexion,
    "SELECT id_Producto, nombre_producto, tipo_producto, estado
     FROM Producto
     WHERE id_Producto = ?
     LIMIT 1",
);

if (!$stmt) {
    error_log("Error preparando producto: " . mysqli_error($conexion));
    enviarMensajeProductoAdmin(
        "No fue posible consultar el producto.",
        "danger",
    );
}

mysqli_stmt_bind_param($stmt, "i", $idProducto);

if (!mysqli_stmt_execute($stmt)) {
    error_log("Error consultando producto: " . mysqli_stmt_error($stmt));
    mysqli_stmt_close($stmt);

    enviarMensajeProductoAdmin(
        "No fue posible consultar el producto.",
        "danger",
    );
}

$producto = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

if (!$producto) {
    enviarMensajeProductoAdmin("El producto no existe.", "warning");
}

if (strtolower((string) $producto["estado"]) === "inactivo") {
    enviarMensajeProductoAdmin("El producto ya se encuentra oculto.", "warning");
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ocultar producto - EcoFauna</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display&family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../css/styleCliente.css?v=2">
    <link rel="stylesheet" href="../css/crud-modern.css?v=1">
    <link rel="stylesheet" href="../css/ecofauna-unified.css?v=1">
</head>

<body class="ecofauna-unified crud-modern">


    <nav class="navbar navbar-cliente">
        <div class="container-fluid px-4">
            <a class="navbar-brand" href="productos.php">
                <img src="../img/LogoEcoFauna1.png" class="logo-navbar-cliente" alt="Logo EcoFauna">
                <span>EcoFauna<small>Administración de tienda</small></span>
            </a>

            <div class="usuario-nav">
                <div class="usuario-info">
                     <a href="../mi_perfil.php" class="usuario-info text-decoration-none">
                <span class="usuario-avatar">
                    <i class="bi bi-person-fill"></i>
                </span>
                <div>
                    <small>Administrador</small>
                    <strong><?= htmlspecialchars(
                        $_SESSION["usuario"],
                        ENT_QUOTES,
                        "UTF-8",
                    ) ?></strong>
                </div>
            </a>
                </div>

                <form action="../logout.php" method="POST" class="m-0">
    <?= campoCsrfSesion("logout") ?>

    <button type="submit" class="btn btn-salir">
        <i class="bi bi-box-arrow-right"></i>
        Cerrar sesión
    </button>
</form>
            </div>
        </div>
    </nav>

    <header class="hero-cliente">
        <div class="container hero-contenido">
            <div class="hero-texto">
                <span class="hero-etiqueta">
                    <i class="bi bi-eye-slash"></i>
                    Gestión de productos
                </span>
                <h1>Ocultar producto</h1>
                <p>Desactiva productos sin eliminarlos del sistema.</p>

                <div class="hero-acciones">
                    <a href="productos.php" class="btn btn-secundario">
                        <i class="bi bi-arrow-left"></i>
                        Regresar
                    </a>
                </div>
            </div>

            <div class="hero-ilustracion">
                <div class="circulo-grande"><i class="bi bi-box-seam"></i></div>
                <span class="burbuja burbuja-uno"><i class="bi bi-eye-slash"></i></span>
                <span class="burbuja burbuja-dos"><i class="bi bi-shop"></i></span>
            </div>
        </div>
    </header>

    <main class="container contenido-principal">
        <section class="seccion-informacion">
            <div class="informacion-principal">
                <span class="informacion-icono"><i class="bi bi-exclamation-triangle-fill"></i></span>
                <div>
                    <span class="mini-titulo">Confirmación</span>
                    <h2>Ocultar producto</h2>
                    <p>Revisa los datos antes de continuar.</p>
                </div>
            </div>

            <div class="card shadow mt-4">
                <div class="card-body text-center p-5">
                    <h4 class="mb-4">¿Deseas ocultar este producto?</h4>

                    <div class="alert alert-warning">
                        <h5><?= htmlspecialchars(
                            $producto["nombre_producto"],
                            ENT_QUOTES,
                            "UTF-8",
                        ) ?></h5>
                        <strong>Categoría:</strong><br>
                        <?= htmlspecialchars(
                            $producto["tipo_producto"],
                            ENT_QUOTES,
                            "UTF-8",
                        ) ?>
                    </div>

                    <p class="text-muted">
                        El producto continuará en la base de datos.<br>
                        Solo cambiará su estado a <strong>Inactivo</strong>.
                    </p>

                    <form action="producto_eliminar.php" method="POST">
                        <input
                            type="hidden"
                            name="csrf_token"
                            value="<?= htmlspecialchars(
                                $_SESSION["csrf_productos"],
                                ENT_QUOTES,
                                "UTF-8",
                            ) ?>">

                        <input
                            type="hidden"
                            name="id_producto"
                            value="<?= (int) $idProducto ?>">

                        <a href="productos.php" class="btn btn-secundario me-2">
                            <i class="bi bi-arrow-left"></i>
                            Cancelar
                        </a>

                        <button type="submit" class="btn btn-eliminar">
                            <i class="bi bi-eye-slash"></i>
                            Ocultar producto
                        </button>
                    </form>
                </div>
            </div>
        </section>
    </main>

    <footer class="footer-cliente">
        <div class="container">
            <span><i class="bi bi-tree-fill"></i> EcoFauna — Administración</span>
            <small>Conservar • Educar • Proteger</small>
        </div>
    </footer>
</body>

</html>