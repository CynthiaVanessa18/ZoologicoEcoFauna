<?php

/** @var mysqli $conexion */

if (!isset($conexion) || !($conexion instanceof mysqli)) {
    http_response_code(403);
    exit("Acceso no permitido.");
}

/* REGISTRAR VACUNACIÓN Y SU ALERTA */
if (
    $_SERVER["REQUEST_METHOD"] === "POST" &&
    isset($_POST["registrar_vacuna"])
) {
    $enTransaccion = false;

    try {
        $idAnimalVacuna = filter_input(
            INPUT_POST,
            "id_animal_vacuna",
            FILTER_VALIDATE_INT,
        );
        $nombreVacuna = trim($_POST["nombre_vacuna"] ?? "");
        $fechaVacunacion = trim($_POST["fecha_vacunacion"] ?? "");
        $fechaProximaVacuna = trim($_POST["fecha_proxima_vacuna"] ?? "");
        $observacionesVacuna = trim($_POST["observaciones_vacuna"] ?? "");

        if (!$idAnimalVacuna || $nombreVacuna === "") {
            throw new RuntimeException(
                "Debes seleccionar un animal y escribir el nombre de la vacuna.",
            );
        }

        if (!fechaIsoValida($fechaVacunacion)) {
            throw new RuntimeException("La fecha de vacunación no es válida.");
        }

        if ($fechaVacunacion > date("Y-m-d")) {
            throw new RuntimeException(
                "Una vacuna aplicada no puede tener una fecha futura.",
            );
        }

        if ($fechaProximaVacuna !== "") {
            if (!fechaIsoValida($fechaProximaVacuna)) {
                throw new RuntimeException(
                    "La fecha de la próxima vacuna no es válida.",
                );
            }

            if ($fechaProximaVacuna <= $fechaVacunacion) {
                throw new RuntimeException(
                    "La próxima vacuna debe ser posterior a la aplicación actual.",
                );
            }
        }

        if (
            mb_strlen($nombreVacuna) > 100 ||
            mb_strlen($observacionesVacuna) > 500
        ) {
            throw new RuntimeException(
                "El nombre de la vacuna o las observaciones superan el tamaño permitido.",
            );
        }

        $sqlAnimalVacuna =
            "SELECT nombre_animal FROM animal WHERE id_animal = ? LIMIT 1";
        $stmtAnimalVacuna = mysqli_prepare($conexion, $sqlAnimalVacuna);
        if (!$stmtAnimalVacuna) {
            throw new ErrorTecnicoVeterinario(mysqli_error($conexion));
        }

        mysqli_stmt_bind_param($stmtAnimalVacuna, "i", $idAnimalVacuna);
        mysqli_stmt_execute($stmtAnimalVacuna);
        $resultadoAnimalVacuna = mysqli_stmt_get_result($stmtAnimalVacuna);
        $animalVacuna = mysqli_fetch_assoc($resultadoAnimalVacuna);
        mysqli_stmt_close($stmtAnimalVacuna);

        if (!$animalVacuna) {
            throw new RuntimeException("El animal seleccionado no existe.");
        }

        mysqli_begin_transaction($conexion);
        $enTransaccion = true;

        $sqlInsertarVacuna = "
            INSERT INTO vacunacion (
                id_animal,
                nombre_vacuna,
                fecha_vacunacion,
                fecha_proxima_vacuna,
                observaciones
            )
            VALUES (?, ?, ?, NULLIF(?, ''), NULLIF(?, ''))
        ";
        $stmtInsertarVacuna = mysqli_prepare($conexion, $sqlInsertarVacuna);
        if (!$stmtInsertarVacuna) {
            throw new ErrorTecnicoVeterinario(mysqli_error($conexion));
        }

        mysqli_stmt_bind_param(
            $stmtInsertarVacuna,
            "issss",
            $idAnimalVacuna,
            $nombreVacuna,
            $fechaVacunacion,
            $fechaProximaVacuna,
            $observacionesVacuna,
        );

        if (!mysqli_stmt_execute($stmtInsertarVacuna)) {
            throw new ErrorTecnicoVeterinario(mysqli_stmt_error($stmtInsertarVacuna));
        }

        $idVacunacion = mysqli_insert_id($conexion);
        mysqli_stmt_close($stmtInsertarVacuna);

        if ($fechaProximaVacuna !== "") {
            $mensajeAlerta = sprintf(
                "Próxima dosis de %s para %s programada para %s.",
                $nombreVacuna,
                $animalVacuna["nombre_animal"],
                $fechaProximaVacuna,
            );

            $sqlInsertarAlerta = "
                INSERT INTO alertavacuna (id_animal, mensaje)
                VALUES (?, ?)
            ";
            $stmtInsertarAlerta = mysqli_prepare($conexion, $sqlInsertarAlerta);
            if (!$stmtInsertarAlerta) {
                throw new ErrorTecnicoVeterinario(mysqli_error($conexion));
            }

            mysqli_stmt_bind_param(
                $stmtInsertarAlerta,
                "is",
                $idAnimalVacuna,
                $mensajeAlerta,
            );
            if (!mysqli_stmt_execute($stmtInsertarAlerta)) {
                throw new ErrorTecnicoVeterinario(
                    mysqli_stmt_error($stmtInsertarAlerta),
                );
            }
            mysqli_stmt_close($stmtInsertarAlerta);
        }

        $detallesVacuna = sprintf(
            "Vacuna %s aplicada a %s el %s. Próxima dosis: %s.",
            $nombreVacuna,
            $animalVacuna["nombre_animal"],
            $fechaVacunacion,
            $fechaProximaVacuna !== "" ? $fechaProximaVacuna : "sin fecha",
        );

        if (
            !registrarBitacora(
                $_SESSION["usuario"],
                "REGISTRAR VACUNA",
                "vacunacion",
                $detallesVacuna,
                $idVacunacion,
            )
        ) {
            throw new RuntimeException(
                "No fue posible registrar la acción en la bitácora.",
            );
        }

        mysqli_commit($conexion);
        $enTransaccion = false;

        $mensaje =
            "La vacuna y su próxima alerta se registraron correctamente.";
        $tipoMensaje = "success";
    } catch (Throwable $e) {
        if ($enTransaccion) {
            mysqli_rollback($conexion);
        }

        registrarErrorVeterinario("REGISTRAR VACUNA", "vacunacion", $e);
        $mensaje = mensajeErrorVeterinario($e);
        $tipoMensaje = "danger";
    }
}

/* ACTUALIZAR UNA VACUNACIÓN SIN ELIMINAR SU TRAZABILIDAD */
if (
    $_SERVER["REQUEST_METHOD"] === "POST" &&
    isset($_POST["actualizar_vacuna"])
) {
    $enTransaccion = false;

    try {
        $idVacunacionEditar = filter_input(
            INPUT_POST,
            "id_vacunacion",
            FILTER_VALIDATE_INT,
        );
        $nombreVacunaEditar = trim($_POST["nombre_vacuna_editar"] ?? "");
        $fechaVacunacionEditar = trim($_POST["fecha_vacunacion_editar"] ?? "");
        $fechaProximaEditar = trim($_POST["fecha_proxima_vacuna_editar"] ?? "");
        $observacionesVacunaEditar = trim(
            $_POST["observaciones_vacuna_editar"] ?? "",
        );

        if (
            !$idVacunacionEditar ||
            $nombreVacunaEditar === "" ||
            !fechaIsoValida($fechaVacunacionEditar)
        ) {
            throw new RuntimeException(
                "Los datos de la vacunación no son válidos.",
            );
        }

        if ($fechaVacunacionEditar > date("Y-m-d")) {
            throw new RuntimeException(
                "Una vacuna aplicada no puede tener una fecha futura.",
            );
        }

        if ($fechaProximaEditar !== "") {
            if (
                !fechaIsoValida($fechaProximaEditar) ||
                $fechaProximaEditar <= $fechaVacunacionEditar
            ) {
                throw new RuntimeException(
                    "La próxima vacuna debe ser una fecha posterior a la aplicación actual.",
                );
            }
        }

        if (
            mb_strlen($nombreVacunaEditar) > 100 ||
            mb_strlen($observacionesVacunaEditar) > 500
        ) {
            throw new RuntimeException(
                "El nombre de la vacuna o las observaciones superan el tamaño permitido.",
            );
        }

        $sqlVacunaEditar = "
            SELECT v.id_animal, a.nombre_animal
            FROM vacunacion v
            INNER JOIN animal a ON v.id_animal = a.id_animal
            WHERE v.id_vacunacion = ?
            LIMIT 1
        ";
        $stmtVacunaEditar = mysqli_prepare($conexion, $sqlVacunaEditar);
        if (!$stmtVacunaEditar) {
            throw new ErrorTecnicoVeterinario(mysqli_error($conexion));
        }

        mysqli_stmt_bind_param($stmtVacunaEditar, "i", $idVacunacionEditar);
        mysqli_stmt_execute($stmtVacunaEditar);
        $resultadoVacunaEditar = mysqli_stmt_get_result($stmtVacunaEditar);
        $datosVacunaEditar = mysqli_fetch_assoc($resultadoVacunaEditar);
        mysqli_stmt_close($stmtVacunaEditar);

        if (!$datosVacunaEditar) {
            throw new RuntimeException("La vacunación seleccionada no existe.");
        }

        mysqli_begin_transaction($conexion);
        $enTransaccion = true;

        $sqlActualizarVacuna = "
            UPDATE vacunacion
            SET nombre_vacuna = ?,
                fecha_vacunacion = ?,
                fecha_proxima_vacuna = NULLIF(?, ''),
                observaciones = NULLIF(?, '')
            WHERE id_vacunacion = ?
        ";
        $stmtActualizarVacuna = mysqli_prepare($conexion, $sqlActualizarVacuna);
        if (!$stmtActualizarVacuna) {
            throw new ErrorTecnicoVeterinario(mysqli_error($conexion));
        }

        mysqli_stmt_bind_param(
            $stmtActualizarVacuna,
            "ssssi",
            $nombreVacunaEditar,
            $fechaVacunacionEditar,
            $fechaProximaEditar,
            $observacionesVacunaEditar,
            $idVacunacionEditar,
        );

        if (!mysqli_stmt_execute($stmtActualizarVacuna)) {
            throw new ErrorTecnicoVeterinario(
                mysqli_stmt_error($stmtActualizarVacuna),
            );
        }
        mysqli_stmt_close($stmtActualizarVacuna);

        if ($fechaProximaEditar !== "") {
            $mensajeAlertaEditar = sprintf(
                "Próxima dosis de %s para %s reprogramada para %s.",
                $nombreVacunaEditar,
                $datosVacunaEditar["nombre_animal"],
                $fechaProximaEditar,
            );

            $sqlNuevaAlerta =
                "INSERT INTO alertavacuna (id_animal, mensaje) VALUES (?, ?)";
            $stmtNuevaAlerta = mysqli_prepare($conexion, $sqlNuevaAlerta);
            if (!$stmtNuevaAlerta) {
                throw new ErrorTecnicoVeterinario(mysqli_error($conexion));
            }

            mysqli_stmt_bind_param(
                $stmtNuevaAlerta,
                "is",
                $datosVacunaEditar["id_animal"],
                $mensajeAlertaEditar,
            );
            if (!mysqli_stmt_execute($stmtNuevaAlerta)) {
                throw new ErrorTecnicoVeterinario(mysqli_stmt_error($stmtNuevaAlerta));
            }
            mysqli_stmt_close($stmtNuevaAlerta);
        }

        if (
            !registrarBitacora(
                $_SESSION["usuario"],
                "ACTUALIZAR VACUNA",
                "vacunacion",
                "Vacunación actualizada para " .
                    $datosVacunaEditar["nombre_animal"] .
                    ".",
                $idVacunacionEditar,
            )
        ) {
            throw new RuntimeException(
                "No fue posible registrar la acción en la bitácora.",
            );
        }

        mysqli_commit($conexion);
        $enTransaccion = false;

        $mensaje = "La información de la vacuna se actualizó correctamente.";
        $tipoMensaje = "success";
    } catch (Throwable $e) {
        if ($enTransaccion) {
            mysqli_rollback($conexion);
        }

        registrarErrorVeterinario("ACTUALIZAR VACUNA", "vacunacion", $e);
        $mensaje = mensajeErrorVeterinario($e);
        $tipoMensaje = "danger";
    }
}

/* ELIMINAR VACUNACIÓN MEDIANTE BORRADO LÓGICO */
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["eliminar_vacuna"])) {
    try {
        $idVacunacion = filter_input(
            INPUT_POST,
            "id_vacunacion",
            FILTER_VALIDATE_INT,
        );
        if (!$idVacunacion) {
            throw new RuntimeException("ID de vacunación inválido.");
        }
        mysqli_begin_transaction($conexion);

        // 1. Obtener detalles de la vacuna antes de inactivarla
        $sqlVacInfo =
            "SELECT id_animal, nombre_vacuna FROM vacunacion WHERE id_vacunacion = ? LIMIT 1";
        $stmtVacInfo = mysqli_prepare($conexion, $sqlVacInfo);
        if ($stmtVacInfo) {
            mysqli_stmt_bind_param($stmtVacInfo, "i", $idVacunacion);
            mysqli_stmt_execute($stmtVacInfo);
            $resVacInfo = mysqli_stmt_get_result($stmtVacInfo);
            $rowVacInfo = mysqli_fetch_assoc($resVacInfo);
            mysqli_stmt_close($stmtVacInfo);

            if ($rowVacInfo) {
                // Inactivar (borrado lógico) alertas de vacuna asociadas
                $sqlDelAlerta =
                    "UPDATE alertavacuna SET activo = 0 WHERE id_animal = ? AND mensaje LIKE ?";
                $stmtDelAlerta = mysqli_prepare($conexion, $sqlDelAlerta);
                if ($stmtDelAlerta) {
                    $likeMsg = "%" . $rowVacInfo["nombre_vacuna"] . "%";
                    mysqli_stmt_bind_param(
                        $stmtDelAlerta,
                        "is",
                        $rowVacInfo["id_animal"],
                        $likeMsg,
                    );
                    mysqli_stmt_execute($stmtDelAlerta);
                    mysqli_stmt_close($stmtDelAlerta);
                }
            }
        }

        $sqlUpd = "UPDATE vacunacion SET activo = 0 WHERE id_vacunacion = ?";
        $stmtUpd = mysqli_prepare($conexion, $sqlUpd);
        if (!$stmtUpd) {
            throw new ErrorTecnicoVeterinario(mysqli_error($conexion));
        }
        mysqli_stmt_bind_param($stmtUpd, "i", $idVacunacion);
        mysqli_stmt_execute($stmtUpd);
        mysqli_stmt_close($stmtUpd);

        registrarBitacora(
            $_SESSION["usuario"],
            "ELIMINAR VACUNACION",
            "vacunacion",
            "Se eliminó lógicamente la vacunación #" . $idVacunacion,
            $idVacunacion,
        );

        mysqli_commit($conexion);
        $mensaje = "Vacunación eliminada correctamente.";
        $tipoMensaje = "success";
    } catch (Throwable $e) {
        mysqli_rollback($conexion);
        $mensaje = mensajeErrorVeterinario($e);
        $tipoMensaje = "danger";
    }
}