<?php

require_once __DIR__ . "/../../soporte/sesion.php";

require_once __DIR__ . "/../../../config/database.php";

if (
    $_SERVER["REQUEST_METHOD"] !== "POST" ||
    !tokenCsrfSesionValido("logout", $_POST["csrf_token"] ?? null)
) {
    header("Location: index.php");
    exit();
}

if (isset($_SESSION["id_login"], $_SESSION["usuario"])) {
    registrarBitacora(
        $_SESSION["usuario"],
        "LOGOUT",
        "usuarios_rol",
        "El usuario cerró sesión voluntariamente",
        (int) $_SESSION["id_login"],
    );
}

$_SESSION = [];
eliminarCookieSesion();
session_destroy();

header("Location: index.php");
exit();