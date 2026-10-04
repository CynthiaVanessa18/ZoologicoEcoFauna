<?php

/** @var mysqli $conexion */

if (!isset($conexion) || !($conexion instanceof mysqli)) {
    http_response_code(403);
    exit("Acceso no permitido.");
}

/* REGISTRAR DIAGNÓSTICO, TRATAMIENTO O ALERGIA EN EL HISTORIAL */
if (
    $_SERVER["REQUEST_METHOD"] === "POST" &&
    isset($_POST["registrar_historial"])
) {
    $enTransaccion = false;

    try {
        $idAnimalMedico = filter_input(
            INPUT_POST,
            "id_animal_medico",
            FILTER_VALIDATE_INT,
        );
        $idEnfermedad = filter_input(
            INPUT_POST,
            "id_enfermedad",
            FILTER_VALIDATE_INT,
        );
        $fechaDiagnostico = trim($_POST["fecha_diagnostico"] ?? "");
        $fechaRecuperacion = trim($_POST["fecha_recuperacion"] ?? "");
        $tratamiento = trim($_POST["tratamiento"] ?? "");
        $observacionesMedicas = trim($_POST["observaciones_medicas"] ?? "");

        if (!$idAnimalMedico || !$idEnfermedad) {
            throw new RuntimeException(
                "Debes seleccionar un animal y una condición médica.",
            );
        }

        if (!fechaIsoValida($fechaDiagnostico)) {
            throw new RuntimeException(
                "La fecha del diagnóstico no es válida.",
            );
        }

        if ($fechaDiagnostico > date("Y-m-d")) {
            throw new RuntimeException(
                "La fecha del diagnóstico no puede estar en el futuro.",
            );
        }

        if ($fechaRecuperacion !== "") {
            if (!fechaIsoValida($fechaRecuperacion)) {
                throw new RuntimeException(
                    "La fecha de recuperación no es válida.",
                );
            }

            if ($fechaRecuperacion < $fechaDiagnostico) {
                throw new RuntimeException(
                    "La recuperación no puede ser anterior al diagnóstico.",
                );
            }

            if ($fechaRecuperacion > date("Y-m-d")) {
                throw new RuntimeException(
                    "La fecha de recuperación no puede estar en el futuro.",
                );
            }
        }

        if (
            mb_strlen($tratamiento) > 500 ||
            mb_strlen($observacionesMedicas) > 500
        ) {
            throw new RuntimeException(
                "El tratamiento o las observaciones superan el tamaño permitido.",
            );
        }

        $sqlDatosHistorial = "
            SELECT a.nombre_animal, e.nombre AS condicion, e.tipo
            FROM animal a
            CROSS JOIN enfermedad e
            WHERE a.id_animal = ? AND e.id_enfermedad = ?
            LIMIT 1
        ";
        $stmtDatosHistorial = mysqli_prepare($conexion, $sqlDatosHistorial);
        if (!$stmtDatosHistorial) {
            throw new ErrorTecnicoVeterinario(mysqli_error($conexion));
        }

        mysqli_stmt_bind_param(
            $stmtDatosHistorial,
            "ii",
            $idAnimalMedico,
            $idEnfermedad,
        );
        mysqli_stmt_execute($stmtDatosHistorial);
        $resultadoDatosHistorial = mysqli_stmt_get_result($stmtDatosHistorial);
        $datosHistorial = mysqli_fetch_assoc($resultadoDatosHistorial);
        mysqli_stmt_close($stmtDatosHistorial);

        if (!$datosHistorial) {
            throw new RuntimeException(
                "El animal o la condición seleccionada no existe.",
            );
        }

        mysqli_begin_transaction($conexion);
        $enTransaccion = true;

        $sqlInsertarHistorial = "
            INSERT INTO historialmedicoanimal (
                id_animal,
                id_enfermedad,
                fecha_diagnostico,
                fecha_recuperacion,
                tratamiento,
                observaciones
            )
            VALUES (?, ?, ?, NULLIF(?, ''), NULLIF(?, ''), NULLIF(?, ''))
        ";
        $stmtInsertarHistorial = mysqli_prepare(
            $conexion,
            $sqlInsertarHistorial,
        );
        if (!$stmtInsertarHistorial) {
            throw new ErrorTecnicoVeterinario(mysqli_error($conexion));
        }

        mysqli_stmt_bind_param(
            $stmtInsertarHistorial,
            "iissss",
            $idAnimalMedico,
            $idEnfermedad,
            $fechaDiagnostico,
            $fechaRecuperacion,
            $tratamiento,
            $observacionesMedicas,
        );

        if (!mysqli_stmt_execute($stmtInsertarHistorial)) {
            throw new ErrorTecnicoVeterinario(
                mysqli_stmt_error($stmtInsertarHistorial),
            );
        }

        $idHistorial = mysqli_insert_id($conexion);
        mysqli_stmt_close($stmtInsertarHistorial);

        $detallesHistorial = sprintf(
            "%s registrada para %s. Condición: %s. Diagnóstico: %s.",
            $datosHistorial["tipo"],
            $datosHistorial["nombre_animal"],
            $datosHistorial["condicion"],
            $fechaDiagnostico,
        );

        if (
            !registrarBitacora(
                $_SESSION["usuario"],
                "REGISTRAR HISTORIAL MÉDICO",
                "historialmedicoanimal",
                $detallesHistorial,
                $idHistorial,
            )
        ) {
            throw new RuntimeException(
                "No fue posible registrar la acción en la bitácora.",
            );
        }

        mysqli_commit($conexion);
        $enTransaccion = false;

        $mensaje =
            "El diagnóstico, tratamiento o alergia se registró correctamente.";
        $tipoMensaje = "success";
    } catch (Throwable $e) {
        if ($enTransaccion) {
            mysqli_rollback($conexion);
        }

        registrarErrorVeterinario(
            "REGISTRAR HISTORIAL MÉDICO",
            "historialmedicoanimal",
            $e,
        );
        $mensaje = mensajeErrorVeterinario($e);
        $tipoMensaje = "danger";
    }
}

/* ACTUALIZAR TRATAMIENTO Y ESTADO DE RECUPERACIÓN */
if (
    $_SERVER["REQUEST_METHOD"] === "POST" &&
    isset($_POST["actualizar_historial"])
) {
    $enTransaccion = false;

    try {
        $idHistorialEditar = filter_input(
            INPUT_POST,
            "id_historial",
            FILTER_VALIDATE_INT,
        );
        $fechaDiagnosticoEditar = trim(
            $_POST["fecha_diagnostico_editar"] ?? "",
        );
        $fechaRecuperacionEditar = trim(
            $_POST["fecha_recuperacion_editar"] ?? "",
        );
        $tratamientoEditar = trim($_POST["tratamiento_editar"] ?? "");
        $observacionesEditar = trim($_POST["observaciones_editar"] ?? "");

        if (!$idHistorialEditar || !fechaIsoValida($fechaDiagnosticoEditar)) {
            throw new RuntimeException(
                "El registro médico o la fecha indicada no es válida.",
            );
        }

        if ($fechaDiagnosticoEditar > date("Y-m-d")) {
            throw new RuntimeException(
                "La fecha del diagnóstico no puede estar en el futuro.",
            );
        }

        if ($fechaRecuperacionEditar !== "") {
            if (!fechaIsoValida($fechaRecuperacionEditar)) {
                throw new RuntimeException(
                    "La fecha de recuperación no es válida.",
                );
            }

            if ($fechaRecuperacionEditar < $fechaDiagnosticoEditar) {
                throw new RuntimeException(
                    "La recuperación no puede ser anterior al diagnóstico.",
                );
            }

            if ($fechaRecuperacionEditar > date("Y-m-d")) {
                throw new RuntimeException(
                    "La fecha de recuperación no puede estar en el futuro.",
                );
            }
        }

        if (
            mb_strlen($tratamientoEditar) > 500 ||
            mb_strlen($observacionesEditar) > 500
        ) {
            throw new RuntimeException(
                "El tratamiento o las observaciones superan el tamaño permitido.",
            );
        }

        $sqlHistorialEditar = "
            SELECT a.nombre_animal, e.nombre AS condicion
            FROM historialmedicoanimal hma
            INNER JOIN animal a ON hma.id_animal = a.id_animal
            INNER JOIN enfermedad e ON hma.id_enfermedad = e.id_enfermedad
            WHERE hma.id_historial = ?
            LIMIT 1
        ";
        $stmtHistorialEditar = mysqli_prepare($conexion, $sqlHistorialEditar);
        if (!$stmtHistorialEditar) {
            throw new ErrorTecnicoVeterinario(mysqli_error($conexion));
        }

        mysqli_stmt_bind_param($stmtHistorialEditar, "i", $idHistorialEditar);
        mysqli_stmt_execute($stmtHistorialEditar);
        $resultadoHistorialEditar = mysqli_stmt_get_result(
            $stmtHistorialEditar,
        );
        $datosHistorialEditar = mysqli_fetch_assoc($resultadoHistorialEditar);
        mysqli_stmt_close($stmtHistorialEditar);

        if (!$datosHistorialEditar) {
            throw new RuntimeException(
                "El registro médico seleccionado no existe.",
            );
        }

        mysqli_begin_transaction($conexion);
        $enTransaccion = true;

        $sqlActualizarHistorial = "
            UPDATE historialmedicoanimal
            SET fecha_diagnostico = ?,
                fecha_recuperacion = NULLIF(?, ''),
                tratamiento = NULLIF(?, ''),
                observaciones = NULLIF(?, '')
            WHERE id_historial = ?
        ";
        $stmtActualizarHistorial = mysqli_prepare(
            $conexion,
            $sqlActualizarHistorial,
        );
        if (!$stmtActualizarHistorial) {
            throw new ErrorTecnicoVeterinario(mysqli_error($conexion));
        }

        mysqli_stmt_bind_param(
            $stmtActualizarHistorial,
            "ssssi",
            $fechaDiagnosticoEditar,
            $fechaRecuperacionEditar,
            $tratamientoEditar,
            $observacionesEditar,
            $idHistorialEditar,
        );

        if (!mysqli_stmt_execute($stmtActualizarHistorial)) {
            throw new ErrorTecnicoVeterinario(
                mysqli_stmt_error($stmtActualizarHistorial),
            );
        }
        mysqli_stmt_close($stmtActualizarHistorial);

        $detallesEdicion = sprintf(
            "Historial actualizado para %s. Condición: %s.",
            $datosHistorialEditar["nombre_animal"],
            $datosHistorialEditar["condicion"],
        );

        if (
            !registrarBitacora(
                $_SESSION["usuario"],
                "ACTUALIZAR HISTORIAL MÉDICO",
                "historialmedicoanimal",
                $detallesEdicion,
                $idHistorialEditar,
            )
        ) {
            throw new RuntimeException(
                "No fue posible registrar la acción en la bitácora.",
            );
        }

        mysqli_commit($conexion);
        $enTransaccion = false;

        $mensaje =
            "El tratamiento y el estado clínico se actualizaron correctamente.";
        $tipoMensaje = "success";
    } catch (Throwable $e) {
        if ($enTransaccion) {
            mysqli_rollback($conexion);
        }

        registrarErrorVeterinario(
            "ACTUALIZAR HISTORIAL MÉDICO",
            "historialmedicoanimal",
            $e,
        );
        $mensaje = mensajeErrorVeterinario($e);
        $tipoMensaje = "danger";
    }
}

/* ELIMINAR HISTORIAL MÉDICO MEDIANTE BORRADO LÓGICO */
if (
    $_SERVER["REQUEST_METHOD"] === "POST" &&
    isset($_POST["eliminar_historial"])
) {
    try {
        $idHistorial = filter_input(
            INPUT_POST,
            "id_historial",
            FILTER_VALIDATE_INT,
        );
        if (!$idHistorial) {
            throw new RuntimeException("ID de historial inválido.");
        }
        mysqli_begin_transaction($conexion);

        $sqlUpd =
            "UPDATE historialmedicoanimal SET activo = 0 WHERE id_historial = ?";
        $stmtUpd = mysqli_prepare($conexion, $sqlUpd);
        if (!$stmtUpd) {
            throw new ErrorTecnicoVeterinario(mysqli_error($conexion));
        }
        mysqli_stmt_bind_param($stmtUpd, "i", $idHistorial);
        mysqli_stmt_execute($stmtUpd);
        mysqli_stmt_close($stmtUpd);

        registrarBitacora(
            $_SESSION["usuario"],
            "ELIMINAR HISTORIAL",
            "historialmedicoanimal",
            "Se eliminó lógicamente el historial médico #" . $idHistorial,
            $idHistorial,
        );

        mysqli_commit($conexion);
        $mensaje = "Historial médico eliminado correctamente.";
        $tipoMensaje = "success";
    } catch (Throwable $e) {
        mysqli_rollback($conexion);
        $mensaje = mensajeErrorVeterinario($e);
        $tipoMensaje = "danger";
    }
}