<?php

require_once __DIR__ . "/entorno.php";

// ======================================================
// CONEXIÓN CON LA BASE DE DATOS
// ======================================================

$hostEntorno = entornoEcoFauna("ECOFAUNA_DB_HOST");
$usuarioEntorno = entornoEcoFauna("ECOFAUNA_DB_USER");
$contrasenaEntorno = entornoEcoFauna("ECOFAUNA_DB_PASSWORD");
$nombreEntorno = entornoEcoFauna("ECOFAUNA_DB_NAME");

$host = is_string($hostEntorno) && $hostEntorno !== ""
    ? $hostEntorno
    : "localhost";
$usuarioDb = is_string($usuarioEntorno) && $usuarioEntorno !== ""
    ? $usuarioEntorno
    : "root";
$contrasenaDb = is_string($contrasenaEntorno)
    ? $contrasenaEntorno
    : "";
$nombreDb = is_string($nombreEntorno) && $nombreEntorno !== ""
    ? $nombreEntorno
    : "ZoologicoDBPortafolio";

try {
    $conexion = mysqli_connect($host, $usuarioDb, $contrasenaDb, $nombreDb);
} catch (mysqli_sql_exception $error) {
    error_log("No fue posible conectar con la base de datos (código " . $error->getCode() . ").");
    http_response_code(503);
    exit("No fue posible conectar con la base de datos. Revisa la configuración local y el servicio MySQL.");
}

if (!$conexion) {
    error_log(
        "Error de conexión con la base de datos: " . mysqli_connect_error(),
    );

    die("No fue posible conectar con la base de datos.");
}

if (!mysqli_set_charset($conexion, "utf8mb4")) {
    error_log(
        "Error configurando el juego de caracteres: " . mysqli_error($conexion),
    );

    die("No fue posible configurar la conexión.");
}

/*
 * Los triggers de usuarios utilizan estas variables de la conexión para
 * identificar quién realizó el cambio. Si todavía no existe una sesión, la
 * acción queda asociada al sistema.
 */
$usuarioSistema = isset($_SESSION["usuario"])
    ? (string) $_SESSION["usuario"]
    : "Sistema";
$ipSistema = $_SERVER["REMOTE_ADDR"] ?? "127.0.0.1";
$ipSistema = $ipSistema === "::1" ? "127.0.0.1" : $ipSistema;

$stmtContexto = mysqli_prepare(
    $conexion,
    "SET @usuario_sistema = ?, @ip_sistema = ?",
);

if ($stmtContexto) {
    mysqli_stmt_bind_param($stmtContexto, "ss", $usuarioSistema, $ipSistema);
    mysqli_stmt_execute($stmtContexto);
    mysqli_stmt_close($stmtContexto);
}

// ======================================================
// FUNCIONES AUXILIARES
// ======================================================

function obtenerIpCliente(): string
{
    $ip = $_SERVER["REMOTE_ADDR"] ?? "127.0.0.1";

    return $ip === "::1" ? "127.0.0.1" : $ip;
}

/**
 * Registra una acción dentro de la bitácora.
 */
function registrarBitacora(
    ?string $usuario,
    string $accion,
    string $tabla,
    ?string $detalles = null,
    ?int $registroId = null,
): bool {
    global $conexion;

    $ip = obtenerIpCliente();

    $sql = "
        INSERT INTO bitacora (
            usuario,
            accion,
            tabla_afectada,
            registro_id,
            detalles,
            ip
        )
        VALUES (?, ?, ?, ?, ?, ?)
    ";

    $stmt = mysqli_prepare($conexion, $sql);

    if (!$stmt) {
        error_log("Error preparando la bitácora: " . mysqli_error($conexion));

        return false;
    }

    mysqli_stmt_bind_param(
        $stmt,
        "sssiss",
        $usuario,
        $accion,
        $tabla,
        $registroId,
        $detalles,
        $ip,
    );

    $ejecutado = mysqli_stmt_execute($stmt);

    if (!$ejecutado) {
        error_log("Error registrando la bitácora: " . mysqli_stmt_error($stmt));
    }

    mysqli_stmt_close($stmt);

    return $ejecutado;
}

/**
 * Comprueba si el rol de la sesión posee un permiso.
 *
 * Mientras un rol no tenga asignaciones guardadas, se conserva el acceso
 * anterior indicado en $rolesCompatibilidad. Así, habilitar el módulo no
 * bloquea de inmediato las páginas que ya utilizaban Empleado o Veterinario.
 */
function usuarioTienePermiso(
    string $nombrePermiso,
    array $rolesCompatibilidad = [],
): bool {
    global $conexion;

    $rol = $_SESSION["rol"] ?? null;

    if (!is_string($rol) || $rol === "") {
        return false;
    }

    if ($rol === "Administrador") {
        return true;
    }

    $sql = "
        SELECT
            COUNT(ap.id_asignarpermisos) AS permisos_asignados,
            SUM(CASE WHEN p.nombre_permiso = ? THEN 1 ELSE 0 END) AS permitido
        FROM rol r
        LEFT JOIN asignar_permisos ap
            ON ap.id_rol = r.id_rol
        LEFT JOIN permisos p
            ON p.id_permisos = ap.id_permisos
        WHERE r.nombre_rol = ?
    ";

    $stmt = mysqli_prepare($conexion, $sql);

    if (!$stmt) {
        error_log("Error consultando permisos: " . mysqli_error($conexion));

        return in_array($rol, $rolesCompatibilidad, true);
    }

    mysqli_stmt_bind_param($stmt, "ss", $nombrePermiso, $rol);

    if (!mysqli_stmt_execute($stmt)) {
        error_log("Error verificando permisos: " . mysqli_stmt_error($stmt));
        mysqli_stmt_close($stmt);

        return in_array($rol, $rolesCompatibilidad, true);
    }

    $resultado = mysqli_stmt_get_result($stmt);
    $fila = mysqli_fetch_assoc($resultado) ?: [];
    mysqli_stmt_close($stmt);

    $asignados = (int) ($fila["permisos_asignados"] ?? 0);
    $permitido = (int) ($fila["permitido"] ?? 0) > 0;

    if ($asignados === 0) {
        return in_array($rol, $rolesCompatibilidad, true);
    }

    return $permitido;
}

/**
 * Detiene una página cuando la sesión no posee el permiso solicitado.
 */
function exigirPermiso(
    string $nombrePermiso,
    array $rolesCompatibilidad,
    string $rutaRegreso,
): void {
    if (usuarioTienePermiso($nombrePermiso, $rolesCompatibilidad)) {
        return;
    }

    $_SESSION["mensaje_permiso"] =
        "No tienes permiso para ingresar a ese módulo.";

    header("Location: " . $rutaRegreso);
    exit();
}
