<?php
require_once __DIR__ . "/../../soporte/sesion.php";

require_once __DIR__ . "/../../../config/database.php";

/** @var mysqli $conexion */

/* =====================================================
   CONTROL DE ACCESO
===================================================== */
if (
    !isset($_SESSION["usuario"], $_SESSION["rol"], $_SESSION["id_login"]) ||
    $_SESSION["rol"] !== "Cliente"
) {
    header("Location: index.php");
    exit();
}

function escapar(string $texto): string
{
    return htmlspecialchars($texto, ENT_QUOTES, "UTF-8");
}

$idUsuario = $_SESSION["id_usuario"] ?? null;
$nombreUsuario = escapar($_SESSION["usuario"]);
$entradas = [];

if ($idUsuario !== null) {
    $sql = "
        SELECT
            te.id_ticket_entrada,
            te.fecha_emision,
            te.metodo_pago,
            te.total_ticket,
            te.estado AS estado_compra,
            ddz.fecha AS fecha_visita,
            bz.codigo_ingreso,
            bz.estado_ticket AS estado_boleto
        FROM ticket_entrada te
        LEFT JOIN disponibilidad_dia_zoo ddz ON te.id_disponibilidad = ddz.id_disponibilidad
        LEFT JOIN boleto_zoologico bz ON te.id_ticket_entrada = bz.id_ticket_entrada
        WHERE te.id_usuario = ?
        ORDER BY te.fecha_emision DESC
    ";

    $stmt = mysqli_prepare($conexion, $sql);
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "i", $idUsuario);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        while ($row = mysqli_fetch_assoc($res)) {
            $entradas[] = $row;
        }
        mysqli_stmt_close($stmt);
    }
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mis Entradas - EcoFauna</title>

    <!-- Bootstrap -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/css/bootstrap.min.css" rel="stylesheet">

    <!-- Iconos de Bootstrap -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">

    <!-- Fuentes de Google -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display&family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">

    <!-- Estilos propios del portal del cliente -->
    <link rel="stylesheet" href="css/styleCliente.css?v=5">
    <link rel="stylesheet" href="css/ecofauna-unified.css?v=1">
</head>

<body class="ecofauna-unified">

    <!-- NAVBAR -->
    <nav class="navbar navbar-cliente">
        <div class="container-fluid px-4">
            <a class="navbar-brand" href="cliente.php">
                <img src="img/LogoEcoFauna1.png" class="logo-navbar-cliente" alt="Logo EcoFauna">
                <span>
                    EcoFauna
                    <small>Portal del visitante</small>
                </span>
            </a>

            <div class="usuario-nav">
                <div class="usuario-info">
                     <a href="mi_perfil.php" class="usuario-info text-decoration-none">
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

                <form action="logout.php" method="POST" class="d-inline">
                    <?= campoCsrfSesion("logout") ?>
                    <button type="submit" class="btn btn-salir">
                    <i class="bi bi-box-arrow-right"></i>
                    Salir
                </button>
                </form>
            </div>
        </div>
    </nav>

    <!-- HERO HEADER -->
    <header class="hero-cliente">
        <div class="container hero-contenido">
            <div class="hero-texto">
                <span class="hero-etiqueta">
                    <i class="bi bi-ticket-perforated-fill"></i>
                    Tus Reservaciones
                </span>
                <h1>Mis Entradas al Zoológico</h1>
                <p>
                    Consulta tus boletos comprados, códigos de acceso y detalles de tu próxima visita a EcoFauna.
                </p>

                <div class="hero-acciones">
                    <a href="cliente.php" class="btn btn-secundario">
                        <i class="bi bi-arrow-left"></i>
                        Regresar al Portal
                    </a>
                </div>
            </div>

            <div class="hero-ilustracion">
                <div class="circulo-grande">
                    <i class="bi bi-ticket-detailed-fill"></i>
                </div>
                <span class="burbuja burbuja-uno"><i class="bi bi-ticket-fill"></i></span>
                <span class="burbuja burbuja-dos"><i class="bi bi-calendar-check-fill"></i></span>
                <span class="burbuja burbuja-tres"><i class="bi bi-shield-check"></i></span>
            </div>
        </div>
    </header>

    <!-- CONTENIDO PRINCIPAL -->
    <main class="container contenido-principal">

        <section class="tarjeta-servicio">
            <span class="servicio-icono">
                <i class="bi bi-ticket-perforated-fill"></i>
            </span>
            <h3>Historial de Boletos Comprados</h3>

            <?php if (empty($entradas)): ?>
                <div class="text-center py-5">
                    <i class="bi bi-ticket-slash text-muted icono-sin-entradas"></i>
                    <h4 class="mt-3 text-muted">Aún no tienes entradas registradas</h4>
                    <p class="text-secondary">Tus compras de boletos para el zoológico aparecerán en esta sección.</p>
                    <a href="cliente.php" class="btn btn-principal mt-2">
                        <i class="bi bi-house-fill me-1"></i> Ir al Inicio
                    </a>
                </div>
            <?php else: ?>
                <div class="table-responsive mt-3">
                    <table class="table table-hover align-middle">
                        <thead class="table-dark">
                            <tr>
                                <th># Ticket</th>
                                <th>Código Ingreso</th>
                                <th>Fecha de Visita</th>
                                <th>Fecha de Compra</th>
                                <th>Método de Pago</th>
                                <th>Total Pagado</th>
                                <th>Estado</th>
                                <th class="text-center">Acción</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($entradas as $ticket): ?>
                                <tr>
                                    <td><strong>#<?= (int) $ticket[
                                        "id_ticket_entrada"
                                    ] ?></strong></td>
                                    <td>
                                        <?php if (
                                            !empty($ticket["codigo_ingreso"])
                                        ): ?>
                                            <span class="badge bg-success font-monospace px-3 py-2 codigo-ingreso-entrada">
                                                <?= escapar(
                                                    $ticket["codigo_ingreso"],
                                                ) ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">Sin código</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <i class="bi bi-calendar-event me-1 text-success"></i>
                                        <?= !empty($ticket["fecha_visita"])
                                            ? date(
                                                "d/m/Y",
                                                strtotime(
                                                    $ticket["fecha_visita"],
                                                ),
                                            )
                                            : "No especificada" ?>
                                    </td>
                                    <td>
                                        <small class="text-muted">
                                            <?= date(
                                                "d/m/Y H:i",
                                                strtotime(
                                                    $ticket["fecha_emision"],
                                                ),
                                            ) ?>
                                        </small>
                                    </td>
                                    <td><?= escapar(
                                        $ticket["metodo_pago"] ?? "Efectivo",
                                    ) ?></td>
                                    <td><strong>₡<?= number_format(
                                        (float) $ticket["total_ticket"],
                                        2,
                                        ",",
                                        ".",
                                    ) ?></strong></td>
                                    <td>
                                        <?php
                                        $estado =
                                            $ticket["estado_boleto"] ??
                                            ($ticket["estado_compra"] ??
                                                "Activo");
                                        if (
                                            strtolower($estado) === "activo" ||
                                            strtolower($estado) === "pagada"
                                        ) {
                                            echo '<span class="badge bg-success">Activo</span>';
                                        } elseif (
                                            strtolower($estado) === "usado"
                                        ) {
                                            echo '<span class="badge bg-secondary">Usado</span>';
                                        } else {
                                            echo '<span class="badge bg-danger">' .
                                                escapar($estado) .
                                                "</span>";
                                        }
                                        ?>
                                    </td>
                                    <td class="text-center">
                                        <a href="detalle_ticket.php?id=<?= (int) $ticket[
                                            "id_ticket_entrada"
                                        ] ?>" class="btn btn-sm btn-outline-success" title="Ver detalle del ticket">
                                            <i class="bi bi-receipt me-1"></i> Ver Detalle
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>

    </main>

    <!-- FOOTER -->
    <footer class="footer-cliente">
        <div class="container">
            <span>
                <i class="bi bi-tree-fill"></i>
                EcoFauna
            </span>
            <small>Conservar • Educar • Proteger</small>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>