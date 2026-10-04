<?php

/** @var mysqli $conexion */

if (!isset($conexion) || !($conexion instanceof mysqli)) {
    http_response_code(403);
    exit("Acceso no permitido.");
}

/* REGISTRAR DIETA Y HORARIO */
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["registrar_dieta"])) {
    try {
        $idAnimal = filter_input(INPUT_POST, "id_animal", FILTER_VALIDATE_INT);
        $idProducto = filter_input(
            INPUT_POST,
            "id_producto",
            FILTER_VALIDATE_INT,
        );
        $cantidad = filter_input(
            INPUT_POST,
            "cantidad_recomendada",
            FILTER_VALIDATE_FLOAT,
        );

        $hora = trim($_POST["hora"] ?? "");

        if (!$idAnimal || !$idProducto) {
            throw new RuntimeException(
                "Debes seleccionar un animal y un alimento.",
            );
        }

        if ($cantidad === false || $cantidad <= 0) {
            throw new RuntimeException(
                "La cantidad recomendada debe ser mayor a cero.",
            );
        }

        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $hora)) {
            throw new RuntimeException("La hora indicada no es válida.");
        }

        mysqli_begin_transaction($conexion);

        // Obtener datos del animal y calcular alergias de forma dinámica desde historialmedicoanimal
        $sqlAnimal = "
            SELECT nombre_animal,
                COALESCE(
                    (SELECT GROUP_CONCAT(e.nombre SEPARATOR ', ')
                     FROM historialmedicoanimal hma
                     INNER JOIN enfermedad e ON hma.id_enfermedad = e.id_enfermedad
                     WHERE hma.id_animal = ? AND e.tipo = 'Alergia' AND hma.fecha_recuperacion IS NULL AND hma.activo = 1),
                    'Ninguna'
                ) AS alergias
            FROM animal
            WHERE id_animal = ?
            LIMIT 1
        ";

        $stmtAnimal = mysqli_prepare($conexion, $sqlAnimal);
        if (!$stmtAnimal) {
            throw new ErrorTecnicoVeterinario(mysqli_error($conexion));
        }
        mysqli_stmt_bind_param($stmtAnimal, "ii", $idAnimal, $idAnimal);
        mysqli_stmt_execute($stmtAnimal);
        $resultadoAnimal = mysqli_stmt_get_result($stmtAnimal);
        $animal = mysqli_fetch_assoc($resultadoAnimal);
        mysqli_stmt_close($stmtAnimal);

        if (!$animal) {
            throw new RuntimeException("El animal seleccionado no existe.");
        }

        // Obtener el alimento de la tabla inventariozoo
        $sqlProducto = "
    SELECT
        nombre_producto,
        unidad_medida,
        cantidad,
        fecha_caducidad
    FROM inventariozoo
    WHERE id_inventarioZoo = ?
      AND cantidad > 0
      AND nombre_producto NOT IN (
            'Cloro',
            'Antibiótico veterinario'
      )
    LIMIT 1
    FOR UPDATE
";

        $stmtProducto = mysqli_prepare($conexion, $sqlProducto);
        if (!$stmtProducto) {
            throw new ErrorTecnicoVeterinario(mysqli_error($conexion));
        }
        mysqli_stmt_bind_param($stmtProducto, "i", $idProducto);
        mysqli_stmt_execute($stmtProducto);
        $resultadoProducto = mysqli_stmt_get_result($stmtProducto);
        $producto = mysqli_fetch_assoc($resultadoProducto);
        mysqli_stmt_close($stmtProducto);

        if (!$producto) {
            throw new RuntimeException(
                "El alimento no existe, no tiene existencias o no es apto para alimentación.",
            );
        }

        // Validar caducidad directamente en PHP para dar un aviso personalizado
        if (
            $producto["fecha_caducidad"] !== null &&
            $producto["fecha_caducidad"] < date("Y-m-d")
        ) {
            throw new RuntimeException(
                "Error: El alimento seleccionado (" .
                    $producto["nombre_producto"] .
                    ") está vencido.",
            );
        }
        if ($cantidad > (float) $producto["cantidad"]) {
            throw new RuntimeException(
                "La cantidad recomendada supera las existencias disponibles. " .
                    "Stock actual: " .
                    number_format((float) $producto["cantidad"], 2, ",", ".") .
                    " " .
                    $producto["unidad_medida"],
            );
        }
        // 1. Validación de Alergias
        $alergiasLower = mb_strtolower($animal["alergias"] ?? "ninguna");
        $productoLower = mb_strtolower($producto["nombre_producto"]);
        if (
            $alergiasLower !== "ninguna" &&
            str_contains($alergiasLower, $productoLower)
        ) {
            throw new RuntimeException(
                sprintf(
                    "¡Riesgo Médico! No se puede registrar la dieta: el animal tiene registrado '%s' y es sensible/alérgico a '%s'.",
                    $animal["alergias"],
                    $producto["nombre_producto"],
                ),
            );
        }

        // 2. Validación de Conflictos de Horario
        $sqlConflicto = "
            SELECT aa.hora, iz.nombre_producto
            FROM alimentacion_animal aa
            INNER JOIN inventariozoo iz ON aa.id_inventarioZoo = iz.id_inventarioZoo
            WHERE aa.id_animal = ?
              AND aa.hora = ?
            LIMIT 1
        ";
        $stmtConflicto = mysqli_prepare($conexion, $sqlConflicto);
        if ($stmtConflicto) {
            mysqli_stmt_bind_param($stmtConflicto, "is", $idAnimal, $hora);
            mysqli_stmt_execute($stmtConflicto);
            $resConflicto = mysqli_stmt_get_result($stmtConflicto);
            $conflicto = mysqli_fetch_assoc($resConflicto);
            mysqli_stmt_close($stmtConflicto);

            if ($conflicto) {
                throw new RuntimeException(
                    sprintf(
                        "Conflicto de horario: Este animal ya tiene programada una alimentación de '%s' a las %s.",
                        $conflicto["nombre_producto"],
                        date("h:i A", strtotime($conflicto["hora"])),
                    ),
                );
            }
        }

        $sqlAlimentacion = "
            INSERT INTO alimentacion_animal (
                id_animal,
                id_inventarioZoo,
                hora,
                cantidad_recomendada
            )
            VALUES (?, ?, ?, ?)
        ";

        $stmtAlimentacion = mysqli_prepare($conexion, $sqlAlimentacion);
        if (!$stmtAlimentacion) {
            throw new ErrorTecnicoVeterinario(mysqli_error($conexion));
        }

        mysqli_stmt_bind_param(
            $stmtAlimentacion,
            "iisd",
            $idAnimal,
            $idProducto,
            $hora,
            $cantidad,
        );

        if (!mysqli_stmt_execute($stmtAlimentacion)) {
            throw new ErrorTecnicoVeterinario(mysqli_stmt_error($stmtAlimentacion));
        }

        mysqli_stmt_close($stmtAlimentacion);
        $idAlimentacion = mysqli_insert_id($conexion);

        $detallesBitacora = sprintf(
            "Dieta registrada para %s. Alimento: %s. Cantidad: %.2f. Hora: %s.",
            $animal["nombre_animal"],
            $producto["nombre_producto"],
            $cantidad,
            $hora,
        );

        if (
            !registrarBitacora(
                $_SESSION["usuario"],
                "REGISTRAR DIETA",
                "alimentacion_animal",
                $detallesBitacora,
                $idAlimentacion,
            )
        ) {
            throw new RuntimeException(
                "No fue posible registrar la acción en la bitácora.",
            );
        }

        mysqli_commit($conexion);

        $mensaje = "La dieta y el horario se registraron correctamente.";
        $tipoMensaje = "success";
    } catch (Throwable $e) {
        mysqli_rollback($conexion);
        error_log("Error registrando dieta: " . $e->getMessage());

        $mensaje = mensajeErrorVeterinario($e);
        $tipoMensaje = "danger";
    }
}

/* ELIMINAR DIETA MEDIANTE BORRADO LÓGICO */
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["eliminar_dieta"])) {
    try {
        $idAlimentacion = filter_input(
            INPUT_POST,
            "id_alimentacion",
            FILTER_VALIDATE_INT,
        );
        if (!$idAlimentacion) {
            throw new RuntimeException("ID de dieta inválido.");
        }

        // 1. Obtener detalles de la dieta antes de eliminarla para registrar en la bitácora
        $sqlDetalles = "
            SELECT a.nombre_animal, iz.nombre_producto, aa.cantidad_recomendada, aa.hora
            FROM alimentacion_animal aa
            INNER JOIN animal a ON aa.id_animal = a.id_animal
            INNER JOIN inventariozoo iz ON aa.id_inventarioZoo = iz.id_inventarioZoo
            WHERE aa.id_alimentacion = ?
            LIMIT 1
        ";
        $info = null;
        $stmtDetalles = mysqli_prepare($conexion, $sqlDetalles);
        if ($stmtDetalles) {
            mysqli_stmt_bind_param($stmtDetalles, "i", $idAlimentacion);
            mysqli_stmt_execute($stmtDetalles);
            $resDetalles = mysqli_stmt_get_result($stmtDetalles);
            $info = mysqli_fetch_assoc($resDetalles);
            mysqli_stmt_close($stmtDetalles);
        }

        if (!$info) {
            throw new RuntimeException("La dieta seleccionada no existe.");
        }

        mysqli_begin_transaction($conexion);

        // 2. Eliminar de la tabla alimentacion_animal (borrado lógico)
        $sqlDelete =
            "UPDATE alimentacion_animal SET activo = 0 WHERE id_alimentacion = ?";
        $stmtDelete = mysqli_prepare($conexion, $sqlDelete);
        if (!$stmtDelete) {
            throw new ErrorTecnicoVeterinario(mysqli_error($conexion));
        }
        mysqli_stmt_bind_param($stmtDelete, "i", $idAlimentacion);
        if (!mysqli_stmt_execute($stmtDelete)) {
            throw new ErrorTecnicoVeterinario(mysqli_stmt_error($stmtDelete));
        }
        mysqli_stmt_close($stmtDelete);

        // 3. Registrar en la bitácora
        $detallesBitacora = sprintf(
            "Dieta eliminada para %s. Alimento: %s. Cantidad: %.2f. Hora: %s.",
            $info["nombre_animal"],
            $info["nombre_producto"],
            $info["cantidad_recomendada"],
            $info["hora"] ?? "N/A",
        );

        if (
            !registrarBitacora(
                $_SESSION["usuario"],
                "ELIMINAR DIETA",
                "alimentacion_animal",
                $detallesBitacora,
                $idAlimentacion,
            )
        ) {
            throw new RuntimeException(
                "No fue posible registrar la eliminación en la bitácora.",
            );
        }

        mysqli_commit($conexion);

        $mensaje = "La dieta y su horario se eliminaron correctamente.";
        $tipoMensaje = "success";
    } catch (Throwable $e) {
        mysqli_rollback($conexion);
        error_log("Error eliminando dieta: " . $e->getMessage());
        $mensaje = mensajeErrorVeterinario($e);
        $tipoMensaje = "danger";
    }
}

/* ACTUALIZAR DIETA Y HORARIO */
if (
    $_SERVER["REQUEST_METHOD"] === "POST" &&
    isset($_POST["actualizar_dieta"])
) {
    try {
        $idAlimentacion = filter_input(
            INPUT_POST,
            "id_alimentacion",
            FILTER_VALIDATE_INT,
        );
        $idAnimal = filter_input(INPUT_POST, "id_animal", FILTER_VALIDATE_INT);
        $idProducto = filter_input(
            INPUT_POST,
            "id_producto",
            FILTER_VALIDATE_INT,
        );
        $cantidad = filter_input(
            INPUT_POST,
            "cantidad_recomendada",
            FILTER_VALIDATE_FLOAT,
        );
        $hora = trim($_POST["hora"] ?? "");

        if (
            !$idAlimentacion ||
            !$idAnimal ||
            !$idProducto ||
            $cantidad <= 0 ||
            empty($hora)
        ) {
            throw new RuntimeException("Campos de edición de dieta inválidos.");
        }

        mysqli_begin_transaction($conexion);

        // 1. Validación de Alergias
        $sqlAnimalInfo = "
            SELECT a.nombre_animal,
                (
                    SELECT COALESCE(GROUP_CONCAT(e.nombre SEPARATOR ', '), 'Ninguna')
                    FROM historialmedicoanimal hma
                    INNER JOIN enfermedad e ON hma.id_enfermedad = e.id_enfermedad
                    WHERE hma.id_animal = a.id_animal
                      AND e.tipo = 'Alergia'
                      AND hma.fecha_recuperacion IS NULL
                      AND hma.activo = 1
                ) AS alergias
            FROM animal a
            WHERE a.id_animal = ?
            LIMIT 1
        ";
        $stmtAnimal = mysqli_prepare($conexion, $sqlAnimalInfo);
        if (!$stmtAnimal) {
            throw new ErrorTecnicoVeterinario(mysqli_error($conexion));
        }
        mysqli_stmt_bind_param($stmtAnimal, "i", $idAnimal);
        mysqli_stmt_execute($stmtAnimal);
        $resAnimal = mysqli_stmt_get_result($stmtAnimal);
        $animal = mysqli_fetch_assoc($resAnimal);
        mysqli_stmt_close($stmtAnimal);

        if (!$animal) {
            throw new RuntimeException("El animal seleccionado no existe.");
        }

        $sqlProdInfo =
            "SELECT nombre_producto FROM inventariozoo WHERE id_inventarioZoo = ? LIMIT 1";
        $stmtProd = mysqli_prepare($conexion, $sqlProdInfo);
        if (!$stmtProd) {
            throw new ErrorTecnicoVeterinario(mysqli_error($conexion));
        }
        mysqli_stmt_bind_param($stmtProd, "i", $idProducto);
        mysqli_stmt_execute($stmtProd);
        $resProd = mysqli_stmt_get_result($stmtProd);
        $producto = mysqli_fetch_assoc($resProd);
        mysqli_stmt_close($stmtProd);

        if (!$producto) {
            throw new RuntimeException("El alimento seleccionado no existe.");
        }

        $alergiasLower = mb_strtolower($animal["alergias"] ?? "ninguna");
        $productoLower = mb_strtolower($producto["nombre_producto"]);
        if (
            $alergiasLower !== "ninguna" &&
            str_contains($alergiasLower, $productoLower)
        ) {
            throw new RuntimeException(
                sprintf(
                    "¡Riesgo Médico! No se puede actualizar la dieta: el animal tiene registrado '%s' y es sensible/alérgico a '%s'.",
                    $animal["alergias"],
                    $producto["nombre_producto"],
                ),
            );
        }

        // 2. Validación de Conflictos de Horario (excluyendo la dieta actual)
        $sqlConflicto = "
            SELECT aa.hora, iz.nombre_producto
            FROM alimentacion_animal aa
            INNER JOIN inventariozoo iz ON aa.id_inventarioZoo = iz.id_inventarioZoo
            WHERE aa.id_animal = ?
              AND aa.hora = ?
              AND aa.id_alimentacion != ?
              AND aa.activo = 1
            LIMIT 1
        ";
        $stmtConflicto = mysqli_prepare($conexion, $sqlConflicto);
        if ($stmtConflicto) {
            mysqli_stmt_bind_param(
                $stmtConflicto,
                "isi",
                $idAnimal,
                $hora,
                $idAlimentacion,
            );
            mysqli_stmt_execute($stmtConflicto);
            $resConflicto = mysqli_stmt_get_result($stmtConflicto);
            $conflicto = mysqli_fetch_assoc($resConflicto);
            mysqli_stmt_close($stmtConflicto);

            if ($conflicto) {
                throw new RuntimeException(
                    sprintf(
                        "Conflicto de horario: Este animal ya tiene programada una alimentación de '%s' a las %s.",
                        $conflicto["nombre_producto"],
                        date("h:i A", strtotime($conflicto["hora"])),
                    ),
                );
            }
        }

        $sqlUpd =
            "UPDATE alimentacion_animal SET id_animal = ?, id_inventarioZoo = ?, cantidad_recomendada = ?, hora = ? WHERE id_alimentacion = ?";
        $stmtUpd = mysqli_prepare($conexion, $sqlUpd);
        if (!$stmtUpd) {
            throw new ErrorTecnicoVeterinario(mysqli_error($conexion));
        }
        mysqli_stmt_bind_param(
            $stmtUpd,
            "iidsi",
            $idAnimal,
            $idProducto,
            $cantidad,
            $hora,
            $idAlimentacion,
        );
        mysqli_stmt_execute($stmtUpd);
        mysqli_stmt_close($stmtUpd);

        registrarBitacora(
            $_SESSION["usuario"],
            "EDITAR DIETA",
            "alimentacion_animal",
            "Se actualizó la dieta #" .
                $idAlimentacion .
                " para " .
                $animal["nombre_animal"] .
                " con alimento " .
                $producto["nombre_producto"] .
                ", cantidad " .
                $cantidad .
                " y hora " .
                $hora,
            $idAlimentacion,
        );

        mysqli_commit($conexion);
        $mensaje = "La dieta se actualizó correctamente.";
        $tipoMensaje = "success";
    } catch (Throwable $e) {
        mysqli_rollback($conexion);
        $mensaje = mensajeErrorVeterinario($e);
        $tipoMensaje = "danger";
    }
}