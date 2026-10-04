<?php
/* =====================================================
   INICIO DE SESIÓN Y CONEXIÓN
===================================================== */
require_once __DIR__ . "/../../soporte/sesion.php";
require_once __DIR__ . "/../../../config/database.php";

/** @var mysqli $conexion */
function escapar(string $texto): string
{
    return htmlspecialchars($texto, ENT_QUOTES, "UTF-8");
}

if (
    !isset($_SESSION["usuario"], $_SESSION["rol"], $_SESSION["id_login"])
) {
    header("Location: index.php");
    exit();
}

$nombreUsuario = escapar($_SESSION["usuario"]);
$rolUsuario = (string) $_SESSION["rol"];
$idUsuario = $_SESSION["id_usuario"] ?? null;

// Definir a qué página volver según el rol
$urlPortal = match ($rolUsuario) {
    "Administrador" => "admin.php",
    "Empleado" => "empleado.php",
    "Veterinario" => "veterinario.php",
    "Cliente" => "cliente.php",
    default => "index.php",
};
$nombrePortal = $rolUsuario === "Cliente"
    ? "Portal del visitante"
    : "Portal del personal";
$tipoCuenta = $rolUsuario === "Cliente"
    ? "Visitante de EcoFauna"
    : "Personal de EcoFauna";
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mi Perfil - EcoFauna</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display&family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/styleCliente.css?v=2">
    <link rel="stylesheet" href="css/ecofauna-unified.css?v=1">
</head>

<body class="ecofauna-unified">
<nav class="navbar navbar-cliente">
    <div class="container-fluid px-4">
       <a class="navbar-brand" href="<?= $urlPortal ?>">
            <img src="img/LogoEcoFauna1.png" class="logo-navbar-cliente" alt="Logo EcoFauna">
            <span>
                EcoFauna
                <small><?= escapar($nombrePortal) ?></small>
            </span>
        </a>
        <div class="usuario-nav">
            <div class="usuario-info text-decoration-none active-perfil">
                <span class="usuario-avatar">
                    <i class="bi bi-person-fill"></i>
                </span>
                <div>
                    <small><?= escapar($rolUsuario) ?></small>
                    <strong><?= $nombreUsuario ?></strong>
                </div>
            </div>
            <form action="logout.php" method="POST" class="d-inline">
                <?= campoCsrfSesion("logout") ?>
                <button type="submit" class="btn btn-salir">
                    <i class="bi bi-box-arrow-right"></i>
                    Cerrar sesión
                </button>
            </form>
        </div>
    </div>
</nav>

<header class="hero-cliente" style="padding: 2.5rem 0;">
    <div class="container hero-contenido">
        <div class="hero-texto">
            <span class="hero-etiqueta">
                <i class="bi bi-person-badge-fill"></i>
                Gestión de cuenta
            </span>
            <h1>Mi Perfil</h1>
            <p>Administra la seguridad de tu cuenta y tus datos personales.</p>
        </div>
    </div>
</header>

<main class="container contenido-principal my-5">
    <div class="row g-4">
        
        <!-- Tarjeta de Información Básica -->
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm p-4 text-center h-100" style="border-radius: 1rem;">
                <div class="usuario-avatar mx-auto mb-3" style="width: 80px; height: 80px; font-size: 2.5rem; display: flex; align-items: center; justify-content: center; background: rgba(40, 116, 91, 0.1); color: #28745b; border-radius: 50%;">
                    <i class="bi bi-person-fill"></i>
                </div>
                <h3 class="h5 mb-1"><strong><?= $nombreUsuario ?></strong></h3>
                <p class="text-muted small mb-3"><?= escapar($rolUsuario) ?> Registrado</p>
                <hr class="text-muted opacity-25">
                <div class="text-start small text-secondary">
                    <p class="mb-2"><i class="bi bi-shield-check text-success me-2"></i>Cuenta verificada</p>
                    <p class="mb-0"><i class="bi bi-calendar-event me-2"></i><?= escapar($tipoCuenta) ?></p>
                </div>
            </div>
        </div>

        <!-- Opciones y Acciones de Configuración -->
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm p-4 h-100" style="border-radius: 1rem;">
                <h3 class="h4 mb-4" style="color: #28745b; font-family: 'DM Serif Display', serif;">Ajustes de la cuenta</h3>
                
                <div class="d-grid gap-3">
                    <!-- Botón Restablecer Contraseña -->
                    <div class="p-3 border rounded-3 d-flex align-items-center justify-content-between bg-light">
                        <div class="d-flex align-items-center gap-3">
                            <div class="fs-4 text-success"><i class="bi bi-key-fill"></i></div>
                            <div>
                                <h6 class="mb-0 fw-bold">Contraseña y Seguridad</h6>
                                <small class="text-muted">Actualiza tu contraseña de acceso regularmente.</small>
                            </div>
                        </div>
                        <a href="usuario/cambiar_contrasena.php" class="btn btn-outline-success btn-sm px-3">
                            Cambiar
                        </a>
                    </div>

                    <!-- Botón Registrar Método de Pago (Solo se muestra si es Cliente) -->
                    <?php if ($rolUsuario === "Cliente"): ?>
                    <div class="p-3 border rounded-3 d-flex align-items-center justify-content-between bg-light">
                        <div class="d-flex align-items-center gap-3">
                            <div class="fs-4 text-success"><i class="bi bi-credit-card-2-front-fill"></i></div>
                            <div>
                                <h6 class="mb-0 fw-bold">Métodos de Pago</h6>
                                <small class="text-muted">Administra tus tarjetas y formas de pago guardadas.</small>
                            </div>
                        </div>
                        <a href="tienda/mis_tarjetas.php" class="btn btn-outline-success btn-sm px-3">
                            Gestionar
                        </a>
                    </div>
                    <?php endif; ?>

                    <!-- Botón Editar Datos Personales -->
                    <div class="p-3 border rounded-3 d-flex align-items-center justify-content-between bg-light">
                        <div class="d-flex align-items-center gap-3">
                            <div class="fs-4 text-success"><i class="bi bi-pencil-square"></i></div>
                            <div>
                                <h6 class="mb-0 fw-bold">Datos Personales</h6>
                                <small class="text-muted">Modifica tu nombre, correo o número de contacto.</small>
                            </div>
                            <?php
$_SESSION["volver_direccion"] = $_SERVER["REQUEST_URI"];
?>
                        </div>
                        <a href="usuario/direccion.php" class="btn btn-outline-success btn-sm px-3">
                            Editar
                        </a>
                    </div>
                </div>

                <div class="mt-4 text-end">
                    <a href="<?= $urlPortal ?>" class="btn btn-secondary px-4">
                        <i class="bi bi-arrow-left me-2"></i>Volver al Portal
                    </a>
                </div>
            </div>
        </div>

    </div>
</main>

<footer class="footer-cliente mt-5">
    <div class="container">
        <span>
            <i class="bi bi-tree-fill"></i>
            EcoFauna — <?= escapar($nombrePortal) ?>
        </span>
        <small>
            Conservar • Educar • Proteger
        </small>
    </div>
</footer>
</body>
</html>