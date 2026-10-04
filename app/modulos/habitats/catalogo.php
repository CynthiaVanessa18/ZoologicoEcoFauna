<?php

require_once __DIR__ . "/../../soporte/sesion.php";
require_once __DIR__ . "/../../../config/database.php";
require_once __DIR__ . "/funciones.php";

/** @var mysqli $conexion */

/*
|--------------------------------------------------------------------------
| CONTROL DE ACCESO
|--------------------------------------------------------------------------
*/

if (
    !isset(
        $_SESSION["usuario"],
        $_SESSION["rol"],
        $_SESSION["id_login"]
    ) ||
    $_SESSION["rol"] !== "Cliente"
) {
    header("Location: index.php");
    exit();
}

/*
|--------------------------------------------------------------------------
| FUNCIONES
|--------------------------------------------------------------------------
*/

function escaparCliente(string $texto): string
{
    return htmlspecialchars(
        $texto,
        ENT_QUOTES,
        "UTF-8"
    );
}

/*
|--------------------------------------------------------------------------
| PREPARAR IMAGEN
|--------------------------------------------------------------------------
|
| Permite trabajar con:
|
| 1. URL completa
| 2. Ruta relativa
| 3. Ruta absoluta dentro del proyecto
|
|--------------------------------------------------------------------------
*/

function imagenCliente(?string $imagen): string
{
    $imagen = trim((string) $imagen);

    if ($imagen === "") {
        return "";
    }

    // Si ya es una URL completa
    if (filter_var($imagen, FILTER_VALIDATE_URL)) {
        return $imagen;
    }

    // Normalizar separadores de Windows
    $imagen = str_replace("\\", "/", $imagen);

    // Eliminar posibles rutas absolutas guardadas en BD
    $imagen = basename($imagen);

    // Ruta pública desde habitats.php
    return "uploads/habitats/" . $imagen;
}

/*
|--------------------------------------------------------------------------
| CONSULTAR HÁBITATS
|--------------------------------------------------------------------------
*/

function obtenerHabitatsCliente(mysqli $conexion): array
{
    $sql = "
        SELECT
            h.id_habitat,
            h.nombre,
            h.tipo_habitat,
            h.zona,
            h.area_m2,
            h.capacidad_animales,
            h.descripcion,
            h.caracteristicas,
            h.foto,
            h.icono,
            h.estado,

            a.id_animal,
            a.codigo_animal,
            a.nombre_animal,
            a.fecha_nacimiento,
            a.fecha_entrada,
            a.peso,
            a.altura,
            a.foto AS foto_animal

        FROM habitat h

        LEFT JOIN animal a
            ON a.id_habitat = h.id_habitat

        WHERE h.estado = 'Activo'

        ORDER BY
            h.nombre ASC,
            a.nombre_animal ASC
    ";

    $resultado = $conexion->query($sql);

    if (!$resultado) {
        throw new RuntimeException(
            "No fue posible consultar los hábitats: " .
            $conexion->error
        );
    }

    $habitats = [];

    while ($fila = $resultado->fetch_assoc()) {

        $idHabitat = (int) $fila["id_habitat"];

        /*
        |--------------------------------------------------------------------------
        | CREAR HÁBITAT
        |--------------------------------------------------------------------------
        */

        if (!isset($habitats[$idHabitat])) {

            $habitats[$idHabitat] = [

                "id_habitat" =>
                    $idHabitat,

                "nombre" =>
                    $fila["nombre"] ?? "",

                "tipo_habitat" =>
                    $fila["tipo_habitat"] ?? "",

                "zona" =>
                    $fila["zona"] ?? "",

                "area_m2" =>
                    $fila["area_m2"] ?? 0,

                "capacidad_animales" =>
                    $fila["capacidad_animales"] ?? 0,

                "descripcion" =>
                    $fila["descripcion"] ?? "",

                "caracteristicas" =>
                    $fila["caracteristicas"] ?? "",

                "foto" =>
                    $fila["foto"] ?? "",

                "icono" =>
                    $fila["icono"] ??
                    "bi-tree-fill",

                "estado" =>
                    $fila["estado"] ??
                    "Activo",

                "animales" => []
            ];
        }

        /*
        |--------------------------------------------------------------------------
        | AGREGAR ANIMAL
        |--------------------------------------------------------------------------
        */

        if (
            !empty(
                $fila["id_animal"]
            )
        ) {

            $habitats[$idHabitat]["animales"][] = [

                "id_animal" =>
                    (int) $fila["id_animal"],

                "codigo_animal" =>
                    $fila["codigo_animal"] ?? "",

                "nombre_animal" =>
                    $fila["nombre_animal"] ?? "",

                "fecha_nacimiento" =>
                    $fila["fecha_nacimiento"] ?? "",

                "fecha_entrada" =>
                    $fila["fecha_entrada"] ?? "",

                "peso" =>
                    $fila["peso"] ?? 0,

                "altura" =>
                    $fila["altura"] ?? 0,

                "foto" =>
                    $fila["foto_animal"] ?? ""
            ];
        }
    }

    $resultado->free();

    return array_values($habitats);
}

/*
|--------------------------------------------------------------------------
| CARGAR HÁBITATS
|--------------------------------------------------------------------------
*/

$habitats = [];

$error = "";

try {

    $habitats =
        obtenerHabitatsCliente(
            $conexion
        );

} catch (Throwable $excepcion) {

    error_log(
        "Error cargando hábitats para cliente: " .
        $excepcion->getMessage()
    );

    $error =
        "No fue posible cargar los hábitats en este momento.";
}

/*
|--------------------------------------------------------------------------
| ZONAS DISPONIBLES
|--------------------------------------------------------------------------
*/

$zonas = [];

foreach ($habitats as $habitat) {

    $zonaHabitat =
        trim(
            (string) (
                $habitat["zona"] ?? ""
            )
        );

    if ($zonaHabitat !== "") {
        $zonas[] = $zonaHabitat;
    }
}

$zonas = array_values(
    array_unique($zonas)
);

sort(
    $zonas,
    SORT_NATURAL |
    SORT_FLAG_CASE
);

/*
|--------------------------------------------------------------------------
| FILTROS
|--------------------------------------------------------------------------
*/

$busqueda = trim(
    $_GET["buscar"] ?? ""
);

$zona = trim(
    $_GET["zona"] ?? ""
);

/*
|--------------------------------------------------------------------------
| VALIDAR ZONA
|--------------------------------------------------------------------------
*/

if (
    $zona !== "" &&
    !in_array(
        $zona,
        $zonas,
        true
    )
) {
    $zona = "";
}

/*
|--------------------------------------------------------------------------
| FILTRAR HÁBITATS
|--------------------------------------------------------------------------
*/

$habitatsVisibles = [];

foreach ($habitats as $habitat) {

    /*
    |--------------------------------------------------------------------------
    | FILTRO ZONA
    |--------------------------------------------------------------------------
    */

    if (
        $zona !== "" &&
        ($habitat["zona"] ?? "") !== $zona
    ) {
        continue;
    }

    /*
    |--------------------------------------------------------------------------
    | FILTRO DE BÚSQUEDA
    |--------------------------------------------------------------------------
    */

    if ($busqueda !== "") {

        $textoBusqueda = implode(
            " ",
            [

                $habitat["nombre"] ?? "",

                $habitat["tipo_habitat"] ?? "",

                $habitat["zona"] ?? "",

                $habitat["descripcion"] ?? "",

                $habitat["caracteristicas"] ?? ""
            ]
        );

        /*
        | Agregar animales
        */

        foreach (
            $habitat["animales"] ?? []
            as $animal
        ) {

            $textoBusqueda .=
                " " .
                ($animal["nombre_animal"] ?? "");

            $textoBusqueda .=
                " " .
                ($animal["codigo_animal"] ?? "");
        }

        if (
            stripos(
                $textoBusqueda,
                $busqueda
            ) === false
        ) {
            continue;
        }
    }

    $habitatsVisibles[] =
        $habitat;
}

/*
|--------------------------------------------------------------------------
| TOTAL DE ANIMALES
|--------------------------------------------------------------------------
*/

$totalAnimales = 0;

foreach ($habitats as $habitat) {

    $totalAnimales += count(
        $habitat["animales"] ?? []
    );
}

/*
|--------------------------------------------------------------------------
| USUARIO
|--------------------------------------------------------------------------
*/

$nombreUsuario =
    escaparCliente(
        (string) $_SESSION["usuario"]
    );

/*
|--------------------------------------------------------------------------
| HÁBITAT / ANIMAL SELECCIONADO
|--------------------------------------------------------------------------
*/

$idAnimalSeleccionado =
    (int) (
        $_GET["animal"] ?? 0
    );

$idHabitatSeleccionado =
    (int) (
        $_GET["habitat"] ?? 0
    );

$animalesDisponibles = [];

$habitatSeleccionado = null;

$animalSeleccionado = null;

/*
|--------------------------------------------------------------------------
| RECORRER HÁBITATS
|--------------------------------------------------------------------------
*/

foreach ($habitats as $habitat) {

    foreach (
        $habitat["animales"] ?? []
        as $animal
    ) {

        $animal["id_habitat"] =
            (int) $habitat["id_habitat"];

        $animal["habitat_nombre"] =
            $habitat["nombre"] ??
            "Hábitat";

        $animalesDisponibles[] =
            $animal;

        if (
            (int) $animal["id_animal"] ===
            $idAnimalSeleccionado
        ) {

            $animalSeleccionado =
                $animal;

            $idHabitatSeleccionado =
                (int) $habitat["id_habitat"];
        }
    }

    if (
        (int) $habitat["id_habitat"] ===
        $idHabitatSeleccionado
    ) {

        $habitatSeleccionado =
            $habitat;
    }
}

/*
|--------------------------------------------------------------------------
| HÁBITAT POR DEFECTO
|--------------------------------------------------------------------------
*/

if (
    $habitatSeleccionado === null &&
    !empty($habitats)
) {

    $habitatSeleccionado =
        $habitats[0];

    $idHabitatSeleccionado =
        (int)
        $habitatSeleccionado[
            "id_habitat"
        ];
}

/*
|--------------------------------------------------------------------------
| ANIMAL POR DEFECTO
|--------------------------------------------------------------------------
*/

if (
    $animalSeleccionado === null &&
    $habitatSeleccionado !== null
) {

    $animalSeleccionado =
        $habitatSeleccionado["animales"][0]
        ?? null;
}

/*
|--------------------------------------------------------------------------
| CARACTERÍSTICAS
|--------------------------------------------------------------------------
*/

$caracteristicasSeleccionadas =
    [];

if ($habitatSeleccionado !== null) {

    $caracteristicasSeleccionadas =
        preg_split(
            '/[\r\n,;]+/',
            (string) (
                $habitatSeleccionado[
                    "caracteristicas"
                ] ?? ""
            )
        ) ?: [];

    $caracteristicasSeleccionadas =
        array_values(
            array_filter(
                array_map(
                    "trim",
                    $caracteristicasSeleccionadas
                )
            )
        );
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
        Hábitats - EcoFauna
    </title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <link
        rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css"
    >

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
        href="css/habitats.css"
    >

    <style>

        html {
            scroll-behavior: smooth;
        }

        body {
            font-family: "Poppins", sans-serif;
        }

        /*
        |--------------------------------------------------------------------------
        | CONTENIDO
        |--------------------------------------------------------------------------
        */

        .contenido-habitats {
            padding-top: 35px;
            padding-bottom: 60px;
        }

        /*
        |--------------------------------------------------------------------------
        | FILTROS
        |--------------------------------------------------------------------------
        */

        .panel-filtros {

            background: #ffffff;

            border-radius: 20px;

            padding: 25px;

            margin-bottom: 35px;

            border:
                1px solid
                rgba(48, 71, 52, .10);

            box-shadow:
                0 8px 25px
                rgba(48, 71, 52, .08);
        }

        .titulo-filtros {

            color: #304734;

            font-weight: 700;

            margin-bottom: 5px;
        }

        .texto-filtros {

            color: #777;

            font-size: 14px;

            margin-bottom: 20px;
        }

        .form-control,
        .form-select {

            min-height: 48px;

            border-radius: 12px;

            border-color:
                #d9dfd5;
        }

        .form-control:focus,
        .form-select:focus {

            border-color: #607754;

            box-shadow:
                0 0 0 .2rem
                rgba(96,119,84,.15);
        }

        .btn-filtrar {

            min-height: 48px;

            border-radius: 12px;

            background: #607754;

            border-color: #607754;

            color: white;

            font-weight: 600;
        }

        .btn-filtrar:hover {

            background: #304734;

            border-color: #304734;

            color: white;
        }

        .btn-limpiar {

            min-height: 48px;

            border-radius: 12px;

            font-weight: 600;
        }

        /*
        |--------------------------------------------------------------------------
        | RESULTADOS
        |--------------------------------------------------------------------------
        */

        .resultado-filtros {

            display: flex;

            justify-content: space-between;

            align-items: center;

            gap: 15px;

            margin-bottom: 20px;

            flex-wrap: wrap;
        }

        .resultado-filtros strong {
            color: #304734;
        }

        /*
        |--------------------------------------------------------------------------
        | TARJETAS DE HÁBITAT
        |--------------------------------------------------------------------------
        */

        .grid-habitats {

            display: grid;

            grid-template-columns:
                repeat(
                    auto-fit,
                    minmax(300px, 1fr)
                );

            gap: 25px;
        }

        .card-habitat {

            background: #ffffff;

            border-radius: 20px;

            overflow: hidden;

            border:
                1px solid
                rgba(48, 71, 52, .10);

            box-shadow:
                0 8px 25px
                rgba(48, 71, 52, .08);

            transition:
                transform .2s ease,
                box-shadow .2s ease;
        }

        .card-habitat:hover {

            transform: translateY(-4px);

            box-shadow:
                0 15px 35px
                rgba(48, 71, 52, .14);
        }

        /*
        |--------------------------------------------------------------------------
        | IMAGEN DEL HÁBITAT
        |--------------------------------------------------------------------------
        */

        .imagen-card-habitat {

            width: 100%;

            height: 220px;

            display: block;

            object-fit: cover;

            object-position: center;

            background: #e8efe2;
        }

        .placeholder-card-habitat {

            width: 100%;

            height: 220px;

            display: flex;

            align-items: center;

            justify-content: center;

            background: #e8efe2;

            color: #607754;

            font-size: 60px;
        }

        .contenido-card-habitat {

            padding: 22px;
        }

        .badge-zona {

            display: inline-flex;

            align-items: center;

            gap: 5px;

            padding: 5px 10px;

            border-radius: 20px;

            background: #e8efe2;

            color: #304734;

            font-size: 12px;

            font-weight: 600;

            margin-bottom: 10px;
        }

        .contenido-card-habitat h3 {

            color: #304734;

            font-weight: 700;

            margin-bottom: 8px;
        }

        .tipo-habitat {

            color: #607754;

            font-size: 14px;

            font-weight: 600;

            margin-bottom: 12px;
        }

        .descripcion-card {

            color: #666;

            font-size: 14px;

            line-height: 1.6;

            min-height: 68px;
        }

        .datos-card {

            display: grid;

            grid-template-columns:
                repeat(2, 1fr);

            gap: 10px;

            margin-top: 18px;
        }

        .dato-card {

            padding: 10px;

            background: #f8f4ec;

            border-radius: 10px;

            font-size: 12px;

            color: #555;
        }

        .dato-card i {

            color: #607754;

            margin-right: 4px;
        }

        .btn-ver-card {

            width: 100%;

            margin-top: 18px;

            background: #607754;

            border-color: #607754;

            color: white;

            border-radius: 10px;

            padding: 10px;

            font-weight: 600;
        }

        .btn-ver-card:hover {

            background: #304734;

            border-color: #304734;

            color: white;
        }

        /*
        |--------------------------------------------------------------------------
        | ANIMALES
        |--------------------------------------------------------------------------
        */

        .lista-animales-habitat {

            margin-top: 20px;

            padding-top: 18px;

            border-top:
                1px solid
                rgba(48,71,52,.12);
        }

        .lista-animales-habitat > small {

            display: block;

            margin-bottom: 12px;

            color: #607754;

            font-weight: 600;
        }

        .animales-grid-cliente {

            display: grid;

            grid-template-columns:
                repeat(
                    auto-fit,
                    minmax(180px, 1fr)
                );

            gap: 12px;
        }

        .animal-cliente {

            display: flex;

            align-items: center;

            gap: 10px;

            padding: 10px;

            background: #f8f4ec;

            border-radius: 12px;
        }

        .foto-animal-cliente {

            width: 48px;

            height: 48px;

            min-width: 48px;

            object-fit: cover;

            object-position: center;

            border-radius: 50%;

            display: block;
        }

        .foto-animal-placeholder {

            width: 48px;

            height: 48px;

            min-width: 48px;

            display: flex;

            align-items: center;

            justify-content: center;

            border-radius: 50%;

            background: #e8efe2;

            color: #607754;

            font-size: 20px;
        }

        .datos-animal-cliente {

            min-width: 0;
        }

        .datos-animal-cliente strong {

            display: block;

            font-size: 14px;

            color: #304734;
        }

        .datos-animal-cliente small {

            display: block;

            color: #777;

            font-size: 11px;
        }

        .contador-animales-habitat {

            display: inline-flex;

            align-items: center;

            gap: 5px;

            margin-top: 12px;

            padding: 5px 10px;

            border-radius: 20px;

            background: #e8efe2;

            color: #304734;

            font-size: 12px;

            font-weight: 600;
        }

        /*
        |--------------------------------------------------------------------------
        | HÁBITAT SELECCIONADO
        |--------------------------------------------------------------------------
        */

        .tarjeta-habitat-cliente {

            background: white;

            border-radius: 22px;

            border:
                1px solid
                rgba(48,71,52,.10);

            box-shadow:
                0 8px 25px
                rgba(48,71,52,.08);

            overflow: hidden;
        }

        .cabecera-habitat-cliente {

            display: flex;

            justify-content: space-between;

            align-items: center;

            gap: 15px;

            padding: 18px 25px;

            background: #f8f4ec;

            flex-wrap: wrap;
        }

        .icono-habitat-cliente {

            width: 45px;

            height: 45px;

            display: flex;

            align-items: center;

            justify-content: center;

            border-radius: 50%;

            background: #607754;

            color: white;

            font-size: 20px;
        }

        .zona-habitat-cliente {

            color: #607754;

            font-weight: 600;
        }

        .cuerpo-habitat-cliente {

            padding: 30px;
        }

        /*
        |--------------------------------------------------------------------------
        | IMAGEN PRINCIPAL
        |--------------------------------------------------------------------------
        */

        .foto-habitat-cliente {

            width: 100%;

            height: 300px;

            display: block;

            object-fit: cover;

            object-position: center;

            border-radius: 16px;

            box-shadow:
                0 4px 12px
                rgba(0,0,0,.08);

            background: #e8efe2;
        }

        .foto-habitat-placeholder-cliente {

            width: 100%;

            height: 300px;

            display: flex;

            align-items: center;

            justify-content: center;

            background: #e8efe2;

            border-radius: 16px;

            font-size: 60px;

            color: #607754;
        }

        /*
        |--------------------------------------------------------------------------
        | VACÍO
        |--------------------------------------------------------------------------
        */

        .vacio-habitats {

            text-align: center;

            padding: 80px 20px;

            color: #777;
        }

        .vacio-habitats i {

            font-size: 70px;

            color: #607754;
        }

        /*
        |--------------------------------------------------------------------------
        | RESPONSIVE
        |--------------------------------------------------------------------------
        */

        @media (max-width: 768px) {

            .contenido-habitats {
                padding-top: 20px;
            }

            .cuerpo-habitat-cliente {
                padding: 20px;
            }

            .foto-habitat-cliente,
            .foto-habitat-placeholder-cliente {
                height: 240px;
            }

            .datos-card {
                grid-template-columns: 1fr;
            }

        }

    </style>

    <link rel="stylesheet" href="css/crud-modern.css?v=1">
    <link rel="stylesheet" href="css/ecofauna-unified.css?v=1">
</head>

<body class="ecofauna-unified crud-modern">



<!--
|--------------------------------------------------------------------------
| NAVBAR
|--------------------------------------------------------------------------
-->

<nav class="navbar-habitats">

    <div class="container barra-habitats">

        <a
            href="cliente.php"
            class="marca-habitats"
        >

            <img
                src="img/LogoEcoFauna1.png"
                alt="Logo EcoFauna"
            >

            <span>

                <strong>
                    EcoFauna
                </strong>

                <small>
                    Portal del visitante
                </small>

            </span>

        </a>

        <div class="usuario-habitats">

            <div class="nombre-cliente-habitats">

                <i class="bi bi-person-fill"></i>

                <span>

                    <small>
                        Visitante
                    </small>

                    <strong>
                        <?= $nombreUsuario ?>
                    </strong>

                </span>

            </div>

            <a
                href="cliente.php"
                class="btn-volver-habitats"
            >

                <i class="bi bi-arrow-left"></i>

                Volver

            </a>

            <form
                action="logout.php"
                method="POST"
            >

                <?= campoCsrfSesion("logout") ?>

                <button
                    type="submit"
                    class="btn-salir-habitats"
                >

                    <i class="bi bi-box-arrow-right"></i>

                    <span>
                        Cerrar sesión
                    </span>

                </button>

            </form>

        </div>

    </div>

</nav>

<!--
|--------------------------------------------------------------------------
| HERO
|--------------------------------------------------------------------------
-->

<header class="hero-habitats">

    <div class="container hero-habitats-contenido">

        <div>

            <span class="etiqueta-habitats">

                <i class="bi bi-compass-fill"></i>

                Explora EcoFauna

            </span>

            <h1>
                Hábitats llenos de vida
            </h1>

            <p>
                Conoce los ambientes del zoológico,
                descubre qué animales encontrarás
                y explora cada espacio.
            </p>

        </div>

        <div
            class="ilustracion-habitats"
            aria-hidden="true"
        >

            <span class="planeta-habitats">

                <i class="bi bi-globe-americas"></i>

            </span>

            <span class="mini-habitat mini-habitat-uno">
                <i class="bi bi-tree-fill"></i>
            </span>

            <span class="mini-habitat mini-habitat-dos">
                <i class="bi bi-water"></i>
            </span>

            <span class="mini-habitat mini-habitat-tres">
                <i class="bi bi-feather"></i>
            </span>

        </div>

    </div>

</header>

<main class="container contenido-habitats">

    <?php if ($error !== ""): ?>

        <div
            class="alert alert-danger"
            role="alert"
        >

            <i class="bi bi-exclamation-triangle-fill"></i>

            <?= escaparCliente($error) ?>

        </div>

    <?php endif; ?>

    <!--
    |--------------------------------------------------------------------------
    | FILTROS
    |--------------------------------------------------------------------------
    -->

    <section class="panel-filtros">

        <h2 class="titulo-filtros">

            <i class="bi bi-funnel-fill me-2"></i>

            Buscar hábitats

        </h2>

        <p class="texto-filtros">

            Filtra por nombre, tipo de hábitat,
            zona o incluso por el nombre de un animal.

        </p>

        <form
            method="GET"
            action="habitats.php"
            class="row g-3"
        >

            <div class="col-lg-6">

                <label
                    for="buscar"
                    class="form-label fw-semibold"
                >
                    Buscar
                </label>

                <div class="input-group">

                    <span class="input-group-text">

                        <i class="bi bi-search"></i>

                    </span>

                    <input
                        type="search"
                        class="form-control"
                        id="buscar"
                        name="buscar"
                        value="<?= escaparCliente($busqueda) ?>"
                        placeholder="Ejemplo: sabana, león, bosque..."
                    >

                </div>

            </div>

            <div class="col-lg-4">

                <label
                    for="zona"
                    class="form-label fw-semibold"
                >
                    Zona
                </label>

                <select
                    class="form-select"
                    id="zona"
                    name="zona"
                >

                    <option value="">
                        Todas las zonas
                    </option>

                    <?php foreach ($zonas as $zonaDisponible): ?>

                        <option
                            value="<?= escaparCliente($zonaDisponible) ?>"
                            <?= $zona === $zonaDisponible
                                ? "selected"
                                : ""
                            ?>
                        >

                            <?= escaparCliente($zonaDisponible) ?>

                        </option>

                    <?php endforeach; ?>

                </select>

            </div>

            <div class="col-lg-2 d-flex gap-2 align-items-end">

                <button
                    type="submit"
                    class="btn btn-filtrar flex-grow-1"
                >

                    <i class="bi bi-search me-1"></i>

                    Filtrar

                </button>

                <a
                    href="habitats.php"
                    class="btn btn-outline-secondary btn-limpiar"
                    title="Limpiar filtros"
                >

                    <i class="bi bi-x-lg"></i>

                </a>

            </div>

        </form>

    </section>

    <!--
    |--------------------------------------------------------------------------
    | RESULTADOS
    |--------------------------------------------------------------------------
    -->

    <div class="resultado-filtros">

        <div>

            <strong>
                <?= count($habitatsVisibles) ?>
            </strong>

            hábitat(s) encontrado(s)

        </div>

        <?php if ($busqueda !== "" || $zona !== ""): ?>

            <div class="text-muted small">

                Filtros activos:

                <?php if ($busqueda !== ""): ?>

                    <span class="badge text-bg-light">
                        <?= escaparCliente($busqueda) ?>
                    </span>

                <?php endif; ?>

                <?php if ($zona !== ""): ?>

                    <span class="badge text-bg-light">
                        <?= escaparCliente($zona) ?>
                    </span>

                <?php endif; ?>

            </div>

        <?php endif; ?>

    </div>

    <!--
    |--------------------------------------------------------------------------
    | LISTA DE HÁBITATS FILTRADOS
    |--------------------------------------------------------------------------
    -->

    <?php if (empty($habitatsVisibles)): ?>

        <section class="vacio-habitats">

            <i class="bi bi-search"></i>

            <h2>
                No encontramos hábitats
            </h2>

            <p>
                Intenta cambiar el texto de búsqueda
                o seleccionar otra zona.
            </p>

            <a
                href="habitats.php"
                class="btn btn-ver-habitat mt-2"
            >

                <i class="bi bi-arrow-counterclockwise"></i>

                Mostrar todos

            </a>

        </section>

    <?php else: ?>

        <section class="grid-habitats mb-5">

            <?php foreach ($habitatsVisibles as $habitat): ?>

                <?php

                $imagenHabitat =
                    imagenCliente(
                        $habitat["foto"] ?? ""
                    );

                ?>

                <article class="card-habitat">

                    <?php if ($imagenHabitat !== ""): ?>

                        <img
                            src="<?= escaparCliente($imagenHabitat) ?>"
                            alt="Imagen del hábitat <?= escaparCliente($habitat["nombre"]) ?>"
                            class="imagen-card-habitat"
                            loading="lazy"
                            onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';"
                        >

                        <div
                            class="placeholder-card-habitat"
                            style="display:none;"
                        >

                            <i
                                class="bi <?= escaparCliente(
                                    $habitat["icono"]
                                    ?: "bi-tree-fill"
                                ) ?>"
                            ></i>

                        </div>

                    <?php else: ?>

                        <div class="placeholder-card-habitat">

                            <i
                                class="bi <?= escaparCliente(
                                    $habitat["icono"]
                                    ?: "bi-tree-fill"
                                ) ?>"
                            ></i>

                        </div>

                    <?php endif; ?>

                    <div class="contenido-card-habitat">

                        <span class="badge-zona">

                            <i class="bi bi-geo-alt-fill"></i>

                            <?= escaparCliente(
                                $habitat["zona"]
                                ?: "Zona sin definir"
                            ) ?>

                        </span>

                        <h3>

                            <?= escaparCliente(
                                $habitat["nombre"]
                            ) ?>

                        </h3>

                        <div class="tipo-habitat">

                            <i class="bi bi-tree-fill me-1"></i>

                            <?= escaparCliente(
                                $habitat["tipo_habitat"]
                                ?: "Tipo no definido"
                            ) ?>

                        </div>

                        <p class="descripcion-card">

                            <?= escaparCliente(
                                $habitat["descripcion"]
                                ?:
                                "Sin descripción registrada."
                            ) ?>

                        </p>

                        <div class="datos-card">

                            <div class="dato-card">

                                <i class="bi bi-rulers"></i>

                                <?= number_format(
                                    (float)
                                    $habitat["area_m2"],
                                    2,
                                    ",",
                                    "."
                                ) ?>

                                m²

                            </div>

                            <div class="dato-card">

                                <i class="bi bi-people-fill"></i>

                                <?= count(
                                    $habitat["animales"] ?? []
                                ) ?>

                                animales

                            </div>

                            <div class="dato-card">

                                <i class="bi bi-house-fill"></i>

                                Capacidad:

                                <?= (int)
                                    $habitat[
                                        "capacidad_animales"
                                    ]
                                ?>

                            </div>

                            <div class="dato-card">

                                <i class="bi bi-hash"></i>

                                HAB-

                                <?= str_pad(
                                    (string)
                                    $habitat[
                                        "id_habitat"
                                    ],
                                    3,
                                    "0",
                                    STR_PAD_LEFT
                                ) ?>

                            </div>

                        </div>

                        <a
                            href="habitats.php?habitat=<?= (int) $habitat["id_habitat"] ?>#habitat-actual"
                            class="btn btn-ver-card"
                        >

                            <i class="bi bi-eye me-1"></i>

                            Ver hábitat

                        </a>

                    </div>

                </article>

            <?php endforeach; ?>

        </section>

    <?php endif; ?>

    <?php if ($habitatSeleccionado !== null): ?>

        <!--
        |--------------------------------------------------------------------------
        | SELECTOR
        |--------------------------------------------------------------------------
        -->

        <section
            id="habitats-zoologico"
            class="mb-4"
        >

            <h2 class="mb-1">
                Explorar un hábitat
            </h2>

            <p class="text-muted mb-4">

                Selecciona un animal o un hábitat
                para consultar la información.

            </p>

            <form
                method="GET"
                action="habitats.php#habitat-actual"
                class="row g-3 align-items-end"
            >

                <?php if ($busqueda !== ""): ?>

                    <input
                        type="hidden"
                        name="buscar"
                        value="<?= escaparCliente($busqueda) ?>"
                    >

                <?php endif; ?>

                <?php if ($zona !== ""): ?>

                    <input
                        type="hidden"
                        name="zona"
                        value="<?= escaparCliente($zona) ?>"
                    >

                <?php endif; ?>

                <div class="col-md-5">

                    <label
                        class="form-label fw-semibold"
                        for="animal"
                    >
                        Seleccionar animal
                    </label>

                    <select
                        class="form-select"
                        id="animal"
                        name="animal"
                    >

                        <option value="">
                            — Selecciona un animal —
                        </option>

                        <?php foreach (
                            $animalesDisponibles
                            as $animal
                        ): ?>

                            <option
                                value="<?= (int) $animal["id_animal"] ?>"
                                <?= (
                                    $animalSeleccionado !== null &&
                                    (int)
                                    $animalSeleccionado[
                                        "id_animal"
                                    ] ===
                                    (int)
                                    $animal[
                                        "id_animal"
                                    ]
                                )
                                    ? "selected"
                                    : ""
                                ?>
                            >

                                <?= escaparCliente(
                                    (
                                        $animal[
                                            "nombre_animal"
                                        ]
                                        ?:
                                        "Animal"
                                    )
                                    .
                                    " — "
                                    .
                                    (
                                        $animal[
                                            "codigo_animal"
                                        ]
                                        ?:
                                        "Sin código"
                                    )
                                    .
                                    " — "
                                    .
                                    (
                                        $animal[
                                            "habitat_nombre"
                                        ]
                                        ?:
                                        "Sin hábitat"
                                    )
                                ) ?>

                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>

                <div class="col-md-4">

                    <label
                        class="form-label fw-semibold"
                        for="habitat"
                    >
                        Seleccionar hábitat
                    </label>

                    <select
                        class="form-select"
                        id="habitat"
                        name="habitat"
                    >

                        <?php foreach (
                            $habitats
                            as $habitat
                        ): ?>

                            <option
                                value="<?= (int) $habitat["id_habitat"] ?>"
                                <?= (
                                    $idHabitatSeleccionado ===
                                    (int)
                                    $habitat[
                                        "id_habitat"
                                    ]
                                )
                                    ? "selected"
                                    : ""
                                ?>
                            >

                                <?= escaparCliente(
                                    $habitat["nombre"]
                                ) ?>

                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>

                <div class="col-md-3">

                    <button
                        class="btn btn-filtrar w-100"
                        type="submit"
                    >

                        <i class="bi bi-eye me-1"></i>

                        Ver hábitat

                    </button>

                </div>

            </form>

        </section>

        <!--
        |--------------------------------------------------------------------------
        | HÁBITAT SELECCIONADO
        |--------------------------------------------------------------------------
        -->

        <section
            id="habitat-actual"
            class="tarjeta-habitat-cliente"
        >

            <div class="cabecera-habitat-cliente">

                <span class="icono-habitat-cliente">

                    <i
                        class="bi <?= escaparCliente(
                            $habitatSeleccionado[
                                "icono"
                            ]
                            ?:
                            "bi-tree-fill"
                        ) ?>"
                    ></i>

                </span>

                <span class="zona-habitat-cliente">

                    <i class="bi bi-geo-alt-fill me-1"></i>

                    <?= escaparCliente(
                        $habitatSeleccionado[
                            "zona"
                        ]
                        ?:
                        "Zona sin definir"
                    ) ?>

                </span>

            </div>

            <div class="cuerpo-habitat-cliente">

                <div class="row g-4">

                    <!-- IMAGEN -->

                    <div class="col-lg-5">

                        <?php

                        $imagenPrincipal =
                            imagenCliente(
                                $habitatSeleccionado[
                                    "foto"
                                ] ?? ""
                            );

                        ?>

                        <?php if ($imagenPrincipal !== ""): ?>

                            <img
                                src="<?= escaparCliente($imagenPrincipal) ?>"
                                alt="Foto de <?= escaparCliente(
                                    $habitatSeleccionado[
                                        "nombre"
                                    ]
                                ) ?>"
                                class="foto-habitat-cliente"
                                onerror="this.style.display='none'; document.getElementById('placeholderPrincipal').style.display='flex';"
                            >

                            <div
                                id="placeholderPrincipal"
                                class="foto-habitat-placeholder-cliente"
                                style="display:none;"
                            >

                                <i class="bi bi-tree-fill"></i>

                            </div>

                        <?php else: ?>

                            <div class="foto-habitat-placeholder-cliente">

                                <i class="bi bi-tree-fill"></i>

                            </div>

                        <?php endif; ?>

                    </div>

                    <!-- DATOS -->

                    <div class="col-lg-4">

                        <h2>

                            <?= escaparCliente(
                                $habitatSeleccionado[
                                    "nombre"
                                ]
                            ) ?>

                        </h2>

                        <p class="text-muted">

                            Código:

                            HAB-

                            <?= str_pad(
                                (string)
                                $habitatSeleccionado[
                                    "id_habitat"
                                ],
                                3,
                                "0",
                                STR_PAD_LEFT
                            ) ?>

                        </p>

                        <div class="d-grid gap-3">

                            <div>

                                <i class="bi bi-tree-fill me-2"></i>

                                <strong>
                                    Tipo:
                                </strong>

                                <?= escaparCliente(
                                    $habitatSeleccionado[
                                        "tipo_habitat"
                                    ]
                                    ?:
                                    "—"
                                ) ?>

                            </div>

                            <div>

                                <i class="bi bi-rulers me-2"></i>

                                <strong>
                                    Área:
                                </strong>

                                <?= number_format(
                                    (float)
                                    $habitatSeleccionado[
                                        "area_m2"
                                    ],
                                    2,
                                    ",",
                                    "."
                                ) ?>

                                m²

                            </div>

                            <div>

                                <i class="bi bi-geo-alt me-2"></i>

                                <strong>
                                    Ubicación:
                                </strong>

                                <?= escaparCliente(
                                    $habitatSeleccionado[
                                        "zona"
                                    ]
                                    ?:
                                    "Sin definir"
                                ) ?>

                            </div>

                            <div>

                                <i class="bi bi-people me-2"></i>

                                <strong>
                                    Capacidad:
                                </strong>

                                <?= (int)
                                    $habitatSeleccionado[
                                        "capacidad_animales"
                                    ]
                                ?>

                                animales

                            </div>

                        </div>

                    </div>

                    <!-- DESCRIPCIÓN -->

                    <div class="col-lg-3">

                        <h5>
                            Descripción
                        </h5>

                        <p>

                            <?= escaparCliente(
                                $habitatSeleccionado[
                                    "descripcion"
                                ]
                                ?:
                                "Sin descripción registrada."
                            ) ?>

                        </p>

                        <h6>
                            Características
                        </h6>

                        <?php if (
                            empty(
                                $caracteristicasSeleccionadas
                            )
                        ): ?>

                            <p class="text-muted">
                                Sin características registradas.
                            </p>

                        <?php else: ?>

                            <ul class="list-unstyled">

                                <?php foreach (
                                    $caracteristicasSeleccionadas
                                    as $caracteristica
                                ): ?>

                                    <li class="mb-2">

                                        <i
                                            class="bi bi-check-circle-fill text-success me-2"
                                        ></i>

                                        <?= escaparCliente(
                                            $caracteristica
                                        ) ?>

                                    </li>

                                <?php endforeach; ?>

                            </ul>

                        <?php endif; ?>

                    </div>

                </div>

                <!--
                |--------------------------------------------------------------------------
                | ANIMALES
                |--------------------------------------------------------------------------
                -->

                <div class="lista-animales-habitat">

                    <small>

                        <i class="bi bi-heart-fill me-1"></i>

                        Animales en este hábitat

                    </small>

                    <?php if (
                        empty(
                            $habitatSeleccionado[
                                "animales"
                            ]
                        )
                    ): ?>

                        <p class="text-muted mb-0">

                            Actualmente no hay animales
                            registrados en este hábitat.

                        </p>

                    <?php else: ?>

                        <div class="animales-grid-cliente">

                            <?php foreach (
                                $habitatSeleccionado[
                                    "animales"
                                ]
                                as $animal
                            ): ?>

                                <?php

                                $imagenAnimal =
                                    imagenCliente(
                                        $animal["foto"] ?? ""
                                    );

                                ?>

                                <div class="animal-cliente">

                                    <?php if (
                                        $imagenAnimal !== ""
                                    ): ?>

                                        <img
                                            src="<?= escaparCliente($imagenAnimal) ?>"
                                            alt="<?= escaparCliente(
                                                $animal[
                                                    "nombre_animal"
                                                ]
                                            ) ?>"
                                            class="foto-animal-cliente"
                                            loading="lazy"
                                        >

                                    <?php else: ?>

                                        <div class="foto-animal-placeholder">

                                            <i class="bi bi-heart-fill"></i>

                                        </div>

                                    <?php endif; ?>

                                    <div class="datos-animal-cliente">

                                        <strong>

                                            <?= escaparCliente(
                                                $animal[
                                                    "nombre_animal"
                                                ]
                                                ?:
                                                "Sin nombre"
                                            ) ?>

                                        </strong>

                                        <small>

                                            <?= escaparCliente(
                                                $animal[
                                                    "codigo_animal"
                                                ]
                                                ?:
                                                "Sin código"
                                            ) ?>

                                        </small>

                                    </div>

                                </div>

                            <?php endforeach; ?>

                        </div>

                        <div class="contador-animales-habitat">

                            <i class="bi bi-info-circle-fill"></i>

                            Total de animales:

                            <?= count(
                                $habitatSeleccionado[
                                    "animales"
                                ]
                            ) ?>

                        </div>

                    <?php endif; ?>

                </div>

            </div>

        </section>

    <?php endif; ?>

</main>

<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.bundle.min.js"
></script>

</body>

</html>
