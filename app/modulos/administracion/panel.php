<?php
require_once __DIR__ . "/../../soporte/sesion.php";

require_once __DIR__ . "/../../../config/database.php";
/** @var mysqli $conexion */

if (
    !isset($_SESSION["usuario"], $_SESSION["rol"], $_SESSION["id_login"]) ||
    $_SESSION["rol"] !== "Administrador"
) {
    header("Location: index.php");
    exit();
}

$busqueda = trim($_GET["buscar"] ?? "");
$accionFiltro = trim($_GET["accion"] ?? "");

$accionesPermitidas = [""];
$resultadoAcciones = mysqli_query(
    $conexion,
    "SELECT DISTINCT accion FROM bitacora ORDER BY accion",
);

if ($resultadoAcciones) {
    while ($filaAccion = mysqli_fetch_assoc($resultadoAcciones)) {
        $accionDisponible = trim((string) ($filaAccion["accion"] ?? ""));

        if ($accionDisponible !== "") {
            $accionesPermitidas[] = $accionDisponible;
        }
    }
}

if (!in_array($accionFiltro, $accionesPermitidas, true)) {
    $accionFiltro = "";
}

// -----------------------------------------------------
// CONFIGURACIÓN DE PAGINACIÓN
// -----------------------------------------------------
$regPorPag = 10;
$pagActual = filter_input(INPUT_GET, "pagina", FILTER_VALIDATE_INT) ?: 1;
if ($pagActual < 1) {
    $pagActual = 1;
}

// 1. Obtener número total de registros coincidentes
$sqlCount = "
    SELECT COUNT(*) AS total
    FROM bitacora b
    WHERE 1 = 1
";

$tiposCount = "";
$parametrosCount = [];

if ($busqueda !== "") {
    $sqlCount .= "
        AND (
            b.usuario LIKE ?
            OR b.accion LIKE ?
            OR b.tabla_afectada LIKE ?
            OR b.detalles LIKE ?
            OR b.ip LIKE ?
        )
    ";
    $valorBusqueda = "%" . $busqueda . "%";
    $tiposCount .= "sssss";
    $parametrosCount = array_fill(0, 5, $valorBusqueda);
}

if ($accionFiltro !== "") {
    $sqlCount .= " AND b.accion = ? ";
    $tiposCount .= "s";
    $parametrosCount[] = $accionFiltro;
}

$stmtCount = mysqli_prepare($conexion, $sqlCount);
if (!$stmtCount) {
    error_log(mysqli_error($conexion));
    die("No fue posible contar los registros de la bitácora.");
}
if ($tiposCount !== "") {
    mysqli_stmt_bind_param($stmtCount, $tiposCount, ...$parametrosCount);
}
mysqli_stmt_execute($stmtCount);
$resCount = mysqli_stmt_get_result($stmtCount);
$totalRegistros = mysqli_fetch_assoc($resCount)["total"] ?? 0;
mysqli_stmt_close($stmtCount);

$totalPaginas = ceil($totalRegistros / $regPorPag);
if ($pagActual > $totalPaginas && $totalPaginas > 0) {
    $pagActual = $totalPaginas;
}
$offset = ($pagActual - 1) * $regPorPag;

// 2. Obtener los registros paginados
$sql = "
    SELECT
        b.id_bitacora,
        b.usuario,
        b.accion,
        b.tabla_afectada,
        b.registro_id,
        b.detalles,
        b.ip,
        b.fecha_hora
    FROM bitacora b
    WHERE 1 = 1
";

$tipos = "";
$parametros = [];

if ($busqueda !== "") {
    $sql .= "
        AND (
            b.usuario LIKE ?
            OR b.accion LIKE ?
            OR b.tabla_afectada LIKE ?
            OR b.detalles LIKE ?
            OR b.ip LIKE ?
        )
    ";
    $valorBusqueda = "%" . $busqueda . "%";
    $tipos .= "sssss";
    $parametros = array_fill(0, 5, $valorBusqueda);
}

if ($accionFiltro !== "") {
    $sql .= " AND b.accion = ? ";
    $tipos .= "s";
    $parametros[] = $accionFiltro;
}

$sql .= " ORDER BY b.fecha_hora DESC LIMIT ? OFFSET ? ";
$tipos .= "ii";
$parametros[] = $regPorPag;
$parametros[] = $offset;

$stmt = mysqli_prepare($conexion, $sql);
if (!$stmt) {
    error_log(mysqli_error($conexion));
    die("No fue posible cargar la bitácora.");
}
if ($tipos !== "") {
    mysqli_stmt_bind_param($stmt, $tipos, ...$parametros);
}
mysqli_stmt_execute($stmt);
$resultado = mysqli_stmt_get_result($stmt);

// 3. Interceptor AJAX para recargas dinámicas
if (isset($_GET["ajax"]) && $_GET["ajax"] == 1) {
    ob_start();
    if (!$resultado || mysqli_num_rows($resultado) === 0) {
        echo "<tr>";
        echo '<td colspan="7">';
        echo '<div class="estado-vacio">';
        echo '<i class="bi bi-inbox"></i>';
        echo "<h3>No hay registros disponibles</h3>";
        echo "<p>No se encontraron acciones con los filtros seleccionados.</p>";
        echo "</div>";
        echo "</td>";
        echo "</tr>";
    } else {
        while ($fila = mysqli_fetch_assoc($resultado)) {
            $accion = strtoupper((string) $fila["accion"]);
            $claseAccion = "accion-neutra";
            $iconoAccion = "bi-dot";

            if (str_contains($accion, "LOGIN")) {
                $claseAccion = "accion-login";
                $iconoAccion = "bi-box-arrow-in-right";
            } elseif (str_contains($accion, "LOGOUT")) {
                $claseAccion = "accion-logout";
                $iconoAccion = "bi-box-arrow-right";
            } elseif (str_contains($accion, "VENTA")) {
                $claseAccion = "accion-venta";
                $iconoAccion = "bi-ticket-perforated-fill";
            } elseif (str_contains($accion, "DIETA")) {
                $claseAccion = "accion-dieta";
                $iconoAccion = "bi-clipboard2-heart-fill";
            } elseif (
                str_contains($accion, "CREAR") ||
                str_contains($accion, "INSERT")
            ) {
                $claseAccion = "accion-crear";
                $iconoAccion = "bi-plus-circle-fill";
            } elseif (
                str_contains($accion, "UPDATE") ||
                str_contains($accion, "MODIFICAR")
            ) {
                $claseAccion = "accion-editar";
                $iconoAccion = "bi-pencil-square";
            } elseif (
                str_contains($accion, "DELETE") ||
                str_contains($accion, "ELIMINAR")
            ) {
                $claseAccion = "accion-eliminar";
                $iconoAccion = "bi-trash3-fill";
            }

            $ip = !empty($fila["ip"]) ? $fila["ip"] : "No registrada";
            echo "<tr>";
            echo "<td>";
            echo '<span class="id-registro">';
            echo "#" . (int) $fila["id_bitacora"];
            echo "</span>";
            echo "</td>";
            echo "<td>";
            echo '<div class="usuario-celda">';
            echo '<span class="usuario-icono">';
            echo '<i class="bi bi-person-fill"></i>';
            echo "</span>";
            echo "<strong>";
            echo htmlspecialchars($fila["usuario"], ENT_QUOTES, "UTF-8");
            echo "</strong>";
            echo "</div>";
            echo "</td>";
            echo "<td>";
            echo '<span class="badge-accion ' . $claseAccion . '">';
            echo '<i class="bi ' . $iconoAccion . '"></i>';
            echo htmlspecialchars($accion, ENT_QUOTES, "UTF-8");
            echo "</span>";
            echo "</td>";
            echo "<td>";
            echo '<span class="tabla-afectada">';
            echo htmlspecialchars($fila["tabla_afectada"], ENT_QUOTES, "UTF-8");
            echo "</span>";
            echo "</td>";
            echo "<td>";
            echo '<div class="detalles">';
            echo htmlspecialchars($fila["detalles"] ?? "", ENT_QUOTES, "UTF-8");
            echo "</div>";
            echo "</td>";
            echo "<td>";
            echo '<span class="ip">';
            echo '<i class="bi bi-globe2"></i>';
            echo htmlspecialchars($ip, ENT_QUOTES, "UTF-8");
            echo "</span>";
            echo "</td>";
            echo "<td>";
            echo '<div class="fecha">';
            echo "<strong>";
            echo date("d/m/Y", strtotime($fila["fecha_hora"]));
            echo "</strong>";
            echo "<small>";
            echo date("h:i A", strtotime($fila["fecha_hora"]));
            echo "</small>";
            echo "</div>";
            echo "</td>";
            echo "</tr>";
        }
    }
    $tbodyHtml = ob_get_clean();

    ob_start();
    if ($totalPaginas > 1) {
        echo '<ul class="pagination justify-content-center">';
        echo '<li class="page-item ' .
            ($pagActual <= 1 ? "disabled" : "") .
            '">';
        echo '<a class="page-link" href="#" data-pagina="' .
            ($pagActual - 1) .
            '">Anterior</a>';
        echo "</li>";
        for ($i = 1; $i <= $totalPaginas; $i++) {
            echo '<li class="page-item ' .
                ($pagActual === $i ? "active" : "") .
                '">';
            echo '<a class="page-link" href="#" data-pagina="' .
                $i .
                '">' .
                $i .
                "</a>";
            echo "</li>";
        }
        echo '<li class="page-item ' .
            ($pagActual >= $totalPaginas ? "disabled" : "") .
            '">';
        echo '<a class="page-link" href="#" data-pagina="' .
            ($pagActual + 1) .
            '">Siguiente</a>';
        echo "</li>";
        echo "</ul>";
    }
    $pagHtml = ob_get_clean();

    header("Content-Type: application/json");
    echo json_encode([
        "tbody" => $tbodyHtml,
        "paginacion" => $pagHtml,
    ]);
    exit();
}

$sqlResumen = "
    SELECT
        COUNT(*) AS total_registros,
        SUM(DATE(fecha_hora) = CURDATE()) AS registros_hoy,
        SUM(accion = 'LOGIN') AS total_login,
        SUM(accion = 'VENTA ENTRADA') AS total_ventas
    FROM bitacora
";

$resultadoResumen = mysqli_query($conexion, $sqlResumen);

$resumen = [
    "total_registros" => 0,
    "registros_hoy" => 0,
    "total_login" => 0,
    "total_ventas" => 0,
];

if ($resultadoResumen) {
    $datosResumen = mysqli_fetch_assoc($resultadoResumen);

    if ($datosResumen) {
        $resumen["total_registros"] =
            (int) ($datosResumen["total_registros"] ?? 0);
        $resumen["registros_hoy"] = (int) ($datosResumen["registros_hoy"] ?? 0);
        $resumen["total_login"] = (int) ($datosResumen["total_login"] ?? 0);
        $resumen["total_ventas"] = (int) ($datosResumen["total_ventas"] ?? 0);
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Panel de Administración - EcoFauna</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display&family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="css/styleAdmin.css">

    <link rel="stylesheet" href="css/crud-modern.css?v=1">
    <link rel="stylesheet" href="css/ecofauna-unified.css?v=1">
</head>

<body class="ecofauna-unified crud-modern">


<nav class="navbar navbar-admin">
    <div class="container-fluid px-4">
       <a class="navbar-brand" href="#">
    <img
        src="img/LogoEcoFauna1.png"
        class="logo-navbar-admin"
        alt="Logo EcoFauna">

    <span>
        EcoFauna
        <small>Panel de administración</small>
    </span>
</a>

        <div class="usuario-nav">
            <div class="usuario-info">
               <a href="mi_perfil.php" class="usuario-info text-decoration-none">
                <span class="usuario-avatar">
                    <i class="bi bi-person-fill"></i>
                </span>
                <div>
                    <small>Administrador</small>
                    <strong><?= htmlspecialchars(
                        $_SESSION["usuario"],
                        ENT_QUOTES,
                        "UTF-8",
                    ) ?></strong>
                </div>
            </a>
            </div>

            <form action="logout.php" method="POST" class="d-inline">
                    <?= campoCsrfSesion("logout") ?>
                    <button type="submit" class="btn btn-salir">
                <i class="bi bi-box-arrow-right"></i>
                Cerrar sesión
            </button>
                </form>
        </div>
    </div>
</nav>

<header class="hero-admin">
    <div class="container">
        <span class="hero-etiqueta">
            <i class="bi bi-stars"></i>
            Control y auditoría
        </span>

        <h1>Centro de administración</h1>

        <p>
            Consulta la actividad del sistema, revisa acciones realizadas
            por los usuarios y mantén el control de la plataforma.
        </p>
    </div>
</header>
<main class="container contenido-principal">

    <!-- RESUMEN -->
    <section class="resumen-grid">

        <article class="tarjeta-resumen">
            <span class="resumen-icono">
                <i class="bi bi-journal-text"></i>
            </span>

            <div>
                <small>Registros totales</small>
                <strong><?= $resumen["total_registros"] ?></strong>
            </div>
        </article>

        <article class="tarjeta-resumen">
            <span class="resumen-icono">
                <i class="bi bi-calendar-check-fill"></i>
            </span>

            <div>
                <small>Actividad de hoy</small>
                <strong><?= $resumen["registros_hoy"] ?></strong>
            </div>
        </article>

        <article class="tarjeta-resumen">
            <span class="resumen-icono">
                <i class="bi bi-box-arrow-in-right"></i>
            </span>

            <div>
                <small>Inicios de sesión</small>
                <strong><?= $resumen["total_login"] ?></strong>
            </div>
        </article>

        <article class="tarjeta-resumen">
            <span class="resumen-icono">
                <i class="bi bi-ticket-perforated-fill"></i>
            </span>

            <div>
                <small>Ventas registradas</small>
                <strong><?= $resumen["total_ventas"] ?></strong>
            </div>
        </article>

    </section>

    <!-- MODULOS -->
    <section class="modulos-dashboard">
        <a href="administracion/usuarios.php" class="modulo-dashboard">
            <div class="modulo-icono">
                <i class="bi bi-people-fill"></i>
            </div>
            <div class="modulo-info">
                <h3>Usuarios</h3>
                <p>Crea cuentas, actualiza datos y cambia el rol asignado.</p>
                <span>Gestionar usuarios <i class="bi bi-arrow-right"></i></span>
            </div>
        </a>

        <a href="administracion/permisos.php" class="modulo-dashboard">
            <div class="modulo-icono">
                <i class="bi bi-shield-lock-fill"></i>
            </div>
            <div class="modulo-info">
                <h3>Roles y permisos</h3>
                <p>Define las funciones disponibles para cada rol del sistema.</p>
                <span>Configurar permisos <i class="bi bi-arrow-right"></i></span>
            </div>
        </a>

        <a href="cuidadores/cuidadores.php" class="modulo-dashboard">
            <div class="modulo-icono">
                <i class="bi bi-person-hearts"></i>
            </div>
            <div class="modulo-info">
                <h3>Cuidadores</h3>
                <p>Gestiona los cuidadores encargados de los animales.</p>
                <span>Ir a cuidadores <i class="bi bi-arrow-right"></i></span>
            </div>
        </a>

        <a href="administracion/animales.php" class="modulo-dashboard">
            <div class="modulo-icono"><i class="bi bi-feather"></i></div>
            <div class="modulo-info">
                <h3>Animales</h3>
                <p>Registra animales, actualiza sus fotos y asígnales un hábitat.</p>
                <span>Gestionar animales <i class="bi bi-arrow-right"></i></span>
            </div>
        </a>

        <a href="administracion/habitats.php" class="modulo-dashboard">
            <div class="modulo-icono">
                <i class="bi bi-tree-fill"></i>
            </div>
            <div class="modulo-info">
                <h3>Hábitats</h3>
                <p>Administra las zonas y ambientes visibles para los clientes.</p>
                <span>Gestionar hábitats <i class="bi bi-arrow-right"></i></span>
            </div>
        </a>

        <a href="tienda_admin/productos.php" class="modulo-dashboard">
            <div class="modulo-icono">
                <i class="bi bi-shop-window"></i>
            </div>
            <div class="modulo-info">
                <h3>Tienda</h3>
                <p>Gestiona productos, inventario y ventas de la tienda.</p>
                <span>Ir a tienda <i class="bi bi-arrow-right"></i></span>
            </div>
        </a>
    </section>

    <section class="tarjeta-admin">
        <div class="encabezado-tarjeta">
            <div class="titulo-seccion">
                <span class="icono-seccion">
                    <i class="bi bi-clock-history"></i>
                </span>

                <div>
                    <small>Auditoría del sistema</small>
                    <h2>Historial de acciones</h2>
                </div>
            </div>

            <span class="estado-modulo">
                <i class="bi bi-circle-fill"></i>
                Bitácora activa
            </span>
        </div>

        <div class="cuerpo-filtros">
            <form method="GET" class="filtros-grid">
                <div class="campo-busqueda">
                    <i class="bi bi-search"></i>
                    <input
                        type="search"
                        name="buscar"
                        class="form-control"
                        value="<?= htmlspecialchars(
                            $busqueda,
                            ENT_QUOTES,
                            "UTF-8",
                        ) ?>"
                        placeholder="Buscar usuario, acción, tabla, detalle o IP">
                </div>

                <select name="accion" class="form-select">
                    <option value="">Todas las acciones</option>
                    <?php foreach (
                        array_filter($accionesPermitidas)
                        as $accion
                    ): ?>
                        <option
                            value="<?= htmlspecialchars(
                                $accion,
                                ENT_QUOTES,
                                "UTF-8",
                            ) ?>"
                            <?= $accionFiltro === $accion ? "selected" : "" ?>>
                            <?= htmlspecialchars(
                                $accion,
                                ENT_QUOTES,
                                "UTF-8",
                            ) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <button type="submit" class="btn btn-filtrar">
                    <i class="bi bi-funnel-fill"></i>
                    Filtrar
                </button>

                <a href="admin.php" class="btn btn-limpiar">
                    <i class="bi bi-arrow-counterclockwise"></i>
                    Limpiar
                </a>
            </form>
        </div>

        <div class="table-responsive">
            <table class="table tabla-bitacora mb-0">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Usuario</th>
                        <th>Acción</th>
                        <th>Tabla</th>
                        <th>Descripción</th>
                        <th>IP</th>
                        <th>Fecha</th>
                    </tr>
                </thead>

                <tbody>
                <?php if (!$resultado || mysqli_num_rows($resultado) === 0): ?>
                    <tr>
                        <td colspan="7">
                            <div class="estado-vacio">
                                <i class="bi bi-inbox"></i>
                                <h3>No hay registros disponibles</h3>
                                <p>No se encontraron acciones con los filtros seleccionados.</p>
                            </div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php while ($fila = mysqli_fetch_assoc($resultado)): ?>
                        <?php
                        $accion = strtoupper((string) $fila["accion"]);
                        $claseAccion = "accion-neutra";
                        $iconoAccion = "bi-dot";

                        if (str_contains($accion, "LOGIN")) {
                            $claseAccion = "accion-login";
                            $iconoAccion = "bi-box-arrow-in-right";
                        } elseif (str_contains($accion, "LOGOUT")) {
                            $claseAccion = "accion-logout";
                            $iconoAccion = "bi-box-arrow-right";
                        } elseif (str_contains($accion, "VENTA")) {
                            $claseAccion = "accion-venta";
                            $iconoAccion = "bi-ticket-perforated-fill";
                        } elseif (str_contains($accion, "DIETA")) {
                            $claseAccion = "accion-dieta";
                            $iconoAccion = "bi-clipboard2-heart-fill";
                        } elseif (
                            str_contains($accion, "CREAR") ||
                            str_contains($accion, "INSERT")
                        ) {
                            $claseAccion = "accion-crear";
                            $iconoAccion = "bi-plus-circle-fill";
                        } elseif (
                            str_contains($accion, "UPDATE") ||
                            str_contains($accion, "MODIFICAR")
                        ) {
                            $claseAccion = "accion-editar";
                            $iconoAccion = "bi-pencil-square";
                        } elseif (
                            str_contains($accion, "DELETE") ||
                            str_contains($accion, "ELIMINAR")
                        ) {
                            $claseAccion = "accion-eliminar";
                            $iconoAccion = "bi-trash3-fill";
                        }

                        $ip = !empty($fila["ip"])
                            ? $fila["ip"]
                            : "No registrada";
                        ?>

                        <tr>
                            <td>
                                <span class="id-registro">
                                    #<?= (int) $fila["id_bitacora"] ?>
                                </span>
                            </td>

                            <td>
                                <div class="usuario-celda">
                                    <span class="usuario-icono">
                                        <i class="bi bi-person-fill"></i>
                                    </span>

                                    <strong>
                                        <?= htmlspecialchars(
                                            $fila["usuario"],
                                            ENT_QUOTES,
                                            "UTF-8",
                                        ) ?>
                                    </strong>
                                </div>
                            </td>

                            <td>
                                <span class="badge-accion <?= $claseAccion ?>">
                                    <i class="bi <?= $iconoAccion ?>"></i>
                                    <?= htmlspecialchars(
                                        $accion,
                                        ENT_QUOTES,
                                        "UTF-8",
                                    ) ?>
                                </span>
                            </td>

                            <td>
                                <span class="tabla-afectada">
                                    <?= htmlspecialchars(
                                        $fila["tabla_afectada"],
                                        ENT_QUOTES,
                                        "UTF-8",
                                    ) ?>
                                </span>
                            </td>

                            <td>
                                <div class="detalles">
                                    <?= htmlspecialchars(
                                        $fila["detalles"] ?? "",
                                        ENT_QUOTES,
                                        "UTF-8",
                                    ) ?>
                                </div>
                            </td>

                            <td>
                                <span class="ip">
                                    <i class="bi bi-globe2"></i>
                                    <?= htmlspecialchars(
                                        $ip,
                                        ENT_QUOTES,
                                        "UTF-8",
                                    ) ?>
                                </span>
                            </td>

                            <td>
                                <div class="fecha">
                                    <strong>
                                        <?= date(
                                            "d/m/Y",
                                            strtotime($fila["fecha_hora"]),
                                        ) ?>
                                    </strong>
                                    <small>
                                        <?= date(
                                            "h:i A",
                                            strtotime($fila["fecha_hora"]),
                                        ) ?>
                                    </small>
                                </div>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <nav aria-label="Paginacion bitacora" class="mt-4" id="paginacion-bitacora">
            <?php if ($totalPaginas > 1): ?>
                <ul class="pagination justify-content-center">
                    <li class="page-item <?= $pagActual <= 1
                        ? "disabled"
                        : "" ?>">
                        <a class="page-link" href="#" data-pagina="<?= $pagActual -
                            1 ?>">Anterior</a>
                    </li>
                    <?php for ($i = 1; $i <= $totalPaginas; $i++): ?>
                        <li class="page-item <?= $pagActual === $i
                            ? "active"
                            : "" ?>">
                            <a class="page-link" href="#" data-pagina="<?= $i ?>"><?= $i ?></a>
                        </li>
                    <?php endfor; ?>
                    <li class="page-item <?= $pagActual >= $totalPaginas
                        ? "disabled"
                        : "" ?>">
                        <a class="page-link" href="#" data-pagina="<?= $pagActual +
                            1 ?>">Siguiente</a>
                    </li>
                </ul>
            <?php endif; ?>
        </nav>
    </section>
</main>

<script src="js/admin.js"></script>

<footer class="footer-admin">
    <div class="container">
        <span>
            <i class="bi bi-shield-lock-fill"></i>
            EcoFauna — Administración
        </span>

        <small>
            Auditoría, seguridad y control del sistema
        </small>
    </div>
</footer>
</body>
</html>
