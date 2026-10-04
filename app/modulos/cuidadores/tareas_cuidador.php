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


// =========================================================
// FUNCIONES
// =========================================================

function escapar($texto): string
{
    return htmlspecialchars(
        (string)$texto,
        ENT_QUOTES,
        "UTF-8"
    );
}


// =========================================================
// MENSAJES
// =========================================================

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


// =========================================================
// PROCESAR FORMULARIO
// =========================================================

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $accion = $_POST["accion"] ?? "";
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
        // AGREGAR
        // =================================================

        if ($accion === "agregar") {

            $nombre_tarea = trim($_POST["nombre_tarea"] ?? "");
            $descripcion = trim($_POST["descripcion"] ?? "");
            $estado = $_POST["estado"] ?? "Activa";


            if ($nombre_tarea === "") {

                throw new RuntimeException(
                    "Debe indicar el nombre de la tarea."
                );
            }


            if (
                mb_strlen($nombre_tarea) > 100 ||
                mb_strlen($descripcion) > 500
            ) {

                throw new RuntimeException(
                    "El nombre o la descripción supera el límite permitido."
                );
            }


            if (
                !in_array(
                    $estado,
                    ["Activa", "Inactiva"],
                    true
                )
            ) {

                throw new RuntimeException(
                    "El estado seleccionado no es válido."
                );
            }


            $stmtExiste = $conexion->prepare(
                "SELECT id_tarea
                 FROM tarea_cuidador
                 WHERE nombre_tarea = ?
                 LIMIT 1"
            );


            if (!$stmtExiste) {

                throw new RuntimeException(
                    "No fue posible verificar la tarea."
                );
            }


            $stmtExiste->bind_param(
                "s",
                $nombre_tarea
            );

            $stmtExiste->execute();

            $duplicada =
                $stmtExiste
                    ->get_result()
                    ->fetch_assoc();

            $stmtExiste->close();


            if ($duplicada) {

                throw new RuntimeException(
                    "Ya existe una tarea con ese nombre."
                );
            }


            $stmtAccion = $conexion->prepare(
                "INSERT INTO tarea_cuidador
                    (nombre_tarea, descripcion, estado)
                 VALUES
                    (?, NULLIF(?, ''), ?)"
            );


            if (!$stmtAccion) {

                throw new RuntimeException(
                    "No fue posible preparar el registro de la tarea."
                );
            }


            $stmtAccion->bind_param(
                "sss",
                $nombre_tarea,
                $descripcion,
                $estado
            );


            $accionBitacora =
                "REGISTRAR TAREA CUIDADOR";

            $detalleBitacora =
                "Se registró la tarea de cuidador {$nombre_tarea}.";

            $mensajeExito =
                "La tarea fue registrada correctamente.";
        }


        // =================================================
        // CAMBIAR ESTADO
        // =================================================

        elseif ($accion === "estado") {

            $id_tarea =
                (int)($_POST["id_tarea"] ?? 0);

            $nuevo_estado =
                $_POST["nuevo_estado"] ?? "";


            if ($id_tarea <= 0) {

                throw new RuntimeException(
                    "No se pudo identificar la tarea."
                );
            }


            if (
                !in_array(
                    $nuevo_estado,
                    ["Activa", "Inactiva"],
                    true
                )
            ) {

                throw new RuntimeException(
                    "El estado seleccionado no es válido."
                );
            }


            $stmtNombre = $conexion->prepare(
                "SELECT nombre_tarea
                 FROM tarea_cuidador
                 WHERE id_tarea = ?
                 LIMIT 1"
            );


            if (!$stmtNombre) {

                throw new RuntimeException(
                    "No fue posible verificar la tarea."
                );
            }


            $stmtNombre->bind_param(
                "i",
                $id_tarea
            );

            $stmtNombre->execute();

            $tareaActual =
                $stmtNombre
                    ->get_result()
                    ->fetch_assoc();

            $stmtNombre->close();


            if (!$tareaActual) {

                throw new RuntimeException(
                    "La tarea seleccionada no existe."
                );
            }


            $stmtAccion = $conexion->prepare(
                "UPDATE tarea_cuidador
                 SET estado = ?
                 WHERE id_tarea = ?"
            );


            if (!$stmtAccion) {

                throw new RuntimeException(
                    "No fue posible preparar el cambio de estado."
                );
            }


            $stmtAccion->bind_param(
                "si",
                $nuevo_estado,
                $id_tarea
            );


            $accionBitacora =
                "CAMBIAR ESTADO TAREA CUIDADOR";

            $detalleBitacora =
                "La tarea {$tareaActual['nombre_tarea']} cambió a {$nuevo_estado}.";

            $mensajeExito =
                $nuevo_estado === "Inactiva"
                    ? "La tarea fue inactivada correctamente."
                    : "La tarea fue activada correctamente.";
        }


        // =================================================
        // EDITAR
        // =================================================

        elseif ($accion === "editar") {

            $id_tarea =
                (int)($_POST["id_tarea"] ?? 0);

            $nombre_tarea =
                trim($_POST["nombre_tarea"] ?? "");

            $descripcion =
                trim($_POST["descripcion"] ?? "");

            $estado =
                $_POST["estado"] ?? "";


            if (
                $id_tarea <= 0 ||
                $nombre_tarea === ""
            ) {

                throw new RuntimeException(
                    "Debe identificar la tarea e indicar su nombre."
                );
            }


            if (
                mb_strlen($nombre_tarea) > 100 ||
                mb_strlen($descripcion) > 500
            ) {

                throw new RuntimeException(
                    "El nombre o la descripción supera el límite permitido."
                );
            }


            if (
                !in_array(
                    $estado,
                    ["Activa", "Inactiva"],
                    true
                )
            ) {

                throw new RuntimeException(
                    "El estado seleccionado no es válido."
                );
            }


            $stmtExiste = $conexion->prepare(
                "SELECT id_tarea
                 FROM tarea_cuidador
                 WHERE nombre_tarea = ?
                 AND id_tarea <> ?
                 LIMIT 1"
            );


            if (!$stmtExiste) {

                throw new RuntimeException(
                    "No fue posible verificar la tarea."
                );
            }


            $stmtExiste->bind_param(
                "si",
                $nombre_tarea,
                $id_tarea
            );

            $stmtExiste->execute();

            $duplicada =
                $stmtExiste
                    ->get_result()
                    ->fetch_assoc();

            $stmtExiste->close();


            if ($duplicada) {

                throw new RuntimeException(
                    "Ya existe otra tarea con ese nombre."
                );
            }


            $stmtAccion = $conexion->prepare(
                "UPDATE tarea_cuidador
                 SET
                    nombre_tarea = ?,
                    descripcion = NULLIF(?, ''),
                    estado = ?
                 WHERE id_tarea = ?"
            );


            if (!$stmtAccion) {

                throw new RuntimeException(
                    "No fue posible preparar la actualización de la tarea."
                );
            }


            $stmtAccion->bind_param(
                "sssi",
                $nombre_tarea,
                $descripcion,
                $estado,
                $id_tarea
            );


            $accionBitacora =
                "ACTUALIZAR TAREA CUIDADOR";

            $detalleBitacora =
                "Se actualizó la tarea de cuidador {$nombre_tarea}.";

            $mensajeExito =
                "La tarea fue actualizada correctamente.";
        }


        else {

            throw new RuntimeException(
                "La acción solicitada no es válida."
            );
        }


        // =================================================
        // EJECUTAR CONSULTA
        // =================================================

        if (!$stmtAccion->execute()) {

            throw new RuntimeException(
                $stmtAccion->error
            );
        }


        // =================================================
        // OBTENER ID INSERTADO
        // =================================================

        if ($accion === "agregar") {

            $id_tarea =
                $conexion->insert_id;

        }

        elseif (
            $stmtAccion->affected_rows === 0 &&
            $accion === "editar"
        ) {

            $stmtVerificar = $conexion->prepare(
                "SELECT id_tarea
                 FROM tarea_cuidador
                 WHERE id_tarea = ?
                 LIMIT 1"
            );


            if (!$stmtVerificar) {

                throw new RuntimeException(
                    "No fue posible verificar la tarea actualizada."
                );
            }


            $stmtVerificar->bind_param(
                "i",
                $id_tarea
            );

            $stmtVerificar->execute();

            $existeObjetivo =
                $stmtVerificar
                    ->get_result()
                    ->fetch_assoc();

            $stmtVerificar->close();


            if (!$existeObjetivo) {

                throw new RuntimeException(
                    "La tarea seleccionada no existe."
                );
            }
        }


        $stmtAccion->close();


        // =================================================
        // BITÁCORA
        // =================================================

        if (
            !registrarBitacora(
                $_SESSION["usuario"],
                $accionBitacora,
                "tarea_cuidador",
                $detalleBitacora,
                $id_tarea
            )
        ) {

            throw new RuntimeException(
                "No fue posible registrar la acción en la bitácora."
            );
        }


        // =================================================
        // CONFIRMAR
        // =================================================

        mysqli_commit($conexion);

        redirigirCuidadores(
            "tareas_cuidador.php",
            $mensajeExito
        );

    }

    catch (Throwable $excepcion) {

        if ($enTransaccion) {

            mysqli_rollback($conexion);
        }


        error_log(
            "Error gestionando tarea de cuidador: " .
            $excepcion->getMessage()
        );


        $error =
            str_contains(
                $excepcion->getMessage(),
                "Duplicate entry"
            )
            ? "Ya existe una tarea con ese nombre."
            : $excepcion->getMessage();
    }
}


// =========================================================
// OBTENER TAREAS
// =========================================================

$sqlTareas = "
    SELECT
        id_tarea,
        nombre_tarea,
        descripcion,
        estado
    FROM tarea_cuidador
    ORDER BY nombre_tarea ASC
";


$resultadoTareas =
    $conexion->query($sqlTareas);


if (!$resultadoTareas) {

    $error =
        "No fue posible cargar las tareas registradas.";

    $resultadoTareas = false;
}


// =========================================================
// CONVERTIR RESULTADOS A ARRAY
// =========================================================

$tareas = [];

if ($resultadoTareas) {

    while ($tarea = $resultadoTareas->fetch_assoc()) {

        $tareas[] = $tarea;
    }

    $resultadoTareas->free();
}

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
        Tareas de cuidadores
    </title>


    <link
        rel="stylesheet"
        href="../css/cuidadores1.css"
    >


    <style>

        /* =================================================
           MENSAJE DE ERROR
        ================================================= */

        .mensaje-error {

            background: #f8e5e2;
            color: #8b3a32;
            border: 1px solid #d9a49d;
            border-left: 5px solid #8b3a32;
            padding: 14px 18px;
            border-radius: 10px;
            margin-bottom: 20px;
            font-weight: 600;

        }


        /* =================================================
           MENSAJE CORRECTO
        ================================================= */

        .mensaje {

            background: #e8efe2;
            color: #304734;
            border: 1px solid #91a27f;
            border-left: 5px solid #607754;
            padding: 14px 18px;
            border-radius: 10px;
            margin-bottom: 20px;
            font-weight: 600;

        }


        /* =================================================
           TABLA
        ================================================= */

        .columna-id {

            display: none;

        }


        .tabla-contenedor {

            overflow-x: auto;

        }


        /* =================================================
           PAGINACIÓN
        ================================================= */

        .paginacion {

            width: 100%;

            display: flex;

            align-items: center;

            justify-content: center;

            gap: 8px;

            margin-top: 22px;

            min-height: 40px;

            box-sizing: border-box;

        }


        /*
         * IMPORTANTE:
         * numerosPagina NO debe tener la clase
         * "paginacion".
         */

        #numerosPagina {

            display: flex;

            align-items: center;

            justify-content: center;

            gap: 8px;

            width: auto;

            min-height: 40px;

            margin: 0;

            padding: 0;

            box-sizing: border-box;

        }


        .boton-paginacion {

            display: inline-flex;

            align-items: center;

            justify-content: center;

            flex: 0 0 auto;

            min-width: 40px;

            height: 40px;

            padding: 0 12px;

            box-sizing: border-box;

            border: 1px solid #91a27f;

            background: #ffffff;

            color: #304734;

            border-radius: 8px;

            cursor: pointer;

            font-family: inherit;

            font-size: 14px;

            font-weight: 600;

            line-height: 1;

            margin: 0;

            white-space: nowrap;

            transition:
                background 0.2s ease,
                color 0.2s ease,
                transform 0.2s ease,
                box-shadow 0.2s ease;

        }


        .boton-paginacion:hover:not(:disabled) {

            background: #e8efe2;

            transform: translateY(-1px);

        }


        .boton-paginacion.activa {

            background: #607754;

            color: #ffffff;

            border-color: #607754;

        }


        .boton-paginacion:disabled {

            opacity: 0.45;

            cursor: not-allowed;

            transform: none;

        }


        /* =================================================
           INFORMACIÓN DE PAGINACIÓN
        ================================================= */

        .informacion-paginacion {

            width: 100%;

            text-align: center;

            margin-top: 14px;

            color: #5a4432;

            font-size: 14px;

            line-height: 1.4;

        }


        /* =================================================
           MODAL DE EDICIÓN
        ================================================= */

        .modal-editar {

            display: none;

            position: fixed;

            inset: 0;

            background: rgba(0, 0, 0, 0.55);

            align-items: center;

            justify-content: center;

            padding: 20px;

            z-index: 9999;

        }


        .modal-editar.modal-visible {

            display: flex;

        }


        .modal-contenido {

            background: white;

            width: 100%;

            max-width: 550px;

            border-radius: 14px;

            padding: 25px;

            box-shadow:
                0 15px 40px rgba(0, 0, 0, 0.25);

        }


        .modal-encabezado {

            display: flex;

            align-items: center;

            justify-content: space-between;

            margin-bottom: 20px;

        }


        .modal-encabezado h2 {

            margin: 0;

        }


        .modal-cerrar {

            border: none;

            background: transparent;

            font-size: 28px;

            cursor: pointer;

            color: #5a4432;

        }


        .modal-cerrar:hover {

            opacity: 0.7;

        }


        .campo-modal {

            margin-bottom: 18px;

        }


        .campo-modal label {

            display: block;

            margin-bottom: 7px;

            font-weight: 600;

        }


        .campo-modal input,
        .campo-modal textarea,
        .campo-modal select {

            width: 100%;

            box-sizing: border-box;

        }


        .campo-modal textarea {

            min-height: 100px;

            resize: vertical;

        }


        .modal-acciones {

            display: flex;

            gap: 10px;

            margin-top: 22px;

        }


        /* =================================================
           BOTONES DESHABILITADOS AL EDITAR
        ================================================= */

        .boton-accion:disabled {

            opacity: 0.45;

            cursor: not-allowed;

            pointer-events: none;

            transform: none;

            box-shadow: none;

        }


        /* =================================================
           MODAL DE CONFIRMACIÓN
        ================================================= */

        .confirmacion-inactivar {

            display: none;

            position: fixed;

            inset: 0;

            background: rgba(0, 0, 0, 0.55);

            align-items: center;

            justify-content: center;

            padding: 20px;

            z-index: 10000;

        }


        .confirmacion-inactivar.visible {

            display: flex;

        }


        .confirmacion-contenido {

            background: #ffffff;

            width: 100%;

            max-width: 420px;

            border-radius: 14px;

            padding: 28px;

            box-shadow:
                0 15px 40px rgba(0, 0, 0, 0.25);

            text-align: center;

        }


        .confirmacion-contenido h3 {

            margin: 0 0 12px;

            color: #304734;

        }


        .confirmacion-contenido p {

            margin: 0 0 24px;

            color: #5a4432;

            line-height: 1.5;

        }


        .confirmacion-acciones {

            display: flex;

            justify-content: center;

            gap: 10px;

        }


        .boton-confirmar {

            background: #8b3a32;

            color: white;

            border: none;

            padding: 10px 18px;

            border-radius: 8px;

            cursor: pointer;

            font-weight: 600;

        }


        .boton-confirmar:hover {

            opacity: 0.9;

        }


        .boton-cancelar {

            background: #e8efe2;

            color: #304734;

            border: 1px solid #91a27f;

            padding: 10px 18px;

            border-radius: 8px;

            cursor: pointer;

            font-weight: 600;

        }


        .boton-cancelar:hover {

            opacity: 0.85;

        }


        /* =================================================
           RESPONSIVE PAGINACIÓN
        ================================================= */

        @media (max-width: 600px) {

            .paginacion {

                justify-content: center;

                gap: 5px;

                overflow-x: auto;

                padding: 4px 2px;

            }


            #numerosPagina {

                gap: 5px;

                flex-shrink: 0;

            }


            .boton-paginacion {

                min-width: 36px;

                height: 36px;

                padding: 0 9px;

                font-size: 13px;

                flex-shrink: 0;

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
    ====================================================== -->

    <div class="encabezado">

        <div>

            <h1>
                Tareas de cuidadores
            </h1>

            <p class="subtitulo">
                Administración de las tareas que
                pueden realizar los cuidadores.
            </p>

        </div>


        <a
            href="cuidadores.php"
            class="boton volver"
        >
            ← Cuidadores
        </a>

    </div>


    <!-- =====================================================
         MENSAJES
    ====================================================== -->

    <?php if ($mensaje !== ""): ?>

        <div class="mensaje">
            <?= escapar($mensaje) ?>
        </div>

    <?php endif; ?>


    <?php if ($error !== ""): ?>

        <div class="mensaje-error">
            <?= escapar($error) ?>
        </div>

    <?php endif; ?>


    <!-- =====================================================
         REGISTRAR TAREA
    ====================================================== -->

    <div class="tarjeta">

        <h2 class="titulo">
            Registrar nueva tarea
        </h2>


        <form
            method="POST"
            class="formulario"
        >

            <?= campoCsrfCuidadores() ?>


            <input
                type="hidden"
                name="accion"
                value="agregar"
            >


            <div>

                <label for="nombre_tarea">
                    Nombre
                </label>

                <input
                    type="text"
                    name="nombre_tarea"
                    id="nombre_tarea"
                    maxlength="100"
                    placeholder="Ej. Alimentación"
                    value="<?= escapar(
                        ($_POST["accion"] ?? "") === "agregar"
                            ? ($_POST["nombre_tarea"] ?? "")
                            : ""
                    ) ?>"
                    required
                >

            </div>


            <div>

                <label for="descripcion">
                    Descripción
                </label>

                <input
                    type="text"
                    name="descripcion"
                    id="descripcion"
                    maxlength="500"
                    placeholder="Descripción de la tarea"
                    value="<?= escapar(
                        ($_POST["accion"] ?? "") === "agregar"
                            ? ($_POST["descripcion"] ?? "")
                            : ""
                    ) ?>"
                >

            </div>


            <div>

                <label for="estado">
                    Estado
                </label>

                <select
                    name="estado"
                    id="estado"
                >

                    <option
                        value="Activa"
                        <?= (
                            $_POST["estado"] ?? "Activa"
                        ) === "Activa"
                            ? "selected"
                            : ""
                        ?>
                    >
                        Activa
                    </option>


                    <option
                        value="Inactiva"
                        <?= (
                            $_POST["estado"] ?? ""
                        ) === "Inactiva"
                            ? "selected"
                            : ""
                        ?>
                    >
                        Inactiva
                    </option>

                </select>

            </div>


            <div>

                <button
                    type="submit"
                    class="boton guardar"
                >
                    + Registrar
                </button>

            </div>

        </form>

    </div>


    <!-- =====================================================
         LISTADO
    ====================================================== -->

    <div class="tarjeta">

        <h2 class="titulo">
            Tareas registradas
        </h2>


        <div class="tabla-contenedor">

            <table id="tablaTareas">

                <thead>

                    <tr>

                        <th class="columna-id">
                            ID
                        </th>

                        <th>
                            Tarea
                        </th>

                        <th>
                            Descripción
                        </th>

                        <th>
                            Estado
                        </th>

                        <th>
                            Acciones
                        </th>

                    </tr>

                </thead>


                <tbody id="cuerpoTabla">

                <?php if (count($tareas) > 0): ?>


                    <?php foreach ($tareas as $tarea): ?>

                        <tr class="fila-tarea">

                            <td class="columna-id">

                                <?= (int)$tarea["id_tarea"] ?>

                            </td>


                            <td>

                                <strong>

                                    <?= escapar(
                                        $tarea["nombre_tarea"]
                                    ) ?>

                                </strong>

                            </td>


                            <td>

                                <?= escapar(
                                    $tarea["descripcion"]
                                    ?: "Sin descripción"
                                ) ?>

                            </td>


                            <td>

                                <?php if (
                                    $tarea["estado"] === "Activa"
                                ): ?>

                                    <span
                                        class="estado estado-activa"
                                    >
                                        Activa
                                    </span>

                                <?php else: ?>

                                    <span
                                        class="estado estado-inactiva"
                                    >
                                        Inactiva
                                    </span>

                                <?php endif; ?>

                            </td>


                            <td>

                                <div class="acciones">


                                    <!-- EDITAR -->

                                    <button
                                        type="button"
                                        class="boton editar boton-accion"
                                        onclick='editarTarea(
                                            <?= (int)$tarea["id_tarea"] ?>,
                                            <?= json_encode(
                                                $tarea["nombre_tarea"],
                                                JSON_HEX_TAG |
                                                JSON_HEX_APOS |
                                                JSON_HEX_QUOT |
                                                JSON_HEX_AMP
                                            ) ?>,
                                            <?= json_encode(
                                                $tarea["descripcion"] ?? "",
                                                JSON_HEX_TAG |
                                                JSON_HEX_APOS |
                                                JSON_HEX_QUOT |
                                                JSON_HEX_AMP
                                            ) ?>,
                                            <?= json_encode(
                                                $tarea["estado"],
                                                JSON_HEX_TAG |
                                                JSON_HEX_APOS |
                                                JSON_HEX_QUOT |
                                                JSON_HEX_AMP
                                            ) ?>
                                        )'
                                    >
                                        Editar
                                    </button>


                                    <!-- CAMBIAR ESTADO -->

                                    <form
                                        method="POST"
                                        class="form-estado"
                                    >

                                        <?= campoCsrfCuidadores() ?>


                                        <input
                                            type="hidden"
                                            name="accion"
                                            value="estado"
                                        >


                                        <input
                                            type="hidden"
                                            name="id_tarea"
                                            value="<?= (int)$tarea["id_tarea"] ?>"
                                        >


                                        <?php if (
                                            $tarea["estado"] === "Activa"
                                        ): ?>

                                            <input
                                                type="hidden"
                                                name="nuevo_estado"
                                                value="Inactiva"
                                            >


                                            <button
                                                type="button"
                                                class="boton desactivar boton-accion"
                                                onclick="mostrarConfirmacion(
                                                    this,
                                                    '¿Desea inactivar esta tarea?'
                                                )"
                                            >
                                                Inactivar
                                            </button>

                                        <?php else: ?>

                                            <input
                                                type="hidden"
                                                name="nuevo_estado"
                                                value="Activa"
                                            >


                                            <button
                                                type="submit"
                                                class="boton activar boton-accion"
                                            >
                                                Activar
                                            </button>

                                        <?php endif; ?>

                                    </form>

                                </div>

                            </td>

                        </tr>

                    <?php endforeach; ?>


                <?php else: ?>

                    <tr>

                        <td
                            colspan="5"
                            class="sin-registros"
                        >
                            No hay tareas registradas.
                        </td>

                    </tr>

                <?php endif; ?>

                </tbody>

            </table>

        </div>


        <!-- =================================================
             INFORMACIÓN DE PAGINACIÓN
        ================================================== -->

        <?php if (count($tareas) > 0): ?>

            <div
                id="informacionPaginacion"
                class="informacion-paginacion"
            >
            </div>


            <!-- =================================================
                 PAGINACIÓN
            ================================================== -->

            <div
                id="paginacion"
                class="paginacion"
            >

                <button
                    type="button"
                    id="btnAnterior"
                    class="boton-paginacion"
                    onclick="cambiarPagina(-1)"
                >
                    ← Anterior
                </button>


                <!-- IMPORTANTE:
                     NO tiene la clase paginacion -->

                <div
                    id="numerosPagina"
                >
                </div>


                <button
                    type="button"
                    id="btnSiguiente"
                    class="boton-paginacion"
                    onclick="cambiarPagina(1)"
                >
                    Siguiente →
                </button>

            </div>

        <?php endif; ?>

    </div>


</div>


<!-- =====================================================
     MODAL DE EDICIÓN
====================================================== -->

<div
    id="modalEditar"
    class="modal-editar"
>

    <div class="modal-contenido">

        <div class="modal-encabezado">

            <h2>
                Editar tarea
            </h2>


            <button
                type="button"
                class="modal-cerrar"
                onclick="cerrarModal()"
                aria-label="Cerrar"
            >
                ×
            </button>

        </div>


        <form method="POST">

            <?= campoCsrfCuidadores() ?>


            <input
                type="hidden"
                name="accion"
                value="editar"
            >


            <input
                type="hidden"
                name="id_tarea"
                id="editar_id"
            >


            <div class="campo-modal">

                <label for="editar_nombre">
                    Nombre
                </label>

                <input
                    type="text"
                    name="nombre_tarea"
                    id="editar_nombre"
                    maxlength="100"
                    required
                >

            </div>


            <div class="campo-modal">

                <label for="editar_descripcion">
                    Descripción
                </label>

                <textarea
                    name="descripcion"
                    id="editar_descripcion"
                    maxlength="500"
                ></textarea>

            </div>


            <div class="campo-modal">

                <label for="editar_estado">
                    Estado
                </label>

                <select
                    name="estado"
                    id="editar_estado"
                    required
                >

                    <option value="Activa">
                        Activa
                    </option>

                    <option value="Inactiva">
                        Inactiva
                    </option>

                </select>

            </div>


            <div class="modal-acciones">

                <button
                    type="submit"
                    class="boton guardar"
                >
                    Guardar cambios
                </button>


                <button
                    type="button"
                    class="boton volver"
                    onclick="cerrarModal()"
                >
                    Cancelar
                </button>

            </div>

        </form>

    </div>

</div>


<!-- =====================================================
     MODAL DE CONFIRMACIÓN
====================================================== -->

<div
    id="confirmacionInactivar"
    class="confirmacion-inactivar"
>

    <div class="confirmacion-contenido">

        <h3>
            Inactivar tarea
        </h3>


        <p id="mensajeConfirmacion">
            ¿Desea inactivar esta tarea?
        </p>


        <div class="confirmacion-acciones">

            <button
                type="button"
                class="boton-confirmar"
                id="btnConfirmarInactivar"
            >
                Sí, inactivar
            </button>


            <button
                type="button"
                class="boton-cancelar"
                onclick="cerrarConfirmacion()"
            >
                Cancelar
            </button>

        </div>

    </div>

</div>


<!-- =====================================================
     JAVASCRIPT
====================================================== -->

<script>


// =====================================================
// CONFIGURACIÓN DE PAGINACIÓN
// =====================================================

const FILAS_POR_PAGINA = 10;

let paginaActual = 1;

const filasTareas =
    Array.from(
        document.querySelectorAll(".fila-tarea")
    );

const totalFilas =
    filasTareas.length;

const totalPaginas =
    Math.ceil(
        totalFilas / FILAS_POR_PAGINA
    );


// =====================================================
// MOSTRAR PÁGINA
// =====================================================

function mostrarPagina(pagina) {

    if (totalFilas === 0) {

        return;

    }


    if (pagina < 1) {

        pagina = 1;

    }


    if (pagina > totalPaginas) {

        pagina = totalPaginas;

    }


    paginaActual = pagina;


    const inicio =
        (paginaActual - 1) *
        FILAS_POR_PAGINA;


    const fin =
        inicio +
        FILAS_POR_PAGINA;


    filasTareas.forEach(
        function(fila, indice) {

            if (
                indice >= inicio &&
                indice < fin
            ) {

                fila.style.display = "";

            } else {

                fila.style.display = "none";

            }

        }
    );


    actualizarPaginacion();

}


// =====================================================
// ACTUALIZAR PAGINACIÓN
// =====================================================

function actualizarPaginacion() {

    const btnAnterior =
        document.getElementById(
            "btnAnterior"
        );

    const btnSiguiente =
        document.getElementById(
            "btnSiguiente"
        );

    const numerosPagina =
        document.getElementById(
            "numerosPagina"
        );

    const informacion =
        document.getElementById(
            "informacionPaginacion"
        );


    if (
        !btnAnterior ||
        !btnSiguiente ||
        !numerosPagina ||
        !informacion
    ) {

        return;

    }


    // =================================================
    // ANTERIOR / SIGUIENTE
    // =================================================

    btnAnterior.disabled =
        paginaActual === 1;

    btnSiguiente.disabled =
        paginaActual === totalPaginas;


    // =================================================
    // NÚMEROS DE PÁGINA
    // =================================================

    numerosPagina.innerHTML = "";


    for (
        let pagina = 1;
        pagina <= totalPaginas;
        pagina++
    ) {

        const boton =
            document.createElement("button");


        boton.type = "button";

        boton.className =
            "boton-paginacion";


        boton.textContent =
            pagina;


        if (
            pagina === paginaActual
        ) {

            boton.classList.add(
                "activa"
            );

        }


        boton.addEventListener(
            "click",
            function() {

                mostrarPagina(pagina);

            }
        );


        numerosPagina.appendChild(
            boton
        );

    }


    // =================================================
    // INFORMACIÓN
    // =================================================

    const inicio =
        (paginaActual - 1) *
        FILAS_POR_PAGINA +
        1;


    const fin =
        Math.min(
            paginaActual *
            FILAS_POR_PAGINA,
            totalFilas
        );


    informacion.textContent =
        "Mostrando " +
        inicio +
        " - " +
        fin +
        " de " +
        totalFilas +
        " tareas";

}


// =====================================================
// CAMBIAR PÁGINA
// =====================================================

function cambiarPagina(direccion) {

    mostrarPagina(
        paginaActual + direccion
    );

}


// =====================================================
// INICIAR PAGINACIÓN
// =====================================================

document.addEventListener(
    "DOMContentLoaded",
    function() {

        if (totalFilas > 0) {

            mostrarPagina(1);

        }

    }
);


// =====================================================
// MODAL DE EDICIÓN
// =====================================================

function editarTarea(
    id,
    nombre,
    descripcion,
    estado
) {

    document.getElementById(
        "editar_id"
    ).value = id;


    document.getElementById(
        "editar_nombre"
    ).value = nombre;


    document.getElementById(
        "editar_descripcion"
    ).value = descripcion;


    document.getElementById(
        "editar_estado"
    ).value = estado;


    document.getElementById(
        "modalEditar"
    ).classList.add(
        "modal-visible"
    );


    document
        .querySelectorAll(".boton-accion")
        .forEach(
            function(boton) {

                boton.disabled = true;

            }
        );

}


// =====================================================
// CERRAR MODAL
// =====================================================

function cerrarModal() {

    document.getElementById(
        "modalEditar"
    ).classList.remove(
        "modal-visible"
    );


    document
        .querySelectorAll(".boton-accion")
        .forEach(
            function(boton) {

                boton.disabled = false;

            }
        );

}


// =====================================================
// CERRAR MODAL AL HACER CLIC FUERA
// =====================================================

document
    .getElementById("modalEditar")
    .addEventListener(
        "click",
        function(event) {

            if (
                event.target === this
            ) {

                cerrarModal();

            }

        }
    );


// =====================================================
// CERRAR CON ESC
// =====================================================

document.addEventListener(
    "keydown",
    function(event) {

        if (
            event.key === "Escape"
        ) {

            cerrarModal();

            cerrarConfirmacion();

        }

    }
);


// =====================================================
// FORMULARIO DE INACTIVAR
// =====================================================

let formularioInactivar = null;


// =====================================================
// MOSTRAR CONFIRMACIÓN
// =====================================================

function mostrarConfirmacion(
    boton,
    mensaje
) {

    formularioInactivar =
        boton.closest("form");


    document.getElementById(
        "mensajeConfirmacion"
    ).textContent = mensaje;


    document.getElementById(
        "confirmacionInactivar"
    ).classList.add(
        "visible"
    );

}


// =====================================================
// CONFIRMAR INACTIVACIÓN
// =====================================================

document
    .getElementById(
        "btnConfirmarInactivar"
    )
    .addEventListener(
        "click",
        function() {

            if (
                formularioInactivar
            ) {

                formularioInactivar.submit();

            }

        }
    );


// =====================================================
// CERRAR CONFIRMACIÓN
// =====================================================

function cerrarConfirmacion() {

    document.getElementById(
        "confirmacionInactivar"
    ).classList.remove(
        "visible"
    );


    formularioInactivar = null;

}


// =====================================================
// CERRAR CONFIRMACIÓN AL HACER CLIC FUERA
// =====================================================

document
    .getElementById(
        "confirmacionInactivar"
    )
    .addEventListener(
        "click",
        function(event) {

            if (
                event.target === this
            ) {

                cerrarConfirmacion();

            }

        }
    );

</script>


</body>

</html>