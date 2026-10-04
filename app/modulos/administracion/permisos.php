<?php

require_once __DIR__ . "/../../soporte/sesion.php";
require_once __DIR__ . "/../../../config/database.php";

/** @var mysqli $conexion */

if (
    !isset($_SESSION["usuario"], $_SESSION["rol"], $_SESSION["id_login"]) ||
    $_SESSION["rol"] !== "Administrador"
) {
    header("Location: ../index.php");
    exit();
}

function escaparPermisos(mixed $valor): string
{
    return htmlspecialchars((string) $valor, ENT_QUOTES, "UTF-8");
}

function redirigirPermisos(
    int $idRol,
    string $mensaje,
    string $tipo = "success",
): void {
    $_SESSION["mensaje_permisos_admin"] = $mensaje;
    $_SESSION["tipo_permisos_admin"] = $tipo;
    header("Location: permisos.php?rol=" . $idRol);
    exit();
}

$mensaje = $_SESSION["mensaje_permisos_admin"] ?? "";
$tipoMensaje = $_SESSION["tipo_permisos_admin"] ?? "success";
unset($_SESSION["mensaje_permisos_admin"], $_SESSION["tipo_permisos_admin"]);
$error = "";

$roles = [];
$resultadoRoles = mysqli_query(
    $conexion,
    "SELECT
        r.id_rol,
        r.nombre_rol,
        r.descripcion,
        COUNT(ap.id_asignarpermisos) AS total_permisos
     FROM rol r
     LEFT JOIN asignar_permisos ap ON ap.id_rol = r.id_rol
     GROUP BY r.id_rol, r.nombre_rol, r.descripcion
     ORDER BY r.id_rol",
);

if ($resultadoRoles) {
    while ($rolFila = mysqli_fetch_assoc($resultadoRoles)) {
        $roles[] = $rolFila;
    }
}

$permisos = [];
$resultadoPermisos = mysqli_query(
    $conexion,
    "SELECT id_permisos, nombre_permiso, descripcion
     FROM permisos
     ORDER BY nombre_permiso",
);

if ($resultadoPermisos) {
    while ($permisoFila = mysqli_fetch_assoc($resultadoPermisos)) {
        $permisos[] = $permisoFila;
    }
}

$rolesPorId = [];
foreach ($roles as $rolFila) {
    $rolesPorId[(int) $rolFila["id_rol"]] = $rolFila;
}

$permisosPorId = [];
foreach ($permisos as $permisoFila) {
    $permisosPorId[(int) $permisoFila["id_permisos"]] = $permisoFila;
}

$idRolSeleccionado = filter_input(INPUT_GET, "rol", FILTER_VALIDATE_INT) ?: 0;

if ($idRolSeleccionado <= 0 && $roles) {
    $primerRolEditable = array_values(
        array_filter(
            $roles,
            static fn(array $rol): bool => $rol["nombre_rol"] !== "Administrador",
        ),
    );
    $idRolSeleccionado = (int) (($primerRolEditable[0] ?? $roles[0])["id_rol"]);
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $idRolSeleccionado = filter_input(
        INPUT_POST,
        "id_rol",
        FILTER_VALIDATE_INT,
    ) ?: 0;

    if (
        !tokenCsrfSesionValido(
            "administracion_permisos",
            $_POST["csrf_token"] ?? null,
        )
    ) {
        http_response_code(403);
        $error = "La solicitud expiró. Recarga la página e inténtalo nuevamente.";
    } elseif (!isset($rolesPorId[$idRolSeleccionado])) {
        $error = "El rol seleccionado no existe.";
    } elseif ($rolesPorId[$idRolSeleccionado]["nombre_rol"] === "Administrador") {
        $error = "El rol Administrador siempre conserva acceso completo.";
    } else {
        $seleccionados = $_POST["permisos"] ?? [];
        $idsSeleccionados = [];

        if (is_array($seleccionados)) {
            foreach ($seleccionados as $idPermiso) {
                $id = filter_var($idPermiso, FILTER_VALIDATE_INT);

                if ($id !== false && isset($permisosPorId[(int) $id])) {
                    $idsSeleccionados[(int) $id] = (int) $id;
                }
            }
        }

        $idsSeleccionados = array_values($idsSeleccionados);

        if (!$idsSeleccionados) {
            $error = "Selecciona al menos un permiso para guardar la configuración del rol.";
        } else {
            $enTransaccion = false;

            try {
                mysqli_begin_transaction($conexion);
                $enTransaccion = true;

                $stmtEliminar = mysqli_prepare(
                    $conexion,
                    "DELETE FROM asignar_permisos WHERE id_rol = ?",
                );

                if (!$stmtEliminar) {
                    throw new RuntimeException("No fue posible preparar la actualización de permisos.");
                }

                mysqli_stmt_bind_param($stmtEliminar, "i", $idRolSeleccionado);

                if (!mysqli_stmt_execute($stmtEliminar)) {
                    throw new RuntimeException(mysqli_stmt_error($stmtEliminar));
                }
                mysqli_stmt_close($stmtEliminar);

                $stmtInsertar = mysqli_prepare(
                    $conexion,
                    "INSERT INTO asignar_permisos (id_rol, id_permisos)
                     VALUES (?, ?)",
                );

                if (!$stmtInsertar) {
                    throw new RuntimeException("No fue posible preparar las nuevas asignaciones.");
                }

                foreach ($idsSeleccionados as $idPermiso) {
                    mysqli_stmt_bind_param(
                        $stmtInsertar,
                        "ii",
                        $idRolSeleccionado,
                        $idPermiso,
                    );

                    if (!mysqli_stmt_execute($stmtInsertar)) {
                        throw new RuntimeException(mysqli_stmt_error($stmtInsertar));
                    }
                }
                mysqli_stmt_close($stmtInsertar);

                $nombreRol = $rolesPorId[$idRolSeleccionado]["nombre_rol"];
                $nombresPermisos = array_map(
                    static fn(int $id): string =>
                        (string) $permisosPorId[$id]["nombre_permiso"],
                    $idsSeleccionados,
                );
                $detalle = "Se asignaron al rol {$nombreRol} los permisos: " .
                    implode(", ", $nombresPermisos) . ".";

                if (
                    !registrarBitacora(
                        $_SESSION["usuario"],
                        "ACTUALIZAR PERMISOS",
                        "asignar_permisos",
                        $detalle,
                        $idRolSeleccionado,
                    )
                ) {
                    throw new RuntimeException("No fue posible registrar el cambio en la bitácora.");
                }

                mysqli_commit($conexion);
                renovarTokenCsrfSesion("administracion_permisos");
                redirigirPermisos(
                    $idRolSeleccionado,
                    "Permisos del rol actualizados correctamente.",
                );
            } catch (Throwable $errorPermisos) {
                if ($enTransaccion) {
                    mysqli_rollback($conexion);
                }

                error_log("Error actualizando permisos: " . $errorPermisos->getMessage());
                $error = $errorPermisos->getMessage();
            }
        }
    }
}

$rolSeleccionado = $rolesPorId[$idRolSeleccionado] ?? null;
$permisosAsignados = [];

if ($rolSeleccionado) {
    $stmtAsignados = mysqli_prepare(
        $conexion,
        "SELECT id_permisos
         FROM asignar_permisos
         WHERE id_rol = ?",
    );

    if ($stmtAsignados) {
        mysqli_stmt_bind_param($stmtAsignados, "i", $idRolSeleccionado);
        mysqli_stmt_execute($stmtAsignados);
        $resultadoAsignados = mysqli_stmt_get_result($stmtAsignados);

        while ($asignado = mysqli_fetch_assoc($resultadoAsignados)) {
            $permisosAsignados[] = (int) $asignado["id_permisos"];
        }
        mysqli_stmt_close($stmtAsignados);
    }
}

$esAdministrador = ($rolSeleccionado["nombre_rol"] ?? "") === "Administrador";

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Roles y permisos | EcoFauna</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display&family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="../css/administracion.css">
    <link rel="stylesheet" href="../css/crud-modern.css?v=1">
    <link rel="stylesheet" href="../css/ecofauna-unified.css?v=1">
</head>

<body class="ecofauna-unified crud-modern">

<nav class="navbar-admin-modulo">
    <div class="container barra-admin-modulo">
        <a href="../admin.php" class="marca-admin-modulo">
            <img src="../img/logoEcoFauna.png" alt="EcoFauna">
            <span><strong>EcoFauna</strong><small>Roles y permisos</small></span>
        </a>
        <div class="acciones-navbar">
            <a href="usuarios.php" class="btn-secundario-admin"><i class="bi bi-people-fill"></i> Usuarios</a>
            <a href="../admin.php" class="btn-volver-admin"><i class="bi bi-arrow-left"></i> Administración</a>
        </div>
    </div>
</nav>

<header class="hero-modulo-admin">
    <div class="container">
        <span class="etiqueta-modulo"><i class="bi bi-shield-lock-fill"></i> Seguridad por rol</span>
        <h1>Roles y permisos</h1>
        <p>Decide qué funciones puede utilizar cada tipo de usuario sin cambiar los nombres que necesita el login.</p>
    </div>
</header>

<main class="container contenido-modulo-admin">
    <?php if ($mensaje !== ""): ?>
        <div class="alert alert-<?= escaparPermisos($tipoMensaje) ?> alert-dismissible fade show rounded-4 shadow-sm" role="alert">
            <?= escaparPermisos($mensaje) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Cerrar"></button>
        </div>
    <?php endif; ?>

    <?php if ($error !== ""): ?>
        <div class="alert alert-danger rounded-4 shadow-sm" role="alert"><i class="bi bi-exclamation-triangle-fill me-2"></i><?= escaparPermisos($error) ?></div>
    <?php endif; ?>

    <div class="aviso-permisos">
        <i class="bi bi-info-circle-fill"></i>
        <div>
            <strong>Compatibilidad protegida</strong>
            <p>Mientras un rol no tenga permisos asignados, conserva sus accesos anteriores. Después de guardar, se aplicará la selección en los módulos protegidos.</p>
        </div>
    </div>

    <section class="roles-grid">
        <?php foreach ($roles as $rol): ?>
            <?php $activo = (int) $rol["id_rol"] === $idRolSeleccionado; ?>
            <a href="permisos.php?rol=<?= (int) $rol["id_rol"] ?>" class="rol-card <?= $activo ? "activo" : "" ?>">
                <span class="rol-icono"><i class="bi bi-person-badge-fill"></i></span>
                <div>
                    <strong><?= escaparPermisos($rol["nombre_rol"]) ?></strong>
                    <small><?= escaparPermisos($rol["descripcion"] ?: "Sin descripción") ?></small>
                </div>
                <span class="total-permisos">
                    <?= $rol["nombre_rol"] === "Administrador" ? count($permisos) : (int) $rol["total_permisos"] ?>
                </span>
            </a>
        <?php endforeach; ?>
    </section>

    <?php if ($rolSeleccionado): ?>
        <section class="tarjeta-modulo-admin">
            <div class="encabezado-tarjeta-modulo">
                <div>
                    <small>Configuración seleccionada</small>
                    <h2>Permisos de <?= escaparPermisos($rolSeleccionado["nombre_rol"]) ?></h2>
                </div>
                <span class="icono-encabezado"><i class="bi bi-key-fill"></i></span>
            </div>

            <?php if ($esAdministrador): ?>
                <div class="aviso-administrador">
                    <i class="bi bi-shield-fill-check"></i>
                    El Administrador mantiene acceso completo para evitar que el sistema quede sin una cuenta de control.
                </div>
            <?php endif; ?>

            <form method="POST" class="formulario-permisos">
                <?= campoCsrfSesion("administracion_permisos") ?>
                <input type="hidden" name="id_rol" value="<?= (int) $rolSeleccionado["id_rol"] ?>">

                <div class="permisos-grid">
                    <?php foreach ($permisos as $permiso): ?>
                        <?php
                        $idPermiso = (int) $permiso["id_permisos"];
                        $marcado = $esAdministrador || in_array($idPermiso, $permisosAsignados, true);
                        ?>
                        <label class="permiso-card <?= $marcado ? "seleccionado" : "" ?>">
                            <input
                                type="checkbox"
                                name="permisos[]"
                                value="<?= $idPermiso ?>"
                                <?= $marcado ? "checked" : "" ?>
                                <?= $esAdministrador ? "disabled" : "" ?>
                            >
                            <span class="check-visual"><i class="bi bi-check-lg"></i></span>
                            <span class="permiso-texto">
                                <strong><?= escaparPermisos($permiso["nombre_permiso"]) ?></strong>
                                <small><?= escaparPermisos($permiso["descripcion"] ?: "Permiso del sistema") ?></small>
                            </span>
                        </label>
                    <?php endforeach; ?>
                </div>

                <?php if (!$esAdministrador): ?>
                    <div class="acciones-formulario">
                        <a href="permisos.php?rol=<?= (int) $rolSeleccionado["id_rol"] ?>" class="btn-cancelar-admin">Restablecer selección</a>
                        <button type="submit" class="btn-guardar-admin"><i class="bi bi-shield-check"></i> Guardar permisos</button>
                    </div>
                <?php endif; ?>
            </form>
        </section>
    <?php else: ?>
        <div class="alert alert-warning rounded-4">No se encontró el rol seleccionado.</div>
    <?php endif; ?>
</main>

<footer class="footer-modulo-admin">
    <div class="container"><span><i class="bi bi-shield-lock-fill"></i> EcoFauna — Administración</span><small>Control de roles y permisos</small></div>
</footer>

<script>
document.querySelectorAll('.permiso-card input').forEach((input) => {
    input.addEventListener('change', () => {
        input.closest('.permiso-card').classList.toggle('seleccionado', input.checked);
    });
});
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
