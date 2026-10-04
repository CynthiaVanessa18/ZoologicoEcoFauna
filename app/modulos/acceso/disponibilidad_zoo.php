<?php

require_once __DIR__ . "/../../soporte/sesion.php";

require_once __DIR__ . "/../../../config/database.php";

/** @var mysqli $conexion */

if (
    !isset(
        $_SESSION["usuario"],
        $_SESSION["rol"],
        $_SESSION["id_login"],
        $_SESSION["id_usuario"],
    ) ||
    $_SESSION["rol"] !== "Empleado"
) {
    header("Location: ../index.php");
    exit();
}

$mensajeDisponibilidad = $_SESSION["mensaje_disponibilidad"] ?? "";
$tipoMensajeDisponibilidad =
    $_SESSION["tipo_mensaje_disponibilidad"] ?? "success";

unset(
    $_SESSION["mensaje_disponibilidad"],
    $_SESSION["tipo_mensaje_disponibilidad"],
);

if (
    !in_array(
        $tipoMensajeDisponibilidad,
        ["success", "danger", "warning"],
        true,
    )
) {
    $tipoMensajeDisponibilidad = "danger";
}

$_SESSION["csrf_disponibilidad"] ??= bin2hex(random_bytes(32));

$sql = "
    SELECT
        fecha,
        hora_apertura,
        hora_cierre,
        capacidad_total,
        capacidad_disponible,
        estado
    FROM disponibilidad_dia_zoo
    ORDER BY fecha
";

$resultado = $conexion->query($sql);

if (!$resultado) {
    error_log(
        "Error consultando la disponibilidad: " . $conexion->error,
    );
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Disponibilidad del Zoológico</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/css/bootstrap.min.css"
        rel="stylesheet">
    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css"
        rel="stylesheet">
    <link rel="stylesheet" href="../css/crud-modern.css?v=1">
    <link rel="stylesheet" href="../css/ecofauna-unified.css?v=1">
</head>

<body class="ecofauna-unified crud-modern">


    <main class="container py-5">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
            <h1 class="h2 mb-0">Disponibilidad de visitas</h1>

            <a href="../empleado.php" class="btn btn-secondary">
                <i class="bi bi-arrow-left"></i>
                Regresar
            </a>
        </div>

        <?php if ($mensajeDisponibilidad !== ""): ?>
            <div
                class="alert alert-<?= $tipoMensajeDisponibilidad ?>"
                role="alert">
                <?= htmlspecialchars(
                    $mensajeDisponibilidad,
                    ENT_QUOTES,
                    "UTF-8",
                ) ?>
            </div>
        <?php endif; ?>

        <section class="card shadow mb-4">
            <div class="card-header bg-success text-white">
                Registrar nuevo día
            </div>

            <div class="card-body">
                <form action="guardar_disponibilidad.php" method="POST">
                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= htmlspecialchars(
                            $_SESSION["csrf_disponibilidad"],
                            ENT_QUOTES,
                            "UTF-8",
                        ) ?>">

                    <div class="row g-3">
                        <div class="col-md-3">
                            <label for="fecha" class="form-label">Fecha</label>
                            <input
                                id="fecha"
                                type="date"
                                name="fecha"
                                class="form-control"
                                min="<?= date("Y-m-d") ?>"
                                required>
                        </div>

                        <div class="col-md-2">
                            <label for="hora_apertura" class="form-label">Apertura</label>
                            <input
                                id="hora_apertura"
                                type="time"
                                name="hora_apertura"
                                class="form-control"
                                value="08:00"
                                required>
                        </div>

                        <div class="col-md-2">
                            <label for="hora_cierre" class="form-label">Cierre</label>
                            <input
                                id="hora_cierre"
                                type="time"
                                name="hora_cierre"
                                class="form-control"
                                value="16:00"
                                required>
                        </div>

                        <div class="col-md-2">
                            <label for="capacidad" class="form-label">Capacidad</label>
                            <input
                                id="capacidad"
                                type="number"
                                name="capacidad"
                                class="form-control"
                                min="1"
                                required>
                        </div>

                        <div class="col-md-3 d-grid">
                            <label class="form-label">&nbsp;</label>
                            <button type="submit" class="btn btn-success">
                                Guardar
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </section>

        <section class="card shadow">
            <div class="card-header bg-primary text-white">
                Días habilitados
            </div>

            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-bordered table-hover align-middle mb-0">
                        <thead class="table-dark">
                            <tr>
                                <th>Fecha</th>
                                <th>Apertura</th>
                                <th>Cierre</th>
                                <th>Capacidad</th>
                                <th>Disponibles</th>
                                <th>Estado</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php if (
                                !$resultado ||
                                $resultado->num_rows === 0
                            ): ?>
                                <tr>
                                    <td colspan="6" class="text-center text-muted py-4">
                                        No hay días registrados.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php while (
                                    $fila = $resultado->fetch_assoc()
                                ): ?>
                                    <tr>
                                        <td><?= htmlspecialchars(
                                            $fila["fecha"],
                                            ENT_QUOTES,
                                            "UTF-8",
                                        ) ?></td>
                                        <td><?= htmlspecialchars(
                                            $fila["hora_apertura"],
                                            ENT_QUOTES,
                                            "UTF-8",
                                        ) ?></td>
                                        <td><?= htmlspecialchars(
                                            $fila["hora_cierre"],
                                            ENT_QUOTES,
                                            "UTF-8",
                                        ) ?></td>
                                        <td><?= (int) $fila[
                                            "capacidad_total"
                                        ] ?></td>
                                        <td><?= (int) $fila[
                                            "capacidad_disponible"
                                        ] ?></td>
                                        <td>
                                            <?php if (
                                                $fila["estado"] === "Disponible"
                                            ): ?>
                                                <span class="badge bg-success">Disponible</span>
                                            <?php elseif (
                                                $fila["estado"] === "Completado"
                                            ): ?>
                                                <span class="badge bg-danger">Completado</span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">Cerrado</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    </main>

</body>

</html>