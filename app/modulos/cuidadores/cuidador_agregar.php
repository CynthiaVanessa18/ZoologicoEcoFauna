
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

$error = "";
$id_usuario = 0;
$fecha_contratacion = "";
$especialidad = "";
$estado = "Activo";

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
             LEFT JOIN cuidador c ON c.id_usuario = u.id_usuario
             WHERE u.id_usuario = ?
               AND r.nombre_rol IN ('Empleado', 'Veterinario')
               AND c.id_cuidador IS NULL
             LIMIT 1",
        );

        if (!$stmtElegible) {
            throw new RuntimeException("No fue posible verificar el usuario seleccionado.");
        }

        $stmtElegible->bind_param("i", $id_usuario);
        $stmtElegible->execute();
        $usuarioElegible = $stmtElegible->get_result()->fetch_assoc();
        $stmtElegible->close();

        if (!$usuarioElegible) {
            throw new RuntimeException(
                "El usuario no existe, ya es cuidador o no pertenece al personal autorizado.",
            );
        }

        mysqli_begin_transaction($conexion);
        $enTransaccion = true;

        $stmt = $conexion->prepare(
            "INSERT INTO cuidador
                (id_usuario, fecha_contratacion, especialidad, estado)
             VALUES (?, ?, NULLIF(?, ''), ?)",
        );

        if (!$stmt) {
            throw new RuntimeException("No fue posible preparar el registro del cuidador.");
        }

        $stmt->bind_param(
            "isss",
            $id_usuario,
            $fecha_contratacion,
            $especialidad,
            $estado,
        );

        if (!$stmt->execute()) {
            throw new RuntimeException($stmt->error);
        }

        $idCuidadorNuevo = $conexion->insert_id;
        $stmt->close();

        if (
            !registrarBitacora(
                $_SESSION["usuario"],
                "REGISTRAR CUIDADOR",
                "cuidador",
                "Se registró como cuidador a " . $usuarioElegible["nombre"] . ".",
                $idCuidadorNuevo,
            )
        ) {
            throw new RuntimeException("No fue posible registrar la acción en la bitácora.");
        }

        mysqli_commit($conexion);
        redirigirCuidadores(
            "cuidadores.php",
            "Cuidador registrado correctamente.",
        );
    } catch (Throwable $excepcion) {
        if ($enTransaccion) {
            mysqli_rollback($conexion);
        }

        error_log("Error registrando cuidador: " . $excepcion->getMessage());
        $error = str_contains($excepcion->getMessage(), "Duplicate entry")
            ? "El usuario seleccionado ya está registrado como cuidador."
            : $excepcion->getMessage();
    }
}

/*
 * Usuarios que todavía no tienen registro como cuidador.
 */
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
    WHERE c.id_cuidador IS NULL
      AND r.nombre_rol IN ('Empleado', 'Veterinario')
    ORDER BY u.nombre
";

$usuarios = $conexion->query($sqlUsuarios);

?>

<!DOCTYPE html>
<html lang="es">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Registrar cuidador</title>

    <link rel="stylesheet" href="../css/cuidadores1.css">

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

            <h1>Registrar cuidador</h1>

            <p class="subtitulo">
                Registre un nuevo cuidador en el sistema,
                asignando su usuario, fecha de contratación,
                especialidad y estado.
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

            <?= htmlspecialchars($error) ?>

        </div>

    <?php endif; ?>


    <!-- =========================================
         FORMULARIO
    ========================================== -->

    <section class="tarjeta">

        <h2 class="titulo">
            Información del cuidador
        </h2>


        <form method="POST" class="formulario">
            <?= campoCsrfCuidadores() ?>

            <!-- USUARIO -->

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

                    <?php while ($usuario = $usuarios->fetch_assoc()): ?>

                        <option
                            value="<?= $usuario['id_usuario'] ?>"
                            <?= (int) $usuario['id_usuario'] === $id_usuario ? "selected" : "" ?>
                        >
                            <?= htmlspecialchars($usuario['nombre']) ?>
                            (<?= htmlspecialchars($usuario['nombre_rol']) ?>)
                        </option>

                    <?php endwhile; ?>

                </select>

            </div>


            <!-- FECHA -->

            <div>

                <label for="fecha_contratacion">
                    Fecha de contratación
                </label>

                <input
                    type="date"
                    name="fecha_contratacion"
                    id="fecha_contratacion"
                    max="<?= date("Y-m-d") ?>"
                    value="<?= htmlspecialchars($fecha_contratacion, ENT_QUOTES, "UTF-8") ?>"
                    required
                >

            </div>


            <!-- ESTADO -->

            <div>

                <label for="estado">
                    Estado
                </label>

                <select
                    name="estado"
                    id="estado"
                >

                    <option value="Activo" <?= $estado === "Activo" ? "selected" : "" ?>>
                        Activo
                    </option>

                    <option value="Inactivo" <?= $estado === "Inactivo" ? "selected" : "" ?>>
                        Inactivo
                    </option>

                </select>

            </div>


            <!-- ESPECIALIDAD -->

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
                    value="<?= htmlspecialchars($especialidad, ENT_QUOTES, "UTF-8") ?>"
                >

            </div>


            <!-- BOTONES -->

            <div class="campo-completo acciones-formulario">

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
                    Guardar cuidador
                </button>

            </div>

        </form>

    </section>

</div>

</body>

</html>
