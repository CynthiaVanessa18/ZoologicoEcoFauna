<?php

require_once __DIR__ . "/../../soporte/sesion.php";
require_once __DIR__ . "/../../../config/database.php";
require_once __DIR__ . "/funciones.php";

/** @var mysqli $conexion */

// =========================================================
// CONTROL DE ACCESO
// =========================================================

if (
    !isset(
        $_SESSION["usuario"],
        $_SESSION["rol"],
        $_SESSION["id_login"]
    ) ||
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


// =========================================================
// MENSAJES
// =========================================================

$mensajeCuidadores = "";
$tipoMensajeCuidadores = "success";

if (function_exists("consumirMensajeCuidadores")) {

    [
        $mensajeCuidadores,
        $tipoMensajeCuidadores
    ] = consumirMensajeCuidadores();

}


// =========================================================
// PROCESAR ASIGNACIÓN DE ANIMAL
// =========================================================

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $id_cuidador = (int)(
        $_POST["id_cuidador"] ?? 0
    );

    $id_animal = (int)(
        $_POST["id_animal"] ?? 0
    );

    $fecha_asignacion = trim(
        $_POST["fecha_asignacion"] ?? ""
    );

    $responsabilidad = trim(
        $_POST["responsabilidad"] ?? ""
    );

    $enTransaccion = false;

    try {

        // =================================================
        // VALIDAR CSRF
        // =================================================

        if (!solicitudCuidadoresValida()) {

            http_response_code(403);

            throw new RuntimeException(
                "La solicitud expiró. Recarga la página e inténtalo nuevamente."
            );

        }


        // =================================================
        // VALIDAR CUIDADOR
        // =================================================

        if ($id_cuidador <= 0) {

            throw new RuntimeException(
                "El cuidador seleccionado no es válido."
            );

        }


        $stmtCuidadorPost = $conexion->prepare(
            "
            SELECT
                c.id_cuidador,
                c.estado,
                u.nombre
            FROM cuidador c

            INNER JOIN usuario u
                ON u.id_usuario = c.id_usuario

            WHERE c.id_cuidador = ?

            LIMIT 1
            "
        );

        if (!$stmtCuidadorPost) {

            throw new RuntimeException(
                "No fue posible verificar el cuidador."
            );

        }


        $stmtCuidadorPost->bind_param(
            "i",
            $id_cuidador
        );

        $stmtCuidadorPost->execute();

        $cuidadorPost =
            $stmtCuidadorPost
                ->get_result()
                ->fetch_assoc();

        $stmtCuidadorPost->close();


        if (!$cuidadorPost) {

            throw new RuntimeException(
                "El cuidador seleccionado no existe."
            );

        }


        if (
            $cuidadorPost["estado"] !== "Activo"
        ) {

            throw new RuntimeException(
                "No se pueden asignar animales a un cuidador inactivo."
            );

        }


        // =================================================
        // VALIDAR ANIMAL
        // =================================================

        if ($id_animal <= 0) {

            throw new RuntimeException(
                "Debe seleccionar un animal."
            );

        }


        // =================================================
        // VALIDAR FECHA
        // =================================================

        if (
            !fechaCuidadoresValida(
                $fecha_asignacion
            ) ||
            $fecha_asignacion > date("Y-m-d")
        ) {

            throw new RuntimeException(
                "La fecha de asignación no es válida."
            );

        }


        // =================================================
        // VALIDAR RESPONSABILIDAD
        // =================================================

        if ($responsabilidad === "") {

            throw new RuntimeException(
                "Debe indicar la responsabilidad del cuidador."
            );

        }


        if (
            mb_strlen($responsabilidad) > 255
        ) {

            throw new RuntimeException(
                "La responsabilidad no puede superar 255 caracteres."
            );

        }


        // =================================================
        // VERIFICAR ANIMAL
        // =================================================

        $stmtAnimal = $conexion->prepare(
            "
            SELECT
                nombre_animal

            FROM animal

            WHERE id_animal = ?

            LIMIT 1
            "
        );

        if (!$stmtAnimal) {

            throw new RuntimeException(
                "No fue posible verificar el animal."
            );

        }


        $stmtAnimal->bind_param(
            "i",
            $id_animal
        );

        $stmtAnimal->execute();

        $animal =
            $stmtAnimal
                ->get_result()
                ->fetch_assoc();

        $stmtAnimal->close();


        if (!$animal) {

            throw new RuntimeException(
                "El animal seleccionado no existe."
            );

        }


        // =================================================
        // VERIFICAR ASIGNACIÓN EXISTENTE
        // =================================================

        $stmtExiste = $conexion->prepare(
            "
            SELECT
                id_cuidador_animal

            FROM cuidador_animal

            WHERE id_cuidador = ?
            AND id_animal = ?
            AND estado = 'Activo'

            LIMIT 1
            "
        );

        if (!$stmtExiste) {

            throw new RuntimeException(
                "No fue posible verificar la asignación."
            );

        }


        $stmtExiste->bind_param(
            "ii",
            $id_cuidador,
            $id_animal
        );

        $stmtExiste->execute();

        $asignacionExistente =
            $stmtExiste
                ->get_result()
                ->fetch_assoc();

        $stmtExiste->close();


        if ($asignacionExistente) {

            throw new RuntimeException(
                "Este animal ya está asignado actualmente a este cuidador."
            );

        }


        // =================================================
        // TRANSACCIÓN
        // =================================================

        mysqli_begin_transaction(
            $conexion
        );

        $enTransaccion = true;


        // =================================================
        // INSERTAR ASIGNACIÓN
        // =================================================

        $stmtInsertar = $conexion->prepare(
            "
            INSERT INTO cuidador_animal
            (
                id_cuidador,
                id_animal,
                fecha_asignacion,
                fecha_finalizacion,
                responsabilidad,
                estado
            )

            VALUES
            (
                ?,
                ?,
                ?,
                NULL,
                ?,
                'Activo'
            )
            "
        );

        if (!$stmtInsertar) {

            throw new RuntimeException(
                "No fue posible preparar la asignación."
            );

        }


        $stmtInsertar->bind_param(
            "iiss",
            $id_cuidador,
            $id_animal,
            $fecha_asignacion,
            $responsabilidad
        );


        if (
            !$stmtInsertar->execute()
        ) {

            throw new RuntimeException(
                $stmtInsertar->error
            );

        }


        $idAsignacion =
            $conexion->insert_id;


        $stmtInsertar->close();


        // =================================================
        // BITÁCORA
        // =================================================

        if (
            !registrarBitacora(
                $_SESSION["usuario"],
                "ASIGNAR ANIMAL",
                "cuidador_animal",
                "Se asignó {$animal["nombre_animal"]} a {$cuidadorPost["nombre"]}.",
                $idAsignacion
            )
        ) {

            throw new RuntimeException(
                "No fue posible registrar la acción en la bitácora."
            );

        }


        // =================================================
        // CONFIRMAR
        // =================================================

        mysqli_commit(
            $conexion
        );

        $enTransaccion = false;


        redirigirCuidadores(
            "cuidadores.php",
            "El animal fue asignado correctamente al cuidador."
        );

        exit();


    } catch (Throwable $excepcion) {

        if ($enTransaccion) {

            mysqli_rollback(
                $conexion
            );

        }


        error_log(
            "Error asignando animal: " .
            $excepcion->getMessage()
        );


        $mensajeCuidadores =
            $excepcion->getMessage();

        $tipoMensajeCuidadores =
            "danger";
    }
}


// =========================================================
// RESUMEN
// =========================================================

$sqlResumen = "
    SELECT

        (
            SELECT COUNT(*)
            FROM cuidador
        ) AS total_cuidadores,

        (
            SELECT COUNT(*)
            FROM cuidador
            WHERE estado = 'Activo'
        ) AS cuidadores_activos,

        (
            SELECT COUNT(*)
            FROM cuidador_animal
            WHERE estado = 'Activo'
        ) AS animales_asignados,

        (
            SELECT COUNT(*)
            FROM tarea
            WHERE estado = 'Activa'
        ) AS tareas_activas
";


$resumen =
    $conexion->query(
        $sqlResumen
    );


if (!$resumen) {

    die(
        "Error al consultar el resumen: " .
        $conexion->error
    );

}


$indicadores =
    $resumen->fetch_assoc();


// =========================================================
// CONSULTAR CUIDADORES
// =========================================================

$sql = "
    SELECT
        c.id_cuidador,
        c.fecha_contratacion,
        c.especialidad,
        c.estado,
        u.nombre,

        COUNT(
            DISTINCT CASE
                WHEN ca.estado = 'Activo'
                THEN ca.id_cuidador_animal
            END
        ) AS animales_asignados

    FROM cuidador c

    INNER JOIN usuario u
        ON u.id_usuario = c.id_usuario

    LEFT JOIN cuidador_animal ca
        ON ca.id_cuidador = c.id_cuidador

    GROUP BY
        c.id_cuidador,
        c.fecha_contratacion,
        c.especialidad,
        c.estado,
        u.nombre

    ORDER BY
        u.nombre ASC
";


$resultado =
    $conexion->query($sql);


if (!$resultado) {

    die(
        "Error al consultar los cuidadores: " .
        $conexion->error
    );

}


// =========================================================
// CARGAR DATOS
// =========================================================

$datosCuidadores = [];


while (
    $cuidador =
        $resultado->fetch_assoc()
) {

    $idCuidador =
        (int)$cuidador["id_cuidador"];


    $cuidador["animales"] = [];

    $cuidador["animales_disponibles"] = [];


    // =====================================================
    // ANIMALES DISPONIBLES PARA ESTE CUIDADOR
    // =====================================================

    $sqlDisponibles = "
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

        ORDER BY
            a.nombre_animal ASC
    ";


    $stmtDisponibles =
        $conexion->prepare(
            $sqlDisponibles
        );


    if (!$stmtDisponibles) {

        die(
            "Error al preparar animales disponibles: " .
            $conexion->error
        );

    }


    $stmtDisponibles->bind_param(
        "i",
        $idCuidador
    );

    $stmtDisponibles->execute();


    $resultadoDisponibles =
        $stmtDisponibles->get_result();


    while (
        $animalDisponible =
            $resultadoDisponibles->fetch_assoc()
    ) {

        $cuidador[
            "animales_disponibles"
        ][] =
            $animalDisponible;

    }


    $stmtDisponibles->close();


    // =====================================================
    // ANIMALES DEL CUIDADOR
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
            "Error al preparar la consulta de animales: " .
            $conexion->error
        );

    }


    $stmtAnimales->bind_param(
        "i",
        $idCuidador
    );

    $stmtAnimales->execute();


    $resultadoAnimales =
        $stmtAnimales->get_result();


    while (
        $animal =
            $resultadoAnimales->fetch_assoc()
    ) {

        $idCuidadorAnimal =
            (int)$animal[
                "id_cuidador_animal"
            ];


        $animal["tareas"] = [];


        // =================================================
        // TAREAS DEL ANIMAL
        // =================================================

        $sqlTareas = "
            SELECT
                id_cuidador_animal,
                nombre_tarea,
                descripcion,
                frecuencia,
                hora_programada,
                observaciones,
                estado

            FROM tarea

            WHERE id_cuidador_animal = ?

            ORDER BY
                hora_programada ASC,
                nombre_tarea ASC
        ";


        $stmtTareas =
            $conexion->prepare(
                $sqlTareas
            );


        if (!$stmtTareas) {

            die(
                "Error al preparar la consulta de tareas: " .
                $conexion->error
            );

        }


        $stmtTareas->bind_param(
            "i",
            $idCuidadorAnimal
        );

        $stmtTareas->execute();


        $resultadoTareas =
            $stmtTareas->get_result();


        while (
            $tarea =
                $resultadoTareas->fetch_assoc()
        ) {

            $animal["tareas"][] =
                $tarea;

        }


        $stmtTareas->close();


        $cuidador["animales"][] =
            $animal;
    }


    $stmtAnimales->close();


    $datosCuidadores[] =
        $cuidador;
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
    Control de cuidadores | EcoFauna
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
    href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css"
>


<style>

:root {

    --verde-oscuro: #173f2a;
    --verde: #287a4a;
    --verde-medio: #3f9a5c;
    --verde-claro: #e9f5ec;
    --verde-suave: #f3f9f4;

    --dorado: #d4a94f;

    --blanco: #ffffff;

    --texto: #203129;
    --texto-suave: #66736b;

    --borde: #dce7df;

    --rojo: #c84b4b;
    --rojo-suave: #fdeeee;

    --sombra:
        0 12px 35px rgba(
            23,
            63,
            42,
            .10
        );

    --sombra-suave:
        0 5px 18px rgba(
            23,
            63,
            42,
            .08
        );

    --radio: 18px;
    --radio-pequeno: 10px;
}


* {
    box-sizing: border-box;
}


html {
    scroll-behavior: smooth;
}


body {

    margin: 0;

    min-height: 100vh;

    font-family:
        "Poppins",
        sans-serif;

    color: var(--texto);

    background:

        radial-gradient(
            circle at top left,
            rgba(
                63,
                154,
                92,
                .08
            ),
            transparent 30%
        ),

        linear-gradient(
            135deg,
            #f5faf6 0%,
            #eef6f0 50%,
            #f8fbf8 100%
        );
}


.contenedor {

    width:
        min(
            1450px,
            calc(100% - 40px)
        );

    margin: 0 auto;

    padding:
        42px 0 60px;
}


.encabezado {

    display: flex;

    align-items: flex-end;

    justify-content:
        space-between;

    gap: 30px;

    margin-bottom: 30px;

    padding: 34px;

    border-radius: 24px;

    color: white;

    background:
        linear-gradient(
            135deg,
            rgba(
                23,
                63,
                42,
                .98
            ),
            rgba(
                40,
                122,
                74,
                .95
            )
        );

    box-shadow:
        var(--sombra);

    position: relative;

    overflow: hidden;
}


.encabezado::before {

    content: "";

    position: absolute;

    width: 280px;
    height: 280px;

    right: -90px;
    top: -120px;

    border-radius: 50%;

    background:
        rgba(
            255,
            255,
            255,
            .08
        );
}


.encabezado::after {

    content: "";

    position: absolute;

    width: 160px;
    height: 160px;

    right: 130px;
    bottom: -100px;

    border-radius: 50%;

    background:
        rgba(
            212,
            169,
            79,
            .12
        );
}


.encabezado > div {
    position: relative;
    z-index: 1;
}


.etiqueta-seccion {

    margin:
        0 0 8px;

    font-size: .76rem;

    font-weight: 700;

    letter-spacing:
        1.5px;

    text-transform:
        uppercase;

    color:
        #bfe5c9;
}


.encabezado h1 {

    margin: 0;

    font-family:
        "DM Serif Display",
        serif;

    font-size:
        clamp(
            2rem,
            4vw,
            3.1rem
        );

    line-height: 1.05;

    font-weight: 400;
}


.subtitulo {

    max-width: 720px;

    margin:
        12px 0 0;

    color:
        rgba(
            255,
            255,
            255,
            .82
        );

    font-size: .96rem;

    line-height: 1.7;
}


.acciones {

    display: flex;

    flex-wrap: wrap;

    justify-content:
        flex-end;

    gap: 8px;

    position: relative;

    z-index: 2;
}


.boton {

    display: inline-flex;

    align-items: center;

    justify-content: center;

    min-height: 38px;

    padding:
        9px 14px;

    border:
        1px solid transparent;

    border-radius: 10px;

    text-decoration: none;

    font-family:
        "Poppins",
        sans-serif;

    font-size: .76rem;

    font-weight: 600;

    cursor: pointer;

    transition:
        .2s ease;
}


.boton:hover {
    transform:
        translateY(-2px);
}


.boton.volver {

    color: white;

    border-color:
        rgba(
            255,
            255,
            255,
            .28
        );

    background:
        rgba(
            255,
            255,
            255,
            .10
        );
}


.boton.editar {

    color:
        var(--verde-oscuro);

    background:
        #edf7ef;

    border-color:
        #d4e8d8;
}


.boton.editar:hover {

    color: white;

    background:
        var(--verde);
}


.boton.guardar {

    color: white;

    background:
        var(--dorado);

    border-color:
        var(--dorado);

    box-shadow:
        0 5px 15px
        rgba(
            212,
            169,
            79,
            .20
        );
}


.boton.guardar:hover {

    background:
        #bc923b;
}


.mensaje,
.error {

    margin-bottom: 24px;

    padding:
        15px 18px;

    border-radius: 12px;

    font-size: .9rem;

    font-weight: 500;
}


.mensaje {

    color:
        #215c35;

    background:
        #eaf7ed;

    border:
        1px solid #c8e6cf;
}


.error {

    color:
        #8f3030;

    background:
        var(--rojo-suave);

    border:
        1px solid #f2caca;
}


.indicadores {

    display: grid;

    grid-template-columns:
        repeat(
            4,
            minmax(0, 1fr)
        );

    gap: 18px;

    margin-bottom: 22px;
}


.indicador {

    position: relative;

    padding: 23px;

    background:
        var(--blanco);

    border:
        1px solid var(--borde);

    border-radius:
        var(--radio);

    box-shadow:
        var(--sombra-suave);

    overflow: hidden;
}


.indicador::before {

    content: "";

    position: absolute;

    left: 0;
    top: 0;
    bottom: 0;

    width: 5px;

    background:
        var(--verde);
}


.indicador:nth-child(2)::before {
    background:
        var(--verde-medio);
}


.indicador:nth-child(3)::before {
    background:
        var(--dorado);
}


.indicador:nth-child(4)::before {
    background:
        #5c8c6a;
}


.indicador span {

    display: block;

    margin-bottom: 8px;

    color:
        var(--texto-suave);

    font-size: .78rem;

    font-weight: 600;

    text-transform:
        uppercase;
}


.indicador strong {

    display: block;

    color:
        var(--verde-oscuro);

    font-size: 2rem;

    line-height: 1;
}


.indicador small {

    color:
        #8a968f;

    font-size: .74rem;
}


.accesos-rapidos {

    display: grid;

    grid-template-columns:
        repeat(
            3,
            minmax(0, 1fr)
        );

    gap: 16px;

    margin-bottom: 25px;
}


.acceso-rapido {

    display: flex;

    flex-direction: column;

    gap: 6px;

    min-height: 105px;

    padding: 21px;

    border-radius: 16px;

    text-decoration: none;

    background:
        white;

    border:
        1px solid var(--borde);

    box-shadow:
        var(--sombra-suave);
}


.acceso-rapido strong {

    color:
        var(--verde-oscuro);

    font-size: .95rem;
}


.acceso-rapido span {

    color:
        var(--texto-suave);

    font-size: .79rem;

    line-height: 1.55;
}


.tarjeta {

    margin-bottom: 22px;

    background:
        var(--blanco);

    border:
        1px solid var(--borde);

    border-radius: 22px;

    box-shadow:
        var(--sombra);

    overflow: hidden;
}


.cabecera-tabla {

    display: flex;

    align-items: flex-end;

    justify-content:
        space-between;

    gap: 25px;

    padding:
        27px 28px 22px;

    border-bottom:
        1px solid var(--borde);
}


.titulo {

    margin: 0;

    color:
        var(--verde-oscuro);

    font-family:
        "DM Serif Display",
        serif;

    font-size: 1.9rem;

    font-weight: 400;
}


.controles-tabla {

    display: flex;

    align-items: flex-end;

    gap: 12px;
}


.campo-busqueda,
.campo-filtro {

    display: flex;

    flex-direction: column;

    gap: 6px;
}


.campo-busqueda span,
.campo-filtro span {

    color:
        var(--texto-suave);

    font-size: .72rem;

    font-weight: 600;

    text-transform:
        uppercase;
}


.campo-busqueda input,
.campo-filtro select {

    height: 42px;

    border:
        1px solid #d7e3da;

    border-radius: 10px;

    background:
        #fbfdfb;

    color:
        var(--texto);

    font-family:
        "Poppins",
        sans-serif;

    font-size: .82rem;

    outline: none;
}


.campo-busqueda input {

    width: 270px;

    padding:
        0 13px;
}


.campo-filtro select {

    min-width: 145px;

    padding:
        0 12px;
}


.tabla-contenedor {

    width: 100%;

    overflow-x: auto;
}


table {

    width: 100%;

    border-collapse:
        collapse;

    min-width: 850px;
}


thead {

    background:
        #f3f8f4;
}


th {

    padding:
        15px 18px;

    color:
        #587064;

    font-size: .71rem;

    font-weight: 700;

    text-align: left;

    text-transform:
        uppercase;

    letter-spacing:
        .6px;

    border-bottom:
        1px solid var(--borde);
}


td {

    padding:
        16px 18px;

    color:
        #526058;

    font-size: .82rem;

    border-bottom:
        1px solid #edf2ee;

    vertical-align:
        middle;
}


tbody tr:hover {

    background:
        #f8fcf9;
}


.estado {

    display: inline-flex;

    align-items: center;

    gap: 6px;

    padding:
        6px 10px;

    border-radius: 30px;

    font-size: .72rem;

    font-weight: 700;
}


.estado::before {

    content: "";

    width: 7px;
    height: 7px;

    border-radius: 50%;
}


.estado.activo {

    color:
        #287044;

    background:
        #e7f5ea;
}


.estado.activo::before {

    background:
        #3f9a5c;
}


.estado.finalizado {

    color:
        #8a4b4b;

    background:
        #f9eaea;
}


.estado.finalizado::before {

    background:
        #c84b4b;
}


.fila-cuidador {

    transition:
        background .2s ease;
}


.fila-cuidador[hidden] {
    display: none;
}


.ficha {

    display: none;

    padding: 28px;

    background:
        #f7fbf8;

    border-top:
        1px solid var(--borde);
}


.ficha.visible {
    display: block;
}


.ficha-cabecera {

    display: flex;

    align-items: center;

    justify-content:
        space-between;

    gap: 20px;

    margin-bottom: 20px;
}


.ficha-cabecera h3 {

    margin: 0;

    color:
        var(--verde-oscuro);

    font-family:
        "DM Serif Display",
        serif;

    font-size: 1.7rem;

    font-weight: 400;
}


.datos {

    display: grid;

    grid-template-columns:
        repeat(
            4,
            minmax(0, 1fr)
        );

    gap: 14px;

    margin-bottom: 25px;
}


.dato {

    padding: 17px;

    background:
        white;

    border:
        1px solid var(--borde);

    border-radius:
        12px;
}


.dato strong {

    display: block;

    margin-bottom: 6px;

    color:
        var(--texto-suave);

    font-size: .72rem;

    text-transform:
        uppercase;
}


.dato span {

    color:
        var(--verde-oscuro);

    font-size: .86rem;

    font-weight: 600;
}


.subseccion {

    margin-top: 25px;

    margin-bottom: 12px;

    color:
        var(--verde-oscuro);

    font-family:
        "DM Serif Display",
        serif;

    font-size: 1.5rem;

    font-weight: 400;
}


/* =========================================================
   FORMULARIO ASIGNAR ANIMAL
========================================================= */

.formulario-animal {

    display: grid;

    grid-template-columns:
        repeat(
            2,
            minmax(0, 1fr)
        );

    gap: 15px;

    margin-bottom: 25px;

    padding: 20px;

    background:
        white;

    border:
        1px solid var(--borde);

    border-radius:
        15px;
}


.formulario-animal .campo {

    display: flex;

    flex-direction:
        column;

    gap: 6px;
}


.formulario-animal .campo-completo {

    grid-column:
        1 / -1;
}


.formulario-animal label {

    color:
        var(--texto);

    font-size: .76rem;

    font-weight: 600;
}


.formulario-animal input,
.formulario-animal select,
.formulario-animal textarea {

    width: 100%;

    border:
        1px solid #d7e3da;

    border-radius: 10px;

    background:
        #fbfdfb;

    color:
        var(--texto);

    font-family:
        "Poppins",
        sans-serif;

    font-size: .82rem;

    outline: none;
}


.formulario-animal input,
.formulario-animal select {

    height: 42px;

    padding:
        0 12px;
}


.formulario-animal textarea {

    min-height: 90px;

    padding:
        11px 12px;

    resize:
        vertical;
}


.formulario-animal input:focus,
.formulario-animal select:focus,
.formulario-animal textarea:focus {

    border-color:
        var(--verde);

    box-shadow:
        0 0 0 3px
        rgba(
            40,
            122,
            74,
            .08
        );
}


.animal-bloque {

    margin-bottom: 16px;

    background:
        white;

    border:
        1px solid var(--borde);

    border-radius:
        15px;

    overflow: hidden;
}


.animal-cabecera {

    display: flex;

    align-items: center;

    justify-content:
        space-between;

    gap: 15px;

    padding:
        16px 18px;

    background:
        #f3f8f4;
}


.animal-nombre {

    color:
        var(--verde-oscuro);

    font-size: .95rem;

    font-weight: 700;
}


.animal-datos {

    display: grid;

    grid-template-columns:
        repeat(
            3,
            minmax(0, 1fr)
        );

    gap: 12px;

    padding:
        17px 18px;
}


.animal-dato {

    color:
        var(--texto-suave);

    font-size: .78rem;

    line-height: 1.5;
}


.animal-dato strong {

    color:
        var(--texto);

    font-weight: 600;
}


.acciones-animal {

    display: flex;

    flex-wrap: wrap;

    gap: 7px;

    padding:
        0 18px 17px;
}


.tareas-animal {

    padding:
        0 18px 18px;
}


.tareas-titulo {

    margin:
        0 0 10px;

    color:
        var(--verde);

    font-size: .78rem;

    font-weight: 700;

    text-transform:
        uppercase;
}


.tarea-item {

    display: grid;

    grid-template-columns:
        1.2fr 1fr 1fr 100px;

    gap: 10px;

    align-items: center;

    padding:
        11px 13px;

    margin-bottom: 6px;

    background:
        #f8fbf8;

    border:
        1px solid #e6eee8;

    border-radius:
        9px;

    font-size: .76rem;
}


.tarea-item strong {

    color:
        var(--verde-oscuro);
}


.tarea-vacia {

    padding: 13px;

    color:
        var(--texto-suave);

    background:
        #fafcfa;

    border-radius:
        9px;

    font-size: .76rem;

    text-align:
        center;
}


.sin-registros {

    padding:
        40px 20px !important;

    color:
        var(--texto-suave) !important;

    text-align:
        center;

    font-size:
        .86rem !important;
}


.pie-tabla {

    display: flex;

    align-items: center;

    justify-content:
        space-between;

    gap: 20px;

    padding:
        18px 25px;

    background:
        #fbfdfb;

    border-top:
        1px solid var(--borde);
}


.resumen-tabla {

    margin: 0;

    color:
        #718078;

    font-size: .76rem;
}


.paginacion {

    display: flex;

    align-items: center;

    justify-content:
        center;

    gap: 5px;
}


.pagina-boton {

    min-width: 34px;

    height: 34px;

    border:
        1px solid #d8e4db;

    border-radius: 8px;

    color:
        #557062;

    background:
        white;

    cursor:
        pointer;
}


.pagina-boton.activa {

    color: white;

    background:
        var(--verde-oscuro);

    border-color:
        var(--verde-oscuro);
}


.pagina-boton:hover:not(:disabled) {

    color: white;

    background:
        var(--verde);

    border-color:
        var(--verde);
}


.pagina-boton:disabled {

    opacity: .4;

    cursor:
        not-allowed;
}


@media (max-width: 1100px) {

    .indicadores {

        grid-template-columns:
            repeat(
                2,
                1fr
            );
    }


    .accesos-rapidos {

        grid-template-columns:
            1fr;
    }


    .datos {

        grid-template-columns:
            repeat(
                2,
                1fr
            );
    }


    .animal-datos {

        grid-template-columns:
            1fr;
    }

}


@media (max-width: 700px) {

    .contenedor {

        width:
            calc(100% - 22px);

        padding-top:
            20px;
    }


    .encabezado {

        flex-direction:
            column;

        align-items:
            flex-start;

        padding:
            25px 20px;
    }


    .acciones {

        width: 100%;

        flex-direction:
            column;
    }


    .acciones .boton {

        width: 100%;
    }


    .indicadores {

        grid-template-columns:
            1fr;
    }


    .cabecera-tabla {

        flex-direction:
            column;

        align-items:
            stretch;
    }


    .controles-tabla {

        flex-direction:
            column;

        align-items:
            stretch;
    }


    .campo-busqueda input {

        width: 100%;
    }


    .datos {

        grid-template-columns:
            1fr;
    }


    .ficha {

        padding:
            20px 15px;
    }


    .animal-cabecera {

        align-items:
            flex-start;

        flex-direction:
            column;
    }


    .tarea-item {

        grid-template-columns:
            1fr;
    }


    .formulario-animal {

        grid-template-columns:
            1fr;
    }


    .formulario-animal
    .campo-completo {

        grid-column:
            auto;
    }


    .pie-tabla {

        flex-direction:
            column;

        align-items:
            flex-start;
    }

}

</style>

    <link rel="stylesheet" href="../css/crud-modern.css?v=1">
    <link rel="stylesheet" href="../css/ecofauna-unified.css?v=1">
</head>

<body class="ecofauna-unified crud-modern">




<main class="contenedor">


<!-- =====================================================
     ENCABEZADO
====================================================== -->

<header class="encabezado">

    <div>

        <p class="etiqueta-seccion">
            Administración · EcoFauna
        </p>

        <h1>
            Panel de cuidadores
        </h1>

        <p class="subtitulo">
            Administra cuidadores, animales asignados,
            horarios y tareas desde una sola pantalla.
        </p>

    </div>


    <div class="acciones">

        <a
            href="../admin.php"
            class="boton volver"
        >
            <i class="bi bi-arrow-left"></i>
            Regresar
        </a>


      


        <a
            href="tareas_cuidador.php"
            class="boton editar"
        >
            <i class="bi bi-list-check"></i>
            Gestionar tareas
        </a>


        <a
            href="cuidador_agregar.php"
            class="boton guardar"
        >
            <i class="bi bi-person-plus-fill"></i>
            Registrar cuidador
        </a>

    </div>

</header>


<!-- =====================================================
     MENSAJE
====================================================== -->

<?php if ($mensajeCuidadores !== ""): ?>

    <div
        class="<?= $tipoMensajeCuidadores === "danger"
            ? "error"
            : "mensaje" ?>"
    >

        <i class="bi bi-info-circle-fill"></i>

        <?= escapar(
            $mensajeCuidadores
        ) ?>

    </div>

<?php endif; ?>


<!-- =====================================================
     INDICADORES
====================================================== -->

<section class="indicadores">

    <article class="indicador">

        <span>
            Total de cuidadores
        </span>

        <strong>
            <?= (int)$indicadores[
                "total_cuidadores"
            ] ?>
        </strong>

        <small>
            Personal registrado
        </small>

    </article>


    <article class="indicador">

        <span>
            En servicio
        </span>

        <strong>
            <?= (int)$indicadores[
                "cuidadores_activos"
            ] ?>
        </strong>

        <small>
            Cuidadores activos
        </small>

    </article>


    <article class="indicador">

        <span>
            Animales asignados
        </span>

        <strong>
            <?= (int)$indicadores[
                "animales_asignados"
            ] ?>
        </strong>

        <small>
            Asignaciones vigentes
        </small>

    </article>


    <article class="indicador">

        <span>
            Tareas activas
        </span>

        <strong>
            <?= (int)$indicadores[
                "tareas_activas"
            ] ?>
        </strong>

        <small>
            Tareas por realizar
        </small>

    </article>

</section>


<!-- =====================================================
     ACCESOS
====================================================== -->

<section class="accesos-rapidos">

    <a
        class="acceso-rapido"
        href="cuidador_agregar.php"
    >

        <strong>
            Registrar cuidador
        </strong>

        <span>
            Agrega un nuevo integrante
            al equipo de cuidadores.
        </span>

    </a>


    <a
        class="acceso-rapido"
        href="horario_cuidador.php"
    >

        <strong>
            Horarios
        </strong>

        <span>
            Consulta y administra los horarios
            de los cuidadores.
        </span>

    </a>


    <a
        class="acceso-rapido"
        href="tareas_cuidador.php"
    >

        <strong>
            Catálogo de tareas
        </strong>

        <span>
            Crea, edita, activa o administra
            las tareas disponibles.
        </span>

    </a>

</section>


<!-- =====================================================
     LISTADO
====================================================== -->

<section class="tarjeta">

    <div class="cabecera-tabla">

        <div>

            <p
                class="etiqueta-seccion"
                style="color:var(--verde);"
            >
                Equipo EcoFauna
            </p>

            <h2 class="titulo">
                Cuidadores registrados
            </h2>

        </div>


        <div class="controles-tabla">

            <label class="campo-busqueda">

                <span>
                    Buscar
                </span>

                <input
                    type="search"
                    id="buscador-cuidadores"
                    placeholder="Nombre o especialidad"
                    autocomplete="off"
                >

            </label>


            <label class="campo-filtro">

                <span>
                    Estado
                </span>

                <select
                    id="filtro-estado"
                >

                    <option value="">
                        Todos
                    </option>

                    <option value="Activo">
                        Activos
                    </option>

                    <option value="Inactivo">
                        Inactivos
                    </option>

                </select>

            </label>

        </div>

    </div>


    <div class="tabla-contenedor">

        <table id="tabla-cuidadores">

            <thead>

                <tr>

                    <th>
                        Cuidador
                    </th>

                    <th>
                        Contratación
                    </th>

                    <th>
                        Especialidad
                    </th>

                    <th>
                        Estado
                    </th>

                    <th>
                        Animales
                    </th>

                    <th>
                        Acciones
                    </th>

                </tr>

            </thead>


            <tbody>

            <?php if (
                count($datosCuidadores) > 0
            ): ?>


                <?php foreach (
                    $datosCuidadores
                    as $cuidador
                ): ?>

                    <?php

                    $idCuidador =
                        (int)$cuidador[
                            "id_cuidador"
                        ];


                    $estadoActual =
                        $cuidador["estado"]
                        === "Activo"
                            ? "Activo"
                            : "Inactivo";

                    ?>


                    <!-- =================================================
                         FILA CUIDADOR
                    ================================================== -->

                    <tr
                        class="fila-cuidador"
                        data-estado="<?= escapar(
                            $estadoActual
                        ) ?>"
                    >

                        <td>

                            <strong>

                                <i class="bi bi-person-badge"></i>

                                <?= escapar(
                                    $cuidador["nombre"]
                                ) ?>

                            </strong>

                        </td>


                        <td>

                            <?= fechaMostrar(
                                $cuidador[
                                    "fecha_contratacion"
                                ]
                            ) ?>

                        </td>


                        <td>

                            <?= escapar(
                                $cuidador[
                                    "especialidad"
                                ]
                                ?: "Sin especialidad"
                            ) ?>

                        </td>


                        <td>

                            <span
                                class="estado
                                <?= $estadoActual === "Activo"
                                    ? "activo"
                                    : "finalizado" ?>"
                            >

                                <?= escapar(
                                    $estadoActual
                                ) ?>

                            </span>

                        </td>


                        <td>

                            <?= (int)
                                $cuidador[
                                    "animales_asignados"
                                ] ?>

                        </td>


                        <td>

                            <div class="acciones">

                                <button
                                    type="button"
                                    class="boton editar"
                                    onclick="mostrarFicha(
                                        <?= $idCuidador ?>
                                    )"
                                >
                                    <i class="bi bi-person-vcard"></i>
                                    Ficha
                                </button>


                                <a
                                    href="cuidador_editar.php?id=<?= $idCuidador ?>"
                                    class="boton editar"
                                >
                                    <i class="bi bi-pencil"></i>
                                    Editar
                                </a>


                                <a
                                    href="horario_cuidador.php?id=<?= $idCuidador ?>"
                                    class="boton editar"
                                >
                                    <i class="bi bi-clock"></i>
                                    Horario
                                </a>


                                <?php if (
                                    $cuidador["estado"]
                                    === "Activo"
                                ): ?>

                                    <button
                                        type="button"
                                        class="boton guardar"
                                        onclick="mostrarFicha(
                                            <?= $idCuidador ?>
                                        )"
                                    >
                                        <i class="bi bi-plus-circle"></i>
                                        + Animal
                                    </button>

                                <?php endif; ?>

                            </div>

                        </td>

                    </tr>


                    <!-- =================================================
                         FICHA
                    ================================================== -->

                    <tr
                        class="fila-ficha"
                        data-cuidador="<?= $idCuidador ?>"
                        hidden
                    >

                        <td
                            colspan="6"
                            style="padding:0;"
                        >

                            <div
                                class="ficha"
                                id="ficha-<?= $idCuidador ?>"
                            >


                                <!-- =================================
                                     CABECERA
                                ================================== -->

                                <div class="ficha-cabecera">

                                    <div>

                                        <p
                                            class="etiqueta-seccion"
                                            style="color:var(--verde);"
                                        >
                                            Ficha completa
                                        </p>

                                        <h3>

                                            <?= escapar(
                                                $cuidador[
                                                    "nombre"
                                                ]
                                            ) ?>

                                        </h3>

                                    </div>


                                    <button
                                        type="button"
                                        class="boton editar"
                                        onclick="cerrarFicha(
                                            <?= $idCuidador ?>
                                        )"
                                    >

                                        <i class="bi bi-x-lg"></i>

                                        Cerrar ficha

                                    </button>

                                </div>


                                <!-- =================================
                                     DATOS
                                ================================== -->

                                <div class="datos">

                                    <div class="dato">

                                        <strong>
                                            Nombre
                                        </strong>

                                        <span>

                                            <?= escapar(
                                                $cuidador[
                                                    "nombre"
                                                ]
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

                                        <span>

                                            <span
                                                class="estado
                                                <?= $cuidador["estado"]
                                                    === "Activo"
                                                    ? "activo"
                                                    : "finalizado" ?>"
                                            >

                                                <?= $cuidador[
                                                    "estado"
                                                ] === "Activo"
                                                    ? "Activo"
                                                    : "Inactivo" ?>

                                            </span>

                                        </span>

                                    </div>

                                </div>


                                <!-- =================================
                                     FORMULARIO ASIGNAR ANIMAL
                                ================================== -->

                                <?php if (
                                    $cuidador["estado"]
                                    === "Activo"
                                ): ?>

                                    <h3 class="subseccion">

                                        <i class="bi bi-plus-circle"></i>

                                        Asignar nuevo animal

                                    </h3>


                                    <form
                                        method="POST"
                                        class="formulario-animal"
                                    >

                                        <?= campoCsrfCuidadores() ?>


                                        <input
                                            type="hidden"
                                            name="id_cuidador"
                                            value="<?= $idCuidador ?>"
                                        >


                                        <div class="campo">

                                            <label
                                                for="id_animal_<?= $idCuidador ?>"
                                            >

                                                <i class="bi bi-paw-fill"></i>

                                                Animal

                                            </label>


                                            <select
                                                name="id_animal"
                                                id="id_animal_<?= $idCuidador ?>"
                                                required
                                            >

                                                <option value="">
                                                    Seleccione un animal
                                                </option>


                                                <?php foreach (
                                                    $cuidador[
                                                        "animales_disponibles"
                                                    ]
                                                    as $animalDisponible
                                                ): ?>

                                                    <option
                                                        value="<?= (int)
                                                            $animalDisponible[
                                                                "id_animal"
                                                            ] ?>"
                                                    >

                                                        <?= escapar(
                                                            $animalDisponible[
                                                                "nombre_animal"
                                                            ]
                                                        ) ?>

                                                    </option>

                                                <?php endforeach; ?>

                                            </select>

                                        </div>


                                        <div class="campo">

                                            <label
                                                for="fecha_asignacion_<?= $idCuidador ?>"
                                            >

                                                <i class="bi bi-calendar-event"></i>

                                                Fecha de asignación

                                            </label>


                                            <input
                                                type="date"
                                                name="fecha_asignacion"
                                                id="fecha_asignacion_<?= $idCuidador ?>"
                                                max="<?= date("Y-m-d") ?>"
                                                value="<?= date("Y-m-d") ?>"
                                                required
                                            >

                                        </div>


                                        <div class="campo campo-completo">

                                            <label
                                                for="responsabilidad_<?= $idCuidador ?>"
                                            >

                                                <i class="bi bi-clipboard-check"></i>

                                                Responsabilidad

                                            </label>


                                            <textarea
                                                name="responsabilidad"
                                                id="responsabilidad_<?= $idCuidador ?>"
                                                maxlength="255"
                                                placeholder="Indique las responsabilidades del cuidador sobre este animal..."
                                                required
                                            ></textarea>

                                        </div>


                                        <div class="campo-completo">

                                            <?php if (
                                                count(
                                                    $cuidador[
                                                        "animales_disponibles"
                                                    ]
                                                ) > 0
                                            ): ?>

                                                <button
                                                    type="submit"
                                                    class="boton guardar"
                                                >

                                                    <i class="bi bi-check-circle-fill"></i>

                                                    Asignar animal

                                                </button>

                                            <?php else: ?>

                                                <div class="tarea-vacia">

                                                    <i class="bi bi-info-circle"></i>

                                                    No hay animales disponibles
                                                    para asignar a este cuidador.

                                                </div>

                                            <?php endif; ?>

                                        </div>

                                    </form>

                                <?php endif; ?>


                                <!-- =================================
                                     ANIMALES
                                ================================== -->

                                <h3 class="subseccion">

                                    <i class="bi bi-paw-fill"></i>

                                    Animales asignados

                                </h3>


                                <?php if (
                                    count(
                                        $cuidador["animales"]
                                    ) > 0
                                ): ?>


                                    <?php foreach (
                                        $cuidador[
                                            "animales"
                                        ]
                                        as $animal
                                    ): ?>

                                        <div
                                            class="animal-bloque"
                                        >


                                            <div
                                                class="animal-cabecera"
                                            >

                                                <span
                                                    class="animal-nombre"
                                                >

                                                    <i class="bi bi-paw-fill"></i>

                                                    <?= escapar(
                                                        $animal[
                                                            "nombre_animal"
                                                        ]
                                                    ) ?>

                                                </span>


                                                <span
                                                    class="estado
                                                    <?= $animal["estado"]
                                                        === "Activo"
                                                        ? "activo"
                                                        : "finalizado" ?>"
                                                >

                                                    <?= escapar(
                                                        $animal[
                                                            "estado"
                                                        ]
                                                    ) ?>

                                                </span>

                                            </div>


                                            <div
                                                class="animal-datos"
                                            >

                                                <div
                                                    class="animal-dato"
                                                >

                                                    <strong>
                                                        Fecha de asignación:
                                                    </strong>

                                                    <br>

                                                    <?= fechaMostrar(
                                                        $animal[
                                                            "fecha_asignacion"
                                                        ]
                                                    ) ?>

                                                </div>


                                                <div
                                                    class="animal-dato"
                                                >

                                                    <strong>
                                                        Fecha de finalización:
                                                    </strong>

                                                    <br>

                                                    <?= fechaMostrar(
                                                        $animal[
                                                            "fecha_finalizacion"
                                                        ]
                                                    ) ?>

                                                </div>


                                                <div
                                                    class="animal-dato"
                                                >

                                                    <strong>
                                                        Responsabilidad:
                                                    </strong>

                                                    <br>

                                                    <?= nl2br(
                                                        escapar(
                                                            $animal[
                                                                "responsabilidad"
                                                            ]
                                                            ?: "Sin especificar"
                                                        )
                                                    ) ?>

                                                </div>

                                            </div>


                                            <div
                                                class="acciones-animal"
                                            >

                                                <a
                                                    href="editar_asignacion.php?id=<?= (int)
                                                        $animal[
                                                            "id_cuidador_animal"
                                                        ] ?>"
                                                    class="boton editar"
                                                >

                                                    <i class="bi bi-pencil"></i>

                                                    Editar asignación

                                                </a>


                                                <?php if (
                                                    $animal["estado"]
                                                    === "Activo"
                                                    &&
                                                    $cuidador["estado"]
                                                    === "Activo"
                                                ): ?>

                                                    <a
                                                        href="asignar_tarea.php?id_cuidador_animal=<?= (int)
                                                            $animal[
                                                                "id_cuidador_animal"
                                                            ] ?>"
                                                        class="boton guardar"
                                                    >

                                                        <i class="bi bi-plus-circle"></i>

                                                        Añadir tarea

                                                    </a>

                                                <?php endif; ?>


                                                <button
                                                    type="button"
                                                    class="boton guardar"
                                                    onclick="mostrarTareas(
                                                        <?= (int)
                                                            $animal[
                                                                "id_cuidador_animal"
                                                            ] ?>
                                                    )"
                                                >

                                                    <i class="bi bi-list-check"></i>

                                                    Ver tareas

                                                </button>

                                            </div>


                                            <!-- =================================
                                                 TAREAS
                                            ================================== -->

                                            <div
                                                class="tareas-animal"
                                                id="tareas-animal-<?= (int)
                                                    $animal[
                                                        "id_cuidador_animal"
                                                    ] ?>"
                                                style="display:none;"
                                            >

                                                <p
                                                    class="tareas-titulo"
                                                >

                                                    Tareas asignadas

                                                </p>


                                                <?php if (
                                                    count(
                                                        $animal["tareas"]
                                                    ) > 0
                                                ): ?>


                                                    <?php foreach (
                                                        $animal[
                                                            "tareas"
                                                        ]
                                                        as $tarea
                                                    ): ?>

                                                        <div
                                                            class="tarea-item"
                                                        >

                                                            <div>

                                                                <strong>

                                                                    <?= escapar(
                                                                        $tarea[
                                                                            "nombre_tarea"
                                                                        ]
                                                                    ) ?>

                                                                </strong>

                                                                <br>

                                                                <?= escapar(
                                                                    $tarea[
                                                                        "descripcion"
                                                                    ]
                                                                    ?: "Sin descripción"
                                                                ) ?>

                                                            </div>


                                                            <div>

                                                                <strong>
                                                                    Frecuencia:
                                                                </strong>

                                                                <?= escapar(
                                                                    $tarea[
                                                                        "frecuencia"
                                                                    ]
                                                                    ?: "—"
                                                                ) ?>

                                                            </div>


                                                            <div>

                                                                <strong>
                                                                    Hora:
                                                                </strong>

                                                                <?= horaMostrar(
                                                                    $tarea[
                                                                        "hora_programada"
                                                                    ]
                                                                ) ?>

                                                            </div>


                                                            <div>

                                                                <?php if (
                                                                    $tarea[
                                                                        "estado"
                                                                    ]
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

                                                            </div>

                                                        </div>


                                                        <?php if (
                                                            !empty(
                                                                $tarea[
                                                                    "observaciones"
                                                                ]
                                                            )
                                                        ): ?>

                                                            <div
                                                                style="
                                                                    padding:
                                                                        5px 13px 12px;
                                                                    color:
                                                                        var(--texto-suave);
                                                                    font-size:
                                                                        .75rem;
                                                                "
                                                            >

                                                                <strong>
                                                                    Observaciones:
                                                                </strong>

                                                                <?= nl2br(
                                                                    escapar(
                                                                        $tarea[
                                                                            "observaciones"
                                                                        ]
                                                                    )
                                                                ) ?>

                                                            </div>

                                                        <?php endif; ?>

                                                    <?php endforeach; ?>


                                                <?php else: ?>

                                                    <div
                                                        class="tarea-vacia"
                                                    >

                                                        Este animal no tiene
                                                        tareas asignadas.

                                                    </div>

                                                <?php endif; ?>

                                            </div>

                                        </div>

                                    <?php endforeach; ?>


                                <?php else: ?>

                                    <div
                                        class="tarea-vacia"
                                    >

                                        <i class="bi bi-inbox"></i>

                                        Este cuidador no tiene
                                        animales asignados.

                                    </div>

                                <?php endif; ?>

                            </div>

                        </td>

                    </tr>

                <?php endforeach; ?>


            <?php else: ?>

                <tr>

                    <td
                        colspan="6"
                        class="sin-registros"
                    >

                        No hay cuidadores registrados.

                        <br><br>

                        <a
                            href="cuidador_agregar.php"
                            class="boton guardar"
                        >

                            Registrar el primero

                        </a>

                    </td>

                </tr>

            <?php endif; ?>

            </tbody>

        </table>

    </div>


    <div class="pie-tabla">

        <p
            id="resumen-cuidadores"
            class="resumen-tabla"
        ></p>


        <nav
            id="paginacion-cuidadores"
            class="paginacion"
        ></nav>

    </div>

</section>

</main>


<script>

// =========================================================
// PAGINACIÓN
// =========================================================

(function () {

    const tabla =
        document.getElementById(
            "tabla-cuidadores"
        );


    const buscador =
        document.getElementById(
            "buscador-cuidadores"
        );


    const filtro =
        document.getElementById(
            "filtro-estado"
        );


    const resumen =
        document.getElementById(
            "resumen-cuidadores"
        );


    const paginacion =
        document.getElementById(
            "paginacion-cuidadores"
        );


    if (
        !tabla ||
        !buscador ||
        !filtro
    ) {
        return;
    }


    const filas =
        Array.from(
            tabla.querySelectorAll(
                "tbody > tr.fila-cuidador"
            )
        );


    const porPagina = 6;

    let pagina = 1;


    function actualizar() {

        const termino =
            buscador.value
                .trim()
                .toLocaleLowerCase("es");


        const estado =
            filtro.value;


        const visibles =
            filas.filter(
                function (fila) {

                    const texto =
                        fila.textContent
                            .toLocaleLowerCase(
                                "es"
                            );


                    const coincideTexto =
                        texto.includes(
                            termino
                        );


                    const coincideEstado =
                        !estado ||
                        fila.dataset.estado ===
                            estado;


                    return (
                        coincideTexto &&
                        coincideEstado
                    );

                }
            );


        const totalPaginas =
            Math.max(
                1,
                Math.ceil(
                    visibles.length /
                    porPagina
                )
            );


        if (
            pagina >
            totalPaginas
        ) {

            pagina =
                totalPaginas;

        }


        filas.forEach(
            function (fila) {

                fila.hidden = true;


                const ficha =
                    fila.nextElementSibling;


                if (ficha) {

                    ficha.hidden = true;

                    const contenido =
                        ficha.querySelector(
                            ".ficha"
                        );


                    if (contenido) {

                        contenido.classList
                            .remove(
                                "visible"
                            );

                    }

                }

            }
        );


        const inicio =
            (pagina - 1) *
            porPagina;


        const fin =
            inicio +
            porPagina;


        visibles
            .slice(
                inicio,
                fin
            )
            .forEach(
                function (fila) {

                    fila.hidden =
                        false;

                }
            );


        if (
            visibles.length > 0
        ) {

            const desde =
                inicio + 1;


            const hasta =
                Math.min(
                    fin,
                    visibles.length
                );


            resumen.textContent =
                `Mostrando ${desde}–${hasta} de ${visibles.length} cuidadores`;

        } else {

            resumen.textContent =
                "No hay cuidadores que coincidan con el filtro.";

        }


        paginacion.replaceChildren();


        if (
            totalPaginas <= 1
        ) {
            return;
        }


        crearBoton(
            "‹",
            pagina - 1,
            pagina === 1,
            "Página anterior"
        );


        for (
            let i = 1;
            i <= totalPaginas;
            i++
        ) {

            crearBoton(
                i,
                i,
                false,
                `Página ${i}`,
                i === pagina
            );

        }


        crearBoton(
            "›",
            pagina + 1,
            pagina === totalPaginas,
            "Página siguiente"
        );

    }


    function crearBoton(
        texto,
        destino,
        deshabilitado,
        etiqueta,
        actual = false
    ) {

        const boton =
            document.createElement(
                "button"
            );


        boton.type =
            "button";


        boton.className =
            "pagina-boton" +
            (
                actual
                    ? " activa"
                    : ""
            );


        boton.textContent =
            texto;


        boton.disabled =
            deshabilitado;


        boton.setAttribute(
            "aria-label",
            etiqueta
        );


        boton.addEventListener(
            "click",
            function () {

                pagina =
                    destino;

                actualizar();

            }
        );


        paginacion.append(
            boton
        );

    }


    buscador.addEventListener(
        "input",
        function () {

            pagina = 1;

            actualizar();

        }
    );


    filtro.addEventListener(
        "change",
        function () {

            pagina = 1;

            actualizar();

        }
    );


    actualizar();

})();


// =========================================================
// MOSTRAR FICHA
// =========================================================

function mostrarFicha(id) {

    document
        .querySelectorAll(
            ".fila-ficha"
        )
        .forEach(
            function (fila) {

                fila.hidden =
                    true;


                const ficha =
                    fila.querySelector(
                        ".ficha"
                    );


                if (ficha) {

                    ficha.classList
                        .remove(
                            "visible"
                        );

                }

            }
        );


    const fila =
        document.querySelector(
            `.fila-ficha[data-cuidador="${id}"]`
        );


    if (!fila) {
        return;
    }


    const ficha =
        document.getElementById(
            `ficha-${id}`
        );


    if (!ficha) {
        return;
    }


    fila.hidden =
        false;


    ficha.classList.add(
        "visible"
    );


    ficha.scrollIntoView({
        behavior: "smooth",
        block: "start"
    });

}


// =========================================================
// CERRAR FICHA
// =========================================================

function cerrarFicha(id) {

    const fila =
        document.querySelector(
            `.fila-ficha[data-cuidador="${id}"]`
        );


    const ficha =
        document.getElementById(
            `ficha-${id}`
        );


    if (ficha) {

        ficha.classList.remove(
            "visible"
        );

    }


    if (fila) {

        fila.hidden =
            true;

    }

}


// =========================================================
// MOSTRAR / OCULTAR TAREAS
// =========================================================

function mostrarTareas(
    idCuidadorAnimal
) {

    const tareas =
        document.getElementById(
            `tareas-animal-${idCuidadorAnimal}`
        );


    if (!tareas) {
        return;
    }


    if (
        tareas.style.display ===
        "none"
    ) {

        tareas.style.display =
            "block";

    } else {

        tareas.style.display =
            "none";

    }

}

</script>


</body>

</html>