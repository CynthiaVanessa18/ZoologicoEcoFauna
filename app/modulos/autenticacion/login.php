<?php

require_once __DIR__ . "/../../soporte/sesion.php";
require_once __DIR__ . "/../../../config/database.php";

/** @var mysqli $conexion */
$conexion = $GLOBALS["conexion"];

/**
 * Regresa al formulario de acceso con un mensaje controlado.
 */
function redirigirAlLogin(string $error, array $parametros = []): void
{
    $consulta = http_build_query(
        array_merge(
            ["error" => $error],
            $parametros,
        ),
    );

    header("Location: index.php?" . $consulta);
    exit();
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: index.php");
    exit();
}
if (!tokenCsrfSesionValido("login", $_POST["csrf_token"] ?? null)) {
    redirigirAlLogin("solicitud");
}

renovarTokenCsrfSesion("login");
$usuario = trim($_POST["usuario"] ?? "");
$contrasena = $_POST["contrasena"] ?? "";

if ($usuario === "" || $contrasena === "") {
    redirigirAlLogin("campos");
}

$sql = "
    SELECT
        ur.id_usuarios_rol,
        ur.id_usuario,
        ur.usuario,
        ur.contrasena,
        r.nombre_rol,
        sc.id_seguridad,
        sc.intentos_fallidos,
        sc.cuenta_bloqueada,
        sc.fecha_bloqueo,
        CASE
            WHEN sc.cuenta_bloqueada = TRUE
             AND sc.fecha_bloqueo IS NOT NULL
            THEN GREATEST(
                TIMESTAMPDIFF(
                    SECOND,
                    NOW(),
                    DATE_ADD(
                        sc.fecha_bloqueo,
                        INTERVAL 1 MINUTE
                    )
                ),
                0
            )
            ELSE 0
        END AS segundos_bloqueo
    FROM usuarios_rol ur
    INNER JOIN rol r
        ON r.id_rol = ur.id_rol
    INNER JOIN seguridad_cuenta sc
        ON sc.id_usuarios_rol = ur.id_usuarios_rol
    WHERE ur.usuario = ?
    LIMIT 1
";

$stmt = mysqli_prepare($conexion, $sql);

if (!$stmt) {
    error_log("Error preparando el inicio de sesión: " . mysqli_error($conexion));
    redirigirAlLogin("sistema");
}

mysqli_stmt_bind_param($stmt, "s", $usuario);

if (!mysqli_stmt_execute($stmt)) {
    error_log("Error consultando el usuario: " . mysqli_stmt_error($stmt));
    mysqli_stmt_close($stmt);
    redirigirAlLogin("sistema");
}

$resultado = mysqli_stmt_get_result($stmt);
$fila = mysqli_fetch_assoc($resultado);

mysqli_stmt_close($stmt);

/*
 * No se revela si el usuario existe.
 */
if (!$fila) {
    redirigirAlLogin("credenciales");
}

$idLogin = (int) $fila["id_usuarios_rol"];
$idSeguridad = (int) $fila["id_seguridad"];
$intentosFallidos = (int) $fila["intentos_fallidos"];
$cuentaBloqueada = (int) $fila["cuenta_bloqueada"] === 1;
$segundosBloqueo = (int) $fila["segundos_bloqueo"];

/*
 * Mientras no haya pasado un minuto, la cuenta continúa bloqueada.
 */
if ($cuentaBloqueada && $segundosBloqueo > 0) {
    redirigirAlLogin(
        "bloqueado",
        ["tiempo" => $segundosBloqueo],
    );
}

/*
 * Al finalizar el minuto se rehabilita la cuenta automáticamente.
 */
if ($cuentaBloqueada) {
    $sqlDesbloquear = "
        UPDATE seguridad_cuenta
        SET intentos_fallidos = 0,
            cuenta_bloqueada = FALSE,
            fecha_bloqueo = NULL
        WHERE id_seguridad = ?
    ";

    $stmtDesbloquear = mysqli_prepare(
        $conexion,
        $sqlDesbloquear,
    );

    if (!$stmtDesbloquear) {
        error_log(
            "Error preparando el desbloqueo: " .
            mysqli_error($conexion),
        );
        redirigirAlLogin("sistema");
    }

    mysqli_stmt_bind_param(
        $stmtDesbloquear,
        "i",
        $idSeguridad,
    );

    if (!mysqli_stmt_execute($stmtDesbloquear)) {
        error_log(
            "Error desbloqueando la cuenta: " .
            mysqli_stmt_error($stmtDesbloquear),
        );
        mysqli_stmt_close($stmtDesbloquear);
        redirigirAlLogin("sistema");
    }

    mysqli_stmt_close($stmtDesbloquear);

    registrarBitacora(
        $usuario,
        "DESBLOQUEO AUTOMÁTICO",
        "seguridad_cuenta",
        "La cuenta fue rehabilitada después de finalizar el bloqueo temporal.",
        $idSeguridad,
    );

    $intentosFallidos = 0;
}

/*
 * Registrar un intento fallido o bloquear la cuenta al tercer intento.
 */
if (!password_verify($contrasena, $fila["contrasena"])) {
    $nuevosIntentos = $intentosFallidos + 1;
    $debeBloquearse = $nuevosIntentos >= 3;

    $sqlFallo = "
        UPDATE seguridad_cuenta
        SET intentos_fallidos = ?,
            cuenta_bloqueada = ?,
            fecha_bloqueo = CASE
                WHEN ? = 1 THEN NOW()
                ELSE NULL
            END,
            ultimo_intento_fallido = NOW()
        WHERE id_seguridad = ?
    ";

    $stmtFallo = mysqli_prepare($conexion, $sqlFallo);

    if (!$stmtFallo) {
        error_log(
            "Error preparando el intento fallido: " .
            mysqli_error($conexion),
        );
        redirigirAlLogin("sistema");
    }

    $valorBloqueo = $debeBloquearse ? 1 : 0;

    mysqli_stmt_bind_param(
        $stmtFallo,
        "iiii",
        $nuevosIntentos,
        $valorBloqueo,
        $valorBloqueo,
        $idSeguridad,
    );

    if (!mysqli_stmt_execute($stmtFallo)) {
        error_log(
            "Error registrando el intento fallido: " .
            mysqli_stmt_error($stmtFallo),
        );
        mysqli_stmt_close($stmtFallo);
        redirigirAlLogin("sistema");
    }

    mysqli_stmt_close($stmtFallo);

    registrarBitacora(
        $usuario,
        "INTENTO FALLIDO LOGIN",
        "seguridad_cuenta",
        sprintf(
            "Intento fallido %d de 3.",
            min($nuevosIntentos, 3),
        ),
        $idSeguridad,
    );

    if ($debeBloquearse) {
        registrarBitacora(
            $usuario,
            "BLOQUEO CUENTA",
            "seguridad_cuenta",
            "Cuenta bloqueada temporalmente por 1 minuto tras 3 intentos fallidos.",
            $idSeguridad,
        );

        redirigirAlLogin(
            "bloqueado",
            ["tiempo" => 60],
        );
    }

    redirigirAlLogin(
        "credenciales",
        ["intentos" => 3 - $nuevosIntentos],
    );
}

/*
 * Un acceso correcto limpia los intentos fallidos anteriores.
 */
if ($intentosFallidos > 0) {
    $sqlRestablecer = "
        UPDATE seguridad_cuenta
        SET intentos_fallidos = 0,
            cuenta_bloqueada = FALSE,
            fecha_bloqueo = NULL
        WHERE id_seguridad = ?
    ";

    $stmtRestablecer = mysqli_prepare(
        $conexion,
        $sqlRestablecer,
    );

    if (!$stmtRestablecer) {
        error_log(
            "Error preparando el restablecimiento de seguridad: " .
            mysqli_error($conexion),
        );
        redirigirAlLogin("sistema");
    }

    mysqli_stmt_bind_param(
        $stmtRestablecer,
        "i",
        $idSeguridad,
    );

    if (!mysqli_stmt_execute($stmtRestablecer)) {
        error_log(
            "Error restableciendo la seguridad: " .
            mysqli_stmt_error($stmtRestablecer),
        );
        mysqli_stmt_close($stmtRestablecer);
        redirigirAlLogin("sistema");
    }

    mysqli_stmt_close($stmtRestablecer);
}

session_regenerate_id(true);

$_SESSION["usuario"] = $fila["usuario"];
$_SESSION["rol"] = $fila["nombre_rol"];
$_SESSION["id_login"] = $idLogin;
$_SESSION["id_usuario"] =
    $fila["id_usuario"] !== null
        ? (int) $fila["id_usuario"]
        : null;

registrarBitacora(
    $_SESSION["usuario"],
    "LOGIN",
    "usuarios_rol",
    "Inicio de sesión exitoso",
    $_SESSION["id_login"],
);

$destino = match ($_SESSION["rol"]) {
    "Administrador" => "admin.php",
    "Empleado" => "empleado.php",
    "Veterinario" => "veterinario.php",
    "Cliente" => "cliente.php",
    default => null,
};

if ($destino === null) {
    $_SESSION = [];
    session_destroy();

    redirigirAlLogin("rol");
}

header("Location: " . $destino);
exit();
