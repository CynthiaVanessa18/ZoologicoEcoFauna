<?php

require_once __DIR__ . "/../../soporte/sesion.php";
require_once __DIR__ . "/../../../config/database.php";
require_once __DIR__ . "/funciones.php";

/** @var mysqli $conexion */

if (
    !isset($_SESSION["usuario"], $_SESSION["rol"], $_SESSION["id_login"]) ||
    $_SESSION["rol"] !== "Administrador"
) {
    header("Location: ../index.php");
    exit();
}


// =====================================================
// FUNCIONES
// =====================================================

function escapar($texto): string
{
    return htmlspecialchars(
        (string) $texto,
        ENT_QUOTES,
        "UTF-8"
    );
}


function fechaMostrar($fecha): string
{
    if (
        empty($fecha) ||
        $fecha === "0000-00-00"
    ) {
        return "—";
    }

    $timestamp = strtotime($fecha);

    if ($timestamp === false) {
        return "—";
    }

    return date("d/m/Y", $timestamp);
}


function horaMostrar($hora): string
{
    if (empty($hora)) {
        return "—";
    }

    $timestamp = strtotime($hora);

    if ($timestamp === false) {
        return "—";
    }

    return date("h:i A", $timestamp);
}


// =====================================================
// OBTENER ID DE LA ASIGNACIÓN
// =====================================================

if (
    !isset($_GET["id_cuidador_animal"]) ||
    !is_numeric($_GET["id_cuidador_animal"])
) {
    header("Location: cuidadores.php");
    exit;
}


$id_cuidador_animal = (int) $_GET["id_cuidador_animal"];


if ($id_cuidador_animal <= 0) {
    header("Location: cuidadores.php");
    exit;
}


// =====================================================
// PAGINACIÓN
// =====================================================

$tareasPorPagina = 5;

$paginaActual = isset($_GET["pagina"])
    ? (int) $_GET["pagina"]
    : 1;

if ($paginaActual < 1) {
    $paginaActual = 1;
}


// =====================================================
// MENSAJES
// =====================================================

$mensaje = "";
$error = "";

[$mensajeFlash, $tipoMensajeFlash] = consumirMensajeCuidadores();

if ($mensajeFlash !== "") {

    if ($tipoMensajeFlash === "danger") {
        $error = $mensajeFlash;
    } else {
        $mensaje = $mensajeFlash;
    }
}


// =====================================================
// VARIABLES DEL FORMULARIO
// =====================================================

$id_tarea_catalogo = 0;
$nombre_tarea = "";
$descripcion = "";
$frecuencia = "";
$hora_programada = null;
$observaciones = "";


// =====================================================
// OBTENER CUIDADOR Y ANIMAL
// =====================================================

$sqlAsignacion = "
    SELECT
        ca.id_cuidador_animal,
        ca.id_cuidador,
        ca.id_animal,
        ca.fecha_asignacion,
        ca.fecha_finalizacion,
        ca.responsabilidad,
        ca.estado,

        u.nombre,

        a.nombre_animal

    FROM cuidador_animal ca

    INNER JOIN cuidador c
        ON c.id_cuidador = ca.id_cuidador

    INNER JOIN usuario u
        ON u.id_usuario = c.id_usuario

    INNER JOIN animal a
        ON a.id_animal = ca.id_animal

    WHERE ca.id_cuidador_animal = ?

    LIMIT 1
";


$stmtAsignacion = $conexion->prepare($sqlAsignacion);


if (!$stmtAsignacion) {

    die(
        "Error al preparar la consulta de la asignación: "
        . $conexion->error
    );
}


$stmtAsignacion->bind_param(
    "i",
    $id_cuidador_animal
);


$stmtAsignacion->execute();


$resultadoAsignacion = $stmtAsignacion->get_result();


if ($resultadoAsignacion->num_rows === 0) {

    header("Location: cuidadores.php");
    exit;
}


$asignacion = $resultadoAsignacion->fetch_assoc();

$stmtAsignacion->close();


// =====================================================
// GUARDAR TAREA / CAMBIAR ESTADO
// =====================================================

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $accion = $_POST["accion"] ?? "asignar";

    $enTransaccion = false;

    try {

        if (!solicitudCuidadoresValida()) {

            http_response_code(403);

            throw new RuntimeException(
                "La solicitud expiró. Recarga la página e inténtalo nuevamente."
            );
        }


        mysqli_begin_transaction($conexion);

        $enTransaccion = true;


        // =================================================
        // ASIGNAR TAREA
        // =================================================

        if ($accion === "asignar") {

            $id_tarea_catalogo =
                (int) ($_POST["id_tarea_catalogo"] ?? 0);

            $frecuencia =
                trim($_POST["frecuencia"] ?? "");

            $hora_programada =
                trim($_POST["hora_programada"] ?? "");

            $observaciones =
                trim($_POST["observaciones"] ?? "");


            if ($asignacion["estado"] !== "Activo") {

                throw new RuntimeException(
                    "Esta asignación está finalizada. No es posible agregar nuevas tareas."
                );
            }


            if ($id_tarea_catalogo <= 0) {

                throw new RuntimeException(
                    "Debe seleccionar una tarea."
                );
            }


            if (
                mb_strlen($frecuencia) > 100 ||
                mb_strlen($observaciones) > 500
            ) {

                throw new RuntimeException(
                    "La frecuencia o las observaciones superan el límite permitido."
                );
            }


            if (
                $hora_programada !== "" &&
                !preg_match(
                    '/^(?:[01]\d|2[0-3]):[0-5]\d$/',
                    $hora_programada
                )
            ) {

                throw new RuntimeException(
                    "La hora programada no es válida."
                );
            }


            // =============================================
            // CONSULTAR CATÁLOGO
            // =============================================

            $stmtCatalogo = $conexion->prepare(
                "SELECT
                    nombre_tarea,
                    descripcion
                 FROM tarea_cuidador
                 WHERE id_tarea = ?
                 AND estado = 'Activa'
                 LIMIT 1"
            );


            if (!$stmtCatalogo) {

                throw new RuntimeException(
                    "No fue posible consultar el catálogo de tareas."
                );
            }


            $stmtCatalogo->bind_param(
                "i",
                $id_tarea_catalogo
            );


            $stmtCatalogo->execute();


            $tareaCatalogo =
                $stmtCatalogo
                    ->get_result()
                    ->fetch_assoc();


            $stmtCatalogo->close();


            if (!$tareaCatalogo) {

                throw new RuntimeException(
                    "La tarea seleccionada no existe o está inactiva."
                );
            }


            $nombre_tarea =
                $tareaCatalogo["nombre_tarea"];

            $descripcion =
                $tareaCatalogo["descripcion"] ?? "";


            // =============================================
            // VERIFICAR DUPLICADA
            // =============================================

            $stmtDuplicada = $conexion->prepare(
                "SELECT id_tarea
                 FROM tarea
                 WHERE id_cuidador_animal = ?
                 AND nombre_tarea = ?
                 AND estado = 'Activa'
                 LIMIT 1"
            );


            if (!$stmtDuplicada) {

                throw new RuntimeException(
                    "No fue posible verificar las tareas asignadas."
                );
            }


            $stmtDuplicada->bind_param(
                "is",
                $id_cuidador_animal,
                $nombre_tarea
            );


            $stmtDuplicada->execute();


            $tareaDuplicada =
                $stmtDuplicada
                    ->get_result()
                    ->fetch_assoc();


            $stmtDuplicada->close();


            if ($tareaDuplicada) {

                throw new RuntimeException(
                    "Esta tarea ya está activa para el animal."
                );
            }


            // =============================================
            // INSERTAR TAREA
            // =============================================

            $stmtAccion = $conexion->prepare(
                "INSERT INTO tarea
                    (
                        id_cuidador_animal,
                        nombre_tarea,
                        descripcion,
                        frecuencia,
                        hora_programada,
                        observaciones,
                        estado
                    )
                 VALUES
                    (
                        ?,
                        ?,
                        NULLIF(?, ''),
                        NULLIF(?, ''),
                        NULLIF(?, ''),
                        NULLIF(?, ''),
                        'Activa'
                    )"
            );


            if (!$stmtAccion) {

                throw new RuntimeException(
                    "No fue posible preparar la asignación de la tarea."
                );
            }


            $stmtAccion->bind_param(
                "isssss",
                $id_cuidador_animal,
                $nombre_tarea,
                $descripcion,
                $frecuencia,
                $hora_programada,
                $observaciones
            );


            $accionBitacora = "ASIGNAR TAREA";


            $detalleBitacora =
                "Se asignó la tarea {$nombre_tarea} "
                . "a {$asignacion['nombre_animal']}.";


            $mensajeExito =
                "La tarea fue asignada correctamente al animal.";
        }


        // =================================================
        // CAMBIAR ESTADO
        // =================================================

        elseif ($accion === "cambiar_estado") {

            $id_tarea =
                (int) ($_POST["id_tarea"] ?? 0);

            $nuevo_estado =
                $_POST["nuevo_estado"] ?? "";


            if (
                $id_tarea <= 0 ||
                !in_array(
                    $nuevo_estado,
                    ["Activa", "Finalizada"],
                    true
                )
            ) {

                throw new RuntimeException(
                    "La tarea o el estado seleccionado no es válido."
                );
            }


            if (
                $nuevo_estado === "Activa" &&
                $asignacion["estado"] !== "Activo"
            ) {

                throw new RuntimeException(
                    "No se puede reactivar una tarea de una asignación finalizada."
                );
            }


            // =============================================
            // VERIFICAR TAREA
            // =============================================

            $stmtTarea = $conexion->prepare(
                "SELECT nombre_tarea
                 FROM tarea
                 WHERE id_tarea = ?
                 AND id_cuidador_animal = ?
                 LIMIT 1"
            );


            if (!$stmtTarea) {

                throw new RuntimeException(
                    "No fue posible verificar la tarea asignada."
                );
            }


            $stmtTarea->bind_param(
                "ii",
                $id_tarea,
                $id_cuidador_animal
            );


            $stmtTarea->execute();


            $tareaAsignada =
                $stmtTarea
                    ->get_result()
                    ->fetch_assoc();


            $stmtTarea->close();


            if (!$tareaAsignada) {

                throw new RuntimeException(
                    "La tarea asignada no existe."
                );
            }


            // =============================================
            // ACTUALIZAR ESTADO
            // =============================================

            $stmtAccion = $conexion->prepare(
                "UPDATE tarea
                 SET estado = ?
                 WHERE id_tarea = ?
                 AND id_cuidador_animal = ?"
            );


            if (!$stmtAccion) {

                throw new RuntimeException(
                    "No fue posible preparar el cambio de estado."
                );
            }


            $stmtAccion->bind_param(
                "sii",
                $nuevo_estado,
                $id_tarea,
                $id_cuidador_animal
            );


            $nombre_tarea =
                $tareaAsignada["nombre_tarea"];


            $accionBitacora =
                $nuevo_estado === "Finalizada"
                    ? "FINALIZAR TAREA ASIGNADA"
                    : "REACTIVAR TAREA ASIGNADA";


            $detalleBitacora =
                "La tarea {$nombre_tarea} "
                . "de {$asignacion['nombre_animal']} "
                . "cambió a {$nuevo_estado}.";


            $mensajeExito =
                $nuevo_estado === "Finalizada"
                    ? "La tarea fue finalizada correctamente."
                    : "La tarea fue reactivada correctamente.";
        }


        else {

            throw new RuntimeException(
                "La acción solicitada no es válida."
            );
        }


        // =================================================
        // EJECUTAR
        // =================================================

        if (!$stmtAccion->execute()) {

            throw new RuntimeException(
                $stmtAccion->error
            );
        }


        if ($accion === "asignar") {

            $id_tarea =
                $conexion->insert_id;
        }


        $stmtAccion->close();


        // =================================================
        // BITÁCORA
        // =================================================

        if (
            !registrarBitacora(
                $_SESSION["usuario"],
                $accionBitacora,
                "tarea",
                $detalleBitacora,
                $id_tarea
            )
        ) {

            throw new RuntimeException(
                "No fue posible registrar la acción en la bitácora."
            );
        }


        mysqli_commit($conexion);


        redirigirCuidadores(
            "asignar_tarea.php?id_cuidador_animal="
            . $id_cuidador_animal,
            $mensajeExito
        );
    }


    catch (Throwable $excepcion) {

        if ($enTransaccion) {

            mysqli_rollback($conexion);
        }


        error_log(
            "Error gestionando tarea asignada: "
            . $excepcion->getMessage()
        );


        $error =
            $excepcion->getMessage();
    }
}


// =====================================================
// CATÁLOGO DE TAREAS
// =====================================================

$sqlTareasDisponibles = "
    SELECT
        id_tarea,
        nombre_tarea,
        descripcion

    FROM tarea_cuidador

    WHERE estado = 'Activa'

    ORDER BY nombre_tarea ASC
";


$tareasDisponibles =
    $conexion->query(
        $sqlTareasDisponibles
    );


if (!$tareasDisponibles) {

    die(
        "Error al consultar el catálogo de tareas: "
        . $conexion->error
    );
}


// =====================================================
// TAREAS ASIGNADAS
// =====================================================

$sqlTareasAsignadas = "
    SELECT
        id_tarea,
        nombre_tarea,
        descripcion,
        frecuencia,
        hora_programada,
        observaciones,
        estado

    FROM tarea

    WHERE id_cuidador_animal = ?

    ORDER BY

        CASE
            WHEN estado = 'Activa'
            THEN 0
            ELSE 1
        END,

        hora_programada ASC,

        nombre_tarea ASC
";


$stmtTareasAsignadas =
    $conexion->prepare(
        $sqlTareasAsignadas
    );


if (!$stmtTareasAsignadas) {

    die(
        "Error al preparar la consulta de tareas asignadas: "
        . $conexion->error
    );
}


$stmtTareasAsignadas->bind_param(
    "i",
    $id_cuidador_animal
);


$stmtTareasAsignadas->execute();


$resultadoTareasAsignadas =
    $stmtTareasAsignadas->get_result();


// =====================================================
// CONVERTIR RESULTADO A ARRAY
// =====================================================

$todasLasTareas = [];

while (
    $fila = $resultadoTareasAsignadas->fetch_assoc()
) {

    $todasLasTareas[] = $fila;
}


$stmtTareasAsignadas->close();


// =====================================================
// CALCULAR PAGINACIÓN
// =====================================================

$totalTareas = count($todasLasTareas);

$totalPaginas = max(
    1,
    (int) ceil(
        $totalTareas / $tareasPorPagina
    )
);


if ($paginaActual > $totalPaginas) {

    $paginaActual =
        $totalPaginas;
}


$inicio =
    ($paginaActual - 1)
    * $tareasPorPagina;


$tareasPagina =
    array_slice(
        $todasLasTareas,
        $inicio,
        $tareasPorPagina
    );

?>

<!DOCTYPE html>

<html lang="es">

<head>

<meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width, initial-scale=1.0"
>

<title>
    Asignar tarea - EcoFauna
</title>


<link
    rel="preconnect"
    href="https://fonts.googleapis.com"
>

<link
    rel="preconnect"
    href="https://fonts.gstatic.com"
    crossorigin
>

<link
    href="https://fonts.googleapis.com/css2?family=DM+Serif+Display&family=Poppins:wght@400;500;600;700&display=swap"
    rel="stylesheet"
>


<link
    rel="stylesheet"
    href="../css/cuidadores1.css"
>


<style>

/* =====================================================
   FORMULARIO
===================================================== */

.formulario {

    display: grid;

    grid-template-columns:
        repeat(2, 1fr);

    gap: 20px;

    padding: 28px;
}


.formulario > div {

    display: flex;

    flex-direction: column;

    gap: 8px;
}


.formulario .campo-completo {

    grid-column: 1 / -1;
}


.formulario label {

    font-weight: 600;
}


.formulario input,
.formulario select,
.formulario textarea {

    width: 100%;

    padding: 12px 14px;

    border: 1px solid #d7dce1;

    border-radius: 10px;

    font-family: inherit;

    font-size: 14px;

    background: white;

    box-sizing: border-box;
}


.formulario textarea {

    min-height: 120px;

    resize: vertical;
}


.descripcion-automatica {

    background: #f5f6f7 !important;

    color: #555;

    cursor: not-allowed;
}


/* =====================================================
   INFORMACIÓN DE ASIGNACIÓN
===================================================== */

.informacion-asignacion {

    display: grid;

    grid-template-columns:
        repeat(4, 1fr);

    gap: 18px;

    padding: 28px;
}


.dato {

    display: flex;

    flex-direction: column;

    gap: 6px;
}


.dato strong {

    font-size: 13px;

    color: #666;
}


.dato span {

    font-size: 15px;

    font-weight: 500;
}


/* =====================================================
   ADVERTENCIA
===================================================== */

.advertencia {

    margin: 0 28px 20px;

    padding: 16px 18px;

    border-radius: 10px;

    background: #fff4e5;

    border: 1px solid #f2c078;
}


/* =====================================================
   MENSAJES
===================================================== */

.mensaje {

    margin-bottom: 20px;

    padding: 15px 18px;

    border-radius: 10px;

    background: #e8f7ed;

    border: 1px solid #a9d9b7;
}


.error {

    margin-bottom: 20px;

    padding: 15px 18px;

    border-radius: 10px;

    background: #fdecec;

    border: 1px solid #e3aaaa;
}


/* =====================================================
   TABLA
===================================================== */

.tabla-contenedor {

    width: 100%;

    overflow-x: auto;
}


table {

    width: 100%;

    border-collapse: collapse;
}


table th,
table td {

    padding: 14px;

    text-align: left;

    vertical-align: top;
}


table th {

    font-weight: 600;
}


/* =====================================================
   ESTADOS
===================================================== */

.estado {

    display: inline-block;

    padding: 5px 10px;

    border-radius: 20px;

    font-size: 12px;

    font-weight: 600;
}


.estado.activo {

    background: #e8f7ed;

    color: #24733a;
}


.estado.finalizado {

    background: #fdecec;

    color: #a33a3a;
}


/* =====================================================
   BOTONES DE ESTADO
===================================================== */

.form-estado-tarea {

    display: inline;
}


.boton.finalizar-tarea,
.boton.reactivar-tarea {

    min-height: 34px;

    padding: 0 11px;

    font-size: 12px;
}


.boton.finalizar-tarea {

    background: #fdecec;

    color: #a33a3a;

    border: 1px solid #e3aaaa;
}


.boton.reactivar-tarea {

    background: #e8f7ed;

    color: #24733a;

    border: 1px solid #a9d9b7;
}


/* =====================================================
   SIN REGISTROS
===================================================== */

.sin-registros {

    text-align: center;

    padding: 30px;
}


/* =====================================================
   PAGINACIÓN
===================================================== */

.paginacion {

    display: flex;

    justify-content: space-between;

    align-items: center;

    gap: 20px;

    padding: 20px 28px;

    border-top: 1px solid #e5e5e5;

    flex-wrap: wrap;
}


.paginacion-info {

    color: #666;

    font-size: 14px;
}


.paginacion-info strong {

    color: #304734;
}


.paginacion-botones {

    display: flex;

    align-items: center;

    gap: 6px;

    flex-wrap: wrap;
}


.boton-paginacion {

    display: inline-flex;

    align-items: center;

    justify-content: center;

    min-width: 38px;

    height: 38px;

    padding: 0 12px;

    border: 1px solid #d7dce1;

    border-radius: 8px;

    background: #ffffff;

    color: #304734;

    text-decoration: none;

    font-size: 13px;

    font-weight: 600;

    transition:
        background 0.2s ease,
        color 0.2s ease,
        border-color 0.2s ease;
}


.boton-paginacion:hover {

    background: #e8efe2;

    border-color: #91a27f;

    color: #304734;
}


.boton-paginacion.activo {

    background: #607754;

    border-color: #607754;

    color: #ffffff;
}


/* =====================================================
   RESPONSIVE
===================================================== */

@media (max-width: 900px) {

    .informacion-asignacion {

        grid-template-columns:
            repeat(2, 1fr);
    }

}


@media (max-width: 650px) {

    .formulario {

        grid-template-columns: 1fr;
    }


    .formulario .campo-completo {

        grid-column: auto;
    }


    .informacion-asignacion {

        grid-template-columns: 1fr;
    }


    .paginacion {

        flex-direction: column;

        align-items: stretch;

        text-align: center;
    }


    .paginacion-botones {

        justify-content: center;
    }

}

</style>

    <link rel="stylesheet" href="../css/crud-modern.css?v=1">
    <link rel="stylesheet" href="../css/ecofauna-unified.css?v=1">
</head>

<body class="ecofauna-unified crud-modern">




<div class="contenedor">


<!-- =====================================================
     ENCABEZADO
===================================================== -->

<div class="encabezado">

    <div>

        <h1>
            Asignar tarea
        </h1>

        <p class="subtitulo">

            Asigne tareas específicas al animal
            bajo responsabilidad del cuidador.

        </p>

    </div>


    <div class="acciones">

        <a
            href="cuidadores.php?id=<?= (int) $asignacion["id_cuidador"] ?>"
            class="boton volver"
        >

            ← Volver

        </a>

    </div>

</div>


<!-- =====================================================
     MENSAJES
===================================================== -->

<?php if (!empty($mensaje)): ?>

<div class="mensaje">

    <?= escapar($mensaje) ?>

</div>

<?php endif; ?>


<?php if (!empty($error)): ?>

<div class="error">

    <?= escapar($error) ?>

</div>

<?php endif; ?>


<!-- =====================================================
     INFORMACIÓN DE LA ASIGNACIÓN
===================================================== -->

<div class="tarjeta">

    <h2 class="titulo">

        Información de la asignación

    </h2>


    <div class="informacion-asignacion">


        <div class="dato">

            <strong>
                Cuidador
            </strong>

            <span>

                <?= escapar(
                    $asignacion["nombre"]
                ) ?>

            </span>

        </div>


        <div class="dato">

            <strong>
                Animal
            </strong>

            <span>

                <?= escapar(
                    $asignacion["nombre_animal"]
                ) ?>

            </span>

        </div>


        <div class="dato">

            <strong>
                Fecha asignación
            </strong>

            <span>

                <?= fechaMostrar(
                    $asignacion["fecha_asignacion"]
                ) ?>

            </span>

        </div>


        <div class="dato">

            <strong>
                Estado
            </strong>

            <span
                class="estado
                <?= $asignacion["estado"] === "Activo"
                    ? "activo"
                    : "finalizado"
                ?>"
            >

                <?= escapar(
                    $asignacion["estado"]
                ) ?>

            </span>

        </div>


    </div>

</div>


<!-- =====================================================
     VALIDAR ASIGNACIÓN
===================================================== -->

<?php if ($asignacion["estado"] !== "Activo"): ?>

<div class="advertencia">

    <strong>
        Esta asignación está finalizada.
    </strong>

    <br>

    No es posible agregar nuevas tareas
    a una asignación que ya finalizó.

</div>


<?php else: ?>


<!-- =====================================================
     FORMULARIO DE NUEVA TAREA
===================================================== -->

<div class="tarjeta">

    <h2 class="titulo">

        Nueva tarea

    </h2>


    <form
        method="POST"
        class="formulario"
    >

        <?= campoCsrfCuidadores() ?>

        <input
            type="hidden"
            name="accion"
            value="asignar"
        >


        <!-- TAREA -->

        <div>

            <label for="id_tarea_catalogo">

                Tarea

            </label>


            <select
                name="id_tarea_catalogo"
                id="id_tarea_catalogo"
                required
            >

                <option value="">

                    Seleccione una tarea

                </option>


                <?php while (
                    $tarea =
                    $tareasDisponibles->fetch_assoc()
                ): ?>

                    <option
                        value="<?= (int) $tarea["id_tarea"] ?>"
                        <?= (int) $tarea["id_tarea"] === $id_tarea_catalogo
                            ? "selected"
                            : ""
                        ?>

                        data-descripcion="<?= escapar(
                            $tarea["descripcion"] ?? ""
                        ) ?>"
                    >

                        <?= escapar(
                            $tarea["nombre_tarea"]
                        ) ?>

                    </option>

                <?php endwhile; ?>

            </select>

        </div>


        <!-- DESCRIPCIÓN -->

        <div>

            <label for="descripcion_tarea">

                Descripción

            </label>


            <textarea
                id="descripcion_tarea"
                class="descripcion-automatica"
                readonly
                placeholder="Seleccione una tarea del catálogo."
            ></textarea>

        </div>


        <!-- FRECUENCIA -->

        <div>

            <label for="frecuencia">

                Frecuencia

            </label>


            <input
                type="text"
                name="frecuencia"
                id="frecuencia"
                maxlength="100"
                placeholder="Ej. Diaria"
                value="<?= escapar(
                    $frecuencia
                ) ?>"
            >

        </div>


        <!-- HORA -->

        <div>

            <label for="hora_programada">

                Hora programada

            </label>


            <input
                type="time"
                name="hora_programada"
                id="hora_programada"
                value="<?= escapar(
                    $hora_programada ?? ""
                ) ?>"
            >

        </div>


        <!-- OBSERVACIONES -->

        <div class="campo-completo">

            <label for="observaciones">

                Observaciones

            </label>


            <textarea
                name="observaciones"
                id="observaciones"
                maxlength="500"
                placeholder="Indique observaciones importantes para realizar la tarea..."
            ><?= escapar(
                $observaciones
            ) ?></textarea>

        </div>


        <!-- BOTÓN -->

        <div class="campo-completo">

            <button
                type="submit"
                class="boton guardar"
            >

                + Asignar tarea

            </button>

        </div>

    </form>

</div>


<?php endif; ?>


<!-- =====================================================
     TAREAS ASIGNADAS AL ANIMAL
===================================================== -->

<div class="tarjeta">

    <h2 class="titulo">

        Tareas asignadas a

        <?= escapar(
            $asignacion["nombre_animal"]
        ) ?>

    </h2>


    <div class="tabla-contenedor">

        <table>

            <thead>

                <tr>

                    <th>
                        Tarea
                    </th>

                    <th>
                        Descripción
                    </th>

                    <th>
                        Frecuencia
                    </th>

                    <th>
                        Hora
                    </th>

                    <th>
                        Observaciones
                    </th>

                    <th>
                        Estado
                    </th>

                    <th>
                        Acción
                    </th>

                </tr>

            </thead>


            <tbody>


            <?php if ($totalTareas > 0): ?>


                <?php foreach (
                    $tareasPagina
                    as $tarea
                ): ?>

                    <tr>


                        <!-- TAREA -->

                        <td>

                            <strong>

                                <?= escapar(
                                    $tarea["nombre_tarea"]
                                ) ?>

                            </strong>

                        </td>


                        <!-- DESCRIPCIÓN -->

                        <td>

                            <?= nl2br(
                                escapar(
                                    $tarea["descripcion"]
                                    ?: "Sin descripción"
                                )
                            ) ?>

                        </td>


                        <!-- FRECUENCIA -->

                        <td>

                            <?= escapar(
                                $tarea["frecuencia"]
                                ?: "—"
                            ) ?>

                        </td>


                        <!-- HORA -->

                        <td>

                            <?php if (
                                !empty(
                                    $tarea["hora_programada"]
                                )
                            ): ?>

                                <?= horaMostrar(
                                    $tarea["hora_programada"]
                                ) ?>

                            <?php else: ?>

                                —

                            <?php endif; ?>

                        </td>


                        <!-- OBSERVACIONES -->

                        <td>

                            <?= nl2br(
                                escapar(
                                    $tarea["observaciones"]
                                    ?: "—"
                                )
                            ) ?>

                        </td>


                        <!-- ESTADO -->

                        <td>

                            <?php if (
                                $tarea["estado"] === "Activa"
                            ): ?>

                                <span
                                    class="estado activo"
                                >

                                    Activa

                                </span>

                            <?php else: ?>

                                <span
                                    class="estado finalizado"
                                >

                                    Finalizada

                                </span>

                            <?php endif; ?>

                        </td>


                        <!-- ACCIÓN -->

                        <td>

                            <?php if (
                                $asignacion["estado"] === "Activo"
                            ): ?>

                                <form
                                    method="POST"
                                    class="form-estado-tarea"
                                >

                                    <?= campoCsrfCuidadores() ?>


                                    <input
                                        type="hidden"
                                        name="accion"
                                        value="cambiar_estado"
                                    >


                                    <input
                                        type="hidden"
                                        name="id_tarea"
                                        value="<?= (int) $tarea["id_tarea"] ?>"
                                    >


                                    <?php if (
                                        $tarea["estado"] === "Activa"
                                    ): ?>

                                        <input
                                            type="hidden"
                                            name="nuevo_estado"
                                            value="Finalizada"
                                        >


                                        <button
                                            type="submit"
                                            class="boton finalizar-tarea"
                                        >

                                            Finalizar

                                        </button>

                                    <?php else: ?>

                                        <input
                                            type="hidden"
                                            name="nuevo_estado"
                                            value="Activa"
                                        >


                                        <button
                                            type="submit"
                                            class="boton reactivar-tarea"
                                        >

                                            Reactivar

                                        </button>

                                    <?php endif; ?>

                                </form>

                            <?php else: ?>

                                —

                            <?php endif; ?>

                        </td>


                    </tr>

                <?php endforeach; ?>


            <?php else: ?>


                <tr>

                    <td
                        colspan="7"
                        class="sin-registros"
                    >

                        No hay tareas asignadas
                        a este animal.

                    </td>

                </tr>


            <?php endif; ?>


            </tbody>

        </table>

    </div>


    <!-- =================================================
         PAGINACIÓN
    ================================================= -->

    <?php if ($totalPaginas > 1): ?>

    <div class="paginacion">

        <div class="paginacion-info">

            Mostrando

            <strong>
                <?= $inicio + 1 ?>
            </strong>

            -

            <strong>
                <?= min(
                    $inicio + $tareasPorPagina,
                    $totalTareas
                ) ?>
            </strong>

            de

            <strong>
                <?= $totalTareas ?>
            </strong>

            tareas

        </div>


        <div class="paginacion-botones">


            <?php if ($paginaActual > 1): ?>

                <a
                    href="?id_cuidador_animal=<?= $id_cuidador_animal ?>&pagina=<?= $paginaActual - 1 ?>"
                    class="boton-paginacion"
                >

                    ← Anterior

                </a>

            <?php endif; ?>


            <?php for (
                $i = 1;
                $i <= $totalPaginas;
                $i++
            ): ?>

                <a
                    href="?id_cuidador_animal=<?= $id_cuidador_animal ?>&pagina=<?= $i ?>"
                    class="boton-paginacion
                    <?= $i === $paginaActual
                        ? "activo"
                        : ""
                    ?>"
                >

                    <?= $i ?>

                </a>

            <?php endfor; ?>


            <?php if (
                $paginaActual < $totalPaginas
            ): ?>

                <a
                    href="?id_cuidador_animal=<?= $id_cuidador_animal ?>&pagina=<?= $paginaActual + 1 ?>"
                    class="boton-paginacion"
                >

                    Siguiente →

                </a>

            <?php endif; ?>


        </div>

    </div>

    <?php endif; ?>


</div>


</div>


<!-- =====================================================
     JAVASCRIPT
===================================================== -->

<script>

const selectTarea =
    document.getElementById(
        "id_tarea_catalogo"
    );


const descripcionTarea =
    document.getElementById(
        "descripcion_tarea"
    );


if (
    selectTarea &&
    descripcionTarea
) {

    selectTarea.addEventListener(
        "change",
        function () {

            const opcionSeleccionada =
                this.options[
                    this.selectedIndex
                ];


            if (
                !opcionSeleccionada ||
                !opcionSeleccionada.value
            ) {

                descripcionTarea.value = "";

                return;
            }


            const descripcion =
                opcionSeleccionada.getAttribute(
                    "data-descripcion"
                ) || "";


            descripcionTarea.value =
                descripcion;

        }
    );


    selectTarea.dispatchEvent(
        new Event("change")
    );

}

</script>


</body>

</html>