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

    return date(
        "d/m/Y",
        $timestamp
    );
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

    return date(
        "h:i A",
        $timestamp
    );
}


// =====================================================
// VALIDAR ID DEL CUIDADOR
// =====================================================

if (
    !isset($_GET["id"]) ||
    !is_numeric($_GET["id"])
) {

    header(
        "Location: cuidadores.php"
    );

    exit;
}


$id_cuidador = (int) $_GET["id"];


if ($id_cuidador <= 0) {

    header(
        "Location: cuidadores.php"
    );

    exit;
}


// =====================================================
// MENSAJES
// =====================================================

$mensaje = "";
$error = "";


// =====================================================
// OBTENER CUIDADOR
// =====================================================

$sqlCuidador = "
    SELECT
        c.id_cuidador,
        u.nombre,
        c.fecha_contratacion,
        c.especialidad,
        c.estado
    FROM cuidador c
    INNER JOIN usuario u
        ON c.id_usuario = u.id_usuario
    WHERE c.id_cuidador = ?
    LIMIT 1
";


$stmtCuidador =
    $conexion->prepare(
        $sqlCuidador
    );


if (!$stmtCuidador) {

    die(
        "Error al preparar la consulta del cuidador."
    );
}


$stmtCuidador->bind_param(
    "i",
    $id_cuidador
);


$stmtCuidador->execute();


$resultadoCuidador =
    $stmtCuidador->get_result();


if (
    $resultadoCuidador->num_rows === 0
) {

    header(
        "Location: cuidadores.php"
    );

    exit;
}


$cuidador =
    $resultadoCuidador->fetch_assoc();


$stmtCuidador->close();


// =====================================================
// HORARIOS
// =====================================================

$sqlHorarios = "
    SELECT
        id_horario,
        id_cuidador,
        dia_semana,
        hora_entrada,
        hora_salida
    FROM horario_cuidador
    WHERE id_cuidador = ?
      AND estado = 'activo'
    ORDER BY
        FIELD(
            dia_semana,
            'Lunes',
            'Martes',
            'Miercoles',
            'Jueves',
            'Viernes',
            'Sabado',
            'Domingo'
        ),
        hora_entrada
";


$stmtHorarios =
    $conexion->prepare(
        $sqlHorarios
    );


if (!$stmtHorarios) {

    die(
        "Error al preparar la consulta de horarios."
    );
}


$stmtHorarios->bind_param(
    "i",
    $id_cuidador
);


$stmtHorarios->execute();


$horarios =
    $stmtHorarios->get_result();


// =====================================================
// ANIMALES ASIGNADOS
// =====================================================

$sqlAnimales = "
    SELECT
        ca.id_cuidador_animal,
        ca.id_cuidador,
        ca.id_animal,
        ca.fecha_asignacion,
        ca.fecha_finalizacion,
        ca.responsabilidad,
        ca.estado,
        a.nombre_animal
    FROM cuidador_animal ca
    INNER JOIN animal a
        ON a.id_animal = ca.id_animal
    WHERE ca.id_cuidador = ?
    ORDER BY
        CASE
            WHEN ca.estado = 'Activo'
            THEN 0
            ELSE 1
        END,
        ca.fecha_asignacion DESC
";


$stmtAnimales =
    $conexion->prepare(
        $sqlAnimales
    );


if (!$stmtAnimales) {

    die(
        "Error al preparar la consulta de animales."
    );
}


$stmtAnimales->bind_param(
    "i",
    $id_cuidador
);


$stmtAnimales->execute();


$animales =
    $stmtAnimales->get_result();


// =====================================================
// TAREAS DEL CUIDADOR
//
// Cada tarea está relacionada con
// id_cuidador_animal.
// =====================================================

$sqlTareas = "
    SELECT
        t.id_cuidador_animal,
        a.nombre_animal,
        t.nombre_tarea,
        t.descripcion,
        t.frecuencia,
        t.hora_programada,
        t.observaciones,
        t.estado
    FROM tarea t

    INNER JOIN cuidador_animal ca
        ON ca.id_cuidador_animal =
           t.id_cuidador_animal

    INNER JOIN animal a
        ON a.id_animal =
           ca.id_animal

    WHERE ca.id_cuidador = ?

    ORDER BY
        a.nombre_animal ASC,
        t.hora_programada ASC
";


$stmtTareas =
    $conexion->prepare(
        $sqlTareas
    );


if (!$stmtTareas) {

    die(
        "Error al preparar la consulta de tareas."
    );
}


$stmtTareas->bind_param(
    "i",
    $id_cuidador
);


$stmtTareas->execute();


$tareas =
    $stmtTareas->get_result();

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
        Detalle del cuidador
    </title>


    <!-- =================================================
         FUENTES
    ================================================== -->

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


    <!-- =================================================
         CSS
    ================================================== -->

    <link
        rel="stylesheet"
        href="../css/cuidadores1.css"
    >

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
            Detalle del cuidador
        </h1>

        <p class="subtitulo">
            Información completa del cuidador,
            horario, animales asignados y tareas.
        </p>

    </div>


    <div class="acciones">

        <a
            href="cuidadores.php"
            class="boton volver"
        >
            ← Volver
        </a>


        <a
            href="cuidador_editar.php?id=<?= $id_cuidador ?>"
            class="boton editar"
        >
            Editar cuidador
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
     INFORMACIÓN DEL CUIDADOR
===================================================== -->

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
                Fecha de contratación
            </strong>

            <span>
                <?= fechaMostrar(
                    $cuidador[
                        "fecha_contratacion"
                    ]
                ) ?>
            </span>

        </div>


        <div class="dato">

            <strong>
                Especialidad
            </strong>

            <span>
                <?= escapar(
                    $cuidador[
                        "especialidad"
                    ]
                    ?: "Sin especialidad"
                ) ?>
            </span>

        </div>


        <div class="dato">

            <strong>
                Estado
            </strong>


            <?php if (
                $cuidador["estado"]
                === "Activo"
            ): ?>

                <span
                    class="estado activo"
                >
                    Activo
                </span>

            <?php else: ?>

                <span
                    class="estado finalizado"
                >
                    Inactivo
                </span>

            <?php endif; ?>

        </div>

    </div>

</div>


<!-- =====================================================
     HORARIO
===================================================== -->

<div class="tarjeta">

    <h2 class="titulo">
        Horario laboral
    </h2>


    <div
        style="
            padding: 0 28px 22px;
        "
    >

        <a
            href="horario_cuidador.php?id=<?= $id_cuidador ?>"
            class="boton guardar"
        >
            Administrar horario
        </a>

    </div>


    <div class="tabla-contenedor">

        <table>

            <thead>

                <tr>

                    <th>
                        Día
                    </th>

                    <th>
                        Hora de entrada
                    </th>

                    <th>
                        Hora de salida
                    </th>

                    <th>
                        Jornada
                    </th>

                </tr>

            </thead>


            <tbody>

            <?php if (
                $horarios->num_rows > 0
            ): ?>

                <?php while (
                    $horario =
                        $horarios->fetch_assoc()
                ): ?>

                    <tr>

                        <td>

                            <?= escapar(
                                $horario[
                                    "dia_semana"
                                ]
                            ) ?>

                        </td>


                        <td>

                            <?= horaMostrar(
                                $horario[
                                    "hora_entrada"
                                ]
                            ) ?>

                        </td>


                        <td>

                            <?= horaMostrar(
                                $horario[
                                    "hora_salida"
                                ]
                            ) ?>

                        </td>


                        <td>

                            <?php

                            $entrada =
                                strtotime(
                                    $horario[
                                        "hora_entrada"
                                    ]
                                );

                            $salida =
                                strtotime(
                                    $horario[
                                        "hora_salida"
                                    ]
                                );


                            $horas =
                                (
                                    $salida
                                    - $entrada
                                ) / 3600;


                            if (
                                $horas < 0
                            ) {

                                $horas += 24;

                            }


                            echo number_format(
                                $horas,
                                2
                            );

                            ?>

                            horas

                        </td>

                    </tr>

                <?php endwhile; ?>

            <?php else: ?>

                <tr>

                    <td
                        colspan="4"
                        class="sin-registros"
                    >
                        No hay horarios registrados
                        para este cuidador.
                    </td>

                </tr>

            <?php endif; ?>

            </tbody>

        </table>

    </div>

</div>


<!-- =====================================================
     ANIMALES ASIGNADOS
===================================================== -->

<div class="tarjeta">


    <!-- ENCABEZADO -->

    <div
        style="
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:15px;
        "
    >

        <h2 class="titulo">
            Animales asignados
        </h2>


        <?php if (
            $cuidador["estado"]
            === "Activo"
        ): ?>

            <a
                href="asignar_animal.php?id=<?= $id_cuidador ?>"
                class="boton guardar"
                style="
                    margin-right:28px;
                "
            >
                + Asignar animal
            </a>

        <?php endif; ?>

    </div>


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
                        Acciones
                    </th>

                </tr>

            </thead>


            <tbody>

            <?php if (
                $animales->num_rows > 0
            ): ?>

                <?php while (
                    $animal =
                        $animales->fetch_assoc()
                ): ?>

                    <tr>

                        <!-- =================================
                             ANIMAL
                        ================================== -->

                        <td>

                            <strong>

                                <?= escapar(
                                    $animal[
                                        "nombre_animal"
                                    ]
                                ) ?>

                            </strong>

                        </td>


                        <!-- =================================
                             FECHA ASIGNACIÓN
                        ================================== -->

                        <td>

                            <?= fechaMostrar(
                                $animal[
                                    "fecha_asignacion"
                                ]
                            ) ?>

                        </td>


                        <!-- =================================
                             FECHA FINALIZACIÓN
                        ================================== -->

                        <td>

                            <?= fechaMostrar(
                                $animal[
                                    "fecha_finalizacion"
                                ]
                            ) ?>

                        </td>


                        <!-- =================================
                             RESPONSABILIDAD
                        ================================== -->

                        <td>

                            <?= nl2br(
                                escapar(
                                    $animal[
                                        "responsabilidad"
                                    ]
                                )
                            ) ?>

                        </td>


                        <!-- =================================
                             ESTADO
                        ================================== -->

                        <td>

                            <?php if (
                                $animal["estado"]
                                === "Activo"
                            ): ?>

                                <span
                                    class="estado activo"
                                >
                                    Activo
                                </span>

                            <?php else: ?>

                                <span
                                    class="estado finalizado"
                                >
                                    Finalizado
                                </span>

                            <?php endif; ?>

                        </td>


                        <!-- =================================
                             ACCIONES
                        ================================== -->

                        <td>

                            <!-- EDITAR ASIGNACIÓN -->

                            <a
                                href="editar_asignacion.php?id=<?= (int) $animal[
                                    "id_cuidador_animal"
                                ] ?>"
                                class="boton editar"
                            >
                                Editar
                            </a>


                            <!-- =================================
                                 AÑADIR TAREA
                            ================================== -->

                            <?php if (
                                $animal["estado"] === "Activo"
                                &&
                                $cuidador["estado"] === "Activo"
                            ): ?>

                                <a
                                    href="asignar_tarea.php?id_cuidador_animal=<?= (int) $animal[
                                        "id_cuidador_animal"
                                    ] ?>"
                                    class="boton guardar"
                                    style="
                                        min-height:36px;
                                        padding:0 13px;
                                        border-radius:10px;
                                        font-size:.75rem;
                                        margin-left:5px;
                                    "
                                >
                                    + Añadir tarea
                                </a>

                            <?php endif; ?>


                            <!-- =================================
                                 VER TAREAS
                            ================================== -->

                            <button
                                type="button"
                                class="boton guardar"
                                onclick="verTareas(
                                    <?= (int) $animal[
                                        "id_cuidador_animal"
                                    ] ?>
                                )"
                                style="
                                    min-height:36px;
                                    padding:0 13px;
                                    border-radius:10px;
                                    font-size:.75rem;
                                    margin-left:5px;
                                "
                            >
                                Ver tareas
                            </button>

                        </td>

                    </tr>

                <?php endwhile; ?>

            <?php else: ?>

                <tr>

                    <td
                        colspan="6"
                        class="sin-registros"
                    >
                        Este cuidador no tiene
                        animales asignados.
                    </td>

                </tr>

            <?php endif; ?>

            </tbody>

        </table>

    </div>

</div>


<!-- =====================================================
     TAREAS CORRESPONDIENTES
===================================================== -->

<div
    class="tarjeta"
    id="seccion-tareas"
>

    <h2 class="titulo">
        Tareas correspondientes
    </h2>


    <div class="tabla-contenedor">

        <table>

            <thead>

                <tr>

                    <th>
                        Animal
                    </th>

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
                        Hora programada
                    </th>

                    <th>
                        Observaciones
                    </th>

                    <th>
                        Estado
                    </th>

                </tr>

            </thead>


            <tbody
                id="tabla-tareas"
            >

            <?php if (
                $tareas->num_rows > 0
            ): ?>

                <?php while (
                    $tarea =
                        $tareas->fetch_assoc()
                ): ?>

                    <tr
                        data-animal="<?= (int) $tarea[
                            "id_cuidador_animal"
                        ] ?>"
                    >

                        <!-- ANIMAL -->

                        <td>

                            <strong>

                                <?= escapar(
                                    $tarea[
                                        "nombre_animal"
                                    ]
                                ) ?>

                            </strong>

                        </td>


                        <!-- TAREA -->

                        <td>

                            <strong>

                                <?= escapar(
                                    $tarea[
                                        "nombre_tarea"
                                    ]
                                ) ?>

                            </strong>

                        </td>


                        <!-- DESCRIPCIÓN -->

                        <td>

                            <?= nl2br(
                                escapar(
                                    $tarea[
                                        "descripcion"
                                    ]
                                    ?: "—"
                                )
                            ) ?>

                        </td>


                        <!-- FRECUENCIA -->

                        <td>

                            <?= escapar(
                                $tarea[
                                    "frecuencia"
                                ]
                                ?: "—"
                            ) ?>

                        </td>


                        <!-- HORA -->

                        <td>

                            <?php if (
                                !empty(
                                    $tarea[
                                        "hora_programada"
                                    ]
                                )
                            ): ?>

                                <?= horaMostrar(
                                    $tarea[
                                        "hora_programada"
                                    ]
                                ) ?>

                            <?php else: ?>

                                —

                            <?php endif; ?>

                        </td>


                        <!-- OBSERVACIONES -->

                        <td>

                            <?= nl2br(
                                escapar(
                                    $tarea[
                                        "observaciones"
                                    ]
                                    ?: "—"
                                )
                            ) ?>

                        </td>


                        <!-- ESTADO -->

                        <td>

                            <?php if (
                                $tarea["estado"]
                                === "Activa"
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

                    </tr>

                <?php endwhile; ?>

            <?php else: ?>

                <tr>

                    <td
                        colspan="7"
                        class="sin-registros"
                    >
                        No hay tareas asignadas
                        a los animales de este cuidador.
                    </td>

                </tr>

            <?php endif; ?>

            </tbody>

        </table>

    </div>

</div>


</div>


<!-- =====================================================
     JAVASCRIPT
===================================================== -->

<script>

function verTareas(idCuidadorAnimal)
{
    const filas =
        document.querySelectorAll(
            "#tabla-tareas tr[data-animal]"
        );


    let hayTareas = false;


    filas.forEach(
        function(fila)
        {

            const idAnimal =
                fila.getAttribute(
                    "data-animal"
                );


            if (
                idAnimal ===
                String(idCuidadorAnimal)
            ) {

                fila.style.display =
                    "";

                hayTareas = true;

            } else {

                fila.style.display =
                    "none";

            }

        }
    );


    /*
     * Eliminar mensaje anterior
     */

    const mensajeAnterior =
        document.getElementById(
            "mensaje-sin-tareas"
        );


    if (mensajeAnterior) {

        mensajeAnterior.remove();

    }


    /*
     * Si el animal no tiene tareas
     */

    if (!hayTareas) {

        const tabla =
            document.getElementById(
                "tabla-tareas"
            );


        const fila =
            document.createElement(
                "tr"
            );


        fila.id =
            "mensaje-sin-tareas";


        fila.innerHTML = `
            <td
                colspan="7"
                class="sin-registros"
            >
                Este animal no tiene
                tareas asignadas.
            </td>
        `;


        tabla.appendChild(
            fila
        );

    }


    /*
     * Ir a la sección de tareas
     */

    const seccion =
        document.getElementById(
            "seccion-tareas"
        );


    if (seccion) {

        seccion.scrollIntoView({
            behavior: "smooth",
            block: "start"
        });

    }

}

</script>


</body>

</html>
