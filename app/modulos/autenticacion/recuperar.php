<?php

require_once __DIR__ . "/../../soporte/sesion.php";

require_once __DIR__ . "/../../../vendor/autoload.php";

use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;

require_once __DIR__ . "/../../../config/database.php";

/** @var mysqli $conexion */

$mensaje = "";
$tipoMensaje = "";
$paso = 1;

/*
 * Permite abandonar de forma segura un código anterior
 * y comenzar otra solicitud.
 */
if (
    $_SERVER["REQUEST_METHOD"] === "GET" &&
    isset($_GET["reiniciar"])
) {
    $idRecuperacionAnterior = (int) (
        $_SESSION["temp_id_recuperacion"] ?? 0
    );
    $usuarioRecuperacionAnterior = (string) (
        $_SESSION["temp_usuario_recuperar"] ?? "Sistema"
    );

    if ($idRecuperacionAnterior > 0) {
        $recuperacionInvalidada = invalidarRecuperacion(
            $conexion,
            $idRecuperacionAnterior,
        );

        if ($recuperacionInvalidada) {
            registrarBitacora(
                $usuarioRecuperacionAnterior,
                "CANCELAR RECUPERACIÓN",
                "recuperacion_contrasena",
                "El usuario canceló el código de recuperación activo.",
                $idRecuperacionAnterior,
            );
        }
    }

    limpiarSesionRecuperacion();

    header("Location: recuperar.php");
    exit();
}

/*
 * Si hay una recuperación vigente en la sesión,
 * se vuelve a mostrar directamente el segundo paso.
 */
if (
    isset(
        $_SESSION["temp_id_recuperacion"],
        $_SESSION["temp_id_usuario_rol"],
        $_SESSION["temp_usuario_recuperar"],
    )
) {
    $paso = 2;
}

/*
 * PASO 1:
 * Verificar el usuario y enviar un PIN de seis dígitos.
 */
if (
    $_SERVER["REQUEST_METHOD"] === "POST" &&
    isset($_POST["solicitar_pin"])
) {
    $usuarioInput = trim($_POST["usuario"] ?? "");
    $correoInput = trim($_POST["correo"] ?? "");

    if ($usuarioInput === "" || $correoInput === "") {
        $mensaje = "Todos los campos son obligatorios.";
        $tipoMensaje = "danger";
    } elseif (!filter_var($correoInput, FILTER_VALIDATE_EMAIL)) {
        $mensaje = "El correo electrónico no tiene un formato válido.";
        $tipoMensaje = "danger";
    } else {
        $enTransaccion = false;

        try {
            $sqlUsuario = "
                SELECT
                    ur.id_usuarios_rol,
                    ur.usuario,
                    u.correo
                FROM usuarios_rol ur
                INNER JOIN usuario u
                    ON u.id_usuario = ur.id_usuario
                WHERE ur.usuario = ?
                  AND u.correo = ?
                LIMIT 1
            ";

            $stmtUsuario = mysqli_prepare(
                $conexion,
                $sqlUsuario,
            );

            if (!$stmtUsuario) {
                throw new RuntimeException(
                    "No fue posible preparar la consulta del usuario.",
                );
            }

            mysqli_stmt_bind_param(
                $stmtUsuario,
                "ss",
                $usuarioInput,
                $correoInput,
            );

            if (!mysqli_stmt_execute($stmtUsuario)) {
                throw new RuntimeException(
                    mysqli_stmt_error($stmtUsuario),
                );
            }

            $resultadoUsuario = mysqli_stmt_get_result(
                $stmtUsuario,
            );

            $usuarioEncontrado = mysqli_fetch_assoc(
                $resultadoUsuario,
            );

            mysqli_stmt_close($stmtUsuario);

            if (!$usuarioEncontrado) {
                throw new InvalidArgumentException(
                    "Los datos ingresados no coinciden con ningún registro.",
                );
            }

            $idUsuarioRol = (int) $usuarioEncontrado[
                "id_usuarios_rol"
            ];

            $pin = str_pad(
                (string) random_int(0, 999999),
                6,
                "0",
                STR_PAD_LEFT,
            );

            /*
             * La base de datos nunca guarda el PIN real.
             * Solo almacena su hash protegido con bcrypt.
             */
            $tokenHash = password_hash(
                $pin,
                PASSWORD_BCRYPT,
            );

            if (!is_string($tokenHash)) {
                throw new RuntimeException(
                    "No fue posible proteger el código de recuperación.",
                );
            }

            $ipSolicitud = obtenerIpCliente();

            mysqli_begin_transaction($conexion);
            $enTransaccion = true;

            /*
             * Un usuario solamente puede tener un código activo.
             */
            $sqlInvalidar = "
                UPDATE recuperacion_contrasena
                SET utilizado = TRUE,
                    fecha_utilizacion = COALESCE(
                        fecha_utilizacion,
                        NOW()
                    )
                WHERE id_usuarios_rol = ?
                  AND utilizado = FALSE
            ";

            $stmtInvalidar = mysqli_prepare(
                $conexion,
                $sqlInvalidar,
            );

            if (!$stmtInvalidar) {
                throw new RuntimeException(
                    "No fue posible invalidar los códigos anteriores.",
                );
            }

            mysqli_stmt_bind_param(
                $stmtInvalidar,
                "i",
                $idUsuarioRol,
            );

            if (!mysqli_stmt_execute($stmtInvalidar)) {
                throw new RuntimeException(
                    mysqli_stmt_error($stmtInvalidar),
                );
            }

            mysqli_stmt_close($stmtInvalidar);

            $sqlRecuperacion = "
                INSERT INTO recuperacion_contrasena (
                    id_usuarios_rol,
                    token_hash,
                    fecha_expiracion,
                    ip_solicitud
                )
                VALUES (
                    ?,
                    ?,
                    DATE_ADD(NOW(), INTERVAL 1 MINUTE),
                    ?
                )
            ";

            $stmtRecuperacion = mysqli_prepare(
                $conexion,
                $sqlRecuperacion,
            );

            if (!$stmtRecuperacion) {
                throw new RuntimeException(
                    "No fue posible preparar el código de recuperación.",
                );
            }

            mysqli_stmt_bind_param(
                $stmtRecuperacion,
                "iss",
                $idUsuarioRol,
                $tokenHash,
                $ipSolicitud,
            );

            if (!mysqli_stmt_execute($stmtRecuperacion)) {
                throw new RuntimeException(
                    mysqli_stmt_error($stmtRecuperacion),
                );
            }

            $idRecuperacion = mysqli_insert_id($conexion);

            mysqli_stmt_close($stmtRecuperacion);

            if (!registrarBitacora(
                $usuarioEncontrado["usuario"],
                "SOLICITAR RECUPERACIÓN",
                "recuperacion_contrasena",
                "Se generó un código temporal para recuperar la contraseña.",
                $idRecuperacion,
            )) {
                throw new RuntimeException(
                    "No fue posible registrar la solicitud en la bitácora.",
                );
            }

            mysqli_commit($conexion);
            $enTransaccion = false;

            if (
                !enviarNotificacionCorreo(
                    $correoInput,
                    $pin,
                )
            ) {
                $recuperacionInvalidada = invalidarRecuperacion(
                    $conexion,
                    $idRecuperacion,
                );

                if ($recuperacionInvalidada) {
                    registrarBitacora(
                        $usuarioEncontrado["usuario"],
                        "INVALIDAR RECUPERACIÓN",
                        "recuperacion_contrasena",
                        "El código fue invalidado porque no se pudo enviar la notificación.",
                        $idRecuperacion,
                    );
                }

                throw new RuntimeException(
                    "No fue posible enviar el correo de verificación.",
                );
            }

            session_regenerate_id(true);

            $_SESSION["temp_id_recuperacion"] =
                $idRecuperacion;

            $_SESSION["temp_id_usuario_rol"] =
                $idUsuarioRol;

            $_SESSION["temp_usuario_recuperar"] =
                $usuarioEncontrado["usuario"];

            $mensaje =
                "Se envió un código de verificación a tu correo. " .
                "Revisa también la carpeta de spam.";

            $tipoMensaje = "success";
            $paso = 2;
        } catch (InvalidArgumentException $e) {
            if ($enTransaccion) {
                mysqli_rollback($conexion);
            }

            $mensaje = $e->getMessage();
            $tipoMensaje = "danger";
            $paso = 1;
        } catch (Throwable $e) {
            if ($enTransaccion) {
                mysqli_rollback($conexion);
            }

            error_log(
                "Error solicitando la recuperación: " .
                $e->getMessage(),
            );

            $mensaje =
                $e->getMessage() ===
                "No fue posible enviar el correo de verificación."
                    ? $e->getMessage()
                    : "No fue posible procesar la recuperación.";

            $tipoMensaje = "danger";
            $paso = 1;
        }
    }
}

/*
 * PASO 2:
 * Verificar el PIN y establecer la nueva contraseña.
 */
if (
    $_SERVER["REQUEST_METHOD"] === "POST" &&
    isset($_POST["restablecer_contrasena"])
) {
    $pinInput = trim($_POST["pin"] ?? "");
    $nuevaContrasena = $_POST["nueva_contrasena"] ?? "";
    $confirmarContrasena =
        $_POST["confirmar_contrasena"] ?? "";

    $idRecuperacion = (int) (
        $_SESSION["temp_id_recuperacion"] ?? 0
    );

    $idUsuarioRol = (int) (
        $_SESSION["temp_id_usuario_rol"] ?? 0
    );

    $usuarioRecuperar =
        $_SESSION["temp_usuario_recuperar"] ?? "";

    $paso = 2;

    if (
        $idRecuperacion <= 0 ||
        $idUsuarioRol <= 0 ||
        $usuarioRecuperar === ""
    ) {
        limpiarSesionRecuperacion();

        $mensaje =
            "La sesión de recuperación expiró. Solicita otro código.";

        $tipoMensaje = "danger";
        $paso = 1;
    } elseif (
        $pinInput === "" ||
        $nuevaContrasena === "" ||
        $confirmarContrasena === ""
    ) {
        $mensaje = "Todos los campos son obligatorios.";
        $tipoMensaje = "danger";
    } elseif (!preg_match('/^\d{6}$/', $pinInput)) {
        $mensaje = "El PIN debe contener exactamente seis números.";
        $tipoMensaje = "danger";
    } elseif ($nuevaContrasena !== $confirmarContrasena) {
        $mensaje = "Las contraseñas no coinciden.";
        $tipoMensaje = "danger";
    } elseif (strlen($nuevaContrasena) < 8) {
        $mensaje =
            "La nueva contraseña debe tener al menos 8 caracteres.";

        $tipoMensaje = "danger";
    } elseif (strlen($nuevaContrasena) > 255) {
        $mensaje = "La contraseña supera el tamaño permitido.";
        $tipoMensaje = "danger";
    } else {
        $enTransaccion = false;

        try {
            mysqli_begin_transaction($conexion);
            $enTransaccion = true;

            $sqlToken = "
                SELECT
                    rc.token_hash
                FROM recuperacion_contrasena rc
                WHERE rc.id_recuperacion = ?
                  AND rc.id_usuarios_rol = ?
                  AND rc.utilizado = FALSE
                  AND rc.fecha_expiracion >= NOW()
                LIMIT 1
                FOR UPDATE
            ";

            $stmtToken = mysqli_prepare(
                $conexion,
                $sqlToken,
            );

            if (!$stmtToken) {
                throw new RuntimeException(
                    "No fue posible preparar la verificación del PIN.",
                );
            }

            mysqli_stmt_bind_param(
                $stmtToken,
                "ii",
                $idRecuperacion,
                $idUsuarioRol,
            );

            if (!mysqli_stmt_execute($stmtToken)) {
                throw new RuntimeException(
                    mysqli_stmt_error($stmtToken),
                );
            }

            $resultadoToken = mysqli_stmt_get_result(
                $stmtToken,
            );

            $recuperacion = mysqli_fetch_assoc(
                $resultadoToken,
            );

            mysqli_stmt_close($stmtToken);

            if (
                !$recuperacion ||
                !password_verify(
                    $pinInput,
                    $recuperacion["token_hash"],
                )
            ) {
                throw new InvalidArgumentException(
                    "El código PIN es incorrecto o ya expiró.",
                );
            }

            $contrasenaHash = password_hash(
                $nuevaContrasena,
                PASSWORD_DEFAULT,
            );

            if (!is_string($contrasenaHash)) {
                throw new RuntimeException(
                    "No fue posible proteger la nueva contraseña.",
                );
            }

            $sqlContrasena = "
                UPDATE usuarios_rol
                SET contrasena = ?
                WHERE id_usuarios_rol = ?
            ";

            $stmtContrasena = mysqli_prepare(
                $conexion,
                $sqlContrasena,
            );

            if (!$stmtContrasena) {
                throw new RuntimeException(
                    "No fue posible preparar la nueva contraseña.",
                );
            }

            mysqli_stmt_bind_param(
                $stmtContrasena,
                "si",
                $contrasenaHash,
                $idUsuarioRol,
            );

            if (!mysqli_stmt_execute($stmtContrasena)) {
                throw new RuntimeException(
                    mysqli_stmt_error($stmtContrasena),
                );
            }

            mysqli_stmt_close($stmtContrasena);

            $sqlUtilizarToken = "
                UPDATE recuperacion_contrasena
                SET utilizado = TRUE,
                    fecha_utilizacion = NOW()
                WHERE id_recuperacion = ?
                  AND utilizado = FALSE
            ";

            $stmtUtilizarToken = mysqli_prepare(
                $conexion,
                $sqlUtilizarToken,
            );

            if (!$stmtUtilizarToken) {
                throw new RuntimeException(
                    "No fue posible finalizar el código utilizado.",
                );
            }

            mysqli_stmt_bind_param(
                $stmtUtilizarToken,
                "i",
                $idRecuperacion,
            );

            if (!mysqli_stmt_execute($stmtUtilizarToken)) {
                throw new RuntimeException(
                    mysqli_stmt_error($stmtUtilizarToken),
                );
            }

            mysqli_stmt_close($stmtUtilizarToken);

            /*
             * Recuperar la contraseña también rehabilita la cuenta.
             */
            $sqlSeguridad = "
                UPDATE seguridad_cuenta
                SET intentos_fallidos = 0,
                    cuenta_bloqueada = FALSE,
                    fecha_bloqueo = NULL
                WHERE id_usuarios_rol = ?
            ";

            $stmtSeguridad = mysqli_prepare(
                $conexion,
                $sqlSeguridad,
            );

            if (!$stmtSeguridad) {
                throw new RuntimeException(
                    "No fue posible restablecer la seguridad de la cuenta.",
                );
            }

            mysqli_stmt_bind_param(
                $stmtSeguridad,
                "i",
                $idUsuarioRol,
            );

            if (!mysqli_stmt_execute($stmtSeguridad)) {
                throw new RuntimeException(
                    mysqli_stmt_error($stmtSeguridad),
                );
            }

            mysqli_stmt_close($stmtSeguridad);

            $bitacoraRegistrada = registrarBitacora(
                $usuarioRecuperar,
                "RECUPERAR CONTRASEÑA",
                "usuarios_rol",
                "El usuario cambió su contraseña mediante un PIN de recuperación.",
                $idUsuarioRol,
            );

            if (!$bitacoraRegistrada) {
                throw new RuntimeException(
                    "No fue posible registrar la recuperación en la bitácora.",
                );
            }

            mysqli_commit($conexion);
            $enTransaccion = false;

            limpiarSesionRecuperacion();

            $mensaje =
                "¡Contraseña restablecida correctamente! " .
                "Ya puedes iniciar sesión.";

            $tipoMensaje = "success";
            $paso = 3;
        } catch (InvalidArgumentException $e) {
            if ($enTransaccion) {
                mysqli_rollback($conexion);
            }

            $mensaje = $e->getMessage();
            $tipoMensaje = "danger";
            $paso = 2;
        } catch (Throwable $e) {
            if ($enTransaccion) {
                mysqli_rollback($conexion);
            }

            error_log(
                "Error restableciendo la contraseña: " .
                $e->getMessage(),
            );

            $mensaje =
                "No fue posible restablecer la contraseña.";

            $tipoMensaje = "danger";
            $paso = 2;
        }
    }
}

/**
 * Marca un código como utilizado cuando el correo no pudo enviarse.
 */
function invalidarRecuperacion(
    mysqli $conexion,
    int $idRecuperacion,
): bool {
    $sql = "
        UPDATE recuperacion_contrasena
        SET utilizado = TRUE,
            fecha_utilizacion = NOW()
        WHERE id_recuperacion = ?
    ";

    $stmt = mysqli_prepare($conexion, $sql);

    if (!$stmt) {
        error_log(
            "No fue posible preparar la invalidación del código.",
        );
        return false;
    }

    mysqli_stmt_bind_param(
        $stmt,
        "i",
        $idRecuperacion,
    );

    $ejecutado = mysqli_stmt_execute($stmt);

    if (!$ejecutado) {
        error_log(
            "No fue posible invalidar el código: " .
            mysqli_stmt_error($stmt),
        );
    }

    mysqli_stmt_close($stmt);

    return $ejecutado;
}

/**
 * Elimina solamente los datos temporales del proceso de recuperación.
 */
function limpiarSesionRecuperacion(): void
{
    unset(
        $_SESSION["temp_id_recuperacion"],
        $_SESSION["temp_id_usuario_rol"],
        $_SESSION["temp_usuario_recuperar"],
    );
}

/**
 * Envía el PIN utilizando la configuración SMTP del entorno.
 */
function enviarNotificacionCorreo(
    string $email,
    string $token,
): bool {
$usuarioSmtp = entornoEcoFauna("ECOFAUNA_SMTP_USER");
$contrasenaSmtp = entornoEcoFauna("ECOFAUNA_SMTP_PASSWORD");

    if (
        !is_string($usuarioSmtp) ||
        $usuarioSmtp === "" ||
        !is_string($contrasenaSmtp) ||
        $contrasenaSmtp === ""
    ) {
        error_log(
            "Faltan las variables ECOFAUNA_SMTP_USER y " .
            "ECOFAUNA_SMTP_PASSWORD.",
        );

        return false;
    }

    $rutaPlantilla =
        __DIR__ . "/../../plantillas/correo_recuperacion.html";

    $rutaEstilos =
        __DIR__ . "/../../../public/assets/css/styleCorreoRecuperacion.css";

    $plantillaCorreo = file_get_contents(
        $rutaPlantilla,
    );

    $estilosCorreo = file_get_contents(
        $rutaEstilos,
    );

    if (
        !is_string($plantillaCorreo) ||
        !is_string($estilosCorreo)
    ) {
        error_log(
            "No fue posible cargar la plantilla del correo.",
        );

        return false;
    }

    $cuerpoCorreo = str_replace(
        [
            "/*__ESTILOS_CORREO__*/",
            "__TOKEN__",
        ],
        [
            $estilosCorreo,
            htmlspecialchars(
                $token,
                ENT_QUOTES,
                "UTF-8",
            ),
        ],
        $plantillaCorreo,
    );

    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host = entornoEcoFauna("ECOFAUNA_SMTP_HOST", "smtp.gmail.com");
        $mail->SMTPAuth = true;
        $mail->Username = $usuarioSmtp;
        $mail->Password = $contrasenaSmtp;
        $mail->SMTPSecure =
            PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = (int) entornoEcoFauna("ECOFAUNA_SMTP_PORT", "587");
        $mail->CharSet = "UTF-8";

        $mail->setFrom(
            $usuarioSmtp,
            "EcoFauna",
        );

        $mail->addAddress($email);
        $mail->isHTML(true);
        $mail->Subject = "Verificación de identidad";
        $mail->Body = $cuerpoCorreo;

        return $mail->send();
    } catch (Exception $e) {
        error_log(
            "No fue posible enviar el PIN: " .
            $mail->ErrorInfo,
        );

        return false;
    }
}

?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Recuperación de Contraseña - EcoFauna</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&family=PT+Serif+Caption&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/stylelogin.css">
    <link rel="stylesheet" href="css/ecofauna-unified.css?v=1">
</head>

<body class="ecofauna-unified fondo-login d-flex justify-content-center align-items-center vh-100">

    <div class="login-card">
        <div class="text-center">
            <div class="logo-login">
                <img src="img/LogoEcoFauna1.png" alt="Logo Zoológico EcoFauna">
            </div>
            <h2>Recuperación</h2>
            <p class="mb-3">Establece tu acceso de forma segura.</p>
        </div>

        <?php if ($mensaje !== ""): ?>
            <div class="alert alert-<?= htmlspecialchars(
                $tipoMensaje,
                ENT_QUOTES,
                "UTF-8",
            ) ?>" role="alert">
                <?= htmlspecialchars(
                    $mensaje,
                    ENT_QUOTES,
                    "UTF-8",
                ) ?>
            </div>
        <?php endif; ?>

        <?php if ($paso === 1): ?>
            <form method="POST">
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
                            placeholder="Ingrese su nombre de usuario"
                            required>
                    </div>
                </div>

                <div class="mb-4">
                    <label class="form-label" for="correo">Correo electrónico</label>
                    <div class="input-group">
                        <span class="input-group-text">
                            <i class="bi bi-envelope-fill"></i>
                        </span>
                        <input
                            id="correo"
                            type="email"
                            class="form-control"
                            name="correo"
                            maxlength="100"
                            autocomplete="email"
                            placeholder="correo@zoo.com"
                            required>
                    </div>
                </div>

                <button type="submit" name="solicitar_pin" class="btn btn-login w-100 mb-3">
                    <i class="bi bi-send-fill me-2"></i>
                    Generar Código PIN
                </button>

                <div class="text-center">
                    <a href="index.php" class="text-decoration-none text-muted small">
                        <i class="bi bi-arrow-left"></i>
                        Volver al Login
                    </a>
                </div>
            </form>

        <?php elseif ($paso === 2): ?>
            <form method="POST">
                <div class="mb-3">
                    <label class="form-label" for="pin">Código PIN de Seguridad</label>
                    <div class="input-group">
                        <span class="input-group-text">
                            <i class="bi bi-shield-fill-check"></i>
                        </span>
                        <input
                            id="pin"
                            type="text"
                            class="form-control"
                            name="pin"
                            inputmode="numeric"
                            pattern="[0-9]{6}"
                            maxlength="6"
                            autocomplete="one-time-code"
                            placeholder="Código de 6 dígitos"
                            required>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label" for="nueva_contrasena">Nueva Contraseña</label>
                    <div class="input-group">
                        <span class="input-group-text">
                            <i class="bi bi-lock-fill"></i>
                        </span>
                        <input
                            id="nueva_contrasena"
                            type="password"
                            class="form-control"
                            name="nueva_contrasena"
                            minlength="8"
                            maxlength="255"
                            autocomplete="new-password"
                            placeholder="Mínimo 8 caracteres"
                            required>
                    </div>
                </div>

                <div class="mb-4">
                    <label class="form-label" for="confirmar_contrasena">Confirmar Nueva Contraseña</label>
                    <div class="input-group">
                        <span class="input-group-text">
                            <i class="bi bi-lock-fill"></i>
                        </span>
                        <input
                            id="confirmar_contrasena"
                            type="password"
                            class="form-control"
                            name="confirmar_contrasena"
                            minlength="8"
                            maxlength="255"
                            autocomplete="new-password"
                            placeholder="Confirmar contraseña"
                            required>
                    </div>
                </div>

                <button type="submit" name="restablecer_contrasena" class="btn btn-login w-100 mb-3">
                    <i class="bi bi-check-circle-fill me-2"></i>
                    Cambiar Contraseña
                </button>

                <div class="text-center">
                    <a href="recuperar.php?reiniciar=1" class="text-decoration-none text-muted small">
                        <i class="bi bi-arrow-counterclockwise"></i>
                        Solicitar otro código
                    </a>
                </div>
            </form>

        <?php elseif ($paso === 3): ?>
            <div class="text-center">
                <i class="bi bi-check-circle text-success fs-1 mb-3 d-block"></i>
                <a href="index.php" class="btn btn-login w-100">
                    <i class="bi bi-box-arrow-in-right me-2"></i>
                    Ir al Inicio de Sesión
                </a>
            </div>
        <?php endif; ?>
    </div>

</body>

</html>
