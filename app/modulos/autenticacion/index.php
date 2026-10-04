<?php

require_once __DIR__ . "/../../soporte/sesion.php";

if (isset($_SESSION["usuario"], $_SESSION["rol"], $_SESSION["id_login"])) {
    $destino = match ($_SESSION["rol"]) {
        "Administrador" => "admin.php",
        "Empleado" => "empleado.php",
        "Veterinario" => "veterinario.php",
        "Cliente" => "cliente.php",
        default => null,
    };

    if ($destino !== null) {
        header("Location: " . $destino);
        exit();
    }
}

$error = $_GET["error"] ?? null;
$intentos = isset($_GET["intentos"]) ? (int) $_GET["intentos"] : null;
$tiempo = isset($_GET["tiempo"]) ? (int) $_GET["tiempo"] : null;

$mensajes = [
    "campos" => "Debes ingresar el usuario y la contraseña.",
    "credenciales" => "Usuario o contraseña incorrectos.",
    "rol" => "El usuario no tiene un rol válido.",
    "sistema" => "Ocurrió un problema interno. Inténtalo nuevamente.",
    "bloqueado" => "Tu cuenta ha sido bloqueada temporalmente.",
    "solicitud" => "La solicitud expiró. Actualiza la página e inténtalo nuevamente.",
];

if ($error === "credenciales" && $intentos !== null) {
    $mensajes["credenciales"] = sprintf(
        "Usuario o contraseña incorrectos. Te quedan %d %s.",
        $intentos,
        $intentos === 1 ? "intento" : "intentos",
    );
}

if ($error === "bloqueado" && $tiempo !== null) {
    $mensajes["bloqueado"] = sprintf(
        "Tu cuenta está bloqueada. Inténtalo nuevamente en %d segundos.",
        $tiempo,
    );
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Centro de Control de EcoFauna</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=Emilys+Candy&family=Lora:ital,wght@0,400..700;1,400..700&family=PT+Serif+Caption:ital@0;1&family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/stylelogin.css">
    <link rel="stylesheet" href="css/ecofauna-unified.css?v=1">
</head>

<body class="ecofauna-unified">
    <main class="container-fluid">
        <div class="row vh-100">
            <section class="col-lg-7 d-none d-lg-flex izquierda">
                <div class="overlay">
                    <h1>
                        Centro de Control de<br>
                        <span>EcoFauna</span>
                    </h1>

                    <p class="descripcion">
                        Conoce más sobre la vida silvestre a través de nuestra
                        página de gestión y cuidado animal.
                    </p>

                    <div class="info">
                        <article class="info-item">
                            <i class="bi bi-tree-fill"></i>
                            <div>
                                <h5>Gestión de Animales</h5>
                                <small>Control completo de especies y hábitats.</small>
                            </div>
                        </article>

                        <article class="info-item">
                            <i class="bi bi-box-seam"></i>
                            <div>
                                <h5>Inventario</h5>
                                <small>Alimentos, medicamentos e insumos.</small>
                            </div>
                        </article>

                        <article class="info-item">
                            <i class="bi bi-heart-pulse-fill"></i>
                            <div>
                                <h5>Historial Médico</h5>
                                <small>Seguimiento veterinario de cada animal.</small>
                            </div>
                        </article>

                        <article class="info-item">
                            <i class="bi bi-people-fill"></i>
                            <div>
                                <h5>Visitantes</h5>
                                <small>Entradas, reservas y control de acceso.</small>
                            </div>
                        </article>
                    </div>
                </div>
            </section>

            <section class="col-lg-5 d-flex justify-content-center align-items-center fondo-login">
                <div class="login-card">
                    <div class="text-center">
                        <div class="logo-login">
                            <img src="img/LogoEcoFauna1.png" alt="Logo de EcoFauna">
                        </div>

                        <h2>Bienvenido</h2>
                        <p>Inicia sesión para acceder al sistema.</p>
                    </div>

                    <?php if ($error && isset($mensajes[$error])): ?>
                        <div class="alert alert-danger" role="alert">
                            <?= htmlspecialchars(
                                $mensajes[$error],
                                ENT_QUOTES,
                                "UTF-8",
                            ) ?>
                        </div>
                    <?php endif; ?>

                    <form action="login.php" method="POST">
                        <?= campoCsrfSesion("login") ?>
                        <div class="mb-3">
                            <label class="form-label" for="usuario">Usuario</label>

                            <div class="input-group">
                                <span class="input-group-text">
                                    <i class="bi bi-person-fill"></i>
                                </span>
                                <input
                                    id="usuario"
                                    type="text"
                                    class="form-control"
                                    name="usuario"
                                    maxlength="50"
                                    autocomplete="username"
                                    placeholder="Ingrese su usuario"
                                    required>
                            </div>
                        </div>

                        <div class="mb-4">
                            <label class="form-label" for="contrasena">Contraseña</label>

                            <div class="input-group">
                                <span class="input-group-text">
                                    <i class="bi bi-lock-fill"></i>
                                </span>
                                <input
                                    id="contrasena"
                                    type="password"
                                    class="form-control"
                                    name="contrasena"
                                    maxlength="255"
                                    autocomplete="current-password"
                                    placeholder="Ingrese su contraseña"
                                    required>
                            </div>
                        </div>

                        <button type="submit" class="btn btn-login w-100 mb-3">
                            <i class="bi bi-box-arrow-in-right me-2"></i>
                            Iniciar sesión
                        </button>

                        <div class="text-center mb-3">
                            <a href="recuperar.php" class="text-decoration-none text-muted small">
                                <i class="bi bi-key-fill"></i>
                                ¿Olvidaste tu contraseña?
                            </a>
                        </div>

                        <p class="lema-login">Conservar • Educar • Proteger</p>
                    </form>
                </div>
            </section>
        </div>
    </main>
</body>

</html>