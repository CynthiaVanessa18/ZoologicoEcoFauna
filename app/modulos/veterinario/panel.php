<?php

require_once __DIR__ . "/../../soporte/sesion.php";
require_once __DIR__ . "/../../../config/database.php";
require_once __DIR__ . "/funciones.php";

/** @var mysqli $conexion */
$conexion = $GLOBALS["conexion"];

if (
    !isset(
        $_SESSION["usuario"],
        $_SESSION["rol"],
        $_SESSION["id_login"]
    ) ||
    $_SESSION["rol"] !== "Veterinario"
) {
    header("Location: index.php");
    exit();
}

$mensaje = $_SESSION["mensaje_permiso"] ?? "";
$tipoMensaje = $mensaje !== "" ? "warning" : "";
unset($_SESSION["mensaje_permiso"]);

obtenerTokenCsrfVeterinario();

$postVeterinarioValido =
    $_SERVER["REQUEST_METHOD"] !== "POST" ||
    tokenCsrfVeterinarioValido($_POST["csrf_token"] ?? null);

$permisosAccionesVeterinarias = [
    "registrar_condicion" => "REGISTRAR_ENFERMEDADES",
    "registrar_historial" => "CONSULTAR_HISTORIAL_MEDICO",
    "actualizar_historial" => "CONSULTAR_HISTORIAL_MEDICO",
    "eliminar_historial" => "CONSULTAR_HISTORIAL_MEDICO",
    "registrar_vacuna" => "REGISTRAR_VACUNACION",
    "actualizar_vacuna" => "REGISTRAR_VACUNACION",
    "eliminar_vacuna" => "REGISTRAR_VACUNACION",
    "registrar_dieta" => "REGISTRAR_ALIMENTACION",
    "actualizar_dieta" => "REGISTRAR_ALIMENTACION",
    "eliminar_dieta" => "REGISTRAR_ALIMENTACION",
];

$permisoPostVeterinarioValido = true;

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    foreach ($permisosAccionesVeterinarias as $campo => $permiso) {
        if (
            isset($_POST[$campo]) &&
            !usuarioTienePermiso($permiso, ["Veterinario"])
        ) {
            $permisoPostVeterinarioValido = false;
            break;
        }
    }
}

if ($postVeterinarioValido && $permisoPostVeterinarioValido) {
    require_once __DIR__ . "/acciones/condiciones.php";
    require_once __DIR__ . "/acciones/historial.php";
    require_once __DIR__ . "/acciones/vacunas.php";
    require_once __DIR__ . "/acciones/alimentacion.php";
} elseif (!$postVeterinarioValido) {
    http_response_code(403);
    $mensaje = "La solicitud expiró o no es válida. Recarga la página e inténtalo nuevamente.";
    $tipoMensaje = "danger";
} else {
    http_response_code(403);
    $mensaje = "Tu rol no tiene permiso para realizar esa acción.";
    $tipoMensaje = "danger";
}

$sqlAnimales = "
    SELECT
        a.id_animal, a.codigo_animal, a.nombre_animal, a.peso, a.altura,
        COALESCE(
            (SELECT GROUP_CONCAT(e.nombre SEPARATOR ', ')
             FROM historialmedicoanimal hma
             INNER JOIN enfermedad e ON hma.id_enfermedad = e.id_enfermedad
             WHERE hma.id_animal = a.id_animal AND e.tipo = 'Alergia' AND hma.fecha_recuperacion IS NULL AND hma.activo = 1),
            'Ninguna'
        ) AS alergias,
        COALESCE(
            (SELECT 'Enfermo'
             FROM historialmedicoanimal hma
             INNER JOIN enfermedad e ON hma.id_enfermedad = e.id_enfermedad
             WHERE hma.id_animal = a.id_animal AND e.tipo = 'Enfermedad' AND hma.fecha_recuperacion IS NULL AND hma.activo = 1
             LIMIT 1),
            'Activo'
        ) AS estado_salud
    FROM animal a
    ORDER BY a.nombre_animal ASC
";

$resultadoAnimales = mysqli_query($conexion, $sqlAnimales);

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

$resultadoAlimentos = mysqli_query($conexion, $sqlAlimentos);

if (!$resultadoAlimentos) {
    error_log(mysqli_error($conexion));
}

$listaAnimales = [];
if ($resultadoAnimales) {
    while ($row = mysqli_fetch_assoc($resultadoAnimales)) {
        $listaAnimales[] = $row;
    }
}

$listaAlimentos = [];
if ($resultadoAlimentos) {
    while ($row = mysqli_fetch_assoc($resultadoAlimentos)) {
        $listaAlimentos[] = $row;
    }
}

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
    INNER JOIN enfermedad e ON hma.id_enfermedad = e.id_enfermedad
    WHERE hma.activo = 1
    ORDER BY hma.fecha_diagnostico DESC
";
$resultadoHistoriales = mysqli_query($conexion, $sqlHistoriales);
$historiales = [];
if ($resultadoHistoriales) {
    while ($h = mysqli_fetch_assoc($resultadoHistoriales)) {
        $historiales[] = [
            "id_animal" => (int) $h["id_animal"],
            "enfermedad" => $h["enfermedad"],
            "tipo_enfermedad" => $h["tipo_enfermedad"],
            "fecha_diagnostico" => $h["fecha_diagnostico"],
            "fecha_recuperacion" => $h["fecha_recuperacion"],
            "tratamiento" => $h["tratamiento"] ?? "",
            "observaciones" => $h["observaciones"] ?? "",
        ];
    }
}

/* Datos reutilizables para el módulo médico */
$listaAnimalesMedicos = [];
$resultadoListaAnimales = mysqli_query(
    $conexion,
    "SELECT id_animal, codigo_animal, nombre_animal, peso, altura
     FROM animal
     ORDER BY nombre_animal ASC",
);

if ($resultadoListaAnimales) {
    while ($animalMedico = mysqli_fetch_assoc($resultadoListaAnimales)) {
        $listaAnimalesMedicos[] = $animalMedico;
    }
} else {
    error_log(
        "Error cargando animales del módulo médico: " . mysqli_error($conexion),
    );
}

$listaCondicionesMedicas = [];
$resultadoCondicionesMedicas = mysqli_query(
    $conexion,
    "SELECT id_enfermedad, nombre, tipo, descripcion
     FROM enfermedad
     ORDER BY tipo ASC, nombre ASC",
);

if ($resultadoCondicionesMedicas) {
    while (
        $condicionMedica = mysqli_fetch_assoc($resultadoCondicionesMedicas)
    ) {
        $listaCondicionesMedicas[] = $condicionMedica;
    }
} else {
    error_log("Error cargando catálogo médico: " . mysqli_error($conexion));
}

// Paginación para Historial Médico
$regPorPagHist = 5;
$pagActualHist = isset($_GET["pag_hist"]) ? (int) $_GET["pag_hist"] : 1;
if ($pagActualHist < 1) {
    $pagActualHist = 1;
}

$sqlContarHist =
    "SELECT COUNT(*) AS total FROM historialmedicoanimal hma INNER JOIN animal a ON hma.id_animal = a.id_animal INNER JOIN enfermedad e ON hma.id_enfermedad = e.id_enfermedad WHERE hma.activo = 1";
$resContarHist = mysqli_query($conexion, $sqlContarHist);
$filaContarHist = mysqli_fetch_assoc($resContarHist);
$totalRegHist = $filaContarHist["total"] ?? 0;
$totalPagHist = ceil($totalRegHist / $regPorPagHist);
if ($totalPagHist < 1) {
    $totalPagHist = 1;
}
if ($pagActualHist > $totalPagHist) {
    $pagActualHist = $totalPagHist;
}
$offsetHist = ($pagActualHist - 1) * $regPorPagHist;

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
    INNER JOIN animal a ON hma.id_animal = a.id_animal
    INNER JOIN enfermedad e ON hma.id_enfermedad = e.id_enfermedad
    WHERE hma.activo = 1
    ORDER BY
        CASE WHEN hma.fecha_recuperacion IS NULL THEN 0 ELSE 1 END,
        hma.fecha_diagnostico DESC,
        a.nombre_animal ASC
    LIMIT $regPorPagHist OFFSET $offsetHist
";
$resultadoRegistrosMedicos = mysqli_query($conexion, $sqlRegistrosMedicos);

if ($resultadoRegistrosMedicos) {
    while ($registroMedico = mysqli_fetch_assoc($resultadoRegistrosMedicos)) {
        $registrosMedicos[] = $registroMedico;
    }
} else {
    error_log("Error cargando historial médico: " . mysqli_error($conexion));
}

// Paginación para Vacunación
$regPorPagVac = 5;
$pagActualVac = isset($_GET["pag_vac"]) ? (int) $_GET["pag_vac"] : 1;
if ($pagActualVac < 1) {
    $pagActualVac = 1;
}

$sqlContarVac =
    "SELECT COUNT(*) AS total FROM vacunacion v INNER JOIN animal a ON v.id_animal = a.id_animal WHERE v.activo = 1";
$resContarVac = mysqli_query($conexion, $sqlContarVac);
$filaContarVac = mysqli_fetch_assoc($resContarVac);
$totalRegVac = $filaContarVac["total"] ?? 0;
$totalPagVac = ceil($totalRegVac / $regPorPagVac);
if ($totalPagVac < 1) {
    $totalPagVac = 1;
}
if ($pagActualVac > $totalPagVac) {
    $pagActualVac = $totalPagVac;
}
$offsetVac = ($pagActualVac - 1) * $regPorPagVac;

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
            WHEN v.fecha_proxima_vacuna IS NULL THEN NULL
            ELSE DATEDIFF(v.fecha_proxima_vacuna, CURDATE())
        END AS dias_restantes
    FROM vacunacion v
    INNER JOIN animal a ON v.id_animal = a.id_animal
    WHERE v.activo = 1
    ORDER BY v.fecha_vacunacion DESC, v.id_vacunacion DESC
    LIMIT $regPorPagVac OFFSET $offsetVac
";
$resultadoRegistrosVacunas = mysqli_query($conexion, $sqlRegistrosVacunas);

if ($resultadoRegistrosVacunas) {
    while ($registroVacuna = mysqli_fetch_assoc($resultadoRegistrosVacunas)) {
        $registrosVacunas[] = $registroVacuna;
    }
} else {
    error_log("Error cargando vacunaciones: " . mysqli_error($conexion));
}

/*
 * Solo se toma la vacunación más reciente de cada animal y nombre de vacuna.
 * Así una dosis aplicada no deja una alerta vencida duplicada para siempre.
 */
$alertasVacunas = [];
$sqlAlertasVacunas = "
    SELECT
        v.id_vacunacion,
        v.id_animal,
        a.codigo_animal,
        a.nombre_animal,
        v.nombre_vacuna,
        v.fecha_proxima_vacuna,
        DATEDIFF(v.fecha_proxima_vacuna, CURDATE()) AS dias_restantes
    FROM vacunacion v
    INNER JOIN animal a ON v.id_animal = a.id_animal
    WHERE v.activo = 1
      AND v.fecha_proxima_vacuna IS NOT NULL
      AND v.id_vacunacion = (
            SELECT MAX(v2.id_vacunacion)
            FROM vacunacion v2
            WHERE v2.id_animal = v.id_animal
              AND v2.nombre_vacuna = v.nombre_vacuna
              AND v2.activo = 1
      )
    ORDER BY v.fecha_proxima_vacuna ASC
";
$resultadoAlertasVacunas = mysqli_query($conexion, $sqlAlertasVacunas);

if ($resultadoAlertasVacunas) {
    while ($alertaVacuna = mysqli_fetch_assoc($resultadoAlertasVacunas)) {
        $alertasVacunas[] = $alertaVacuna;
    }
} else {
    error_log(
        "Error calculando alertas de vacunación: " . mysqli_error($conexion),
    );
}

$regPorPagAlert = 5;
$pagActualAlert = isset($_GET["pag_alert"]) ? (int) $_GET["pag_alert"] : 1;
if ($pagActualAlert < 1) {
    $pagActualAlert = 1;
}

$sqlContarAlert =
    "SELECT COUNT(*) AS total FROM alertavacuna av INNER JOIN animal a ON av.id_animal = a.id_animal WHERE av.activo = 1";
$resContarAlert = mysqli_query($conexion, $sqlContarAlert);
$filaContarAlert = mysqli_fetch_assoc($resContarAlert);
$totalRegAlert = $filaContarAlert["total"] ?? 0;
$totalPagAlert = ceil($totalRegAlert / $regPorPagAlert);
if ($totalPagAlert < 1) {
    $totalPagAlert = 1;
}
if ($pagActualAlert > $totalPagAlert) {
    $pagActualAlert = $totalPagAlert;
}
$offsetAlert = ($pagActualAlert - 1) * $regPorPagAlert;

$historialAlertas = [];
$sqlHistorialAlertas = "
    SELECT
        av.id_alerta,
        a.nombre_animal,
        a.codigo_animal,
        av.mensaje,
        av.fecha_generada
    FROM alertavacuna av
    INNER JOIN animal a ON av.id_animal = a.id_animal
    WHERE av.activo = 1
    ORDER BY av.fecha_generada DESC, av.id_alerta DESC
    LIMIT $regPorPagAlert OFFSET $offsetAlert
";
$resultadoHistorialAlertas = mysqli_query($conexion, $sqlHistorialAlertas);

if ($resultadoHistorialAlertas) {
    while ($alertaGuardada = mysqli_fetch_assoc($resultadoHistorialAlertas)) {
        $historialAlertas[] = $alertaGuardada;
    }
} else {
    error_log(
        "Error cargando historial de alertas: " . mysqli_error($conexion),
    );
}

$totalEnfermedadesActivas = 0;
$totalAlergiasActivas = 0;
foreach ($registrosMedicos as $registroMedico) {
    if ($registroMedico["fecha_recuperacion"] === null) {
        if ($registroMedico["tipo"] === "Alergia") {
            $totalAlergiasActivas++;
        } else {
            $totalEnfermedadesActivas++;
        }
    }
}

$totalVacunasVencidas = 0;
$totalVacunasProximas = 0;
foreach ($alertasVacunas as $alertaVacuna) {
    $diasRestantes = (int) $alertaVacuna["dias_restantes"];

    if ($diasRestantes < 0) {
        $totalVacunasVencidas++;
    } elseif ($diasRestantes <= 30) {
        $totalVacunasProximas++;
    }
}

$idAnimalFiltro = filter_input(INPUT_GET, "animal", FILTER_VALIDATE_INT);

// Paginación para Dietas
$regPorPagDiet = 5;
$pagActualDiet = isset($_GET["pag_diet"]) ? (int) $_GET["pag_diet"] : 1;
if ($pagActualDiet < 1) {
    $pagActualDiet = 1;
}

$sqlContarDiet =
    "SELECT COUNT(*) AS total FROM alimentacion_animal aa INNER JOIN animal a ON aa.id_animal = a.id_animal INNER JOIN inventariozoo iz ON aa.id_inventarioZoo = iz.id_inventarioZoo WHERE aa.activo = 1";
if ($idAnimalFiltro) {
    $sqlContarDiet .= " AND a.id_animal = ? ";
}
$stmtContarDiet = mysqli_prepare($conexion, $sqlContarDiet);
if ($stmtContarDiet) {
    if ($idAnimalFiltro) {
        mysqli_stmt_bind_param($stmtContarDiet, "i", $idAnimalFiltro);
    }
    mysqli_stmt_execute($stmtContarDiet);
    $resContarDiet = mysqli_stmt_get_result($stmtContarDiet);
    $filaContarDiet = mysqli_fetch_assoc($resContarDiet);
    $totalRegDiet = $filaContarDiet["total"] ?? 0;
    mysqli_stmt_close($stmtContarDiet);
} else {
    $totalRegDiet = 0;
}
$totalPagDiet = ceil($totalRegDiet / $regPorPagDiet);
if ($totalPagDiet < 1) {
    $totalPagDiet = 1;
}
if ($pagActualDiet > $totalPagDiet) {
    $pagActualDiet = $totalPagDiet;
}
$offsetDiet = ($pagActualDiet - 1) * $regPorPagDiet;

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
    INNER JOIN animal a ON aa.id_animal = a.id_animal
    INNER JOIN inventariozoo iz ON aa.id_inventarioZoo = iz.id_inventarioZoo
    WHERE aa.activo = 1
";

if ($idAnimalFiltro) {
    $sqlHorarios .= " AND a.id_animal = ? ";
}

$sqlHorarios .= "
    ORDER BY
        aa.hora ASC,
        a.nombre_animal ASC
    LIMIT $regPorPagDiet OFFSET $offsetDiet
";

$stmtHorarios = null;

if ($idAnimalFiltro) {
    $stmtHorarios = mysqli_prepare($conexion, $sqlHorarios);

    if ($stmtHorarios) {
        mysqli_stmt_bind_param($stmtHorarios, "i", $idAnimalFiltro);
        mysqli_stmt_execute($stmtHorarios);
        $resultadoHorarios = mysqli_stmt_get_result($stmtHorarios);
    } else {
        $resultadoHorarios = false;
        error_log(mysqli_error($conexion));
    }
} else {
    $resultadoHorarios = mysqli_query($conexion, $sqlHorarios);

    if (!$resultadoHorarios) {
        error_log(mysqli_error($conexion));
    }
}

if (isset($_GET["ajax"])) {
    $tabla = trim($_GET["tabla"] ?? "");
    $tbodyHtml = "";
    $pagHtml = "";

    if ($tabla === "historial") {
        ob_start();
        if (count($registrosMedicos) === 0) {
            echo '<tr class="fila-sin-registros"><td colspan="6"><div class="estado-vacio"><i class="bi bi-journal-plus"></i><h3>No hay antecedentes médicos</h3><p>Los diagnósticos, tratamientos y alergias aparecerán aquí.</p></div></td></tr>';
        } else {
            foreach ($registrosMedicos as $registroMedico) {
                $registroActivo =
                    $registroMedico["fecha_recuperacion"] === null;
                $esAlergia = $registroMedico["tipo"] === "Alergia";
                echo '<tr class="fila-historial-medico" data-animal="' .
                    (int) $registroMedico["id_animal"] .
                    '">';
                echo '<td><div class="animal-celda"><span class="animal-icono"><i class="fa-solid fa-paw"></i></span><div><strong>' .
                    htmlspecialchars($registroMedico["nombre_animal"]) .
                    "</strong><small>" .
                    htmlspecialchars($registroMedico["codigo_animal"]) .
                    "</small></div></div></td>";
                echo "<td>";
                if ($esAlergia) {
                    echo '<span class="etiqueta-condicion etiqueta-alergia"><i class="bi bi-exclamation-triangle-fill"></i> Alergia: ' .
                        htmlspecialchars($registroMedico["condicion"]) .
                        "</span>";
                } else {
                    echo '<span class="etiqueta-condicion etiqueta-enfermedad"><i class="bi bi-virus"></i> Enfermedad: ' .
                        htmlspecialchars($registroMedico["condicion"]) .
                        "</span>";
                }
                echo "</td>";
                echo "<td>" .
                    date(
                        "d/m/Y",
                        strtotime($registroMedico["fecha_diagnostico"]),
                    ) .
                    "</td>";
                echo '<td class="detalle-medico"><strong>Tratamiento:</strong> ' .
                    nl2br(
                        htmlspecialchars(
                            $registroMedico["tratamiento"] ?:
                                "Sin tratamiento indicado",
                        ),
                    ) .
                    '<br><small class="text-muted"><strong>Obs:</strong> ' .
                    nl2br(
                        htmlspecialchars(
                            $registroMedico["observaciones"] ?:
                                "Sin observaciones",
                        ),
                    ) .
                    "</small></td>";
                echo "<td>";
                if ($registroActivo) {
                    echo '<span class="estado-medico estado-activo">Activo</span>';
                } else {
                    echo '<span class="estado-medico estado-recuperado">Recuperado (' .
                        date(
                            "d/m/Y",
                            strtotime($registroMedico["fecha_recuperacion"]),
                        ) .
                        ")</span>";
                }
                echo "</td>";
                echo "<td>";
                echo '<button type="button" class="btn btn-outline-success btn-sm btn-editar-historial"
                        data-id="' .
                    (int) $registroMedico["id_historial"] .
                    '"
                        data-animal="' .
                    htmlspecialchars(
                        $registroMedico["nombre_animal"],
                        ENT_QUOTES,
                        "UTF-8",
                    ) .
                    '"
                        data-condicion="' .
                    htmlspecialchars(
                        $registroMedico["condicion"],
                        ENT_QUOTES,
                        "UTF-8",
                    ) .
                    '"
                        data-diagnostico="' .
                    htmlspecialchars(
                        $registroMedico["fecha_diagnostico"],
                        ENT_QUOTES,
                        "UTF-8",
                    ) .
                    '"
                        data-recuperacion="' .
                    htmlspecialchars(
                        $registroMedico["fecha_recuperacion"] ?? "",
                        ENT_QUOTES,
                        "UTF-8",
                    ) .
                    '"
                        data-tratamiento="' .
                    htmlspecialchars(
                        $registroMedico["tratamiento"] ?? "",
                        ENT_QUOTES,
                        "UTF-8",
                    ) .
                    '"
                        data-observaciones="' .
                    htmlspecialchars(
                        $registroMedico["observaciones"] ?? "",
                        ENT_QUOTES,
                        "UTF-8",
                    ) .
                    '"
                        title="Actualizar tratamiento y recuperación"><i class="bi bi-pencil-square"></i></button> ';
                echo '<form method="POST" class="d-inline form-eliminar-historial">' .
                    campoCsrfVeterinario() .
                    '
                        <input type="hidden" name="eliminar_historial" value="1">
                        <input type="hidden" name="id_historial" value="' .
                    (int) $registroMedico["id_historial"] .
                    '">
                        <button type="submit" name="btn_eliminar_historial" class="btn btn-outline-danger btn-sm" title="Eliminar historial médico">
                            <i class="bi bi-trash"></i>
                        </button>
                      </form>';
                echo "</td>";
                echo "</tr>";
            }
        }
        $tbodyHtml = ob_get_clean();

        ob_start();
        if ($totalPagHist > 1) {
            echo '<ul class="pagination justify-content-center">';
            echo '<li class="page-item ' .
                ($pagActualHist <= 1 ? "disabled" : "") .
                '">';
            echo '<a class="page-link" href="#" data-pagina="' .
                ($pagActualHist - 1) .
                '" data-tabla="historial">Anterior</a>';
            echo "</li>";
            for ($i = 1; $i <= $totalPagHist; $i++) {
                echo '<li class="page-item ' .
                    ($pagActualHist === $i ? "active" : "") .
                    '">';
                echo '<a class="page-link" href="#" data-pagina="' .
                    $i .
                    '" data-tabla="historial">' .
                    $i .
                    "</a>";
                echo "</li>";
            }
            echo '<li class="page-item ' .
                ($pagActualHist >= $totalPagHist ? "disabled" : "") .
                '">';
            echo '<a class="page-link" href="#" data-pagina="' .
                ($pagActualHist + 1) .
                '" data-tabla="historial">Siguiente</a>';
            echo "</li>";
            echo "</ul>";
        }
        $pagHtml = ob_get_clean();
    }

    if ($tabla === "vacunas") {
        ob_start();
        if (count($registrosVacunas) === 0) {
            echo '<tr><td colspan="6"><div class="estado-vacio"><i class="bi bi-shield-slash"></i><h3>No hay vacunas registradas</h3><p>El historial de vacunación aparecerá aquí.</p></div></td></tr>';
        } else {
            foreach ($registrosVacunas as $registroVacuna) {
                $diasVacuna = $registroVacuna["dias_restantes"];
                if ($diasVacuna === null) {
                    $claseVacuna = "vacuna-unica";
                    $textoVacuna = "Dosis única";
                } elseif ($diasVacuna < 0) {
                    $claseVacuna = "vacuna-vencida";
                    $textoVacuna =
                        "Vencida hace " . abs($diasVacuna) . " día(s)";
                } elseif ($diasVacuna === 0) {
                    $claseVacuna = "vacuna-hoy";
                    $textoVacuna = "Hoy";
                } elseif ($diasVacuna <= 15) {
                    $claseVacuna = "vacuna-proxima";
                    $textoVacuna = "En " . $diasVacuna . " día(s)";
                } else {
                    $claseVacuna = "vacuna-programada";
                    $textoVacuna = "Programada";
                }
                echo "<tr>";
                echo '<td><div class="animal-celda"><span class="animal-icono"><i class="fa-solid fa-paw"></i></span><div><strong>' .
                    htmlspecialchars($registroVacuna["nombre_animal"]) .
                    "</strong><small>" .
                    htmlspecialchars($registroVacuna["codigo_animal"]) .
                    "</small></div></div></td>";
                echo "<td><strong>" .
                    htmlspecialchars($registroVacuna["nombre_vacuna"]) .
                    "</strong></td>";
                echo "<td>" .
                    date(
                        "d/m/Y",
                        strtotime($registroVacuna["fecha_vacunacion"]),
                    ) .
                    "</td>";
                echo "<td>";
                if ($registroVacuna["fecha_proxima_vacuna"] !== null) {
                    echo "<strong>" .
                        date(
                            "d/m/Y",
                            strtotime($registroVacuna["fecha_proxima_vacuna"]),
                        ) .
                        "</strong>";
                }
                echo ' <span class="estado-vacuna ' .
                    $claseVacuna .
                    '">' .
                    $textoVacuna .
                    "</span>";
                echo "</td>";
                echo '<td class="detalle-medico">' .
                    nl2br(
                        htmlspecialchars(
                            $registroVacuna["observaciones"] ?:
                                "Sin observaciones",
                        ),
                    ) .
                    "</td>";
                echo "<td>";
                echo '<button type="button" class="btn btn-outline-success btn-sm btn-editar-vacuna"
                        data-id="' .
                    (int) $registroVacuna["id_vacunacion"] .
                    '"
                        data-animal="' .
                    htmlspecialchars(
                        $registroVacuna["nombre_animal"],
                        ENT_QUOTES,
                        "UTF-8",
                    ) .
                    '"
                        data-nombre="' .
                    htmlspecialchars(
                        $registroVacuna["nombre_vacuna"],
                        ENT_QUOTES,
                        "UTF-8",
                    ) .
                    '"
                        data-fecha="' .
                    htmlspecialchars(
                        $registroVacuna["fecha_vacunacion"],
                        ENT_QUOTES,
                        "UTF-8",
                    ) .
                    '"
                        data-proxima="' .
                    htmlspecialchars(
                        $registroVacuna["fecha_proxima_vacuna"] ?? "",
                        ENT_QUOTES,
                        "UTF-8",
                    ) .
                    '"
                        data-observaciones="' .
                    htmlspecialchars(
                        $registroVacuna["observaciones"] ?? "",
                        ENT_QUOTES,
                        "UTF-8",
                    ) .
                    '"
                        title="Editar vacunación"><i class="bi bi-pencil-square"></i></button> ';
                echo '<form method="POST" class="d-inline form-eliminar-vacuna">' .
                    campoCsrfVeterinario() .
                    '
                        <input type="hidden" name="eliminar_vacuna" value="1">
                        <input type="hidden" name="id_vacunacion" value="' .
                    (int) $registroVacuna["id_vacunacion"] .
                    '">
                        <button type="submit" name="btn_eliminar_vacuna" class="btn btn-outline-danger btn-sm" title="Eliminar vacunación">
                            <i class="bi bi-trash"></i>
                        </button>
                      </form>';
                echo "</td>";
                echo "</tr>";
            }
        }
        $tbodyHtml = ob_get_clean();

        ob_start();
        if ($totalPagVac > 1) {
            echo '<ul class="pagination justify-content-center">';
            echo '<li class="page-item ' .
                ($pagActualVac <= 1 ? "disabled" : "") .
                '">';
            echo '<a class="page-link" href="#" data-pagina="' .
                ($pagActualVac - 1) .
                '" data-tabla="vacunas">Anterior</a>';
            echo "</li>";
            for ($i = 1; $i <= $totalPagVac; $i++) {
                echo '<li class="page-item ' .
                    ($pagActualVac === $i ? "active" : "") .
                    '">';
                echo '<a class="page-link" href="#" data-pagina="' .
                    $i .
                    '" data-tabla="vacunas">' .
                    $i .
                    "</a>";
                echo "</li>";
            }
            echo '<li class="page-item ' .
                ($pagActualVac >= $totalPagVac ? "disabled" : "") .
                '">';
            echo '<a class="page-link" href="#" data-pagina="' .
                ($pagActualVac + 1) .
                '" data-tabla="vacunas">Siguiente</a>';
            echo "</li>";
            echo "</ul>";
        }
        $pagHtml = ob_get_clean();
    }

    if ($tabla === "dietas") {
        ob_start();
        if (!$resultadoHorarios) {
            echo '<tr><td colspan="5"><div class="estado-vacio"><i class="bi bi-database-x"></i><h3>No fue posible cargar los horarios</h3></div></td></tr>';
        } elseif (mysqli_num_rows($resultadoHorarios) === 0) {
            echo '<tr><td colspan="5"><div class="estado-vacio"><i class="bi bi-calendar2-plus"></i><h3>No hay dietas registradas</h3><p>Los horarios aparecerán aquí después de registrar la primera dieta.</p></div></td></tr>';
        } else {
            while ($horario = mysqli_fetch_assoc($resultadoHorarios)) {
                $unidad = match ($horario["unidad_medida"]) {
                    "Kilogramos" => "kg",
                    "Litros" => "L",
                    "Unidades" => "uds",
                    default => $horario["unidad_medida"] ?? "",
                };
                echo "<tr>";
                echo '<td><div class="animal-celda"><span class="animal-icono"><i class="fa-solid fa-paw"></i></span><div><strong>' .
                    htmlspecialchars($horario["nombre_animal"]) .
                    "</strong><small>" .
                    htmlspecialchars($horario["codigo_animal"]) .
                    "</small></div></div></td>";
                echo "<td>" .
                    htmlspecialchars($horario["nombre_producto"]) .
                    "</td>";
                echo '<td><span class="cantidad">' .
                    number_format(
                        (float) $horario["cantidad_recomendada"],
                        2,
                        ",",
                        ".",
                    ) .
                    " " .
                    htmlspecialchars($unidad) .
                    "</span></td>";
                echo "<td><strong>" .
                    date("h:i A", strtotime($horario["hora"])) .
                    "</strong></td>";
                echo "<td>";
                echo '<div class="d-flex gap-1 justify-content-center">';
                echo '<button type="button" class="btn btn-outline-success btn-sm btn-editar-dieta"
                        data-id="' .
                    (int) $horario["id_alimentacion"] .
                    '"
                        data-id-animal="' .
                    (int) $horario["id_animal"] .
                    '"
                        data-id-producto="' .
                    (int) $horario["id_inventarioZoo"] .
                    '"
                        data-cantidad="' .
                    (float) $horario["cantidad_recomendada"] .
                    '"
                        data-hora="' .
                    htmlspecialchars($horario["hora"], ENT_QUOTES, "UTF-8") .
                    '"
                        title="Editar dieta"><i class="bi bi-pencil-square"></i></button> ';
                echo '<button type="button" class="btn btn-outline-danger btn-sm px-2 py-1 btn-eliminar-dieta"
                        data-id="' .
                    (int) $horario["id_alimentacion"] .
                    '"
                        data-animal="' .
                    htmlspecialchars(
                        $horario["nombre_animal"],
                        ENT_QUOTES,
                        "UTF-8",
                    ) .
                    '"
                        title="Eliminar horario"><i class="bi bi-trash-fill"></i></button>';
                echo "</div>";
                echo "</td>";
                echo "</tr>";
            }
        }
        $tbodyHtml = ob_get_clean();

        ob_start();
        if ($totalPagDiet > 1) {
            echo '<ul class="pagination justify-content-center">';
            echo '<li class="page-item ' .
                ($pagActualDiet <= 1 ? "disabled" : "") .
                '">';
            echo '<a class="page-link" href="#" data-pagina="' .
                ($pagActualDiet - 1) .
                '" data-tabla="dietas">Anterior</a>';
            echo "</li>";
            for ($i = 1; $i <= $totalPagDiet; $i++) {
                echo '<li class="page-item ' .
                    ($pagActualDiet === $i ? "active" : "") .
                    '">';
                echo '<a class="page-link" href="#" data-pagina="' .
                    $i .
                    '" data-tabla="dietas">' .
                    $i .
                    "</a>";
                echo "</li>";
            }
            echo '<li class="page-item ' .
                ($pagActualDiet >= $totalPagDiet ? "disabled" : "") .
                '">';
            echo '<a class="page-link" href="#" data-pagina="' .
                ($pagActualDiet + 1) .
                '" data-tabla="dietas">Siguiente</a>';
            echo "</li>";
            echo "</ul>";
        }
        $pagHtml = ob_get_clean();
    }

    if ($tabla === "alertas") {
        ob_start();
        if (count($historialAlertas) === 0) {
            echo '<tr><td colspan="3"><div class="estado-vacio estado-vacio-compacto"><i class="bi bi-bell-slash"></i><h3>No hay alertas registradas</h3></div></td></tr>';
        } else {
            foreach ($historialAlertas as $alertaGuardada) {
                echo "<tr>";
                echo "<td><strong>" .
                    htmlspecialchars(
                        $alertaGuardada["nombre_animal"],
                        ENT_QUOTES,
                        "UTF-8",
                    ) .
                    '</strong><small class="d-block text-muted">' .
                    htmlspecialchars(
                        $alertaGuardada["codigo_animal"],
                        ENT_QUOTES,
                        "UTF-8",
                    ) .
                    "</small></td>";
                echo "<td>" .
                    htmlspecialchars(
                        $alertaGuardada["mensaje"],
                        ENT_QUOTES,
                        "UTF-8",
                    ) .
                    "</td>";
                echo "<td>" .
                    date(
                        "d/m/Y h:i A",
                        strtotime($alertaGuardada["fecha_generada"]),
                    ) .
                    "</td>";
                echo "</tr>";
            }
        }
        $tbodyHtml = ob_get_clean();

        ob_start();
        if ($totalPagAlert > 1) {
            echo '<ul class="pagination justify-content-center">';
            echo '<li class="page-item ' .
                ($pagActualAlert <= 1 ? "disabled" : "") .
                '">';
            echo '<a class="page-link" href="#" data-pagina="' .
                ($pagActualAlert - 1) .
                '" data-tabla="alertas">Anterior</a>';
            echo "</li>";
            for ($i = 1; $i <= $totalPagAlert; $i++) {
                echo '<li class="page-item ' .
                    ($pagActualAlert === $i ? "active" : "") .
                    '">';
                echo '<a class="page-link" href="#" data-pagina="' .
                    $i .
                    '" data-tabla="alertas">' .
                    $i .
                    "</a>";
                echo "</li>";
            }
            echo '<li class="page-item ' .
                ($pagActualAlert >= $totalPagAlert ? "disabled" : "") .
                '">';
            echo '<a class="page-link" href="#" data-pagina="' .
                ($pagActualAlert + 1) .
                '" data-tabla="alertas">Siguiente</a>';
            echo "</li>";
            echo "</ul>";
        }
        $pagHtml = ob_get_clean();
    }

    header("Content-Type: application/json");
    echo json_encode([
        "tbody" => $tbodyHtml,
        "paginacion" => $pagHtml,
    ]);
    exit();
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Panel de Veterinario - Salud integral</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
    <!-- Font Awesome para el icono de la patita -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display&family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="css/styleVet.css">
    <style></style>
    <link rel="stylesheet" href="css/crud-modern.css?v=1">
    <link rel="stylesheet" href="css/ecofauna-unified.css?v=1">
</head>

<body class="ecofauna-unified crud-modern">



    <nav class="navbar navbar-vet" aria-label="Navegación principal veterinaria">
        <div class="container-fluid px-4">
            <a class="navbar-brand" href="#">

                <img
                    src="img/LogoEcoFauna1.png"
                    alt="Logo EcoFauna"
                    class="logo-navbar-vet">

                <span>
                    EcoFauna
                    <small>Área médica y nutricional</small>
                </span>

            </a>
            <div class="usuario-nav">

                <a href="inventario.php" class="btn btn-success me-3">
                    <i class="bi bi-box-seam-fill me-1"></i>
                    Inventario
                </a>

                <div class="usuario-info">
                   
                 <a href="mi_perfil.php" class="usuario-info text-decoration-none">
                    <span class="usuario-avatar">
                        <i class="bi bi-person-fill"></i>
                    </span>
                    <div>
                        <small>Veterinario</small>
                        <strong><?= htmlspecialchars(
                                    $_SESSION["usuario"],
                                    ENT_QUOTES,
                                    "UTF-8",
                                ) ?></strong>

                    </div>
                </a>
                </div>


                <form action="logout.php" method="POST" class="m-0">
                    <?= campoCsrfSesion("logout") ?>

                    <button type="submit" class="btn btn-salir">
                        <i class="bi bi-box-arrow-right"></i>
                        Cerrar sesión
                    </button>
                </form>

            </div>
        </div>
    </nav>

    <header class="hero-vet">
        <div class="container">
            <span class="hero-etiqueta">
                <i class="bi bi-stars"></i>
                Salud y bienestar animal
            </span>

            <h1>Control médico y nutricional</h1>

            <p>
                Registra enfermedades, alergias, tratamientos, vacunaciones y
                planes de alimentación con trazabilidad por animal.
            </p>
        </div>
    </header>

    <main class="container contenido-principal">

        <?php if ($mensaje !== ""): ?>
            <div class="alerta alerta-<?= $tipoMensaje ?>">
                <i class="bi <?= $tipoMensaje === "success"
                                    ? "bi-check-circle-fill"
                                    : "bi-exclamation-triangle-fill" ?>">
                </i>

                <span>
                    <?= htmlspecialchars($mensaje, ENT_QUOTES, "UTF-8") ?>
                </span>
            </div>
        <?php endif; ?>

        <nav class="menu-modulos" aria-label="Módulos veterinarios">
            <a href="#resumen-medico"><i class="bi bi-speedometer2"></i> Resumen</a>
            <a href="#gestion-medica"><i class="bi bi-journal-medical"></i> Historial médico</a>
            <a href="#vacunacion"><i class="bi bi-shield-plus"></i> Vacunación</a>
            <a href="#alertas-vacunas"><i class="bi bi-bell-fill"></i> Alertas</a>
            <a href="#alimentacion"><i class="bi bi-basket2-fill"></i> Alimentación</a>
        </nav>

        <section id="resumen-medico" class="resumen-medico">
            <article class="indicador-medico indicador-enfermedad">
                <span class="indicador-icono"><i class="bi bi-heart-pulse-fill"></i></span>
                <div>
                    <small>En tratamiento</small>
                    <strong><?= $totalEnfermedadesActivas ?></strong>
                    <span>enfermedades activas</span>
                </div>
            </article>

            <article class="indicador-medico indicador-alergia">
                <span class="indicador-icono"><i class="bi bi-exclamation-diamond-fill"></i></span>
                <div>
                    <small>Precaución</small>
                    <strong><?= $totalAlergiasActivas ?></strong>
                    <span>alergias vigentes</span>
                </div>
            </article>

            <article class="indicador-medico indicador-vencida">
                <span class="indicador-icono"><i class="bi bi-calendar-x-fill"></i></span>
                <div>
                    <small>Atención inmediata</small>
                    <strong><?= $totalVacunasVencidas ?></strong>
                    <span>vacunas atrasadas</span>
                </div>
            </article>

            <article class="indicador-medico indicador-proxima">
                <span class="indicador-icono"><i class="bi bi-calendar2-check-fill"></i></span>
                <div>
                    <small>Próximos 30 días</small>
                    <strong><?= $totalVacunasProximas ?></strong>
                    <span>vacunas por aplicar</span>
                </div>
            </article>
        </section>

        <section id="gestion-medica" class="tarjeta-vet ancla-seccion">
            <div class="encabezado-tarjeta">
                <div class="titulo-seccion">
                    <span class="icono-seccion icono-medico">
                        <i class="bi bi-clipboard2-pulse-fill"></i>
                    </span>

                    <div>
                        <small>Control clínico</small>
                        <h2>Enfermedades, alergias y tratamientos</h2>
                    </div>
                </div>

                <span class="estado-modulo">
                    <i class="bi bi-circle-fill"></i>
                    Trazabilidad activa
                </span>
            </div>

            <div class="cuerpo-tarjeta">
                <div class="row g-4">
                    <div class="col-xl-4">
                        <div class="subtarjeta-medica h-100">
                            <div class="subtarjeta-titulo">
                                <span><i class="bi bi-plus-circle-fill"></i></span>
                                <div>
                                    <small>Catálogo médico</small>
                                    <h3>Nueva condición</h3>
                                </div>
                            </div>

                            <p class="texto-ayuda-medica">
                                Registra primero una enfermedad o alergia para poder
                                asignarla al historial de un animal.
                            </p>

                            <form method="POST">
                                <?= campoCsrfVeterinario() ?>
                                <div class="mb-3">
                                    <label class="form-label" for="nombre_condicion">Nombre</label>
                                    <div class="campo-con-icono">
                                        <i class="bi bi-virus2"></i>
                                        <input
                                            id="nombre_condicion"
                                            type="text"
                                            name="nombre_condicion"
                                            class="form-control"
                                            maxlength="100"
                                            placeholder="Ejemplo: Dermatitis"
                                            required>
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label" for="tipo_condicion">Tipo</label>
                                    <div class="campo-con-icono">
                                        <i class="bi bi-tags-fill"></i>
                                        <select
                                            id="tipo_condicion"
                                            name="tipo_condicion"
                                            class="form-select"
                                            required>
                                            <option value="">Selecciona el tipo</option>
                                            <option value="Enfermedad">Enfermedad</option>
                                            <option value="Alergia">Alergia</option>
                                        </select>
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label" for="descripcion_condicion">Descripción</label>
                                    <textarea
                                        id="descripcion_condicion"
                                        name="descripcion_condicion"
                                        class="form-control textarea-instrucciones"
                                        rows="4"
                                        maxlength="500"
                                        placeholder="Síntomas, causa o precauciones generales"></textarea>
                                </div>

                                <button
                                    type="submit"
                                    name="registrar_condicion"
                                    value="1"
                                    class="btn btn-registrar w-100">
                                    <i class="bi bi-plus-lg"></i>
                                    Guardar en catálogo
                                </button>
                            </form>
                        </div>
                    </div>

                    <div class="col-xl-8">
                        <div class="subtarjeta-medica h-100">
                            <div class="subtarjeta-titulo">
                                <span><i class="bi bi-file-earmark-medical-fill"></i></span>
                                <div>
                                    <small>Ficha del animal</small>
                                    <h3>Registrar diagnóstico o alergia</h3>
                                </div>
                            </div>

                            <?php if (
                                count($listaAnimalesMedicos) === 0 ||
                                count($listaCondicionesMedicas) === 0
                            ): ?>
                                <div class="estado-vacio estado-vacio-compacto">
                                    <i class="bi bi-clipboard-x"></i>
                                    <h3>Faltan datos médicos</h3>
                                    <p>Debe existir al menos un animal y una condición en el catálogo.</p>
                                </div>
                            <?php else: ?>
                                <form method="POST">
                                    <?= campoCsrfVeterinario() ?>
                                    <div class="row g-3">
                                        <div class="col-md-6">
                                            <label class="form-label" for="id_animal_medico">Animal</label>
                                            <div class="campo-con-icono">
                                                <i class="fa-solid fa-paw"></i>
                                                <select
                                                    id="id_animal_medico"
                                                    name="id_animal_medico"
                                                    class="form-select"
                                                    required>
                                                    <option value="">Selecciona un animal</option>
                                                    <?php foreach (
                                                        $listaAnimalesMedicos
                                                        as $animalMedico
                                                    ): ?>
                                                        <option value="<?= (int) $animalMedico["id_animal"] ?>">
                                                            <?= htmlspecialchars(
                                                                $animalMedico["nombre_animal"],
                                                                ENT_QUOTES,
                                                                "UTF-8",
                                                            ) ?>
                                                            — <?= htmlspecialchars(
                                                                    $animalMedico["codigo_animal"],
                                                                    ENT_QUOTES,
                                                                    "UTF-8",
                                                                ) ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>

                                        <div class="col-md-6">
                                            <label class="form-label" for="id_enfermedad">Enfermedad o alergia</label>
                                            <div class="campo-con-icono">
                                                <i class="bi bi-bandaid-fill"></i>
                                                <select
                                                    id="id_enfermedad"
                                                    name="id_enfermedad"
                                                    class="form-select"
                                                    required>
                                                    <option value="">Selecciona una condición</option>
                                                    <?php foreach (
                                                        $listaCondicionesMedicas
                                                        as $condicionMedica
                                                    ): ?>
                                                        <option
                                                            value="<?= (int) $condicionMedica["id_enfermedad"] ?>"
                                                            data-tipo="<?= htmlspecialchars(
                                                                            $condicionMedica["tipo"],
                                                                            ENT_QUOTES,
                                                                            "UTF-8",
                                                                        ) ?>">
                                                            [<?= htmlspecialchars(
                                                                    $condicionMedica["tipo"],
                                                                    ENT_QUOTES,
                                                                    "UTF-8",
                                                                ) ?>]
                                                            <?= htmlspecialchars(
                                                                $condicionMedica["nombre"],
                                                                ENT_QUOTES,
                                                                "UTF-8",
                                                            ) ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <small id="ayudaTipoCondicion" class="ayuda-campo">
                                                Las alergias activas también bloquearán alimentos incompatibles.
                                            </small>
                                        </div>

                                        <div class="col-md-6">
                                            <label class="form-label" for="fecha_diagnostico">Fecha del diagnóstico</label>
                                            <div class="campo-con-icono">
                                                <i class="bi bi-calendar-event-fill"></i>
                                                <input
                                                    id="fecha_diagnostico"
                                                    type="date"
                                                    name="fecha_diagnostico"
                                                    class="form-control"
                                                    max="<?= date("Y-m-d") ?>"
                                                    value="<?= date("Y-m-d") ?>"
                                                    required>
                                            </div>
                                        </div>

                                        <div class="col-md-6">
                                            <label class="form-label" for="fecha_recuperacion">Fecha de recuperación</label>
                                            <div class="campo-con-icono">
                                                <i class="bi bi-calendar2-check-fill"></i>
                                                <input
                                                    id="fecha_recuperacion"
                                                    type="date"
                                                    name="fecha_recuperacion"
                                                    max="<?= date("Y-m-d") ?>"
                                                    class="form-control">
                                            </div>
                                            <small class="ayuda-campo">Déjala vacía mientras siga en tratamiento o la alergia esté vigente.</small>
                                        </div>

                                        <div class="col-md-6">
                                            <label class="form-label" for="tratamiento">Tratamiento o cuidados</label>
                                            <textarea
                                                id="tratamiento"
                                                name="tratamiento"
                                                class="form-control textarea-instrucciones"
                                                rows="4"
                                                maxlength="500"
                                                placeholder="Medicamento, dosis, cuidados o restricciones"></textarea>
                                        </div>

                                        <div class="col-md-6">
                                            <label class="form-label" for="observaciones_medicas">Observaciones</label>
                                            <textarea
                                                id="observaciones_medicas"
                                                name="observaciones_medicas"
                                                class="form-control textarea-instrucciones"
                                                rows="4"
                                                maxlength="500"
                                                placeholder="Síntomas, evolución y detalles importantes"></textarea>
                                        </div>

                                        <div class="col-12">
                                            <button
                                                type="submit"
                                                name="registrar_historial"
                                                value="1"
                                                class="btn btn-registrar">
                                                <i class="bi bi-clipboard2-check-fill"></i>
                                                Registrar en historial médico
                                            </button>
                                        </div>
                                    </div>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section class="tarjeta-vet">
            <div class="encabezado-tarjeta">
                <div class="titulo-seccion">
                    <span class="icono-seccion icono-historial">
                        <i class="bi bi-journal-medical"></i>
                    </span>

                    <div>
                        <small>Trazabilidad</small>
                        <h2>Historial clínico de animales</h2>
                    </div>
                </div>

                <div class="filtro-animal">
                    <label for="filtroHistorialMedico">Filtrar:</label>
                    <select id="filtroHistorialMedico" class="form-select">
                        <option value="">Todos los animales</option>
                        <?php foreach (
                            $listaAnimalesMedicos
                            as $animalMedico
                        ): ?>
                            <option value="<?= (int) $animalMedico["id_animal"] ?>">
                                <?= htmlspecialchars(
                                    $animalMedico["nombre_animal"],
                                    ENT_QUOTES,
                                    "UTF-8",
                                ) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="cuerpo-tabla">
                <div class="table-responsive">
                    <table class="table tabla-horarios tabla-medica mb-0">
                        <thead>
                            <tr>
                                <th scope="col">Animal</th>
                                <th scope="col">Condición</th>
                                <th scope="col">Diagnóstico</th>
                                <th scope="col">Tratamiento y observaciones</th>
                                <th scope="col">Estado</th>
                                <th scope="col">Acciones</th>
                            </tr>
                        </thead>
                        <tbody id="cuerpoHistorialMedico">
                            <?php if (count($registrosMedicos) === 0): ?>
                                <tr class="fila-sin-registros">
                                    <td colspan="6">
                                        <div class="estado-vacio">
                                            <i class="bi bi-journal-plus"></i>
                                            <h3>No hay antecedentes médicos</h3>
                                            <p>Los diagnósticos, tratamientos y alergias aparecerán aquí.</p>
                                        </div>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach (
                                    $registrosMedicos
                                    as $registroMedico
                                ): ?>
                                    <?php
                                    $registroActivo =
                                        $registroMedico["fecha_recuperacion"] === null;
                                    $esAlergia =
                                        $registroMedico["tipo"] === "Alergia";
                                    ?>
                                    <tr
                                        class="fila-historial-medico"
                                        data-animal="<?= (int) $registroMedico["id_animal"] ?>">
                                        <td>
                                            <div class="animal-celda">
                                                <span class="animal-icono"><i class="fa-solid fa-paw"></i></span>
                                                <div>
                                                    <strong><?= htmlspecialchars(
                                                                $registroMedico["nombre_animal"],
                                                                ENT_QUOTES,
                                                                "UTF-8",
                                                            ) ?></strong>
                                                    <small><?= htmlspecialchars(
                                                                $registroMedico["codigo_animal"],
                                                                ENT_QUOTES,
                                                                "UTF-8",
                                                            ) ?></small>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="badge-condicion <?= $esAlergia
                                                                                ? "badge-alergia"
                                                                                : "badge-enfermedad" ?>">
                                                <i class="bi <?= $esAlergia
                                                                    ? "bi-exclamation-diamond-fill"
                                                                    : "bi-virus2" ?>"></i>
                                                <?= htmlspecialchars(
                                                    $registroMedico["tipo"],
                                                    ENT_QUOTES,
                                                    "UTF-8",
                                                ) ?>
                                            </span>
                                            <strong class="condicion-nombre">
                                                <?= htmlspecialchars(
                                                    $registroMedico["condicion"],
                                                    ENT_QUOTES,
                                                    "UTF-8",
                                                ) ?>
                                            </strong>
                                        </td>
                                        <td>
                                            <strong><?= date(
                                                        "d/m/Y",
                                                        strtotime(
                                                            $registroMedico["fecha_diagnostico"],
                                                        ),
                                                    ) ?></strong>
                                            <?php if (
                                                $registroMedico["fecha_recuperacion"] !== null
                                            ): ?>
                                                <small class="d-block text-muted mt-1">
                                                    Recuperación: <?= date(
                                                                        "d/m/Y",
                                                                        strtotime(
                                                                            $registroMedico["fecha_recuperacion"],
                                                                        ),
                                                                    ) ?>
                                                </small>
                                            <?php endif; ?>
                                        </td>
                                        <td class="detalle-medico">
                                            <strong>Tratamiento:</strong>
                                            <?= nl2br(
                                                htmlspecialchars(
                                                    $registroMedico["tratamiento"] ?:
                                                        "Sin tratamiento indicado",
                                                    ENT_QUOTES,
                                                    "UTF-8",
                                                ),
                                            ) ?>
                                            <small>
                                                <strong>Observaciones:</strong>
                                                <?= nl2br(
                                                    htmlspecialchars(
                                                        $registroMedico["observaciones"] ?:
                                                            "Sin observaciones",
                                                        ENT_QUOTES,
                                                        "UTF-8",
                                                    ),
                                                ) ?>
                                            </small>
                                        </td>
                                        <td>
                                            <span class="estado-clinico <?= $registroActivo
                                                                            ? ($esAlergia
                                                                                ? "estado-alergia"
                                                                                : "estado-tratamiento")
                                                                            : "estado-recuperado" ?>">
                                                <i class="bi <?= $registroActivo
                                                                    ? "bi-exclamation-circle-fill"
                                                                    : "bi-check-circle-fill" ?>"></i>
                                                <?= $registroActivo
                                                    ? ($esAlergia
                                                        ? "Vigente"
                                                        : "En tratamiento")
                                                    : "Recuperado" ?>
                                            </span>
                                        </td>
                                        <td>
                                            <button
                                                type="button"
                                                class="btn btn-outline-success btn-sm btn-editar-historial"
                                                data-id="<?= (int) $registroMedico["id_historial"] ?>"
                                                data-animal="<?= htmlspecialchars(
                                                                    $registroMedico["nombre_animal"],
                                                                    ENT_QUOTES,
                                                                    "UTF-8",
                                                                ) ?>"
                                                data-condicion="<?= htmlspecialchars(
                                                                    $registroMedico["condicion"],
                                                                    ENT_QUOTES,
                                                                    "UTF-8",
                                                                ) ?>"
                                                data-diagnostico="<?= htmlspecialchars(
                                                                        $registroMedico["fecha_diagnostico"],
                                                                        ENT_QUOTES,
                                                                        "UTF-8",
                                                                    ) ?>"
                                                data-recuperacion="<?= htmlspecialchars(
                                                                        $registroMedico["fecha_recuperacion"] ?? "",
                                                                        ENT_QUOTES,
                                                                        "UTF-8",
                                                                    ) ?>"
                                                data-tratamiento="<?= htmlspecialchars(
                                                                        $registroMedico["tratamiento"] ?? "",
                                                                        ENT_QUOTES,
                                                                        "UTF-8",
                                                                    ) ?>"
                                                data-observaciones="<?= htmlspecialchars(
                                                                        $registroMedico["observaciones"] ?? "",
                                                                        ENT_QUOTES,
                                                                        "UTF-8",
                                                                    ) ?>"
                                                aria-label="Actualizar historial de <?= htmlspecialchars(
                                                                                        $registroMedico["nombre_animal"],
                                                                                        ENT_QUOTES,
                                                                                        "UTF-8",
                                                                                    ) ?>"
                                                title="Actualizar tratamiento y recuperación">
                                                <i class="bi bi-pencil-square"></i>
                                            </button>
                                            <form method="POST" class="d-inline form-eliminar-historial">
                                                <?= campoCsrfVeterinario() ?>
                                                <input type="hidden" name="eliminar_historial" value="1">
                                                <input type="hidden" name="id_historial" value="<?= (int) $registroMedico["id_historial"] ?>">
                                                <button type="submit" name="btn_eliminar_historial" class="btn btn-outline-danger btn-sm" title="Eliminar historial médico">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <tr id="filaSinCoincidenciasMedicas" class="d-none">
                                    <td colspan="6">
                                        <div class="estado-vacio estado-vacio-compacto">
                                            <i class="bi bi-search"></i>
                                            <h3>Sin registros para este animal</h3>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>

                    <!-- Paginación de Historial Médico -->
                    <nav aria-label="Paginacion historial medico" class="mt-3" id="paginacion-historial">
                        <?php if ($totalPagHist > 1): ?>
                            <ul class="pagination justify-content-center">
                                <li class="page-item <?= $pagActualHist <= 1
                                                            ? "disabled"
                                                            : "" ?>">
                                    <a class="page-link" href="#" data-pagina="<?= $pagActualHist -
                                                                                    1 ?>" data-tabla="historial">Anterior</a>
                                </li>
                                <?php for (
                                    $i = 1;
                                    $i <= $totalPagHist;
                                    $i++
                                ): ?>
                                    <li class="page-item <?= $pagActualHist ===
                                                                $i
                                                                ? "active"
                                                                : "" ?>">
                                        <a class="page-link" href="#" data-pagina="<?= $i ?>" data-tabla="historial"><?= $i ?></a>
                                    </li>
                                <?php endfor; ?>
                                <li class="page-item <?= $pagActualHist >=
                                                            $totalPagHist
                                                            ? "disabled"
                                                            : "" ?>">
                                    <a class="page-link" href="#" data-pagina="<?= $pagActualHist +
                                                                                    1 ?>" data-tabla="historial">Siguiente</a>
                                </li>
                            </ul>
                        <?php endif; ?>
                    </nav>
                </div>
            </div>
        </section>

        <section id="vacunacion" class="tarjeta-vet ancla-seccion">
            <div class="encabezado-tarjeta">
                <div class="titulo-seccion">
                    <span class="icono-seccion icono-vacuna">
                        <i class="bi bi-shield-plus"></i>
                    </span>

                    <div>
                        <small>Prevención</small>
                        <h2>Vacunación animal</h2>
                    </div>
                </div>

                <span class="estado-modulo">
                    <i class="bi bi-circle-fill"></i>
                    Alertas automáticas
                </span>
            </div>

            <div class="cuerpo-tarjeta">
                <?php if (count($listaAnimalesMedicos) === 0): ?>
                    <div class="estado-vacio estado-vacio-compacto">
                        <i class="fa-solid fa-paw"></i>
                        <h3>No hay animales registrados</h3>
                    </div>
                <?php else: ?>
                    <form method="POST">
                        <?= campoCsrfVeterinario() ?>
                        <div class="row g-3 align-items-end">
                            <div class="col-lg-3 col-md-6">
                                <label class="form-label" for="id_animal_vacuna">Animal</label>
                                <div class="campo-con-icono">
                                    <i class="fa-solid fa-paw"></i>
                                    <select
                                        id="id_animal_vacuna"
                                        name="id_animal_vacuna"
                                        class="form-select"
                                        required>
                                        <option value="">Selecciona un animal</option>
                                        <?php foreach (
                                            $listaAnimalesMedicos
                                            as $animalMedico
                                        ): ?>
                                            <option value="<?= (int) $animalMedico["id_animal"] ?>">
                                                <?= htmlspecialchars(
                                                    $animalMedico["nombre_animal"],
                                                    ENT_QUOTES,
                                                    "UTF-8",
                                                ) ?>
                                                — <?= htmlspecialchars(
                                                        $animalMedico["codigo_animal"],
                                                        ENT_QUOTES,
                                                        "UTF-8",
                                                    ) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <div class="col-lg-3 col-md-6">
                                <label class="form-label" for="nombre_vacuna">Nombre de la vacuna</label>
                                <div class="campo-con-icono">
                                    <i class="bi bi-shield-fill-plus"></i>
                                    <input
                                        id="nombre_vacuna"
                                        type="text"
                                        name="nombre_vacuna"
                                        class="form-control"
                                        maxlength="100"
                                        placeholder="Ejemplo: Antirrábica"
                                        required>
                                </div>
                            </div>

                            <div class="col-lg-3 col-md-6">
                                <label class="form-label" for="fecha_vacunacion">Fecha de aplicación</label>
                                <div class="campo-con-icono">
                                    <i class="bi bi-calendar-check-fill"></i>
                                    <input
                                        id="fecha_vacunacion"
                                        type="date"
                                        name="fecha_vacunacion"
                                        class="form-control"
                                        value="<?= date("Y-m-d") ?>"
                                        required>
                                </div>
                            </div>

                            <div class="col-lg-3 col-md-6">
                                <label class="form-label" for="fecha_proxima_vacuna">Próxima dosis</label>
                                <div class="campo-con-icono">
                                    <i class="bi bi-calendar-plus-fill"></i>
                                    <input
                                        id="fecha_proxima_vacuna"
                                        type="date"
                                        name="fecha_proxima_vacuna"
                                        class="form-control">
                                </div>
                            </div>

                            <div class="col-lg-9">
                                <label class="form-label" for="observaciones_vacuna">Observaciones</label>
                                <textarea
                                    id="observaciones_vacuna"
                                    name="observaciones_vacuna"
                                    class="form-control textarea-instrucciones"
                                    rows="2"
                                    maxlength="500"
                                    placeholder="Lote, reacción, dosis o indicaciones"></textarea>
                            </div>

                            <div class="col-lg-3">
                                <button
                                    type="submit"
                                    name="registrar_vacuna"
                                    value="1"
                                    class="btn btn-registrar w-100">
                                    <i class="bi bi-shield-check"></i>
                                    Registrar vacuna
                                </button>
                            </div>
                        </div>
                    </form>
                <?php endif; ?>
            </div>

            <div class="cuerpo-tabla borde-superior">
                <div class="table-responsive">
                    <table class="table tabla-horarios tabla-vacunas mb-0">
                        <thead>
                            <tr>
                                <th scope="col">Animal</th>
                                <th scope="col">Vacuna</th>
                                <th scope="col">Aplicación</th>
                                <th scope="col">Próxima dosis</th>
                                <th scope="col">Observaciones</th>
                                <th scope="col">Acciones</th>
                            </tr>
                        </thead>
                        <tbody id="tbody-vacunas">
                            <?php if (count($registrosVacunas) === 0): ?>
                                <tr>
                                    <td colspan="6">
                                        <div class="estado-vacio">
                                            <i class="bi bi-shield-plus"></i>
                                            <h3>No hay vacunas registradas</h3>
                                            <p>El historial de vacunación aparecerá aquí.</p>
                                        </div>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach (
                                    $registrosVacunas
                                    as $registroVacuna
                                ): ?>
                                    <?php
                                    $diasVacuna =
                                        $registroVacuna["dias_restantes"] !==
                                        null
                                        ? (int) $registroVacuna["dias_restantes"]
                                        : null;

                                    if ($diasVacuna === null) {
                                        $claseVacuna = "vacuna-sin-fecha";
                                        $textoVacuna = "Sin próxima dosis";
                                    } elseif ($diasVacuna < 0) {
                                        $claseVacuna = "vacuna-vencida";
                                        $textoVacuna =
                                            "Atrasada " .
                                            abs($diasVacuna) .
                                            " día(s)";
                                    } elseif ($diasVacuna === 0) {
                                        $claseVacuna = "vacuna-hoy";
                                        $textoVacuna = "Aplicar hoy";
                                    } elseif ($diasVacuna <= 30) {
                                        $claseVacuna = "vacuna-proxima";
                                        $textoVacuna =
                                            "En " . $diasVacuna . " día(s)";
                                    } else {
                                        $claseVacuna = "vacuna-programada";
                                        $textoVacuna = "Programada";
                                    }
                                    ?>
                                    <tr>
                                        <td>
                                            <div class="animal-celda">
                                                <span class="animal-icono"><i class="fa-solid fa-paw"></i></span>
                                                <div>
                                                    <strong><?= htmlspecialchars(
                                                                $registroVacuna["nombre_animal"],
                                                                ENT_QUOTES,
                                                                "UTF-8",
                                                            ) ?></strong>
                                                    <small><?= htmlspecialchars(
                                                                $registroVacuna["codigo_animal"],
                                                                ENT_QUOTES,
                                                                "UTF-8",
                                                            ) ?></small>
                                                </div>
                                            </div>
                                        </td>
                                        <td><strong><?= htmlspecialchars(
                                                        $registroVacuna["nombre_vacuna"],
                                                        ENT_QUOTES,
                                                        "UTF-8",
                                                    ) ?></strong></td>
                                        <td><?= date(
                                                "d/m/Y",
                                                strtotime(
                                                    $registroVacuna["fecha_vacunacion"],
                                                ),
                                            ) ?></td>
                                        <td>
                                            <?php if (
                                                $registroVacuna["fecha_proxima_vacuna"] !== null
                                            ): ?>
                                                <strong><?= date(
                                                            "d/m/Y",
                                                            strtotime(
                                                                $registroVacuna["fecha_proxima_vacuna"],
                                                            ),
                                                        ) ?></strong>
                                            <?php endif; ?>
                                            <span class="estado-vacuna <?= $claseVacuna ?>"><?= $textoVacuna ?></span>
                                        </td>
                                        <td class="detalle-medico">
                                            <?= nl2br(
                                                htmlspecialchars(
                                                    $registroVacuna["observaciones"] ?:
                                                        "Sin observaciones",
                                                    ENT_QUOTES,
                                                    "UTF-8",
                                                ),
                                            ) ?>
                                        </td>
                                        <td>
                                            <button
                                                type="button"
                                                class="btn btn-outline-success btn-sm btn-editar-vacuna"
                                                data-id="<?= (int) $registroVacuna["id_vacunacion"] ?>"
                                                data-animal="<?= htmlspecialchars(
                                                                    $registroVacuna["nombre_animal"],
                                                                    ENT_QUOTES,
                                                                    "UTF-8",
                                                                ) ?>"
                                                data-nombre="<?= htmlspecialchars(
                                                                    $registroVacuna["nombre_vacuna"],
                                                                    ENT_QUOTES,
                                                                    "UTF-8",
                                                                ) ?>"
                                                data-fecha="<?= htmlspecialchars(
                                                                $registroVacuna["fecha_vacunacion"],
                                                                ENT_QUOTES,
                                                                "UTF-8",
                                                            ) ?>"
                                                data-proxima="<?= htmlspecialchars(
                                                                    $registroVacuna["fecha_proxima_vacuna"] ?? "",
                                                                    ENT_QUOTES,
                                                                    "UTF-8",
                                                                ) ?>"
                                                data-observaciones="<?= htmlspecialchars(
                                                                        $registroVacuna["observaciones"] ?? "",
                                                                        ENT_QUOTES,
                                                                        "UTF-8",
                                                                    ) ?>"
                                                aria-label="Editar vacuna de <?= htmlspecialchars(
                                                                                    $registroVacuna["nombre_animal"],
                                                                                    ENT_QUOTES,
                                                                                    "UTF-8",
                                                                                ) ?>"
                                                title="Editar vacunación">
                                                <i class="bi bi-pencil-square"></i>
                                            </button>
                                            <form method="POST" class="d-inline form-eliminar-vacuna">
                                                <?= campoCsrfVeterinario() ?>
                                                <input type="hidden" name="eliminar_vacuna" value="1">
                                                <input type="hidden" name="id_vacunacion" value="<?= (int) $registroVacuna["id_vacunacion"] ?>">
                                                <button type="submit" name="btn_eliminar_vacuna" class="btn btn-outline-danger btn-sm" title="Eliminar vacunación">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>

                    <!-- Paginación de Vacunación -->
                    <nav aria-label="Paginacion vacunacion" class="mt-3" id="paginacion-vacunacion">
                        <?php if ($totalPagVac > 1): ?>
                            <ul class="pagination justify-content-center">
                                <li class="page-item <?= $pagActualVac <= 1
                                                            ? "disabled"
                                                            : "" ?>">
                                    <a class="page-link" href="#" data-pagina="<?= $pagActualVac -
                                                                                    1 ?>" data-tabla="vacunas">Anterior</a>
                                </li>
                                <?php for ($i = 1; $i <= $totalPagVac; $i++): ?>
                                    <li class="page-item <?= $pagActualVac ===
                                                                $i
                                                                ? "active"
                                                                : "" ?>">
                                        <a class="page-link" href="#" data-pagina="<?= $i ?>" data-tabla="vacunas"><?= $i ?></a>
                                    </li>
                                <?php endfor; ?>
                                <li class="page-item <?= $pagActualVac >=
                                                            $totalPagVac
                                                            ? "disabled"
                                                            : "" ?>">
                                    <a class="page-link" href="#" data-pagina="<?= $pagActualVac +
                                                                                    1 ?>" data-tabla="vacunas">Siguiente</a>
                                </li>
                            </ul>
                        <?php endif; ?>
                    </nav>
                </div>
            </div>
        </section>

        <section id="alertas-vacunas" class="tarjeta-vet ancla-seccion">
            <div class="encabezado-tarjeta">
                <div class="titulo-seccion">
                    <span class="icono-seccion icono-alerta-vacuna">
                        <i class="bi bi-bell-fill"></i>
                    </span>

                    <div>
                        <small>Seguimiento preventivo</small>
                        <h2>Alertas de próximas vacunas</h2>
                    </div>
                </div>

                <span class="estado-modulo">
                    <i class="bi bi-arrow-repeat"></i>
                    Calculadas al abrir la página
                </span>
            </div>

            <div class="cuerpo-tarjeta">
                <?php if (count($alertasVacunas) === 0): ?>
                    <div class="estado-vacio estado-vacio-compacto">
                        <i class="bi bi-shield-check"></i>
                        <h3>No hay próximas dosis programadas</h3>
                        <p>Registra una fecha de próxima vacuna para activar las alertas.</p>
                    </div>
                <?php else: ?>
                    <div class="rejilla-alertas-vacuna">
                        <?php foreach (
                            array_slice($alertasVacunas, 0, 8)
                            as $alertaVacuna
                        ): ?>
                            <?php
                            $diasAlerta = (int) $alertaVacuna["dias_restantes"];

                            if ($diasAlerta < 0) {
                                $claseAlerta = "alerta-vencida";
                                $iconoAlerta = "bi-exclamation-octagon-fill";
                                $textoAlerta =
                                    "Atrasada por " .
                                    abs($diasAlerta) .
                                    " día(s)";
                            } elseif ($diasAlerta === 0) {
                                $claseAlerta = "alerta-hoy";
                                $iconoAlerta = "bi-alarm-fill";
                                $textoAlerta = "Debe aplicarse hoy";
                            } elseif ($diasAlerta <= 30) {
                                $claseAlerta = "alerta-proxima";
                                $iconoAlerta = "bi-calendar2-week-fill";
                                $textoAlerta =
                                    "Faltan " . $diasAlerta . " día(s)";
                            } else {
                                $claseAlerta = "alerta-programada";
                                $iconoAlerta = "bi-calendar2-check-fill";
                                $textoAlerta = "Programada";
                            }
                            ?>
                            <article class="alerta-vacuna-card <?= $claseAlerta ?>">
                                <span class="alerta-vacuna-icono"><i class="bi <?= $iconoAlerta ?>"></i></span>
                                <div>
                                    <small><?= htmlspecialchars(
                                                $alertaVacuna["codigo_animal"],
                                                ENT_QUOTES,
                                                "UTF-8",
                                            ) ?></small>
                                    <h3><?= htmlspecialchars(
                                            $alertaVacuna["nombre_animal"],
                                            ENT_QUOTES,
                                            "UTF-8",
                                        ) ?></h3>
                                    <strong><?= htmlspecialchars(
                                                $alertaVacuna["nombre_vacuna"],
                                                ENT_QUOTES,
                                                "UTF-8",
                                            ) ?></strong>
                                    <span><?= date(
                                                "d/m/Y",
                                                strtotime(
                                                    $alertaVacuna["fecha_proxima_vacuna"],
                                                ),
                                            ) ?> · <?= $textoAlerta ?></span>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <div class="historial-alertas">
                    <h3><i class="bi bi-clock-history"></i> Últimas alertas generadas</h3>

                    <?php if (count($historialAlertas) === 0): ?>
                        <p class="sin-alertas-guardadas">Aún no hay alertas guardadas en la base de datos.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table tabla-alertas mb-0">
                                <thead>
                                    <tr>
                                        <th scope="col">Animal</th>
                                        <th scope="col">Mensaje</th>
                                        <th scope="col">Generada</th>
                                    </tr>
                                </thead>
                                <tbody id="tbody-alertas">
                                    <?php foreach (
                                        $historialAlertas
                                        as $alertaGuardada
                                    ): ?>
                                        <tr>
                                            <td>
                                                <strong><?= htmlspecialchars(
                                                            $alertaGuardada["nombre_animal"],
                                                            ENT_QUOTES,
                                                            "UTF-8",
                                                        ) ?></strong>
                                                <small class="d-block text-muted"><?= htmlspecialchars(
                                                                                        $alertaGuardada["codigo_animal"],
                                                                                        ENT_QUOTES,
                                                                                        "UTF-8",
                                                                                    ) ?></small>
                                            </td>
                                            <td><?= htmlspecialchars(
                                                    $alertaGuardada["mensaje"],
                                                    ENT_QUOTES,
                                                    "UTF-8",
                                                ) ?></td>
                                            <td><?= date(
                                                    "d/m/Y h:i A",
                                                    strtotime(
                                                        $alertaGuardada["fecha_generada"],
                                                    ),
                                                ) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <!-- Paginación de Alertas Generadas -->
                        <nav aria-label="Paginacion alertas" class="mt-3" id="paginacion-alertas">
                            <?php if ($totalPagAlert > 1): ?>
                                <ul class="pagination justify-content-center">
                                    <li class="page-item <?= $pagActualAlert <=
                                                                1
                                                                ? "disabled"
                                                                : "" ?>">
                                        <a class="page-link" href="#" data-pagina="<?= $pagActualAlert -
                                                                                        1 ?>" data-tabla="alertas">Anterior</a>
                                    </li>
                                    <?php for (
                                        $i = 1;
                                        $i <= $totalPagAlert;
                                        $i++
                                    ): ?>
                                        <li class="page-item <?= $pagActualAlert ===
                                                                    $i
                                                                    ? "active"
                                                                    : "" ?>">
                                            <a class="page-link" href="#" data-pagina="<?= $i ?>" data-tabla="alertas"><?= $i ?></a>
                                        </li>
                                    <?php endfor; ?>
                                    <li class="page-item <?= $pagActualAlert >=
                                                                $totalPagAlert
                                                                ? "disabled"
                                                                : "" ?>">
                                        <a class="page-link" href="#" data-pagina="<?= $pagActualAlert +
                                                                                        1 ?>" data-tabla="alertas">Siguiente</a>
                                    </li>
                                </ul>
                            <?php endif; ?>
                        </nav>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <section id="alimentacion" class="tarjeta-vet ancla-seccion">
            <div class="encabezado-tarjeta">
                <div class="titulo-seccion">
                    <span class="icono-seccion">
                        <i class="bi bi-clipboard2-heart-fill"></i>
                    </span>

                    <div>
                        <small>Nuevo registro</small>
                        <h2>Registrar dieta</h2>
                    </div>
                </div>

                <span class="estado-modulo">
                    <i class="bi bi-circle-fill"></i>
                    Formulario activo
                </span>
            </div>

            <div class="cuerpo-tarjeta">

                <?php if (!$resultadoAnimales || !$resultadoAlimentos): ?>

                    <div class="estado-vacio">
                        <i class="bi bi-database-x"></i>
                        <h3>No fue posible cargar la información</h3>
                        <p>Revisa la conexión y los datos registrados en Animal y Producto.</p>
                    </div>

                <?php elseif (
                    mysqli_num_rows($resultadoAnimales) === 0 ||
                    mysqli_num_rows($resultadoAlimentos) === 0
                ): ?>

                    <div class="estado-vacio">
                        <i class="bi bi-clipboard-x"></i>
                        <h3>Faltan datos para registrar dietas</h3>
                        <p>
                            Debes tener al menos un animal y un producto de tipo
                            Alimento registrados.
                        </p>
                    </div>

                <?php else: ?>

                    <form method="POST">
                        <?= campoCsrfVeterinario() ?>
                        <div class="row g-4">

                            <div class="col-lg-6">
                                <label class="form-label" for="id_animal">Animal</label>

                                <div class="campo-con-icono">
                                    <i class="fa-solid fa-paw"></i>

                                    <select
                                        id="id_animal"
                                        name="id_animal"
                                        class="form-select"
                                        required>

                                        <option value="">Selecciona un animal</option>

                                        <?php foreach (
                                            $listaAnimales
                                            as $animal
                                        ): ?>
                                            <option value="<?= (int) $animal["id_animal"] ?>"
                                                data-codigo="<?= htmlspecialchars(
                                                                    $animal["codigo_animal"],
                                                                    ENT_QUOTES,
                                                                    "UTF-8",
                                                                ) ?>"
                                                data-altura="<?= number_format(
                                                                    (float) $animal["altura"],
                                                                    2,
                                                                    ",",
                                                                    ".",
                                                                ) ?>"
                                                data-peso="<?= number_format(
                                                                (float) $animal["peso"],
                                                                2,
                                                                ",",
                                                                ".",
                                                            ) ?>"
                                                data-alergias="<?= htmlspecialchars(
                                                                    $animal["alergias"] ??
                                                                        "Ninguna",
                                                                    ENT_QUOTES,
                                                                    "UTF-8",
                                                                ) ?>"
                                                data-estado="<?= htmlspecialchars(
                                                                    $animal["estado_salud"] ??
                                                                        "Activo",
                                                                    ENT_QUOTES,
                                                                    "UTF-8",
                                                                ) ?>">
                                                <?= htmlspecialchars(
                                                    $animal["nombre_animal"],
                                                    ENT_QUOTES,
                                                    "UTF-8",
                                                ) ?>
                                                — <?= htmlspecialchars(
                                                        $animal["codigo_animal"],
                                                        ENT_QUOTES,
                                                        "UTF-8",
                                                    ) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <!-- Ficha Médica Dinámica del Animal -->
                                <div id="ficha_animal_card" class="mt-3 p-3 border rounded shadow-sm bg-light ficha-animal-card d-none">
                                    <h6 class="mb-2 text-success fw-bold"><i class="bi bi-heart-pulse-fill me-1"></i> Ficha Médica del Animal</h6>
                                    <div class="row g-2 text-muted ficha-animal-resumen">
                                        <div class="col-6"><strong>Código:</strong> <span id="fa_codigo">-</span></div>
                                        <div class="col-6"><strong>Altura:</strong> <span id="fa_altura">-</span> m</div>
                                        <div class="col-6"><strong>Peso:</strong> <span id="fa_peso">-</span> kg</div>
                                        <div class="col-12 mt-2">
                                            <strong>Estado de salud:</strong>
                                            <span id="fa_estado" class="badge bg-secondary">-</span>
                                        </div>
                                        <div class="col-12 mt-2">
                                            <strong>Alergias:</strong>
                                            <div id="fa_alergias" class="alert py-1 px-2 m-0 ficha-animal-alergias">-</div>
                                        </div>
                                        <div class="col-12 mt-3 pt-3 border-top">
                                            <strong class="text-success d-block mb-2"><i class="bi bi-journal-medical me-1"></i> Historial Clínico Reciente:</strong>
                                            <div id="fa_historial_clinico" class="ficha-animal-historial">
                                                <!-- Historial clínico dinámico -->
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="col-lg-6">
                                <label class="form-label" for="id_producto">Alimento</label>

                                <div class="campo-con-icono">
                                    <i class="bi bi-basket2-fill"></i>

                                    <select
                                        id="id_producto"
                                        name="id_producto"
                                        class="form-select"
                                        required>

                                        <option value="">Selecciona un alimento</option>

                                        <?php foreach (
                                            $listaAlimentos
                                            as $alimento
                                        ): ?>
                                            <?php $unidad = match ($alimento["unidad_medida"]) {
                                                "Kilogramos" => "kg",
                                                "Litros" => "L",
                                                "Unidades" => "uds",
                                                default => $alimento["unidad_medida"] ?? "",
                                            }; ?>
                                            <option value="<?= (int) $alimento["id_Producto"] ?>" data-unidad="<?= htmlspecialchars(
                                                                    $unidad,
                                                                    ENT_QUOTES,
                                                                    "UTF-8",
                                                                ) ?>">
                                                <?= htmlspecialchars(
                                                    $alimento["nombre_producto"],
                                                    ENT_QUOTES,
                                                    "UTF-8",
                                                ) ?>
                                                <?php if (
                                                    $alimento["fecha_caducidad"] !== null &&
                                                    $alimento["fecha_caducidad"] < date("Y-m-d")
                                                ): ?>
                                                    (VENCIDO)
                                                <?php endif; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label" for="cantidad_recomendada">
                                    Cantidad recomendada
                                </label>

                                <div class="input-group grupo-cantidad-recomendada">
                                    <span class="input-group-text fw-bold unidad-medida-badge" id="unidad_badge">
                                        -
                                    </span>
                                    <input
                                        id="cantidad_recomendada"
                                        type="number"
                                        name="cantidad_recomendada"
                                        class="form-control border-start-0 cantidad-recomendada-input"
                                        min="0.01"
                                        step="0.01"
                                        placeholder="Ejemplo: 5.50"
                                        required>
                                </div>

                            </div>

                            <div class="col-md-6">
                                <label class="form-label" for="hora">Hora de alimentación</label>

                                <div class="campo-con-icono">
                                    <i class="bi bi-clock-fill"></i>

                                    <input
                                        id="hora"
                                        type="time"
                                        name="hora"
                                        class="form-control"
                                        required>
                                </div>
                            </div>

                            <div class="col-12">
                                <button
                                    type="submit"
                                    class="btn btn-registrar"
                                    name="registrar_dieta"
                                    value="1">

                                    <i class="bi bi-check2-circle"></i>
                                    Registrar dieta y horario
                                </button>
                            </div>
                        </div>
                    </form>

                <?php endif; ?>

            </div>
        </section>

        <section class="tarjeta-vet">
            <div class="encabezado-tarjeta">
                <div class="titulo-seccion">
                    <span class="icono-seccion icono-horarios">
                        <i class="bi bi-calendar2-heart-fill"></i>
                    </span>

                    <div>
                        <small>Consulta</small>
                        <h2>Horarios registrados</h2>
                    </div>
                </div>

                <form method="GET" class="filtro-animal">
                    <label for="animal">Filtrar:</label>

                    <select
                        id="animal"
                        name="animal"
                        class="form-select">

                        <option value="">Todos los animales</option>

                        <?php $resultadoAnimalesFiltro = mysqli_query(
                            $conexion,
                            $sqlAnimales,
                        ); ?>

                        <?php if ($resultadoAnimalesFiltro): ?>
                            <?php while (
                                $animalFiltro = mysqli_fetch_assoc(
                                    $resultadoAnimalesFiltro,
                                )
                            ): ?>
                                <option
                                    value="<?= (int) $animalFiltro["id_animal"] ?>"
                                    <?= $idAnimalFiltro ===
                                        (int) $animalFiltro["id_animal"]
                                        ? "selected"
                                        : "" ?>>

                                    <?= htmlspecialchars(
                                        $animalFiltro["nombre_animal"],
                                        ENT_QUOTES,
                                        "UTF-8",
                                    ) ?>
                                </option>
                            <?php endwhile; ?>
                        <?php endif; ?>
                    </select>
                </form>
            </div>

            <div class="cuerpo-tabla">
                <div class="table-responsive">
                    <table class="table tabla-horarios mb-0">
                        <thead>
                            <tr>
                                <th scope="col">Animal</th>
                                <th scope="col">Alimento</th>
                                <th scope="col">Cantidad</th>
                                <th scope="col">Hora</th>
                                <th scope="col">Acciones</th>
                            </tr>
                        </thead>

                        <tbody id="tbody-dietas">

                            <?php if (!$resultadoHorarios): ?>

                                <tr>
                                    <td colspan="5">
                                        <div class="estado-vacio">
                                            <i class="bi bi-database-x"></i>
                                            <h3>No fue posible cargar los horarios</h3>
                                        </div>
                                    </td>
                                </tr>

                            <?php elseif (
                                mysqli_num_rows($resultadoHorarios) === 0
                            ): ?>

                                <tr>
                                    <td colspan="5">
                                        <div class="estado-vacio">
                                            <i class="bi bi-calendar2-plus"></i>
                                            <h3>No hay dietas registradas</h3>
                                            <p>
                                                Los horarios aparecerán aquí después
                                                de registrar la primera dieta.
                                            </p>
                                        </div>
                                    </td>
                                </tr>

                            <?php else: ?>

                                <?php while (
                                    $horario = mysqli_fetch_assoc(
                                        $resultadoHorarios,
                                    )
                                ): ?>
                                    <tr>
                                        <td>
                                            <div class="animal-celda">
                                                <span class="animal-icono">
                                                    <i class="fa-solid fa-paw"></i>
                                                </span>

                                                <div>
                                                    <strong>
                                                        <?= htmlspecialchars(
                                                            $horario["nombre_animal"],
                                                            ENT_QUOTES,
                                                            "UTF-8",
                                                        ) ?>
                                                    </strong>

                                                    <small>
                                                        <?= htmlspecialchars(
                                                            $horario["codigo_animal"],
                                                            ENT_QUOTES,
                                                            "UTF-8",
                                                        ) ?>
                                                    </small>
                                                </div>
                                            </div>
                                        </td>

                                        <td>
                                            <?= htmlspecialchars(
                                                $horario["nombre_producto"],
                                                ENT_QUOTES,
                                                "UTF-8",
                                            ) ?>
                                        </td>

                                        <td>
                                            <span class="cantidad">
                                                <?php
                                                $unidad = match ($horario["unidad_medida"]) {
                                                    "Kilogramos" => "kg",
                                                    "Litros" => "L",
                                                    "Unidades" => "uds",
                                                    default => $horario["unidad_medida"] ?? "",
                                                };
                                                echo number_format(
                                                    (float) $horario["cantidad_recomendada"],
                                                    2,
                                                    ",",
                                                    ".",
                                                ) .
                                                    " " .
                                                    htmlspecialchars(
                                                        $unidad,
                                                        ENT_QUOTES,
                                                        "UTF-8",
                                                    );
                                                ?>
                                            </span>
                                        </td>

                                        <td>
                                            <strong>
                                                <?= date(
                                                    "h:i A",
                                                    strtotime($horario["hora"]),
                                                ) ?>
                                            </strong>
                                        </td>

                                        <td>
                                            <div class="d-flex gap-1 justify-content-center">
                                                <button
                                                    type="button"
                                                    class="btn btn-outline-success btn-sm btn-editar-dieta"
                                                    data-id="<?= (int) $horario["id_alimentacion"] ?>"
                                                    data-id-animal="<?= (int) $horario["id_animal"] ?>"
                                                    data-id-producto="<?= (int) $horario["id_inventarioZoo"] ?>"
                                                    data-cantidad="<?= (float) $horario["cantidad_recomendada"] ?>"
                                                    data-hora="<?= htmlspecialchars(
                                                                    $horario["hora"],
                                                                    ENT_QUOTES,
                                                                    "UTF-8",
                                                                ) ?>"
                                                    title="Editar dieta">
                                                    <i class="bi bi-pencil-square"></i>
                                                </button>
                                                <button
                                                    type="button"
                                                    class="btn btn-outline-danger btn-sm px-2 py-1 btn-eliminar-dieta"
                                                    data-id="<?= (int) $horario["id_alimentacion"] ?>"
                                                    data-animal="<?= htmlspecialchars(
                                                                        $horario["nombre_animal"],
                                                                        ENT_QUOTES,
                                                                        "UTF-8",
                                                                    ) ?>"
                                                    aria-label="Eliminar horario de <?= htmlspecialchars(
                                                                                        $horario["nombre_animal"],
                                                                                        ENT_QUOTES,
                                                                                        "UTF-8",
                                                                                    ) ?>"
                                                    title="Eliminar horario">
                                                    <i class="bi bi-trash-fill"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>

                            <?php endif; ?>

                        </tbody>
                    </table>

                    <!-- Paginación de Dietas -->
                    <nav aria-label="Paginacion dietas" class="mt-3" id="paginacion-dietas">
                        <?php if ($totalPagDiet > 1): ?>
                            <ul class="pagination justify-content-center">
                                <li class="page-item <?= $pagActualDiet <= 1
                                                            ? "disabled"
                                                            : "" ?>">
                                    <a class="page-link" href="#" data-pagina="<?= $pagActualDiet -
                                                                                    1 ?>" data-tabla="dietas">Anterior</a>
                                </li>
                                <?php for (
                                    $i = 1;
                                    $i <= $totalPagDiet;
                                    $i++
                                ): ?>
                                    <li class="page-item <?= $pagActualDiet ===
                                                                $i
                                                                ? "active"
                                                                : "" ?>">
                                        <a class="page-link" href="#" data-pagina="<?= $i ?>" data-tabla="dietas"><?= $i ?></a>
                                    </li>
                                <?php endfor; ?>
                                <li class="page-item <?= $pagActualDiet >=
                                                            $totalPagDiet
                                                            ? "disabled"
                                                            : "" ?>">
                                    <a class="page-link" href="#" data-pagina="<?= $pagActualDiet +
                                                                                    1 ?>" data-tabla="dietas">Siguiente</a>
                                </li>
                            </ul>
                        <?php endif; ?>
                    </nav>

                </div>
            </div>
        </section>
    </main>

    <?php if ($mensaje !== ""): ?>
        <!-- Ventana automática de resultado EcoFauna -->
        <div
            class="modal fade modal-resultado"
            id="modalResultado"
            tabindex="-1"
            aria-labelledby="tituloModalResultado"
            aria-hidden="true">

            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-resultado-cabecera <?= $tipoMensaje ===
                                                                "success"
                                                                ? "resultado-exitoso"
                                                                : "resultado-error" ?>">
                        <span class="modal-resultado-icono">
                            <i class="bi <?= $tipoMensaje === "success"
                                                ? "bi-check-circle-fill"
                                                : "bi-exclamation-triangle-fill" ?>"></i>
                        </span>

                        <div>
                            <small>EcoFauna informa</small>
                            <h5 id="tituloModalResultado">
                                <?= $tipoMensaje === "success"
                                    ? "¡Proceso realizado!"
                                    : "No se pudo completar" ?>
                            </h5>
                        </div>
                    </div>

                    <div class="modal-body modal-resultado-cuerpo">
                        <p><?= htmlspecialchars(
                                $mensaje,
                                ENT_QUOTES,
                                "UTF-8",
                            ) ?></p>
                    </div>

                    <div class="modal-footer modal-resultado-pie">
                        <button
                            type="button"
                            class="btn btn-entendido"
                            data-bs-dismiss="modal">
                            <i class="bi bi-check2"></i>
                            Entendido
                        </button>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Modal para actualizar un registro médico -->
    <div
        class="modal fade modal-confirmacion modal-medico"
        id="modalEditarHistorial"
        tabindex="-1"
        aria-hidden="true">

        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <form method="POST" action="veterinario.php#gestion-medica">
                    <?= campoCsrfVeterinario() ?>
                    <input type="hidden" name="id_historial" id="editarIdHistorial">

                    <div class="modal-header">
                        <h5 class="modal-title">
                            <i class="bi bi-clipboard2-pulse-fill"></i>
                            Actualizar historial médico
                        </h5>
                        <button
                            type="button"
                            class="btn-close btn-close-white"
                            data-bs-dismiss="modal"
                            aria-label="Cerrar"></button>
                    </div>

                    <div class="modal-body">
                        <div class="resumen-modal-medico mb-4">
                            <span><i class="fa-solid fa-paw"></i></span>
                            <div>
                                <small>Registro clínico</small>
                                <strong id="editarResumenHistorial">-</strong>
                            </div>
                        </div>

                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label" for="editarFechaDiagnostico">Fecha del diagnóstico</label>
                                <input
                                    id="editarFechaDiagnostico"
                                    type="date"
                                    name="fecha_diagnostico_editar"
                                    class="form-control"
                                    max="<?= date("Y-m-d") ?>"
                                    required>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label" for="editarFechaRecuperacion">Fecha de recuperación</label>
                                <input
                                    id="editarFechaRecuperacion"
                                    type="date"
                                    name="fecha_recuperacion_editar"
                                    max="<?= date("Y-m-d") ?>"
                                    class="form-control">
                                <small class="ayuda-campo">Vacía significa que continúa activo o en tratamiento.</small>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label" for="editarTratamiento">Tratamiento o cuidados</label>
                                <textarea
                                    id="editarTratamiento"
                                    name="tratamiento_editar"
                                    class="form-control textarea-instrucciones"
                                    rows="5"
                                    maxlength="500"></textarea>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label" for="editarObservaciones">Observaciones</label>
                                <textarea
                                    id="editarObservaciones"
                                    name="observaciones_editar"
                                    class="form-control textarea-instrucciones"
                                    rows="5"
                                    maxlength="500"></textarea>
                            </div>
                        </div>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            Cancelar
                        </button>
                        <button
                            type="submit"
                            name="actualizar_historial"
                            value="1"
                            class="btn btn-success">
                            <i class="bi bi-check2-circle"></i>
                            Guardar cambios
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal para actualizar una vacunación -->
    <div
        class="modal fade modal-confirmacion modal-medico"
        id="modalEditarVacuna"
        tabindex="-1"
        aria-hidden="true">

        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <form method="POST">
                    <?= campoCsrfVeterinario() ?>
                    <input type="hidden" name="id_vacunacion" id="editarIdVacunacion">

                    <div class="modal-header">
                        <h5 class="modal-title">
                            <i class="bi bi-shield-plus"></i>
                            Actualizar vacunación
                        </h5>
                        <button
                            type="button"
                            class="btn-close btn-close-white"
                            data-bs-dismiss="modal"
                            aria-label="Cerrar"></button>
                    </div>

                    <div class="modal-body">
                        <div class="resumen-modal-medico mb-4">
                            <span><i class="fa-solid fa-paw"></i></span>
                            <div>
                                <small>Animal</small>
                                <strong id="editarAnimalVacuna">-</strong>
                            </div>
                        </div>

                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label" for="editarNombreVacuna">Nombre de la vacuna</label>
                                <input
                                    id="editarNombreVacuna"
                                    type="text"
                                    name="nombre_vacuna_editar"
                                    class="form-control"
                                    maxlength="100"
                                    required>
                            </div>

                            <div class="col-md-3">
                                <label class="form-label" for="editarFechaVacuna">Fecha aplicada</label>
                                <input
                                    id="editarFechaVacuna"
                                    type="date"
                                    name="fecha_vacunacion_editar"
                                    class="form-control"
                                    max="<?= date("Y-m-d") ?>"
                                    required>
                            </div>

                            <div class="col-md-3">
                                <label class="form-label" for="editarProximaVacuna">Próxima dosis</label>
                                <input
                                    id="editarProximaVacuna"
                                    type="date"
                                    name="fecha_proxima_vacuna_editar"
                                    class="form-control">
                            </div>

                            <div class="col-12">
                                <label class="form-label" for="editarObservacionesVacuna">Observaciones</label>
                                <textarea
                                    id="editarObservacionesVacuna"
                                    name="observaciones_vacuna_editar"
                                    class="form-control textarea-instrucciones"
                                    rows="4"
                                    maxlength="500"></textarea>
                            </div>
                        </div>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            Cancelar
                        </button>
                        <button
                            type="submit"
                            name="actualizar_vacuna"
                            value="1"
                            class="btn btn-success">
                            <i class="bi bi-check2-circle"></i>
                            Guardar cambios
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal para eliminar dieta -->

    <div
        class="modal fade modal-confirmacion"
        id="modalEliminarDieta"
        tabindex="-1"
        aria-hidden="true">

        <div class="modal-dialog modal-dialog-centered">

            <div class="modal-content">

                <form method="POST">
                    <?= campoCsrfVeterinario() ?>

                    <input
                        type="hidden"
                        name="id_alimentacion"
                        id="idDietaEliminar">

                    <div class="modal-header">

                        <h5 class="modal-title">

                            <i class="bi bi-exclamation-triangle-fill"></i>
                            Eliminar horario

                        </h5>

                        <button
                            type="button"
                            class="btn-close btn-close-white"
                            data-bs-dismiss="modal"
                            aria-label="Cerrar">
                        </button>

                    </div>

                    <div class="modal-body">

                        <p id="mensajeEliminarDieta" class="mb-2"></p>

                        <small class="text-muted">

                            Esta acción eliminará el horario de alimentación
                            seleccionado.

                        </small>

                    </div>

                    <div class="modal-footer">

                        <button
                            type="button"
                            class="btn btn-secondary"
                            data-bs-dismiss="modal">

                            No, regresar

                        </button>

                        <button
                            type="submit"
                            name="eliminar_dieta"
                            value="1"
                            class="btn btn-danger">

                            <i class="bi bi-trash-fill"></i>
                            Sí, eliminar

                        </button>

                    </div>

                </form>

            </div>

        </div>

    </div>

    <!-- Modal para actualizar una dieta -->
    <div
        class="modal fade modal-confirmacion modal-medico"
        id="modalEditarDieta"
        tabindex="-1"
        aria-hidden="true">

        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <form method="POST">
                    <?= campoCsrfVeterinario() ?>
                    <input type="hidden" name="id_alimentacion" id="editarIdAlimentacion">

                    <div class="modal-header">
                        <h5 class="modal-title text-white">
                            <i class="bi bi-calendar2-range"></i>
                            Actualizar Dieta
                        </h5>
                        <button
                            type="button"
                            class="btn-close btn-close-white"
                            data-bs-dismiss="modal"
                            aria-label="Cerrar"></button>
                    </div>

                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="mb-3 col-md-6">
                                <label class="form-label" for="editarAnimalDieta">Animal</label>
                                <select name="id_animal" id="editarAnimalDieta" class="form-select" required>
                                    <?php foreach (
                                        $listaAnimales
                                        as $animal
                                    ): ?>
                                        <option value="<?= (int) $animal["id_animal"] ?>">
                                            <?= htmlspecialchars(
                                                $animal["nombre_animal"],
                                                ENT_QUOTES,
                                                "UTF-8",
                                            ) ?> — <?= htmlspecialchars(
                                                        $animal["codigo_animal"],
                                                        ENT_QUOTES,
                                                        "UTF-8",
                                                    ) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="mb-3 col-md-6">
                                <label class="form-label" for="editarProductoDieta">Alimento / Insumo</label>
                                <select name="id_producto" id="editarProductoDieta" class="form-select" required>
                                    <?php foreach (
                                        $listaAlimentos
                                        as $alimento
                                    ): ?>
                                        <option value="<?= (int) $alimento["id_Producto"] ?>">
                                            <?= htmlspecialchars(
                                                $alimento["nombre_producto"],
                                                ENT_QUOTES,
                                                "UTF-8",
                                            ) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="mb-3 col-md-6">
                                <label class="form-label" for="editarCantidadDiet">Cantidad Recomendada</label>
                                <input
                                    id="editarCantidadDiet"
                                    type="number"
                                    step="0.01"
                                    name="cantidad_recomendada"
                                    class="form-control"
                                    required>
                            </div>

                            <div class="mb-3 col-md-6">
                                <label class="form-label" for="editarHoraDiet">Hora de alimentación</label>
                                <input
                                    id="editarHoraDiet"
                                    type="time"
                                    name="hora"
                                    class="form-control"
                                    required>
                            </div>
                        </div>
                    </div>

                    <div class="modal-footer">
                        <button
                            type="button"
                            class="btn btn-secondary"
                            data-bs-dismiss="modal">
                            Cancelar
                        </button>
                        <button
                            type="submit"
                            name="actualizar_dieta"
                            class="btn btn-success">
                            <i class="bi bi-check2-circle"></i>
                            Guardar Cambios
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <footer class="footer-vet">
        <div class="container">
            <span>
                <i class="bi bi-heart-pulse-fill"></i>
                EcoFauna — Área veterinaria
            </span>

            <small>Historial clínico, vacunas, alertas y alimentación animal</small>
        </div>
    </footer>

    <div
        id="configVeterinario"
        hidden
        data-historiales="<?= htmlspecialchars(
                                json_encode(
                                    $historiales,
                                    JSON_UNESCAPED_UNICODE |
                                        JSON_HEX_TAG |
                                        JSON_HEX_APOS |
                                        JSON_HEX_QUOT |
                                        JSON_HEX_AMP,
                                ),
                                ENT_QUOTES,
                                "UTF-8",
                            ) ?>"
        data-pag-hist="<?= (int) $pagActualHist ?>"
        data-pag-vac="<?= (int) $pagActualVac ?>"
        data-pag-alert="<?= (int) $pagActualAlert ?>"
        data-pag-diet="<?= (int) $pagActualDiet ?>"
        data-animal-filtro="<?= $idAnimalFiltro
                                ? (int) $idAnimalFiltro
                                : "" ?>">
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="js/veterinario.js"></script>
</body>

</html>
