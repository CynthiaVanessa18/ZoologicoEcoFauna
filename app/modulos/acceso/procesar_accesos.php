<?php

require_once __DIR__ . "/../../soporte/sesion.php";
require_once __DIR__ . "/../../../config/database.php";

/** @var mysqli $conexion */

if (
    !isset($_SESSION["usuario"], $_SESSION["rol"], $_SESSION["id_login"]) ||
    $_SESSION["rol"] !== "Empleado"
) {
    header("Location: ../index.php");
    exit();
}

function enviarMensajeAcceso(string $mensaje, string $tipo = "success"): void
{
    $_SESSION["mensaje_acceso"] = $mensaje;
    $_SESSION["tipo_mensaje_acceso"] = $tipo;

    header("Location: historial_accesos.php");
    exit();
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: historial_accesos.php");
    exit();
}

$tokenSesion = $_SESSION["csrf_accesos"] ?? "";
$tokenFormulario = $_POST["csrf_token"] ?? "";

if (
    $tokenSesion === "" ||
    !is_string($tokenFormulario) ||
    !hash_equals($tokenSesion, $tokenFormulario)
) {
    enviarMensajeAcceso(
        "La solicitud venció o no es válida. Inténtalo nuevamente.",
        "danger",
    );
}

unset($_SESSION["csrf_accesos"]);

$codigo = strtoupper(trim($_POST["codigo_ingreso"] ?? ""));

if (preg_match('/^[A-Z0-9]{5}$/', $codigo) !== 1) {
    enviarMensajeAcceso(
        "El código debe contener exactamente 5 letras o números.",
        "danger",
    );
}

$enTransaccion = false;

try {
    if (!mysqli_begin_transaction($conexion)) {
        throw new RuntimeException(
            "No fue posible iniciar la transacción de acceso.",
        );
    }

    $enTransaccion = true;

    $sqlBoleto = "
        SELECT id_boleto_zoologico
        FROM boleto_zoologico
        WHERE codigo_ingreso = ?
          AND estado_ticket = 'Activo'
        LIMIT 1
        FOR UPDATE
    ";

    $stmtBoleto = mysqli_prepare($conexion, $sqlBoleto);

    if (!$stmtBoleto) {
        throw new RuntimeException(mysqli_error($conexion));
    }

    mysqli_stmt_bind_param($stmtBoleto, "s", $codigo);

    if (!mysqli_stmt_execute($stmtBoleto)) {
        $errorTecnico = mysqli_stmt_error($stmtBoleto);
        mysqli_stmt_close($stmtBoleto);
        throw new RuntimeException($errorTecnico);
    }

    $boleto = mysqli_fetch_assoc(
        mysqli_stmt_get_result($stmtBoleto),
    );

    mysqli_stmt_close($stmtBoleto);

    if (!$boleto) {
        throw new DomainException(
            "Boleto no encontrado o ya está finalizado.",
        );
    }

    $idBoleto = (int) $boleto["id_boleto_zoologico"];

    $sqlRegistro = "
        SELECT id_registro_acceso
        FROM registro_acceso
        WHERE id_entrada = ?
          AND hora_salida IS NULL
        LIMIT 1
        FOR UPDATE
    ";

    $stmtRegistro = mysqli_prepare($conexion, $sqlRegistro);

    if (!$stmtRegistro) {
        throw new RuntimeException(mysqli_error($conexion));
    }

    mysqli_stmt_bind_param($stmtRegistro, "i", $idBoleto);

    if (!mysqli_stmt_execute($stmtRegistro)) {
        $errorTecnico = mysqli_stmt_error($stmtRegistro);
        mysqli_stmt_close($stmtRegistro);
        throw new RuntimeException($errorTecnico);
    }

    $registro = mysqli_fetch_assoc(
        mysqli_stmt_get_result($stmtRegistro),
    );

    mysqli_stmt_close($stmtRegistro);

    if (!$registro) {
        $stmtEntrada = mysqli_prepare(
            $conexion,
            "INSERT INTO registro_acceso (id_entrada, hora_entrada)
             VALUES (?, NOW())",
        );

        if (!$stmtEntrada) {
            throw new RuntimeException(mysqli_error($conexion));
        }

        mysqli_stmt_bind_param($stmtEntrada, "i", $idBoleto);

        if (!mysqli_stmt_execute($stmtEntrada)) {
            $errorTecnico = mysqli_stmt_error($stmtEntrada);
            mysqli_stmt_close($stmtEntrada);
            throw new RuntimeException($errorTecnico);
        }

        $idRegistro = mysqli_insert_id($conexion);
        mysqli_stmt_close($stmtEntrada);

        if (
            !registrarBitacora(
                $_SESSION["usuario"],
                "REGISTRAR ENTRADA",
                "registro_acceso",
                "Se registró la entrada con el boleto {$codigo}.",
                $idRegistro,
            )
        ) {
            throw new RuntimeException(
                "No fue posible registrar la entrada en la bitácora.",
            );
        }

        if (!mysqli_commit($conexion)) {
            throw new RuntimeException(
                "No fue posible confirmar la entrada.",
            );
        }

        $enTransaccion = false;

        enviarMensajeAcceso(
            "Entrada registrada correctamente.",
            "success",
        );
    }

    $idRegistro = (int) $registro["id_registro_acceso"];

    $stmtSalida = mysqli_prepare(
        $conexion,
        "UPDATE registro_acceso
         SET hora_salida = NOW()
         WHERE id_registro_acceso = ?
           AND hora_salida IS NULL",
    );

    if (!$stmtSalida) {
        throw new RuntimeException(mysqli_error($conexion));
    }

    mysqli_stmt_bind_param($stmtSalida, "i", $idRegistro);

    if (!mysqli_stmt_execute($stmtSalida)) {
        $errorTecnico = mysqli_stmt_error($stmtSalida);
        mysqli_stmt_close($stmtSalida);
        throw new RuntimeException($errorTecnico);
    }

    $salidaActualizada = mysqli_stmt_affected_rows($stmtSalida) === 1;
    mysqli_stmt_close($stmtSalida);

    if (!$salidaActualizada) {
        throw new DomainException(
            "La salida ya había sido registrada.",
        );
    }

    $stmtEstado = mysqli_prepare(
        $conexion,
        "UPDATE boleto_zoologico
         SET estado_ticket = 'Usado'
         WHERE id_boleto_zoologico = ?
           AND estado_ticket = 'Activo'",
    );

    if (!$stmtEstado) {
        throw new RuntimeException(mysqli_error($conexion));
    }

    mysqli_stmt_bind_param($stmtEstado, "i", $idBoleto);

    if (!mysqli_stmt_execute($stmtEstado)) {
        $errorTecnico = mysqli_stmt_error($stmtEstado);
        mysqli_stmt_close($stmtEstado);
        throw new RuntimeException($errorTecnico);
    }

    $boletoActualizado = mysqli_stmt_affected_rows($stmtEstado) === 1;
    mysqli_stmt_close($stmtEstado);

    if (!$boletoActualizado) {
        throw new DomainException(
            "El boleto ya no se encuentra activo.",
        );
    }

    if (
        !registrarBitacora(
            $_SESSION["usuario"],
            "REGISTRAR SALIDA",
            "registro_acceso",
            "Se registró la salida con el boleto {$codigo} y quedó inhabilitado.",
            $idRegistro,
        )
    ) {
        throw new RuntimeException(
            "No fue posible registrar la salida en la bitácora.",
        );
    }

    if (!mysqli_commit($conexion)) {
        throw new RuntimeException(
            "No fue posible confirmar la salida.",
        );
    }

    $enTransaccion = false;

    enviarMensajeAcceso(
        "Salida registrada correctamente. El boleto ha quedado inhabilitado.",
        "success",
    );
} catch (Throwable $error) {
    if ($enTransaccion) {
        mysqli_rollback($conexion);
    }

    error_log(
        "Error procesando el acceso: " . $error->getMessage(),
    );

    $mensaje =
        $error instanceof DomainException
            ? $error->getMessage()
            : "No fue posible procesar el acceso. Inténtalo nuevamente.";

    enviarMensajeAcceso($mensaje, "danger");
}