<?php

/**
 * Obtiene el token CSRF usado por las acciones del módulo veterinario.
 */
function obtenerTokenCsrfVeterinario(): string
{
    $token = $_SESSION["csrf_veterinario"] ?? null;

    if (!is_string($token) || strlen($token) !== 64) {
        $token = bin2hex(random_bytes(32));
        $_SESSION["csrf_veterinario"] = $token;
    }

    return $token;
}

/**
 * Comprueba que una petición POST provenga de un formulario veterinario.
 */
function tokenCsrfVeterinarioValido(mixed $token): bool
{
    return is_string($token) &&
        $token !== "" &&
        hash_equals(obtenerTokenCsrfVeterinario(), $token);
}

/**
 * Genera el campo oculto que debe incluir cada formulario POST.
 */
function campoCsrfVeterinario(): string
{
    return '<input type="hidden" name="csrf_token" value="' .
        htmlspecialchars(
            obtenerTokenCsrfVeterinario(),
            ENT_QUOTES,
            "UTF-8",
        ) .
        '">';
}

/**
 * Comprueba que una fecha tenga el formato ISO usado por los formularios.
 */
function fechaIsoValida(string $fecha): bool
{
    $fechaObjeto = DateTime::createFromFormat("Y-m-d", $fecha);

    return $fechaObjeto !== false && $fechaObjeto->format("Y-m-d") === $fecha;
}
class ErrorTecnicoVeterinario extends RuntimeException
{
}
/**
 * Guarda el detalle técnico del error y registra el evento en la bitácora.
 */
function registrarErrorVeterinario(
    string $accion,
    string $tabla,
    Throwable $error,
): void {
    error_log($accion . ": " . $error->getMessage());

    try {
        registrarBitacora(
            $_SESSION["usuario"] ?? "Sistema",
            "ERROR " . $accion,
            $tabla,
            $error->getMessage(),
            null,
        );
    } catch (Throwable $errorBitacora) {
        error_log(
            "No fue posible registrar el error en la bitácora: " .
                $errorBitacora->getMessage(),
        );
    }
}

function mensajeErrorVeterinario(Throwable $error): string
{
    if ($error instanceof ErrorTecnicoVeterinario) {
        return "Ocurrió un error interno al procesar la solicitud.";
    }

    $mensaje = trim($error->getMessage());

    return $mensaje !== ""
        ? $mensaje
        : "No fue posible completar la operación.";
}