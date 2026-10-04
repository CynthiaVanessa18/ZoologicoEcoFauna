<?php

/** @var mysqli $conexion */

if (!isset($conexion) || !($conexion instanceof mysqli)) {
    http_response_code(403);
    exit("Acceso no permitido.");
}

if (
    $_SERVER["REQUEST_METHOD"] === "POST" &&
    isset($_POST["registrar_condicion"])
) {
    $enTransaccion = false;

    try {
        $nombreCondicion = trim($_POST["nombre_condicion"] ?? "");
        $tipoCondicion = trim($_POST["tipo_condicion"] ?? "");
        $descripcionCondicion = trim($_POST["descripcion_condicion"] ?? "");

        if ($nombreCondicion === "") {
            throw new RuntimeException(
                "Debes escribir el nombre de la enfermedad o alergia.",
            );
        }

        if (!in_array($tipoCondicion, ["Enfermedad", "Alergia"], true)) {
            throw new RuntimeException(
                "El tipo de condición médica no es válido.",
            );
        }

        if (
            mb_strlen($nombreCondicion) > 100 ||
            mb_strlen($descripcionCondicion) > 500
        ) {
            throw new RuntimeException(
                "El nombre o la descripción supera el tamaño permitido.",
            );
        }

        $sqlExisteCondicion = "
            SELECT id_enfermedad
            FROM enfermedad
            WHERE nombre = ? AND tipo = ?
            LIMIT 1
        ";
        $stmtExisteCondicion = mysqli_prepare($conexion, $sqlExisteCondicion);
        if (!$stmtExisteCondicion) {
            throw new ErrorTecnicoVeterinario(mysqli_error($conexion));
        }

        mysqli_stmt_bind_param(
            $stmtExisteCondicion,
            "ss",
            $nombreCondicion,
            $tipoCondicion,
        );
        mysqli_stmt_execute($stmtExisteCondicion);
        $resultadoExisteCondicion = mysqli_stmt_get_result(
            $stmtExisteCondicion,
        );
        $condicionExistente = mysqli_fetch_assoc($resultadoExisteCondicion);
        mysqli_stmt_close($stmtExisteCondicion);

        if ($condicionExistente) {
            throw new RuntimeException(
                "Esa enfermedad o alergia ya existe en el catálogo.",
            );
        }

        mysqli_begin_transaction($conexion);
        $enTransaccion = true;

        $sqlInsertarCondicion = "
            INSERT INTO enfermedad (nombre, tipo, descripcion)
            VALUES (?, ?, NULLIF(?, ''))
        ";
        $stmtInsertarCondicion = mysqli_prepare(
            $conexion,
            $sqlInsertarCondicion,
        );
        if (!$stmtInsertarCondicion) {
            throw new ErrorTecnicoVeterinario(mysqli_error($conexion));
        }

        mysqli_stmt_bind_param(
            $stmtInsertarCondicion,
            "sss",
            $nombreCondicion,
            $tipoCondicion,
            $descripcionCondicion,
        );

        if (!mysqli_stmt_execute($stmtInsertarCondicion)) {
            throw new ErrorTecnicoVeterinario(
                mysqli_stmt_error($stmtInsertarCondicion),
            );
        }

        $idCondicion = mysqli_insert_id($conexion);
        mysqli_stmt_close($stmtInsertarCondicion);

        if (
            !registrarBitacora(
                $_SESSION["usuario"],
                "REGISTRAR " . strtoupper($tipoCondicion),
                "enfermedad",
                $tipoCondicion .
                    " registrada en el catálogo: " .
                    $nombreCondicion .
                    ".",
                $idCondicion,
            )
        ) {
            throw new RuntimeException(
                "No fue posible registrar la acción en la bitácora.",
            );
        }

        mysqli_commit($conexion);
        $enTransaccion = false;

        $mensaje =
            $tipoCondicion . " registrada correctamente en el catálogo médico.";
        $tipoMensaje = "success";
    } catch (Throwable $e) {
        if ($enTransaccion) {
            mysqli_rollback($conexion);
        }

        registrarErrorVeterinario("REGISTRAR CONDICIÓN", "enfermedad", $e);
        $mensaje = mensajeErrorVeterinario($e);
        $tipoMensaje = "danger";
    }
}