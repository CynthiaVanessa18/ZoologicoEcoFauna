<?php
require_once __DIR__ . "/../../soporte/sesion.php";
require_once __DIR__ . "/../../../config/database.php";
require_once __DIR__ . "/../../soporte/funciones_tienda.php";

/** @var mysqli $conexion */

//=========================================
// VALIDAR SESIÓN
//=========================================

if (!isset($_SESSION["usuario"]) || $_SESSION["rol"] !== "Cliente") {
    header("Location: ../index.php");
    exit();
}

$id_usuario = $_SESSION["id_usuario"];

//=========================================
// OBTENER COMPRAS
//=========================================

$stmt = mysqli_prepare($conexion, "CALL sp_MisComprasTienda(?)");

mysqli_stmt_bind_param($stmt, "i", $id_usuario);

mysqli_stmt_execute($stmt);

$resultado = mysqli_stmt_get_result($stmt);
?>

<!DOCTYPE html>
<html lang="es">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Mis Compras | EcoFauna</title>

    <!-- Bootstrap -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/css/bootstrap.min.css" rel="stylesheet">

    <!-- Bootstrap Icons -->
    <link rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">

    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">

    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

    <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display&family=Poppins:wght@400;500;600;700&display=swap"
        rel="stylesheet">

    <!-- CSS -->
    <link rel="stylesheet" href="../css/styleCliente.css?v=2">

    <link rel="stylesheet" href="../css/ecofauna-unified.css?v=1">
</head>

<body class="ecofauna-unified">

    <!-- =====================================
            NAVBAR
    ====================================== -->

    <nav class="navbar navbar-cliente">

        <div class="container-fluid px-4">

            <a class="navbar-brand" href="../cliente.php">

                <img src="../img/LogoEcoFauna1.png"
                    class="logo-navbar-cliente"
                    alt="Logo">

                <span>

                    EcoFauna

                    <small>Portal del visitante</small>

                </span>

            </a>

            <div class="usuario-nav">

                <div class="usuario-info">

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

                <a href="cliente.php" class="btn btn-success">

                    <i class="bi bi-shop"></i>

                    Tienda

                </a>

                <a href="carrito.php" class="btn btn-outline-success">

                    <i class="bi bi-cart-fill"></i>

                    Carrito

                </a>

                <form action="../logout.php" method="POST" class="m-0">
                    <?= campoCsrfSesion("logout") ?>

                    <button type="submit" class="btn btn-salir">
                        <i class="bi bi-box-arrow-right"></i>
                        Salir
                    </button>
                </form>

            </div>

        </div>

    </nav>

    <!-- =====================================
            HERO
    ====================================== -->

    <header class="hero-cliente">

        <div class="container hero-contenido">

            <div class="hero-texto">

                <span class="hero-etiqueta">

                    <i class="bi bi-bag-check-fill"></i>

                    Historial de compras

                </span>

                <h1>

                    Mis compras

                </h1>

                <p>

                    Aquí puedes consultar todas las compras que has realizado
                    en la tienda EcoFauna y revisar el detalle de cada una.

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

                    <i class="bi bi-check-circle-fill"></i>

                </span>

                <span class="burbuja burbuja-tres">

                    <i class="bi bi-gift-fill"></i>

                </span>

            </div>

        </div>

    </header>

    <!-- =====================================
            CONTENIDO
    ====================================== -->

    <main class="container contenido-principal">

        <section class="seccion-servicios">

            <div class="encabezado-seccion">

                <div>

                    <span>Historial</span>

                    <h2>Compras realizadas</h2>

                </div>

                <p>

                    Revisa todas las compras efectuadas en la tienda.

                </p>

            </div>

            <div class="table-responsive">

                <table class="table table-hover align-middle">

                    <thead class="table-success">

                        <tr>
                            <th>Factura</th>
                            <th>Fecha</th>
                            <th>Total</th>
                            <th>Estado</th>
                            <th>Detalle</th>
                        </tr>

                    </thead>

                    <tbody>

                        <?php
                        $compras = [];

                        while ($fila = mysqli_fetch_assoc($resultado)) {
                            $compras[$fila["id_venta_tienda"]][] = $fila;
                        }
                        //=========================================
// PAGINACIÓN
//=========================================

$facturasPorPagina = 5;

$totalFacturas = count($compras);

$totalPaginas = max(1, ceil($totalFacturas / $facturasPorPagina));

$paginaActual = isset($_GET["pagina"])
    ? max(1, (int)$_GET["pagina"])
    : 1;

if ($paginaActual > $totalPaginas) {
    $paginaActual = $totalPaginas;
}

$inicio = ($paginaActual - 1) * $facturasPorPagina;

$comprasPaginadas = array_slice(
    $compras,
    $inicio,
    $facturasPorPagina,
    true
);

                        if (count($compras) > 0) {
foreach ($comprasPaginadas as $idFactura => $productos) {                                $factura = $productos[0]; ?>

                                <!-- FACTURA -->

                                <tr>

                                    <td>

                                        <strong>
                                            #<?= $idFactura ?>
                                        </strong>

                                    </td>

                                    <td>

                                        <?= date("d/m/Y H:i", strtotime($factura["fecha_emision"])) ?>

                                    </td>

                                    <td>

                                        ₡<?= number_format(
                                                array_sum(array_column($productos, "subtotal")),
                                                2,
                                            ) ?>

                                    </td>

                                    <td>

                                        <?php
                                        $estado = strtolower($factura["estado"]);

                                        if ($estado == "pagada") {
                                            $color = "success";
                                        } elseif ($estado == "cancelada") {
                                            $color = "danger";
                                        } elseif ($estado == "reembolsada") {
                                            $color = "warning";
                                        } else {
                                            $color = "secondary";
                                        }
                                        ?>

                                        <span class="badge bg-<?= $color ?>">

                                            <?= ucfirst($factura["estado"]) ?>

                                        </span>

                                    </td>

                                    <td>
                                        <button
                                            class="btn btn-success btn-sm"
                                            type="button"
                                            data-bs-toggle="collapse"
                                            data-bs-target="#detalle<?= $idFactura ?>"
                                            aria-expanded="false"
                                            aria-controls="detalle<?= $idFactura ?>">

                                            <i class="bi bi-chevron-down"></i>
                                            Ver detalle

                                        </button>

                                    </td>

                                </tr>

                                <!-- DETALLE DESPLEGABLE -->

                                <tr>

                                    <td colspan="5" class="p-0">

                                        <div id="detalle<?= $idFactura ?>" class="collapse">

    <div class="p-4 bg-light rounded">

        <h5 class="mb-4">

            <i class="bi bi-receipt-cutoff-fill text-success"></i>

            Detalle de la compra #<?= $idFactura ?>

        </h5>

        <div class="row">

            <div class="col-md-6">

                <table class="table table-borderless table-sm">

                    <tr>
                        <th width="40%">Número de venta</th>
                        <td>#<?= $idFactura ?></td>
                    </tr>

                    <tr>
                        <th>Fecha</th>
                        <td><?= date("d/m/Y H:i", strtotime($factura["fecha_emision"])) ?></td>
                    </tr>

                    <tr>
                        <th>Cliente</th>
                        <td><?= htmlspecialchars($_SESSION["usuario"]) ?></td>
                    </tr>

                    <tr>
                        <th>Método de pago</th>
                        <td><?= htmlspecialchars($factura["metodo_pago"]) ?></td>
                    </tr>

                    <tr>
                        <th>Total pagado</th>
                        <td class="text-success fw-bold">
                            ₡<?= number_format($factura["total_factura"],2,",",".") ?>
                        </td>
                    </tr>

                    <tr>
                        <th>Estado</th>
                        <td>
                            <span class="badge bg-<?= $color ?>">
                                <?= ucfirst($factura["estado"]) ?>
                            </span>
                        </td>
                    </tr>

                </table>

            </div>

            <div class="col-md-6">

                <?php if ($factura["tipo_entrega"] == "Entrega") { ?>

    <strong>Dirección de entrega</strong><br>

    <?= htmlspecialchars($factura["nombre_recibe"]) ?><br>

    <?= htmlspecialchars($factura["provincia"]) ?>,
    <?= htmlspecialchars($factura["canton"]) ?>,
    <?= htmlspecialchars($factura["distrito"]) ?><br>

    <?= htmlspecialchars($factura["direccion"]) ?><br>

    <span class="text-success">
        🚚 Tiempo estimado: 1 a 3 días hábiles
    </span>

<?php } else { ?>

    <strong>Retiro en tienda</strong><br>

    <?= htmlspecialchars($factura["nombre_sucursal"]) ?><br>

    <?= htmlspecialchars($factura["sucursal_provincia"]) ?>,
    <?= htmlspecialchars($factura["sucursal_canton"]) ?>,
    <?= htmlspecialchars($factura["sucursal_distrito"]) ?><br>

    <?= htmlspecialchars($factura["sucursal_direccion"]) ?>

<?php } ?>

            </div>

        </div>

        <hr>

        <h6 class="mb-3">

            <i class="bi bi-bag-fill"></i>

            Productos comprados

        </h6>

        <table class="table table-hover table-bordered">

            <thead class="table-success">

                <tr>

                    <th>Foto</th>

                    <th>Producto</th>

                    <th>Cantidad</th>

                    <th>Precio</th>

                    <th>Subtotal</th>

                </tr>

            </thead>

            <tbody>

                <?php foreach ($productos as $producto) { ?>

                    <tr>

                        <td width="80">

                            <?php
                            $imagenProducto = crearDataUriImagenProducto(
                                $producto["foto"] ?? null
                            );
                            ?>

                            <?php if ($imagenProducto) { ?>

                                <img
                                    src="<?= htmlspecialchars($imagenProducto, ENT_QUOTES, "UTF-8") ?>"
                                    class="rounded"
                                    width="60"
                                    height="60">

                            <?php } else { ?>

                                Sin foto

                            <?php } ?>

                        </td>

                        <td>

                            <?= htmlspecialchars($producto["nombre_producto"]) ?>

                        </td>

                        <td>

                            <?= $producto["cantidad"] ?>

                        </td>

                        <td>

                            ₡<?= number_format($producto["precio_unitario"],2,",",".") ?>

                        </td>

                        <td class="fw-bold">

                            ₡<?= number_format($producto["subtotal"],2,",",".") ?>

                        </td>

                    </tr>

                <?php } ?>

            </tbody>

        </table>

    </div>

</div>
                                    </td>

                                </tr>

                            <?php
                            }
                        } else {
                            ?>

                            <tr>

                                <td colspan="5" class="text-center py-5">

                                    <i class="bi bi-bag-x-fill display-4 text-secondary"></i>

                                    <h4 class="mt-3">

                                        No has realizado compras todavía.

                                    </h4>

                                    <a href="cliente.php"
                                        class="btn btn-success mt-3">

                                        <i class="bi bi-shop"></i>

                                        Ir a la tienda

                                    </a>

                                </td>

                            </tr>

                        <?php
                        }
                        ?>

                    </tbody>

                </table>
<?php if ($totalPaginas > 1) { ?>

<nav class="mt-4">

    <ul class="pagination justify-content-center">

        <!-- Anterior -->
        <li class="page-item <?= ($paginaActual == 1) ? 'disabled' : '' ?>">

            <a class="page-link"
                href="?pagina=<?= $paginaActual - 1 ?>">

                &laquo;

            </a>

        </li>

        <?php for ($i = 1; $i <= $totalPaginas; $i++) { ?>

            <li class="page-item <?= ($i == $paginaActual) ? 'active' : '' ?>">

                <a class="page-link"
                    href="?pagina=<?= $i ?>">

                    <?= $i ?>

                </a>

            </li>

        <?php } ?>

        <!-- Siguiente -->
        <li class="page-item <?= ($paginaActual == $totalPaginas) ? 'disabled' : '' ?>">

            <a class="page-link"
                href="?pagina=<?= $paginaActual + 1 ?>">

                &raquo;

            </a>

        </li>

    </ul>

</nav>

<?php } ?>
            </div>

        </section>

    </main>

    <!-- =====================================
            FOOTER
    ====================================== -->

    <footer class="footer-cliente">

        <div class="container">

            <span>

                <i class="bi bi-tree-fill"></i>

                EcoFauna — Portal del visitante

            </span>

            <small>

                Conservar • Educar • Proteger

            </small>

        </div>

    </footer>

</body>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.bundle.min.js"></script>

</html>

<?php
mysqli_stmt_close($stmt);

while (mysqli_more_results($conexion)) {
    mysqli_next_result($conexion);
}


?>