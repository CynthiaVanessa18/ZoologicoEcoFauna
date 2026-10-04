<?php

require_once __DIR__ . "/../../soporte/sesion.php";
require_once __DIR__ . "/../../../config/database.php";
require_once __DIR__ . "/../../soporte/funciones_tienda.php";

/** @var mysqli $conexion */


/* =====================================================
   VALIDAR SESIÓN
===================================================== */

if (
    !isset(
        $_SESSION["usuario"],
        $_SESSION["rol"],
        $_SESSION["id_login"],
        $_SESSION["id_usuario"]
    )
) {
    header("Location: ../index.php");
    exit();
}


/* =====================================================
   DATOS DEL USUARIO
===================================================== */

$idUsuario = (int) $_SESSION["id_usuario"];
$nombreUsuario = (string) $_SESSION["usuario"];
$rolUsuario = (string) $_SESSION["rol"];


/* =====================================================
   PORTAL SEGÚN ROL
===================================================== */

$urlPortal = match ($rolUsuario) {

    "Administrador" => "../admin.php",

    "Empleado" => "../empleado.php",

    "Veterinario" => "../veterinario.php",

    "Cliente" => "../cliente.php",

    default => "../index.php",

};


/* =====================================================
   TOKEN CSRF
===================================================== */

$_SESSION["csrf_cambiar_contrasena"] ??=
    bin2hex(random_bytes(32));


/* =====================================================
   MENSAJES
===================================================== */

$error = "";
$mensaje = "";


/* =====================================================
   PROCESAR FORMULARIO
===================================================== */

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $transaccionIniciada = false;

    try {

        /* =================================================
           VALIDAR CSRF
        ================================================= */

        $token = $_POST["csrf_token"] ?? null;

        if (
            !is_string($token) ||
            !hash_equals(
                (string) $_SESSION["csrf_cambiar_contrasena"],
                $token
            )
        ) {

            throw new DomainException(
                "La sesión del formulario expiró. Actualiza la página e inténtalo nuevamente."
            );
        }


        /* =================================================
           RECIBIR DATOS
        ================================================= */

        $actual = trim(
            $_POST["actual"] ?? ""
        );

        $nueva = trim(
            $_POST["nueva"] ?? ""
        );

        $confirmar = trim(
            $_POST["confirmar"] ?? ""
        );


        /* =================================================
           VALIDAR CAMPOS VACÍOS
        ================================================= */

        if (
            $actual === "" ||
            $nueva === "" ||
            $confirmar === ""
        ) {

            $detalle = sprintf(
                "Intento fallido de cambio de contraseña del usuario '%s' (%s): se enviaron campos incompletos.",
                $nombreUsuario,
                $rolUsuario
            );

            registrarBitacora(
                $nombreUsuario,
                "CAMBIO CONTRASEÑA FALLIDO",
                "usuarios_rol",
                $detalle,
                $idUsuario
            );

            throw new DomainException(
                "Debe completar todos los campos."
            );
        }


        /* =================================================
           VALIDAR CONFIRMACIÓN
        ================================================= */

        if ($nueva !== $confirmar) {

            $detalle = sprintf(
                "Intento fallido de cambio de contraseña del usuario '%s' (%s): las nuevas contraseñas no coinciden.",
                $nombreUsuario,
                $rolUsuario
            );

            registrarBitacora(
                $nombreUsuario,
                "CAMBIO CONTRASEÑA FALLIDO",
                "usuarios_rol",
                $detalle,
                $idUsuario
            );

            throw new DomainException(
                "Las contraseñas nuevas no coinciden."
            );
        }


        /* =================================================
           VALIDAR LONGITUD
        ================================================= */

        if (strlen($nueva) < 8) {

            $detalle = sprintf(
                "Intento fallido de cambio de contraseña del usuario '%s' (%s): la nueva contraseña no cumple la longitud mínima requerida.",
                $nombreUsuario,
                $rolUsuario
            );

            registrarBitacora(
                $nombreUsuario,
                "CAMBIO CONTRASEÑA FALLIDO",
                "usuarios_rol",
                $detalle,
                $idUsuario
            );

            throw new DomainException(
                "La contraseña debe tener mínimo 8 caracteres."
            );
        }


        /* =================================================
           BUSCAR CONTRASEÑA ACTUAL
        ================================================= */

        $sql = "
            SELECT contrasena
            FROM usuarios_rol
            WHERE id_usuario = ?
            LIMIT 1
        ";

        $stmt = mysqli_prepare(
            $conexion,
            $sql
        );

        if (!$stmt) {

            throw new RuntimeException(
                mysqli_error($conexion)
            );
        }


        mysqli_stmt_bind_param(
            $stmt,
            "i",
            $idUsuario
        );


        if (!mysqli_stmt_execute($stmt)) {

            $errorSql = mysqli_stmt_error($stmt);

            mysqli_stmt_close($stmt);

            throw new RuntimeException(
                $errorSql
            );
        }


        $resultado = mysqli_stmt_get_result($stmt);

        $usuario = mysqli_fetch_assoc($resultado);

        mysqli_stmt_close($stmt);


        /* =================================================
           USUARIO NO ENCONTRADO
        ================================================= */

        if (!$usuario) {

            $detalle = sprintf(
                "No se pudo cambiar la contraseña del usuario '%s' (%s): no se encontró el registro de credenciales.",
                $nombreUsuario,
                $rolUsuario
            );

            registrarBitacora(
                $nombreUsuario,
                "CAMBIO CONTRASEÑA FALLIDO",
                "usuarios_rol",
                $detalle,
                $idUsuario
            );

            throw new DomainException(
                "No fue posible encontrar la cuenta del usuario."
            );
        }


        /* =================================================
           VERIFICAR CONTRASEÑA ACTUAL
        ================================================= */

        if (
            !password_verify(
                $actual,
                $usuario["contrasena"]
            )
        ) {

            $detalle = sprintf(
                "Intento fallido de cambio de contraseña del usuario '%s' (%s): la contraseña actual proporcionada no es correcta.",
                $nombreUsuario,
                $rolUsuario
            );

            registrarBitacora(
                $nombreUsuario,
                "CAMBIO CONTRASEÑA FALLIDO",
                "usuarios_rol",
                $detalle,
                $idUsuario
            );

            throw new DomainException(
                "La contraseña actual es incorrecta."
            );
        }


        /* =================================================
           EVITAR REUTILIZAR LA MISMA CONTRASEÑA
        ================================================= */

        if (
            password_verify(
                $nueva,
                $usuario["contrasena"]
            )
        ) {

            $detalle = sprintf(
                "Intento fallido de cambio de contraseña del usuario '%s' (%s): la nueva contraseña es igual a la contraseña actual.",
                $nombreUsuario,
                $rolUsuario
            );

            registrarBitacora(
                $nombreUsuario,
                "CAMBIO CONTRASEÑA FALLIDO",
                "usuarios_rol",
                $detalle,
                $idUsuario
            );

            throw new DomainException(
                "La nueva contraseña debe ser diferente de la actual."
            );
        }


        /* =================================================
           GENERAR HASH
        ================================================= */

        $nuevaHash = password_hash(
            $nueva,
            PASSWORD_DEFAULT
        );


        if ($nuevaHash === false) {

            throw new RuntimeException(
                "No fue posible generar la nueva contraseña."
            );
        }


        /* =================================================
           INICIAR TRANSACCIÓN
        ================================================= */

        if (!mysqli_begin_transaction($conexion)) {

            throw new RuntimeException(
                mysqli_error($conexion)
            );
        }

        $transaccionIniciada = true;


        /* =================================================
           ACTUALIZAR CONTRASEÑA
        ================================================= */

        $sql = "
            UPDATE usuarios_rol
            SET contrasena = ?
            WHERE id_usuario = ?
        ";

        $stmt = mysqli_prepare(
            $conexion,
            $sql
        );


        if (!$stmt) {

            throw new RuntimeException(
                mysqli_error($conexion)
            );
        }


        mysqli_stmt_bind_param(
            $stmt,
            "si",
            $nuevaHash,
            $idUsuario
        );


        if (!mysqli_stmt_execute($stmt)) {

            $errorSql = mysqli_stmt_error($stmt);

            mysqli_stmt_close($stmt);

            throw new RuntimeException(
                $errorSql
            );
        }


        mysqli_stmt_close($stmt);


        /* =================================================
           REGISTRAR BITÁCORA EXITOSA
           
           IMPORTANTE:
           NO se registra:
           - contraseña anterior
           - contraseña nueva
           - hash
           - CVC
           - ningún dato secreto
        ================================================= */

        $detalleBitacora = sprintf(
            "El usuario '%s' (%s) actualizó correctamente su contraseña desde el módulo de seguridad.",
            $nombreUsuario,
            $rolUsuario
        );


        if (
            !registrarBitacora(
                $nombreUsuario,
                "CAMBIAR CONTRASEÑA",
                "usuarios_rol",
                $detalleBitacora,
                $idUsuario
            )
        ) {

            throw new RuntimeException(
                "No fue posible registrar la acción en la bitácora."
            );
        }


        /* =================================================
           CONFIRMAR TRANSACCIÓN
        ================================================= */

        if (!mysqli_commit($conexion)) {

            throw new RuntimeException(
                mysqli_error($conexion)
            );
        }

        $transaccionIniciada = false;


        /* =================================================
           RENOVAR TOKEN
        ================================================= */

        $_SESSION["csrf_cambiar_contrasena"] =
            bin2hex(random_bytes(32));


        /* =================================================
           MENSAJE ÉXITO
        ================================================= */

        $mensaje =
            "Contraseña actualizada correctamente.";


    } catch (DomainException $e) {

        if ($transaccionIniciada) {
            mysqli_rollback($conexion);
        }

        $error = $e->getMessage();


    } catch (Throwable $e) {

        if ($transaccionIniciada) {
            mysqli_rollback($conexion);
        }

        error_log(
            "Error cambiando contraseña: " .
            $e->getMessage()
        );

        $error =
            "No fue posible actualizar la contraseña. Intenta nuevamente.";
    }
}

?>

<!DOCTYPE html>
<html lang="es">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        Actualizar contraseña
    </title>


    <!-- Bootstrap -->

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >


    <!-- Iconos -->

    <link
        rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css"
    >


    <!-- Fuentes -->

    <link
        href="https://fonts.googleapis.com/css2?family=DM+Serif+Display&family=Poppins:wght@400;500;600;700&display=swap"
        rel="stylesheet"
    >


    <!-- CSS -->

    <link
        rel="stylesheet"
        href="../css/styleCliente.css?v=2"
    >

    <link rel="stylesheet" href="../css/ecofauna-unified.css?v=1">
</head>


<body class="ecofauna-unified bg-light">


    <!-- =================================================
         NAVBAR
    ================================================= -->

    <nav class="navbar navbar-cliente">

        <div class="container-fluid px-4">


            <a
                class="navbar-brand"
                href="<?= htmlspecialchars(
                    $urlPortal,
                    ENT_QUOTES,
                    "UTF-8"
                ) ?>"
            >

                <img
                    src="../img/LogoEcoFauna1.png"
                    class="logo-navbar-cliente"
                    alt="EcoFauna"
                >

            </a>


            <div class="usuario-nav">


                <a
                    href="<?= htmlspecialchars(
                        $urlPortal,
                        ENT_QUOTES,
                        "UTF-8"
                    ) ?>"
                    class="btn btn-outline-light"
                >

                    <i class="bi bi-house-fill"></i>

                    Inicio

                </a>


                <a
                    href="../mi_perfil.php"
                    class="usuario-info text-decoration-none"
                >

                    <span class="usuario-avatar">

                        <i class="bi bi-person-fill"></i>

                    </span>


                    <div>

                        <small>

                            <?= htmlspecialchars(
                                $rolUsuario,
                                ENT_QUOTES,
                                "UTF-8"
                            ) ?>

                        </small>


                        <strong>

                            <?= htmlspecialchars(
                                $nombreUsuario,
                                ENT_QUOTES,
                                "UTF-8"
                            ) ?>

                        </strong>

                    </div>

                </a>


            </div>

        </div>

    </nav>


    <!-- =================================================
         HERO
    ================================================= -->

    <header class="hero-cliente">

        <div class="container hero-contenido">


            <div class="hero-texto">


                <span class="hero-etiqueta">

                    <i class="bi bi-shield-lock-fill"></i>

                    Seguridad

                </span>


                <h1>

                    Cambiar contraseña

                </h1>


                <p>

                    Actualiza tu contraseña para mantener
                    protegida tu cuenta de EcoFauna.

                </p>


            </div>


            <div class="hero-ilustracion">


                <div class="circulo-grande">

                    <i class="bi bi-key-fill"></i>

                </div>


                <span class="burbuja burbuja-uno">

                    <i class="bi bi-lock-fill"></i>

                </span>


                <span class="burbuja burbuja-dos">

                    <i class="bi bi-shield-check"></i>

                </span>


            </div>


        </div>

    </header>


    <!-- =================================================
         CONTENIDO
    ================================================= -->

    <main class="container contenido-principal">


        <div class="row justify-content-center">


            <div class="col-lg-6 col-md-8">


                <div class="card product-card shadow">


                    <div class="card-body p-5">


                        <h3 class="text-center mb-4">

                            <i class="bi bi-lock-fill text-success"></i>

                            Cambiar contraseña

                        </h3>


                        <!-- ERROR -->

                        <?php if ($error !== "") { ?>

                            <div
                                class="alert alert-danger"
                                role="alert"
                            >

                                <i class="bi bi-exclamation-triangle-fill me-2"></i>

                                <?= htmlspecialchars(
                                    $error,
                                    ENT_QUOTES,
                                    "UTF-8"
                                ) ?>

                            </div>

                        <?php } ?>


                        <!-- ÉXITO -->

                        <?php if ($mensaje !== "") { ?>

                            <div
                                class="alert alert-success"
                                role="alert"
                            >

                                <i class="bi bi-check-circle-fill me-2"></i>

                                <?= htmlspecialchars(
                                    $mensaje,
                                    ENT_QUOTES,
                                    "UTF-8"
                                ) ?>

                            </div>

                        <?php } ?>


                        <!-- FORMULARIO -->

                        <form method="POST">


                            <!-- CSRF -->

                            <input
                                type="hidden"
                                name="csrf_token"
                                value="<?= htmlspecialchars(
                                    $_SESSION["csrf_cambiar_contrasena"],
                                    ENT_QUOTES,
                                    "UTF-8"
                                ) ?>"
                            >


                            <!-- CONTRASEÑA ACTUAL -->

                            <div class="mb-3">

                                <label class="form-label">

                                    Contraseña actual

                                </label>


                                <input
                                    type="password"
                                    name="actual"
                                    class="form-control"
                                    autocomplete="current-password"
                                    required
                                >

                            </div>


                            <!-- NUEVA CONTRASEÑA -->

                            <div class="mb-3">

                                <label class="form-label">

                                    Nueva contraseña

                                </label>


                                <input
                                    type="password"
                                    name="nueva"
                                    class="form-control"
                                    minlength="8"
                                    autocomplete="new-password"
                                    required
                                >


                                <small class="text-muted">

                                    Mínimo 8 caracteres.

                                </small>

                            </div>


                            <!-- CONFIRMAR -->

                            <div class="mb-4">

                                <label class="form-label">

                                    Confirmar nueva contraseña

                                </label>


                                <input
                                    type="password"
                                    name="confirmar"
                                    class="form-control"
                                    minlength="8"
                                    autocomplete="new-password"
                                    required
                                >

                            </div>


                            <!-- BOTONES -->

                            <div class="d-grid gap-2">


                                <button
                                    type="submit"
                                    class="btn btn-success btn-lg"
                                >

                                    <i class="bi bi-check-circle-fill me-2"></i>

                                    Actualizar contraseña

                                </button>


                                <a
                                    href="../mi_perfil.php"
                                    class="btn btn-outline-success"
                                >

                                    <i class="bi bi-arrow-left me-2"></i>

                                    Volver al perfil

                                </a>


                            </div>


                        </form>


                    </div>

                </div>

            </div>

        </div>

    </main>


    <!-- =================================================
         FOOTER
    ================================================= -->

    <footer class="footer-cliente">

        <div class="container">


            <span>

                <i class="bi bi-tree-fill"></i>

                EcoFauna

            </span>


            <small>

                Conservar • Educar • Proteger

            </small>


        </div>

    </footer>


</body>

</html>