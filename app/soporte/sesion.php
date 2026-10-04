<?php

const LIMITE_INACTIVIDAD_SESION = 1800;

/**
 * Elimina la cookie utilizada por la sesión actual.
 */
function eliminarCookieSesion(): void
{
    if (!ini_get("session.use_cookies")) {
        return;
    }

    $parametros = session_get_cookie_params();

    setcookie(session_name(), "", [
        "expires" => time() - 42000,
        "path" => $parametros["path"],
        "domain" => $parametros["domain"],
        "secure" => $parametros["secure"],
        "httponly" => $parametros["httponly"],
        "samesite" => $parametros["samesite"] ?? "Lax",
    ]);
}

/**
 * Inicia una sesión con cookies seguras y controla la inactividad.
 */
function iniciarSesionSegura(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        // Mantener los archivos de sesión junto a los datos privados del proyecto.
        $directorioSesiones = dirname(__DIR__, 2) . "/storage/sesiones";
        if (!is_dir($directorioSesiones) && !mkdir($directorioSesiones, 0700, true)) {
            throw new RuntimeException("No fue posible crear el directorio de sesiones.");
        }
        session_save_path($directorioSesiones);

        $conexionSegura =
            isset($_SERVER["HTTPS"]) &&
            $_SERVER["HTTPS"] !== "" &&
            strtolower((string) $_SERVER["HTTPS"]) !== "off";

        ini_set("session.use_strict_mode", "1");
        ini_set("session.use_only_cookies", "1");

        session_name("ECOFAUNA_SESION");

        session_set_cookie_params([
            "lifetime" => 0,
            "path" => "/",
            "secure" => $conexionSegura,
            "httponly" => true,
            "samesite" => "Lax",
        ]);

        session_start();
    }

    $ahora = time();
    $ultimaActividad = $_SESSION["ultima_actividad"] ?? null;

    if (
        is_numeric($ultimaActividad) &&
        $ahora - (int) $ultimaActividad > LIMITE_INACTIVIDAD_SESION
    ) {
        $_SESSION = [];
        eliminarCookieSesion();
        session_destroy();
        session_start();
        session_regenerate_id(true);
    }

    $_SESSION["ultima_actividad"] = $ahora;

    if (!headers_sent()) {
        header("X-Content-Type-Options: nosniff");
        header("X-Frame-Options: SAMEORIGIN");
        header("Referrer-Policy: same-origin");

        if (isset($_SESSION["usuario"])) {
            header("Cache-Control: no-store, no-cache, must-revalidate");
            header("Pragma: no-cache");
        }
    }
}

/**
 * Obtiene un token CSRF independiente para cada módulo.
 */
function obtenerTokenCsrfSesion(string $contexto): string
{
    if (preg_match('/^[a-z0-9_]+$/', $contexto) !== 1) {
        throw new InvalidArgumentException("Contexto CSRF inválido.");
    }

    $clave = "csrf_" . $contexto;
    $token = $_SESSION[$clave] ?? null;

    if (!is_string($token) || strlen($token) !== 64) {
        $token = bin2hex(random_bytes(32));
        $_SESSION[$clave] = $token;
    }

    return $token;
}

/**
 * Valida el token recibido en una petición POST.
 */
function tokenCsrfSesionValido(string $contexto, mixed $token): bool
{
    return is_string($token) &&
        $token !== "" &&
        hash_equals(obtenerTokenCsrfSesion($contexto), $token);
}

/**
 * Genera el campo oculto usado por un formulario protegido.
 */
function campoCsrfSesion(
    string $contexto,
    string $nombre = "csrf_token",
): string {
    return '<input type="hidden" name="' .
        htmlspecialchars($nombre, ENT_QUOTES, "UTF-8") .
        '" value="' .
        htmlspecialchars(
            obtenerTokenCsrfSesion($contexto),
            ENT_QUOTES,
            "UTF-8",
        ) .
        '">';
}

/**
 * Invalida el token actual después de una operación sensible.
 */
function renovarTokenCsrfSesion(string $contexto): void
{
    unset($_SESSION["csrf_" . $contexto]);
}

iniciarSesionSegura();
