<?php
// Pruebas sin base de datos para la reorganización de carpetas.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$raiz = dirname(__DIR__);
require $raiz . '/app/soporte/router.php';
$errores = [];
$rutas = require $raiz . '/config/rutas.php';
foreach ($rutas as $url => $archivo) {
    if (!is_file($raiz . '/' . $archivo) || !str_starts_with($archivo, 'app/modulos/')) {
        $errores[] = "Módulo no encontrado: $url";
    }
}
$referencias = 0;
foreach (['app', 'config', 'scripts'] as $carpeta) {
    $iterador = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($raiz . '/' . $carpeta, FilesystemIterator::SKIP_DOTS));
    foreach ($iterador as $archivo) {
        if ($archivo->getExtension() !== 'php' || $archivo->getFilename() === 'local.php') {
            continue;
        }
        $texto = file_get_contents($archivo->getPathname());
        preg_match_all('~__DIR__\s*\.\s*[\x22\x27]([^\x22\x27]+)[\x22\x27]~', $texto, $coincidencias);
        foreach ($coincidencias[1] as $referencia) {
            if ($referencia === '/local.php') {
                continue; // La configuración privada es opcional.
            }
            $referencias++;
            if (!file_exists($archivo->getPath() . '/' . $referencia)) {
                $errores[] = $archivo->getFilename() . ': referencia inexistente ' . $referencia;
            }
        }
    }
}
foreach (['css/stylelogin.css', 'js/admin.js', 'assets/css/stylelogin.css', 'estilo.css'] as $recurso) {
    if (recursoPublico($recurso) === null) {
        $errores[] = "Recurso no encontrado: $recurso";
    }
}
foreach (['config/local.php', 'database/bd.sql', 'uploads/../../config/local.php', 'assets/../../../config/local.php', 'assets/../index.php', 'app/modulos/autenticacion/index.php'] as $privado) {
    if (recursoPublico($privado) !== null) {
        $errores[] = "Recurso privado accesible: $privado";
    }
}
require $raiz . '/app/modulos/habitats/funciones.php';
if (leerHabitats() === []) {
    $errores[] = 'No se pudo leer el catálogo de hábitats trasladado.';
}
if ($errores !== []) {
    fwrite(STDERR, implode(PHP_EOL, $errores) . PHP_EOL);
    exit(1);
}
echo count($rutas) . " rutas y $referencias referencias locales verificadas; recursos, privacidad y catálogo OK.\n";
