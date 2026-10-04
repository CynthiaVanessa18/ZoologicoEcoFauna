<?php

// Configuración local opcional; las variables del entorno tienen prioridad.
function entornoEcoFauna(string $nombre, string $predeterminado = ""): string
{
    static $local = null;
    if ($local === null) {
        $ruta = __DIR__ . "/local.php";
        $local = is_file($ruta) ? require $ruta : [];
        if (!is_array($local)) {
            throw new RuntimeException("La configuración local debe devolver un arreglo.");
        }
    }
    $valor = getenv($nombre);
    return $valor !== false ? $valor : (string) ($local[$nombre] ?? $predeterminado);
}

function claveCifradoEcoFauna(): string
{
    $clave = entornoEcoFauna("ECOFAUNA_ENCRYPTION_KEY");
    if (strlen($clave) < 32) {
        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, "Configura ECOFAUNA_ENCRYPTION_KEY con al menos 32 caracteres.\n");
            exit(1);
        }
        http_response_code(503);
        exit("Configura ECOFAUNA_ENCRYPTION_KEY con al menos 32 caracteres antes de usar los pagos de demostración.");
    }
    return $clave;
}
