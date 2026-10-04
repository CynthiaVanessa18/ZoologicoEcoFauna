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


/* =====================================================
   VALIDAR ID
===================================================== */

$id_cuidador = isset($_GET["id"])
    ? (int) $_GET["id"]
    : 0;

if ($id_cuidador <= 0) {
    header("Location: cuidadores.php");
    exit();
}


/* =====================================================
   FUNCIONES
===================================================== */

function escapar($texto)
{
    return htmlspecialchars(
        $texto ?? "",
        ENT_QUOTES,
        "UTF-8"
    );
}


/* =====================================================
   ACTUALIZAR CUIDADOR
===================================================== */

$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $id_usuario = (int) ($_POST["id_usuario"] ?? 0);
    $fecha_contratacion = trim($_POST["fecha_contratacion"] ?? "");
    $especialidad = trim($_POST["especialidad"] ?? "");
    $estado = $_POST["estado"] ?? "Activo";
    $enTransaccion = false;

    try {
        if (!solicitudCuidadoresValida()) {
            http_response_code(403);
            throw new RuntimeException(
                "La solicitud expiró. Recarga la página e inténtalo nuevamente.",
            );
        }

        if ($id_usuario <= 0 || $fecha_contratacion === "") {
            throw new RuntimeException("Complete los campos obligatorios.");
        }

        if (
            !fechaCuidadoresValida($fecha_contratacion) ||
            $fecha_contratacion > date("Y-m-d")
        ) {
            throw new RuntimeException("La fecha de contratación no es válida.");
        }

        if (mb_strlen($especialidad) > 100) {
            throw new RuntimeException("La especialidad no puede superar 100 caracteres.");
        }

        if (!in_array($estado, ["Activo", "Inactivo"], true)) {
            throw new RuntimeException("El estado seleccionado no es válido.");
        }

        $stmtElegible = $conexion->prepare(
            "SELECT u.nombre
             FROM usuario u
             INNER JOIN usuarios_rol ur ON ur.id_usuario = u.id_usuario
             INNER JOIN rol r ON r.id_rol = ur.id_rol
             LEFT JOIN cuidador c
                ON c.id_usuario = u.id_usuario
               AND c.id_cuidador <> ?
             WHERE u.id_usuario = ?
               AND r.nombre_rol IN ('Empleado', 'Veterinario')
               AND c.id_cuidador IS NULL
             LIMIT 1",
        );

        if (!$stmtElegible) {
            throw new RuntimeException("No fue posible verificar el usuario seleccionado.");
        }

        $stmtElegible->bind_param("ii", $id_cuidador, $id_usuario);
        $stmtElegible->execute();
        $usuarioElegible = $stmtElegible->get_result()->fetch_assoc();
        $stmtElegible->close();

        if (!$usuarioElegible) {
            throw new RuntimeException(
                "El usuario no existe, ya pertenece a otro cuidador o no tiene un rol autorizado.",
            );
        }

        mysqli_begin_transaction($conexion);
        $enTransaccion = true;

        $stmtObjetivo = $conexion->prepare(
            "SELECT id_cuidador FROM cuidador WHERE id_cuidador = ? FOR UPDATE",
        );
        if (!$stmtObjetivo) {
            throw new RuntimeException("No fue posible verificar el cuidador.");
        }
        $stmtObjetivo->bind_param("i", $id_cuidador);
        $stmtObjetivo->execute();
        $cuidadorObjetivo = $stmtObjetivo->get_result()->fetch_assoc();
        $stmtObjetivo->close();
        if (!$cuidadorObjetivo) {
            throw new RuntimeException("El cuidador seleccionado no existe.");
        }

        $stmtActualizar = $conexion->prepare(
            "UPDATE cuidador
             SET id_usuario = ?,
                 fecha_contratacion = ?,
                 especialidad = NULLIF(?, ''),
                 estado = ?
             WHERE id_cuidador = ?",
        );

        if (!$stmtActualizar) {
            throw new RuntimeException("No fue posible preparar la actualización del cuidador.");
        }

        $stmtActualizar->bind_param(
            "isssi",
            $id_usuario,
            $fecha_contratacion,
            $especialidad,
            $estado,
            $id_cuidador,
        );

        if (!$stmtActualizar->execute()) {
            throw new RuntimeException($stmtActualizar->error);
        }
        $stmtActualizar->close();

        if (
            !registrarBitacora(
                $_SESSION["usuario"],
                "ACTUALIZAR CUIDADOR",
                "cuidador",
                "Se actualizó a {$usuarioElegible['nombre']} con estado {$estado}.",
                $id_cuidador,
            )
        ) {
            throw new RuntimeException("No fue posible registrar la acción en la bitácora.");
        }

        mysqli_commit($conexion);
        redirigirCuidadores("cuidadores.php", "Cuidador actualizado correctamente.");
    } catch (Throwable $excepcion) {
        if ($enTransaccion) {
            mysqli_rollback($conexion);
        }

        error_log("Error actualizando cuidador: " . $excepcion->getMessage());
        $error = str_contains($excepcion->getMessage(), "Duplicate entry")
            ? "El usuario seleccionado ya está registrado como cuidador."
            : $excepcion->getMessage();
    }
}


/* =====================================================
   OBTENER DATOS DEL CUIDADOR
===================================================== */

$sqlCuidador = "
    SELECT
        c.id_cuidador,
        c.id_usuario,
        c.fecha_contratacion,
        c.especialidad,
        c.estado,
        u.nombre
    FROM cuidador c
    INNER JOIN usuario u
        ON u.id_usuario = c.id_usuario
    WHERE c.id_cuidador = ?
";


$stmtCuidador =
    $conexion->prepare($sqlCuidador);

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


/* =====================================================
   USUARIOS DISPONIBLES
===================================================== */

$sqlUsuarios = "
    SELECT DISTINCT
        u.id_usuario,
        u.nombre,
        r.nombre_rol
    FROM usuario u
    INNER JOIN usuarios_rol ur
        ON ur.id_usuario = u.id_usuario
    INNER JOIN rol r
        ON r.id_rol = ur.id_rol
    LEFT JOIN cuidador c
        ON c.id_usuario = u.id_usuario
        AND c.id_cuidador <> ?
    WHERE c.id_cuidador IS NULL
      AND r.nombre_rol IN ('Empleado', 'Veterinario')
    ORDER BY u.nombre
";


$stmtUsuarios =
    $conexion->prepare($sqlUsuarios);

$stmtUsuarios->bind_param(
    "i",
    $id_cuidador
);

$stmtUsuarios->execute();

$usuarios =
    $stmtUsuarios->get_result();

?>
<!DOCTYPE html>
<html lang="es">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Editar cuidador</title>

    <link
        rel="stylesheet"
        href="../css/cuidadores1.css"
    >

    <link rel="stylesheet" href="../css/crud-modern.css?v=1">
    <link rel="stylesheet" href="../css/ecofauna-unified.css?v=1">
</head>

<body class="ecofauna-unified crud-modern">



<div class="contenedor">

    <!-- =========================================
         ENCABEZADO
    ========================================== -->

    <section class="encabezado">

        <div>

            <h1>
                Editar cuidador
            </h1>

            <p class="subtitulo">
                Modifique la información del cuidador
                seleccionado.
            </p>

        </div>

        <a
            href="cuidadores.php"
            class="boton volver"
        >
            ← Volver
        </a>

    </section>


    <!-- =========================================
         MENSAJE DE ERROR
    ========================================== -->

    <?php if (!empty($error)): ?>

        <div class="error">

            <?= escapar($error) ?>

        </div>

    <?php endif; ?>


    <!-- =========================================
         FORMULARIO
    ========================================== -->

    <section class="tarjeta">

        <h2 class="titulo">
            Información del cuidador
        </h2>


        <form
            method="POST"
            class="formulario"
        >
            <?= campoCsrfCuidadores() ?>

            <!-- =====================================
                 USUARIO
            ====================================== -->

            <div class="campo-completo">

                <label for="id_usuario">
                    Usuario
                </label>

                <select
                    name="id_usuario"
                    id="id_usuario"
                    required
                >

                    <option value="">
                        Seleccione un usuario
                    </option>

                    <?php while (
                        $usuario =
                            $usuarios->fetch_assoc()
                    ): ?>

                        <option
                            value="<?= $usuario["id_usuario"] ?>"
                            <?= (
                                $usuario["id_usuario"]
                                == ($_POST["id_usuario"] ?? $cuidador["id_usuario"])
                            )
                                ? "selected"
                                : ""
                            ?>
                        >

                            <?= escapar(
                                $usuario["nombre"]
                            ) ?>
                            (<?= escapar($usuario["nombre_rol"]) ?>)

                        </option>

                    <?php endwhile; ?>

                </select>

            </div>


            <!-- =====================================
                 FECHA
            ====================================== -->

            <div>

                <label for="fecha_contratacion">
                    Fecha de contratación
                </label>

                <input
                    type="date"
                    name="fecha_contratacion"
                    id="fecha_contratacion"
                    max="<?= date("Y-m-d") ?>"
                    value="<?= escapar(
                        $_POST["fecha_contratacion"] ?? $cuidador["fecha_contratacion"]
                    ) ?>"
                    required
                >

            </div>


            <!-- =====================================
                 ESTADO
            ====================================== -->

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
                        <?= ($_POST["estado"] ?? $cuidador["estado"]) === "Activo"
                            ? "selected"
                            : ""
                        ?>
                    >
                        Activo
                    </option>

                    <option
                        value="Inactivo"
                        <?= ($_POST["estado"] ?? $cuidador["estado"]) === "Inactivo"
                            ? "selected"
                            : ""
                        ?>
                    >
                        Inactivo
                    </option>

                </select>

            </div>


            <!-- =====================================
                 ESPECIALIDAD
            ====================================== -->

            <div class="campo-completo">

                <label for="especialidad">
                    Especialidad
                </label>

                <input
                    type="text"
                    name="especialidad"
                    id="especialidad"
                    maxlength="100"
                    placeholder="Ej. Mamíferos"
                    value="<?= escapar(
                        $_POST["especialidad"] ?? $cuidador["especialidad"]
                    ) ?>"
                >

            </div>


            <!-- =====================================
                 BOTONES
            ====================================== -->

            <div class="acciones-formulario campo-completo">

                <a
                    href="cuidadores.php"
                    class="boton volver-formulario"
                >
                    Cancelar
                </a>

                <button
                    type="submit"
                    class="boton guardar"
                >
                    Guardar cambios
                </button>

            </div>

        </form>

    </section>

</div>

</body>

</html>
