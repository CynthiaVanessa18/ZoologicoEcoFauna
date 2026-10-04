<?php

require_once __DIR__ . "/../../soporte/sesion.php";

require_once __DIR__ . "/../../../config/database.php";

/** @var mysqli $conexion */

/* =====================================================
   CONTROL DE ACCESO
===================================================== */

if (
    !isset($_SESSION["usuario"], $_SESSION["rol"], $_SESSION["id_login"]) ||
    ($_SESSION["rol"] !== "Administrador" && $_SESSION["rol"] !== "Empleado")
) {
    header("Location: ../index.php");
    exit();
}

/* =====================================================
   FUNCIONES
===================================================== */

function escapar(mixed $texto): string
{
    return htmlspecialchars((string) $texto, ENT_QUOTES, "UTF-8");
}

function fechaFiltroAccesoValida(string $fecha): bool
{
    $objetoFecha = DateTime::createFromFormat("!Y-m-d", $fecha);

    return $objetoFecha !== false &&
        $objetoFecha->format("Y-m-d") === $fecha;
}

/**
 * Prepara y ejecuta las consultas del historial usando los mismos filtros.
 */
function ejecutarConsultaAccesos(
    mysqli $conexion,
    string $sql,
    string $fecha,
    string $codigoLike,
    ?int $limite = null,
    ?int $offset = null,
): mysqli_result {
    $stmt = mysqli_prepare($conexion, $sql);

    if (!$stmt) {
        throw new RuntimeException(mysqli_error($conexion));
    }

    $conPaginacion = $limite !== null && $offset !== null;
    $tipos = "";
    $parametros = [];
    $vinculado = true;

    if ($fecha !== "") {
        $tipos .= "s";
        $parametros[] = &$fecha;
    }

    if ($codigoLike !== "") {
        $tipos .= "s";
        $parametros[] = &$codigoLike;
    }

    if ($conPaginacion) {
        $tipos .= "ii";
        $parametros[] = &$limite;
        $parametros[] = &$offset;
    }

    if ($tipos !== "") {
        $vinculado = mysqli_stmt_bind_param(
            $stmt,
            $tipos,
            ...$parametros,
        );
    }

    if (!$vinculado) {
        $errorTecnico = mysqli_stmt_error($stmt);
        mysqli_stmt_close($stmt);
        throw new RuntimeException($errorTecnico);
    }

    if (!mysqli_stmt_execute($stmt)) {
        $errorTecnico = mysqli_stmt_error($stmt);
        mysqli_stmt_close($stmt);
        throw new RuntimeException($errorTecnico);
    }

    $resultado = mysqli_stmt_get_result($stmt);
    mysqli_stmt_close($stmt);

    if (!$resultado) {
        throw new RuntimeException(
            "No fue posible obtener el resultado del historial.",
        );
    }

    return $resultado;
}

/* =====================================================
   FILTROS
===================================================== */

$fecha = trim((string) ($_GET["fecha"] ?? ""));
$codigo = strtoupper(trim((string) ($_GET["codigo"] ?? "")));
$mensajeFiltro = "";

if ($fecha !== "" && !fechaFiltroAccesoValida($fecha)) {
    $mensajeFiltro = "La fecha utilizada para filtrar no es válida.";
    $fecha = "";
}

if (
    $codigo !== "" &&
    (strlen($codigo) > 5 || preg_match('/^[A-Z0-9]+$/', $codigo) !== 1)
) {
    $mensajeFiltro = "El código debe contener únicamente letras y números, con un máximo de 5 caracteres.";
    $codigo = "";
}

$codigoLike = $codigo !== "" ? "%" . $codigo . "%" : "";
$mensajeAcceso = $_SESSION["mensaje_acceso"] ?? "";

$tipoMensajeAcceso = $_SESSION["tipo_mensaje_acceso"] ?? "success";

unset($_SESSION["mensaje_acceso"], $_SESSION["tipo_mensaje_acceso"]);

if (!in_array($tipoMensajeAcceso, ["success", "danger"], true)) {
    $tipoMensajeAcceso = "success";
}

if ($_SESSION["rol"] === "Empleado") {
    $_SESSION["csrf_accesos"] ??= bin2hex(random_bytes(32));
}

/* =====================================================
   CONSULTA HISTORIAL CON PAGINACIÓN
===================================================== */

$condiciones = [];

if ($fecha !== "") {
    $condiciones[] = "DATE(r.hora_entrada) = ?";
}

if ($codigoLike !== "") {
    $condiciones[] = "bz.codigo_ingreso LIKE ?";
}

$whereSql =
    $condiciones === []
    ? ""
    : " WHERE " . implode(" AND ", $condiciones);

$registrosPorPagina = 10;
$paginaActual = isset($_GET["pagina"]) ? (int) $_GET["pagina"] : 1;

if ($paginaActual < 1) {
    $paginaActual = 1;
}

$totalRegistros = 0;
$totalPaginas = 1;
$resultado = null;

try {
    $sqlCount = "
        SELECT COUNT(*) AS total
        FROM registro_acceso r
        INNER JOIN boleto_zoologico bz
            ON bz.id_boleto_zoologico = r.id_entrada
        {$whereSql}
    ";

    $resCount = ejecutarConsultaAccesos(
        $conexion,
        $sqlCount,
        $fecha,
        $codigoLike,
    );

    $filaCount = mysqli_fetch_assoc($resCount);
    $totalRegistros = (int) ($filaCount["total"] ?? 0);
    mysqli_free_result($resCount);

    $totalPaginas = max(
        1,
        (int) ceil($totalRegistros / $registrosPorPagina),
    );

    if ($paginaActual > $totalPaginas) {
        $paginaActual = $totalPaginas;
    }

    $offset = ($paginaActual - 1) * $registrosPorPagina;

    $sql = "
        SELECT
            r.id_registro_acceso,
            bz.codigo_ingreso,
            r.hora_entrada,
            r.hora_salida,
            TIMESTAMPDIFF(
                MINUTE,
                r.hora_entrada,
                r.hora_salida
            ) AS tiempo
        FROM registro_acceso r
        INNER JOIN boleto_zoologico bz
            ON bz.id_boleto_zoologico = r.id_entrada
        {$whereSql}
        ORDER BY r.hora_entrada DESC
        LIMIT ? OFFSET ?
    ";

    $resultado = ejecutarConsultaAccesos(
        $conexion,
        $sql,
        $fecha,
        $codigoLike,
        $registrosPorPagina,
        $offset,
    );
} catch (Throwable $error) {
    error_log(
        "Error consultando el historial de accesos: " .
            $error->getMessage(),
    );

    $mensajeFiltro =
        "No fue posible consultar el historial. Inténtalo nuevamente.";
}

// Función para construir URLs de paginación conservando filtros
function getPaginacionUrl(
    int $pagina,
    string $fecha,
    string $codigo,
): string {
    $params = ["pagina" => $pagina];
    if ($fecha !== "") {
        $params["fecha"] = $fecha;
    }
    if ($codigo !== "") {
        $params["codigo"] = $codigo;
    }
    return "historial_accesos.php?" . http_build_query($params);
}

/* =====================================================
   REGRESAR
===================================================== */

$regresar =
    $_SESSION["rol"] === "Administrador" ? "../admin.php" : "../empleado.php";
?>

<!DOCTYPE html>

<html lang="es">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>
        Control de Accesos - EcoFauna
    </title>

    <!-- Bootstrap -->

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/css/bootstrap.min.css" rel="stylesheet">

    <!-- Iconos -->

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">

    <!-- Fuentes -->

    <link rel="preconnect" href="https://fonts.googleapis.com">

    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

    <link
        href="https://fonts.googleapis.com/css2?family=DM Serif Display&family=Poppins:wght@400;500;600;700&display=swap"
        rel="stylesheet">

    <!-- CSS EcoFauna -->

    <link rel="stylesheet" href="../css/styleCliente.css?v=4">

    <link rel="stylesheet" href="../css/crud-modern.css?v=1">
    <link rel="stylesheet" href="../css/ecofauna-unified.css?v=1">
</head>

<body class="ecofauna-unified crud-modern">



    <?php if ($mensajeAcceso !== ""): ?>

        <div
            class="modal fade modal-ecofauna"
            id="modalMensajeAcceso"
            tabindex="-1"
            aria-hidden="true">

            <div class="modal-dialog modal-dialog-centered">

                <div class="modal-content">

                    <div class="modal-header">

                        <h5 class="modal-title">

                            <?php if ($tipoMensajeAcceso === "success"): ?>

                                <i class="bi bi-check-circle-fill"></i>
                                Operación exitosa

                            <?php else: ?>

                                <i class="bi bi-exclamation-triangle-fill"></i>
                                No se pudo registrar

                            <?php endif; ?>

                        </h5>

                        <button
                            type="button"
                            class="btn-close btn-close-white"
                            data-bs-dismiss="modal"
                            aria-label="Cerrar">
                        </button>

                    </div>

                    <div class="modal-body">

                        <p class="mb-0">

                            <?= escapar($mensajeAcceso) ?>

                        </p>

                    </div>

                    <div class="modal-footer">

                        <button
                            type="button"
                            class="btn btn-principal"
                            data-bs-dismiss="modal">

                            Aceptar

                        </button>

                    </div>

                </div>

            </div>

        </div>

    <?php endif; ?>

    <!-- ==================================================
     NAVBAR
================================================== -->

    <nav class="navbar navbar-cliente">

        <div class="container-fluid px-4">

            <a class="navbar-brand" href="#">

                <img src="../img/LogoEcoFauna1.png" class="logo-navbar-cliente" alt="EcoFauna">

                <span>

                    EcoFauna

                    <small>

                        Control administrativo

                    </small>

                </span>

            </a>

            <div class="usuario-nav">

                <div class="usuario-info">

 <div class="navbar-usuario">

               <a href="../mi_perfil.php" class="text-decoration-none text-white">
    <i class="bi bi-person-circle me-2"></i>
    <?= htmlspecialchars(
        $_SESSION["usuario"],
        ENT_QUOTES,
        "UTF-8",
    ) ?>
</a>

                </div>

                <form action="../logout.php" method="POST" class="m-0">
                    <?= campoCsrfSesion("logout") ?>

                    <button type="submit" class="btn btn-salir">
                        <i class="bi bi-box-arrow-right"></i>
                        Salir
                    </button>
                </form>
            </div>

        </div>

    </nav>

    <!-- ==================================================
     HERO
================================================== -->

    <header class="hero-cliente">

        <div class="container hero-contenido">

            <div class="hero-texto">

                <span class="hero-etiqueta">

                    <i class="bi bi-door-open-fill"></i>

                    Control de visitantes

                </span>

                <h1>

                    Entradas y salidas

                </h1>

                <p>

                    Registre accesos mediante código de boleto
                    y consulte visitantes dentro del zoológico.

                </p>

                <div class="hero-acciones">

                    <a href="#historial" class="btn btn-principal">

                        <i class="bi bi-clock-history"></i>

                        Historial

                    </a>

                    <a
                        href="<?= escapar($regresar) ?>"
                        class="btn btn-secundario">

                        <i class="bi bi-arrow-left"></i>

                        Regresar

                    </a>

                </div>

            </div>

            <div class="hero-ilustracion">

                <div class="circulo-grande">

                    <i class="bi bi-tree-fill"></i>

                </div>

                <span class="burbuja burbuja-uno">

                    <i class="bi bi-ticket-fill"></i>

                </span>

                <span class="burbuja burbuja-dos">

                    <i class="bi bi-person-check-fill"></i>

                </span>

                <span class="burbuja burbuja-tres">

                    <i class="bi bi-shield-check"></i>

                </span>

            </div>

        </div>

    </header>

    <main class="container contenido-principal">

        <?php if ($_SESSION["rol"] === "Empleado"): ?>

            <!-- REGISTRO -->

            <section class="tarjeta-servicio mb-4">

                <span class="servicio-icono">

                    <i class="bi bi-check-circle-fill"></i>

                </span>

                <h3>

                    Registrar entrada / salida

                </h3>

                <form action="procesar_accesos.php" method="POST">

                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= escapar($_SESSION["csrf_accesos"]) ?>">

                    <div class="row g-3">

                        <div class="col-md-9">

                            <label class="form-label">

                                Código del boleto

                            </label>

                            <input
                                type="text"
                                name="codigo_ingreso"
                                maxlength="5"
                                pattern="[A-Za-z0-9]{5}"
                                class="form-control"
                                placeholder="ABCDE"
                                autocomplete="off"
                                required>

                        </div>

                        <div class="col-md-3 d-grid">

                            <label>

                                &nbsp;

                            </label>

                            <button type="submit" class="btn btn-principal">

                                <i class="bi bi-save"></i>

                                Registrar

                            </button>

                        </div>

                    </div>

                </form>

            </section>

        <?php endif; ?>

        <!-- FILTROS -->

        <?php if ($mensajeFiltro !== ""): ?>
            <div class="alert alert-warning" role="alert">
                <?= escapar($mensajeFiltro) ?>
            </div>
        <?php endif; ?>

        <section class="tarjeta-servicio mb-4">

            <span class="servicio-icono">

                <i class="bi bi-funnel-fill"></i>

            </span>

            <h3>

                Buscar accesos

            </h3>

            <form method="GET">

                <div class="row g-3">

                    <div class="col-md-4">

                        <label>

                            Fecha

                        </label>

                        <input type="date" name="fecha" class="form-control" value="<?= escapar(
                                                                                        $fecha,
                                                                                    ) ?>">

                    </div>

                    <div class="col-md-4">

                        <label>

                            Código

                        </label>

                        <input
                            type="text"
                            name="codigo"
                            class="form-control"
                            value="<?= escapar($codigo) ?>"
                            maxlength="5"
                            pattern="[A-Za-z0-9]{1,5}">

                    </div>

                    <div class="col-md-2 d-grid">

                        <label>

                            &nbsp;

                        </label>

                        <button class="btn btn-principal">

                            <i class="bi bi-search"></i>

                            Buscar

                        </button>

                    </div>

                    <div class="col-md-2 d-grid">

                        <label>

                            &nbsp;

                        </label>

                        <a href="historial_accesos.php" class="btn btn-limpiar-acceso">
                            <i class="bi bi-arrow-counterclockwise"></i>
                            Limpiar
                        </a>

                    </div>

                </div>

            </form>

        </section>

        <!-- TABLA -->

        <section id="historial" class="tarjeta-servicio">

            <span class="servicio-icono">

                <i class="bi bi-clock-history"></i>

            </span>

            <h3>

                Historial de accesos

            </h3>

            <div class="table-responsive">

                <table class="table table-hover align-middle">

                    <thead class="table-dark">

                        <tr>

                            <th>
                                Código
                            </th>

                            <th>
                                Entrada
                            </th>

                            <th>
                                Salida
                            </th>

                            <th>
                                Tiempo
                            </th>

                            <th>
                                Estado
                            </th>

                        </tr>

                    </thead>

                    <tbody>

                        <?php if (
                            $resultado instanceof mysqli_result &&
                            mysqli_num_rows($resultado) > 0
                        ): ?>

                            <?php while (
                                $fila = mysqli_fetch_assoc($resultado)
                            ): ?>

                                <tr>

                                    <td>

                                        <strong>

                                            <?= escapar(
                                                $fila["codigo_ingreso"],
                                            ) ?>

                                        </strong>

                                    </td>

                                    <td>

                                        <?= date(
                                            "d/m/Y H:i:s",
                                            strtotime($fila["hora_entrada"]),
                                        ) ?>

                                    </td>

                                    <td>

                                        <?php if ($fila["hora_salida"]): ?>

                                            <?= date(
                                                "d/m/Y H:i:s",
                                                strtotime($fila["hora_salida"]),
                                            ) ?>

                                        <?php else: ?>

                                            Pendiente

                                        <?php endif; ?>

                                    </td>

                                    <td>

                                        <?= $fila["tiempo"] !== null
                                            ? (int) $fila["tiempo"] . " min"
                                            : "-" ?>

                                    </td>

                                    <td>

                                        <?php if ($fila["hora_salida"]): ?>

                                            <span class="badge bg-secondary">

                                                Salida registrada

                                            </span>

                                        <?php else: ?>

                                            <span class="badge bg-success">

                                                Dentro del zoológico

                                            </span>

                                        <?php endif; ?>

                                    </td>

                                </tr>

                            <?php endwhile; ?>

                        <?php else: ?>

                            <tr>

                                <td colspan="5" class="text-center">

                                    No existen registros.

                                </td>

                            </tr>

                        <?php endif; ?>

                    </tbody>

                </table>

            </div>

            <!-- Paginación -->
            <?php if ($totalPaginas > 1): ?>
                <nav aria-label="Navegación de páginas de historial" class="mt-4">
                    <ul class="pagination justify-content-center">

                        <!-- Botón Anterior -->
                        <li class="page-item <?= $paginaActual <= 1
                                                    ? "disabled"
                                                    : "" ?>">
                            <a class="page-link" href="<?= escapar(
                                                            getPaginacionUrl(
                                                                $paginaActual - 1,
                                                                $fecha,
                                                                $codigo,
                                                            ),
                                                        ) ?>" aria-label="Anterior">
                                <span aria-hidden="true">&laquo; Anterior</span>
                            </a>
                        </li>

                        <!-- Números de Página -->
                        <?php for ($i = 1; $i <= $totalPaginas; $i++): ?>
                            <li class="page-item <?= $i === $paginaActual
                                                        ? "active"
                                                        : "" ?>">
                                <a class="page-link" href="<?= escapar(
                                                                getPaginacionUrl(
                                                                    $i,
                                                                    $fecha,
                                                                    $codigo,
                                                                ),
                                                            ) ?>"><?= $i ?></a>
                            </li>
                        <?php endfor; ?>

                        <!-- Botón Siguiente -->
                        <li class="page-item <?= $paginaActual >= $totalPaginas
                                                    ? "disabled"
                                                    : "" ?>">
                            <a class="page-link" href="<?= escapar(
                                                            getPaginacionUrl(
                                                                $paginaActual + 1,
                                                                $fecha,
                                                                $codigo,
                                                            ),
                                                        ) ?>" aria-label="Siguiente">
                                <span aria-hidden="true">Siguiente &raquo;</span>
                            </a>
                        </li>

                    </ul>
                </nav>
            <?php endif; ?>

        </section>

    </main>

    <footer class="footer-cliente">

        <div class="container">

            <span>

                <i class="bi bi-tree-fill"></i>

                EcoFauna

            </span>

            <small>

                Conservar • Educar • Proteger

            </small>

        </div>

    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../js/historial_accesos.js"></script>

</body>

</html>