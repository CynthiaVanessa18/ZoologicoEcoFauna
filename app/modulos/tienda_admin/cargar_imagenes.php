<?php

require_once __DIR__ . "/../../soporte/sesion.php";
require_once __DIR__ . "/../../../config/database.php";

/** @var mysqli $conexion */

if (
    !isset($_SESSION["usuario"], $_SESSION["rol"], $_SESSION["id_login"]) ||
    $_SESSION["rol"] !== "Administrador"
) {
    header("Location: index.php");
    exit();
}

$mensajes = [];
$procesado = false;

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (
        !tokenCsrfSesionValido(
            "cargar_imagenes",
            $_POST["csrf_token"] ?? null,
        )
    ) {
        http_response_code(403);
        $mensajes[] = [
            "tipo" => "error",
            "texto" => "La solicitud expiró. Actualiza la página e inténtalo nuevamente.",
        ];
    } else {
        $directorios = [
            __DIR__ . "/../../../public/assets/img/productos",
            __DIR__ . "/../../../public/assets/imagenes/productos",
        ];
        $imagenes = [
            1 => ["manzana.png", "manzana_tienda.png"],
            2 => ["banano.png", "banano_tienda.png"],
            3 => ["refresco.png", "refresco_embotellado.png"],
            4 => ["gorra.png", "gorra_eco.png"],
            5 => ["llavero.png", "llavero_tucan.png"],
            6 => ["camiseta.png", "camiseta_ecofauna.png"],
        ];

        $enTransaccion = false;

        try {
            if (!mysqli_begin_transaction($conexion)) {
                throw new RuntimeException(
                    "No fue posible iniciar la carga de imágenes.",
                );
            }

            $enTransaccion = true;

            foreach ($imagenes as $idProducto => $nombresArchivo) {
                $ruta = null;

                foreach ($directorios as $directorio) {
                    foreach ($nombresArchivo as $nombreArchivo) {
                        $rutaPosible = $directorio . "/" . $nombreArchivo;

                        if (is_file($rutaPosible)) {
                            $ruta = $rutaPosible;
                            break 2;
                        }
                    }
                }

                if ($ruta === null) {
                    $mensajes[] = [
                        "tipo" => "advertencia",
                        "texto" => "No se encontró una imagen para el producto #{$idProducto}.",
                    ];
                    continue;
                }

                $archivo = basename($ruta);

                $contenido = file_get_contents($ruta);

                if ($contenido === false || $contenido === "") {
                    throw new RuntimeException(
                        "No fue posible leer la imagen {$archivo}.",
                    );
                }

                $stmt = mysqli_prepare(
                    $conexion,
                    "UPDATE inventario_tienda
                     SET foto = ?
                     WHERE id_producto = ?",
                );

                if (!$stmt) {
                    throw new RuntimeException(mysqli_error($conexion));
                }

                $blob = null;
                mysqli_stmt_bind_param($stmt, "bi", $blob, $idProducto);
                mysqli_stmt_send_long_data($stmt, 0, $contenido);

                if (!mysqli_stmt_execute($stmt)) {
                    $errorTecnico = mysqli_stmt_error($stmt);
                    mysqli_stmt_close($stmt);
                    throw new RuntimeException($errorTecnico);
                }

                mysqli_stmt_close($stmt);

                $mensajes[] = [
                    "tipo" => "exito",
                    "texto" => "Imagen del producto #{$idProducto} cargada correctamente.",
                ];
            }

            if (
                !registrarBitacora(
                    $_SESSION["usuario"],
                    "CARGAR IMÁGENES",
                    "inventario_tienda",
                    "Se ejecutó la carga inicial de imágenes de productos.",
                    null,
                )
            ) {
                throw new RuntimeException(
                    "No fue posible registrar la acción en la bitácora.",
                );
            }

            if (!mysqli_commit($conexion)) {
                throw new RuntimeException(
                    "No fue posible confirmar la carga de imágenes.",
                );
            }

            $enTransaccion = false;
            $procesado = true;
            renovarTokenCsrfSesion("cargar_imagenes");
        } catch (Throwable $error) {
            if ($enTransaccion) {
                mysqli_rollback($conexion);
            }

            error_log("Error cargando imágenes: " . $error->getMessage());
            $mensajes = [[
                "tipo" => "error",
                "texto" => "No fue posible completar la carga de imágenes.",
            ]];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cargar imágenes - EcoFauna</title>
    <link rel="stylesheet" href="css/styleCargaImagenes.css">
    <link rel="stylesheet" href="css/crud-modern.css?v=1">
    <link rel="stylesheet" href="css/ecofauna-unified.css?v=1">
</head>

<body class="ecofauna-unified crud-modern">

    <main class="carga-imagenes">
        <h1>Cargar imágenes de productos</h1>

        <?php if ($_SERVER["REQUEST_METHOD"] !== "POST"): ?>
            <p>Esta herramienta actualiza las imágenes iniciales de la tienda.</p>

            <form method="POST">
                <?= campoCsrfSesion("cargar_imagenes") ?>
                <button type="submit">Iniciar carga</button>
            </form>
        <?php else: ?>
            <?php foreach ($mensajes as $mensaje): ?>
                <p class="mensaje-carga mensaje-carga-<?= htmlspecialchars(
                    $mensaje["tipo"],
                    ENT_QUOTES,
                    "UTF-8",
                ) ?>">
                    <?= htmlspecialchars(
                        $mensaje["texto"],
                        ENT_QUOTES,
                        "UTF-8",
                    ) ?>
                </p>
            <?php endforeach; ?>

            <?php if ($procesado): ?>
                <h2 class="proceso-terminado">Proceso terminado.</h2>
            <?php endif; ?>

            <a href="admin.php">Regresar al panel</a>
        <?php endif; ?>
    </main>
</body>
</html>