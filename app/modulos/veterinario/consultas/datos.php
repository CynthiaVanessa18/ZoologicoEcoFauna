<?php

/** @var mysqli $conexion */

if (!isset($conexion) || !($conexion instanceof mysqli)) {
    http_response_code(403);
    exit("Acceso no permitido.");
}

/* =====================================================
   ANIMALES Y ALIMENTOS
===================================================== */

$sqlAnimales = "
    SELECT
        a.id_animal,
        a.codigo_animal,
        a.nombre_animal,
        a.peso,
        a.altura,
        COALESCE(
            (
                SELECT GROUP_CONCAT(e.nombre SEPARATOR ', ')
                FROM historialmedicoanimal hma
                INNER JOIN enfermedad e
                    ON hma.id_enfermedad = e.id_enfermedad
                WHERE hma.id_animal = a.id_animal
                  AND e.tipo = 'Alergia'
                  AND hma.fecha_recuperacion IS NULL
                  AND hma.activo = 1
            ),
            'Ninguna'
        ) AS alergias,
        COALESCE(
            (
                SELECT 'Enfermo'
                FROM historialmedicoanimal hma
                INNER JOIN enfermedad e
                    ON hma.id_enfermedad = e.id_enfermedad
                WHERE hma.id_animal = a.id_animal
                  AND e.tipo = 'Enfermedad'
                  AND hma.fecha_recuperacion IS NULL
                  AND hma.activo = 1
                LIMIT 1
            ),
            'Activo'
        ) AS estado_salud
    FROM animal a
    ORDER BY a.nombre_animal ASC
";

$resultadoAnimales = mysqli_query(
    $conexion,
    $sqlAnimales
);

if (!$resultadoAnimales) {
    error_log(mysqli_error($conexion));
}

$sqlAlimentos = "
    SELECT
        MIN(id_inventarioZoo) AS id_Producto,
        nombre_producto,
        MIN(unidad_medida) AS unidad_medida,
        SUM(cantidad) AS cantidad,
        MIN(fecha_caducidad) AS fecha_caducidad
    FROM inventariozoo
    WHERE activo = 1
      AND cantidad > 0
      AND nombre_producto IN (
            'Manzana',
            'Banano',
            'Carne de res',
            'Mango',
            'Semillas de girasol',
            'Pescado fresco',
            'Lechuga romana',
            'Zanahoria'
      )
    GROUP BY nombre_producto
    ORDER BY nombre_producto ASC
";

$resultadoAlimentos = mysqli_query(
    $conexion,
    $sqlAlimentos
);

if (!$resultadoAlimentos) {
    error_log(mysqli_error($conexion));
}

$errorCargaAnimales =
    $resultadoAnimales === false;

$errorCargaAlimentos =
    $resultadoAlimentos === false;

$listaAnimales = [];

if ($resultadoAnimales) {
    while (
        $animal = mysqli_fetch_assoc(
            $resultadoAnimales
        )
    ) {
        $listaAnimales[] = $animal;
    }
}

$listaAlimentos = [];

if ($resultadoAlimentos) {
    while (
        $alimento = mysqli_fetch_assoc(
            $resultadoAlimentos
        )
    ) {
        $listaAlimentos[] = $alimento;
    }
}


/* =====================================================
   HISTORIAL COMPLETO PARA LA FICHA DEL ANIMAL
===================================================== */

$sqlHistoriales = "
    SELECT
        hma.id_animal,
        e.nombre AS enfermedad,
        e.tipo AS tipo_enfermedad,
        hma.fecha_diagnostico,
        hma.fecha_recuperacion,
        hma.tratamiento,
        hma.observaciones
    FROM historialmedicoanimal hma
    INNER JOIN enfermedad e
        ON hma.id_enfermedad = e.id_enfermedad
    WHERE hma.activo = 1
    ORDER BY hma.fecha_diagnostico DESC
";

$resultadoHistoriales = mysqli_query(
    $conexion,
    $sqlHistoriales
);

$historiales = [];

if ($resultadoHistoriales) {
    while (
        $historial = mysqli_fetch_assoc(
            $resultadoHistoriales
        )
    ) {
        $historiales[] = [
            "id_animal" =>
                (int) $historial["id_animal"],

            "enfermedad" =>
                $historial["enfermedad"],

            "tipo_enfermedad" =>
                $historial["tipo_enfermedad"],

            "fecha_diagnostico" =>
                $historial["fecha_diagnostico"],

            "fecha_recuperacion" =>
                $historial["fecha_recuperacion"],

            "tratamiento" =>
                $historial["tratamiento"] ?? "",

            "observaciones" =>
                $historial["observaciones"] ?? ""
        ];
    }
} else {
    error_log(
        "Error cargando el historial completo: " .
        mysqli_error($conexion)
    );
}


/* =====================================================
   CATÁLOGOS DEL MÓDULO MÉDICO
===================================================== */

$listaAnimalesMedicos = [];

$resultadoListaAnimales = mysqli_query(
    $conexion,
    "
        SELECT
            id_animal,
            codigo_animal,
            nombre_animal,
            peso,
            altura
        FROM animal
        ORDER BY nombre_animal ASC
    "
);

if ($resultadoListaAnimales) {
    while (
        $animalMedico = mysqli_fetch_assoc(
            $resultadoListaAnimales
        )
    ) {
        $listaAnimalesMedicos[] =
            $animalMedico;
    }
} else {
    error_log(
        "Error cargando animales del módulo médico: " .
        mysqli_error($conexion)
    );
}

$listaCondicionesMedicas = [];

$resultadoCondicionesMedicas = mysqli_query(
    $conexion,
    "
        SELECT
            id_enfermedad,
            nombre,
            tipo,
            descripcion
        FROM enfermedad
        ORDER BY tipo ASC, nombre ASC
    "
);

if ($resultadoCondicionesMedicas) {
    while (
        $condicionMedica = mysqli_fetch_assoc(
            $resultadoCondicionesMedicas
        )
    ) {
        $listaCondicionesMedicas[] =
            $condicionMedica;
    }
} else {
    error_log(
        "Error cargando el catálogo médico: " .
        mysqli_error($conexion)
    );
}


/* =====================================================
   PAGINACIÓN DEL HISTORIAL MÉDICO
===================================================== */

$regPorPagHist = 5;

$pagActualHist =
    filter_input(
        INPUT_GET,
        "pag_hist",
        FILTER_VALIDATE_INT
    ) ?: 1;

if ($pagActualHist < 1) {
    $pagActualHist = 1;
}

$sqlContarHist = "
    SELECT COUNT(*) AS total
    FROM historialmedicoanimal hma
    INNER JOIN animal a
        ON hma.id_animal = a.id_animal
    INNER JOIN enfermedad e
        ON hma.id_enfermedad = e.id_enfermedad
    WHERE hma.activo = 1
";

$resContarHist = mysqli_query(
    $conexion,
    $sqlContarHist
);

$totalRegHist = 0;

if ($resContarHist) {
    $filaContarHist =
        mysqli_fetch_assoc($resContarHist);

    $totalRegHist =
        (int) ($filaContarHist["total"] ?? 0);
} else {
    error_log(
        "Error contando historiales médicos: " .
        mysqli_error($conexion)
    );
}

$totalPagHist = max(
    1,
    (int) ceil(
        $totalRegHist / $regPorPagHist
    )
);

if ($pagActualHist > $totalPagHist) {
    $pagActualHist = $totalPagHist;
}

$offsetHist =
    ($pagActualHist - 1) *
    $regPorPagHist;

$registrosMedicos = [];

$sqlRegistrosMedicos = "
    SELECT
        hma.id_historial,
        hma.id_animal,
        a.codigo_animal,
        a.nombre_animal,
        e.nombre AS condicion,
        e.tipo,
        e.descripcion,
        hma.fecha_diagnostico,
        hma.fecha_recuperacion,
        hma.tratamiento,
        hma.observaciones
    FROM historialmedicoanimal hma
    INNER JOIN animal a
        ON hma.id_animal = a.id_animal
    INNER JOIN enfermedad e
        ON hma.id_enfermedad = e.id_enfermedad
    WHERE hma.activo = 1
    ORDER BY
        CASE
            WHEN hma.fecha_recuperacion IS NULL
            THEN 0
            ELSE 1
        END,
        hma.fecha_diagnostico DESC,
        a.nombre_animal ASC
    LIMIT $regPorPagHist
    OFFSET $offsetHist
";

$resultadoRegistrosMedicos = mysqli_query(
    $conexion,
    $sqlRegistrosMedicos
);

if ($resultadoRegistrosMedicos) {
    while (
        $registroMedico =
            mysqli_fetch_assoc(
                $resultadoRegistrosMedicos
            )
    ) {
        $registrosMedicos[] =
            $registroMedico;
    }
} else {
    error_log(
        "Error cargando historial médico: " .
        mysqli_error($conexion)
    );
}


/* =====================================================
   PAGINACIÓN DE VACUNACIÓN
===================================================== */

$regPorPagVac = 5;

$pagActualVac =
    filter_input(
        INPUT_GET,
        "pag_vac",
        FILTER_VALIDATE_INT
    ) ?: 1;

if ($pagActualVac < 1) {
    $pagActualVac = 1;
}

$sqlContarVac = "
    SELECT COUNT(*) AS total
    FROM vacunacion v
    INNER JOIN animal a
        ON v.id_animal = a.id_animal
    WHERE v.activo = 1
";

$resContarVac = mysqli_query(
    $conexion,
    $sqlContarVac
);

$totalRegVac = 0;

if ($resContarVac) {
    $filaContarVac =
        mysqli_fetch_assoc($resContarVac);

    $totalRegVac =
        (int) ($filaContarVac["total"] ?? 0);
} else {
    error_log(
        "Error contando vacunaciones: " .
        mysqli_error($conexion)
    );
}

$totalPagVac = max(
    1,
    (int) ceil(
        $totalRegVac / $regPorPagVac
    )
);

if ($pagActualVac > $totalPagVac) {
    $pagActualVac = $totalPagVac;
}

$offsetVac =
    ($pagActualVac - 1) *
    $regPorPagVac;

$registrosVacunas = [];

$sqlRegistrosVacunas = "
    SELECT
        v.id_vacunacion,
        v.id_animal,
        a.codigo_animal,
        a.nombre_animal,
        v.nombre_vacuna,
        v.fecha_vacunacion,
        v.fecha_proxima_vacuna,
        v.observaciones,
        CASE
            WHEN v.fecha_proxima_vacuna IS NULL
            THEN NULL
            ELSE DATEDIFF(
                v.fecha_proxima_vacuna,
                CURDATE()
            )
        END AS dias_restantes
    FROM vacunacion v
    INNER JOIN animal a
        ON v.id_animal = a.id_animal
    WHERE v.activo = 1
    ORDER BY
        v.fecha_vacunacion DESC,
        v.id_vacunacion DESC
    LIMIT $regPorPagVac
    OFFSET $offsetVac
";

$resultadoRegistrosVacunas = mysqli_query(
    $conexion,
    $sqlRegistrosVacunas
);

if ($resultadoRegistrosVacunas) {
    while (
        $registroVacuna =
            mysqli_fetch_assoc(
                $resultadoRegistrosVacunas
            )
    ) {
        $registrosVacunas[] =
            $registroVacuna;
    }
} else {
    error_log(
        "Error cargando vacunaciones: " .
        mysqli_error($conexion)
    );
}


/* =====================================================
   ALERTAS DE VACUNACIÓN
===================================================== */

$alertasVacunas = [];

$sqlAlertasVacunas = "
    SELECT
        v.id_vacunacion,
        v.id_animal,
        a.codigo_animal,
        a.nombre_animal,
        v.nombre_vacuna,
        v.fecha_proxima_vacuna,
        DATEDIFF(
            v.fecha_proxima_vacuna,
            CURDATE()
        ) AS dias_restantes
    FROM vacunacion v
    INNER JOIN animal a
        ON v.id_animal = a.id_animal
    WHERE v.activo = 1
      AND v.fecha_proxima_vacuna IS NOT NULL
      AND v.id_vacunacion = (
            SELECT MAX(v2.id_vacunacion)
            FROM vacunacion v2
            WHERE v2.id_animal = v.id_animal
              AND v2.nombre_vacuna =
                  v.nombre_vacuna
              AND v2.activo = 1
      )
    ORDER BY
        v.fecha_proxima_vacuna ASC
";

$resultadoAlertasVacunas = mysqli_query(
    $conexion,
    $sqlAlertasVacunas
);

if ($resultadoAlertasVacunas) {
    while (
        $alertaVacuna =
            mysqli_fetch_assoc(
                $resultadoAlertasVacunas
            )
    ) {
        $alertasVacunas[] =
            $alertaVacuna;
    }
} else {
    error_log(
        "Error calculando alertas de vacunación: " .
        mysqli_error($conexion)
    );
}


/* =====================================================
   HISTORIAL DE ALERTAS
===================================================== */

$regPorPagAlert = 5;

$pagActualAlert =
    filter_input(
        INPUT_GET,
        "pag_alert",
        FILTER_VALIDATE_INT
    ) ?: 1;

if ($pagActualAlert < 1) {
    $pagActualAlert = 1;
}

$sqlContarAlert = "
    SELECT COUNT(*) AS total
    FROM alertavacuna av
    INNER JOIN animal a
        ON av.id_animal = a.id_animal
    WHERE av.activo = 1
";

$resContarAlert = mysqli_query(
    $conexion,
    $sqlContarAlert
);

$totalRegAlert = 0;

if ($resContarAlert) {
    $filaContarAlert =
        mysqli_fetch_assoc($resContarAlert);

    $totalRegAlert =
        (int) ($filaContarAlert["total"] ?? 0);
} else {
    error_log(
        "Error contando alertas: " .
        mysqli_error($conexion)
    );
}

$totalPagAlert = max(
    1,
    (int) ceil(
        $totalRegAlert /
        $regPorPagAlert
    )
);

if ($pagActualAlert > $totalPagAlert) {
    $pagActualAlert =
        $totalPagAlert;
}

$offsetAlert =
    ($pagActualAlert - 1) *
    $regPorPagAlert;

$historialAlertas = [];

$sqlHistorialAlertas = "
    SELECT
        av.id_alerta,
        a.nombre_animal,
        a.codigo_animal,
        av.mensaje,
        av.fecha_generada
    FROM alertavacuna av
    INNER JOIN animal a
        ON av.id_animal = a.id_animal
    WHERE av.activo = 1
    ORDER BY
        av.fecha_generada DESC,
        av.id_alerta DESC
    LIMIT $regPorPagAlert
    OFFSET $offsetAlert
";

$resultadoHistorialAlertas =
    mysqli_query(
        $conexion,
        $sqlHistorialAlertas
    );

if ($resultadoHistorialAlertas) {
    while (
        $alertaGuardada =
            mysqli_fetch_assoc(
                $resultadoHistorialAlertas
            )
    ) {
        $historialAlertas[] =
            $alertaGuardada;
    }
} else {
    error_log(
        "Error cargando historial de alertas: " .
        mysqli_error($conexion)
    );
}


/* =====================================================
   ESTADÍSTICAS GENERALES
===================================================== */

$totalEnfermedadesActivas = 0;
$totalAlergiasActivas = 0;

/*
 * Se utiliza el historial completo y no solamente
 * la página actual de la tabla.
 */
foreach ($historiales as $historial) {
    if (
        $historial["fecha_recuperacion"] === null
    ) {
        if (
            $historial["tipo_enfermedad"] ===
            "Alergia"
        ) {
            $totalAlergiasActivas++;
        } else {
            $totalEnfermedadesActivas++;
        }
    }
}

$totalVacunasVencidas = 0;
$totalVacunasProximas = 0;

foreach (
    $alertasVacunas as $alertaVacuna
) {
    $diasRestantes =
        (int) $alertaVacuna[
            "dias_restantes"
        ];

    if ($diasRestantes < 0) {
        $totalVacunasVencidas++;
    } elseif ($diasRestantes <= 30) {
        $totalVacunasProximas++;
    }
}


/* =====================================================
   FILTRO Y PAGINACIÓN DE DIETAS
===================================================== */

$idAnimalFiltro =
    filter_input(
        INPUT_GET,
        "animal",
        FILTER_VALIDATE_INT
    );

$regPorPagDiet = 5;

$pagActualDiet =
    filter_input(
        INPUT_GET,
        "pag_diet",
        FILTER_VALIDATE_INT
    ) ?: 1;

if ($pagActualDiet < 1) {
    $pagActualDiet = 1;
}

$sqlContarDiet = "
    SELECT COUNT(*) AS total
    FROM alimentacion_animal aa
    INNER JOIN animal a
        ON aa.id_animal = a.id_animal
    INNER JOIN inventariozoo iz
        ON aa.id_inventarioZoo =
           iz.id_inventarioZoo
    WHERE aa.activo = 1
";

if ($idAnimalFiltro) {
    $sqlContarDiet .=
        " AND a.id_animal = ? ";
}

$stmtContarDiet = mysqli_prepare(
    $conexion,
    $sqlContarDiet
);

$totalRegDiet = 0;

if ($stmtContarDiet) {
    if ($idAnimalFiltro) {
        mysqli_stmt_bind_param(
            $stmtContarDiet,
            "i",
            $idAnimalFiltro
        );
    }

    if (
        mysqli_stmt_execute(
            $stmtContarDiet
        )
    ) {
        $resContarDiet =
            mysqli_stmt_get_result(
                $stmtContarDiet
            );

        $filaContarDiet =
            mysqli_fetch_assoc(
                $resContarDiet
            );

        $totalRegDiet =
            (int) (
                $filaContarDiet["total"] ??
                0
            );
    } else {
        error_log(
            mysqli_stmt_error(
                $stmtContarDiet
            )
        );
    }

    mysqli_stmt_close(
        $stmtContarDiet
    );
} else {
    error_log(mysqli_error($conexion));
}

$totalPagDiet = max(
    1,
    (int) ceil(
        $totalRegDiet /
        $regPorPagDiet
    )
);

if ($pagActualDiet > $totalPagDiet) {
    $pagActualDiet =
        $totalPagDiet;
}

$offsetDiet =
    ($pagActualDiet - 1) *
    $regPorPagDiet;


/* =====================================================
   HORARIOS DE ALIMENTACIÓN
===================================================== */

$sqlHorarios = "
    SELECT
        aa.id_alimentacion,
        aa.id_animal,
        aa.id_inventarioZoo,
        a.codigo_animal,
        a.nombre_animal,
        iz.nombre_producto,
        aa.cantidad_recomendada,
        aa.hora,
        iz.unidad_medida
    FROM alimentacion_animal aa
    INNER JOIN animal a
        ON aa.id_animal = a.id_animal
    INNER JOIN inventariozoo iz
        ON aa.id_inventarioZoo =
           iz.id_inventarioZoo
    WHERE aa.activo = 1
";

if ($idAnimalFiltro) {
    $sqlHorarios .=
        " AND a.id_animal = ? ";
}

$sqlHorarios .= "
    ORDER BY
        aa.hora ASC,
        a.nombre_animal ASC
    LIMIT $regPorPagDiet
    OFFSET $offsetDiet
";

$stmtHorarios = null;
$resultadoHorarios = false;

if ($idAnimalFiltro) {
    $stmtHorarios = mysqli_prepare(
        $conexion,
        $sqlHorarios
    );

    if ($stmtHorarios) {
        mysqli_stmt_bind_param(
            $stmtHorarios,
            "i",
            $idAnimalFiltro
        );

        if (
            mysqli_stmt_execute(
                $stmtHorarios
            )
        ) {
            $resultadoHorarios =
                mysqli_stmt_get_result(
                    $stmtHorarios
                );
        } else {
            error_log(
                mysqli_stmt_error(
                    $stmtHorarios
                )
            );
        }
    } else {
        error_log(mysqli_error($conexion));
    }
} else {
    $resultadoHorarios = mysqli_query(
        $conexion,
        $sqlHorarios
    );

    if (!$resultadoHorarios) {
        error_log(mysqli_error($conexion));
    }
}

$errorCargaHorarios =
    $resultadoHorarios === false;

$listaHorarios = [];

if ($resultadoHorarios) {
    while (
        $horario =
            mysqli_fetch_assoc(
                $resultadoHorarios
            )
    ) {
        $listaHorarios[] =
            $horario;
    }
}

if ($stmtHorarios instanceof mysqli_stmt) {
    mysqli_stmt_close($stmtHorarios);
}