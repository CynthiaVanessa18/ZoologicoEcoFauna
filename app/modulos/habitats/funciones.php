<?php

const RUTA_CATALOGO_HABITATS = __DIR__ . "/../../../storage/datos/habitats.json";

function iconosHabitatsPermitidos(): array
{
    return [
        "bi-tree-fill" => "Bosque",
        "bi-sun-fill" => "Sabana",
        "bi-water" => "Humedal",
        "bi-cloud-fog2-fill" => "Bosque nuboso",
        "bi-flower1" => "Jardín",
        "bi-feather" => "Aviario",
        "bi-globe-americas" => "Ecosistema",
        "bi-droplet-fill" => "Acuático",
    ];
}

function leerHabitats(): array
{
    if (!is_file(RUTA_CATALOGO_HABITATS)) {
        return [];
    }

    $archivo = fopen(RUTA_CATALOGO_HABITATS, "rb");

    if ($archivo === false) {
        error_log("No fue posible abrir el catálogo de hábitats.");
        return [];
    }

    $contenido = "";

    try {
        if (!flock($archivo, LOCK_SH)) {
            return [];
        }

        $contenido = stream_get_contents($archivo);
        flock($archivo, LOCK_UN);
    } finally {
        fclose($archivo);
    }

    if (!is_string($contenido) || trim($contenido) === "") {
        return [];
    }

    try {
        $datos = json_decode($contenido, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $excepcion) {
        error_log("Catálogo de hábitats inválido: " . $excepcion->getMessage());
        return [];
    }

    if (
        !is_array($datos) ||
        ($datos !== [] && array_keys($datos) !== range(0, count($datos) - 1))
    ) {
        return [];
    }

    $habitats = array_values(array_filter($datos, "is_array"));

    usort(
        $habitats,
        static fn(array $a, array $b): int => strcasecmp(
            (string) ($a["nombre"] ?? ""),
            (string) ($b["nombre"] ?? ""),
        ),
    );

    return $habitats;
}

function guardarHabitats(array $habitats): bool
{
    $directorio = dirname(RUTA_CATALOGO_HABITATS);

    if (!is_dir($directorio) && !mkdir($directorio, 0775, true)) {
        error_log("No fue posible crear el directorio del catálogo de hábitats.");
        return false;
    }

    try {
        $json = json_encode(
            array_values($habitats),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
    } catch (JsonException $excepcion) {
        error_log("No fue posible convertir los hábitats a JSON: " . $excepcion->getMessage());
        return false;
    }

    $archivo = fopen(RUTA_CATALOGO_HABITATS, "c+b");

    if ($archivo === false) {
        error_log("No fue posible abrir el catálogo de hábitats para escritura.");
        return false;
    }

    $guardado = false;

    try {
        if (!flock($archivo, LOCK_EX)) {
            return false;
        }

        if (!ftruncate($archivo, 0) || !rewind($archivo)) {
            flock($archivo, LOCK_UN);
            return false;
        }

        $pendiente = $json . PHP_EOL;
        $total = strlen($pendiente);
        $escritos = 0;

        while ($escritos < $total) {
            $cantidad = fwrite($archivo, substr($pendiente, $escritos));

            if ($cantidad === false || $cantidad === 0) {
                break;
            }

            $escritos += $cantidad;
        }

        $guardado = $escritos === $total && fflush($archivo);
        flock($archivo, LOCK_UN);
    } finally {
        fclose($archivo);
    }

    return $guardado;
}

function siguienteIdHabitat(array $habitats): int
{
    $mayor = 0;

    foreach ($habitats as $habitat) {
        $mayor = max($mayor, (int) ($habitat["id"] ?? 0));
    }

    return $mayor + 1;
}

function buscarIndiceHabitat(array $habitats, int $id): ?int
{
    foreach ($habitats as $indice => $habitat) {
        if ((int) ($habitat["id"] ?? 0) === $id) {
            return $indice;
        }
    }

    return null;
}

function escaparHabitat(mixed $valor): string
{
    return htmlspecialchars((string) $valor, ENT_QUOTES, "UTF-8");
}
