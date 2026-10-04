<?php
require_once __DIR__ . "/../../soporte/sesion.php";

require_once __DIR__ . "/../../../config/database.php";
/** @var mysqli $conexion */

if (
    !isset($_SESSION["usuario"], $_SESSION["rol"], $_SESSION["id_login"]) ||
    ($_SESSION["rol"] !== "Empleado" && $_SESSION["rol"] !== "Veterinario")
) {
    header("Location: index.php");
    exit();
}

exigirPermiso(
    "Gestionar inventario",
    ["Empleado", "Veterinario"],
    "index.php",
);

function enviarMensajeInventario(
    string $mensaje,
    string $tipo = "success",
): void {
    $_SESSION["mensaje_inventario"] = $mensaje;
    $_SESSION["tipo_mensaje_inventario"] = $tipo;

    header("Location: inventario.php");
    exit();
}

function csrfInventarioValido(): bool
{
    $tokenSesion = $_SESSION["csrf_inventario"] ?? "";
    $tokenFormulario = $_POST["csrf_token"] ?? "";

    return is_string($tokenSesion) &&
        is_string($tokenFormulario) &&
        $tokenSesion !== "" &&
        $tokenFormulario !== "" &&
        hash_equals($tokenSesion, $tokenFormulario);
}

function fechaInventarioValida(string $fecha): bool
{
    $fechaObjeto = DateTimeImmutable::createFromFormat("!Y-m-d", $fecha);

    return $fechaObjeto !== false &&
        $fechaObjeto->format("Y-m-d") === $fecha;
}

function validarDatosLoteInventario(array $entrada): array
{
    $unidad = trim((string) ($entrada["unidad_medida"] ?? ""));
    $cantidad = filter_var(
        $entrada["cantidad"] ?? null,
        FILTER_VALIDATE_FLOAT,
    );
    $fechaIngreso = trim((string) ($entrada["fecha_ingreso"] ?? ""));
    $fechaCaducidadEntrada = trim(
        (string) ($entrada["fecha_caducidad"] ?? ""),
    );
    $fechaCaducidad = $fechaCaducidadEntrada !== ""
        ? $fechaCaducidadEntrada
        : null;

    $longitudUnidad = function_exists("mb_strlen")
        ? mb_strlen($unidad, "UTF-8")
        : strlen($unidad);

    if (
        $unidad === "" ||
        $longitudUnidad > 30 ||
        preg_match('/[\x00-\x1F\x7F]/u', $unidad) === 1
    ) {
        throw new DomainException("La unidad de medida no es válida.");
    }

    if (
        $cantidad === false ||
        $cantidad <= 0 ||
        $cantidad > 99999999.99
    ) {
        throw new DomainException("La cantidad debe ser mayor que cero.");
    }

    if (!fechaInventarioValida($fechaIngreso)) {
        throw new DomainException("La fecha de ingreso no es válida.");
    }

    $hoy = date("Y-m-d");

    if ($fechaIngreso > $hoy) {
        throw new DomainException(
            "La fecha de ingreso no puede estar en el futuro.",
        );
    }

    if ($fechaCaducidad !== null) {
        if (!fechaInventarioValida($fechaCaducidad)) {
            throw new DomainException("La fecha de caducidad no es válida.");
        }

        if ($fechaCaducidad < $fechaIngreso) {
            throw new DomainException(
                "La fecha de caducidad no puede ser menor que la fecha de ingreso.",
            );
        }

        if ($fechaCaducidad < $hoy) {
            throw new DomainException(
                "No se puede registrar un producto ya vencido.",
            );
        }
    }

    return [
        "unidad" => $unidad,
        "cantidad" => round((float) $cantidad, 2),
        "fecha_ingreso" => $fechaIngreso,
        "fecha_caducidad" => $fechaCaducidad,
    ];
}

function obtenerLoteInventarioParaActualizar(
    mysqli $conexion,
    int $idInventario,
): array {
    $stmt = mysqli_prepare(
        $conexion,
        "SELECT id_inventarioZoo, nombre_producto, activo
         FROM inventariozoo
         WHERE id_inventarioZoo = ?
         LIMIT 1
         FOR UPDATE",
    );

    if (!$stmt) {
        throw new RuntimeException(mysqli_error($conexion));
    }

    mysqli_stmt_bind_param($stmt, "i", $idInventario);

    if (!mysqli_stmt_execute($stmt)) {
        throw new RuntimeException(mysqli_stmt_error($stmt));
    }

    $lote = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    if (!$lote) {
        throw new DomainException("El lote de inventario no existe.");
    }

    return $lote;
}

function loteInventarioPermitidoParaRol(
    string $nombreProducto,
    string $rol,
    array $nombresMedicamentos,
): bool {
    $esMedicamento = in_array(
        $nombreProducto,
        $nombresMedicamentos,
        true,
    );

    return $rol === "Veterinario" ? $esMedicamento : !$esMedicamento;
}

$_SESSION["csrf_inventario"] ??= bin2hex(random_bytes(32));

$csrfInventarioEscapado = htmlspecialchars(
    $_SESSION["csrf_inventario"],
    ENT_QUOTES,
    "UTF-8",
);

$mensaje = $_SESSION["mensaje_inventario"] ?? "";
$tipoMensaje = $_SESSION["tipo_mensaje_inventario"] ?? "success";

unset(
    $_SESSION["mensaje_inventario"],
    $_SESSION["tipo_mensaje_inventario"],
);

/*=========================================
=            PRODUCTOS PREDEFINIDOS       =
=========================================*/
$productosArray = [];
if ($_SESSION["rol"] === "Veterinario") {
    $productosArray = [
        [
            "id_producto" => 1,
            "nombre_producto" => "Antibiótico veterinario",
            "tipo_producto" => "Medicina",
        ],
        [
            "id_producto" => 2,
            "nombre_producto" => "Antiparasitario",
            "tipo_producto" => "Medicina",
        ],
        [
            "id_producto" => 3,
            "nombre_producto" => "Vitaminas inyectables",
            "tipo_producto" => "Medicina",
        ],
        [
            "id_producto" => 4,
            "nombre_producto" => "Vacuna contra rabia",
            "tipo_producto" => "Medicina",
        ],
        [
            "id_producto" => 5,
            "nombre_producto" => "Analgésico veterinario",
            "tipo_producto" => "Medicina",
        ],
        [
            "id_producto" => 6,
            "nombre_producto" => "Antiinflamatorio veterinario",
            "tipo_producto" => "Medicina",
        ],
        [
            "id_producto" => 7,
            "nombre_producto" => "Suero veterinario",
            "tipo_producto" => "Medicina",
        ],
        [
            "id_producto" => 8,
            "nombre_producto" => "Pomada cicatrizante",
            "tipo_producto" => "Medicina",
        ],
    ];
} else {
    $productosArray = [
        [
            "id_producto" => 9,
            "nombre_producto" => "Manzana",
            "tipo_producto" => "Alimento",
        ],
        [
            "id_producto" => 10,
            "nombre_producto" => "Banano",
            "tipo_producto" => "Alimento",
        ],
        [
            "id_producto" => 11,
            "nombre_producto" => "Carne de res",
            "tipo_producto" => "Alimento",
        ],
        [
            "id_producto" => 12,
            "nombre_producto" => "Pescado fresco",
            "tipo_producto" => "Alimento",
        ],
        [
            "id_producto" => 13,
            "nombre_producto" => "Mango",
            "tipo_producto" => "Alimento",
        ],
        [
            "id_producto" => 14,
            "nombre_producto" => "Zanahoria",
            "tipo_producto" => "Alimento",
        ],
        [
            "id_producto" => 15,
            "nombre_producto" => "Lechuga romana",
            "tipo_producto" => "Alimento",
        ],
        [
            "id_producto" => 16,
            "nombre_producto" => "Cloro",
            "tipo_producto" => "Limpieza",
        ],
        [
            "id_producto" => 17,
            "nombre_producto" => "Pala para limpieza",
            "tipo_producto" => "Herramienta",
        ],
        [
            "id_producto" => 18,
            "nombre_producto" => "Escoba industrial",
            "tipo_producto" => "Herramienta",
        ],
        [
            "id_producto" => 19,
            "nombre_producto" => "Manguera de agua",
            "tipo_producto" => "Herramienta",
        ],
    ];
}

$nombresMedicamentos = [
    "Antibiótico veterinario",
    "Antiparasitario",
    "Vitaminas inyectables",
    "Vacuna contra rabia",
    "Analgésico veterinario",
    "Antiinflamatorio veterinario",
    "Suero veterinario",
    "Pomada cicatrizante",
];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (!csrfInventarioValido()) {
        enviarMensajeInventario(
            "La sesión del formulario venció. Intenta nuevamente.",
            "danger",
        );
    }

    $accionFormulario = trim((string) ($_POST["accion"] ?? ""));
    $accionesPermitidas = ["guardar", "editar", "eliminar"];

    if (!in_array($accionFormulario, $accionesPermitidas, true)) {
        enviarMensajeInventario("La acción solicitada no es válida.", "danger");
    }

    $enTransaccion = false;

    try {
        $datosLote = null;

        if ($accionFormulario === "guardar" || $accionFormulario === "editar") {
            $datosLote = validarDatosLoteInventario($_POST);
        }

        if (!mysqli_begin_transaction($conexion)) {
            throw new RuntimeException(mysqli_error($conexion));
        }

        $enTransaccion = true;
        $idRegistro = null;
        $accionBitacora = "";
        $detalleBitacora = "";
        $mensajeExito = "";

        if ($accionFormulario === "guardar") {
            $idProducto = filter_var(
                $_POST["id_producto"] ?? null,
                FILTER_VALIDATE_INT,
                ["options" => ["min_range" => 1]],
            );

            if (!$idProducto) {
                throw new DomainException("Debes seleccionar un producto.");
            }

            $datosProducto = null;

            foreach ($productosArray as $productoPermitido) {
                if ($productoPermitido["id_producto"] === $idProducto) {
                    $datosProducto = $productoPermitido;
                    break;
                }
            }

            if (!$datosProducto) {
                throw new DomainException(
                    "El producto seleccionado no está permitido para tu rol.",
                );
            }

            $nombreProducto = $datosProducto["nombre_producto"];
            $unidad = $datosLote["unidad"];
            $cantidad = $datosLote["cantidad"];
            $fechaIngreso = $datosLote["fecha_ingreso"];
            $fechaCaducidad = $datosLote["fecha_caducidad"];

            $stmt = mysqli_prepare(
                $conexion,
                "INSERT INTO inventariozoo (
                    nombre_producto,
                    unidad_medida,
                    cantidad,
                    fecha_caducidad,
                    fecha_ingreso
                 ) VALUES (?, ?, ?, ?, ?)",
            );

            if (!$stmt) {
                throw new RuntimeException(mysqli_error($conexion));
            }

            mysqli_stmt_bind_param(
                $stmt,
                "ssdss",
                $nombreProducto,
                $unidad,
                $cantidad,
                $fechaCaducidad,
                $fechaIngreso,
            );

            if (!mysqli_stmt_execute($stmt)) {
                throw new RuntimeException(mysqli_stmt_error($stmt));
            }

            $idRegistro = mysqli_insert_id($conexion);
            mysqli_stmt_close($stmt);

            $accionBitacora = "AGREGAR LOTE";
            $detalleBitacora = "Se agregó un lote de " . $nombreProducto . ".";
            $mensajeExito = "Producto agregado correctamente al inventario.";
        } elseif ($accionFormulario === "editar") {
            $idInventario = filter_var(
                $_POST["id_inventario_zoo"] ?? null,
                FILTER_VALIDATE_INT,
                ["options" => ["min_range" => 1]],
            );

            if (!$idInventario) {
                throw new DomainException("El lote seleccionado no es válido.");
            }

            $lote = obtenerLoteInventarioParaActualizar(
                $conexion,
                $idInventario,
            );

            if ((int) $lote["activo"] !== 1) {
                throw new DomainException("El lote ya se encuentra oculto.");
            }

            if (
                !loteInventarioPermitidoParaRol(
                    $lote["nombre_producto"],
                    $_SESSION["rol"],
                    $nombresMedicamentos,
                )
            ) {
                throw new DomainException(
                    "No tienes permiso para modificar este lote.",
                );
            }

            $unidad = $datosLote["unidad"];
            $cantidad = $datosLote["cantidad"];
            $fechaIngreso = $datosLote["fecha_ingreso"];
            $fechaCaducidad = $datosLote["fecha_caducidad"];

            $stmt = mysqli_prepare(
                $conexion,
                "UPDATE inventariozoo
                 SET unidad_medida = ?, cantidad = ?,
                     fecha_ingreso = ?, fecha_caducidad = ?
                 WHERE id_inventarioZoo = ?",
            );

            if (!$stmt) {
                throw new RuntimeException(mysqli_error($conexion));
            }

            mysqli_stmt_bind_param(
                $stmt,
                "sdssi",
                $unidad,
                $cantidad,
                $fechaIngreso,
                $fechaCaducidad,
                $idInventario,
            );

            if (!mysqli_stmt_execute($stmt)) {
                throw new RuntimeException(mysqli_stmt_error($stmt));
            }

            mysqli_stmt_close($stmt);

            $idRegistro = $idInventario;
            $accionBitacora = "EDITAR LOTE";
            $detalleBitacora =
                "Se actualizó el lote de " . $lote["nombre_producto"] . ".";
            $mensajeExito = "Lote actualizado correctamente.";
        } else {
            $idInventario = filter_var(
                $_POST["id_inventario_zoo"] ?? null,
                FILTER_VALIDATE_INT,
                ["options" => ["min_range" => 1]],
            );

            if (!$idInventario) {
                throw new DomainException("El lote seleccionado no es válido.");
            }

            $lote = obtenerLoteInventarioParaActualizar(
                $conexion,
                $idInventario,
            );

            if ((int) $lote["activo"] !== 1) {
                throw new DomainException("El lote ya se encuentra oculto.");
            }

            if (
                !loteInventarioPermitidoParaRol(
                    $lote["nombre_producto"],
                    $_SESSION["rol"],
                    $nombresMedicamentos,
                )
            ) {
                throw new DomainException(
                    "No tienes permiso para ocultar este lote.",
                );
            }

            $stmt = mysqli_prepare(
                $conexion,
                "UPDATE inventariozoo
                 SET activo = 0
                 WHERE id_inventarioZoo = ?",
            );

            if (!$stmt) {
                throw new RuntimeException(mysqli_error($conexion));
            }

            mysqli_stmt_bind_param($stmt, "i", $idInventario);

            if (!mysqli_stmt_execute($stmt)) {
                throw new RuntimeException(mysqli_stmt_error($stmt));
            }

            mysqli_stmt_close($stmt);

            $idRegistro = $idInventario;
            $accionBitacora = "OCULTAR LOTE";
            $detalleBitacora =
                "Se ocultó el lote de " . $lote["nombre_producto"] . ".";
            $mensajeExito = "Lote ocultado correctamente.";
        }

        if (
            !registrarBitacora(
                $_SESSION["usuario"],
                $accionBitacora,
                "inventariozoo",
                $detalleBitacora,
                $idRegistro,
            )
        ) {
            throw new RuntimeException(
                "No fue posible registrar la acción en la bitácora.",
            );
        }

        if (!mysqli_commit($conexion)) {
            throw new RuntimeException(mysqli_error($conexion));
        }

        $enTransaccion = false;
        $_SESSION["csrf_inventario"] = bin2hex(random_bytes(32));

        enviarMensajeInventario($mensajeExito);
    } catch (DomainException $error) {
        if ($enTransaccion) {
            mysqli_rollback($conexion);
        }

        enviarMensajeInventario($error->getMessage(), "warning");
    } catch (Throwable $error) {
        if ($enTransaccion) {
            mysqli_rollback($conexion);
        }

        error_log(
            "Error procesando inventario (" .
                $accionFormulario .
                "): " .
                $error->getMessage(),
        );

        enviarMensajeInventario(
            "No fue posible completar la operación. Intenta nuevamente.",
            "danger",
        );
    }
}

// Paginación para Inventario
$registrosPorPagina = 5;
$paginaActual = isset($_GET["pagina"]) ? (int) $_GET["pagina"] : 1;
if ($paginaActual < 1) {
    $paginaActual = 1;
}
$offset = ($paginaActual - 1) * $registrosPorPagina;

if ($_SESSION["rol"] == "Veterinario") {
    $sqlContar =
        "SELECT COUNT(*) AS total FROM inventariozoo WHERE activo = 1 AND nombre_producto IN ('Antibiótico veterinario', 'Antiparasitario', 'Vitaminas inyectables', 'Vacuna contra rabia', 'Analgésico veterinario', 'Antiinflamatorio veterinario', 'Suero veterinario', 'Pomada cicatrizante')";
} else {
    $sqlContar =
        "SELECT COUNT(*) AS total FROM inventariozoo WHERE activo = 1 AND nombre_producto NOT IN ('Antibiótico veterinario', 'Antiparasitario', 'Vitaminas inyectables', 'Vacuna contra rabia', 'Analgésico veterinario', 'Antiinflamatorio veterinario', 'Suero veterinario', 'Pomada cicatrizante')";
}
$resContar = mysqli_query($conexion, $sqlContar);

if (!$resContar) {
    error_log("Error contando inventario: " . mysqli_error($conexion));
    $totalRegistros = 0;
} else {
    $filaContar = mysqli_fetch_assoc($resContar);
    $totalRegistros = (int) ($filaContar["total"] ?? 0);
}

$totalPaginas = ceil($totalRegistros / $registrosPorPagina);
if ($totalPaginas < 1) {
    $totalPaginas = 1;
}
if ($paginaActual > $totalPaginas) {
    $paginaActual = $totalPaginas;
    $offset = ($paginaActual - 1) * $registrosPorPagina;
}

if ($_SESSION["rol"] == "Veterinario") {
    $sqlInventario = "
        SELECT
            iz.id_inventarioZoo AS id_inventario_zoo,
            iz.unidad_medida,
            iz.cantidad,
            iz.fecha_caducidad,
            iz.fecha_ingreso,
            iz.nombre_producto,
            'Medicina' AS tipo_producto
        FROM inventariozoo iz
        WHERE iz.activo = 1 AND iz.nombre_producto IN (
            'Antibiótico veterinario', 'Antiparasitario', 'Vitaminas inyectables',
            'Vacuna contra rabia', 'Analgésico veterinario', 'Antiinflamatorio veterinario',
            'Suero veterinario', 'Pomada cicatrizante'
        )
        ORDER BY
            iz.fecha_caducidad IS NULL,
            iz.fecha_caducidad ASC
        LIMIT $registrosPorPagina OFFSET $offset
    ";
} else {
    $sqlInventario = "
        SELECT
            iz.id_inventarioZoo AS id_inventario_zoo,
            iz.nombre_producto,
            CASE
                WHEN iz.nombre_producto = 'Cloro' THEN 'Limpieza'
                WHEN iz.nombre_producto IN ('Pala para limpieza', 'Escoba industrial', 'Manguera de agua') THEN 'Herramienta'
                ELSE 'Alimento'
            END AS tipo_producto,
            iz.unidad_medida,
            iz.cantidad,
            iz.fecha_ingreso,
            iz.fecha_caducidad
        FROM inventariozoo iz
        WHERE iz.activo = 1 AND iz.nombre_producto NOT IN (
            'Antibiótico veterinario', 'Antiparasitario', 'Vitaminas inyectables',
            'Vacuna contra rabia', 'Analgésico veterinario', 'Antiinflamatorio veterinario',
            'Suero veterinario', 'Pomada cicatrizante'
        )
        ORDER BY iz.nombre_producto ASC
        LIMIT $registrosPorPagina OFFSET $offset
    ";
}

$resultadoInventario = mysqli_query($conexion, $sqlInventario);

if (!$resultadoInventario) {
    error_log("Error consultando inventario: " . mysqli_error($conexion));
}

if (isset($_GET["ajax"])) {
    ob_start();
    if (!$resultadoInventario) {
        echo '<tr><td colspan="9" class="text-center text-danger">No fue posible cargar el inventario.</td></tr>';
    } elseif (mysqli_num_rows($resultadoInventario) === 0) {
        echo '<tr><td colspan="9" class="text-center text-muted">No hay productos registrados.</td></tr>';
    } else {
        while ($producto = mysqli_fetch_assoc($resultadoInventario)) {
            $diasCaducidad = null;
            if ($producto["fecha_caducidad"] !== null) {
                $diasCaducidad =
                    (int) ((strtotime($producto["fecha_caducidad"]) -
                        strtotime(date("Y-m-d"))) /
                        86400);
            }
            $esMedicamento = $producto["tipo_producto"] === "Medicina";
            $vencido =
                $producto["fecha_caducidad"] !== null &&
                $producto["fecha_caducidad"] < date("Y-m-d");

            echo '<tr class="' . ($vencido ? "table-danger" : "") . '">';
            echo "<td>#" . (int) $producto["id_inventario_zoo"] . "</td>";
            echo "<td><strong>" .
                htmlspecialchars($producto["nombre_producto"]) .
                "</strong>" .
                ($vencido
                    ? ' <span class="badge bg-danger">VENCIDO</span>'
                    : "") .
                "</td>";
            echo "<td>" .
                htmlspecialchars($producto["unidad_medida"]) .
                "</td>";
            echo "<td>" .
                number_format((float) $producto["cantidad"], 2, ",", ".") .
                "</td>";
            echo "<td>" .
                date("d/m/Y", strtotime($producto["fecha_ingreso"])) .
                "</td>";
            echo "<td>" .
                ($producto["fecha_caducidad"] !== null
                    ? date("d/m/Y", strtotime($producto["fecha_caducidad"]))
                    : "N/A") .
                "</td>";
            echo "<td>";
            if ($producto["fecha_caducidad"] !== null) {
                if ($diasCaducidad > 0) {
                    echo '<span class="text-success fw-bold">' .
                        $diasCaducidad .
                        " días</span>";
                } elseif ($diasCaducidad === 0) {
                    echo '<span class="text-warning fw-bold">Vence hoy</span>';
                } else {
                    echo '<span class="text-danger fw-bold">Vencido</span>';
                }
            } else {
                echo '<span class="text-muted">N/A</span>';
            }
            echo "</td>";
            echo "<td>";
            if ($vencido) {
                echo '<span class="badge bg-danger">No utilizable</span>';
            } else {
                echo '<span class="badge bg-success">Activo</span>';
            }
            echo "</td>";
            echo "<td>";
            echo '<div class="d-flex gap-1 justify-content-center">';
            echo '<button type="button" class="btn btn-warning btn-sm btn-editar-inventario"
                    data-id="' .
                (int) $producto["id_inventario_zoo"] .
                '"
                    data-nombre="' .
                htmlspecialchars(
                    $producto["nombre_producto"],
                    ENT_QUOTES,
                    "UTF-8",
                ) .
                '"
                    data-unidad="' .
                htmlspecialchars(
                    $producto["unidad_medida"] ?? "",
                    ENT_QUOTES,
                    "UTF-8",
                ) .
                '"
                    data-cantidad="' .
                (float) $producto["cantidad"] .
                '"
                    data-ingreso="' .
                htmlspecialchars(
                    $producto["fecha_ingreso"],
                    ENT_QUOTES,
                    "UTF-8",
                ) .
                '"
                    data-caducidad="' .
                htmlspecialchars(
                    $producto["fecha_caducidad"] ?? "",
                    ENT_QUOTES,
                    "UTF-8",
                ) .
                '">
                    <i class="bi bi-pencil-square"></i>
                  </button>';
            echo '<form action="inventario.php" method="POST" class="d-inline form-eliminar-lote">
                    <input type="hidden" name="csrf_token" value="' .
                $csrfInventarioEscapado .
                '">
                    <input type="hidden" name="accion" value="eliminar">
                    <input type="hidden" name="id_inventario_zoo" value="' .
                (int) $producto["id_inventario_zoo"] .
                '">
                    <button type="submit" class="btn btn-danger btn-sm" title="Ocultar lote" aria-label="Ocultar lote">
                        <i class="bi bi-eye-slash"></i>
                    </button>
                  </form>';
            echo "</div>";
            echo "</td>";
            echo "</tr>";
        }
    }
    $tbodyHtml = ob_get_clean();

    ob_start();
    if ($totalPaginas > 1) {
        echo '<ul class="pagination justify-content-center">';
        echo '<li class="page-item ' .
            ($paginaActual <= 1 ? "disabled" : "") .
            '">';
        echo '<a class="page-link" href="#" data-pagina="' .
            ($paginaActual - 1) .
            '">Anterior</a>';
        echo "</li>";
        for ($i = 1; $i <= $totalPaginas; $i++) {
            echo '<li class="page-item ' .
                ($paginaActual === $i ? "active" : "") .
                '">';
            echo '<a class="page-link" href="#" data-pagina="' .
                $i .
                '">' .
                $i .
                "</a>";
            echo "</li>";
        }
        echo '<li class="page-item ' .
            ($paginaActual >= $totalPaginas ? "disabled" : "") .
            '">';
        echo '<a class="page-link" href="#" data-pagina="' .
            ($paginaActual + 1) .
            '">Siguiente</a>';
        echo "</li>";
        echo "</ul>";
    }
    $pagHtml = ob_get_clean();

    header("Content-Type: application/json");
    echo json_encode([
        "tbody" => $tbodyHtml,
        "paginacion" => $pagHtml,
    ]);
    exit();
}
?>

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventario - EcoFauna</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

    <link
        href="https://fonts.googleapis.com/css2?family=DM+Serif+Display&family=Poppins:wght@400;500;600;700&display=swap"
        rel="stylesheet">

    <link rel="stylesheet" href="css/styleEmpleado.css">
    <style>.usuario-info a,
a.usuario-info {
    display: flex;
    align-items: center;
    gap: 12px;
    text-decoration: none;
    color: #fff;
    padding: 8px 14px;
    border-radius: 12px;
    transition: all .25s ease;
}

.usuario-info a:hover,
a.usuario-info:hover {
    background: rgba(255,255,255,.12);
    color: #fff;
    text-decoration: none;
}

.usuario-info a div,
a.usuario-info div {
    display: flex;
    flex-direction: column;
    line-height: 1.2;
}

.usuario-info a small,
a.usuario-info small {
    color: rgba(255,255,255,.75);
    font-size: .75rem;
}

.usuario-info a strong,
a.usuario-info strong {
    color: #fff;
    font-size: .95rem;
    font-weight: 600;
}</style>
    <link rel="stylesheet" href="css/crud-modern.css?v=1">
    <link rel="stylesheet" href="css/ecofauna-unified.css?v=1">
</head>

<body class="ecofauna-unified crud-modern">



    <nav class="navbar navbar-expand-lg navbar-ecofauna shadow">

        <div class="container-fluid">

            <a class="navbar-brand d-flex align-items-center" href="empleado.php">

                <img src="img/LogoEcoFauna1.png" class="logo-navbar" alt="Logo EcoFauna">

                <div class="ms-3">
                    <strong>EcoFauna</strong><br>
                    <small>Inventario</small>
                </div>

            </a>

            <div class="navbar-usuario">

                 <div class="usuario-info">
                   
                 <a href="mi_perfil.php" class="usuario-info text-decoration-none">
                    <span class="usuario-avatar">
                        <i class="bi bi-person-fill"></i>
                    </span>
                    <div>
                        <strong><?= htmlspecialchars(
                                    $_SESSION["usuario"],
                                    ENT_QUOTES,
                                    "UTF-8",
                                ) ?></strong>

                    </div>
                </a>
                </div>


                <a href="empleado.php" class="btn btn-outline-light btn-sm ms-3">
                    <i class="bi bi-arrow-left"></i> Volver
                </a>

                <form action="logout.php" method="POST" class="m-0">
                    <?= campoCsrfSesion("logout") ?>

                    <button type="submit" class="btn btn-salir">
                        <i class="bi bi-box-arrow-right"></i>
                        Cerrar sesión
                    </button>
                </form>

            </div>

        </div>

    </nav>

    <main class="container mt-4">

        <!-- AGREGAR INVENTARIO -->

        <section class="card card-shadow mb-4">

            <div class="card-header bg-primary text-white">

                <h5 class="mb-0">

                    <i class="bi bi-plus-circle-fill me-2"></i>

                    Agregar Inventario

                </h5>

            </div>

            <div class="card-body">

                <?php if (!empty($mensaje)): ?>
                    <div class="alert alert-<?= $tipoMensaje ?> alert-dismissible fade show mb-4" role="alert">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i>
                        <strong><?= htmlspecialchars(
                                    $mensaje,
                                    ENT_QUOTES,
                                    "UTF-8",
                                ) ?></strong>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Cerrar"></button>
                    </div>
                <?php endif; ?>

                <form action="inventario.php" method="POST">

                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= $csrfInventarioEscapado ?>">

                    <input type="hidden" name="accion" value="guardar">

                    <div class="row g-3">

                        <div class="col-md-4">

                            <label class="form-label fw-bold">

                                Producto

                            </label>

                            <select name="id_producto" class="form-select" required>

                                <option value="">
                                    Seleccione un producto
                                </option>

                                <?php foreach (
                                    $productosArray
                                    as $producto
                                ): ?>

                                    <option value="<?= $producto["id_producto"] ?>">

                                        <?= htmlspecialchars(
                                            $producto["nombre_producto"],
                                        ) ?>

                                    </option>

                                <?php endforeach; ?>

                            </select>

                        </div>

                        <div class="col-md-2">

                            <label class="form-label fw-bold">

                                Unidad

                            </label>

                            <select name="unidad_medida" class="form-select" required>

                                <option value="">Seleccione una unidad</option>

                                <option value="Kg">Kilogramos (Kg)</option>

                                <option value="Gramos">Gramos (g)</option>

                                <option value="Litros">Litros (L)</option>

                                <option value="Mililitros">Mililitros (mL)</option>

                                <option value="Unidades">Unidades</option>

                                <option value="Cajas">Cajas</option>

                                <option value="Bolsas">Bolsas</option>

                                <option value="Frascos">Frascos</option>

                                <option value="Tabletas">Tabletas</option>

                                <option value="Ampollas">Ampollas</option>

                            </select>

                        </div>

                        <div class="col-md-2">

                            <label class="form-label fw-bold">

                                Cantidad

                            </label>

                            <input type="number" name="cantidad" class="form-control" min="0.01" max="99999999.99" step="0.01"
                                required>

                        </div>

                        <div class="col-md-2">

                            <label class="form-label fw-bold">

                                Fecha ingreso

                            </label>

                            <input type="date" name="fecha_ingreso" class="form-control"
                                value="<?= date("Y-m-d") ?>" max="<?= date(
                                                                        "Y-m-d",
                                                                    ) ?>" required>

                        </div>

                        <div class="col-md-2">

                            <label class="form-label fw-bold">

                                Caducidad

                            </label>

                            <input type="date" name="fecha_caducidad" class="form-control" min="<?= date(
                                                                                                    "Y-m-d",
                                                                                                ) ?>">

                        </div>

                    </div>

                    <div class="mt-4">

                        <button type="submit" class="btn btn-success">

                            <i class="bi bi-save me-2"></i>

                            Guardar

                        </button>

                        <a href="empleado.php" class="btn btn-secondary">

                            <i class="bi bi-arrow-left me-2"></i>

                            Volver

                        </a>

                    </div>

                </form>

            </div>

        </section>

        <!-- INVENTARIO -->

        <section class="card card-shadow" id="seccion-inventario">

            <div class="card-header bg-primary text-white">

                <h5 class="mb-0">

                    <i class="bi bi-box-seam-fill me-2"></i>

                    Inventario del Zoológico

                </h5>

            </div>

            <div class="card-body">

                <div class="table-responsive">

                    <table class="table table-hover table-bordered align-middle">

                        <thead class="table-dark">

                            <tr>

                                <th>ID</th>
                                <th>Producto</th>
                                <th>Unidad</th>
                                <th>Cantidad</th>
                                <th>Ingreso</th>
                                <th>Caducidad</th>
                                <th>Días</th>
                                <th>Estado</th>
                                <th>Acciones</th>

                            </tr>

                        </thead>

                        <tbody id="tbody-inventario">

                            <?php if (!$resultadoInventario): ?>

                                <tr>
                                    <td colspan="9" class="text-center text-danger">
                                        No fue posible cargar el inventario.
                                    </td>
                                </tr>

                            <?php elseif (
                                mysqli_num_rows($resultadoInventario) === 0
                            ): ?>

                                <tr>
                                    <td colspan="9" class="text-center text-muted">
                                        No hay productos registrados.
                                    </td>
                                </tr>

                            <?php else: ?>

                                <?php $hoy = new DateTimeImmutable("today"); ?>

                                <?php while (
                                    $producto = mysqli_fetch_assoc(
                                        $resultadoInventario,
                                    )
                                ): ?>

                                    <?php
                                    $fechaCaducidad =
                                        $producto["fecha_caducidad"];

                                    $estado = "SIN CADUCIDAD";
                                    $dias = "N/A";
                                    $clase = "";

                                    if (
                                        !empty($fechaCaducidad) &&
                                        $fechaCaducidad != "0000-00-00"
                                    ) {
                                        $fechaCad = new DateTimeImmutable(
                                            $fechaCaducidad,
                                        );

                                        $diferencia = (int) $hoy
                                            ->diff($fechaCad)
                                            ->format("%r%a");

                                        $dias = $diferencia;

                                        if ($diferencia < 0) {
                                            $estado = "VENCIDO";
                                            $clase = "table-danger";
                                        } elseif ($diferencia <= 30) {
                                            $estado = "PRÓXIMO A VENCER";
                                            $clase = "table-warning";
                                        } else {
                                            $estado = "DISPONIBLE";
                                            $clase = "table-success";
                                        }
                                    }
                                    ?>

                                    <tr class="<?= $clase ?>">

                                        <td><?= (int) $producto["id_inventario_zoo"] ?></td>

                                        <td>
                                            <strong>
                                                <?= htmlspecialchars(
                                                    $producto["nombre_producto"],
                                                    ENT_QUOTES,
                                                    "UTF-8",
                                                ) ?>
                                            </strong>
                                        </td>

                                        <td>
                                            <?= htmlspecialchars(
                                                $producto["unidad_medida"] ??
                                                    "N/A",
                                                ENT_QUOTES,
                                                "UTF-8",
                                            ) ?>
                                        </td>

                                        <td>
                                            <?= htmlspecialchars(
                                                (string) $producto["cantidad"],
                                                ENT_QUOTES,
                                                "UTF-8",
                                            ) ?>
                                        </td>

                                        <td>
                                            <?= !empty($producto["fecha_ingreso"])
                                                ? date(
                                                    "d/m/Y",
                                                    strtotime(
                                                        $producto["fecha_ingreso"],
                                                    ),
                                                )
                                                : "N/A" ?>
                                        </td>

                                        <td>
                                            <?= !empty($fechaCaducidad)
                                                ? date(
                                                    "d/m/Y",
                                                    strtotime($fechaCaducidad),
                                                )
                                                : "N/A" ?>
                                        </td>

                                        <td>
                                            <?= htmlspecialchars(
                                                (string) $dias,
                                                ENT_QUOTES,
                                                "UTF-8",
                                            ) ?>
                                        </td>

                                        <td>

                                            <?php if ($estado == "VENCIDO") {
                                                echo '<span class="badge bg-danger">VENCIDO</span>';
                                            } elseif (
                                                $estado == "PRÓXIMO A VENCER"
                                            ) {
                                                echo '<span class="badge bg-warning text-dark">PRÓXIMO A VENCER</span>';
                                            } elseif ($estado == "DISPONIBLE") {
                                                echo '<span class="badge bg-success">DISPONIBLE</span>';
                                            } else {
                                                echo '<span class="badge bg-secondary">SIN CADUCIDAD</span>';
                                            } ?>

                                        </td>
                                        <td>
                                            <div class="d-flex gap-1 justify-content-center">
                                                <button type="button" class="btn btn-warning btn-sm btn-editar-inventario"
                                                    data-id="<?= (int) $producto["id_inventario_zoo"] ?>"
                                                    data-nombre="<?= htmlspecialchars(
                                                                        $producto["nombre_producto"],
                                                                        ENT_QUOTES,
                                                                        "UTF-8",
                                                                    ) ?>"
                                                    data-unidad="<?= htmlspecialchars(
                                                                        $producto["unidad_medida"] ?? "",
                                                                        ENT_QUOTES,
                                                                        "UTF-8",
                                                                    ) ?>"
                                                    data-cantidad="<?= (float) $producto["cantidad"] ?>"
                                                    data-ingreso="<?= htmlspecialchars(
                                                                        $producto["fecha_ingreso"],
                                                                        ENT_QUOTES,
                                                                        "UTF-8",
                                                                    ) ?>"
                                                    data-caducidad="<?= htmlspecialchars(
                                                                        $producto["fecha_caducidad"] ?? "",
                                                                        ENT_QUOTES,
                                                                        "UTF-8",
                                                                    ) ?>">
                                                    <i class="bi bi-pencil-square"></i>
                                                </button>
                                                <form action="inventario.php" method="POST" class="d-inline form-eliminar-lote">
                                                    <input type="hidden" name="csrf_token" value="<?= $csrfInventarioEscapado ?>">
                                                    <input type="hidden" name="accion" value="eliminar">
                                                    <input type="hidden" name="id_inventario_zoo" value="<?= (int) $producto["id_inventario_zoo"] ?>">
                                                    <button type="submit" class="btn btn-danger btn-sm" title="Ocultar lote" aria-label="Ocultar lote">
                                                        <i class="bi bi-eye-slash"></i>
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>

                                <?php endwhile; ?>

                            <?php endif; ?>

                        </tbody>

                    </table>

                    <!-- Paginación de Inventario -->
                    <nav aria-label="Paginacion inventario" class="mt-3" id="paginacion-inventario">
                        <?php if ($totalPaginas > 1): ?>
                            <ul class="pagination justify-content-center">
                                <li class="page-item <?= $paginaActual <= 1
                                                            ? "disabled"
                                                            : "" ?>">
                                    <a class="page-link" href="#" data-pagina="<?= $paginaActual -
                                                                                    1 ?>">Anterior</a>
                                </li>
                                <?php for (
                                    $i = 1;
                                    $i <= $totalPaginas;
                                    $i++
                                ): ?>
                                    <li class="page-item <?= $paginaActual ===
                                                                $i
                                                                ? "active"
                                                                : "" ?>">
                                        <a class="page-link" href="#" data-pagina="<?= $i ?>"><?= $i ?></a>
                                    </li>
                                <?php endfor; ?>
                                <li class="page-item <?= $paginaActual >=
                                                            $totalPaginas
                                                            ? "disabled"
                                                            : "" ?>">
                                    <a class="page-link" href="#" data-pagina="<?= $paginaActual +
                                                                                    1 ?>">Siguiente</a>
                                </li>
                            </ul>
                        <?php endif; ?>
                    </nav>

                </div>

            </div>

        </section>

    </main>

    <!-- Modal Editar Inventario -->
    <div class="modal fade" id="modalEditarInventario" tabindex="-1" aria-labelledby="modalEditarInventarioLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form action="inventario.php" method="POST">
                    <input type="hidden" name="csrf_token" value="<?= $csrfInventarioEscapado ?>">
                    <input type="hidden" name="accion" value="editar">
                    <div class="modal-header">
                        <h5 class="modal-title" id="modalEditarInventarioLabel">Editar Lote de Inventario</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="id_inventario_zoo" id="editIdInventario">

                        <div class="mb-3">
                            <label class="form-label">Producto</label>
                            <input type="text" class="form-control" id="editNombreProducto" disabled>
                        </div>

                        <div class="mb-3">
                            <label class="form-label" for="editUnidadMedida">Unidad de Medida</label>
                            <input type="text" name="unidad_medida" class="form-control" id="editUnidadMedida" maxlength="30" required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label" for="editCantidad">Cantidad</label>
                            <input type="number" step="0.01" min="0.01" max="99999999.99" name="cantidad" class="form-control" id="editCantidad" required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label" for="editFechaIngreso">Fecha de Ingreso</label>
                            <input type="date" name="fecha_ingreso" class="form-control" id="editFechaIngreso" max="<?= date(
                                                                                                                        "Y-m-d",
                                                                                                                    ) ?>" required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label" for="editFechaCaducidad">Fecha de Caducidad</label>
                            <input type="date" name="fecha_caducidad" class="form-control" id="editFechaCaducidad" min="<?= date(
                                                                                                                            "Y-m-d",
                                                                                                                        ) ?>">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary">Guardar Cambios</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="js/inventario.js"></script>
</body>

</html>
