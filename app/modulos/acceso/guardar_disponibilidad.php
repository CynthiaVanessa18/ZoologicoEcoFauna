<?php

require_once __DIR__ . "/../../soporte/sesion.php";
require_once __DIR__ . "/../../../config/database.php";

/** @var mysqli $conexion */
$conexion = $GLOBALS["conexion"];

function redirigirDisponibilidad(
    string $mensaje,
    string $tipo = "danger",
): void {
    $_SESSION["mensaje_disponibilidad"] = $mensaje;
    $_SESSION["tipo_mensaje_disponibilidad"] = $tipo;

    header("Location: disponibilidad_zoo.php");
    exit();
}

function fechaDisponibilidadValida(string $fecha): bool
{
    $objetoFecha = DateTime::createFromFormat("!Y-m-d", $fecha);

    return $objetoFecha !== false &&
        $objetoFecha->format("Y-m-d") === $fecha;
}

function horaDisponibilidadValida(string $hora): bool
{
    $objetoHora = DateTime::createFromFormat("!H:i", $hora);

    return $objetoHora !== false &&
        $objetoHora->format("H:i") === $hora;
}

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

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: disponibilidad_zoo.php");
    exit();
}

$tokenSesion = $_SESSION["csrf_disponibilidad"] ?? "";
$tokenFormulario = $_POST["csrf_token"] ?? "";

if (
    $tokenSesion === "" ||
    !is_string($tokenFormulario) ||
    !hash_equals($tokenSesion, $tokenFormulario)
) {
    redirigirDisponibilidad(
        "La solicitud venció o no es válida. Inténtalo nuevamente.",
    );
}

$fecha = trim($_POST["fecha"] ?? "");
$apertura = trim($_POST["hora_apertura"] ?? "");
$cierre = trim($_POST["hora_cierre"] ?? "");
$capacidad = filter_var(
    $_POST["capacidad"] ?? null,
    FILTER_VALIDATE_INT,
    ["options" => ["min_range" => 1, "max_range" => 100000]],
);
$idAdmin = (int) $_SESSION["id_usuario"];

if (!fechaDisponibilidadValida($fecha)) {
    redirigirDisponibilidad("La fecha seleccionada no es válida.");
}

if ($fecha < date("Y-m-d")) {
    redirigirDisponibilidad(
        "No puedes registrar disponibilidad para una fecha anterior a hoy.",
    );
}

if (
    !horaDisponibilidadValida($apertura) ||
    !horaDisponibilidadValida($cierre)
) {
    redirigirDisponibilidad("El horario ingresado no es válido.");
}

if ($apertura >= $cierre) {
    redirigirDisponibilidad(
        "La hora de apertura debe ser anterior a la hora de cierre.",
    );
}

if ($capacidad === false) {
    redirigirDisponibilidad(
        "La capacidad debe ser un número mayor que cero.",
    );
}

if ($idAdmin <= 0) {
    redirigirDisponibilidad(
        "La cuenta del empleado no está vinculada correctamente.",
    );
}

$enTransaccion = false;

try {
    if (!mysqli_begin_transaction($conexion)) {
        throw new RuntimeException(
            "No fue posible iniciar la transacción.",
        );
    }

    $enTransaccion = true;

    $stmtExiste = mysqli_prepare(
        $conexion,
        "SELECT id_disponibilidad
         FROM disponibilidad_dia_zoo
         WHERE fecha = ?
         LIMIT 1
         FOR UPDATE",
    );

    if (!$stmtExiste) {
        throw new RuntimeException(mysqli_error($conexion));
    }

    mysqli_stmt_bind_param($stmtExiste, "s", $fecha);

    if (!mysqli_stmt_execute($stmtExiste)) {
        $errorTecnico = mysqli_stmt_error($stmtExiste);
        mysqli_stmt_close($stmtExiste);
        throw new RuntimeException($errorTecnico);
    }

    $diaExistente = mysqli_fetch_assoc(
        mysqli_stmt_get_result($stmtExiste),
    );

    mysqli_stmt_close($stmtExiste);

    if ($diaExistente) {
        throw new DomainException(
            "Ya existe un registro de disponibilidad para esa fecha.",
        );
    }

    $sql = "
    INSERT INTO disponibilidad_dia_zoo (
        fecha,
        hora_apertura,
        hora_cierre,
        capacidad_total,
        capacidad_disponible,
        estado,
        id_usuario_admin
    )
    VALUES (?, ?, ?, ?, ?, 'Disponible', ?)
";

    $stmt = mysqli_prepare($conexion, $sql);

    if (!$stmt) {
        throw new RuntimeException(mysqli_error($conexion));
    }

    mysqli_stmt_bind_param(
        $stmt,
        "sssiii",
        $fecha,
        $apertura,
        $cierre,
        $capacidad,
        $capacidad,
        $idAdmin,
    );

    if (!mysqli_stmt_execute($stmt)) {
        $numeroError = mysqli_stmt_errno($stmt);
        $errorTecnico = mysqli_stmt_error($stmt);
        mysqli_stmt_close($stmt);

        if ($numeroError === 1062) {
            throw new DomainException(
                "Ya existe un registro de disponibilidad para esa fecha.",
            );
        }

        throw new RuntimeException($errorTecnico);
    }

    $idDisponibilidad = mysqli_insert_id($conexion);
    mysqli_stmt_close($stmt);

    if (
        !registrarBitacora(
            $_SESSION["usuario"],
            "REGISTRAR DISPONIBILIDAD",
            "disponibilidad_dia_zoo",
            "Se habilitó el día {$fecha} con capacidad de {$capacidad} visitantes.",
            $idDisponibilidad,
        )
    ) {
        throw new RuntimeException(
            "No fue posible registrar la acción en la bitácora.",
        );
    }

    if (!mysqli_commit($conexion)) {
        throw new RuntimeException(
            "No fue posible confirmar el registro.",
        );
    }

    $enTransaccion = false;
    unset($_SESSION["csrf_disponibilidad"]);

    redirigirDisponibilidad(
        "Disponibilidad registrada correctamente.",
        "success",
    );
} catch (Throwable $error) {
    if ($enTransaccion) {
        mysqli_rollback($conexion);
    }

    error_log(
        "Error registrando disponibilidad: " . $error->getMessage(),
    );

    $mensaje =
        $error instanceof DomainException
            ? $error->getMessage()
            : "No fue posible registrar la disponibilidad. Inténtalo nuevamente.";

    redirigirDisponibilidad($mensaje);
}