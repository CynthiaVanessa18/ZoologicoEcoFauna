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
// VALIDAR ID
// =========================================================

if (!isset($_GET["id"]) || !is_numeric($_GET["id"])) {

    header("Location: cuidadores.php");
    exit();

}

$id_cuidador_animal = (int)$_GET["id"];

if ($id_cuidador_animal <= 0) {

    header("Location: cuidadores.php");
    exit();

}


// =========================================================
// OBTENER ASIGNACIÓN
// =========================================================

$sql = "
    SELECT

        ca.id_cuidador_animal,
        ca.id_cuidador,
        ca.id_animal,
        ca.fecha_asignacion,
        ca.fecha_finalizacion,
        ca.responsabilidad,
        ca.estado,

        c.estado AS estado_cuidador,

        a.nombre_animal AS nombre_animal,

        u.nombre

    FROM cuidador_animal ca

    INNER JOIN cuidador c
        ON c.id_cuidador = ca.id_cuidador

    INNER JOIN usuario u
        ON u.id_usuario = c.id_usuario

    INNER JOIN animal a
        ON a.id_animal = ca.id_animal

    WHERE ca.id_cuidador_animal = ?
";

$stmt = $conexion->prepare($sql);

$stmt->bind_param(
    "i",
    $id_cuidador_animal
);

$stmt->execute();

$resultado = $stmt->get_result();

if ($resultado->num_rows === 0) {

    header("Location: cuidadores.php");
    exit();

}

$asignacion = $resultado->fetch_assoc();

$stmt->close();


// =========================================================
// DATOS INICIALES
// =========================================================

$id_cuidador =
    (int)$asignacion["id_cuidador"];

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
// ACTUALIZAR ASIGNACIÓN
// =========================================================

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $fecha_finalizacion = trim($_POST["fecha_finalizacion"] ?? "");
    $responsabilidad = trim($_POST["responsabilidad"] ?? "");
    $estado = $_POST["estado"] ?? "";
    $enTransaccion = false;

    try {
        if (!solicitudCuidadoresValida()) {
            http_response_code(403);
            throw new RuntimeException(
                "La solicitud expiró. Recarga la página e inténtalo nuevamente.",
            );
        }

        if ($responsabilidad === "") {
            throw new RuntimeException("Debe indicar la responsabilidad.");
        }

        if (mb_strlen($responsabilidad) > 255) {
            throw new RuntimeException("La responsabilidad no puede superar 255 caracteres.");
        }

        if (!in_array($estado, ["Activo", "Finalizado"], true)) {
            throw new RuntimeException("El estado seleccionado no es válido.");
        }

        if ($estado === "Finalizado") {
            if (
                !fechaCuidadoresValida($fecha_finalizacion) ||
                $fecha_finalizacion < $asignacion["fecha_asignacion"] ||
                $fecha_finalizacion > date("Y-m-d")
            ) {
                throw new RuntimeException(
                    "La fecha de finalización debe estar entre la asignación y hoy.",
                );
            }
        } else {
            $fecha_finalizacion = null;
        }

        mysqli_begin_transaction($conexion);
        $enTransaccion = true;

        if ($estado === "Activo") {
            if ($asignacion["estado_cuidador"] !== "Activo") {
                throw new RuntimeException(
                    "No se puede reactivar una asignación de un cuidador inactivo.",
                );
            }

            $stmtConflicto = $conexion->prepare(
                "SELECT id_cuidador_animal
                 FROM cuidador_animal
                 WHERE id_cuidador = ? AND id_animal = ?
                   AND estado = 'Activo' AND id_cuidador_animal <> ?
                 LIMIT 1 FOR UPDATE",
            );
            if (!$stmtConflicto) {
                throw new RuntimeException("No fue posible verificar las asignaciones activas.");
            }
            $idAnimalAsignado = (int) $asignacion["id_animal"];
            $stmtConflicto->bind_param(
                "iii",
                $id_cuidador,
                $idAnimalAsignado,
                $id_cuidador_animal,
            );
            $stmtConflicto->execute();
            $asignacionActiva = $stmtConflicto->get_result()->fetch_assoc();
            $stmtConflicto->close();
            if ($asignacionActiva) {
                throw new RuntimeException(
                    "Ya existe otra asignación activa de este animal para el cuidador.",
                );
            }
        }

        $stmtActualizar = $conexion->prepare(
            "UPDATE cuidador_animal
             SET fecha_finalizacion = ?, responsabilidad = ?, estado = ?
             WHERE id_cuidador_animal = ?",
        );
        if (!$stmtActualizar) {
            throw new RuntimeException("No fue posible preparar la actualización.");
        }
        $stmtActualizar->bind_param(
            "sssi",
            $fecha_finalizacion,
            $responsabilidad,
            $estado,
            $id_cuidador_animal,
        );
        if (!$stmtActualizar->execute()) {
            throw new RuntimeException($stmtActualizar->error);
        }
        $stmtActualizar->close();

        if ($estado === "Finalizado") {
            $stmtTareas = $conexion->prepare(
                "UPDATE tarea SET estado = 'Finalizada'
                 WHERE id_cuidador_animal = ? AND estado = 'Activa'",
            );
            if (!$stmtTareas) {
                throw new RuntimeException("No fue posible cerrar las tareas de la asignación.");
            }
            $stmtTareas->bind_param("i", $id_cuidador_animal);
            if (!$stmtTareas->execute()) {
                throw new RuntimeException($stmtTareas->error);
            }
            $tareasFinalizadas = $stmtTareas->affected_rows;
            $stmtTareas->close();

            if (
                $tareasFinalizadas > 0 &&
                !registrarBitacora(
                    $_SESSION["usuario"],
                    "FINALIZAR TAREAS DE ASIGNACIÓN",
                    "tarea",
                    "Se finalizaron {$tareasFinalizadas} tareas de {$asignacion['nombre_animal']} al cerrar la asignación.",
                    null,
                )
            ) {
                throw new RuntimeException("No fue posible registrar las tareas en la bitácora.");
            }
        }

        $accionBitacora = $estado === "Finalizado"
            ? "FINALIZAR ASIGNACIÓN"
            : "ACTUALIZAR ASIGNACIÓN";
        $detalleBitacora = "Se actualizó la asignación de "
            . $asignacion["nombre_animal"]
            . " a "
            . $asignacion["nombre"]
            . " con estado "
            . $estado
            . ".";

        if (
            !registrarBitacora(
                $_SESSION["usuario"],
                $accionBitacora,
                "cuidador_animal",
                $detalleBitacora,
                $id_cuidador_animal,
            )
        ) {
            throw new RuntimeException("No fue posible registrar la acción en la bitácora.");
        }

        mysqli_commit($conexion);
        redirigirCuidadores(
            "editar_asignacion.php?id=" . $id_cuidador_animal,
            "La asignación fue actualizada correctamente.",
        );
    } catch (Throwable $excepcion) {
        if ($enTransaccion) {
            mysqli_rollback($conexion);
        }
        error_log("Error actualizando asignación: " . $excepcion->getMessage());
        $error = $excepcion->getMessage();
    }
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
        Editar asignación
    </title>

    <link
        rel="stylesheet"
        href="../css/cuidadores1.css"
    >

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
                Editar asignación
            </h1>

            <p class="subtitulo">
                Modifique la responsabilidad y el estado
                de la asignación del animal.
            </p>

        </div>


        <a
            href="cuidadores.php?id=<?= $id_cuidador ?>"
            class="boton volver"
        >
            ← Volver
        </a>

    </div>


    <!-- =================================================
         INFORMACIÓN DE LA ASIGNACIÓN
    ================================================== -->

    <div class="tarjeta">

        <h2 class="titulo">
            Información de la asignación
        </h2>


        <div class="datos">


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
                    Fecha de asignación
                </strong>

                <span>
                    <?= date(
                        "d/m/Y",
                        strtotime(
                            $asignacion["fecha_asignacion"]
                        )
                    ) ?>
                </span>

            </div>


        </div>

    </div>


    <!-- =================================================
         MENSAJES
    ================================================== -->

    <?php if ($mensaje): ?>

        <div class="mensaje">

            <?= escapar($mensaje) ?>

        </div>

    <?php endif; ?>


    <?php if ($error): ?>

        <div class="error">

            <?= escapar($error) ?>

        </div>

    <?php endif; ?>


    <!-- =================================================
         FORMULARIO
    ================================================== -->

    <div class="tarjeta">

        <h2 class="titulo">
            Actualizar asignación
        </h2>


        <?php if ($asignacion["estado"] === "Activo"): ?>

            <div class="mensaje">

                <strong>
                    Asignación activa
                </strong>

                <br><br>

                Mientras la asignación permanezca
                activa, la fecha de finalización
                permanecerá vacía.

                Para terminar la responsabilidad,
                seleccione <strong>Finalizado</strong>
                e indique la fecha correspondiente.

            </div>

        <?php endif; ?>


        <form
            method="POST"
            class="formulario"
        >
            <?= campoCsrfCuidadores() ?>


            <!-- =================================================
                 FECHA DE FINALIZACIÓN
            ================================================== -->

            <div>

                <label for="fecha_finalizacion">

                    Fecha de finalización

                </label>

                <input
                    type="date"
                    name="fecha_finalizacion"
                    id="fecha_finalizacion"
                    min="<?= escapar(
                        $asignacion["fecha_asignacion"]
                    ) ?>"
                    max="<?= date("Y-m-d") ?>"
                    value="<?= escapar(
                        $_POST["fecha_finalizacion"] ?? ($asignacion["fecha_finalizacion"] ?? "")
                    ) ?>"
                >

            </div>


            <!-- =================================================
                 ESTADO
            ================================================== -->

            <div>

                <label for="estado">

                    Estado

                </label>

                <select
                    name="estado"
                    id="estado"
                    required
                >

                    <option
                        value="Activo"
                        <?= ($_POST["estado"] ?? $asignacion["estado"]) === "Activo"
                            ? "selected"
                            : "" ?>
                    >
                        Activo
                    </option>

                    <option
                        value="Finalizado"
                        <?= ($_POST["estado"] ?? $asignacion["estado"]) === "Finalizado"
                            ? "selected"
                            : "" ?>
                    >
                        Finalizado
                    </option>

                </select>

            </div>


            <!-- =================================================
                 RESPONSABILIDAD
            ================================================== -->

            <div class="campo-completo">

                <label for="responsabilidad">

                    Responsabilidad

                </label>

                <textarea
                    name="responsabilidad"
                    id="responsabilidad"
                    maxlength="255"
                    required
                    placeholder="Describa la responsabilidad del cuidador..."
                ><?= escapar(
                    $_POST["responsabilidad"] ?? $asignacion["responsabilidad"]
                ) ?></textarea>

            </div>


            <!-- =================================================
                 BOTONES
            ================================================== -->

            <div class="botones">

                <button
                    type="submit"
                    class="boton guardar"
                >
                    Guardar cambios
                </button>


                <a
                    href="cuidador_ver.php?id=<?= $id_cuidador ?>"
                    class="boton volver"
                >
                    Cancelar
                </a>

            </div>


        </form>

    </div>


</div>


<!-- =====================================================
     JAVASCRIPT
====================================================== -->

<script>

document.addEventListener(
    "DOMContentLoaded",
    function () {

        const estado =
            document.getElementById("estado");

        const fecha =
            document.getElementById(
                "fecha_finalizacion"
            );


        function controlarFecha() {

            if (estado.value === "Finalizado") {

                fecha.required = true;

            } else {

                fecha.required = false;

                fecha.value = "";

            }

        }


        estado.addEventListener(
            "change",
            controlarFecha
        );


        controlarFecha();

    }
);

</script>


</body>

</html>
