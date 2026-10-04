<?php

require_once __DIR__ . "/../../soporte/sesion.php";
require_once __DIR__ . "/../../../config/database.php";

/** @var mysqli $conexion */

/*
|--------------------------------------------------------------------------
| CONTROL DE ACCESO
|--------------------------------------------------------------------------
*/

if (
    !isset($_SESSION["usuario"], $_SESSION["rol"], $_SESSION["id_login"]) ||
    $_SESSION["rol"] !== "Administrador"
) {
    header("Location: ../index.php");
    exit();
}

/*
|--------------------------------------------------------------------------
| FUNCIONES
|--------------------------------------------------------------------------
*/

function escaparUsuarios(mixed $valor): string
{
    return htmlspecialchars((string) $valor, ENT_QUOTES, "UTF-8");
}

function fechaIsoUsuariosValida(string $fecha): bool
{
    if ($fecha === "") {
        return true;
    }

    $objeto = DateTime::createFromFormat("Y-m-d", $fecha);

    return $objeto !== false && $objeto->format("Y-m-d") === $fecha;
}

function redirigirUsuarios(
    string $mensaje,
    string $tipo = "success",
    ?int $idEditar = null,
): void {
    $_SESSION["mensaje_usuarios_admin"] = $mensaje;
    $_SESSION["tipo_usuarios_admin"] = $tipo;

    $destino = "usuarios.php";

    if ($idEditar !== null && $idEditar > 0) {
        $destino .= "?editar=" . $idEditar;
    }

    header("Location: " . $destino);
    exit();
}

function rolUsuariosExiste(mysqli $conexion, int $idRol): bool
{
    $stmt = mysqli_prepare(
        $conexion,
        "SELECT id_rol
         FROM rol
         WHERE id_rol = ?
         LIMIT 1",
    );

    if (!$stmt) {
        return false;
    }

    mysqli_stmt_bind_param($stmt, "i", $idRol);
    mysqli_stmt_execute($stmt);

    $resultado = mysqli_stmt_get_result($stmt);
    $existe = mysqli_fetch_assoc($resultado) !== null;

    mysqli_stmt_close($stmt);

    return $existe;
}

function datoUsuariosDuplicado(
    mysqli $conexion,
    string $campo,
    string $valor,
    ?int $idUsuarioRolExcluir = null,
): bool {

    if (!in_array($campo, ["correo", "usuario"], true)) {
        throw new InvalidArgumentException(
            "Campo de usuario inválido."
        );
    }

    if ($campo === "correo") {

        $sql = "
            SELECT 1
            FROM usuario u
            INNER JOIN usuarios_rol ur
                ON ur.id_usuario = u.id_usuario
            WHERE u.correo = ?
        ";

    } else {

        $sql = "
            SELECT 1
            FROM usuarios_rol ur
            WHERE ur.usuario = ?
        ";
    }

    if ($idUsuarioRolExcluir !== null) {
        $sql .= " AND ur.id_usuarios_rol <> ?";
    }

    $sql .= " LIMIT 1";

    $stmt = mysqli_prepare($conexion, $sql);

    if (!$stmt) {
        throw new RuntimeException(
            "No fue posible comprobar los datos del usuario."
        );
    }

    if ($idUsuarioRolExcluir !== null) {

        mysqli_stmt_bind_param(
            $stmt,
            "si",
            $valor,
            $idUsuarioRolExcluir,
        );

    } else {

        mysqli_stmt_bind_param(
            $stmt,
            "s",
            $valor,
        );
    }

    mysqli_stmt_execute($stmt);

    $resultado = mysqli_stmt_get_result($stmt);

    $duplicado = mysqli_fetch_assoc($resultado) !== null;

    mysqli_stmt_close($stmt);

    return $duplicado;
}

/*
|--------------------------------------------------------------------------
| MENSAJES
|--------------------------------------------------------------------------
*/

$mensaje = $_SESSION["mensaje_usuarios_admin"] ?? "";
$tipoMensaje = $_SESSION["tipo_usuarios_admin"] ?? "success";

unset(
    $_SESSION["mensaje_usuarios_admin"],
    $_SESSION["tipo_usuarios_admin"]
);

$error = "";

$idEditar = filter_input(
    INPUT_GET,
    "editar",
    FILTER_VALIDATE_INT
) ?: 0;

/*
|--------------------------------------------------------------------------
| DATOS PARA CREAR
|--------------------------------------------------------------------------
*/

$datosCrear = [
    "nombre" => "",
    "correo" => "",
    "telefono" => "",
    "fecha_nacimiento" => "",
    "usuario" => "",
    "id_rol" => "",
];

/*
|--------------------------------------------------------------------------
| PROCESAMIENTO POST
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $accion = $_POST["accion"] ?? "";

    $tokenValido = tokenCsrfSesionValido(
        "administracion_usuarios",
        $_POST["csrf_token"] ?? null,
    );

    if (!$tokenValido) {

        http_response_code(403);

        $error =
            "La solicitud expiró. Recarga la página e inténtalo nuevamente.";

    }

    /*
    |--------------------------------------------------------------------------
    | CREAR
    |--------------------------------------------------------------------------
    */

    elseif ($accion === "crear") {

        $datosCrear = [
            "nombre" => trim($_POST["nombre"] ?? ""),
            "correo" => trim($_POST["correo"] ?? ""),
            "telefono" => trim($_POST["telefono"] ?? ""),
            "fecha_nacimiento" => trim($_POST["fecha_nacimiento"] ?? ""),
            "usuario" => trim($_POST["usuario_nuevo"] ?? ""),
            "id_rol" => (string) ((int) ($_POST["id_rol"] ?? 0)),
        ];

        $contrasena = (string) ($_POST["contrasena"] ?? "");

        $idRol = (int) $datosCrear["id_rol"];

        $enTransaccion = false;

        try {

            if (
                $datosCrear["nombre"] === "" ||
                mb_strlen($datosCrear["nombre"]) > 100
            ) {
                throw new RuntimeException(
                    "Escribe un nombre válido de máximo 100 caracteres."
                );
            }

            if (
                !filter_var(
                    $datosCrear["correo"],
                    FILTER_VALIDATE_EMAIL
                ) ||
                mb_strlen($datosCrear["correo"]) > 100
            ) {
                throw new RuntimeException(
                    "Escribe un correo electrónico válido."
                );
            }

            if (mb_strlen($datosCrear["telefono"]) > 20) {
                throw new RuntimeException(
                    "El teléfono no puede superar 20 caracteres."
                );
            }

            if (
                !fechaIsoUsuariosValida(
                    $datosCrear["fecha_nacimiento"]
                ) ||
                (
                    $datosCrear["fecha_nacimiento"] !== "" &&
                    $datosCrear["fecha_nacimiento"] > date("Y-m-d")
                )
            ) {
                throw new RuntimeException(
                    "La fecha de nacimiento no es válida."
                );
            }

            if (
                preg_match(
                    '/^[A-Za-z0-9._-]{3,50}$/',
                    $datosCrear["usuario"]
                ) !== 1
            ) {
                throw new RuntimeException(
                    "El usuario debe tener entre 3 y 50 caracteres y usar solamente letras, números, punto, guion o guion bajo."
                );
            }

            if (
                strlen($contrasena) < 8 ||
                strlen($contrasena) > 72
            ) {
                throw new RuntimeException(
                    "La contraseña debe tener entre 8 y 72 caracteres."
                );
            }

            if (!rolUsuariosExiste($conexion, $idRol)) {
                throw new RuntimeException(
                    "Selecciona un rol válido."
                );
            }

            if (
                datoUsuariosDuplicado(
                    $conexion,
                    "correo",
                    $datosCrear["correo"]
                )
            ) {
                throw new RuntimeException(
                    "Ya existe una persona registrada con ese correo."
                );
            }

            if (
                datoUsuariosDuplicado(
                    $conexion,
                    "usuario",
                    $datosCrear["usuario"]
                )
            ) {
                throw new RuntimeException(
                    "Ese nombre de usuario ya está en uso."
                );
            }

            mysqli_begin_transaction($conexion);

            $enTransaccion = true;

            $fechaNacimiento =
                $datosCrear["fecha_nacimiento"] !== ""
                    ? $datosCrear["fecha_nacimiento"]
                    : null;

            $stmtUsuario = mysqli_prepare(
                $conexion,
                "INSERT INTO usuario
                    (nombre, correo, telefono, fecha_nacimiento)
                 VALUES
                    (?, ?, NULLIF(?, ''), ?)"
            );

            if (!$stmtUsuario) {
                throw new RuntimeException(
                    "No fue posible preparar el registro personal."
                );
            }

            mysqli_stmt_bind_param(
                $stmtUsuario,
                "ssss",
                $datosCrear["nombre"],
                $datosCrear["correo"],
                $datosCrear["telefono"],
                $fechaNacimiento,
            );

            if (!mysqli_stmt_execute($stmtUsuario)) {
                throw new RuntimeException(
                    mysqli_stmt_error($stmtUsuario)
                );
            }

            $idUsuario = mysqli_insert_id($conexion);

            mysqli_stmt_close($stmtUsuario);

            $hash = password_hash(
                $contrasena,
                PASSWORD_DEFAULT
            );

            if (!is_string($hash)) {
                throw new RuntimeException(
                    "No fue posible proteger la contraseña."
                );
            }

            $stmtAcceso = mysqli_prepare(
                $conexion,
                "INSERT INTO usuarios_rol
                    (id_usuario, id_rol, usuario, contrasena)
                 VALUES
                    (?, ?, ?, ?)"
            );

            if (!$stmtAcceso) {
                throw new RuntimeException(
                    "No fue posible preparar los datos de acceso."
                );
            }

            mysqli_stmt_bind_param(
                $stmtAcceso,
                "iiss",
                $idUsuario,
                $idRol,
                $datosCrear["usuario"],
                $hash,
            );

            if (!mysqli_stmt_execute($stmtAcceso)) {
                throw new RuntimeException(
                    mysqli_stmt_error($stmtAcceso)
                );
            }

            mysqli_stmt_close($stmtAcceso);

            mysqli_commit($conexion);

            renovarTokenCsrfSesion(
                "administracion_usuarios"
            );

            redirigirUsuarios(
                "Usuario creado correctamente."
            );

        } catch (Throwable $errorCrear) {

            if ($enTransaccion) {
                mysqli_rollback($conexion);
            }

            error_log(
                "Error creando usuario: " .
                $errorCrear->getMessage()
            );

            $error =
                str_contains(
                    $errorCrear->getMessage(),
                    "Duplicate entry"
                )
                    ? "El correo o el nombre de usuario ya está registrado."
                    : $errorCrear->getMessage();
        }
    }

    /*
    |--------------------------------------------------------------------------
    | ACTUALIZAR
    |--------------------------------------------------------------------------
    */

    elseif ($accion === "actualizar") {

        $idUsuarioRol = filter_input(
            INPUT_POST,
            "id_usuarios_rol",
            FILTER_VALIDATE_INT
        ) ?: 0;

        $idEditar = $idUsuarioRol;

        $nombre = trim($_POST["nombre"] ?? "");
        $correo = trim($_POST["correo"] ?? "");
        $telefono = trim($_POST["telefono"] ?? "");
        $fechaNacimiento =
            trim($_POST["fecha_nacimiento"] ?? "");

        $usuarioAcceso =
            trim($_POST["usuario_acceso"] ?? "");

        $idRol =
            (int) ($_POST["id_rol"] ?? 0);

        $contrasenaNueva =
            (string) ($_POST["contrasena_nueva"] ?? "");

        $enTransaccion = false;

        try {

            $stmtActual = mysqli_prepare(
                $conexion,
                "SELECT
                    ur.id_usuario,
                    ur.usuario,
                    ur.id_rol,
                    r.nombre_rol
                 FROM usuarios_rol ur
                 INNER JOIN rol r
                    ON r.id_rol = ur.id_rol
                 WHERE ur.id_usuarios_rol = ?
                 LIMIT 1"
            );

            if (!$stmtActual) {
                throw new RuntimeException(
                    "No fue posible consultar el usuario."
                );
            }

            mysqli_stmt_bind_param(
                $stmtActual,
                "i",
                $idUsuarioRol
            );

            mysqli_stmt_execute($stmtActual);

            $resultadoActual =
                mysqli_stmt_get_result($stmtActual);

            $actual =
                mysqli_fetch_assoc($resultadoActual);

            mysqli_stmt_close($stmtActual);

            if (!$actual) {
                throw new RuntimeException(
                    "El usuario seleccionado no existe."
                );
            }

            if (
                $nombre === "" ||
                mb_strlen($nombre) > 100
            ) {
                throw new RuntimeException(
                    "Escribe un nombre válido de máximo 100 caracteres."
                );
            }

            if (
                !filter_var(
                    $correo,
                    FILTER_VALIDATE_EMAIL
                ) ||
                mb_strlen($correo) > 100
            ) {
                throw new RuntimeException(
                    "Escribe un correo electrónico válido."
                );
            }

            if (mb_strlen($telefono) > 20) {
                throw new RuntimeException(
                    "El teléfono no puede superar 20 caracteres."
                );
            }

            if (
                !fechaIsoUsuariosValida($fechaNacimiento) ||
                (
                    $fechaNacimiento !== "" &&
                    $fechaNacimiento > date("Y-m-d")
                )
            ) {
                throw new RuntimeException(
                    "La fecha de nacimiento no es válida."
                );
            }

            if (
                preg_match(
                    '/^[A-Za-z0-9._-]{3,50}$/',
                    $usuarioAcceso
                ) !== 1
            ) {
                throw new RuntimeException(
                    "El nombre de usuario no tiene un formato válido."
                );
            }

            if (
                $contrasenaNueva !== "" &&
                (
                    strlen($contrasenaNueva) < 8 ||
                    strlen($contrasenaNueva) > 72
                )
            ) {
                throw new RuntimeException(
                    "La contraseña nueva debe tener entre 8 y 72 caracteres."
                );
            }

            if (!rolUsuariosExiste($conexion, $idRol)) {
                throw new RuntimeException(
                    "Selecciona un rol válido."
                );
            }

            if (
                $idUsuarioRol ===
                (int) $_SESSION["id_login"] &&
                $idRol !== (int) $actual["id_rol"]
            ) {
                throw new RuntimeException(
                    "No puedes cambiar tu propio rol mientras tienes la sesión abierta."
                );
            }

            if (
                datoUsuariosDuplicado(
                    $conexion,
                    "correo",
                    $correo,
                    $idUsuarioRol
                )
            ) {
                throw new RuntimeException(
                    "Ya existe una persona registrada con ese correo."
                );
            }

            if (
                datoUsuariosDuplicado(
                    $conexion,
                    "usuario",
                    $usuarioAcceso,
                    $idUsuarioRol
                )
            ) {
                throw new RuntimeException(
                    "Ese nombre de usuario ya está en uso."
                );
            }

            mysqli_begin_transaction($conexion);

            $enTransaccion = true;

            $idUsuario =
                (int) $actual["id_usuario"];

            $fechaNacimientoDb =
                $fechaNacimiento !== ""
                    ? $fechaNacimiento
                    : null;

            $stmtPersona = mysqli_prepare(
                $conexion,
                "UPDATE usuario
                 SET
                    nombre = ?,
                    correo = ?,
                    telefono = NULLIF(?, ''),
                    fecha_nacimiento = ?
                 WHERE id_usuario = ?"
            );

            if (!$stmtPersona) {
                throw new RuntimeException(
                    "No fue posible preparar los datos personales."
                );
            }

            mysqli_stmt_bind_param(
                $stmtPersona,
                "ssssi",
                $nombre,
                $correo,
                $telefono,
                $fechaNacimientoDb,
                $idUsuario,
            );

            if (!mysqli_stmt_execute($stmtPersona)) {
                throw new RuntimeException(
                    mysqli_stmt_error($stmtPersona)
                );
            }

            mysqli_stmt_close($stmtPersona);

            if ($contrasenaNueva !== "") {

                $hashNuevo = password_hash(
                    $contrasenaNueva,
                    PASSWORD_DEFAULT
                );

                if (!is_string($hashNuevo)) {
                    throw new RuntimeException(
                        "No fue posible proteger la contraseña nueva."
                    );
                }

                $stmtAcceso = mysqli_prepare(
                    $conexion,
                    "UPDATE usuarios_rol
                     SET
                        id_rol = ?,
                        usuario = ?,
                        contrasena = ?
                     WHERE id_usuarios_rol = ?"
                );

                if (!$stmtAcceso) {
                    throw new RuntimeException(
                        "No fue posible preparar los datos de acceso."
                    );
                }

                mysqli_stmt_bind_param(
                    $stmtAcceso,
                    "issi",
                    $idRol,
                    $usuarioAcceso,
                    $hashNuevo,
                    $idUsuarioRol,
                );

            } else {

                $stmtAcceso = mysqli_prepare(
                    $conexion,
                    "UPDATE usuarios_rol
                     SET
                        id_rol = ?,
                        usuario = ?
                     WHERE id_usuarios_rol = ?"
                );

                if (!$stmtAcceso) {
                    throw new RuntimeException(
                        "No fue posible preparar los datos de acceso."
                    );
                }

                mysqli_stmt_bind_param(
                    $stmtAcceso,
                    "isi",
                    $idRol,
                    $usuarioAcceso,
                    $idUsuarioRol,
                );
            }

            if (!mysqli_stmt_execute($stmtAcceso)) {
                throw new RuntimeException(
                    mysqli_stmt_error($stmtAcceso)
                );
            }

            mysqli_stmt_close($stmtAcceso);

            mysqli_commit($conexion);

            if (
                $idUsuarioRol ===
                (int) $_SESSION["id_login"]
            ) {
                $_SESSION["usuario"] =
                    $usuarioAcceso;
            }

            renovarTokenCsrfSesion(
                "administracion_usuarios"
            );

            redirigirUsuarios(
                "Usuario actualizado correctamente.",
                "success",
                $idUsuarioRol
            );

        } catch (Throwable $errorActualizar) {

            if ($enTransaccion) {
                mysqli_rollback($conexion);
            }

            error_log(
                "Error actualizando usuario: " .
                $errorActualizar->getMessage()
            );

            $error =
                str_contains(
                    $errorActualizar->getMessage(),
                    "Duplicate entry"
                )
                    ? "El correo o el nombre de usuario ya está registrado."
                    : $errorActualizar->getMessage();
        }
    }

    /*
    |--------------------------------------------------------------------------
    | DESBLOQUEAR CUENTA
    |--------------------------------------------------------------------------
    */

    elseif ($accion === "desbloquear") {

        $idUsuarioRol = filter_input(
            INPUT_POST,
            "id_usuarios_rol",
            FILTER_VALIDATE_INT
        ) ?: 0;

        $enTransaccion = false;

        try {

            if ($idUsuarioRol <= 0) {
                throw new RuntimeException(
                    "El usuario seleccionado no es válido."
                );
            }

            mysqli_begin_transaction($conexion);

            $enTransaccion = true;

            $stmt = mysqli_prepare(
                $conexion,
                "UPDATE seguridad_cuenta
                 SET
                    intentos_fallidos = 0,
                    cuenta_bloqueada = FALSE,
                    fecha_bloqueo = NULL,
                    ultimo_intento_fallido = NULL
                 WHERE id_usuarios_rol = ?"
            );

            if (!$stmt) {
                throw new RuntimeException(
                    "No fue posible preparar el desbloqueo."
                );
            }

            mysqli_stmt_bind_param(
                $stmt,
                "i",
                $idUsuarioRol
            );

            if (!mysqli_stmt_execute($stmt)) {
                throw new RuntimeException(
                    mysqli_stmt_error($stmt)
                );
            }

            mysqli_stmt_close($stmt);

            if (
                !registrarBitacora(
                    $_SESSION["usuario"],
                    "DESBLOQUEAR CUENTA",
                    "seguridad_cuenta",
                    "El administrador rehabilitó la cuenta de acceso #{$idUsuarioRol}.",
                    $idUsuarioRol,
                )
            ) {
                throw new RuntimeException(
                    "No fue posible registrar el desbloqueo en la bitácora."
                );
            }

            mysqli_commit($conexion);

            renovarTokenCsrfSesion(
                "administracion_usuarios"
            );

            redirigirUsuarios(
                "Cuenta desbloqueada correctamente."
            );

        } catch (Throwable $errorDesbloquear) {

            if ($enTransaccion) {
                mysqli_rollback($conexion);
            }

            error_log(
                "Error desbloqueando cuenta: " .
                $errorDesbloquear->getMessage()
            );

            $error =
                $errorDesbloquear->getMessage();
        }

    } else {

        $error =
            "La acción solicitada no es válida.";
    }
}

/*
|--------------------------------------------------------------------------
| ROLES
|--------------------------------------------------------------------------
*/

$roles = [];

$resultadoRoles = mysqli_query(
    $conexion,
    "SELECT
        id_rol,
        nombre_rol,
        descripcion
     FROM rol
     ORDER BY id_rol"
);

if ($resultadoRoles) {

    while (
        $rol = mysqli_fetch_assoc(
            $resultadoRoles
        )
    ) {
        $roles[] = $rol;
    }
}

/*
|--------------------------------------------------------------------------
| USUARIO A EDITAR
|--------------------------------------------------------------------------
*/

$usuarioEditar = null;

if ($idEditar > 0) {

    $stmtEditar = mysqli_prepare(
        $conexion,
        "SELECT
            ur.id_usuarios_rol,
            ur.usuario,
            ur.id_rol,
            u.nombre,
            u.correo,
            u.telefono,
            u.fecha_nacimiento,
            r.nombre_rol
         FROM usuarios_rol ur
         INNER JOIN usuario u
            ON u.id_usuario = ur.id_usuario
         INNER JOIN rol r
            ON r.id_rol = ur.id_rol
         WHERE ur.id_usuarios_rol = ?
         LIMIT 1"
    );

    if ($stmtEditar) {

        mysqli_stmt_bind_param(
            $stmtEditar,
            "i",
            $idEditar
        );

        mysqli_stmt_execute($stmtEditar);

        $resultadoEditar =
            mysqli_stmt_get_result(
                $stmtEditar
            );

        $usuarioEditar =
            mysqli_fetch_assoc(
                $resultadoEditar
            ) ?: null;

        mysqli_stmt_close($stmtEditar);
    }

    if (
        !$usuarioEditar &&
        $error === ""
    ) {
        $error =
            "El usuario que intentas editar no existe.";
    }
}
/*
|--------------------------------------------------------------------------
| PAGINACIÓN
|--------------------------------------------------------------------------
*/

$registrosPorPagina = 10;

$paginaActual = filter_input(
    INPUT_GET,
    "pagina",
    FILTER_VALIDATE_INT
) ?: 1;

if ($paginaActual < 1) {
    $paginaActual = 1;
}


/*
|--------------------------------------------------------------------------
| CONTAR USUARIOS
|--------------------------------------------------------------------------
*/

$sqlTotalUsuarios = "
    SELECT COUNT(*) AS total
    FROM usuarios_rol ur
    INNER JOIN usuario u
        ON u.id_usuario = ur.id_usuario
    INNER JOIN rol r
        ON r.id_rol = ur.id_rol
";

$resultadoTotalUsuarios = mysqli_query(
    $conexion,
    $sqlTotalUsuarios
);

$totalUsuarios = 0;

if ($resultadoTotalUsuarios) {

    $filaTotal = mysqli_fetch_assoc(
        $resultadoTotalUsuarios
    );

    $totalUsuarios = (int) ($filaTotal["total"] ?? 0);
}


/*
|--------------------------------------------------------------------------
| CALCULAR PAGINACIÓN
|--------------------------------------------------------------------------
*/

$totalPaginas = max(
    1,
    (int) ceil(
        $totalUsuarios / $registrosPorPagina
    )
);

if ($paginaActual > $totalPaginas) {
    $paginaActual = $totalPaginas;
}

$offset = (
    $paginaActual - 1
) * $registrosPorPagina;


/*
|--------------------------------------------------------------------------
| LISTAR USUARIOS DE LA PÁGINA ACTUAL
|--------------------------------------------------------------------------
*/

$usuarios = [];

$sqlUsuarios = "
    SELECT
        ur.id_usuarios_rol,
        ur.usuario,
        u.nombre,
        u.correo,
        u.telefono,
        r.nombre_rol,
        COALESCE(sc.intentos_fallidos, 0)
            AS intentos_fallidos,
        COALESCE(sc.cuenta_bloqueada, 0)
            AS cuenta_bloqueada,
        sc.fecha_bloqueo
    FROM usuarios_rol ur
    INNER JOIN usuario u
        ON u.id_usuario = ur.id_usuario
    INNER JOIN rol r
        ON r.id_rol = ur.id_rol
    LEFT JOIN seguridad_cuenta sc
        ON sc.id_usuarios_rol =
           ur.id_usuarios_rol
    ORDER BY
        u.nombre,
        ur.usuario
    LIMIT ? OFFSET ?
";

$stmtUsuarios = mysqli_prepare(
    $conexion,
    $sqlUsuarios
);

if ($stmtUsuarios) {

    mysqli_stmt_bind_param(
        $stmtUsuarios,
        "ii",
        $registrosPorPagina,
        $offset
    );

    mysqli_stmt_execute($stmtUsuarios);

    $resultadoUsuarios =
        mysqli_stmt_get_result(
            $stmtUsuarios
        );

    while (
        $filaUsuario =
            mysqli_fetch_assoc(
                $resultadoUsuarios
            )
    ) {
        $usuarios[] = $filaUsuario;
    }

    mysqli_stmt_close($stmtUsuarios);

} else {

    error_log(
        "Error cargando usuarios: " .
        mysqli_error($conexion)
    );

    $error =
        $error !== ""
            ? $error
            : "No fue posible cargar los usuarios.";
}


/*
|--------------------------------------------------------------------------
| ESTADÍSTICAS
|--------------------------------------------------------------------------
*/

$totalBloqueados = 0;

$sqlBloqueados = "
    SELECT COUNT(*) AS total
    FROM seguridad_cuenta
    WHERE cuenta_bloqueada = TRUE
";

$resultadoBloqueados = mysqli_query(
    $conexion,
    $sqlBloqueados
);

if ($resultadoBloqueados) {

    $filaBloqueados =
        mysqli_fetch_assoc(
            $resultadoBloqueados
        );

    $totalBloqueados =
        (int) ($filaBloqueados["total"] ?? 0);
}

$totalRoles = count($roles);
/*
|--------------------------------------------------------------------------
| LISTAR USUARIOS
|--------------------------------------------------------------------------
*/

$usuarios = [];

$sqlUsuarios = "
    SELECT
        ur.id_usuarios_rol,
        ur.usuario,
        u.nombre,
        u.correo,
        u.telefono,
        r.nombre_rol,
        COALESCE(sc.intentos_fallidos, 0)
            AS intentos_fallidos,
        COALESCE(sc.cuenta_bloqueada, 0)
            AS cuenta_bloqueada,
        sc.fecha_bloqueo
    FROM usuarios_rol ur
    INNER JOIN usuario u
        ON u.id_usuario = ur.id_usuario
    INNER JOIN rol r
        ON r.id_rol = ur.id_rol
    LEFT JOIN seguridad_cuenta sc
        ON sc.id_usuarios_rol =
           ur.id_usuarios_rol
    ORDER BY
        u.nombre,
        ur.usuario
";

$resultadoUsuarios =
    mysqli_query(
        $conexion,
        $sqlUsuarios
    );

if ($resultadoUsuarios) {

    while (
        $filaUsuario =
            mysqli_fetch_assoc(
                $resultadoUsuarios
            )
    ) {
        $usuarios[] =
            $filaUsuario;
    }

} else {

    error_log(
        "Error cargando usuarios: " .
        mysqli_error($conexion)
    );

    $error =
        $error !== ""
            ? $error
            : "No fue posible cargar los usuarios.";
}

/*
|--------------------------------------------------------------------------
| ESTADÍSTICAS
|--------------------------------------------------------------------------
*/

$totalUsuarios =
    count($usuarios);

$totalBloqueados =
    count(
        array_filter(
            $usuarios,
            static fn(array $usuario): bool =>
                (int) $usuario["cuenta_bloqueada"] === 1,
        )
    );

$totalRoles =
    count($roles);

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
        Usuarios y roles | EcoFauna
    </title>

    <link
        rel="preconnect"
        href="https://fonts.googleapis.com"
    >

    <link
        rel="preconnect"
        href="https://fonts.gstatic.com"
        crossorigin
    >

    <link
        href="https://fonts.googleapis.com/css2?family=DM+Serif+Display&family=Poppins:wght@400;500;600;700&display=swap"
        rel="stylesheet"
    >

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <link
        rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css"
    >

    <link
        rel="stylesheet"
        href="../css/administracion.css"
    >

    <style>

        /*
        |--------------------------------------------------------------------------
        | MODAL DESBLOQUEAR
        |--------------------------------------------------------------------------
        */
/*
|--------------------------------------------------------------------------
| PAGINACIÓN DE USUARIOS
|--------------------------------------------------------------------------
*/

.paginacion-usuarios {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 20px;
    padding: 20px 24px;
    border-top: 1px solid #edf0ed;
    background: #fcfdfc;
}

.paginacion-info {
    color: #6b766f;
    font-size: 14px;
}

.paginacion-info strong {
    color: #304734;
    font-weight: 700;
}


/*
|--------------------------------------------------------------------------
| BOTONES PAGINACIÓN
|--------------------------------------------------------------------------
*/

.paginacion-usuarios .pagination {
    display: flex;
    align-items: center;
    gap: 5px;
}

.paginacion-usuarios .page-link {
    width: 38px;
    height: 38px;
    padding: 0;

    display: flex;
    align-items: center;
    justify-content: center;

    border: 1px solid #dce4dd;
    border-radius: 10px !important;

    background: #ffffff;
    color: #304734;

    font-size: 14px;
    font-weight: 600;

    transition:
        background 0.2s ease,
        color 0.2s ease,
        border-color 0.2s ease,
        transform 0.2s ease;
}

.paginacion-usuarios .page-link:hover {
    background: #e8efe2;
    border-color: #91a27f;
    color: #304734;
    transform: translateY(-1px);
}


/*
|--------------------------------------------------------------------------
| PÁGINA ACTIVA
|--------------------------------------------------------------------------
*/

.paginacion-usuarios .page-item.active .page-link {
    background: #607754;
    border-color: #607754;
    color: #ffffff;
}

.paginacion-usuarios .page-item.active .page-link:hover {
    background: #304734;
    border-color: #304734;
    color: #ffffff;
}


/*
|--------------------------------------------------------------------------
| DESHABILITADO
|--------------------------------------------------------------------------
*/

.paginacion-usuarios .page-item.disabled .page-link {
    background: #f3f5f3;
    border-color: #e5e9e6;
    color: #a4ada7;
    cursor: not-allowed;
    transform: none;
}


/*
|--------------------------------------------------------------------------
| RESPONSIVE
|--------------------------------------------------------------------------
*/

@media (max-width: 768px) {

    .paginacion-usuarios {
        flex-direction: column;
        align-items: center;
        text-align: center;
    }

    .paginacion-usuarios .pagination {
        justify-content: center;
        flex-wrap: wrap;
    }

}


@media (max-width: 480px) {

    .paginacion-usuarios {
        padding: 16px;
    }

    .paginacion-usuarios .page-link {
        width: 34px;
        height: 34px;
        font-size: 13px;
    }

}
        .modal-desbloquear .modal-content {
            border: none;
            border-radius: 24px;
            overflow: hidden;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.18);
        }

        .modal-desbloquear .modal-header {
            border: none;
            padding: 25px 28px 10px;
        }

        .modal-desbloquear .modal-body {
            padding: 10px 28px 25px;
        }

        .modal-desbloquear .modal-footer {
            border-top: 1px solid #eeeeee;
            padding: 18px 28px;
            gap: 10px;
        }

        .icono-modal-desbloquear {
            width: 58px;
            height: 58px;
            border-radius: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #eaf7ef;
            color: #198754;
            font-size: 27px;
            margin-bottom: 15px;
        }

        .modal-desbloquear h5 {
            font-family: "DM Serif Display", serif;
            font-size: 28px;
            color: #26382c;
            margin: 0;
        }

        .modal-desbloquear p {
            color: #68756d;
            margin: 8px 0 0;
            line-height: 1.6;
        }

        .nombre-desbloquear {
            font-weight: 700;
            color: #26382c;
        }

        .btn-modal-cancelar {
            border: none;
            background: #f1f3f2;
            color: #4d5a52;
            border-radius: 12px;
            padding: 10px 20px;
            font-weight: 600;
        }

        .btn-modal-cancelar:hover {
            background: #e5e9e6;
            color: #26382c;
        }

        .btn-modal-confirmar {
            border: none;
            background: #198754;
            color: white;
            border-radius: 12px;
            padding: 10px 20px;
            font-weight: 600;
        }

        .btn-modal-confirmar:hover {
            background: #157347;
            color: white;
        }

        .btn-tabla.desbloquear {
            border: none;
            cursor: pointer;
        }

        .acciones-tabla form {
            margin: 0;
        }

        .acciones-tabla {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 7px;
        }

    </style>

    <link rel="stylesheet" href="../css/crud-modern.css?v=1">
    <link rel="stylesheet" href="../css/ecofauna-unified.css?v=1">
</head>

<body class="ecofauna-unified crud-modern">



<nav class="navbar-admin-modulo">

    <div class="container barra-admin-modulo">

        <a
            href="../admin.php"
            class="marca-admin-modulo"
        >

            <img
                src="../img/logoEcoFauna.png"
                alt="EcoFauna"
            >

            <span>
                <strong>EcoFauna</strong>
                <small>Usuarios y seguridad</small>
            </span>

        </a>

        <div class="acciones-navbar">

            <a
                href="permisos.php"
                class="btn-secundario-admin"
            >
                <i class="bi bi-shield-check"></i>
                Permisos
            </a>

            <a
                href="../admin.php"
                class="btn-volver-admin"
            >
                <i class="bi bi-arrow-left"></i>
                Administración
            </a>

        </div>

    </div>

</nav>


<header class="hero-modulo-admin">

    <div class="container">

        <span class="etiqueta-modulo">
            <i class="bi bi-people-fill"></i>
            Control de acceso
        </span>

        <h1>
            Usuarios y roles
        </h1>

        <p>
            Crea cuentas, actualiza sus datos,
            cambia el rol asignado y rehabilita
            accesos bloqueados.
        </p>

    </div>

</header>


<main class="container contenido-modulo-admin">

    <section class="resumen-admin-grid">

        <article>

            <i class="bi bi-people"></i>

            <div>
                <small>Usuarios</small>
                <strong>
                    <?= $totalUsuarios ?>
                </strong>
            </div>

        </article>

        <article>

            <i class="bi bi-person-badge"></i>

            <div>
                <small>Roles disponibles</small>
                <strong>
                    <?= $totalRoles ?>
                </strong>
            </div>

        </article>

        <article>

            <i class="bi bi-lock-fill"></i>

            <div>
                <small>Cuentas bloqueadas</small>
                <strong>
                    <?= $totalBloqueados ?>
                </strong>
            </div>

        </article>

    </section>


    <?php if ($mensaje !== ""): ?>

        <div
            class="alert alert-<?= escaparUsuarios($tipoMensaje) ?> alert-dismissible fade show rounded-4 shadow-sm"
            role="alert"
        >

            <?= escaparUsuarios($mensaje) ?>

            <button
                type="button"
                class="btn-close"
                data-bs-dismiss="alert"
                aria-label="Cerrar"
            ></button>

        </div>

    <?php endif; ?>


    <?php if ($error !== ""): ?>

        <div
            class="alert alert-danger rounded-4 shadow-sm"
            role="alert"
        >

            <i class="bi bi-exclamation-triangle-fill me-2"></i>

            <?= escaparUsuarios($error) ?>

        </div>

    <?php endif; ?>


    <?php if ($usuarioEditar): ?>

        <section
            class="tarjeta-modulo-admin mb-4"
            id="editar-usuario"
        >

            <div class="encabezado-tarjeta-modulo">

                <div>

                    <small>
                        Cuenta seleccionada
                    </small>

                    <h2>
                        Editar
                        <?= escaparUsuarios(
                            $usuarioEditar["nombre"]
                        ) ?>
                    </h2>

                </div>

                <a
                    href="usuarios.php"
                    class="btn-cerrar-edicion"
                >
                    <i class="bi bi-x-lg"></i>
                    Cerrar
                </a>

            </div>


            <form
                method="POST"
                class="formulario-admin-grid"
            >

                <?= campoCsrfSesion(
                    "administracion_usuarios"
                ) ?>

                <input
                    type="hidden"
                    name="accion"
                    value="actualizar"
                >

                <input
                    type="hidden"
                    name="id_usuarios_rol"
                    value="<?= (int) $usuarioEditar["id_usuarios_rol"] ?>"
                >


                <div class="campo-admin campo-doble">

                    <label for="editarNombre">
                        Nombre completo
                    </label>

                    <input
                        id="editarNombre"
                        class="form-control"
                        type="text"
                        name="nombre"
                        maxlength="100"
                        required
                        value="<?= escaparUsuarios(
                            $usuarioEditar["nombre"]
                        ) ?>"
                    >

                </div>


                <div class="campo-admin">

                    <label for="editarCorreo">
                        Correo
                    </label>

                    <input
                        id="editarCorreo"
                        class="form-control"
                        type="email"
                        name="correo"
                        maxlength="100"
                        required
                        value="<?= escaparUsuarios(
                            $usuarioEditar["correo"]
                        ) ?>"
                    >

                </div>


                <div class="campo-admin">

                    <label for="editarTelefono">
                        Teléfono
                    </label>

                    <input
                        id="editarTelefono"
                        class="form-control"
                        type="text"
                        name="telefono"
                        maxlength="20"
                        value="<?= escaparUsuarios(
                            $usuarioEditar["telefono"]
                        ) ?>"
                    >

                </div>


                <div class="campo-admin">

                    <label for="editarFecha">
                        Fecha de nacimiento
                    </label>

                    <input
                        id="editarFecha"
                        class="form-control"
                        type="date"
                        name="fecha_nacimiento"
                        max="<?= date("Y-m-d") ?>"
                        value="<?= escaparUsuarios(
                            $usuarioEditar["fecha_nacimiento"]
                        ) ?>"
                    >

                </div>


                <div class="campo-admin">

                    <label for="editarUsuario">
                        Nombre de usuario
                    </label>

                    <input
                        id="editarUsuario"
                        class="form-control"
                        type="text"
                        name="usuario_acceso"
                        minlength="3"
                        maxlength="50"
                        required
                        value="<?= escaparUsuarios(
                            $usuarioEditar["usuario"]
                        ) ?>"
                    >

                </div>


                <div class="campo-admin">

                    <label for="editarRol">
                        Rol
                    </label>

                    <select
                        id="editarRol"
                        class="form-select"
                        name="id_rol"
                        required
                    >

                        <?php foreach ($roles as $rol): ?>

                            <option
                                value="<?= (int) $rol["id_rol"] ?>"
                                <?= (int) $rol["id_rol"] ===
                                (int) $usuarioEditar["id_rol"]
                                    ? "selected"
                                    : "" ?>
                            >

                                <?= escaparUsuarios(
                                    $rol["nombre_rol"]
                                ) ?>

                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>


                <div class="campo-admin campo-doble">

                    <label for="editarContrasena">
                        Contraseña nueva
                        <span>(opcional)</span>
                    </label>

                    <input
                        id="editarContrasena"
                        class="form-control"
                        type="password"
                        name="contrasena_nueva"
                        minlength="8"
                        maxlength="72"
                        autocomplete="new-password"
                        placeholder="Déjala vacía para conservar la actual"
                    >

                </div>


                <div class="acciones-formulario campo-completo">

                    <a
                        href="usuarios.php"
                        class="btn-cancelar-admin"
                    >
                        Cancelar
                    </a>

                    <button
                        type="submit"
                        class="btn-guardar-admin"
                    >
                        <i class="bi bi-check2-circle"></i>
                        Guardar cambios
                    </button>

                </div>

            </form>

        </section>

    <?php endif; ?>


    <div class="columnas-admin">


        <!-- =========================================================
             CREAR USUARIO
        ========================================================== -->

        <section class="tarjeta-modulo-admin">

            <div class="encabezado-tarjeta-modulo">

                <div>

                    <small>
                        Nueva cuenta
                    </small>

                    <h2>
                        Registrar usuario
                    </h2>

                </div>

                <span class="icono-encabezado">
                    <i class="bi bi-person-plus-fill"></i>
                </span>

            </div>


            <form
                method="POST"
                class="formulario-admin-grid formulario-compacto"
            >

                <?= campoCsrfSesion(
                    "administracion_usuarios"
                ) ?>

                <input
                    type="hidden"
                    name="accion"
                    value="crear"
                >


                <div class="campo-admin campo-completo">

                    <label for="crearNombre">
                        Nombre completo
                    </label>

                    <input
                        id="crearNombre"
                        class="form-control"
                        type="text"
                        name="nombre"
                        maxlength="100"
                        required
                        value="<?= escaparUsuarios(
                            $datosCrear["nombre"]
                        ) ?>"
                    >

                </div>


                <div class="campo-admin campo-completo">

                    <label for="crearCorreo">
                        Correo
                    </label>

                    <input
                        id="crearCorreo"
                        class="form-control"
                        type="email"
                        name="correo"
                        maxlength="100"
                        required
                        value="<?= escaparUsuarios(
                            $datosCrear["correo"]
                        ) ?>"
                    >

                </div>


                <div class="campo-admin">

                    <label for="crearTelefono">
                        Teléfono
                    </label>

                    <input
                        id="crearTelefono"
                        class="form-control"
                        type="text"
                        name="telefono"
                        maxlength="20"
                        value="<?= escaparUsuarios(
                            $datosCrear["telefono"]
                        ) ?>"
                    >

                </div>


                <div class="campo-admin">

                    <label for="crearFecha">
                        Nacimiento
                    </label>

                    <input
                        id="crearFecha"
                        class="form-control"
                        type="date"
                        name="fecha_nacimiento"
                        max="<?= date("Y-m-d") ?>"
                        value="<?= escaparUsuarios(
                            $datosCrear["fecha_nacimiento"]
                        ) ?>"
                    >

                </div>


                <div class="campo-admin">

                    <label for="crearUsuario">
                        Usuario
                    </label>

                    <input
                        id="crearUsuario"
                        class="form-control"
                        type="text"
                        name="usuario_nuevo"
                        minlength="3"
                        maxlength="50"
                        required
                        value="<?= escaparUsuarios(
                            $datosCrear["usuario"]
                        ) ?>"
                    >

                </div>


                <div class="campo-admin">

                    <label for="crearRol">
                        Rol
                    </label>

                    <select
                        id="crearRol"
                        class="form-select"
                        name="id_rol"
                        required
                    >

                        <option value="">
                            Seleccionar
                        </option>

                        <?php foreach ($roles as $rol): ?>

                            <option
                                value="<?= (int) $rol["id_rol"] ?>"
                                <?= (int) $datosCrear["id_rol"] ===
                                (int) $rol["id_rol"]
                                    ? "selected"
                                    : "" ?>
                            >

                                <?= escaparUsuarios(
                                    $rol["nombre_rol"]
                                ) ?>

                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>


                <div class="campo-admin campo-completo">

                    <label for="crearContrasena">
                        Contraseña temporal
                    </label>

                    <input
                        id="crearContrasena"
                        class="form-control"
                        type="password"
                        name="contrasena"
                        minlength="8"
                        maxlength="72"
                        autocomplete="new-password"
                        required
                    >

                    <small>
                        Debe contener entre 8 y 72 caracteres.
                    </small>

                </div>


                <button
                    type="submit"
                    class="btn-guardar-admin campo-completo"
                >

                    <i class="bi bi-person-check-fill"></i>

                    Crear usuario

                </button>

            </form>

        </section>


        <!-- =========================================================
             TABLA
        ========================================================== -->

        <section
            class="tarjeta-modulo-admin tabla-usuarios-card"
        >

            <div class="encabezado-tarjeta-modulo">

                <div>

                    <small>
                        Cuentas registradas
                    </small>

                    <h2>
                        Usuarios del sistema
                    </h2>

                </div>

                <span class="contador-registros">
                    <?= $totalUsuarios ?> registros
                </span>

            </div>


            <div class="table-responsive">

                <table
                    class="table tabla-admin align-middle mb-0"
                >

                    <thead>

                        <tr>

                            <th>
                                Persona
                            </th>

                            <th>
                                Usuario
                            </th>

                            <th>
                                Rol
                            </th>

                            <th>
                                Estado
                            </th>

                            <th class="text-end">
                                Acciones
                            </th>

                        </tr>

                    </thead>


                    <tbody>

                        <?php if (!$usuarios): ?>

                            <tr>

                                <td
                                    colspan="5"
                                    class="estado-vacio-admin"
                                >

                                    <i class="bi bi-people"></i>

                                    No hay usuarios registrados.

                                </td>

                            </tr>

                        <?php else: ?>


                            <?php foreach ($usuarios as $usuarioFila): ?>

                                <?php

                                $bloqueada =
                                    (int) $usuarioFila["cuenta_bloqueada"] === 1;

                                ?>

                                <tr>


                                    <td>

                                        <div class="persona-tabla">

                                            <span>

                                                <?= escaparUsuarios(
                                                    mb_strtoupper(
                                                        mb_substr(
                                                            $usuarioFila["nombre"],
                                                            0,
                                                            1
                                                        )
                                                    )
                                                ) ?>

                                            </span>


                                            <div>

                                                <strong>
                                                    <?= escaparUsuarios(
                                                        $usuarioFila["nombre"]
                                                    ) ?>
                                                </strong>

                                                <small>
                                                    <?= escaparUsuarios(
                                                        $usuarioFila["correo"]
                                                    ) ?>
                                                </small>

                                            </div>

                                        </div>

                                    </td>


                                    <td>

                                        <code>
                                            <?= escaparUsuarios(
                                                $usuarioFila["usuario"]
                                            ) ?>
                                        </code>

                                    </td>


                                    <td>

                                        <span
                                            class="badge-rol badge-<?= escaparUsuarios(
                                                mb_strtolower(
                                                    $usuarioFila["nombre_rol"]
                                                )
                                            ) ?>"
                                        >

                                            <?= escaparUsuarios(
                                                $usuarioFila["nombre_rol"]
                                            ) ?>

                                        </span>

                                    </td>


                                    <td>

                                        <span
                                            class="badge-estado <?= $bloqueada ? "bloqueada" : "activa" ?>"
                                        >

                                            <i
                                                class="bi <?= $bloqueada
                                                    ? "bi-lock-fill"
                                                    : "bi-check-circle-fill" ?>"
                                            ></i>

                                            <?= $bloqueada
                                                ? "Bloqueada"
                                                : "Activa" ?>

                                        </span>


                                        <?php if (
                                            (int) $usuarioFila["intentos_fallidos"] > 0
                                        ): ?>

                                            <small class="intentos-texto">

                                                <?= (int) $usuarioFila["intentos_fallidos"] ?>

                                                intento(s)

                                            </small>

                                        <?php endif; ?>

                                    </td>


                                    <td>

                                        <div class="acciones-tabla">


                                            <!-- EDITAR -->

                                            <a
                                                href="usuarios.php?editar=<?= (int) $usuarioFila["id_usuarios_rol"] ?>#editar-usuario"
                                                class="btn-tabla editar"
                                                title="Editar usuario"
                                            >

                                                <i class="bi bi-pencil-square"></i>

                                            </a>


                                            <!-- DESBLOQUEAR -->

                                            <?php if (
                                                $bloqueada ||
                                                (int) $usuarioFila["intentos_fallidos"] > 0
                                            ): ?>

                                                <form
                                                    method="POST"
                                                    class="form-desbloquear"
                                                >

                                                    <?= campoCsrfSesion(
                                                        "administracion_usuarios"
                                                    ) ?>

                                                    <input
                                                        type="hidden"
                                                        name="accion"
                                                        value="desbloquear"
                                                    >

                                                    <input
                                                        type="hidden"
                                                        name="id_usuarios_rol"
                                                        value="<?= (int) $usuarioFila["id_usuarios_rol"] ?>"
                                                    >


                                                    <button
                                                        type="button"
                                                        class="btn-tabla desbloquear btn-abrir-desbloqueo"
                                                        title="Desbloquear cuenta"
                                                        data-id="<?= (int) $usuarioFila["id_usuarios_rol"] ?>"
                                                        data-nombre="<?= escaparUsuarios($usuarioFila["nombre"]) ?>"
                                                    >

                                                        <i class="bi bi-unlock-fill"></i>

                                                    </button>

                                                </form>

                                            <?php endif; ?>


                                        </div>

                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        <?php endif; ?>

                    </tbody>

                </table>

                       </div>


            <!-- =====================================================
                 PAGINACIÓN
            ====================================================== -->

            <?php if ($totalPaginas > 1): ?>

                <div class="paginacion-usuarios">

                    <div class="paginacion-info">

                        Mostrando

                        <strong>
                            <?= $totalUsuarios > 0
                                ? $offset + 1
                                : 0 ?>
                        </strong>

                        -
                        
                        <strong>
                            <?= min(
                                $offset + $registrosPorPagina,
                                $totalUsuarios
                            ) ?>
                        </strong>

                        de

                        <strong>
                            <?= $totalUsuarios ?>
                        </strong>

                        usuarios

                    </div>


                    <nav
                        aria-label="Paginación de usuarios"
                    >

                        <ul class="pagination mb-0">


                            <!-- ANTERIOR -->

                            <li
                                class="page-item
                                <?= $paginaActual <= 1
                                    ? "disabled"
                                    : "" ?>"
                            >

                                <?php if ($paginaActual > 1): ?>

                                    <a
                                        class="page-link"
                                        href="usuarios.php?pagina=<?= $paginaActual - 1 ?>"
                                        aria-label="Página anterior"
                                    >

                                        <i class="bi bi-chevron-left"></i>

                                    </a>

                                <?php else: ?>

                                    <span class="page-link">

                                        <i class="bi bi-chevron-left"></i>

                                    </span>

                                <?php endif; ?>

                            </li>


                            <?php

                            /*
                             * Mostrar máximo 5 números
                             */

                            $inicioPagina = max(
                                1,
                                $paginaActual - 2
                            );

                            $finPagina = min(
                                $totalPaginas,
                                $paginaActual + 2
                            );

                            ?>


                            <!-- PRIMERA PÁGINA -->

                            <?php if ($inicioPagina > 1): ?>

                                <li class="page-item">

                                    <a
                                        class="page-link"
                                        href="usuarios.php?pagina=1"
                                    >
                                        1
                                    </a>

                                </li>


                                <?php if ($inicioPagina > 2): ?>

                                    <li
                                        class="page-item disabled"
                                    >

                                        <span class="page-link">
                                            ...
                                        </span>

                                    </li>

                                <?php endif; ?>

                            <?php endif; ?>


                            <!-- NÚMEROS -->

                            <?php for (
                                $pagina = $inicioPagina;
                                $pagina <= $finPagina;
                                $pagina++
                            ): ?>

                                <li
                                    class="page-item
                                    <?= $pagina === $paginaActual
                                        ? "active"
                                        : "" ?>"
                                >

                                    <a
                                        class="page-link"
                                        href="usuarios.php?pagina=<?= $pagina ?>"
                                    >

                                        <?= $pagina ?>

                                    </a>

                                </li>

                            <?php endfor; ?>


                            <!-- ÚLTIMA PÁGINA -->

                            <?php if ($finPagina < $totalPaginas): ?>

                                <?php if (
                                    $finPagina < $totalPaginas - 1
                                ): ?>

                                    <li
                                        class="page-item disabled"
                                    >

                                        <span class="page-link">
                                            ...
                                        </span>

                                    </li>

                                <?php endif; ?>


                                <li class="page-item">

                                    <a
                                        class="page-link"
                                        href="usuarios.php?pagina=<?= $totalPaginas ?>"
                                    >

                                        <?= $totalPaginas ?>

                                    </a>

                                </li>

                            <?php endif; ?>


                            <!-- SIGUIENTE -->

                            <li
                                class="page-item
                                <?= $paginaActual >= $totalPaginas
                                    ? "disabled"
                                    : "" ?>"
                            >

                                <?php if (
                                    $paginaActual < $totalPaginas
                                ): ?>

                                    <a
                                        class="page-link"
                                        href="usuarios.php?pagina=<?= $paginaActual + 1 ?>"
                                        aria-label="Página siguiente"
                                    >

                                        <i class="bi bi-chevron-right"></i>

                                    </a>

                                <?php else: ?>

                                    <span class="page-link">

                                        <i class="bi bi-chevron-right"></i>

                                    </span>

                                <?php endif; ?>

                            </li>


                        </ul>

                    </nav>

                </div>

            <?php endif; ?>


        </section>

    </div>

</main>


<!-- =============================================================
     MODAL DE CONFIRMACIÓN PARA DESBLOQUEAR
============================================================== -->

<div
    class="modal fade modal-desbloquear"
    id="modalDesbloquear"
    tabindex="-1"
    aria-labelledby="modalDesbloquearLabel"
    aria-hidden="true"
>

    <div
        class="modal-dialog modal-dialog-centered"
    >

        <div class="modal-content">


            <div class="modal-header">

                <div>

                    <div class="icono-modal-desbloquear">

                        <i class="bi bi-unlock-fill"></i>

                    </div>

                    <h5 id="modalDesbloquearLabel">
                        Desbloquear cuenta
                    </h5>

                </div>


                <button
                    type="button"
                    class="btn-close"
                    data-bs-dismiss="modal"
                    aria-label="Cerrar"
                ></button>

            </div>


            <div class="modal-body">

                <p>

                    ¿Deseas rehabilitar la cuenta de

                    <span
                        class="nombre-desbloquear"
                        id="nombreUsuarioDesbloquear"
                    >
                    </span>?

                    <br>

                    Se restablecerán los intentos fallidos
                    y la cuenta podrá iniciar sesión nuevamente.

                </p>

            </div>


            <div class="modal-footer">

                <button
                    type="button"
                    class="btn-modal-cancelar"
                    data-bs-dismiss="modal"
                >
                    Cancelar
                </button>


                <button
                    type="button"
                    class="btn-modal-confirmar"
                    id="btnConfirmarDesbloqueo"
                >

                    <i class="bi bi-unlock-fill me-1"></i>

                    Desbloquear cuenta

                </button>

            </div>

        </div>

    </div>

</div>


<footer class="footer-modulo-admin">

    <div class="container">

        <span>

            <i class="bi bi-shield-lock-fill"></i>

            EcoFauna — Administración

        </span>

        <small>
            Usuarios, roles y seguridad
        </small>

    </div>

</footer>


<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.bundle.min.js"
></script>


<script>

document.addEventListener("DOMContentLoaded", function () {

    const modalElemento =
        document.getElementById("modalDesbloquear");

    const modal =
        new bootstrap.Modal(modalElemento);

    const nombreElemento =
        document.getElementById(
            "nombreUsuarioDesbloquear"
        );

    const btnConfirmar =
        document.getElementById(
            "btnConfirmarDesbloqueo"
        );

    let formularioSeleccionado = null;


    /*
    |--------------------------------------------------------------------------
    | ABRIR MODAL
    |--------------------------------------------------------------------------
    */

    document.querySelectorAll(
        ".btn-abrir-desbloqueo"
    ).forEach(function (boton) {

        boton.addEventListener(
            "click",
            function () {

                formularioSeleccionado =
                    boton.closest("form");

                const nombre =
                    boton.dataset.nombre || "esta cuenta";

                nombreElemento.textContent =
                    nombre;

                modal.show();
            }
        );

    });


    /*
    |--------------------------------------------------------------------------
    | CONFIRMAR DESBLOQUEO
    |--------------------------------------------------------------------------
    */

    btnConfirmar.addEventListener(
        "click",
        function () {

            if (!formularioSeleccionado) {
                return;
            }

            btnConfirmar.disabled = true;

            btnConfirmar.innerHTML =
                '<span class="spinner-border spinner-border-sm me-2"></span>' +
                'Desbloqueando...';

            formularioSeleccionado.submit();

        }
    );


    /*
    |--------------------------------------------------------------------------
    | LIMPIAR MODAL
    |--------------------------------------------------------------------------
    */

    modalElemento.addEventListener(
        "hidden.bs.modal",
        function () {

            formularioSeleccionado = null;

            btnConfirmar.disabled = false;

            btnConfirmar.innerHTML =
                '<i class="bi bi-unlock-fill me-1"></i>' +
                'Desbloquear cuenta';

        }
    );

});

</script>


</body>
</html>