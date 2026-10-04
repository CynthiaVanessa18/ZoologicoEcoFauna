<?php

require_once __DIR__ . "/../../soporte/sesion.php";
require_once __DIR__ . "/../../../config/database.php";
require_once __DIR__ . "/../../soporte/funciones_tienda.php";

/** @var mysqli $conexion */

/* =====================================================
   CONFIGURACIÓN DE CIFRADO
===================================================== */

$CLAVE_CIFRADO = claveCifradoEcoFauna();


/* =====================================================
   FUNCIÓN PARA DESCIFRAR DATOS
===================================================== */

function descifrarDato(
    $datoCifrado,
    $clave
) {

    $metodo = "aes-256-gcm";

    if (empty($datoCifrado)) {
        return false;
    }

    $datos = base64_decode(
        $datoCifrado,
        true
    );

    if ($datos === false) {
        return false;
    }

    /*
     * IV = 12 bytes
     * TAG = 16 bytes
     * Mínimo = 28 bytes
     */

    if (strlen($datos) < 28) {
        return false;
    }

    $iv = substr(
        $datos,
        0,
        12
    );

    $tag = substr(
        $datos,
        12,
        16
    );

    $cifrado = substr(
        $datos,
        28
    );

    $key = hash(
        "sha256",
        $clave,
        true
    );

    $descifrado = openssl_decrypt(
        $cifrado,
        $metodo,
        $key,
        OPENSSL_RAW_DATA,
        $iv,
        $tag
    );

    if ($descifrado === false) {
        return false;
    }

    return $descifrado;
}

/* =====================================================
   VALIDAR SESIÓN
===================================================== */

if (
    !isset($_SESSION["usuario"]) ||
    !isset($_SESSION["rol"]) ||
    $_SESSION["rol"] !== "Cliente"
) {
    header("Location: ../index.php");
    exit();
}

/* =====================================================
   DATOS INICIALES
===================================================== */

$carrito = $_SESSION["carrito"] ?? [];

$total = 0;

$id_usuario = (int) $_SESSION["id_usuario"];

/* =====================================================
   OBTENER TARJETAS ACTIVAS
===================================================== */

$sql = "SELECT
            id_tarjeta,
            banco,
            tipo_tarjeta,
            numero_tarjeta
        FROM tarjeta_cliente
        WHERE id_usuario = ?
        AND estado = 'Activa'";

$stmt = mysqli_prepare($conexion, $sql);

if (!$stmt) {
    die("Error al preparar la consulta de tarjetas.");
}

mysqli_stmt_bind_param(
    $stmt,
    "i",
    $id_usuario
);

mysqli_stmt_execute($stmt);

$tarjetas = mysqli_stmt_get_result($stmt);
/* =====================================================
   PREPARAR ÚLTIMOS 4 DÍGITOS DE LAS TARJETAS
===================================================== */

$tarjetasArray = [];

while ($tarjeta = mysqli_fetch_assoc($tarjetas)) {

    /*
     * Desciframos solamente para obtener
     * los últimos 4 dígitos.
     */

    $numeroReal = descifrarDato(
        $tarjeta["numero_tarjeta"],
        $CLAVE_CIFRADO
    );

    $ultimos4 = "----";

    if (
        $numeroReal !== false &&
        preg_match(
            '/^[0-9]+$/',
            $numeroReal
        )
    ) {

        $ultimos4 = substr(
            $numeroReal,
            -4
        );
    }

    $tarjeta["ultimos4"] = $ultimos4;

    /*
     * Eliminamos el número cifrado del array
     * para evitar utilizarlo accidentalmente.
     */

    unset($tarjeta["numero_tarjeta"]);

    $tarjetasArray[] = $tarjeta;
}

/* =====================================================
   OBTENER DIRECCIÓN DEL CLIENTE
===================================================== */

$sql = "SELECT *
        FROM direccion_entrega
        WHERE id_usuario = ?
        LIMIT 1";

$stmtDireccion = mysqli_prepare(
    $conexion,
    $sql
);

if (!$stmtDireccion) {
    die("Error al preparar la consulta de dirección.");
}

mysqli_stmt_bind_param(
    $stmtDireccion,
    "i",
    $id_usuario
);

mysqli_stmt_execute($stmtDireccion);

$resultadoDireccion = mysqli_stmt_get_result(
    $stmtDireccion
);

$direccionEntrega = mysqli_fetch_assoc(
    $resultadoDireccion
);

/* =====================================================
   GUARDAR PÁGINA PARA VOLVER DESDE DIRECCIÓN
===================================================== */

$_SESSION["volver_direccion"] = $_SERVER["REQUEST_URI"];

/* =====================================================
   TIPO DE ENTREGA SELECCIONADO
===================================================== */

$tipoEntregaSeleccionado =
    $_GET["tipo_entrega"]
    ?? $_SESSION["tipo_entrega"]
    ?? "";

?>

<!DOCTYPE html>

<html lang="es">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        Carrito EcoFauna
    </title>

    <!-- Bootstrap -->

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <!-- Iconos -->

    <link
        rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css"
    >

    <!-- Fuente -->

    <link
        href="https://fonts.googleapis.com/css2?family=DM+Serif+Display&family=Poppins:wght@400;500;600;700&display=swap"
        rel="stylesheet"
    >

    <!-- CSS CLIENTE -->

    <link
        rel="stylesheet"
        href="../css/styleCliente.css?v=2"
    >

    <link rel="stylesheet" href="../css/ecofauna-unified.css?v=1">
</head>

<body class="ecofauna-unified">
<div class="alert alert-info text-center m-3" role="note">Demostración de portafolio: pagos simulados. Utiliza únicamente las tarjetas ficticias de prueba.</div>

<!-- =====================================================
     NAVBAR
===================================================== -->

<nav class="navbar navbar-cliente">

    <div class="container-fluid px-4">

        <a
            href="cliente.php"
            class="navbar-brand"
        >

            <img
                src="../img/LogoEcoFauna1.png"
                class="logo-navbar-cliente"
                alt="EcoFauna"
            >

            <span>

                EcoFauna

                <small>
                    Carrito de compras
                </small>

            </span>

        </a>

        <div class="usuario-nav">

            <a
                href="cliente.php"
                class="btn btn-outline-light"
            >

                <i class="bi bi-arrow-left"></i>

                Tienda

            </a>

            <a
                href="../mi_perfil.php"
                class="usuario-info text-decoration-none"
            >

                <span class="usuario-avatar">

                    <i class="bi bi-person-fill"></i>

                </span>

                <div>

                    <small>
                        Cliente
                    </small>

                    <strong>

                        <?= htmlspecialchars(
                            $_SESSION["usuario"],
                            ENT_QUOTES,
                            "UTF-8"
                        ) ?>

                    </strong>

                </div>

            </a>

            <form
                action="../logout.php"
                method="POST"
                class="d-inline"
            >

                <?= campoCsrfSesion("logout") ?>

                <button
                    type="submit"
                    class="btn btn-salir"
                >

                    <i class="bi bi-box-arrow-right"></i>

                    Cerrar sesión

                </button>

            </form>

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

                <i class="bi bi-cart-fill"></i>

                Mi compra

            </span>

            <h1>

                Carrito de productos

            </h1>

            <p>

                Revisa tus productos seleccionados antes
                de finalizar tu compra en EcoFauna.

            </p>

        </div>

        <div class="hero-ilustracion">

            <div class="circulo-grande">

                <i class="bi bi-bag-check-fill"></i>

            </div>

            <span class="burbuja burbuja-uno">

                <i class="bi bi-gift-fill"></i>

            </span>

            <span class="burbuja burbuja-dos">

                <i class="bi bi-cart-check-fill"></i>

            </span>

            <span class="burbuja burbuja-tres">

                <i class="bi bi-stars"></i>

            </span>

        </div>

    </div>

</header>


<!-- =====================================================
     CONTENIDO PRINCIPAL
===================================================== -->

<main class="container contenido-principal">

<?php if (empty($carrito)) { ?>

    <div class="alert alert-warning text-center p-4">

        <i class="bi bi-cart-x fs-1"></i>

        <h4 class="mt-3">

            Tu carrito está vacío

        </h4>

        <a
            href="cliente.php"
            class="btn btn-success mt-3 btn-carrito"
        >

            <i class="bi bi-shop"></i>

            Ir a la tienda

        </a>

    </div>

<?php exit(); ?>

<?php } ?>


<section class="carrito-card">

    <!-- =================================================
         PRODUCTOS
    ================================================== -->

    <h2 class="text-success mb-4">

        <i class="bi bi-cart-fill"></i>

        Productos seleccionados

    </h2>


    <div class="table-responsive">

        <table class="table align-middle tabla-carrito">

            <thead class="table-success">

                <tr>

                    <th>
                        Producto
                    </th>

                    <th>
                        Precio
                    </th>

                    <th>
                        Cantidad
                    </th>

                    <th>
                        Subtotal
                    </th>

                </tr>

            </thead>

            <tbody>

            <?php

            foreach ($carrito as $id => $cantidad) {

                $idProducto = (int) $id;
                $cantidad = (int) $cantidad;

                if ($cantidad <= 0) {
                    continue;
                }

                /* =========================================
                   OBTENER PRODUCTO
                ========================================== */

                $sql = "SELECT
                            p.nombre_producto,
                            i.foto,
                            i.precio_venta,
                            i.stock
                        FROM Inventario_tienda i
                        INNER JOIN Producto p
                            ON i.id_producto = p.id_Producto
                        WHERE i.id_producto = ?";

                $stmtProducto = mysqli_prepare(
                    $conexion,
                    $sql
                );

                if (!$stmtProducto) {
                    continue;
                }

                mysqli_stmt_bind_param(
                    $stmtProducto,
                    "i",
                    $idProducto
                );

                mysqli_stmt_execute(
                    $stmtProducto
                );

                $resultadoProducto =
                    mysqli_stmt_get_result(
                        $stmtProducto
                    );

                $producto =
                    mysqli_fetch_assoc(
                        $resultadoProducto
                    );

                mysqli_stmt_close(
                    $stmtProducto
                );

                if (!$producto) {
                    continue;
                }

                /* =========================================
                   CALCULAR SUBTOTAL
                ========================================== */

                $precio =
                    (float) $producto["precio_venta"];

                $subtotal =
                    $precio * $cantidad;

                $total += $subtotal;

                ?>

                <tr>

                    <!-- PRODUCTO -->

                    <td>

                        <div class="d-flex align-items-center gap-3">

                            <?php

                            $imagenProducto =
                                crearDataUriImagenProducto(
                                    $producto["foto"] ?? null
                                );

                            ?>

                            <?php if (
                                $imagenProducto !== null
                            ) { ?>

                                <img
                                    src="<?= htmlspecialchars(
                                        $imagenProducto,
                                        ENT_QUOTES,
                                        "UTF-8"
                                    ) ?>"
                                    class="producto-img"
                                    alt="<?= htmlspecialchars(
                                        $producto["nombre_producto"],
                                        ENT_QUOTES,
                                        "UTF-8"
                                    ) ?>"
                                >

                            <?php } else { ?>

                                <div class="sin-imagen">

                                    <i class="bi bi-image"></i>

                                </div>

                            <?php } ?>

                            <strong>

                                <?= htmlspecialchars(
                                    $producto["nombre_producto"],
                                    ENT_QUOTES,
                                    "UTF-8"
                                ) ?>

                            </strong>

                        </div>

                    </td>


                    <!-- PRECIO -->

                    <td>

                        ₡<?= number_format(
                            $precio,
                            2,
                            ".",
                            ","
                        ) ?>

                    </td>


                    <!-- CANTIDAD -->

                    <td>

                        <span class="badge bg-success">

                            <?= $cantidad ?>

                        </span>

                    </td>


                    <!-- SUBTOTAL -->

                    <td>

                        <strong>

                            ₡<?= number_format(
                                $subtotal,
                                2,
                                ".",
                                ","
                            ) ?>

                        </strong>

                    </td>

                </tr>

                <?php

            }

            ?>

            </tbody>

        </table>

    </div>


    <!-- =================================================
         TOTAL
    ================================================== -->

    <div class="total-box text-end mb-4">

        Total:

        <strong class="text-success">

            ₡<?= number_format(
                $total,
                2,
                ".",
                ","
            ) ?>

        </strong>

    </div>


    <!-- =================================================
         BOTONES
    ================================================== -->

    <div
        class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4"
    >

        <div class="d-flex gap-2">

            <!-- SEGUIR COMPRANDO -->

            <a
                href="cliente.php"
                class="btn btn-secondary"
            >

                <i class="bi bi-arrow-left"></i>

                Seguir comprando

            </a>


            <!-- VACIAR -->

            <form
                action="vaciar_carrito.php"
                method="POST"
                class="m-0"
            >

                <?= campoCsrfSesion("carrito") ?>

                <button
                    type="submit"
                    class="btn btn-danger"
                >

                    <i class="bi bi-trash"></i>

                    Vaciar

                </button>

            </form>

        </div>

    </div>


    <!-- =================================================
         FORMULARIO DE COMPRA
    ================================================== -->

    <div class="card shadow-sm p-4 border-0">

        <form
            action="finalizar_compra.php"
            method="POST"
            id="formCompra"
        >

            <?= campoCsrfSesion("carrito") ?>


            <!-- =========================================
                 MÉTODO DE PAGO
            ========================================== -->

            <h4 class="mb-3 text-success">

                <i class="bi bi-credit-card-fill"></i>

                Método de pago

            </h4>


            <div class="mb-4">

                <label class="form-label">

                    Seleccione una tarjeta registrada

                </label>


                <?php if (!empty($tarjetasArray)) { ?>

    <select
        class="form-select"
        name="id_tarjeta"
        required
    >

        <option value="">
            Seleccione una tarjeta
        </option>

        <?php foreach (
            $tarjetasArray
            as $tarjeta
        ) { ?>

            <option
                value="<?= (int) $tarjeta["id_tarjeta"] ?>"
            >

                <?= htmlspecialchars(
                    $tarjeta["banco"],
                    ENT_QUOTES,
                    "UTF-8"
                ) ?>

                -

                <?= htmlspecialchars(
                    $tarjeta["tipo_tarjeta"],
                    ENT_QUOTES,
                    "UTF-8"
                ) ?>

                |

                **** **** ****

                <?= htmlspecialchars(
                    $tarjeta["ultimos4"],
                    ENT_QUOTES,
                    "UTF-8"
                ) ?>

            </option>

        <?php } ?>

    </select>

<?php } else { ?>

    <div class="alert alert-warning mb-3">

        <i class="bi bi-credit-card"></i>

        No tienes ninguna tarjeta registrada.

    </div>

    <a
        href="registrar_tarjeta.php"
        class="btn btn-success"
    >

        <i class="bi bi-plus-circle"></i>

        Registrar tarjeta

    </a>

<?php } ?>

            </div>


            <div class="alert alert-info">

                <i class="bi bi-shield-check"></i>

                El pago será procesado utilizando la
                tarjeta seleccionada.

            </div>


            <hr>


            <!-- =========================================
                 FORMA DE ENTREGA
            ========================================== -->

            <h4 class="mb-3 text-success">

                <i class="bi bi-truck"></i>

                Forma de entrega

            </h4>


            <div class="mb-3">

                <label
                    class="form-label"
                    for="tipo_entrega"
                >

                    Tipo de entrega

                </label>


                <select
                    class="form-select"
                    id="tipo_entrega"
                    name="tipo_entrega"
                    required
                >

                    <option value="">

                        Seleccione...

                    </option>


                    <option
                        value="Entrega"
                        <?= (
                            $tipoEntregaSeleccionado === "Entrega"
                        )
                            ? "selected"
                            : "" ?>
                    >

                        Entrega a domicilio

                    </option>


                    <option
                        value="Retiro"
                        <?= (
                            $tipoEntregaSeleccionado === "Retiro"
                        )
                            ? "selected"
                            : "" ?>
                    >

                        Retiro en sucursal

                    </option>

                </select>

            </div>


            <!-- =========================================
                 DATOS DE ENTREGA
            ========================================== -->

            <div
                id="datosEntrega"
                style="display:none;"
            >

                <?php if ($direccionEntrega) { ?>

                    <div class="card border-success shadow-sm">

                        <div
                            class="card-header bg-success text-white d-flex justify-content-between align-items-center"
                        >

                            <span>

                                <i class="bi bi-geo-alt-fill"></i>

                                Dirección de entrega

                            </span>


                            <a
                                href="../usuario/direccion.php"
                                onclick="guardarPosicion()"
                                class="btn btn-light btn-sm"
                            >

                                <i class="bi bi-pencil-square"></i>

                                Editar

                            </a>

                        </div>


                        <div class="card-body">

                            <div class="row">

                                <div class="col-md-6 mb-2">

                                    <strong>
                                        Recibe:
                                    </strong>

                                    <br>

                                    <?= htmlspecialchars(
                                        $direccionEntrega["nombre_recibe"],
                                        ENT_QUOTES,
                                        "UTF-8"
                                    ) ?>

                                </div>


                                <div class="col-md-6 mb-2">

                                    <strong>
                                        Provincia:
                                    </strong>

                                    <br>

                                    <?= htmlspecialchars(
                                        $direccionEntrega["provincia"],
                                        ENT_QUOTES,
                                        "UTF-8"
                                    ) ?>

                                </div>


                                <div class="col-md-6 mb-2">

                                    <strong>
                                        Cantón:
                                    </strong>

                                    <br>

                                    <?= htmlspecialchars(
                                        $direccionEntrega["canton"],
                                        ENT_QUOTES,
                                        "UTF-8"
                                    ) ?>

                                </div>


                                <div class="col-md-6 mb-2">

                                    <strong>
                                        Distrito:
                                    </strong>

                                    <br>

                                    <?= htmlspecialchars(
                                        $direccionEntrega["distrito"],
                                        ENT_QUOTES,
                                        "UTF-8"
                                    ) ?>

                                </div>


                                <div class="col-12 mt-2">

                                    <strong>
                                        Dirección exacta:
                                    </strong>

                                    <br>

                                    <?= nl2br(
                                        htmlspecialchars(
                                            $direccionEntrega["direccion"],
                                            ENT_QUOTES,
                                            "UTF-8"
                                        )
                                    ) ?>

                                </div>

                            </div>

                        </div>

                    </div>

                <?php } else { ?>

                    <div class="alert alert-warning">

                        <h6 class="mb-2">

                            <i class="bi bi-exclamation-triangle-fill"></i>

                            No tienes una dirección registrada.

                        </h6>


                        <p class="mb-3">

                            Debes registrar una dirección
                            para recibir tus pedidos.

                        </p>


                        <a
                            href="../usuario/direccion.php"
                            onclick="guardarPosicion()"
                            class="btn btn-success"
                        >

                            <i class="bi bi-plus-circle"></i>

                            Registrar dirección

                        </a>

                    </div>

                <?php } ?>

            </div>


            <!-- =========================================
                 DATOS DE RETIRO
            ========================================== -->

            <div
                id="datosRetiro"
                style="display:none;"
            >

                <div class="mb-3">

                    <label
                        class="form-label fw-semibold"
                        for="id_sucursal"
                    >

                        <i class="bi bi-shop"></i>

                        Seleccione la sucursal

                    </label>


                    <select
                        class="form-select"
                        name="id_sucursal"
                        id="id_sucursal"
                    >

                        <option value="">

                            Seleccione una sucursal

                        </option>


                        <?php

                        $sqlSucursal = "
                            SELECT *
                            FROM sucursal
                            WHERE estado = 'Activa'
                            ORDER BY nombre_sucursal
                        ";

                        $resultadoSucursal =
                            mysqli_query(
                                $conexion,
                                $sqlSucursal
                            );


                        if ($resultadoSucursal) {

                            while (
                                $fila =
                                mysqli_fetch_assoc(
                                    $resultadoSucursal
                                )
                            ) {

                        ?>

                            <option
                                value="<?= (int) $fila["id_sucursal"] ?>"
                                data-nombre="<?= htmlspecialchars(
                                    $fila["nombre_sucursal"],
                                    ENT_QUOTES,
                                    "UTF-8"
                                ) ?>"
                                data-provincia="<?= htmlspecialchars(
                                    $fila["provincia"],
                                    ENT_QUOTES,
                                    "UTF-8"
                                ) ?>"
                                data-canton="<?= htmlspecialchars(
                                    $fila["canton"],
                                    ENT_QUOTES,
                                    "UTF-8"
                                ) ?>"
                                data-distrito="<?= htmlspecialchars(
                                    $fila["distrito"],
                                    ENT_QUOTES,
                                    "UTF-8"
                                ) ?>"
                                data-direccion="<?= htmlspecialchars(
                                    $fila["direccion"],
                                    ENT_QUOTES,
                                    "UTF-8"
                                ) ?>"
                                data-telefono="<?= htmlspecialchars(
                                    $fila["telefono"],
                                    ENT_QUOTES,
                                    "UTF-8"
                                ) ?>"
                                data-horario="<?= htmlspecialchars(
                                    $fila["horario"],
                                    ENT_QUOTES,
                                    "UTF-8"
                                ) ?>"
                            >

                                <?= htmlspecialchars(
                                    $fila["nombre_sucursal"],
                                    ENT_QUOTES,
                                    "UTF-8"
                                ) ?>

                            </option>

                        <?php

                            }

                        }

                        ?>

                    </select>

                </div>


                <!-- INFORMACIÓN DE SUCURSAL -->

                <div
                    id="infoSucursal"
                    class="card shadow-sm d-none mb-3"
                >

                    <div class="card-header bg-success text-white">

                        Información de sucursal

                    </div>


                    <div class="card-body">

                        <h5 id="s_nombre"></h5>


                        <p>

                            <i class="bi bi-geo-alt"></i>

                            <span id="s_ubicacion"></span>

                        </p>


                        <p>

                            <i class="bi bi-telephone"></i>

                            <span id="s_telefono"></span>

                        </p>


                        <p>

                            <i class="bi bi-signpost"></i>

                            <span id="s_direccion"></span>

                        </p>


                        <p>

                            <i class="bi bi-clock"></i>

                            <span id="s_horario"></span>

                        </p>

                    </div>

                </div>

            </div>


            <!-- =========================================
                 FINALIZAR COMPRA
            ========================================== -->

            <button
                type="submit"
                class="btn btn-success w-100 mt-4 py-2"
                <?= empty($tarjetasArray) ? "disabled" : "" ?>
            >

                <i class="bi bi-check-circle-fill"></i>

                Finalizar compra

            </button>

        </form>

    </div>

</section>

</main>


<!-- =====================================================
     FOOTER
===================================================== -->

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


<!-- =====================================================
     JAVASCRIPT
===================================================== -->

<script>

/* =====================================================
   ELEMENTOS
===================================================== */

const tipoEntrega =
    document.getElementById("tipo_entrega");

const datosEntrega =
    document.getElementById("datosEntrega");

const datosRetiro =
    document.getElementById("datosRetiro");

const sucursal =
    document.getElementById("id_sucursal");

const infoSucursal =
    document.getElementById("infoSucursal");


/* =====================================================
   CAMBIAR TIPO DE ENTREGA
===================================================== */

function cambiarEntrega() {

    if (!tipoEntrega) {
        return;
    }


    /* ================================================
       ENTREGA A DOMICILIO
    ================================================= */

    if (tipoEntrega.value === "Entrega") {

        datosEntrega.style.display = "block";

        datosRetiro.style.display = "none";


        if (sucursal) {

            sucursal.required = false;

            sucursal.value = "";

        }


        if (infoSucursal) {

            infoSucursal.classList.add("d-none");

        }

    }


    /* ================================================
       RETIRO EN SUCURSAL
    ================================================= */

    else if (tipoEntrega.value === "Retiro") {

        datosEntrega.style.display = "none";

        datosRetiro.style.display = "block";


        if (sucursal) {

            sucursal.required = true;

        }

    }


    /* ================================================
       SIN SELECCIÓN
    ================================================= */

    else {

        datosEntrega.style.display = "none";

        datosRetiro.style.display = "none";


        if (sucursal) {

            sucursal.required = false;

            sucursal.value = "";

        }


        if (infoSucursal) {

            infoSucursal.classList.add("d-none");

        }

    }

}


/* =====================================================
   MOSTRAR INFORMACIÓN DE SUCURSAL
===================================================== */

if (sucursal) {

    sucursal.addEventListener(
        "change",
        function () {

            const opcion =
                this.options[
                    this.selectedIndex
                ];


            if (this.value === "") {

                infoSucursal.classList.add(
                    "d-none"
                );

                return;

            }


            document.getElementById(
                "s_nombre"
            ).textContent =
                opcion.dataset.nombre || "";


            document.getElementById(
                "s_ubicacion"
            ).textContent =
                `${opcion.dataset.distrito || ""},
                 ${opcion.dataset.canton || ""},
                 ${opcion.dataset.provincia || ""}`;


            document.getElementById(
                "s_direccion"
            ).textContent =
                opcion.dataset.direccion || "";


            document.getElementById(
                "s_telefono"
            ).textContent =
                opcion.dataset.telefono ||
                "No disponible";


            document.getElementById(
                "s_horario"
            ).textContent =
                opcion.dataset.horario ||
                "No disponible";


            infoSucursal.classList.remove(
                "d-none"
            );

        }
    );

}


/* =====================================================
   CAMBIO DE ENTREGA
===================================================== */

if (tipoEntrega) {

    tipoEntrega.addEventListener(
        "change",
        function () {

            cambiarEntrega();


            sessionStorage.setItem(
                "tipo_entrega",
                this.value
            );

        }
    );

}


/* =====================================================
   GUARDAR POSICIÓN
===================================================== */

function guardarPosicion() {

    sessionStorage.setItem(
        "scrollCarrito",
        window.scrollY
    );

}


/* =====================================================
   CARGAR PÁGINA
===================================================== */

window.addEventListener(
    "load",
    function () {

        /* =============================================
           RESTAURAR TIPO DE ENTREGA
        ============================================== */

        const entregaGuardada =
            sessionStorage.getItem(
                "tipo_entrega"
            );


        if (
            entregaGuardada &&
            tipoEntrega
        ) {

            tipoEntrega.value =
                entregaGuardada;

        }


        cambiarEntrega();


        /* =============================================
           RESTAURAR SCROLL
        ============================================== */

        const posicion =
            sessionStorage.getItem(
                "scrollCarrito"
            );


        if (posicion !== null) {

            window.scrollTo(
                0,
                parseInt(
                    posicion,
                    10
                )
            );


            sessionStorage.removeItem(
                "scrollCarrito"
            );

        }

    }
);

</script>

</body>

</html>
