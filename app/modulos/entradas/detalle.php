<?php
require_once __DIR__ . "/../../soporte/sesion.php";

require_once __DIR__ . "/../../../config/database.php";

/** @var mysqli $conexion */

if (
    !isset($_SESSION["usuario"], $_SESSION["rol"], $_SESSION["id_login"]) ||
    !in_array($_SESSION["rol"], ["Empleado", "Cliente"], true)
) {
    header("Location: index.php");
    exit();
}

$rol = $_SESSION["rol"];
$idUsuario = (int) ($_SESSION["id_usuario"] ?? 0);
$rutaRegreso = $rol === "Cliente" ? "mis_entradas.php" : "empleado.php";

if ($rol === "Cliente" && $idUsuario <= 0) {
    header("Location: index.php");
    exit();
}

$idTicket = filter_input(INPUT_GET, "id", FILTER_VALIDATE_INT);

if (!$idTicket || $idTicket <= 0) {
    header("Location: " . $rutaRegreso);
    exit();
}

function escaparDetalle(string $texto): string
{
    return htmlspecialchars($texto, ENT_QUOTES, "UTF-8");
}

function regresarDesdeDetalle(string $ruta): void
{
    header("Location: " . $ruta);
    exit();
}

/* ===========================
   ENCABEZADO DEL TICKET
=========================== */

$sqlTicket = "
    SELECT
        t.id_ticket_entrada,
        t.fecha_emision,
        t.metodo_pago,
        t.total_ticket,
        t.iva,
        t.estado,
        d.fecha AS fecha_visita
    FROM ticket_entrada t
    INNER JOIN disponibilidad_dia_zoo d
        ON d.id_disponibilidad = t.id_disponibilidad
    WHERE t.id_ticket_entrada = ?
";

if ($rol === "Cliente") {
    $sqlTicket .= " AND t.id_usuario = ?";
}

$stmt = mysqli_prepare($conexion, $sqlTicket);

if (!$stmt) {
    error_log(
        "Error preparando el detalle del ticket: " . mysqli_error($conexion),
    );
    regresarDesdeDetalle($rutaRegreso);
}

if ($rol === "Cliente") {
    mysqli_stmt_bind_param($stmt, "ii", $idTicket, $idUsuario);
} else {
    mysqli_stmt_bind_param($stmt, "i", $idTicket);
}

if (!mysqli_stmt_execute($stmt)) {
    error_log(
        "Error consultando el detalle del ticket: " .
            mysqli_stmt_error($stmt),
    );
    mysqli_stmt_close($stmt);
    regresarDesdeDetalle($rutaRegreso);
}

$ticket = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

if (!$ticket) {
    registrarBitacora(
        $_SESSION["usuario"],
        "CONSULTA TICKET DENEGADA",
        "ticket_entrada",
        "Se intentó consultar un ticket inexistente o sin autorización.",
        (int) $idTicket,
    );

    regresarDesdeDetalle($rutaRegreso);
}

/* ===========================
   DETALLE DEL TICKET
=========================== */

$sqlDetalle = "
    SELECT
        te.categoria,
        dt.cantidad,
        dt.precio,
        dt.subtotal
    FROM detalle_ticket_entrada dt
    INNER JOIN tarifa_entrada te
        ON te.id_tarifa = dt.id_tarifa
    WHERE dt.id_ticket_entrada = ?
";

$stmt = mysqli_prepare($conexion, $sqlDetalle);

if (!$stmt) {
    error_log(
        "Error preparando los conceptos del ticket: " . mysqli_error($conexion),
    );
    regresarDesdeDetalle($rutaRegreso);
}

mysqli_stmt_bind_param($stmt, "i", $idTicket);

if (!mysqli_stmt_execute($stmt)) {
    error_log(
        "Error consultando los conceptos del ticket: " .
            mysqli_stmt_error($stmt),
    );
    mysqli_stmt_close($stmt);
    regresarDesdeDetalle($rutaRegreso);
}

$detalle = mysqli_stmt_get_result($stmt);
mysqli_stmt_close($stmt);

/* ===========================
   CODIGOS DE BOLETOS
=========================== */

$sqlBoletos = "
    SELECT codigo_ingreso
    FROM boleto_zoologico
    WHERE id_ticket_entrada = ?
    ORDER BY id_boleto_zoologico
";

$stmt = mysqli_prepare($conexion, $sqlBoletos);

if (!$stmt) {
    error_log(
        "Error preparando los códigos del ticket: " . mysqli_error($conexion),
    );
    regresarDesdeDetalle($rutaRegreso);
}

mysqli_stmt_bind_param($stmt, "i", $idTicket);

if (!mysqli_stmt_execute($stmt)) {
    error_log(
        "Error consultando los códigos del ticket: " .
            mysqli_stmt_error($stmt),
    );
    mysqli_stmt_close($stmt);
    regresarDesdeDetalle($rutaRegreso);
}

$boletos = mysqli_stmt_get_result($stmt);
mysqli_stmt_close($stmt);
?>
<!DOCTYPE html>
<html lang="es">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Detalle Ticket - EcoFauna</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/css/bootstrap.min.css" rel="stylesheet">

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">

    <link rel="preconnect" href="https://fonts.googleapis.com">

    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

    <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display&family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="css/styleEmpleado.css">

    <link rel="stylesheet" href="css/ecofauna-unified.css?v=1">
</head>

<body class="ecofauna-unified">

    <nav class="navbar navbar-expand-lg navbar-ecofauna shadow">

        <div class="container-fluid">

            <a class="navbar-brand d-flex align-items-center" href="<?= $rutaRegreso ?>">

                <img src="img/LogoEcoFauna1.png"
                    class="logo-navbar"
                    alt="Logo EcoFauna">

                <div class="ms-3">
                    <strong>EcoFauna</strong><br>
                    <small>Detalle del Ticket</small>
                </div>

            </a>

            <div class="navbar-usuario">

                <i class="bi bi-person-circle me-2"></i>

                <?= escaparDetalle($_SESSION["usuario"]) ?>

                <a href="<?= $rutaRegreso ?>" class="btn btn-outline-light btn-sm ms-3">
                    <i class="bi bi-arrow-left"></i>
                    Volver
                </a>

                <form action="logout.php" method="POST" class="m-0">
                    <?= campoCsrfSesion("logout") ?>

                    <button type="submit" class="btn btn-salir">
                        <i class="bi bi-box-arrow-right"></i>
                        Salir
                    </button>
                </form>

            </div>

        </div>

    </nav>

    <main class="container mt-4">

        <!-- INFORMACIÓN DEL TICKET -->

        <section class="card card-shadow mb-4">

            <div class="card-header bg-primary text-white">

                <h5 class="mb-0">

                    <i class="bi bi-receipt-cutoff me-2"></i>

                    Ticket #<?= (int) $ticket["id_ticket_entrada"] ?>

                </h5>

            </div>

            <div class="card-body">

                <div class="row">

                    <div class="col-md-6">

                        <div class="mb-3">
                            <strong>Fecha de compra</strong><br>
                            <?= date(
                                "d/m/Y H:i",
                                strtotime($ticket["fecha_emision"]),
                            ) ?>
                        </div>

                        <div class="mb-3">
                            <strong>Fecha de visita</strong><br>
                            <?= date("d/m/Y", strtotime($ticket["fecha_visita"])) ?>
                        </div>

                        <div class="mb-3">
                            <strong>Método de pago</strong><br>

                            <span class="badge bg-info text-dark fs-6">

                                <?= escaparDetalle($ticket["metodo_pago"]) ?>

                            </span>

                        </div>

                    </div>

                    <div class="col-md-6">

                        <div class="mb-3">

                            <strong>Estado</strong><br>

                            <?php if ($ticket["estado"] === "Pagada"): ?>

                                <span class="badge bg-success fs-6">

                                    <?= escaparDetalle($ticket["estado"]) ?>

                                </span>

                            <?php else: ?>

                                <span class="badge bg-secondary fs-6">

                                    <?= escaparDetalle($ticket["estado"]) ?>

                                </span>

                            <?php endif; ?>

                        </div>

                        <div class="mb-3">

                            <strong>IVA</strong><br>

                            ₡<?= number_format($ticket["iva"], 2, ",", ".") ?>

                        </div>

                        <div class="mb-3">

                            <strong>Total</strong><br>

                            <span class="fw-bold text-success fs-4">

                                ₡<?= number_format(
                                        $ticket["total_ticket"],
                                        2,
                                        ",",
                                        ".",
                                    ) ?>

                            </span>

                        </div>

                    </div>

                </div>

            </div>

        </section>

        <!-- DETALLE -->

        <section class="card card-shadow mb-4">

            <div class="card-header bg-primary text-white">

                <h5 class="mb-0">

                    <i class="bi bi-list-ul me-2"></i>

                    Detalle de la Compra

                </h5>

            </div>

            <div class="card-body">

                <div class="table-responsive">

                    <table class="table table-hover table-bordered align-middle">

                        <thead class="table-dark">

                            <tr>

                                <th>Categoría</th>

                                <th>Cantidad</th>

                                <th>Precio</th>

                                <th>Subtotal</th>

                            </tr>

                        </thead>

                        <tbody>

                            <?php
                            $subtotal = 0;

                            while ($fila = mysqli_fetch_assoc($detalle)):
                                $subtotal += $fila["subtotal"]; ?>

                                <tr>

                                    <td>

                                        <?= escaparDetalle($fila["categoria"]) ?>

                                    </td>

                                    <td>

                                        <?= (int) $fila["cantidad"] ?>

                                    </td>

                                    <td>

                                        ₡<?= number_format($fila["precio"], 2, ",", ".") ?>

                                    </td>

                                    <td class="fw-bold">

                                        ₡<?= number_format(
                                                $fila["subtotal"],
                                                2,
                                                ",",
                                                ".",
                                            ) ?>

                                    </td>

                                </tr>

                            <?php
                            endwhile;
                            ?>

                        </tbody>

                        <tfoot>

                            <tr>

                                <th colspan="3" class="text-end">

                                    Subtotal

                                </th>

                                <th>

                                    ₡<?= number_format($subtotal, 2, ",", ".") ?>

                                </th>

                            </tr>

                            <tr>

                                <th colspan="3" class="text-end">

                                    IVA

                                </th>

                                <th>

                                    ₡<?= number_format(
                                            $ticket["iva"],
                                            2,
                                            ",",
                                            ".",
                                        ) ?>

                                </th>

                            </tr>

                            <tr class="table-success">

                                <th colspan="3" class="text-end">

                                    TOTAL

                                </th>

                                <th>

                                    ₡<?= number_format(
                                            $ticket["total_ticket"],
                                            2,
                                            ",",
                                            ".",
                                        ) ?>

                                </th>

                            </tr>

                        </tfoot>

                    </table>

                </div>

            </div>

        </section>

        <!-- CÓDIGOS -->

        <section class="card card-shadow">

            <div class="card-header bg-primary text-white">

                <h5 class="mb-0">

                    <i class="bi bi-upc-scan me-2"></i>

                    Códigos de Ingreso

                </h5>

            </div>

            <div class="card-body">

                <div class="table-responsive">

                    <table class="table table-hover table-bordered align-middle">

                        <thead class="table-dark">

                            <tr>

                                <th>#</th>

                                <th>Código</th>

                            </tr>

                        </thead>

                        <tbody>

                            <?php
                            $i = 1;

                            while ($boleto = mysqli_fetch_assoc($boletos)): ?>

                                <tr>

                                    <td>

                                        <?= $i++ ?>

                                    </td>

                                    <td>

                                        <span class="badge bg-success fs-6 px-3 py-2">

                                            <?= escaparDetalle($boleto["codigo_ingreso"]) ?>

                                        </span>

                                    </td>

                                </tr>

                            <?php endwhile;
                            ?>

                        </tbody>

                    </table>

                </div>

                <div class="mt-4">

                    <a href="<?= $rutaRegreso ?>" class="btn btn-secondary">

                        <i class="bi bi-arrow-left me-2"></i>

                        Volver

                    </a>

                    <button id="imprimirTicket" type="button" class="btn btn-success">

                        <i class="bi bi-printer me-2"></i>

                        Imprimir

                    </button>

                </div>

            </div>

        </section>

    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.bundle.min.js"></script>
    <script src="js/detalle_ticket.js"></script>

</body>

</html>