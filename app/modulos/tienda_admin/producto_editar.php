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

$_SESSION["csrf_productos"] ??= bin2hex(random_bytes(32));

$idProducto = filter_input(
    INPUT_GET,
    "id",
    FILTER_VALIDATE_INT,
    ["options" => ["min_range" => 1]],
);

if (!$idProducto) {
    header("Location: productos.php");
    exit();
}

$sql = "
    SELECT
        p.id_Producto,
        p.nombre_producto,
        p.tipo_producto,
        i.foto,
        i.fecha_ingreso,
        i.stock,
        i.precio_compra,
        i.precio_venta
    FROM Producto p
    INNER JOIN inventario_tienda i
        ON p.id_Producto = i.id_producto
    WHERE p.id_Producto = ?
    LIMIT 1
";

$stmt = mysqli_prepare($conexion, $sql);

if (!$stmt) {
    error_log("Error preparando producto: " . mysqli_error($conexion));
    header("Location: productos.php?mensaje=error");
    exit();
}

mysqli_stmt_bind_param($stmt, "i", $idProducto);

if (!mysqli_stmt_execute($stmt)) {
    error_log("Error consultando producto: " . mysqli_stmt_error($stmt));
    mysqli_stmt_close($stmt);
    header("Location: productos.php?mensaje=error");
    exit();
}

$resultado = mysqli_stmt_get_result($stmt);
$producto = mysqli_fetch_assoc($resultado);
mysqli_stmt_close($stmt);

if (!$producto) {
    header("Location: productos.php?mensaje=no_encontrado");
    exit();
}

$mensajeError = "";

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["guardar"])) {
    $enTransaccion = false;

    try {
        if (!csrfProductosValido()) {
            throw new DomainException(
                "La sesión del formulario venció. Recarga la página e intenta nuevamente.",
            );
        }

        $datos = validarDatosFormularioProducto($_POST);

        $producto["nombre_producto"] = $datos["nombre"];
        $producto["tipo_producto"] = $datos["tipo"];
        $producto["fecha_ingreso"] = $datos["fecha"];
        $producto["stock"] = $datos["stock"];
        $producto["precio_compra"] = $datos["precio_compra"];
        $producto["precio_venta"] = $datos["precio_venta"];

        $fotoNueva = obtenerImagenProductoSubida("foto");

        if (!mysqli_begin_transaction($conexion)) {
            throw new RuntimeException(mysqli_error($conexion));
        }

        $enTransaccion = true;

        $stmtDuplicado = mysqli_prepare(
            $conexion,
            "SELECT id_Producto
             FROM Producto
             WHERE nombre_producto = ?
               AND id_Producto <> ?
             LIMIT 1
             FOR UPDATE",
        );

        if (!$stmtDuplicado) {
            throw new RuntimeException(mysqli_error($conexion));
        }

        mysqli_stmt_bind_param(
            $stmtDuplicado,
            "si",
            $datos["nombre"],
            $idProducto,
        );

        if (!mysqli_stmt_execute($stmtDuplicado)) {
            throw new RuntimeException(mysqli_stmt_error($stmtDuplicado));
        }

        $productoDuplicado = mysqli_fetch_assoc(
            mysqli_stmt_get_result($stmtDuplicado),
        );
        mysqli_stmt_close($stmtDuplicado);

        if ($productoDuplicado) {
            throw new DomainException(
                "Ya existe otro producto con ese nombre.",
            );
        }

        $stmtProducto = mysqli_prepare(
            $conexion,
            "UPDATE Producto
             SET nombre_producto = ?, tipo_producto = ?
             WHERE id_Producto = ?",
        );

        if (!$stmtProducto) {
            throw new RuntimeException(mysqli_error($conexion));
        }

        mysqli_stmt_bind_param(
            $stmtProducto,
            "ssi",
            $datos["nombre"],
            $datos["tipo"],
            $idProducto,
        );

        if (!mysqli_stmt_execute($stmtProducto)) {
            throw new RuntimeException(mysqli_stmt_error($stmtProducto));
        }
        mysqli_stmt_close($stmtProducto);

        if ($fotoNueva !== null) {
            $stmtInventario = mysqli_prepare(
                $conexion,
                "UPDATE inventario_tienda
                 SET foto = ?, fecha_ingreso = ?, stock = ?,
                     precio_compra = ?, precio_venta = ?
                 WHERE id_producto = ?",
            );

            if (!$stmtInventario) {
                throw new RuntimeException(mysqli_error($conexion));
            }

            $blob = null;
            mysqli_stmt_bind_param(
                $stmtInventario,
                "bsiddi",
                $blob,
                $datos["fecha"],
                $datos["stock"],
                $datos["precio_compra"],
                $datos["precio_venta"],
                $idProducto,
            );
            mysqli_stmt_send_long_data($stmtInventario, 0, $fotoNueva);
        } else {
            $stmtInventario = mysqli_prepare(
                $conexion,
                "UPDATE inventario_tienda
                 SET fecha_ingreso = ?, stock = ?,
                     precio_compra = ?, precio_venta = ?
                 WHERE id_producto = ?",
            );

            if (!$stmtInventario) {
                throw new RuntimeException(mysqli_error($conexion));
            }

            mysqli_stmt_bind_param(
                $stmtInventario,
                "siddi",
                $datos["fecha"],
                $datos["stock"],
                $datos["precio_compra"],
                $datos["precio_venta"],
                $idProducto,
            );
        }

        if (!mysqli_stmt_execute($stmtInventario)) {
            throw new RuntimeException(mysqli_stmt_error($stmtInventario));
        }
        mysqli_stmt_close($stmtInventario);

        if (
            !registrarBitacora(
                $_SESSION["usuario"],
                "ACTUALIZAR PRODUCTO",
                "Producto",
                "Se actualizó el producto: " . $datos["nombre"] . ".",
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

        header("Location: productos.php?mensaje=actualizado");
        exit();
   } catch (DomainException $error) {
        if ($enTransaccion) {
            try {
                mysqli_rollback($conexion);
            } catch (Throwable $e) {
            }
        }
        $mensajeError = $error->getMessage();
    } catch (Throwable $error) {
        if ($enTransaccion) {
            try {
                mysqli_rollback($conexion);
            } catch (Throwable $e) {
            }
        }

        error_log("Error actualizando producto: " . $error->getMessage());

        // Detectar si el mensaje viene de la validación del archivo o de PHP (upload_max_filesize)
        $msg = $error->getMessage();
        if (strpos($msg, "pesada") !== false || strpos($msg, "tamaño") !== false || strpos($msg, "grande") !== false) {
            $mensajeError = "La imagen seleccionada es demasiado grande. El tamaño máximo permitido es de 20 MB.";
        } elseif (strpos($error->getMessage(), "gone away") !== false || strpos($error->getMessage(), "link") !== false) {
            $mensajeError = "Se perdió la conexión con el servidor de la base de datos. Por favor, intenta guardar nuevamente.";
        } else {
$mensajeError = "ERROR TÉCNICO: " . $error->getMessage() . " en la línea " . $error->getLine();        }
    }
}
?>
<!DOCTYPE html>

<html lang="es">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>
        Editar Producto - EcoFauna
    </title>

    <!-- Bootstrap -->

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/css/bootstrap.min.css" rel="stylesheet">

    <!-- Iconos -->

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">

    <!-- Fuentes -->

    <link rel="preconnect" href="https://fonts.googleapis.com">

    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

    <link
        href="https://fonts.googleapis.com/css2?family=DM+Serif+Display&family=Poppins:wght@400;500;600;700&display=swap"
        rel="stylesheet">

    <!-- CSS SISTEMA -->

    <link rel="stylesheet" href="../css/styleCliente.css?v=2">

    <link rel="stylesheet" href="../css/crud-modern.css?v=1">
    <link rel="stylesheet" href="../css/ecofauna-unified.css?v=1">
</head>

<body class="ecofauna-unified crud-modern">



    <!-- =====================================
 NAVBAR
===================================== -->

    <nav class="navbar navbar-cliente">

        <div class="container-fluid px-4">

            <a class="navbar-brand" href="#">

                <img src="../img/LogoEcoFauna1.png" class="logo-navbar-cliente" alt="Logo EcoFauna">

                <span>

                    EcoFauna

                    <small>
                        Administración tienda
                    </small>

                </span>

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

    <!-- =====================================
 HERO
===================================== -->

    <header class="hero-cliente">

        <div class="container hero-contenido">

            <div class="hero-texto">

                <span class="hero-etiqueta">

                    <i class="bi bi-pencil-square"></i>

                    Gestión de productos

                </span>

                <h1>

                    Editar producto

                </h1>

                <p>

                    Actualiza información, inventario e imagen del producto.

                </p>

                <div class="hero-acciones">

                    <a href="productos.php" class="btn btn-secundario">

                        <i class="bi bi-arrow-left"></i>

                        Regresar

                    </a>

                </div>

            </div>

            <div class="hero-ilustracion">

                <div class="circulo-grande">

                    <i class="bi bi-box-seam"></i>

                </div>

                <span class="burbuja burbuja-uno">

                    <i class="bi bi-pencil"></i>

                </span>

                <span class="burbuja burbuja-dos">

                    <i class="bi bi-image"></i>

                </span>

            </div>

        </div>

    </header>

    <!-- =====================================
 CONTENIDO
===================================== -->

    <main class="container contenido-principal">

        <section class="seccion-informacion">

            <div class="informacion-principal">

                <span class="informacion-icono">

                    <i class="bi bi-pencil-square"></i>

                </span>

                <div>

                    <span class="mini-titulo">

                        Formulario

                    </span>

                    <h2>

                        Editar información

                    </h2>

                    <p>

                        Modifique los datos necesarios del producto.

                    </p>

                </div>

            </div>

            <div class="form-editar mt-4">

                <?php if (!empty($mensajeError)): ?>
                    <div class="alert alert-danger" role="alert">
                        <?= htmlspecialchars($mensajeError, ENT_QUOTES, "UTF-8") ?>
                    </div>
                <?php endif; ?>

                <form method="POST" enctype="multipart/form-data">

                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(
                        $_SESSION["csrf_productos"],
                        ENT_QUOTES,
                        "UTF-8",
                    ) ?>">

                    <div class="row">

                        <div class="col-md-6">

                            <label class="form-label">

                                Nombre producto

                            </label>

                            <input type="text" name="nombre_producto" class="form-control" maxlength="100" value="<?= htmlspecialchars(
                                $producto["nombre_producto"],
                                ENT_QUOTES,
                                "UTF-8",
                            ) ?>" required>

                        </div>

                        <div class="col-md-6">

                            <label class="form-label">

                                Tipo producto

                            </label>

                            <select name="tipo_producto" class="form-select" required>

                                <option value="Recuerdo" <?= $producto["tipo_producto"] == "Recuerdo" ? "selected" : "" ?>>

                                    Recuerdo

                                </option>

                                <option value="Alimento" <?= $producto["tipo_producto"] == "Alimento" ? "selected" : "" ?>>

                                    Alimento

                                </option>

                                <option value="Ropa" <?= $producto["tipo_producto"] == "Ropa" ? "selected" : "" ?>>

                                    Ropa

                                </option>

                                <option value="Juguete" <?= $producto["tipo_producto"] == "Juguete" ? "selected" : "" ?>>

                                    Juguete

                                </option>

                                <option value="Otro" <?= $producto["tipo_producto"] == "Otro" ? "selected" : "" ?>>

                                    Otro

                                </option>

                            </select>

                        </div>

                    </div>

                    <div class="row mt-3">

                        <div class="col-md-4">

                            <label class="form-label">

                                Fecha ingreso

                            </label>

                            <input type="date" name="fecha_ingreso" class="form-control"
                                value="<?= $producto["fecha_ingreso"] ?>" required>

                        </div>

                        <div class="col-md-4">

                            <label class="form-label">

                                Stock

                            </label>

                            <input type="number" name="stock" class="form-control" min="0" max="1000000"
                                value="<?= $producto["stock"] ?>" required>

                        </div>

                        <div class="col-md-4">

                            <label class="form-label">

                                Precio compra

                            </label>

                            <input type="number" step="0.01" min="0" max="99999999.99" name="precio_compra"
                                class="form-control" value="<?= $producto["precio_compra"] ?>" required>

                        </div>

                    </div>

                    <div class="mt-3">

                        <label class="form-label">

                            Precio venta

                        </label>

                        <input type="number" step="0.01" min="0.01" max="99999999.99" name="precio_venta"
                            class="form-control" value="<?= $producto["precio_venta"] ?>" required>

                    </div>

                    <div class="mt-4">

                        <label class="form-label">

                            Imagen actual

                        </label>

                        <br>

                        <?php $imagenActual = crearDataUriImagenProducto(
                            $producto["foto"] ?? null,
                        ); ?>

                        <?php if ($imagenActual !== null) { ?>

                            <img src="<?= htmlspecialchars($imagenActual, ENT_QUOTES, "UTF-8") ?>" class="imagen-editar">

                        <?php } else { ?>

                            <p>
                                Sin imagen
                            </p>

                        <?php } ?>

                    </div>

                    <div class="mt-4">

                        <label class="form-label">

                            Cambiar imagen

                        </label>

                        <input type="file" name="foto" class="form-control" accept="image/jpeg,image/png,image/webp">

                        <small class="text-muted">
                            JPG, PNG o WebP. Máximo 20 MB y 4000 × 4000 píxeles.
                        </small>

                    </div>

                    <div class="d-flex justify-content-between mt-5">

                        <a href="productos.php" class="btn btn-secundario">

                            <i class="bi bi-arrow-left"></i>

                            Cancelar

                        </a>

                        <button type="submit" name="guardar" class="btn btn-principal">

                            <i class="bi bi-save"></i>

                            Guardar cambios

                        </button>

                    </div>

                </form>

            </div>

        </section>

    </main>

    <!-- FOOTER -->

    <footer class="footer-cliente">

        <div class="container">

            <span>

                <i class="bi bi-tree-fill"></i>

                EcoFauna — Administración

            </span>

            <small>

                Conservar • Educar • Proteger

            </small>

        </div>

    </footer>

</body>

</html>