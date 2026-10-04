<?php

const CONTEXTO_CSRF_CUIDADORES = "cuidadores";

function solicitudCuidadoresValida(): bool
{
    return tokenCsrfSesionValido(
        CONTEXTO_CSRF_CUIDADORES,
        $_POST["csrf_token"] ?? null,
    );
}

function campoCsrfCuidadores(): string
{
    return campoCsrfSesion(CONTEXTO_CSRF_CUIDADORES);
}

function renovarCsrfCuidadores(): void
{
    renovarTokenCsrfSesion(CONTEXTO_CSRF_CUIDADORES);
}

function fechaCuidadoresValida(string $fecha): bool
{
    $objeto = DateTime::createFromFormat("Y-m-d", $fecha);

    return $objeto !== false && $objeto->format("Y-m-d") === $fecha;
}

function redirigirCuidadores(
    string $ruta,
    string $mensaje,
    string $tipo = "success",
): void {
    $_SESSION["mensaje_cuidadores"] = $mensaje;
    $_SESSION["tipo_mensaje_cuidadores"] = $tipo;
    renovarCsrfCuidadores();

    header("Location: " . $ruta);
    exit();
}

function consumirMensajeCuidadores(): array
{
    $mensaje = (string) ($_SESSION["mensaje_cuidadores"] ?? "");
    $tipo = (string) ($_SESSION["tipo_mensaje_cuidadores"] ?? "success");

    unset(
        $_SESSION["mensaje_cuidadores"],
        $_SESSION["tipo_mensaje_cuidadores"],
    );

    return [$mensaje, $tipo];
}
