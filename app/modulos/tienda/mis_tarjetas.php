<?php

require_once __DIR__ . "/../../soporte/sesion.php";
require_once __DIR__ . "/../../../config/database.php";
require_once __DIR__ . "/../../soporte/funciones_tienda.php";

/** @var mysqli $conexion */


/*
|--------------------------------------------------------------------------
| CONTROL DE ACCESO
|--------------------------------------------------------------------------
*/

if (!clienteTiendaAutenticado()) {

    header("Location: ../index.php");
    exit();

}


$id_usuario = (int) $_SESSION["id_usuario"];


/*
|--------------------------------------------------------------------------
| CLAVE DE CIFRADO
|--------------------------------------------------------------------------
|
| DEBE SER EXACTAMENTE LA MISMA UTILIZADA
| PARA CIFRAR numero_tarjeta EN tarjeta_banco
| Y tarjeta_cliente.
|
*/

$CLAVE_CIFRADO = claveCifradoEcoFauna();


/*
|--------------------------------------------------------------------------
| FUNCIÓN PARA DESCIFRAR
|--------------------------------------------------------------------------
*/

function descifrarDato($datoCifrado, $clave)
{

    $metodo = "aes-256-gcm";


    /*
    | Convertir desde Base64
    */

    $datos = base64_decode(
        $datoCifrado,
        true
    );


    if ($datos === false) {

        return false;

    }


    /*
    | Estructura:
    |
    | 12 bytes = IV
    | 16 bytes = TAG
    | resto    = contenido cifrado
    */

    if (strlen($datos) < 28) {

        return false;

    }


    /*
    | IV
    */

    $iv = substr(
        $datos,
        0,
        12
    );


    /*
    | TAG
    */

    $tag = substr(
        $datos,
        12,
        16
    );


    /*
    | Cifrado
    */

    $cifrado = substr(
        $datos,
        28
    );


    /*
    | Generar la misma clave
    */

    $key = hash(
        "sha256",
        $clave,
        true
    );


    /*
    | Descifrar
    */

    $descifrado = openssl_decrypt(
        $cifrado,
        $metodo,
        $key,
        OPENSSL_RAW_DATA,
        $iv,
        $tag
    );


    return $descifrado;
}


/*
|--------------------------------------------------------------------------
| TOKEN CSRF
|--------------------------------------------------------------------------
*/

if (!isset($_SESSION["csrf_token_tarjeta"])) {

    $_SESSION["csrf_token_tarjeta"] = bin2hex(
        random_bytes(32)
    );

}


/*
|--------------------------------------------------------------------------
| OCULTAR TARJETA
|--------------------------------------------------------------------------
|
| IMPORTANTE:
|
| NO SE ELIMINA FÍSICAMENTE.
|
| Se cambia:
|
| Activa → Inactiva
|
| Esto evita el error:
|
| pago_tarjeta_ibfk_1
|
| porque los pagos históricos siguen
| apuntando a la tarjeta.
|
*/

if (
    $_SERVER["REQUEST_METHOD"] === "POST" &&
    isset($_POST["id_tarjeta"])
) {


    $id_tarjeta = (int) $_POST["id_tarjeta"];


    /*
    |--------------------------------------------------------------------------
    | VALIDAR ID
    |--------------------------------------------------------------------------
    */

    if ($id_tarjeta <= 0) {

        $_SESSION["mensaje_tarjeta"] = [
            "tipo" => "danger",
            "texto" => "La tarjeta seleccionada no es válida."
        ];


        header(
            "Location: " . $_SERVER["PHP_SELF"]
        );

        exit();

    }


    /*
    |--------------------------------------------------------------------------
    | VALIDAR CSRF
    |--------------------------------------------------------------------------
    */

    if (
        !isset($_POST["csrf_token"]) ||
        !isset($_SESSION["csrf_token_tarjeta"]) ||
        !hash_equals(
            $_SESSION["csrf_token_tarjeta"],
            $_POST["csrf_token"]
        )
    ) {

        $_SESSION["mensaje_tarjeta"] = [
            "tipo" => "danger",
            "texto" => "La solicitud no es válida o ha expirado."
        ];


        header(
            "Location: " . $_SERVER["PHP_SELF"]
        );

        exit();

    }


    /*
    |--------------------------------------------------------------------------
    | VERIFICAR QUE LA TARJETA PERTENECE AL USUARIO
    |--------------------------------------------------------------------------
    */

    $sqlVerificar = "

        SELECT
            id_tarjeta,
            estado

        FROM tarjeta_cliente

        WHERE id_tarjeta = ?
          AND id_usuario = ?

        LIMIT 1

    ";


    $stmtVerificar = mysqli_prepare(
        $conexion,
        $sqlVerificar
    );


    if (!$stmtVerificar) {

        $_SESSION["mensaje_tarjeta"] = [
            "tipo" => "danger",
            "texto" => "No se pudo verificar la tarjeta."
        ];


        header(
            "Location: " . $_SERVER["PHP_SELF"]
        );

        exit();

    }


    mysqli_stmt_bind_param(
        $stmtVerificar,
        "ii",
        $id_tarjeta,
        $id_usuario
    );


    if (
        !mysqli_stmt_execute(
            $stmtVerificar
        )
    ) {

        mysqli_stmt_close(
            $stmtVerificar
        );


        $_SESSION["mensaje_tarjeta"] = [
            "tipo" => "danger",
            "texto" => "No se pudo verificar la tarjeta."
        ];


        header(
            "Location: " . $_SERVER["PHP_SELF"]
        );

        exit();

    }


    $resultadoVerificar =
        mysqli_stmt_get_result(
            $stmtVerificar
        );


    /*
    |--------------------------------------------------------------------------
    | TARJETA NO ENCONTRADA
    |--------------------------------------------------------------------------
    */

    if (
        mysqli_num_rows(
            $resultadoVerificar
        ) === 0
    ) {

        mysqli_stmt_close(
            $stmtVerificar
        );


        $_SESSION["mensaje_tarjeta"] = [
            "tipo" => "danger",
            "texto" => "La tarjeta no existe o no pertenece a tu cuenta."
        ];


        header(
            "Location: " . $_SERVER["PHP_SELF"]
        );

        exit();

    }


    $tarjetaVerificada =
        mysqli_fetch_assoc(
            $resultadoVerificar
        );


    mysqli_stmt_close(
        $stmtVerificar
    );


    /*
    |--------------------------------------------------------------------------
    | VERIFICAR ESTADO
    |--------------------------------------------------------------------------
    */

    if (
        $tarjetaVerificada["estado"] !== "Activa"
    ) {

        $_SESSION["mensaje_tarjeta"] = [
            "tipo" => "warning",
            "texto" => "La tarjeta ya se encuentra oculta."
        ];


        header(
            "Location: " . $_SERVER["PHP_SELF"]
        );

        exit();

    }


    /*
    |--------------------------------------------------------------------------
    | CAMBIAR ESTADO A INACTIVA
    |--------------------------------------------------------------------------
    |
    | AQUÍ ESTÁ LA CORRECCIÓN PRINCIPAL.
    |
    | NO usamos DELETE.
    |
    */

    $sqlOcultar = "

        UPDATE tarjeta_cliente

        SET estado = 'Inactiva'

        WHERE id_tarjeta = ?
          AND id_usuario = ?
          AND estado = 'Activa'

    ";


    $stmtOcultar = mysqli_prepare(
        $conexion,
        $sqlOcultar
    );


    if (!$stmtOcultar) {

        $_SESSION["mensaje_tarjeta"] = [
            "tipo" => "danger",
            "texto" => "No se pudo preparar la operación."
        ];


        header(
            "Location: " . $_SERVER["PHP_SELF"]
        );

        exit();

    }


    mysqli_stmt_bind_param(
        $stmtOcultar,
        "ii",
        $id_tarjeta,
        $id_usuario
    );


    /*
    |--------------------------------------------------------------------------
    | EJECUTAR
    |--------------------------------------------------------------------------
    */

    if (
        mysqli_stmt_execute(
            $stmtOcultar
        )
    ) {


        if (
            mysqli_stmt_affected_rows(
                $stmtOcultar
            ) > 0
        ) {


            $_SESSION["mensaje_tarjeta"] = [
                "tipo" => "success",
                "texto" => "La tarjeta fue eliminada correctamente."
            ];


        } else {


            $_SESSION["mensaje_tarjeta"] = [
                "tipo" => "warning",
                "texto" => "La tarjeta ya no se encuentra activa."
            ];

        }


    } else {


        $_SESSION["mensaje_tarjeta"] = [
            "tipo" => "danger",
            "texto" => "No se pudo ocultar la tarjeta."
        ];

    }


    mysqli_stmt_close(
        $stmtOcultar
    );


    /*
    |--------------------------------------------------------------------------
    | REDIRECCIÓN
    |--------------------------------------------------------------------------
    |
    | Evita volver a enviar el POST.
    |
    */

    header(
        "Location: " . $_SERVER["PHP_SELF"]
    );

    exit();

}


/*
|--------------------------------------------------------------------------
| CONSULTAR TARJETAS ACTIVAS
|--------------------------------------------------------------------------
|
| IMPORTANTE:
|
| Solo mostramos las tarjetas:
|
| estado = Activa
|
| Las Inactivas permanecen en BD para
| conservar el historial de pagos.
|
*/

$sql = "

    SELECT

        tc.id_tarjeta,

        tc.id_tarjeta_banco,

        tc.numero_tarjeta,

        tc.titular,

        tc.fecha_vencimiento,

        tc.tipo_tarjeta,

        tc.banco,

        tc.estado

    FROM tarjeta_cliente tc

    WHERE tc.id_usuario = ?

      AND tc.estado = 'Activa'

    ORDER BY tc.id_tarjeta DESC

";


$stmt = mysqli_prepare(
    $conexion,
    $sql
);


if (!$stmt) {

    die(
        "Error al preparar la consulta: " .
        mysqli_error($conexion)
    );

}


mysqli_stmt_bind_param(
    $stmt,
    "i",
    $id_usuario
);


mysqli_stmt_execute(
    $stmt
);


$resultado =
    mysqli_stmt_get_result(
        $stmt
    );

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
        Mis tarjetas - EcoFauna
    </title>


    <!-- =========================================================
         BOOTSTRAP
    ========================================================== -->

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >


    <!-- =========================================================
         BOOTSTRAP ICONS
    ========================================================== -->

    <link
        rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css"
    >


    <!-- =========================================================
         CSS PRINCIPAL
    ========================================================== -->

    <link
        rel="stylesheet"
        href="../css/styleCliente.css?v=3"
    >


    <style>


        /*
        |--------------------------------------------------------------------------
        | MENSAJE
        |--------------------------------------------------------------------------
        */

        .mensaje-tarjeta {

            margin-bottom: 25px;

            border-radius: 12px;

            padding: 14px 18px;

            font-size: 15px;

        }


        /*
        |--------------------------------------------------------------------------
        | MODAL
        |--------------------------------------------------------------------------
        */

        .modal-eliminar {

            position: fixed;

            inset: 0;

            width: 100%;

            height: 100%;

            background: rgba(
                30,
                40,
                30,
                0.65
            );

            display: flex;

            align-items: center;

            justify-content: center;

            padding: 20px;

            opacity: 0;

            visibility: hidden;

            transition:
                opacity 0.25s ease,
                visibility 0.25s ease;

            z-index: 99999;

        }


        .modal-eliminar.mostrar {

            opacity: 1;

            visibility: visible;

        }


        .modal-eliminar-contenido {

            width: 100%;

            max-width: 430px;

            background: #ffffff;

            border-radius: 20px;

            padding: 35px 30px 30px;

            text-align: center;

            box-shadow:
                0 20px 50px rgba(
                    0,
                    0,
                    0,
                    0.25
                );

            transform:
                translateY(-20px)
                scale(0.95);

            transition:
                transform 0.25s ease;

        }


        .modal-eliminar.mostrar
        .modal-eliminar-contenido {

            transform:
                translateY(0)
                scale(1);

        }


        /*
        |--------------------------------------------------------------------------
        | ICONO
        |--------------------------------------------------------------------------
        */

        .modal-icono {

            width: 75px;

            height: 75px;

            margin:
                0 auto 20px;

            border-radius: 50%;

            background: #fbe9e7;

            color: #c0392b;

            display: flex;

            align-items: center;

            justify-content: center;

            font-size: 32px;

        }


        /*
        |--------------------------------------------------------------------------
        | TITULO
        |--------------------------------------------------------------------------
        */

        .modal-eliminar-contenido h3 {

            margin:
                0 0 12px;

            color: #304734;

            font-size: 25px;

            font-weight: 700;

        }


        /*
        |--------------------------------------------------------------------------
        | TEXTO
        |--------------------------------------------------------------------------
        */

        .modal-eliminar-contenido p {

            margin:
                0 auto 8px;

            color: #555;

            font-size: 16px;

            line-height: 1.5;

        }


        .modal-advertencia {

            color: #8a6d3b !important;

            font-size: 14px !important;

            margin-top: 12px !important;

        }


        /*
        |--------------------------------------------------------------------------
        | BOTONES
        |--------------------------------------------------------------------------
        */

        .modal-botones {

            display: flex;

            justify-content: center;

            gap: 12px;

            margin-top: 28px;

        }


        .btn-modal {

            border: none;

            border-radius: 10px;

            padding: 11px 20px;

            font-size: 15px;

            font-weight: 600;

            cursor: pointer;

            display: inline-flex;

            align-items: center;

            justify-content: center;

            gap: 7px;

            transition: all 0.2s ease;

        }


        /*
        |--------------------------------------------------------------------------
        | CANCELAR
        |--------------------------------------------------------------------------
        */

        .btn-modal.cancelar {

            background: #e8efe2;

            color: #304734;

        }


        .btn-modal.cancelar:hover {

            background: #d9e4d1;

            transform: translateY(-1px);

        }


        /*
        |--------------------------------------------------------------------------
        | ELIMINAR
        |--------------------------------------------------------------------------
        */

        .btn-modal.eliminar {

            background: #c0392b;

            color: #ffffff;

        }


        .btn-modal.eliminar:hover {

            background: #a93226;

            transform: translateY(-1px);

            box-shadow:
                0 5px 12px rgba(
                    192,
                    57,
                    43,
                    0.25
                );

        }


        /*
        |--------------------------------------------------------------------------
        | BLOQUEAR SCROLL
        |--------------------------------------------------------------------------
        */

        body.modal-abierto {

            overflow: hidden;

        }


        /*
        |--------------------------------------------------------------------------
        | RESPONSIVE
        |--------------------------------------------------------------------------
        */

        @media (max-width: 500px) {


            .modal-eliminar-contenido {

                padding:
                    30px 20px 25px;

                border-radius: 16px;

            }


            .modal-icono {

                width: 65px;

                height: 65px;

                font-size: 28px;

            }


            .modal-eliminar-contenido h3 {

                font-size: 22px;

            }


            .modal-botones {

                flex-direction: column;

            }


            .btn-modal {

                width: 100%;

            }

        }

    </style>

    <link rel="stylesheet" href="../css/ecofauna-unified.css?v=1">
</head>


<body class="ecofauna-unified">
<div class="alert alert-info text-center m-3" role="note">Demostración de portafolio: pagos simulados. Utiliza únicamente las tarjetas ficticias de prueba.</div>


<!-- =========================================================
     NAVBAR
========================================================= -->

<nav class="navbar navbar-cliente">


    <div class="container-fluid px-4">


        <a
            href="cliente.php"
            class="navbar-brand"
        >


            <img
                src="../img/LogoEcoFauna1.png"
                class="logo-navbar-cliente"
                alt="EcoFauna"
            >


            <span>

                EcoFauna

                <small>
                    Mis tarjetas
                </small>

            </span>


        </a>


        <div class="usuario-nav">


            <div class="usuario-info">


                <a
                    href="../mi_perfil.php"
                    class="usuario-info text-decoration-none"
                >


                    <span class="usuario-avatar">

                        <i class="bi bi-person-fill"></i>

                    </span>


                    <div>

                        <small>
                            Cliente
                        </small>


                        <strong>

                            <?= htmlspecialchars(
                                $_SESSION["usuario"] ?? ""
                            ) ?>

                        </strong>

                    </div>


                </a>


            </div>


            <a
                href="registrar_tarjeta.php"
                class="btn btn-success"
            >

                <i class="bi bi-plus-circle"></i>

                Agregar tarjeta

            </a>


        </div>


    </div>

</nav>


<!-- =========================================================
     HERO
========================================================= -->

<header class="hero-cliente">


    <div class="container hero-contenido">


        <div class="hero-texto">


            <span class="hero-etiqueta">

                <i class="bi bi-credit-card-fill"></i>

                Métodos de pago

            </span>


            <h1>

                Mis tarjetas registradas

            </h1>


            <p>

                Administra las tarjetas que puedes utilizar
                para tus compras en EcoFauna.

            </p>


        </div>


        <div class="hero-ilustracion">


            <div class="circulo-grande">

                <i class="bi bi-wallet2"></i>

            </div>


        </div>


    </div>


</header>


<!-- =========================================================
     CONTENIDO
========================================================= -->

<main class="container contenido-principal">


    <!-- =====================================================
         MENSAJE
    ====================================================== -->

    <?php if (
        isset(
            $_SESSION["mensaje_tarjeta"]
        )
    ): ?>


        <div
            class="alert alert-<?= htmlspecialchars(
                $_SESSION["mensaje_tarjeta"]["tipo"],
                ENT_QUOTES,
                "UTF-8"
            ) ?> alert-dismissible fade show mensaje-tarjeta"
            role="alert"
        >


            <?php

            $tipoMensaje =
                $_SESSION["mensaje_tarjeta"]["tipo"];

            ?>


            <?php if (
                $tipoMensaje === "success"
            ): ?>


                <i class="bi bi-check-circle-fill"></i>


            <?php elseif (
                $tipoMensaje === "danger"
            ): ?>


                <i class="bi bi-exclamation-triangle-fill"></i>


            <?php else: ?>


                <i class="bi bi-info-circle-fill"></i>


            <?php endif; ?>


            <?= htmlspecialchars(
                $_SESSION["mensaje_tarjeta"]["texto"],
                ENT_QUOTES,
                "UTF-8"
            ) ?>


            <button
                type="button"
                class="btn-close"
                data-bs-dismiss="alert"
                aria-label="Cerrar"
            ></button>


        </div>


        <?php

        unset(
            $_SESSION["mensaje_tarjeta"]
        );

        ?>


    <?php endif; ?>


    <!-- =====================================================
         TARJETAS
    ====================================================== -->

    <section class="carrito-card">


        <h2 class="text-success mb-4">


            <i class="bi bi-credit-card"></i>


            Tarjetas disponibles


        </h2>


        <div class="row g-4">


            <?php if (
                mysqli_num_rows(
                    $resultado
                ) === 0
            ): ?>


                <div
                    class="alert alert-warning text-center"
                >


                    <i
                        class="
                            bi
                            bi-credit-card-2-front
                            fs-1
                        "
                    ></i>


                    <h4 class="mt-3">

                        No tienes tarjetas registradas

                    </h4>


                    <p>

                        Agrega una tarjeta para utilizarla
                        como método de pago.

                    </p>


                    <a
                        href="registrar_tarjeta.php"
                        class="btn btn-success mt-2"
                    >


                        <i class="bi bi-plus-circle"></i>


                        Registrar tarjeta


                    </a>


                </div>


            <?php endif; ?>


            <?php while (
                $tarjeta =
                mysqli_fetch_assoc(
                    $resultado
                )
            ): ?>


                <?php


                /*
                |--------------------------------------------------------------------------
                | DESCIFRAR NÚMERO
                |--------------------------------------------------------------------------
                |
                | numero_tarjeta está cifrado en BD.
                |
                */

                $numeroOriginal =
                    descifrarDato(
                        $tarjeta["numero_tarjeta"],
                        $CLAVE_CIFRADO
                    );


                /*
                |--------------------------------------------------------------------------
                | OBTENER ÚLTIMOS 4
                |--------------------------------------------------------------------------
                */

                if (
                    $numeroOriginal !== false &&
                    strlen($numeroOriginal) >= 4
                ) {

                    $ultimos4 =
                        substr(
                            $numeroOriginal,
                            -4
                        );

                } else {

                    $ultimos4 = "****";

                }


                /*
                |--------------------------------------------------------------------------
                | FORMATEAR FECHA
                |--------------------------------------------------------------------------
                */

                $fechaMostrar = "";

                if (
                    !empty(
                        $tarjeta["fecha_vencimiento"]
                    )
                ) {

                    $fechaTimestamp =
                        strtotime(
                            $tarjeta["fecha_vencimiento"]
                        );


                    if (
                        $fechaTimestamp !== false
                    ) {

                        $fechaMostrar =
                            date(
                                "m/Y",
                                $fechaTimestamp
                            );

                    }

                }

                ?>


                <div class="col-md-6">


                    <div
                        class="
                            card
                            shadow
                            border-0
                            h-100
                        "
                    >


                        <div class="card-body">


                            <!-- =================================
                                 ENCABEZADO
                            ================================== -->

                            <div
                                class="
                                    d-flex
                                    justify-content-between
                                    align-items-center
                                    mb-3
                                "
                            >


                                <h4
                                    class="
                                        text-success
                                        mb-0
                                    "
                                >


                                    <i
                                        class="
                                            bi
                                            bi-credit-card-fill
                                        "
                                    ></i>


                                    <?= htmlspecialchars(
                                        $tarjeta["banco"],
                                        ENT_QUOTES,
                                        "UTF-8"
                                    ) ?>


                                </h4>


                                <span
                                    class="
                                        badge
                                        bg-success
                                    "
                                >


                                    <?= htmlspecialchars(
                                        $tarjeta["estado"],
                                        ENT_QUOTES,
                                        "UTF-8"
                                    ) ?>


                                </span>


                            </div>


                            <!-- =================================
                                 TIPO
                            ================================== -->

                            <p>


                                <strong>

                                    Tipo:

                                </strong>


                                <?= htmlspecialchars(
                                    $tarjeta["tipo_tarjeta"],
                                    ENT_QUOTES,
                                    "UTF-8"
                                ) ?>


                            </p>


                            <!-- =================================
                                 NUMERO
                            ================================== -->

                            <p>


                                <strong>

                                    Número:

                                </strong>


                                **** **** ****


                                <?= htmlspecialchars(
                                    $ultimos4,
                                    ENT_QUOTES,
                                    "UTF-8"
                                ) ?>


                            </p>


                            <!-- =================================
                                 TITULAR
                            ================================== -->

                            <p>


                                <strong>

                                    Titular:

                                </strong>


                                <?= htmlspecialchars(
                                    $tarjeta["titular"],
                                    ENT_QUOTES,
                                    "UTF-8"
                                ) ?>


                            </p>


                            <!-- =================================
                                 VENCIMIENTO
                            ================================== -->

                            <p>


                                <strong>

                                    Vencimiento:

                                </strong>


                                <?= htmlspecialchars(
                                    $fechaMostrar,
                                    ENT_QUOTES,
                                    "UTF-8"
                                ) ?>


                            </p>


                            <!-- =================================
                                 ELIMINAR / OCULTAR
                            ================================== -->

                            <form
                                action=""
                                method="POST"
                                class="
                                    form-eliminar-tarjeta
                                "
                            >


                                <input
                                    type="hidden"
                                    name="csrf_token"
                                    value="<?= htmlspecialchars(
                                        $_SESSION["csrf_token_tarjeta"],
                                        ENT_QUOTES,
                                        "UTF-8"
                                    ) ?>"
                                >


                                <input
                                    type="hidden"
                                    name="id_tarjeta"
                                    value="<?= (int) $tarjeta["id_tarjeta"] ?>"
                                >


                                <button
                                    type="button"
                                    class="
                                        btn
                                        btn-danger
                                        btn-sm
                                    "
                                    onclick="
                                        abrirModalEliminar(this)
                                    "
                                >


                                    <i
                                        class="
                                            bi
                                            bi-trash
                                        "
                                    ></i>


                                    Eliminar


                                </button>


                            </form>


                        </div>


                    </div>


                </div>


            <?php endwhile; ?>


        </div>


    </section>


</main>


<!-- =========================================================
     FOOTER
========================================================= -->

<footer class="footer-cliente">


    <div class="container">


        <span>


            <i class="bi bi-tree-fill"></i>


            EcoFauna — Portal del visitante


        </span>


        <small>

            Conservar • Educar • Proteger

        </small>


    </div>


</footer>


<!-- =========================================================
     MODAL ELIMINAR
========================================================= -->

<div
    id="modalEliminar"
    class="modal-eliminar"
>


    <div
        class="modal-eliminar-contenido"
    >


        <!-- ICONO -->

        <div class="modal-icono">


            <i
                class="
                    bi
                    bi-exclamation-triangle-fill
                "
            ></i>


        </div>


        <!-- TITULO -->

        <h3>

            ¿Eliminar tarjeta?

        </h3>


        <!-- TEXTO -->

        <p>

            ¿Está seguro de que desea eliminar esta tarjeta?

        </p>


        <p class="modal-advertencia">


            <i
                class="
                    bi
                    bi-info-circle
                "
            ></i>


            La tarjeta dejará de aparecer en tus métodos
            de pago.


        </p>


        <!-- BOTONES -->

        <div class="modal-botones">


            <button
                type="button"
                class="
                    btn-modal
                    cancelar
                "
                onclick="
                    cerrarModalEliminar()
                "
            >


                <i
                    class="
                        bi
                        bi-x-circle
                    "
                ></i>


                Cancelar


            </button>


            <button
                type="button"
                class="
                    btn-modal
                    eliminar
                "
                onclick="
                    confirmarEliminar()
                "
            >


                <i
                    class="
                        bi
                        bi-trash
                    "
                ></i>


                Sí, eliminar


            </button>


        </div>


    </div>


</div>


<!-- =========================================================
     BOOTSTRAP JS
========================================================= -->

<script
    src="
        https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.bundle.min.js
    "
></script>


<!-- =========================================================
     JAVASCRIPT
========================================================= -->

<script>


/*
|--------------------------------------------------------------------------
| VARIABLE DEL FORMULARIO
|--------------------------------------------------------------------------
*/

let formularioEliminar = null;


/*
|--------------------------------------------------------------------------
| ABRIR MODAL
|--------------------------------------------------------------------------
*/

function abrirModalEliminar(boton) {


    formularioEliminar =
        boton.closest(
            ".form-eliminar-tarjeta"
        );


    const modal =
        document.getElementById(
            "modalEliminar"
        );


    modal.classList.add(
        "mostrar"
    );


    document.body.classList.add(
        "modal-abierto"
    );

}


/*
|--------------------------------------------------------------------------
| CERRAR MODAL
|--------------------------------------------------------------------------
*/

function cerrarModalEliminar() {


    const modal =
        document.getElementById(
            "modalEliminar"
        );


    modal.classList.remove(
        "mostrar"
    );


    document.body.classList.remove(
        "modal-abierto"
    );


    formularioEliminar = null;

}


/*
|--------------------------------------------------------------------------
| CONFIRMAR
|--------------------------------------------------------------------------
*/

function confirmarEliminar() {


    if (
        formularioEliminar !== null
    ) {

        formularioEliminar.submit();

    }

}


/*
|--------------------------------------------------------------------------
| CLIC FUERA DEL MODAL
|--------------------------------------------------------------------------
*/

document
    .getElementById(
        "modalEliminar"
    )
    .addEventListener(
        "click",
        function(event) {


            if (
                event.target === this
            ) {

                cerrarModalEliminar();

            }

        }
    );


/*
|--------------------------------------------------------------------------
| ESC
|--------------------------------------------------------------------------
*/

document.addEventListener(
    "keydown",
    function(event) {


        if (
            event.key === "Escape" &&
            document
                .getElementById(
                    "modalEliminar"
                )
                .classList
                .contains("mostrar")
        ) {

            cerrarModalEliminar();

        }

    }
);

</script>


</body>

</html>