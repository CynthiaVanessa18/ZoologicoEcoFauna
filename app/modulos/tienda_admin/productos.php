<?php
require_once __DIR__ . "/../../soporte/sesion.php";
require_once __DIR__ . "/../../../config/database.php";
require_once __DIR__ . "/../../soporte/funciones_tienda.php";

/** @var mysqli $conexion */

// =====================================
// VALIDAR ADMINISTRADOR
// =====================================

if (!isset($_SESSION["usuario"]) || $_SESSION["rol"] != "Administrador") {
    header("Location: ../index.php");
    exit();
}

// =====================================
// FILTROS
// =====================================

$categoria = $_GET["categoria"] ?? "";

$buscar = $_GET["buscar"] ?? "";

// =====================================
// PAGINACIÓN
// =====================================

// Productos por página
$limite = 9;

// Página actual
$pagina = isset($_GET["pagina"]) ? intval($_GET["pagina"]) : 1;

// Evitar páginas negativas
if ($pagina < 1) {
    $pagina = 1;
}

// Inicio del registro
$inicio = ($pagina - 1) * $limite;

// =====================================
// CATEGORÍAS
// =====================================

$categorias = mysqli_query(
    $conexion,
    "
    SELECT DISTINCT tipo_producto
    FROM Producto
    ORDER BY tipo_producto
",
);

// =====================================
// CONDICIONES FILTRO
// =====================================

$where = " WHERE p.estado='Activo' ";

if (!empty($categoria)) {
    $categoria = mysqli_real_escape_string($conexion, $categoria);

    $where .= "
        AND p.tipo_producto='$categoria'
    ";
}

if (!empty($buscar)) {
    $buscar = mysqli_real_escape_string($conexion, $buscar);

    $where .= "
        AND p.nombre_producto LIKE '%$buscar%'
    ";
}

// =====================================
// CONTAR PRODUCTOS
// =====================================

$sql_total = "

SELECT COUNT(*) AS total

FROM inventario_tienda i

INNER JOIN Producto p

ON i.id_producto = p.id_Producto

$where

";

$resultado_total = mysqli_query($conexion, $sql_total);

$fila_total = mysqli_fetch_assoc($resultado_total);

$total_productos = $fila_total["total"];

// Total páginas

$total_paginas = ceil($total_productos / $limite);

// =====================================
// CONSULTAR PRODUCTOS PAGINADOS
// =====================================

$sql = "

SELECT

p.id_Producto,

p.nombre_producto,

p.tipo_producto,

p.estado,

i.foto,

i.fecha_ingreso,

i.stock,

i.precio_compra,

i.precio_venta

FROM inventario_tienda i

INNER JOIN Producto p

ON i.id_producto = p.id_Producto

$where

ORDER BY p.nombre_producto

LIMIT $inicio,$limite

";

$resultado = mysqli_query($conexion, $sql);
?>

<!DOCTYPE html>
<html lang="es">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Productos Tienda - EcoFauna</title>

    <!-- Bootstrap -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/css/bootstrap.min.css" rel="stylesheet">

    <!-- Iconos -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">

    <!-- Fuentes EcoFauna -->
    <link rel="preconnect" href="https://fonts.googleapis.com">

    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

    <link
        href="https://fonts.googleapis.com/css2?family=DM Serif Display&family=Poppins:wght@400;500;600;700&display=swap"
        rel="stylesheet">

    <!-- CSS DEL SISTEMA -->
    <link rel="stylesheet" href="../css/styleCliente.css?v=2">

    <link rel="stylesheet" href="../css/crud-modern.css?v=1">
    <link rel="stylesheet" href="../css/ecofauna-unified.css?v=1">
</head>

<body class="ecofauna-unified crud-modern">



    <!-- =========================================
     NAVBAR
========================================= -->

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

    <!-- =========================================
     HERO
========================================= -->

    <header class="hero-cliente">

        <div class="container hero-contenido">

            <div class="hero-texto">

                <span class="hero-etiqueta">

                    <i class="bi bi-shop"></i>

                    Gestión de productos

                </span>

                <h1>
                    Productos de tienda
                </h1>

                <p>
                    Administra productos, imágenes,
                    precios e inventario de EcoFauna.
                </p>

                <div class="hero-acciones">

                    <a href="../admin.php" class="btn btn-secundario">

                        <i class="bi bi-arrow-left"></i>

                        Regresar

                    </a>

                    <a href="producto_agregar.php" class="btn btn-principal">

                        <i class="bi bi-plus-circle"></i>

                        Nuevo producto

                    </a>

                </div>

            </div>

            <div class="hero-ilustracion">

                <div class="circulo-grande">

                    <i class="bi bi-bag-heart-fill"></i>

                </div>

                <span class="burbuja burbuja-uno">
                    <i class="bi bi-box-seam"></i>
                </span>

                <span class="burbuja burbuja-dos">
                    <i class="bi bi-camera-fill"></i>
                </span>

                <span class="burbuja burbuja-tres">
                    <i class="bi bi-cash-coin"></i>
                </span>

            </div>

        </div>

    </header>

    <!-- =========================================
     CONTENIDO
========================================= -->

    <main class="container contenido-principal">

        <!-- FILTROS -->

        <section class="seccion-informacion">

            <div class="informacion-principal">

                <span class="informacion-icono">

                    <i class="bi bi-funnel-fill"></i>

                </span>

                <div>

                    <span class="mini-titulo">
                        Filtros
                    </span>

                    <h2>
                        Buscar productos
                    </h2>

                    <p>
                        Filtra productos por categoría o nombre.
                    </p>

                </div>

            </div>

            <form method="GET" class="row g-3 mt-3">

                <div class="col-md-4">

                    <label class="form-label">
                        Categoría
                    </label>

                    <select id="filtroCategoriaProductos" name="categoria" class="form-select">

                        <option value="">
                            Todas
                        </option>

                        <?php while ($cat = mysqli_fetch_assoc($categorias)) { ?>

                            <option value="<?= htmlspecialchars($cat["tipo_producto"]) ?>"
                                <?= $categoria == $cat["tipo_producto"] ? "selected" : "" ?>>

                                <?= htmlspecialchars($cat["tipo_producto"]) ?>

                            </option>

                        <?php } ?>

                    </select>

                </div>

                <div class="col-md-5">

                    <label class="form-label">
                        Buscar producto
                    </label>

                    <input type="text" name="buscar" class="form-control" placeholder="Nombre del producto"
                        value="<?= htmlspecialchars($buscar) ?>">

                </div>

                <div class="col-md-3 d-flex align-items-end">

                    <button class="btn btn-principal me-2">

                        <i class="bi bi-search"></i>

                        Buscar

                    </button>

                    <a href="productos.php" class="btn btn-secundario">

                        <i class="bi bi-x-circle"></i>

                        Limpiar

                    </a>

                </div>

            </form>

        </section>

        <!-- TABLA PRODUCTOS -->

        <section class="seccion-servicios">

            <div class="encabezado-seccion">

                <div>

                    <span class="subtitulo-seccion">
                        Inventario
                    </span>

                    <h2>
                        Productos registrados
                    </h2>

                </div>

            </div>

            <div class="table-responsive">

                <table class="table table-hover align-middle tabla-eco">

                    <thead>

                        <tr>

                            <th>
                                Imagen
                            </th>

                            <th>
                                Producto
                            </th>

                            <th>
                                Categoría
                            </th>

                            <th>
                                Fecha ingreso
                            </th>

                            <th>
                                Stock
                            </th>

                            <th>
                                Compra
                            </th>

                            <th>
                                Venta
                            </th>

                            <th>
                                Estado
                            </th>

                            <th>
                                Acciones
                            </th>

                        </tr>

                    </thead>

                    <tbody>

                        <?php if (mysqli_num_rows($resultado) > 0) { ?>

                            <?php while ($producto = mysqli_fetch_assoc($resultado)) { ?>

                                <tr>

                                    <td>

                                        <?php $imagenProducto = crearDataUriImagenProducto(
                                            $producto["foto"] ?? null,
                                        ); ?>

                                        <?php if ($imagenProducto !== null) { ?>

                                            <img src="<?= htmlspecialchars($imagenProducto, ENT_QUOTES, "UTF-8") ?>"
                                                class="producto-imagen">

                                        <?php } else { ?>

                                            <i class="bi bi-image fs-2"></i>

                                        <?php } ?>

                                    </td>

                                    <td>

                                        <?= htmlspecialchars($producto["nombre_producto"]) ?>

                                    </td>

                                    <td>

                                        <span class="badge bg-success">

                                            <?= htmlspecialchars($producto["tipo_producto"]) ?>

                                        </span>

                                    </td>

                                    <td>

                                        <?= htmlspecialchars($producto["fecha_ingreso"]) ?>

                                    </td>

                                    <td>

                                        <?= $producto["stock"] ?>

                                    </td>

                                    <td>

                                        ₡<?= number_format($producto["precio_compra"], 2) ?>

                                    </td>

                                    <td>

                                        ₡<?= number_format($producto["precio_venta"], 2) ?>

                                    </td>

                                    <td>

                                        <span class="badge bg-primary">

                                            <?= htmlspecialchars($producto["estado"]) ?>

                                        </span>

                                    </td>

                                    <td>

                                        <a href="producto_editar.php?id=<?= $producto["id_Producto"] ?>"
                                            class="btn btn-editar btn-sm">

                                            <i class="bi bi-pencil"></i>

                                        </a>

                                        <a href="producto_eliminar.php?id=<?= $producto["id_Producto"] ?>"
                                            class="btn btn-eliminar btn-sm">

                                            <i class="bi bi-trash"></i>

                                        </a>

                                    </td>

                                </tr>

                            <?php } ?>

                        <?php } else { ?>

                            <tr>

                                <td colspan="9" class="text-center">

                                    No hay productos registrados

                                </td>

                            </tr>

                        <?php } ?>

                    </tbody>

                </table>

            </div>

            <!-- PAGINACIÓN -->

            <?php if ($total_paginas > 1) { ?>

                <nav>

                    <ul class="pagination justify-content-center mt-4">

                        <?php for ($i = 1; $i <= $total_paginas; $i++) { ?>

                            <li class="page-item <?= $pagina == $i ? "active" : "" ?>">

                                <a class="page-link" href="?pagina=<?= $i ?>&categoria=<?= urlencode(
                                      $categoria,
                                  ) ?>&buscar=<?= urlencode($buscar) ?>">

                                    <?= $i ?>

                                </a>

                            </li>

                        <?php } ?>

                    </ul>

                </nav>

            <?php } ?>

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

    <script src="../js/productos_admin.js"></script>

</body>

</html>