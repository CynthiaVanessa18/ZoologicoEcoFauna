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

function enviarMensajeCancelacion(
    string $mensaje,
    string $tipo = "success",
): void {
    $_SESSION["mensaje_ticket"] = $mensaje;
    $_SESSION["tipo_mensaje_ticket"] = $tipo;

    header("Location: empleado.php");
    exit();
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    enviarMensajeCancelacion("La solicitud de cancelación no es válida.", "danger");
}

$tokenSesion = $_SESSION["csrf_cancelar_ticket"] ?? "";
$tokenFormulario = $_POST["csrf_token"] ?? "";

if (
    !is_string($tokenSesion) ||
    !is_string($tokenFormulario) ||
    $tokenSesion === "" ||
    $tokenFormulario === "" ||
    !hash_equals($tokenSesion, $tokenFormulario)
) {
    enviarMensajeCancelacion(
        "La sesión del formulario venció. Intenta nuevamente.",
        "danger",
    );
}

$idTicket = filter_input(
    INPUT_POST,
    "id_ticket",
    FILTER_VALIDATE_INT,
    ["options" => ["min_range" => 1]],
);

if (!$idTicket) {
    enviarMensajeCancelacion("El ticket seleccionado no es válido.", "danger");
}

$enTransaccion = false;

try {
    if (!mysqli_begin_transaction($conexion)) {
        throw new RuntimeException(mysqli_error($conexion));
    }

    $enTransaccion = true;

    $sql = "
        SELECT
            t.estado,
            t.id_disponibilidad,
            b.estado_ticket,
            ra.id_registro_acceso
        FROM ticket_entrada t
        LEFT JOIN boleto_zoologico b
            ON b.id_ticket_entrada = t.id_ticket_entrada
        LEFT JOIN registro_acceso ra
            ON ra.id_entrada = b.id_boleto_zoologico
        WHERE t.id_ticket_entrada = ?
        LIMIT 1
        FOR UPDATE
    ";

    $stmt = mysqli_prepare($conexion, $sql);

    if (!$stmt) {
        throw new RuntimeException(mysqli_error($conexion));
    }

    mysqli_stmt_bind_param($stmt, "i", $idTicket);

    if (!mysqli_stmt_execute($stmt)) {
        throw new RuntimeException(mysqli_stmt_error($stmt));
    }

    $ticket = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    if (!$ticket) {
        throw new DomainException("El ticket no existe.");
    }

    if (strtolower($ticket["estado"]) === "cancelada") {
        throw new DomainException("El ticket ya fue cancelado.");
    }

    if ($ticket["id_registro_acceso"] !== null) {
        throw new DomainException(
            "El ticket no puede cancelarse porque ya registró un acceso.",
        );
    }

    if (strtolower((string) ($ticket["estado_ticket"] ?? "")) === "usado") {
        throw new DomainException(
            "El ticket no puede cancelarse porque ya fue utilizado.",
        );
    }

    $stmt = mysqli_prepare(
        $conexion,
        "SELECT COALESCE(SUM(cantidad), 0) AS total
         FROM detalle_ticket_entrada
         WHERE id_ticket_entrada = ?",
    );

    if (!$stmt) {
        throw new RuntimeException(mysqli_error($conexion));
    }

    mysqli_stmt_bind_param($stmt, "i", $idTicket);

    if (!mysqli_stmt_execute($stmt)) {
        throw new RuntimeException(mysqli_stmt_error($stmt));
    }

    $fila = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    $cantidad = (int) $fila["total"];

    $stmt = mysqli_prepare(
        $conexion,
        "UPDATE ticket_entrada
         SET estado = 'Cancelada'
         WHERE id_ticket_entrada = ?",
    );

    if (!$stmt) {
        throw new RuntimeException(mysqli_error($conexion));
    }

    mysqli_stmt_bind_param($stmt, "i", $idTicket);

    if (!mysqli_stmt_execute($stmt)) {
        throw new RuntimeException(mysqli_stmt_error($stmt));
    }

    mysqli_stmt_close($stmt);

    $stmt = mysqli_prepare(
        $conexion,
        "UPDATE boleto_zoologico
         SET estado_ticket = 'Cancelado'
         WHERE id_ticket_entrada = ?",
    );

    if (!$stmt) {
        throw new RuntimeException(mysqli_error($conexion));
    }

    mysqli_stmt_bind_param($stmt, "i", $idTicket);

    if (!mysqli_stmt_execute($stmt)) {
        throw new RuntimeException(mysqli_stmt_error($stmt));
    }

    mysqli_stmt_close($stmt);

    $idDisponibilidad = (int) ($ticket["id_disponibilidad"] ?? 0);

    if ($cantidad > 0 && $idDisponibilidad > 0) {
        $stmt = mysqli_prepare(
            $conexion,
            "UPDATE disponibilidad_dia_zoo
             SET capacidad_disponible = LEAST(
                 capacidad_total,
                 capacidad_disponible + ?
             )
             WHERE id_disponibilidad = ?",
        );

        if (!$stmt) {
            throw new RuntimeException(mysqli_error($conexion));
        }

        mysqli_stmt_bind_param(
            $stmt,
            "ii",
            $cantidad,
            $idDisponibilidad,
        );

        if (!mysqli_stmt_execute($stmt)) {
            throw new RuntimeException(mysqli_stmt_error($stmt));
        }

        mysqli_stmt_close($stmt);
    }

    if (
        !registrarBitacora(
            $_SESSION["usuario"],
            "CANCELAR TICKET",
            "ticket_entrada",
            "Se canceló el ticket #" . $idTicket . ".",
            $idTicket,
        )
    ) {
        throw new RuntimeException(
            "No fue posible registrar la cancelación en la bitácora.",
        );
    }

    if (!mysqli_commit($conexion)) {
        throw new RuntimeException(mysqli_error($conexion));
    }

    $enTransaccion = false;
    unset($_SESSION["csrf_cancelar_ticket"]);

    enviarMensajeCancelacion("Ticket cancelado correctamente.");
} catch (DomainException $error) {
    if ($enTransaccion) {
        mysqli_rollback($conexion);
    }

    enviarMensajeCancelacion($error->getMessage(), "warning");
} catch (Throwable $error) {
    if ($enTransaccion) {
        mysqli_rollback($conexion);
    }

    error_log("Error cancelando ticket: " . $error->getMessage());

    enviarMensajeCancelacion(
        "No fue posible cancelar el ticket. Intenta nuevamente.",
        "danger",
    );
}