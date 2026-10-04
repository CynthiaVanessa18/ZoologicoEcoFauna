<?php
require_once __DIR__ . "/../../soporte/sesion.php";

require_once __DIR__ . "/../../../config/database.php";
/** @var mysqli $conexion */

if (
    !isset($_SESSION["usuario"], $_SESSION["rol"], $_SESSION["id_login"]) ||
    $_SESSION["rol"] !== "Empleado"
) {
    header("Location: index.php");
    exit();
}

$_SESSION["csrf_cancelar_ticket"] ??= bin2hex(random_bytes(32));

$mensajeVenta = $_SESSION["mensaje_ticket"] ??
    $_SESSION["mensaje_permiso"] ??
    "";
$tipoMensaje = $_SESSION["tipo_mensaje_ticket"] ??
    (isset($_SESSION["mensaje_permiso"]) ? "warning" : "");

unset(
    $_SESSION["mensaje_ticket"],
    $_SESSION["tipo_mensaje_ticket"],
    $_SESSION["mensaje_permiso"],
);

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["vender"])) {
    try {
        $tiposBoleto = $_POST["tipo_boleto"] ?? [];
        $cantidades = $_POST["cantidad"] ?? [];
        $metodoPago = trim($_POST["metodo_pago"] ?? "");

        $metodosPermitidos = [
            "Efectivo",
            "Tarjeta Crédito",
            "Tarjeta Débito",
            "Transferencia",
            "Sinpe Móvil",
        ];

        $lineasVenta = [];
        $cantidadTotal = 0;
        $subtotalGeneral = 0.0;

        mysqli_begin_transaction($conexion);

        if (!in_array($metodoPago, $metodosPermitidos, true)) {
            throw new RuntimeException(
                "El método de pago seleccionado no es válido.",
            );
        }

        $sqlPrecio = "
            SELECT categoria, precio
            FROM Tarifa_Entrada
            WHERE id_tarifa = ?
              AND estado = 'Activa'
            LIMIT 1
        ";

        $stmtPrecio = mysqli_prepare($conexion, $sqlPrecio);
        if (!$stmtPrecio) {
            throw new RuntimeException(mysqli_error($conexion));
        }

        foreach ($tiposBoleto as $indice => $idTarifaRaw) {
            $idTarifa = filter_var($idTarifaRaw, FILTER_VALIDATE_INT);
            $cantidad = filter_var(
                $cantidades[$indice] ?? null,
                FILTER_VALIDATE_INT,
                ["options" => ["min_range" => 1, "max_range" => 20]],
            );

            if (!$idTarifa || !$cantidad) {
                throw new RuntimeException(
                    "Hay una línea de boletos inválida.",
                );
            }

            mysqli_stmt_bind_param($stmtPrecio, "i", $idTarifa);
            mysqli_stmt_execute($stmtPrecio);
            $resultadoPrecio = mysqli_stmt_get_result($stmtPrecio);
            $tarifa = mysqli_fetch_assoc($resultadoPrecio);

            if (!$tarifa) {
                throw new RuntimeException(
                    "Una de las tarifas seleccionadas no está activa.",
                );
            }

            $precio = (float) $tarifa["precio"];
            $subtotalLinea = round($precio * $cantidad, 2);

            $lineasVenta[] = [
                "id_tarifa" => (int) $idTarifa,
                "categoria" => $tarifa["categoria"],
                "precio" => $precio,
                "cantidad" => (int) $cantidad,
                "subtotal" => $subtotalLinea,
            ];

            $cantidadTotal += (int) $cantidad;
            $subtotalGeneral += $subtotalLinea;
        }
        mysqli_stmt_close($stmtPrecio);
        if ($cantidadTotal <= 0) {
            throw new RuntimeException(
                "Debes agregar al menos un boleto a la venta.",
            );
        }

        if ($cantidadTotal > 50) {
            throw new RuntimeException(
                "La venta no puede superar 50 boletos en total.",
            );
        }
        $subtotalGeneral = round($subtotalGeneral, 2);
        $iva = round($subtotalGeneral * 0.13, 2);
        $total = round($subtotalGeneral + $iva, 2);
        $sqlDisponibilidad = "
            SELECT id_disponibilidad, fecha, capacidad_disponible
            FROM Disponibilidad_Dia_Zoo
            WHERE fecha >= CURDATE()
              AND capacidad_disponible >= ?
              AND estado = 'Disponible'
            ORDER BY fecha ASC
            LIMIT 1
            FOR UPDATE
        ";
        $stmtDisponibilidad = mysqli_prepare($conexion, $sqlDisponibilidad);
        if (!$stmtDisponibilidad) {
            throw new RuntimeException(mysqli_error($conexion));
        }
        mysqli_stmt_bind_param($stmtDisponibilidad, "i", $cantidadTotal);
        mysqli_stmt_execute($stmtDisponibilidad);
        $resultadoDisponibilidad = mysqli_stmt_get_result($stmtDisponibilidad);
        $dia = mysqli_fetch_assoc($resultadoDisponibilidad);
        mysqli_stmt_close($stmtDisponibilidad);

        if (!$dia) {
            throw new RuntimeException(
                "No hay fechas disponibles con capacidad suficiente.",
            );
        }
        $idDisponibilidad = (int) $dia["id_disponibilidad"];
        $fechaVisita = $dia["fecha"];
        $stmtTicket = mysqli_prepare(
            $conexion,
            "CALL registrarVenta(?, ?, ?, ?, @idTicket)",
        );
        if (!$stmtTicket) {
            throw new RuntimeException(mysqli_error($conexion));
        }
        mysqli_stmt_bind_param(
            $stmtTicket,
            "sddi",
            $metodoPago,
            $total,
            $iva,
            $idDisponibilidad,
        );

        if (!mysqli_stmt_execute($stmtTicket)) {
            throw new RuntimeException(mysqli_stmt_error($stmtTicket));
        }

        mysqli_stmt_close($stmtTicket);

        /* Muy importante */
        while (mysqli_more_results($conexion)) {
            mysqli_next_result($conexion);
        }
        $res = mysqli_query($conexion, "SELECT @idTicket AS id");

        $fila = mysqli_fetch_assoc($res);

        $idTicket = (int) $fila["id"];

        if ($idTicket <= 0) {
            throw new RuntimeException(
                "No fue posible obtener el ID del ticket.",
            );
        }

        $sqlDetalle = "
    INSERT INTO Detalle_Ticket_Entrada (
        id_ticket_entrada, id_tarifa, cantidad, precio, subtotal
    ) VALUES (?, ?, ?, ?, ?)
";
        $stmtDetalle = mysqli_prepare($conexion, $sqlDetalle);
        if (!$stmtDetalle) {
            throw new RuntimeException(mysqli_error($conexion));
        }

        foreach ($lineasVenta as $linea) {
            mysqli_stmt_bind_param(
                $stmtDetalle,
                "iiidd",
                $idTicket,
                $linea["id_tarifa"],
                $linea["cantidad"],
                $linea["precio"],
                $linea["subtotal"],
            );
            if (!mysqli_stmt_execute($stmtDetalle)) {
                throw new RuntimeException(mysqli_stmt_error($stmtDetalle));
            }
        }
        mysqli_stmt_close($stmtDetalle);

        $sqlExisteCodigo = "
    SELECT id_boleto_zoologico
    FROM Boleto_zoologico
    WHERE codigo_ingreso = ?
    LIMIT 1
";

        $codigoExiste = true;
        $intentos = 0;
        $codigo = "";

        while ($codigoExiste && $intentos < 20) {
            $codigo = strtoupper(substr(bin2hex(random_bytes(4)), 0, 5));

            $stmtExiste = mysqli_prepare($conexion, $sqlExisteCodigo);

            mysqli_stmt_bind_param($stmtExiste, "s", $codigo);

            mysqli_stmt_execute($stmtExiste);

            $resultadoExiste = mysqli_stmt_get_result($stmtExiste);

            $codigoExiste = mysqli_num_rows($resultadoExiste) > 0;

            mysqli_stmt_close($stmtExiste);

            $intentos++;
        }

        if ($codigoExiste) {
            throw new RuntimeException(
                "No fue posible generar un código único.",
            );
        }

        /*
         * Registrar un único código para todo el ticket.
         */
        $stmtBoleto = mysqli_prepare(
            $conexion,
            "CALL sp_Registrarboleto(?, ?)",
        );

        if (!$stmtBoleto) {
            throw new RuntimeException(mysqli_error($conexion));
        }

        mysqli_stmt_bind_param($stmtBoleto, "is", $idTicket, $codigo);

        if (!mysqli_stmt_execute($stmtBoleto)) {
            throw new RuntimeException(mysqli_stmt_error($stmtBoleto));
        }

        mysqli_stmt_close($stmtBoleto);

        /* Limpiar resultados del procedimiento almacenado */
        while (mysqli_more_results($conexion)) {
            mysqli_next_result($conexion);
        }

        /* Se conserva como arreglo para mostrarlo en el mensaje */
        $codigos = [$codigo];

        $sqlCapacidad = "
            UPDATE Disponibilidad_Dia_Zoo
            SET capacidad_disponible = capacidad_disponible - ?
            WHERE id_disponibilidad = ?
              AND capacidad_disponible >= ?
        ";
        $stmtCapacidad = mysqli_prepare($conexion, $sqlCapacidad);
        mysqli_stmt_bind_param(
            $stmtCapacidad,
            "iii",
            $cantidadTotal,
            $idDisponibilidad,
            $cantidadTotal,
        );
        mysqli_stmt_execute($stmtCapacidad);

        if (mysqli_stmt_affected_rows($stmtCapacidad) !== 1) {
            throw new RuntimeException(
                "La disponibilidad cambió antes de completar la venta.",
            );
        }
        mysqli_stmt_close($stmtCapacidad);

        $resumenLineas = [];
        foreach ($lineasVenta as $linea) {
            $resumenLineas[] = $linea["cantidad"] . " " . $linea["categoria"];
        }

        $detalles = sprintf(
            "Venta de %d boleto(s): %s. Fecha: %s. Total: %.2f",
            $cantidadTotal,
            implode(", ", $resumenLineas),
            $fechaVisita,
            $total,
        );

        if (
            !registrarBitacora(
                $_SESSION["usuario"],
                "VENTA ENTRADA",
                "Ticket_Entrada",
                $detalles,
                $idTicket,
            )
        ) {
            throw new RuntimeException("No fue posible registrar la bitácora.");
        }

        mysqli_commit($conexion);

        $detalleHtml = "<ul class='mb-2'>";
        foreach ($lineasVenta as $linea) {
            $detalleHtml .=
                "<li>" .
                (int) $linea["cantidad"] .
                " × " .
                htmlspecialchars($linea["categoria"], ENT_QUOTES, "UTF-8") .
                " — ₡" .
                number_format($linea["subtotal"], 2, ",", ".") .
                "</li>";
        }
        $detalleHtml .= "</ul>";

        $mensajeVenta =
            "
            <h5 class='mb-3'> ¡Venta registrada exitosamente!</h5>
            <strong>Ticket:</strong> #" .
            (int) $idTicket .
            "<br>
            <strong>Fecha de visita:</strong> " .
            date("d/m/Y", strtotime($fechaVisita)) .
            "<br>
            <strong>Detalle:</strong> " .
            $detalleHtml .
            "
            <strong>Subtotal:</strong> ₡" .
            number_format($subtotalGeneral, 2, ",", ".") .
            "<br>
            <strong>IVA (13%):</strong> ₡" .
            number_format($iva, 2, ",", ".") .
            "<br>
            <strong>Total:</strong> ₡" .
            number_format($total, 2, ",", ".") .
            "<br>
        ";
        $tipoMensaje = "success";
    } catch (Throwable $e) {
        mysqli_rollback($conexion);
        error_log("Error de venta: " . $e->getMessage());
        $mensajeVenta =
            "❌ " . htmlspecialchars($e->getMessage(), ENT_QUOTES, "UTF-8");
        $tipoMensaje = "danger";
    }
}

// Tarifas activas.
$sqlTarifas = "
    SELECT id_tarifa, categoria, precio
    FROM Tarifa_Entrada
    WHERE estado = 'Activa'
    ORDER BY precio ASC
";

$resultadoTarifas = mysqli_query($conexion, $sqlTarifas);

if (!$resultadoTarifas) {
    error_log(mysqli_error($conexion));
}

$filtroTicket = trim($_GET["ticket"] ?? "");
$filtroEstado = trim($_GET["estado"] ?? "");
$filtroPago = trim($_GET["pago"] ?? "");
$filtroFecha = trim($_GET["fecha"] ?? "");

$where = [];

if ($filtroTicket != "") {
    $where[] = "t.id_ticket_entrada = " . (int) $filtroTicket;
}

if ($filtroEstado != "") {
    $where[] =
        "t.estado = '" .
        mysqli_real_escape_string($conexion, $filtroEstado) .
        "'";
} else {
    // Por defecto, ocultar los tickets cancelados (borrado lógico de la pantalla)
    $where[] = "t.estado != 'Cancelada'";
}

if ($filtroPago != "") {
    $where[] =
        "t.metodo_pago = '" .
        mysqli_real_escape_string($conexion, $filtroPago) .
        "'";
}

if ($filtroFecha != "") {
    $where[] =
        "DATE(t.fecha_emision) = '" .
        mysqli_real_escape_string($conexion, $filtroFecha) .
        "'";
}

$filtroSQL = "";

if (!empty($where)) {
    $filtroSQL = " WHERE " . implode(" AND ", $where);
}

// Lógica de Paginación para Tickets
$registrosPorPagina = 5;
$paginaActual = isset($_GET["pagina"]) ? (int) $_GET["pagina"] : 1;
if ($paginaActual < 1) {
    $paginaActual = 1;
}

$sqlContar = "SELECT COUNT(DISTINCT t.id_ticket_entrada) AS total FROM Ticket_Entrada t INNER JOIN Disponibilidad_Dia_Zoo d ON d.id_disponibilidad = t.id_disponibilidad $filtroSQL";
$resContar = mysqli_query($conexion, $sqlContar);
$filaContar = mysqli_fetch_assoc($resContar);
$totalRegistros = $filaContar["total"] ?? 0;
$totalPaginas = ceil($totalRegistros / $registrosPorPagina);
if ($totalPaginas < 1) {
    $totalPaginas = 1;
}
if ($paginaActual > $totalPaginas) {
    $paginaActual = $totalPaginas;
}
$offset = ($paginaActual - 1) * $registrosPorPagina;

// Historial de tickets vendidos
$sqlTickets = "

SELECT
    t.id_ticket_entrada,
    t.fecha_emision,
    t.metodo_pago,
    t.total_ticket,
    t.iva,
    t.estado,
    d.fecha AS fecha_visita,

    (
        SELECT COALESCE(SUM(subtotal),0)
        FROM Detalle_Ticket_Entrada dt
        WHERE dt.id_ticket_entrada = t.id_ticket_entrada
    ) AS subtotal,

    (
    SELECT COALESCE(SUM(dt.cantidad), 0)
    FROM Detalle_Ticket_Entrada dt
    WHERE dt.id_ticket_entrada = t.id_ticket_entrada
) AS boletos

FROM Ticket_Entrada t

INNER JOIN Disponibilidad_Dia_Zoo d
ON d.id_disponibilidad = t.id_disponibilidad

$filtroSQL

GROUP BY
t.id_ticket_entrada,
t.fecha_emision,
t.metodo_pago,
t.total_ticket,
t.iva,
t.estado,
d.fecha

ORDER BY t.id_ticket_entrada DESC
LIMIT $registrosPorPagina OFFSET $offset
";

$resultadoTickets = mysqli_query($conexion, $sqlTickets);

if (!$resultadoTickets) {
    die(mysqli_error($conexion));
}

if (isset($_GET["ajax"])) {
    ob_start();
    if (mysqli_num_rows($resultadoTickets) > 0) {
        while ($ticket = mysqli_fetch_assoc($resultadoTickets)) {
            echo "<tr>";
            echo "<td>#" . $ticket["id_ticket_entrada"] . "</td>";
            echo "<td>" .
                date("d/m/Y H:i", strtotime($ticket["fecha_emision"])) .
                "</td>";
            echo "<td>" .
                date("d/m/Y", strtotime($ticket["fecha_visita"])) .
                "</td>";
            echo "<td>" . $ticket["boletos"] . "</td>";
            echo "<td>" . htmlspecialchars($ticket["metodo_pago"]) . "</td>";
            echo "<td>₡" .
                number_format($ticket["subtotal"], 2, ",", ".") .
                "</td>";
            echo '<td class="text-danger fw-bold">₡' .
                number_format($ticket["iva"], 2, ",", ".") .
                "</td>";
            echo '<td class="text-success fw-bold">₡' .
                number_format($ticket["total_ticket"], 2, ",", ".") .
                "</td>";
            echo "<td>";
            $estado = strtolower($ticket["estado"] ?? "pendiente");
            if ($estado == "pagada") {
                echo '<span class="badge bg-success">Pagada</span>';
            } elseif ($estado == "pendiente") {
                echo '<span class="badge bg-warning text-dark">Pendiente</span>';
            } elseif ($estado == "cancelada") {
                echo '<span class="badge bg-danger">Cancelada</span>';
            } else {
                echo '<span class="badge bg-secondary">' .
                    htmlspecialchars($ticket["estado"] ?? "Sin estado") .
                    "</span>";
            }
            echo "</td>";
            echo "<td>";
            echo '<a href="detalle_ticket.php?id=' .
                $ticket["id_ticket_entrada"] .
                '" class="btn btn-info btn-sm"><i class="bi bi-eye"></i></a> ';
            echo '<button type="button" class="btn btn-danger btn-sm btn-cancelar-ticket"
                    data-ticket="' .
                (int) $ticket["id_ticket_entrada"] .
                '">
                    <i class="bi bi-trash"></i>
                  </button>';
            echo "</td>";
            echo "</tr>";
        }
    } else {
        echo '<tr><td colspan="10" class="text-center">No hay tickets registrados.</td></tr>';
    }
    $tbodyHtml = ob_get_clean();

    ob_start();
    if ($totalPaginas > 1) {
        echo '<ul class="pagination justify-content-center">';
        echo '<li class="page-item ' .
            ($paginaActual <= 1 ? "disabled" : "") .
            '">';
        echo '<a class="page-link" href="#" data-pagina="' .
            ($paginaActual - 1) .
            '">Anterior</a>';
        echo "</li>";
        for ($i = 1; $i <= $totalPaginas; $i++) {
            echo '<li class="page-item ' .
                ($paginaActual === $i ? "active" : "") .
                '">';
            echo '<a class="page-link" href="#" data-pagina="' .
                $i .
                '">' .
                $i .
                "</a>";
            echo "</li>";
        }
        echo '<li class="page-item ' .
            ($paginaActual >= $totalPaginas ? "disabled" : "") .
            '">';
        echo '<a class="page-link" href="#" data-pagina="' .
            ($paginaActual + 1) .
            '">Siguiente</a>';
        echo "</li>";
        echo "</ul>";
    }
    $pagHtml = ob_get_clean();

    header("Content-Type: application/json");
    echo json_encode([
        "tbody" => $tbodyHtml,
        "paginacion" => $pagHtml,
    ]);
    exit();
}
?>

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Panel de Empleado - EcoFauna</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/css/bootstrap.min.css" rel="stylesheet">

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=DM+Serif+Display&family=Poppins:wght@400;500;600;700&display=swap"
        rel="stylesheet">
    <link rel="stylesheet" href="css/styleEmpleado.css">
    <link rel="stylesheet" href="css/crud-modern.css?v=1">
    <link rel="stylesheet" href="css/ecofauna-unified.css?v=1">
</head>

<body class="ecofauna-unified crud-modern">



    <nav class="navbar navbar-expand-lg navbar-ecofauna shadow">

        <div class="container-fluid">

            <a class="navbar-brand d-flex align-items-center" href="#">

                <img src="img/LogoEcoFauna1.png" class="logo-navbar" alt="Logo EcoFauna">

                <div class="ms-3">

                    <strong>EcoFauna</strong><br>
                    <small>Panel de Empleado</small>

                </div>

            </a>

            <div class="navbar-usuario">

               <a href="mi_perfil.php" class="text-decoration-none text-white">
    <i class="bi bi-person-circle me-2"></i>
    <?= htmlspecialchars(
        $_SESSION["usuario"],
        ENT_QUOTES,
        "UTF-8",
    ) ?>
</a>

                <form action="logout.php" method="POST" class="m-0">
                    <?= campoCsrfSesion("logout") ?>

                    <button type="submit" class="btn btn-salir">
                        <i class="bi bi-box-arrow-right"></i>
                        Cerrar sesión
                    </button>
                </form>

            </div>

        </div>

    </nav>

    <main class="container mt-4">

        <?php if ($mensajeVenta !== ""): ?>
            <div class="alert alert-<?= $tipoMensaje ?> shadow-sm">
                <?= $mensajeVenta ?>
            </div>
        <?php endif; ?>
        <div class="row mb-4">

            <div class="col-md-4">

                <a href="inventario.php" class="text-decoration-none">

                    <div class="card shadow border-0 dashboard-card h-100">

                        <div class="card-body text-center">

                            <i class="bi bi-box-seam-fill display-3 text-success mb-3"></i>

                            <h4 class="fw-bold">
                                Inventario
                            </h4>

                            <p class="text-muted mb-0">
                                Consultar y administrar productos del zoológico.
                            </p>

                        </div>

                    </div>

                </a>

            </div>
            <div class="col-md-4">

                <a href="acceso/historial_accesos.php" class="text-decoration-none">

                    <div class="card shadow border-0 dashboard-card h-100">

                        <div class="card-body text-center">

                            <i class="bi bi-person-check-fill display-3 text-primary mb-3"></i>

                            <h4 class="fw-bold">
                                Control de Accesos
                            </h4>

                            <p class="text-muted mb-0">
                                Validar entradas, registrar ingresos y controlar visitantes del zoológico.
                            </p>

                        </div>

                    </div>

                </a>

            </div>

        </div>
        <section class="card card-shadow mb-4">

            <div class="card-header bg-primary text-white">
                <h5 class="mb-0">
                    <i class="bi bi-ticket-fill me-2"></i>
                    Venta de boletos
                </h5>
            </div>

            <div class="card-body">

                <?php if (!$resultadoTarifas): ?>

                    <div class="alert alert-danger mb-0">
                        No fue posible cargar las tarifas.
                    </div>

                <?php elseif (mysqli_num_rows($resultadoTarifas) === 0): ?>

                    <div class="alert alert-warning mb-0">
                        No hay tarifas activas disponibles.
                    </div>

                <?php else: ?>

                    <form method="POST" id="formVenta">

                        <div id="lineasBoletos">
                            <div class="linea-boleto row g-3 align-items-end mb-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">Tipo de boleto</label>
                                    <select name="tipo_boleto[]" class="form-select tipo-boleto" required>
                                        <option value="">Seleccione</option>
                                        <?php
                                        mysqli_data_seek($resultadoTarifas, 0);
                                        while (
                                            $tarifa = mysqli_fetch_assoc(
                                                $resultadoTarifas,
                                            )
                                        ): ?>
                                            <option value="<?= (int) $tarifa["id_tarifa"] ?>"
                                                data-precio="<?= htmlspecialchars(
                                                                    (string) $tarifa["precio"],
                                                                    ENT_QUOTES,
                                                                    "UTF-8",
                                                                ) ?>">
                                                <?= htmlspecialchars(
                                                    $tarifa["categoria"],
                                                    ENT_QUOTES,
                                                    "UTF-8",
                                                ) ?>
                                                — ₡<?= number_format(
                                                        (float) $tarifa["precio"],
                                                        2,
                                                        ",",
                                                        ".",
                                                    ) ?>
                                            </option>
                                        <?php endwhile;
                                        ?>
                                    </select>
                                </div>

                                <div class="col-md-3">
                                    <label class="form-label fw-bold">Cantidad</label>
                                    <input type="number" name="cantidad[]" class="form-control cantidad-boleto" min="1"
                                        max="20" value="1" required>
                                </div>

                                <div class="col-md-3">
                                    <button type="button" class="btn btn-outline-danger w-100 btn-quitar-linea" disabled>
                                        <i class="bi bi-trash"></i> Quitar
                                    </button>
                                </div>
                            </div>
                        </div>

                        <button type="button" id="agregarLinea" class="btn btn-outline-success mb-4">
                            <i class="bi bi-plus-circle me-1"></i>
                            Agregar otro tipo de boleto
                        </button>

                        <div class="card shadow border-0 mb-4">

                            <div class="card-header bg-primary text-white">
                                <h5 class="mb-0">
                                    <i class="bi bi-cash-stack me-2"></i>
                                    Resumen de la compra
                                </h5>
                            </div>

                            <div class="card-body">

                                <div class="row mb-2">
                                    <div class="col-6">
                                        <strong>Subtotal</strong>
                                    </div>
                                    <div class="col-6 text-end">
                                        <span id="subtotalVista">₡0,00</span>
                                    </div>
                                </div>
                                <div class="row mb-2">
                                    <div class="col-6">
                                        <strong>IVA (13%)</strong>
                                    </div>
                                    <div class="col-6 text-end">
                                        <span id="ivaVista">₡0,00</span>
                                    </div>
                                </div>
                                <hr>
                                <div class="row">
                                    <div class="col-6">
                                        <h4 class="fw-bold text-success mb-0">
                                            Total a pagar
                                        </h4>
                                    </div>
                                    <div class="col-6 text-end">
                                        <h4 class="fw-bold text-success mb-0" id="totalVista">
                                            ₡0,00
                                        </h4>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="row g-3 align-items-end">
                            <div class="col-md-5">
                                <label class="form-label fw-bold" for="metodo_pago">Método de pago</label>
                                <select id="metodo_pago" name="metodo_pago" class="form-select" required>
                                    <option value="Efectivo">Efectivo</option>
                                    <option value="Tarjeta Crédito">Tarjeta Crédito</option>
                                    <option value="Tarjeta Débito">Tarjeta Débito</option>
                                    <option value="Transferencia">Transferencia</option>
                                    <option value="Sinpe Móvil">Sinpe Móvil</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <div class="resumen-previo">
                                    <small>Total de boletos</small>
                                    <strong id="totalBoletos">1</strong>
                                </div>
                            </div>

                            <div class="col-md-4">
                                <button type="submit" class="btn btn-success w-100" name="vender" value="1">
                                    <i class="bi bi-cart-check me-2"></i>
                                    Registrar venta
                                </button>
                            </div>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </section>
        <div class="card shadow-sm mb-3">
            <div class="card-body">
                <form method="GET">
                    <div class="row g-3">
                        <div class="col-md-2">
                            <label class="form-label">Ticket</label>
                            <input
                                type="number"
                                name="ticket"
                                class="form-control"
                                value="<?= htmlspecialchars($filtroTicket) ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Estado</label>
                            <select name="estado" class="form-select">
                                <option value="">Todos</option>
                                <option value="Pagada"
                                    <?= $filtroEstado == "Pagada"
                                        ? "selected"
                                        : "" ?>>
                                    Pagada
                                </option>
                                <option value="Pendiente"
                                    <?= $filtroEstado == "Pendiente"
                                        ? "selected"
                                        : "" ?>>
                                    Pendiente
                                </option>
                                <option value="Cancelada"
                                    <?= $filtroEstado == "Cancelada"
                                        ? "selected"
                                        : "" ?>>
                                    Cancelada
                                </option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Método pago</label>
                            <select name="pago" class="form-select">
                                <option value="">Todos</option>
                                <option value="Efectivo">Efectivo</option>
                                <option value="Tarjeta Crédito">Tarjeta Crédito</option>
                                <option value="Tarjeta Débito">Tarjeta Débito</option>
                                <option value="Transferencia">Transferencia</option>
                                <option value="Sinpe Móvil">Sinpe Móvil</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Fecha</label>
                            <input
                                type="date"
                                name="fecha"
                                class="form-control"
                                value="<?= htmlspecialchars($filtroFecha) ?>">
                        </div>
                        <div class="col-md-2 d-grid">
                            <label class="form-label">&nbsp;</label>
                            <button class="btn btn-success w-100">
                                <i class="bi bi-funnel-fill me-2"></i>
                                Filtrar
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
        <section class="card card-shadow mb-4">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0">
                    <i class="bi bi-receipt-cutoff me-2"></i>
                    Historial de Tickets Vendidos
                </h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-bordered table-hover align-middle">
                        <thead class="table-dark">
                            <tr>
                                <th>Ticket</th>
                                <th>Fecha Compra</th>
                                <th>Fecha Visita</th>
                                <th>Boletos</th>
                                <th>Método Pago</th>
                                <th>Subtotal</th>
                                <th>IVA</th>
                                <th>Total</th>
                                <th>Estado</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody id="tbody-tickets">
                            <?php if (
                                mysqli_num_rows($resultadoTickets) > 0
                            ): ?>
                                <?php while (
                                    $ticket = mysqli_fetch_assoc(
                                        $resultadoTickets,
                                    )
                                ): ?>
                                    <tr>
                                        <td>
                                            #<?= $ticket["id_ticket_entrada"] ?>
                                        </td>
                                        <td>
                                            <?= date(
                                                "d/m/Y H:i",
                                                strtotime(
                                                    $ticket["fecha_emision"],
                                                ),
                                            ) ?>
                                        </td>
                                        <td>
                                            <?= date(
                                                "d/m/Y",
                                                strtotime(
                                                    $ticket["fecha_visita"],
                                                ),
                                            ) ?>
                                        </td>
                                        <td>
                                            <?= $ticket["boletos"] ?>
                                        </td>
                                        <td>
                                            <?= htmlspecialchars(
                                                $ticket["metodo_pago"],
                                            ) ?>
                                        </td>
                                        <!-- SUBTOTAL -->
                                        <td>
                                            ₡<?= number_format(
                                                    $ticket["subtotal"],
                                                    2,
                                                    ",",
                                                    ".",
                                                ) ?>
                                        </td>
                                        <!-- IVA -->
                                        <td class="text-danger fw-bold">

                                            ₡<?= number_format(
                                                    $ticket["iva"],
                                                    2,
                                                    ",",
                                                    ".",
                                                ) ?>
                                        </td>
                                        <!-- TOTAL -->
                                        <td class="text-success fw-bold">
                                            ₡<?= number_format(
                                                    $ticket["total_ticket"],
                                                    2,
                                                    ",",
                                                    ".",
                                                ) ?>
                                        </td>
                                        <td>
                                            <?php
                                            $estado = strtolower(
                                                $ticket["estado"] ??
                                                    "pendiente",
                                            );
                                            if ($estado == "pagada"): ?>
                                                <span class="badge bg-success">Pagada</span>
                                            <?php elseif (
                                                $estado == "pendiente"
                                            ): ?>
                                                <span class="badge bg-warning text-dark">Pendiente</span>
                                            <?php elseif (
                                                $estado == "cancelada"
                                            ): ?>
                                                <span class="badge bg-danger">Cancelada</span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">
                                                    <?= htmlspecialchars(
                                                        $ticket["estado"] ??
                                                            "Sin estado",
                                                    ) ?>
                                                </span>
                                            <?php endif;
                                            ?>
                                        </td>
                                        <td>
                                            <a href="detalle_ticket.php?id=<?= $ticket["id_ticket_entrada"] ?>"
                                                class="btn btn-info btn-sm">
                                                <i class="bi bi-eye"></i>
                                            </a>
                                            <button
                                                type="button"
                                                class="btn btn-danger btn-sm btn-cancelar-ticket"
                                                data-ticket="<?= (int) $ticket["id_ticket_entrada"] ?>">

                                                <i class="bi bi-trash"></i>

                                            </button>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="10" class="text-center">
                                        No hay tickets registrados.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>

                    <!-- Paginación de Tickets -->
                    <nav aria-label="Paginacion tickets" class="mt-3" id="paginacion-tickets">
                        <?php if ($totalPaginas > 1): ?>
                            <ul class="pagination justify-content-center">
                                <li class="page-item <?= $paginaActual <= 1
                                                            ? "disabled"
                                                            : "" ?>">
                                    <a class="page-link" href="#" data-pagina="<?= $paginaActual -
                                                                                    1 ?>">Anterior</a>
                                </li>
                                <?php for (
                                    $i = 1;
                                    $i <= $totalPaginas;
                                    $i++
                                ): ?>
                                    <li class="page-item <?= $paginaActual ===
                                                                $i
                                                                ? "active"
                                                                : "" ?>">
                                        <a class="page-link" href="#" data-pagina="<?= $i ?>"><?= $i ?></a>
                                    </li>
                                <?php endfor; ?>
                                <li class="page-item <?= $paginaActual >=
                                                            $totalPaginas
                                                            ? "disabled"
                                                            : "" ?>">
                                    <a class="page-link" href="#" data-pagina="<?= $paginaActual +
                                                                                    1 ?>">Siguiente</a>
                                </li>
                            </ul>
                        <?php endif; ?>
                    </nav>

                </div>
            </div>
        </section>
        <!-- Modal para cancelar ticket -->

        <div
            class="modal fade modal-confirmacion"
            id="modalCancelarTicket"
            tabindex="-1"
            aria-hidden="true">

            <div class="modal-dialog modal-dialog-centered">

                <div class="modal-content">

                    <div class="modal-header">

                        <h5 class="modal-title">

                            <i class="bi bi-exclamation-triangle-fill"></i>
                            Cancelar ticket

                        </h5>

                        <button
                            type="button"
                            class="btn-close btn-close-white"
                            data-bs-dismiss="modal"
                            aria-label="Cerrar">
                        </button>

                    </div>

                    <div class="modal-body">

                        <p id="mensajeCancelarTicket" class="mb-2"></p>

                        <small class="text-muted">

                            Los códigos quedarán inhabilitados y la capacidad
                            será devuelta al día correspondiente.

                        </small>

                    </div>

                    <div class="modal-footer">

                        <button
                            type="button"
                            class="btn btn-secondary"
                            data-bs-dismiss="modal">

                            No, regresar

                        </button>

                        <form
                            id="formCancelarTicket"
                            action="eliminar_ticket.php"
                            method="POST"
                            class="m-0">

                            <input
                                type="hidden"
                                name="csrf_token"
                                value="<?= htmlspecialchars(
                                            $_SESSION["csrf_cancelar_ticket"],
                                            ENT_QUOTES,
                                            "UTF-8",
                                        ) ?>">

                            <input
                                type="hidden"
                                name="id_ticket"
                                id="idTicketCancelar">

                            <button
                                type="submit"
                                id="confirmarCancelarTicket"
                                class="btn btn-danger">

                                <i class="bi bi-x-circle-fill"></i>
                                Sí, cancelar

                            </button>

                        </form>

                    </div>

                </div>

            </div>

        </div>
    </main>

    <div
        id="configEmpleado"
        hidden
        data-filtro-ticket="<?= htmlspecialchars(
                                $filtroTicket,
                                ENT_QUOTES,
                                "UTF-8",
                            ) ?>"
        data-filtro-estado="<?= htmlspecialchars(
                                $filtroEstado,
                                ENT_QUOTES,
                                "UTF-8",
                            ) ?>"
        data-filtro-pago="<?= htmlspecialchars(
                                $filtroPago,
                                ENT_QUOTES,
                                "UTF-8",
                            ) ?>"
        data-filtro-fecha="<?= htmlspecialchars(
                                $filtroFecha,
                                ENT_QUOTES,
                                "UTF-8",
                            ) ?>">
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.bundle.min.js"></script>
    <script src="js/empleado.js"></script>
</body>

</html>
