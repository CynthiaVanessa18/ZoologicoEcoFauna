<?php

/** Resuelve únicamente recursos públicos; nunca acepta rutas fuera de su carpeta. */
function recursoPublico(string $ruta): ?string
{
    $raiz = dirname(__DIR__, 2);
    $alias = [
        'assets/' => '/public/assets/',
        'css/' => '/public/assets/css/',
        'js/' => '/public/assets/js/',
        'img/' => '/public/assets/img/',
        'imagenes/' => '/public/assets/imagenes/',
        'animales/' => '/public/assets/animales/',
        'uploads/' => '/storage/uploads/',
    ];
    if ($ruta === 'estilo.css') {
        return $raiz . '/public/assets/css/estilo.css';
    }
    foreach ($alias as $prefijo => $directorio) {
        if (!str_starts_with($ruta, $prefijo)) {
            continue;
        }
        $base = realpath($raiz . $directorio);
        $archivo = realpath($raiz . $directorio . substr($ruta, strlen($prefijo)));
        if ($base === false || $archivo === false || !is_file($archivo)) {
            return null;
        }
        if (!str_starts_with($archivo, $base . DIRECTORY_SEPARATOR)) {
            return null;
        }
        $extensiones = ['css', 'js', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'avif', 'ico', 'woff', 'woff2', 'ttf'];
        return in_array(strtolower(pathinfo($archivo, PATHINFO_EXTENSION)), $extensiones, true) ? $archivo : null;
    }
    return null;
}
