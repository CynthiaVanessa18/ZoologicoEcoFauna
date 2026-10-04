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


if (!isset($_GET["id"]) || !is_numeric($_GET["id"])) {
    header("Location: cuidadores.php");
    exit();
}

$id_cuidador = (int)$_GET["id"];

if ($id_cuidador <= 0) {
    header("Location: cuidadores.php");
    exit();
}



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

$resultadoCuidador = $stmtCuidador->get_result();

if ($resultadoCuidador->num_rows === 0) {
    header("Location: cuidadores.php");
    exit();
}

$cuidador = $resultadoCuidador->fetch_assoc();

$stmtCuidador->close();


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



if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $id_animal = (int) ($_POST["id_animal"] ?? 0);
    $fecha_asignacion = trim($_POST["fecha_asignacion"] ?? "");
    $responsabilidad = trim($_POST["responsabilidad"] ?? "");
    $enTransaccion = false;

    try {
        if (!solicitudCuidadoresValida()) {
            http_response_code(403);
            throw new RuntimeException(
                "La solicitud expiró. Recarga la página e inténtalo nuevamente.",
            );
        }

        if ($cuidador["estado"] !== "Activo") {
            throw new RuntimeException("No se pueden asignar animales a un cuidador inactivo.");
        }

        if ($id_animal <= 0) {
            throw new RuntimeException("Debe seleccionar un animal.");
        }

        if (
            !fechaCuidadoresValida($fecha_asignacion) ||
            $fecha_asignacion > date("Y-m-d")
        ) {
            throw new RuntimeException("La fecha de asignación no es válida.");
        }

        if ($responsabilidad === "") {
            throw new RuntimeException("Debe indicar la responsabilidad del cuidador.");
        }

        if (mb_strlen($responsabilidad) > 255) {
            throw new RuntimeException("La responsabilidad no puede superar 255 caracteres.");
        }

        $stmtAnimal = $conexion->prepare(
            "SELECT nombre_animal FROM animal WHERE id_animal = ? LIMIT 1",
        );
        if (!$stmtAnimal) {
            throw new RuntimeException("No fue posible verificar el animal.");
        }
        $stmtAnimal->bind_param("i", $id_animal);
        $stmtAnimal->execute();
        $animal = $stmtAnimal->get_result()->fetch_assoc();
        $stmtAnimal->close();

        if (!$animal) {
            throw new RuntimeException("El animal seleccionado no existe.");
        }

        $stmtExiste = $conexion->prepare(
            "SELECT id_cuidador_animal
             FROM cuidador_animal
             WHERE id_cuidador = ? AND id_animal = ? AND estado = 'Activo'
             LIMIT 1",
        );
        if (!$stmtExiste) {
            throw new RuntimeException("No fue posible verificar la asignación.");
        }
        $stmtExiste->bind_param("ii", $id_cuidador, $id_animal);
        $stmtExiste->execute();
        $asignacionExistente = $stmtExiste->get_result()->fetch_assoc();
        $stmtExiste->close();

        if ($asignacionExistente) {
            throw new RuntimeException(
                "Este animal ya está asignado actualmente a este cuidador.",
            );
        }

        mysqli_begin_transaction($conexion);
        $enTransaccion = true;

        $stmtInsertar = $conexion->prepare(
            "INSERT INTO cuidador_animal
                (id_cuidador, id_animal, fecha_asignacion,
                 fecha_finalizacion, responsabilidad, estado)
             VALUES (?, ?, ?, NULL, ?, 'Activo')",
        );
        if (!$stmtInsertar) {
            throw new RuntimeException("No fue posible preparar la asignación.");
        }
        $stmtInsertar->bind_param(
            "iiss",
            $id_cuidador,
            $id_animal,
            $fecha_asignacion,
            $responsabilidad,
        );
        if (!$stmtInsertar->execute()) {
            throw new RuntimeException($stmtInsertar->error);
        }
        $idAsignacion = $conexion->insert_id;
        $stmtInsertar->close();

        if (
            !registrarBitacora(
                $_SESSION["usuario"],
                "ASIGNAR ANIMAL",
                "cuidador_animal",
                "Se asignó {$animal['nombre_animal']} a {$cuidador['nombre']}.",
                $idAsignacion,
            )
        ) {
            throw new RuntimeException("No fue posible registrar la acción en la bitácora.");
        }

        mysqli_commit($conexion);
        redirigirCuidadores(
            "asignar_animal.php?id=" . $id_cuidador,
            "El animal fue asignado correctamente al cuidador.",
        );
    } catch (Throwable $excepcion) {
        if ($enTransaccion) {
            mysqli_rollback($conexion);
        }
        error_log("Error asignando animal: " . $excepcion->getMessage());
        $error = $excepcion->getMessage();
    }
}



$sqlAnimalesDisponibles = "
    SELECT
        a.id_animal,
        a.nombre_animal
    FROM animal a
    WHERE NOT EXISTS (

        SELECT 1

        FROM cuidador_animal ca

        WHERE ca.id_cuidador = ?
        AND ca.id_animal = a.id_animal
        AND ca.estado = 'Activo'
    )

    ORDER BY a.nombre_animal
";

$stmtDisponibles =
    $conexion->prepare(
        $sqlAnimalesDisponibles
    );

$stmtDisponibles->bind_param(
    "i",
    $id_cuidador
);

$stmtDisponibles->execute();

$animalesDisponibles =
    $stmtDisponibles->get_result();



$sqlAsignaciones = "
    SELECT

        ca.id_cuidador_animal,
        ca.fecha_asignacion,
        ca.fecha_finalizacion,
        ca.responsabilidad,
        ca.estado,

        a.nombre_animal AS nombre_animal

    FROM cuidador_animal ca

    INNER JOIN animal a
        ON a.id_animal = ca.id_animal

    WHERE ca.id_cuidador = ?

    ORDER BY
        CASE
            WHEN ca.estado = 'Activo' THEN 1
            ELSE 2
        END,
        ca.fecha_asignacion DESC
";

$stmtAsignaciones =
    $conexion->prepare(
        $sqlAsignaciones
    );

$stmtAsignaciones->bind_param(
    "i",
    $id_cuidador
);

$stmtAsignaciones->execute();

$asignaciones =
    $stmtAsignaciones->get_result();

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
    Asignar animal - EcoFauna
</title>



<link rel="preconnect" href="https://fonts.googleapis.com">

<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

<link
    href="https://fonts.googleapis.com/css2?family=DM+Serif+Display&family=Poppins:wght@400;500;600;700&display=swap"
    rel="stylesheet"
>


<link
    rel="stylesheet"
    href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css"
>

<link rel="stylesheet" href="../css/cuidadores1.css">

<!-- =================================================
     CSS
================================================== -->

    <link rel="stylesheet" href="../css/crud-modern.css?v=1">
    <link rel="stylesheet" href="../css/ecofauna-unified.css?v=1">
</head>

<body class="ecofauna-unified crud-modern">



<div class="contenedor">

<!--  ENCABEZADO -->

<div class="encabezado">

    <div>

        <h1>

            <i class="bi bi-person-plus-fill"></i>

            Asignar animal

        </h1>

        <p class="subtitulo">

            Asigne animales bajo la responsabilidad
            del cuidador seleccionado.

        </p>

    </div>


    <a
        href="cuidadores.php?id=<?= $id_cuidador ?>"
        class="boton volver"
    >

        <i class="bi bi-arrow-left"></i>

        Volver

    </a>

</div>



<!-- =================================================
     DATOS DEL CUIDADOR
================================================== -->

<div class="tarjeta">

    <h2 class="titulo">

        <i class="bi bi-person-badge"></i>

        Cuidador

    </h2>


    <div class="datos">


        <div class="dato">

            <strong>

                <i class="bi bi-person"></i>

                Nombre

            </strong>

            <?= escapar(
                $cuidador["nombre"] . " "
            ) ?>

        </div>


        <div class="dato">

            <strong>

                <i class="bi bi-award"></i>

                Especialidad

            </strong>

            <?= escapar(
                $cuidador["especialidad"]
                ?: "Sin especialidad"
            ) ?>

        </div>


        <div class="dato">

            <strong>

                <i class="bi bi-circle-fill"></i>

                Estado

            </strong>

            <?= escapar(
                $cuidador["estado"]
            ) ?>

        </div>


    </div>

</div>



<!-- =================================================
     MENSAJES
================================================== -->

<?php if ($mensaje): ?>

    <div class="mensaje">

        <i class="bi bi-check-circle-fill"></i>

        <?= escapar($mensaje) ?>

    </div>

<?php endif; ?>


<?php if ($error): ?>

    <div class="error">

        <i class="bi bi-exclamation-triangle-fill"></i>

        <?= escapar($error) ?>

    </div>

<?php endif; ?>



<!-- =================================================
     FORMULARIO
================================================== -->

<div class="tarjeta">

    <h2 class="titulo">

        <i class="bi bi-plus-circle"></i>

        Nueva asignación

    </h2>


    <form
        method="POST"
        class="formulario"
    >
        <?= campoCsrfCuidadores() ?>


        <div>

            <label for="id_animal">

                <i class="bi bi-paw-fill"></i>

                Animal

            </label>

            <select
                name="id_animal"
                id="id_animal"
                required
            >

                <option value="">

                    Seleccione un animal

                </option>

                <?php while (
                    $animal =
                    $animalesDisponibles->fetch_assoc()
                ): ?>

                    <option
                        value="<?= $animal["id_animal"] ?>"
                        <?= (int) $animal["id_animal"] === (int) ($_POST["id_animal"] ?? 0) ? "selected" : "" ?>
                    >

                        <?= escapar(
                            $animal["nombre_animal"]
                        ) ?>

                    </option>

                <?php endwhile; ?>

            </select>

        </div>


        <div>

            <label for="fecha_asignacion">

                <i class="bi bi-calendar-event"></i>

                Fecha de asignación

            </label>

            <input
                type="date"
                name="fecha_asignacion"
                id="fecha_asignacion"
                max="<?= date("Y-m-d") ?>"
                value="<?= escapar($_POST["fecha_asignacion"] ?? date("Y-m-d")) ?>"
                required
            >

        </div>


        <div class="campo-completo">

            <label for="responsabilidad">

                <i class="bi bi-clipboard-check"></i>

                Responsabilidad

            </label>

            <textarea
                name="responsabilidad"
                id="responsabilidad"
                maxlength="255"
                placeholder="Indique las responsabilidades del cuidador sobre este animal..."
                required
            ><?= escapar($_POST["responsabilidad"] ?? "") ?></textarea>

        </div>


        <div class="campo-completo">

            <button
                type="submit"
                class="boton guardar"
            >

                <i class="bi bi-check-circle-fill"></i>

                Asignar animal

            </button>

        </div>

    </form>

</div>



<!-- =================================================
     ASIGNACIONES
================================================== -->

<div class="tarjeta">

    <h2 class="titulo">

        <i class="bi bi-list-check"></i>

        Animales asignados al cuidador

    </h2>


    <div class="tabla-contenedor">

        <table>

            <thead>

                <tr>

                    <th>
                        Animal
                    </th>

                    <th>
                        Fecha asignación
                    </th>

                    <th>
                        Fecha finalización
                    </th>

                    <th>
                        Responsabilidad
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
                $asignaciones->num_rows > 0
            ): ?>

                <?php while (
                    $asignacion =
                    $asignaciones->fetch_assoc()
                ): ?>

                    <tr>

                        <td>

                            <strong>

                                <i class="bi bi-paw-fill"></i>

                                <?= escapar(
                                    $asignacion[
                                        "nombre_animal"
                                    ]
                                ) ?>

                            </strong>

                        </td>


                        <td>

                            <i class="bi bi-calendar3"></i>

                            <?= date(
                                "d/m/Y",
                                strtotime(
                                    $asignacion[
                                        "fecha_asignacion"
                                    ]
                                )
                            ) ?>

                        </td>


                        <td>

                            <?php if (
                                !empty(
                                    $asignacion[
                                        "fecha_finalizacion"
                                    ]
                                )
                            ): ?>

                                <i class="bi bi-calendar-check"></i>

                                <?= date(
                                    "d/m/Y",
                                    strtotime(
                                        $asignacion[
                                            "fecha_finalizacion"
                                        ]
                                    )
                                ) ?>

                            <?php else: ?>

                                —

                            <?php endif; ?>

                        </td>


                        <td>

                            <?= nl2br(
                                escapar(
                                    $asignacion[
                                        "responsabilidad"
                                    ]
                                )
                            ) ?>

                        </td>


                        <td>

                            <?php if (
                                $asignacion["estado"]
                                === "Activo"
                            ): ?>

                                <span
                                    class="estado activo"
                                >

                                    <i class="bi bi-circle-fill"></i>

                                    Activo

                                </span>

                            <?php else: ?>

                                <span
                                    class="estado finalizado"
                                >

                                    <i class="bi bi-circle-fill"></i>

                                    Finalizado

                                </span>

                            <?php endif; ?>

                        </td>


                        <td>

                            <a
                                href="editar_asignacion.php?id=<?= $asignacion["id_cuidador_animal"] ?>"
                                class="boton editar"
                            >

                                <i class="bi bi-pencil"></i>

                                Editar

                            </a>

                        </td>

                    </tr>

                <?php endwhile; ?>

            <?php else: ?>

                <tr>

                    <td
                        colspan="6"
                        class="sin-registros"
                    >

                        <i class="bi bi-inbox"></i>

                        <br>

                        Este cuidador todavía no tiene
                        animales asignados.

                    </td>

                </tr>

            <?php endif; ?>

            </tbody>

        </table>

    </div>

</div>

</div>

</body>

</html>

<?php

$stmtDisponibles->close();

$stmtAsignaciones->close();

?>
