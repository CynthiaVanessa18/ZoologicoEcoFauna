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

$mensaje = "";
$tipoMensaje = "info";

$nombreFormulario = "";
$tipoFormulario = "";
$fechaFormulario = date("Y-m-d");
$stockFormulario = "0";
$precioCompraFormulario = "";
$precioVentaFormulario = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $enTransaccion = false;

    try {
        if (!csrfProductosValido()) {
            throw new DomainException(
                "La sesión del formulario venció. Recarga la página e intenta nuevamente.",
            );
        }

        $datos = validarDatosFormularioProducto($_POST);

        $nombreFormulario = $datos["nombre"];
        $tipoFormulario = $datos["tipo"];
        $fechaFormulario = $datos["fecha"];
        $stockFormulario = (string) $datos["stock"];
        $precioCompraFormulario = (string) $datos["precio_compra"];
        $precioVentaFormulario = (string) $datos["precio_venta"];

        $foto = obtenerImagenProductoSubida("foto");

        if (!mysqli_begin_transaction($conexion)) {
            throw new RuntimeException(mysqli_error($conexion));
        }

        $enTransaccion = true;

        $sqlCheck = "
            SELECT id_Producto
            FROM Producto
            WHERE nombre_producto = ?
            LIMIT 1
            FOR UPDATE
        ";
        $stmtCheck = mysqli_prepare($conexion, $sqlCheck);

        if (!$stmtCheck) {
            throw new RuntimeException(mysqli_error($conexion));
        }

        mysqli_stmt_bind_param($stmtCheck, "s", $datos["nombre"]);

        if (!mysqli_stmt_execute($stmtCheck)) {
            throw new RuntimeException(mysqli_stmt_error($stmtCheck));
        }

        $resCheck = mysqli_stmt_get_result($stmtCheck);
        $rowCheck = mysqli_fetch_assoc($resCheck);
        mysqli_stmt_close($stmtCheck);

        $productoExistia = $rowCheck !== null;

        if ($rowCheck) {
            $idProducto = (int) $rowCheck["id_Producto"];

            $sqlUpdProd = "
                UPDATE Producto
                SET tipo_producto = ?, estado = 'Activo'
                WHERE id_Producto = ?
            ";
            $stmtUpdProd = mysqli_prepare($conexion, $sqlUpdProd);

            if (!$stmtUpdProd) {
                throw new RuntimeException(mysqli_error($conexion));
            }

            mysqli_stmt_bind_param(
                $stmtUpdProd,
                "si",
                $datos["tipo"],
                $idProducto,
            );

            if (!mysqli_stmt_execute($stmtUpdProd)) {
                throw new RuntimeException(mysqli_stmt_error($stmtUpdProd));
            }

            mysqli_stmt_close($stmtUpdProd);
        } else {
            $stmtProducto = mysqli_prepare(
                $conexion,
                "INSERT INTO Producto (
                    nombre_producto,
                    tipo_producto,
                    estado
                 ) VALUES (?, ?, 'Activo')",
            );

            if (!$stmtProducto) {
                throw new RuntimeException(mysqli_error($conexion));
            }

            mysqli_stmt_bind_param(
                $stmtProducto,
                "ss",
                $datos["nombre"],
                $datos["tipo"],
            );

            if (!mysqli_stmt_execute($stmtProducto)) {
                throw new RuntimeException(mysqli_stmt_error($stmtProducto));
            }

            $idProducto = mysqli_insert_id($conexion);
            mysqli_stmt_close($stmtProducto);
        }

        $sqlCheckInv = "
            SELECT id_inventario_tienda
            FROM inventario_tienda
            WHERE id_producto = ?
            LIMIT 1
            FOR UPDATE
        ";
        $stmtCheckInv = mysqli_prepare($conexion, $sqlCheckInv);

        if (!$stmtCheckInv) {
            throw new RuntimeException(mysqli_error($conexion));
        }

        mysqli_stmt_bind_param($stmtCheckInv, "i", $idProducto);

        if (!mysqli_stmt_execute($stmtCheckInv)) {
            throw new RuntimeException(mysqli_stmt_error($stmtCheckInv));
        }

        $inventarioExistente = mysqli_fetch_assoc(
            mysqli_stmt_get_result($stmtCheckInv),
        );
        mysqli_stmt_close($stmtCheckInv);

        if ($inventarioExistente) {
            if ($foto !== null) {
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
                mysqli_stmt_send_long_data($stmtInventario, 0, $foto);
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
        } else {
            $stmtInventario = mysqli_prepare(
                $conexion,
                "INSERT INTO inventario_tienda (
                    id_producto,
                    foto,
                    fecha_ingreso,
                    stock,
                    precio_compra,
                    precio_venta
                 ) VALUES (?, ?, ?, ?, ?, ?)",
            );

            if (!$stmtInventario) {
                throw new RuntimeException(mysqli_error($conexion));
            }

            $blob = null;
            mysqli_stmt_bind_param(
                $stmtInventario,
                "ibsidd",
                $idProducto,
                $blob,
                $datos["fecha"],
                $datos["stock"],
                $datos["precio_compra"],
                $datos["precio_venta"],
            );

            if ($foto !== null) {
                mysqli_stmt_send_long_data($stmtInventario, 1, $foto);
            }
        }

        if (!mysqli_stmt_execute($stmtInventario)) {
            throw new RuntimeException(mysqli_stmt_error($stmtInventario));
        }

        mysqli_stmt_close($stmtInventario);

        $accion = $productoExistia ? "ACTUALIZAR PRODUCTO" : "AGREGAR PRODUCTO";

        if (
            !registrarBitacora(
                $_SESSION["usuario"],
                $accion,
                "Producto",
                $accion . ": " . $datos["nombre"] . ".",
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

        $mensaje = $productoExistia
            ? "Producto e inventario actualizados correctamente."
            : "Producto agregado correctamente.";
        $tipoMensaje = "success";

        $nombreFormulario = "";
        $tipoFormulario = "";
        $fechaFormulario = date("Y-m-d");
        $stockFormulario = "0";
        $precioCompraFormulario = "";
        $precioVentaFormulario = "";
    } catch (DomainException $error) {
        if ($enTransaccion) {
            mysqli_rollback($conexion);
        }

        $mensaje = $error->getMessage();
        $tipoMensaje = "danger";
    } catch (Throwable $error) {
        if ($enTransaccion) {
            mysqli_rollback($conexion);
        }

        error_log("Error guardando producto: " . $error->getMessage());

        $mensaje = "No fue posible guardar el producto. Intenta nuevamente.";
        $tipoMensaje = "danger";
    }
}
?>
<!DOCTYPE html>

<html lang="es">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>
        Agregar Producto - EcoFauna
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

                    <i class="bi bi-plus-circle"></i>

                    Gestión de productos

                </span>

                <h1>

                    Agregar producto

                </h1>

                <p>

                    Registra nuevos productos para la tienda EcoFauna.

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

                    <i class="bi bi-bag-plus-fill"></i>

                </div>

                <span class="burbuja burbuja-uno">

                    <i class="bi bi-box-seam"></i>

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

                    <i class="bi bi-plus-circle-fill"></i>

                </span>

                <div>

                    <span class="mini-titulo">

                        Nuevo registro

                    </span>

                    <h2>

                        Agregar producto

                    </h2>

                    <p>

                        Complete la información del producto.

                    </p>

                </div>

            </div>

            <div class="card shadow mt-4 border-0">

                <div class="card-body p-5">

                    <?php if ($mensaje !== "") { ?>

                        <div class="alert alert-<?= htmlspecialchars(
                            $tipoMensaje,
                            ENT_QUOTES,
                            "UTF-8",
                        ) ?>">

                            <?= htmlspecialchars($mensaje, ENT_QUOTES, "UTF-8") ?>

                        </div>

                    <?php } ?>

                    <form method="POST" enctype="multipart/form-data">

                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(
                            $_SESSION["csrf_productos"],
                            ENT_QUOTES,
                            "UTF-8",
                        ) ?>">

                        <div class="row">

                            <div class="col-md-6 mb-3">

                                <label class="form-label">

                                    Nombre producto

                                </label>

                                <input type="text" name="nombre_producto" class="form-control" maxlength="100" value="<?= htmlspecialchars(
                                    $nombreFormulario,
                                    ENT_QUOTES,
                                    "UTF-8",
                                ) ?>" required>

                            </div>

                            <div class="col-md-6 mb-3">

                                <label class="form-label">

                                    Tipo producto

                                </label>

                                <select name="tipo_producto" class="form-select" required>

                                    <option value="">

                                        Seleccione

                                    </option>

                                    <option value="Recuerdo" <?= $tipoFormulario === "Recuerdo"
                                        ? "selected"
                                        : "" ?>>

                                        Recuerdo

                                    </option>

                                    <option value="Alimento" <?= $tipoFormulario === "Alimento"
                                        ? "selected"
                                        : "" ?>>

                                        Alimento

                                    </option>

                                    <option value="Ropa" <?= $tipoFormulario === "Ropa" ? "selected" : "" ?>>

                                        Ropa

                                    </option>

                                    <option value="Juguete" <?= $tipoFormulario === "Juguete"
                                        ? "selected"
                                        : "" ?>>

                                        Juguete

                                    </option>

                                    <option value="Otro" <?= $tipoFormulario === "Otro" ? "selected" : "" ?>>

                                        Otro

                                    </option>

                                </select>

                            </div>

                        </div>

                        <div class="row">

                            <div class="col-md-4 mb-3">

                                <label class="form-label">

                                    Fecha ingreso

                                </label>

                                <input type="date" name="fecha_ingreso" class="form-control" value="<?= htmlspecialchars(
                                    $fechaFormulario,
                                    ENT_QUOTES,
                                    "UTF-8",
                                ) ?>" required>

                            </div>

                            <div class="col-md-4 mb-3">

                                <label class="form-label">

                                    Stock

                                </label>

                                <input type="number" name="stock" class="form-control" min="0" max="1000000" value="<?= htmlspecialchars(
                                    $stockFormulario,
                                    ENT_QUOTES,
                                    "UTF-8",
                                ) ?>" required>

                            </div>

                            <div class="col-md-4 mb-3">

                                <label class="form-label">

                                    Precio compra

                                </label>

                                <input type="number" step="0.01" min="0" max="99999999.99" name="precio_compra"
                                    class="form-control" value="<?= htmlspecialchars(
                                        $precioCompraFormulario,
                                        ENT_QUOTES,
                                        "UTF-8",
                                    ) ?>" required>

                            </div>

                        </div>

                        <div class="mb-3">

                            <label class="form-label">

                                Precio venta

                            </label>

                            <input type="number" step="0.01" min="0.01" max="99999999.99" name="precio_venta"
                                class="form-control" value="<?= htmlspecialchars(
                                    $precioVentaFormulario,
                                    ENT_QUOTES,
                                    "UTF-8",
                                ) ?>" required>

                        </div>

                        <div class="mb-4">

                            <label class="form-label">

                                Imagen del producto

                            </label>

                            <input type="file" name="foto" class="form-control"
                                accept="image/jpeg,image/png,image/webp">

                            <small class="text-muted">
                                JPG, PNG o WebP. Máximo 2 MB y 4000 × 4000 píxeles.
                            </small>

                        </div>

                        <div class="d-flex justify-content-between">

                            <a href="productos.php" class="btn btn-secundario">

                                <i class="bi bi-arrow-left"></i>

                                Cancelar

                            </a>

                            <button type="submit" class="btn btn-principal">

                                <i class="bi bi-save"></i>

                                Guardar producto

                            </button>

                        </div>

                    </form>

                </div>

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