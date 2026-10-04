<?php

require_once __DIR__ . "/../../soporte/sesion.php";
require_once __DIR__ . "/../../../config/database.php";
require_once __DIR__ . "/../../soporte/funciones_tienda.php";

/** @var mysqli $conexion */

if (!isset($_SESSION["usuario"]) || $_SESSION["rol"] !== "Cliente") {
    header("Location: ../index.php");
    exit();
}

$categoria = $_GET["categoria"] ?? "";

/* Obtener categorías */

$categorias = mysqli_query(
    $conexion,
    "
    SELECT DISTINCT tipo_producto
    FROM Producto
    WHERE estado = 'Activo'
    ORDER BY tipo_producto
    ",
);
$sql = "
SELECT

    p.id_Producto,
    p.nombre_producto,
    p.tipo_producto,

    i.id_inventario_tienda,
    i.stock,
    i.precio_venta,
    i.foto

FROM Inventario_tienda i

INNER JOIN Producto p

ON i.id_producto = p.id_Producto

WHERE i.stock > 0 AND p.estado = 'Activo'

";

if (!empty($categoria)) {
    $categoria = mysqli_real_escape_string($conexion, $categoria);

    $sql .= "
        AND p.tipo_producto='$categoria'
    ";
}

$sql .= "
ORDER BY p.nombre_producto
";

$resultado = mysqli_query($conexion, $sql);
?>

<!DOCTYPE html>

<html lang="es">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>
        Tienda EcoFauna
    </title>

    <!-- Bootstrap -->

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/css/bootstrap.min.css" rel="stylesheet">

    <!-- Iconos -->

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">

    <!-- Fuente -->

    <link
        href="https://fonts.googleapis.com/css2?family=DM+Serif+Display&family=Poppins:wght@400;500;600;700&display=swap"
        rel="stylesheet">

    <!-- CSS PRINCIPAL -->

    <link rel="stylesheet" href="../css/styleCliente.css?v=2">

    <link rel="stylesheet" href="../css/ecofauna-unified.css?v=1">
</head>

<body class="ecofauna-unified">

    <!-- =====================================================
     NAVBAR
===================================================== -->

    <nav class="navbar navbar-cliente">

        <div class="container-fluid px-4">

            <a class="navbar-brand" href="../cliente.php">

                <img src="../img/LogoEcoFauna1.png" class="logo-navbar-cliente" alt="EcoFauna">


            </a>

            <div class="usuario-nav">

                <div class="usuario-info">



                </div>

                <a href="../cliente.php" class="btn btn-outline-light">

                    <i class="bi bi-arrow-left"></i>

                    Regresar

                </a>

                <a href="mis_compras.php" class="btn btn-success">
                    <i class="bi bi-bag-check-fill"></i>
                    Mis compras
                </a>

                <?php
                $cantCarritoTotal = 0;
                if (
                    isset($_SESSION["carrito"]) &&
                    is_array($_SESSION["carrito"])
                ) {
                    foreach ($_SESSION["carrito"] as $itemVal) {
                        if (is_array($itemVal)) {
                            $cantCarritoTotal +=
                                (int) ($itemVal["cantidad"] ?? 0);
                        } else {
                            $cantCarritoTotal += (int) $itemVal;
                        }
                    }
                }
                ?>
                <a href="carrito.php" class="btn btn-success position-relative" id="btn-ver-carrito">
                    <i class="bi bi-cart-fill"></i>
                    Carrito
                    <span
                        class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger contador-carrito<?= $cantCarritoTotal >
                            0
                            ? ""
                            : " d-none" ?>"
                        id="badge-carrito-total">
                        <?= $cantCarritoTotal ?>
                    </span>
                </a>

                <a href="cliente.php" class="btn btn-outline-light">
                    <i class="bi bi-arrow-left"></i>
                    Tienda
                </a>
                <a href="../mi_perfil.php" class="usuario-info text-decoration-none">
                    <span class="usuario-avatar">
                        <i class="bi bi-person-fill"></i>
                    </span>
                    <div>
                        <small>Cliente</small>
                        <strong>

                            <?= htmlspecialchars($_SESSION["usuario"]) ?>

                        </strong>

                    </div>
                </a>


            </div>

        </div>

    </nav>

    <!-- =====================================================
     HERO
===================================================== -->

    <header class="hero-cliente">

        <div class="container hero-contenido">

            <div class="hero-texto">

                <span class="hero-etiqueta">

                    <i class="bi bi-shop"></i>

                    Tienda oficial EcoFauna

                </span>

                <h1>

                    Productos del zoológico

                </h1>

                <p>

                    Compra recuerdos, artículos especiales y productos
                    para llevar la experiencia EcoFauna contigo.

                </p>

            </div>

            <div class="hero-ilustracion">

                <div class="circulo-grande">

                    <i class="bi bi-bag-heart-fill"></i>

                </div>

                <span class="burbuja burbuja-uno">

                    <i class="bi bi-cart-fill"></i>

                </span>

                <span class="burbuja burbuja-dos">

                    <i class="bi bi-gift-fill"></i>

                </span>

                <span class="burbuja burbuja-tres">

                    <i class="bi bi-stars"></i>

                </span>

            </div>

        </div>

    </header>

    <main class="container contenido-principal">

        <!-- FILTRO -->

        <section class="filtro mb-5">

            <div class="row align-items-center">

                <div class="col-md-6">

                    <h2 class="mb-0">

                        <i class="bi bi-shop text-success"></i>

                        Tienda EcoFauna

                    </h2>

                </div>

                <div class="col-md-6">

                    <form method="GET" class="d-flex">

                        <select name="categoria" class="form-select me-2">

                            <option value="">

                                Todas las categorías

                            </option>

                            <?php while (
                                $cat = mysqli_fetch_assoc($categorias)
                            ) { ?>

                                <option value="<?= htmlspecialchars(
                                    $cat["tipo_producto"],
                                ) ?>" <?= $categoria == $cat["tipo_producto"]
                                     ? "selected"
                                     : "" ?>>

                                    <?= htmlspecialchars(
                                        $cat["tipo_producto"],
                                    ) ?>

                                </option>

                            <?php } ?>

                        </select>

                        <button class="btn btn-success">

                            <i class="bi bi-funnel-fill"></i>

                        </button>

                    </form>

                </div>

            </div>

        </section>

        <!-- PRODUCTOS -->

        <div class="row g-4">

            <?php if (mysqli_num_rows($resultado) > 0) {
                while ($producto = mysqli_fetch_assoc($resultado)) { ?>

                    <div class="col-md-6 col-lg-4">

                        <div class="card product-card h-100">

                            <?php $imagenProducto = crearDataUriImagenProducto(
                                $producto["foto"] ?? null,
                            ); ?>

                            <?php if ($imagenProducto !== null) { ?>

                                <img src="<?= htmlspecialchars(
                                    $imagenProducto,
                                    ENT_QUOTES,
                                    "UTF-8",
                                ) ?>" class="product-image">

                            <?php } else { ?>

                                <div class="no-image">

                                    <i class="bi bi-image"></i>

                                </div>

                            <?php } ?>

                            <div class="card-body d-flex flex-column">

                                <h4 class="fw-bold">

                                    <?= htmlspecialchars(
                                        $producto["nombre_producto"],
                                    ) ?>

                                </h4>

                                <span class="badge bg-success mb-3">

                                    <?= htmlspecialchars(
                                        $producto["tipo_producto"],
                                    ) ?>

                                </span>

                                <h3 class="precio">

                                    ₡<?= number_format(
                                        $producto["precio_venta"],
                                        2,
                                    ) ?>

                                </h3>

                                <p class="text-muted">

                                    <i class="bi bi-box-seam"></i>

                                    Stock disponible:

                                    <?= $producto["stock"] ?>

                                </p>

                                <form action="agregar_carrito.php" method="POST" class="mt-auto form-agregar-carrito">
                                    <?= campoCsrfSesion("carrito") ?>

                                    <input type="hidden" name="id_producto" value="<?= $producto["id_Producto"] ?>">

                                    <label class="form-label">
                                        Cantidad
                                    </label>

                                    <input type="number" name="cantidad" class="form-control mb-3" value="1" min="1"
                                        max="<?= $producto["stock"] ?>">

                                    <button type="submit" class="btn btn-success w-100 btn-submit-carrito">
                                        <i class="bi bi-cart-plus-fill me-1"></i>
                                        Agregar al carrito
                                    </button>

                                </form>

                            </div>

                        </div>

                    </div>

                <?php }
            } else {
                ?>

                <div class="alert alert-warning text-center">

                    <i class="bi bi-exclamation-circle"></i>

                    No hay productos disponibles.

                </div>

                <?php
            } ?>

        </div>

    </main>

    <footer class="footer-cliente">

        <div class="container">

            <span>

                <i class="bi bi-tree-fill"></i>

                EcoFauna — Tienda del visitante

            </span>

            <small>

                Conservar • Educar • Proteger

            </small>

        </div>

    </footer>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="../js/tienda_cliente.js"></script>
</body>

</html>