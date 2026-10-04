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
// VALIDAR ID DEL CUIDADOR
// =========================================================

if (
    !isset($_GET["id"]) ||
    !is_numeric($_GET["id"])
) {

    header("Location: cuidadores.php");
    exit();

}

$id_cuidador = (int)$_GET["id"];

if ($id_cuidador <= 0) {

    header("Location: cuidadores.php");
    exit();

}


// =========================================================
// OBTENER CUIDADOR
// =========================================================

$sqlCuidador = "
    SELECT
        c.id_cuidador,
        c.fecha_contratacion,
        c.especialidad,
        c.estado,
        u.nombre
    FROM cuidador c
    INNER JOIN usuario u
        ON u.id_usuario = c.id_usuario
    WHERE c.id_cuidador = ?
";

$stmtCuidador = $conexion->prepare($sqlCuidador);

$stmtCuidador->bind_param(
    "i",
    $id_cuidador
);

$stmtCuidador->execute();

$resultadoCuidador =
    $stmtCuidador->get_result();


if ($resultadoCuidador->num_rows === 0) {

    $stmtCuidador->close();

    header("Location: cuidadores.php");
    exit();

}


$cuidador =
    $resultadoCuidador->fetch_assoc();

$stmtCuidador->close();


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
// PROCESAR FORMULARIOS
// =========================================================

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $accion = $_POST["accion"] ?? "";
    $enTransaccion = false;

    try {
        if (!solicitudCuidadoresValida()) {
            http_response_code(403);
            throw new RuntimeException(
                "La solicitud expiró. Recarga la página e inténtalo nuevamente.",
            );
        }

        mysqli_begin_transaction($conexion);
        $enTransaccion = true;

        if ($accion === "guardar") {
            $dia_semana = trim($_POST["dia_semana"] ?? "");
            $hora_entrada = trim($_POST["hora_entrada"] ?? "");
            $hora_salida = trim($_POST["hora_salida"] ?? "");
            $diasPermitidos = [
                "Lunes",
                "Martes",
                "Miercoles",
                "Jueves",
                "Viernes",
                "Sabado",
                "Domingo",
            ];

            if ($cuidador["estado"] !== "Activo") {
                throw new RuntimeException("No se pueden agregar horarios a un cuidador inactivo.");
            }

            if (!in_array($dia_semana, $diasPermitidos, true)) {
                throw new RuntimeException("Seleccione un día válido.");
            }

            if (
                !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $hora_entrada) ||
                !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $hora_salida)
            ) {
                throw new RuntimeException("Debe indicar horas válidas de entrada y salida.");
            }

            if ($hora_entrada >= $hora_salida) {
                throw new RuntimeException("La hora de entrada debe ser menor que la hora de salida.");
            }

            $stmtExiste = $conexion->prepare(
                "SELECT id_horario, estado
                 FROM horario_cuidador
                 WHERE id_cuidador = ? AND dia_semana = ?
                 LIMIT 1 FOR UPDATE",
            );
            if (!$stmtExiste) {
                throw new RuntimeException("No fue posible verificar el horario.");
            }
            $stmtExiste->bind_param("is", $id_cuidador, $dia_semana);
            $stmtExiste->execute();
            $horarioExistente = $stmtExiste->get_result()->fetch_assoc();
            $stmtExiste->close();

            if ($horarioExistente && $horarioExistente["estado"] === "activo") {
                throw new RuntimeException("Ya existe un horario activo registrado para ese día.");
            }

            if ($horarioExistente) {
                $id_horario = (int) $horarioExistente["id_horario"];
                $stmtGuardar = $conexion->prepare(
                    "UPDATE horario_cuidador
                     SET hora_entrada = ?, hora_salida = ?, estado = 'activo'
                     WHERE id_horario = ? AND id_cuidador = ?",
                );
                if (!$stmtGuardar) {
                    throw new RuntimeException("No fue posible preparar la reactivación del horario.");
                }
                $stmtGuardar->bind_param(
                    "ssii",
                    $hora_entrada,
                    $hora_salida,
                    $id_horario,
                    $id_cuidador,
                );
                $accionBitacora = "REACTIVAR HORARIO";
            } else {
                $stmtGuardar = $conexion->prepare(
                    "INSERT INTO horario_cuidador
                        (id_cuidador, dia_semana, hora_entrada, hora_salida, estado)
                     VALUES (?, ?, ?, ?, 'activo')",
                );
                if (!$stmtGuardar) {
                    throw new RuntimeException("No fue posible preparar el horario.");
                }
                $stmtGuardar->bind_param(
                    "isss",
                    $id_cuidador,
                    $dia_semana,
                    $hora_entrada,
                    $hora_salida,
                );
                $accionBitacora = "REGISTRAR HORARIO";
            }

            if (!$stmtGuardar->execute()) {
                throw new RuntimeException($stmtGuardar->error);
            }
            if (!$horarioExistente) {
                $id_horario = $conexion->insert_id;
            }
            $stmtGuardar->close();

            if (
                !registrarBitacora(
                    $_SESSION["usuario"],
                    $accionBitacora,
                    "horario_cuidador",
                    "Horario de {$cuidador['nombre']}: {$dia_semana}, {$hora_entrada} a {$hora_salida}.",
                    $id_horario,
                )
            ) {
                throw new RuntimeException("No fue posible registrar la acción en la bitácora.");
            }

            $mensajeExito = $horarioExistente
                ? "Horario reactivado y actualizado correctamente."
                : "Horario registrado correctamente.";
        } elseif ($accion === "ocultar") {
            $id_horario = (int) ($_POST["id_horario"] ?? 0);
            if ($id_horario <= 0) {
                throw new RuntimeException("El horario seleccionado no es válido.");
            }

            $stmtOcultar = $conexion->prepare(
                "UPDATE horario_cuidador
                 SET estado = 'inactivo'
                 WHERE id_horario = ? AND id_cuidador = ? AND estado = 'activo'",
            );
            if (!$stmtOcultar) {
                throw new RuntimeException("No fue posible preparar la actualización del horario.");
            }
            $stmtOcultar->bind_param("ii", $id_horario, $id_cuidador);
            if (!$stmtOcultar->execute()) {
                throw new RuntimeException($stmtOcultar->error);
            }
            if ($stmtOcultar->affected_rows === 0) {
                throw new RuntimeException("El horario ya estaba oculto o no existe.");
            }
            $stmtOcultar->close();

            if (
                !registrarBitacora(
                    $_SESSION["usuario"],
                    "OCULTAR HORARIO",
                    "horario_cuidador",
                    "Se ocultó un horario de {$cuidador['nombre']}.",
                    $id_horario,
                )
            ) {
                throw new RuntimeException("No fue posible registrar la acción en la bitácora.");
            }
            $mensajeExito = "El horario fue ocultado correctamente.";
        } else {
            throw new RuntimeException("La acción solicitada no es válida.");
        }

        mysqli_commit($conexion);
        redirigirCuidadores(
            "horario_cuidador.php?id=" . $id_cuidador,
            $mensajeExito,
        );
    } catch (Throwable $excepcion) {
        if ($enTransaccion) {
            mysqli_rollback($conexion);
        }
        error_log("Error gestionando horario: " . $excepcion->getMessage());
        $error = $excepcion->getMessage();
    }
}


// =========================================================
// OBTENER HORARIOS ACTIVOS
// =========================================================
//
// Los horarios inactivos permanecen en la BD,
// pero no se muestran en esta pantalla.
// =========================================================

$sqlHorarios = "
    SELECT
        id_horario,
        dia_semana,
        hora_entrada,
        hora_salida,
        estado
    FROM horario_cuidador
    WHERE id_cuidador = ?
    AND estado = 'activo'
    ORDER BY FIELD(
        dia_semana,
        'Lunes',
        'Martes',
        'Miercoles',
        'Jueves',
        'Viernes',
        'Sabado',
        'Domingo'
    )
";

$stmtHorarios =
    $conexion->prepare(
        $sqlHorarios
    );

$stmtHorarios->bind_param(
    "i",
    $id_cuidador
);

$stmtHorarios->execute();

$horarios =
    $stmtHorarios->get_result();


// =========================================================
// DÍAS CON HORARIO ACTIVO
// =========================================================

$diasRegistrados = [];

$horariosArray = [];


while (
    $horario =
        $horarios->fetch_assoc()
) {

    $horariosArray[] =
        $horario;

    $diasRegistrados[] =
        $horario["dia_semana"];

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
        Horario del cuidador
    </title>


    <link
        rel="stylesheet"
        href="../css/cuidadores1.css"
    >


    <style>

        /* =================================================
           MENSAJES
        ================================================= */

        .mensaje-exito,
        .mensaje-error {

            position: relative;

            display: flex;

            align-items: center;

            gap: 12px;

            padding: 15px 18px;

            margin-bottom: 20px;

            border-radius: 10px;

            font-size: 15px;

            font-weight: 600;

            box-shadow:
                0 4px 12px rgba(
                    0,
                    0,
                    0,
                    0.08
                );

            animation:
                aparecerMensaje
                0.3s ease;

        }


        .mensaje-exito {

            background: #e8efe2;

            color: #304734;

            border: 1px solid #91a27f;

            border-left: 5px solid #607754;

        }


        .mensaje-error {

            background: #f8e5e2;

            color: #8b3a32;

            border: 1px solid #d9a49d;

            border-left: 5px solid #8b3a32;

        }


        .mensaje-icono {

            width: 30px;

            height: 30px;

            display: flex;

            align-items: center;

            justify-content: center;

            flex-shrink: 0;

            border-radius: 50%;

            font-size: 16px;

            font-weight: 700;

        }


        .mensaje-exito
        .mensaje-icono {

            background: #607754;

            color: #ffffff;

        }


        .mensaje-error
        .mensaje-icono {

            background: #8b3a32;

            color: #ffffff;

        }


        @keyframes aparecerMensaje {

            from {

                opacity: 0;

                transform:
                    translateY(-8px);

            }

            to {

                opacity: 1;

                transform:
                    translateY(0);

            }

        }


        /* =================================================
           ESTADO ACTIVO
        ================================================= */

        .estado-activo {

            display: inline-block;

            background: #e8efe2;

            color: #304734;

            border: 1px solid #91a27f;

            padding: 5px 12px;

            border-radius: 20px;

            font-size: 13px;

            font-weight: 700;

        }


        /* =================================================
           BOTÓN OCULTAR
        ================================================= */

        .boton.ocultar {

            background: #8b3a32;

            color: #ffffff;

            border: none;

            cursor: pointer;

            transition:
                background 0.2s ease,
                transform 0.2s ease;

        }


        .boton.ocultar:hover {

            background: #6f2d27;

            transform:
                translateY(-1px);

        }


        /* =================================================
           DÍAS DESHABILITADOS
        ================================================= */

        select option:disabled {

            color: #999999;

            background: #eeeeee;

        }


        /* =================================================
           SIN REGISTROS
        ================================================= */

        .sin-registros {

            text-align: center;

            padding: 25px;

            color: #6b7567;

            font-style: italic;

        }


        /* =================================================
           MODAL DE CONFIRMACIÓN
        ================================================= */

        .modal-fondo {

            position: fixed;

            inset: 0;

            background:
                rgba(
                    20,
                    25,
                    20,
                    0.55
                );

            display: none;

            align-items: center;

            justify-content: center;

            padding: 20px;

            z-index: 9999;

            backdrop-filter:
                blur(3px);

        }


        .modal-fondo.mostrar {

            display: flex;

            animation:
                aparecerFondo
                0.2s ease;

        }


        .modal {

            width: 100%;

            max-width: 440px;

            background: #ffffff;

            border-radius: 16px;

            padding: 28px;

            box-shadow:
                0 15px 40px
                rgba(
                    0,
                    0,
                    0,
                    0.20
                );

            animation:
                aparecerModal
                0.25s ease;

        }


        .modal-icono {

            width: 58px;

            height: 58px;

            margin: 0 auto 18px;

            display: flex;

            align-items: center;

            justify-content: center;

            border-radius: 50%;

            background: #f8e5e2;

            color: #8b3a32;

            font-size: 27px;

            font-weight: 700;

        }


        .modal h3 {

            margin: 0 0 10px;

            text-align: center;

            color: #304734;

            font-size: 21px;

        }


        .modal p {

            margin: 0;

            text-align: center;

            color: #687066;

            line-height: 1.6;

            font-size: 15px;

        }


        .modal-acciones {

            display: flex;

            justify-content: center;

            gap: 12px;

            margin-top: 25px;

        }


        .modal-boton {

            border: none;

            padding: 11px 20px;

            border-radius: 9px;

            font-size: 14px;

            font-weight: 700;

            cursor: pointer;

            transition:
                background 0.2s ease,
                transform 0.2s ease;

        }


        .modal-boton:hover {

            transform:
                translateY(-1px);

        }


        .modal-cancelar {

            background: #eeeeee;

            color: #4d554d;

        }


        .modal-cancelar:hover {

            background: #dddddd;

        }


        .modal-confirmar {

            background: #8b3a32;

            color: #ffffff;

        }


        .modal-confirmar:hover {

            background: #6f2d27;

        }


        @keyframes aparecerFondo {

            from {

                opacity: 0;

            }

            to {

                opacity: 1;

            }

        }


        @keyframes aparecerModal {

            from {

                opacity: 0;

                transform:
                    translateY(-15px)
                    scale(0.97);

            }

            to {

                opacity: 1;

                transform:
                    translateY(0)
                    scale(1);

            }

        }


        /* =================================================
           RESPONSIVE MODAL
        ================================================= */

        @media (max-width: 480px) {

            .modal {

                padding: 22px;

            }


            .modal-acciones {

                flex-direction: column;

            }


            .modal-boton {

                width: 100%;

            }

        }

    </style>

    <link rel="stylesheet" href="../css/crud-modern.css?v=1">
    <link rel="stylesheet" href="../css/ecofauna-unified.css?v=1">
</head>

<body class="ecofauna-unified crud-modern">





<div class="contenedor">


    <!-- =================================================
         ENCABEZADO
    ================================================== -->

    <div class="encabezado">

        <div>

            <h1>
                Horario del cuidador
            </h1>

            <p class="subtitulo">
                Administración del horario laboral.
            </p>

        </div>


        <div class="botones">

            <a
                href="cuidadores.php?id=<?= $id_cuidador ?>"
                class="boton volver"
            >
                ← Volver
            </a>

        </div>

    </div>


    <!-- =================================================
         DATOS DEL CUIDADOR
    ================================================== -->

    <div class="tarjeta">

        <h2 class="titulo">
            Información del cuidador
        </h2>


        <div class="datos">


            <div class="dato">

                <strong>
                    Nombre
                </strong>

                <span>
                    <?= escapar(
                        $cuidador["nombre"]
                    ) ?>
                </span>

            </div>


            <div class="dato">

                <strong>
                    Especialidad
                </strong>

                <span>
                    <?= escapar(
                        $cuidador["especialidad"]
                        ?: "Sin especialidad"
                    ) ?>
                </span>

            </div>


            <div class="dato">

                <strong>
                    Estado
                </strong>

                <span>
                    <?= escapar(
                        $cuidador["estado"]
                    ) ?>
                </span>

            </div>


        </div>

    </div>


    <!-- =================================================
         MENSAJES
    ================================================== -->

    <?php if ($mensaje !== ""): ?>

        <div
            class="mensaje-exito"
            role="alert"
        >

            <span class="mensaje-icono">
                ✓
            </span>

            <span>
                <?= escapar($mensaje) ?>
            </span>

        </div>

    <?php endif; ?>


    <?php if ($error !== ""): ?>

        <div
            class="mensaje-error"
            role="alert"
        >

            <span class="mensaje-icono">
                !
            </span>

            <span>
                <?= escapar($error) ?>
            </span>

        </div>

    <?php endif; ?>


    <!-- =================================================
         AGREGAR HORARIO
    ================================================== -->

    <div class="tarjeta">

        <h2 class="titulo">
            Agregar horario
        </h2>


        <form
            method="POST"
            class="formulario"
        >
            <?= campoCsrfCuidadores() ?>

            <input
                type="hidden"
                name="accion"
                value="guardar"
            >


            <!-- DÍA -->

            <div>

                <label for="dia_semana">
                    Día de la semana
                </label>


                <select
                    name="dia_semana"
                    id="dia_semana"
                    required
                >

                    <option value="">
                        Seleccione un día
                    </option>


                    <?php

                    $dias = [

                        "Lunes",
                        "Martes",
                        "Miercoles",
                        "Jueves",
                        "Viernes",
                        "Sabado",
                        "Domingo"

                    ];

                    foreach (
                        $dias as $dia
                    ):

                        $registrado =
                            in_array(
                                $dia,
                                $diasRegistrados,
                                true
                            );

                    ?>

                        <option
                            value="<?= escapar($dia) ?>"
                            <?= $dia === ($_POST["dia_semana"] ?? "") ? "selected" : "" ?>
                            <?= $registrado
                                ? "disabled"
                                : ""
                            ?>
                        >

                            <?= escapar($dia) ?>

                            <?php if ($registrado): ?>

                                - Registrado

                            <?php endif; ?>

                        </option>

                    <?php endforeach; ?>

                </select>

            </div>


            <!-- HORA ENTRADA -->

            <div>

                <label for="hora_entrada">
                    Hora de entrada
                </label>

                <input
                    type="time"
                    name="hora_entrada"
                    id="hora_entrada"
                    value="<?= escapar($_POST["hora_entrada"] ?? "") ?>"
                    required
                >

            </div>


            <!-- HORA SALIDA -->

            <div>

                <label for="hora_salida">
                    Hora de salida
                </label>

                <input
                    type="time"
                    name="hora_salida"
                    id="hora_salida"
                    value="<?= escapar($_POST["hora_salida"] ?? "") ?>"
                    required
                >

            </div>


            <!-- BOTÓN -->

            <div>

                <button
                    type="submit"
                    class="boton guardar"
                >
                    Guardar horario
                </button>

            </div>

        </form>

    </div>


    <!-- =================================================
         HORARIOS ACTIVOS
    ================================================== -->

    <div class="tarjeta">

        <h2 class="titulo">
            Horarios registrados
        </h2>


        <div class="tabla-contenedor">

            <table>

                <thead>

                    <tr>

                        <th>
                            Día
                        </th>

                        <th>
                            Entrada
                        </th>

                        <th>
                            Salida
                        </th>

                        <th>
                            Jornada
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


                <?php if (
                    count($horariosArray) > 0
                ): ?>


                    <?php foreach (
                        $horariosArray as $horario
                    ): ?>


                        <?php

                        $entrada =
                            strtotime(
                                $horario["hora_entrada"]
                            );

                        $salida =
                            strtotime(
                                $horario["hora_salida"]
                            );


                        $horas =
                            (
                                $salida - $entrada
                            ) / 3600;

                        ?>


                        <tr>


                            <!-- DÍA -->

                            <td>

                                <?= escapar(
                                    $horario["dia_semana"]
                                ) ?>

                            </td>


                            <!-- ENTRADA -->

                            <td>

                                <?= date(
                                    "h:i A",
                                    $entrada
                                ) ?>

                            </td>


                            <!-- SALIDA -->

                            <td>

                                <?= date(
                                    "h:i A",
                                    $salida
                                ) ?>

                            </td>


                            <!-- JORNADA -->

                            <td>

                                <?= number_format(
                                    $horas,
                                    2
                                ) ?>

                                horas

                            </td>


                            <!-- ESTADO -->

                            <td>

                                <span
                                    class="estado-activo"
                                >
                                    Activo
                                </span>

                            </td>


                            <!-- ACCIÓN -->

                            <td>

                                <!--
                                    IMPORTANTE:
                                    Ya NO utilizamos confirm().
                                    Por eso NO aparecerá:
                                    "localhost dice..."
                                -->

                                <form
                                    method="POST"
                                    class="form-ocultar"
                                >
                                    <?= campoCsrfCuidadores() ?>

                                    <input
                                        type="hidden"
                                        name="accion"
                                        value="ocultar"
                                    >


                                    <input
                                        type="hidden"
                                        name="id_horario"
                                        value="<?= escapar(
                                            $horario["id_horario"]
                                        ) ?>"
                                    >


                                    <button
                                        type="button"
                                        class="boton ocultar"
                                        onclick="
                                            abrirModal(
                                                this.closest('form')
                                            );
                                        "
                                    >
                                        Ocultar
                                    </button>

                                </form>

                            </td>


                        </tr>


                    <?php endforeach; ?>


                <?php else: ?>


                    <tr>

                        <td
                            colspan="6"
                            class="sin-registros"
                        >

                            No hay horarios activos
                            registrados para este cuidador.

                        </td>

                    </tr>


                <?php endif; ?>


                </tbody>

            </table>

        </div>

    </div>


</div>


<!-- =====================================================
     MODAL PERSONALIZADO
====================================================== -->

<div
    id="modalOcultar"
    class="modal-fondo"
    aria-hidden="true"
>


    <div
        class="modal"
        role="dialog"
        aria-modal="true"
        aria-labelledby="tituloModal"
    >


        <div class="modal-icono">
            !
        </div>


        <h3 id="tituloModal">
            Ocultar horario
        </h3>


        <p>
            ¿Está seguro de que desea ocultar
            este horario?
            <br><br>
            El registro
            <strong>
                no será eliminado
            </strong>
            de la base de datos.
        </p>


        <div class="modal-acciones">


            <button
                type="button"
                class="modal-boton modal-cancelar"
                onclick="cerrarModal();"
            >
                Cancelar
            </button>


            <button
                type="button"
                class="modal-boton modal-confirmar"
                onclick="confirmarOcultar();"
            >
                Sí, ocultar
            </button>


        </div>

    </div>

</div>


<script>

/* =====================================================
   VARIABLE DEL FORMULARIO SELECCIONADO
===================================================== */

let formularioSeleccionado = null;


/* =====================================================
   ABRIR MODAL
===================================================== */

function abrirModal(formulario)
{
    formularioSeleccionado =
        formulario;

    const modal =
        document.getElementById(
            "modalOcultar"
        );

    modal.classList.add(
        "mostrar"
    );

    modal.setAttribute(
        "aria-hidden",
        "false"
    );

    document.body.style.overflow =
        "hidden";
}


/* =====================================================
   CERRAR MODAL
===================================================== */

function cerrarModal()
{
    const modal =
        document.getElementById(
            "modalOcultar"
        );

    modal.classList.remove(
        "mostrar"
    );

    modal.setAttribute(
        "aria-hidden",
        "true"
    );

    document.body.style.overflow =
        "";

    formularioSeleccionado =
        null;
}


/* =====================================================
   CONFIRMAR OCULTAR
===================================================== */

function confirmarOcultar()
{
    if (
        formularioSeleccionado
    ) {

        formularioSeleccionado.submit();

    }
}


/* =====================================================
   CERRAR AL HACER CLIC FUERA DEL MODAL
===================================================== */

document
    .getElementById("modalOcultar")
    .addEventListener(
        "click",
        function(event)
        {

            if (
                event.target === this
            ) {

                cerrarModal();

            }

        }
    );


/* =====================================================
   CERRAR CON ESC
===================================================== */

document.addEventListener(
    "keydown",
    function(event)
    {

        if (
            event.key === "Escape"
        ) {

            cerrarModal();

        }

    }
);

</script>


</body>

</html>


<?php

$stmtHorarios->close();

?>
