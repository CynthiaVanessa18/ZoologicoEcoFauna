<?php

require_once __DIR__ . "/../../soporte/sesion.php";
require_once __DIR__ . "/../../../config/database.php";

/** @var mysqli $conexion */


if (!isset(
    $_SESSION["usuario"],
    $_SESSION["rol"],
    $_SESSION["id_login"],
    $_SESSION["id_usuario"],
)) {

    header("Location: ../index.php");
    exit();

}


$idUsuario = (int) $_SESSION["id_usuario"];
$rolUsuario = (string) $_SESSION["rol"];
$esCliente = $rolUsuario === "Cliente";


/* =====================================
   PAGINA DE RETORNO
===================================== */

$volver = $_SESSION["volver_direccion"] ?? "../mi_perfil.php";



/* =====================================
   CONSULTAR DATOS DEL USUARIO
===================================== */


$sql = "SELECT
            nombre,
            correo,
            telefono
        FROM usuario
        WHERE id_usuario = ?";


$stmtUsuario = mysqli_prepare($conexion, $sql);

mysqli_stmt_bind_param(
    $stmtUsuario,
    "i",
    $idUsuario
);

mysqli_stmt_execute($stmtUsuario);


$resultadoUsuario = mysqli_stmt_get_result($stmtUsuario);

$usuario = mysqli_fetch_assoc($resultadoUsuario);

mysqli_stmt_close($stmtUsuario);




/* =====================================
   CONSULTAR DIRECCION
===================================== */


$direccion = null;

if ($esCliente) {
    $sql = "SELECT *
            FROM direccion_entrega
            WHERE id_usuario = ?
            LIMIT 1";

    $stmtDireccion = mysqli_prepare($conexion, $sql);

    mysqli_stmt_bind_param(
        $stmtDireccion,
        "i",
        $idUsuario
    );

    mysqli_stmt_execute($stmtDireccion);

    $resultadoDireccion = mysqli_stmt_get_result($stmtDireccion);
    $direccion = mysqli_fetch_assoc($resultadoDireccion);
    mysqli_stmt_close($stmtDireccion);
}




/* =====================================
   GUARDAR INFORMACION
===================================== */


if ($_SERVER["REQUEST_METHOD"] === "POST") {


    if (!tokenCsrfSesionValido(
        "datos_personales",
        $_POST["csrf_token"] ?? null,
    )) {
        http_response_code(403);
        die("La solicitud expiró. Regresa al perfil e inténtalo nuevamente.");
    }


    $nombre = trim($_POST["nombre"] ?? "");

    $correo = trim($_POST["correo"] ?? "");

    $telefono = trim($_POST["telefono"] ?? "");


    if ($nombre === "" || $correo === "" || $telefono === "") {
        die("Debes completar los datos personales.");
    }
    if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
        die("El correo electrónico no tiene un formato válido.");
    }


    $nombreRecibe = $esCliente
        ? trim($_POST["nombre_recibe"] ?? "")
        : "";
    $provincia = $esCliente
        ? trim($_POST["provincia"] ?? "")
        : "";
    $canton = $esCliente
        ? trim($_POST["canton"] ?? "")
        : "";
    $distrito = $esCliente
        ? trim($_POST["distrito"] ?? "")
        : "";
    $direccionTexto = $esCliente
        ? trim($_POST["direccion"] ?? "")
        : "";

    if (
        $esCliente &&
        (
            $nombreRecibe === "" ||
            $provincia === "" ||
            $canton === "" ||
            $distrito === "" ||
            $direccionTexto === ""
        )
    ) {
        die("Debes completar la información de entrega.");
    }




    mysqli_begin_transaction($conexion);



    try {



        /* ACTUALIZAR USUARIO */


        $sql = "UPDATE usuario
                SET nombre = ?,
                    correo = ?,
                    telefono = ?
                WHERE id_usuario = ?";


        $stmt = mysqli_prepare($conexion, $sql);


        mysqli_stmt_bind_param(
            $stmt,
            "sssi",
            $nombre,
            $correo,
            $telefono,
            $idUsuario
        );


        if (!mysqli_stmt_execute($stmt)) {
            throw new RuntimeException(
                "No fue posible actualizar los datos personales."
            );
        }


        mysqli_stmt_close($stmt);





        if ($esCliente) {


        /* INSERTAR O ACTUALIZAR DIRECCION */


        if ($direccion) {


            $idDireccionRegistro = (int) $direccion["id_direccion"];
            $accionDireccion = "ACTUALIZAR DIRECCIÓN";



            $sql = "UPDATE direccion_entrega
                    SET nombre_recibe = ?,
                        provincia = ?,
                        canton = ?,
                        distrito = ?,
                        direccion = ?
                    WHERE id_usuario = ?";



            $stmt = mysqli_prepare($conexion, $sql);



            mysqli_stmt_bind_param(
                $stmt,
                "sssssi",
                $nombreRecibe,
                $provincia,
                $canton,
                $distrito,
                $direccionTexto,
                $idUsuario
            );



        } else {


            $idDireccionRegistro = null;
            $accionDireccion = "REGISTRAR DIRECCIÓN";



            $sql = "INSERT INTO direccion_entrega
                    (
                        id_usuario,
                        nombre_recibe,
                        provincia,
                        canton,
                        distrito,
                        direccion
                    )
                    VALUES
                    (?,?,?,?,?,?)";



            $stmt = mysqli_prepare($conexion, $sql);



            mysqli_stmt_bind_param(
                $stmt,
                "isssss",
                $idUsuario,
                $nombreRecibe,
                $provincia,
                $canton,
                $distrito,
                $direccionTexto
            );



        }




        if (!mysqli_stmt_execute($stmt)) {
            throw new RuntimeException(
                "No fue posible guardar la dirección de entrega."
            );
        }


        if ($idDireccionRegistro === null) {
            $idDireccionRegistro = mysqli_insert_id($conexion);
        }


        mysqli_stmt_close($stmt);


        }


        if (!registrarBitacora(
            $_SESSION["usuario"],
            "ACTUALIZAR DATOS PERSONALES",
            "usuario",
            "Se actualizaron los datos personales del usuario.",
            $idUsuario,
        )) {
            throw new RuntimeException(
                "No fue posible registrar los datos personales en la bitácora."
            );
        }


        if ($esCliente) {
            if (!registrarBitacora(
                $_SESSION["usuario"],
                $accionDireccion,
                "direccion_entrega",
                "Se guardó la información de entrega del cliente.",
                $idDireccionRegistro,
            )) {
                throw new RuntimeException(
                    "No fue posible registrar la dirección en la bitácora."
                );
            }
        }



        if (!mysqli_commit($conexion)) {
            throw new RuntimeException(
                "No fue posible confirmar los datos personales."
            );
        }


        renovarTokenCsrfSesion("datos_personales");



        $destino = $volver;


        unset($_SESSION["volver_direccion"]);



        header("Location: " . $destino);

        exit();




    } catch (Throwable $e) {



        mysqli_rollback($conexion);



        error_log($e->getMessage());


        die("Error al guardar la información.");



    }



}

?>
<!DOCTYPE html>
<html lang="es">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>
        <?= $esCliente ? "Información de entrega" : "Datos personales" ?>
    </title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/css/bootstrap.min.css" rel="stylesheet">

    <link rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">

    <link rel="stylesheet" href="../css/styleCliente.css">

    <link rel="stylesheet" href="../css/ecofauna-unified.css?v=1">
</head>

<body class="ecofauna-unified">


<div class="container py-5">

    <div class="row justify-content-center">

        <div class="col-lg-8">


            <div class="card shadow border-0">


                <div class="card-header bg-success text-white">

                    <h3 class="mb-0">

                        <i class="bi <?= $esCliente ? "bi-geo-alt-fill" : "bi-person-fill" ?>"></i>

                        <?= $esCliente ? "Información de entrega" : "Editar datos personales" ?>

                    </h3>

                </div>



                <div class="card-body">


                    <form method="POST">


                        <?= campoCsrfSesion("datos_personales") ?>


                        <h5 class="text-success mb-3">

                            <i class="bi bi-person-fill"></i>

                            Datos personales

                        </h5>



                        <div class="row">


                            <div class="col-md-6 mb-3">


                                <label class="form-label">
                                    Nombre
                                </label>


                                <input
                                    type="text"
                                    name="nombre"
                                    class="form-control"
                                    required
                                    value="<?= htmlspecialchars($usuario["nombre"] ?? "") ?>">


                            </div>



                            <div class="col-md-6 mb-3">


                                <label class="form-label">
                                    Correo electrónico
                                </label>


                                <input
                                    type="email"
                                    name="correo"
                                    class="form-control"
                                    required
                                    value="<?= htmlspecialchars($usuario["correo"] ?? "") ?>">


                            </div>



                            <div class="col-md-6 mb-4">


                                <label class="form-label">
                                    Teléfono
                                </label>


                                <input
                                    type="text"
                                    name="telefono"
                                    class="form-control"
                                    required
                                    value="<?= htmlspecialchars($usuario["telefono"] ?? "") ?>">


                            </div>


                        </div>



                        <?php if ($esCliente): ?>


                        <hr>



                        <h5 class="text-success mb-3">

                            <i class="bi bi-truck"></i>

                            Dirección de entrega

                        </h5>



                        <div class="mb-3">


                            <label class="form-label">
                                Nombre de quien recibe
                            </label>


                            <input
                                type="text"
                                name="nombre_recibe"
                                class="form-control"
                                required
                                value="<?= htmlspecialchars($direccion["nombre_recibe"] ?? "") ?>">


                        </div>






                        <div class="row">


                            <div class="col-md-4 mb-3">


                                <label class="form-label">
                                    Provincia
                                </label>


                                <input
                                    type="text"
                                    name="provincia"
                                    class="form-control"
                                    required
                                    value="<?= htmlspecialchars($direccion["provincia"] ?? "") ?>">


                            </div>



                            <div class="col-md-4 mb-3">


                                <label class="form-label">
                                    Cantón
                                </label>


                                <input
                                    type="text"
                                    name="canton"
                                    class="form-control"
                                    required
                                    value="<?= htmlspecialchars($direccion["canton"] ?? "") ?>">


                            </div>



                            <div class="col-md-4 mb-3">


                                <label class="form-label">
                                    Distrito
                                </label>


                                <input
                                    type="text"
                                    name="distrito"
                                    class="form-control"
                                    required
                                    value="<?= htmlspecialchars($direccion["distrito"] ?? "") ?>">


                            </div>


                        </div>




                        <div class="mb-4">


                            <label class="form-label">
                                Dirección exacta
                            </label>


                            <textarea
                                name="direccion"
                                class="form-control"
                                rows="4"
                                required><?= htmlspecialchars($direccion["direccion"] ?? "") ?></textarea>


                        </div>




                        <?php endif; ?>


                        <div class="d-flex justify-content-between">


                            <a href="<?= htmlspecialchars($volver) ?>"
                               class="btn btn-secondary">

                                <i class="bi bi-arrow-left"></i>

                                Regresar

                            </a>



                            <button
                                type="submit"
                                class="btn btn-success">


                                <i class="bi bi-check-circle-fill"></i>


                                <?= !$esCliente
                                    ? "Actualizar datos"
                                    : ($direccion
                                        ? "Actualizar información"
                                        : "Guardar información") ?>


                            </button>


                        </div>


                    </form>


                </div>


            </div>


        </div>


    </div>


</div>



<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.bundle.min.js"></script>


</body>

</html>