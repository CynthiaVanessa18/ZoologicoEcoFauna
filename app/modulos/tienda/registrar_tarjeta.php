<?php

require_once __DIR__ . "/../../soporte/sesion.php";
require_once __DIR__ . "/../../../config/database.php";
require_once __DIR__ . "/../../soporte/funciones_tienda.php";

/** @var mysqli $conexion */


/* =====================================================
   CONFIGURACIÓN DE CIFRADO
===================================================== */

$CLAVE_CIFRADO = claveCifradoEcoFauna();


/* =====================================================
   FUNCIÓN PARA CIFRAR
===================================================== */

function cifrarDato($dato, $clave)
{
    $metodo = "aes-256-gcm";

    $iv = random_bytes(12);

    $key = hash(
        "sha256",
        $clave,
        true
    );

    $tag = "";

    $cifrado = openssl_encrypt(
        $dato,
        $metodo,
        $key,
        OPENSSL_RAW_DATA,
        $iv,
        $tag
    );

    if ($cifrado === false) {

        throw new Exception(
            "No se pudo cifrar el dato."
        );
    }

    /*
     * FORMATO:
     *
     * IV + TAG + CIFRADO
     *
     * Después:
     *
     * Base64
     */

    return base64_encode(
        $iv . $tag . $cifrado
    );
}


/* =====================================================
   FUNCIÓN PARA DESCIFRAR
===================================================== */

function descifrarDato($datoCifrado, $clave)
{
    $metodo = "aes-256-gcm";

    if (empty($datoCifrado)) {

        return false;
    }

    $datos = base64_decode(
        $datoCifrado,
        true
    );

    if ($datos === false) {

        return false;
    }

    /*
     * IV = 12 bytes
     * TAG = 16 bytes
     * TOTAL MÍNIMO = 28
     */

    if (strlen($datos) < 28) {

        return false;
    }

    $iv = substr(
        $datos,
        0,
        12
    );

    $tag = substr(
        $datos,
        12,
        16
    );

    $cifrado = substr(
        $datos,
        28
    );

    $key = hash(
        "sha256",
        $clave,
        true
    );

    $descifrado = openssl_decrypt(
        $cifrado,
        $metodo,
        $key,
        OPENSSL_RAW_DATA,
        $iv,
        $tag
    );

    if ($descifrado === false) {

        return false;
    }

    return $descifrado;
}


/* =====================================================
   VALIDAR SESIÓN
===================================================== */

if (!clienteTiendaAutenticado()) {

    header(
        "Location: ../index.php"
    );

    exit();
}


$id_usuario = (int) $_SESSION["id_usuario"];

$mensaje = "";
$tipo = "";


/* =====================================================
   PÁGINA ANTERIOR
===================================================== */

/*
 * IMPORTANTE:
 *
 * La página desde donde se abrió registrar_tarjeta.php
 * se guarda en la sesión.
 *
 * Esto evita depender de HTTP_REFERER después del POST.
 */


/* -----------------------------------------------------
   SI VIENE "regresar" POR GET
----------------------------------------------------- */

if (
    isset($_GET["regresar"]) &&
    !empty($_GET["regresar"])
) {

    $paginaRecibida = $_GET["regresar"];

    /*
     * Validar que no sea una URL externa.
     */

    $parseada = parse_url($paginaRecibida);

    $esValida = true;


    /*
     * No permitir http://
     * No permitir https://
     * No permitir dominios externos.
     */

    if (
        isset($parseada["scheme"]) ||
        isset($parseada["host"])
    ) {

        $esValida = false;
    }


    /*
     * No permitir //
     */

    if (
        strpos($paginaRecibida, "//") === 0 ||
        preg_match(
            '/^(https?:)?\/\//i',
            $paginaRecibida
        )
    ) {

        $esValida = false;
    }


    if ($esValida) {

        $_SESSION["pagina_regreso_tarjeta"] =
            $paginaRecibida;
    }
}


/* -----------------------------------------------------
   SI NO EXISTE EN SESIÓN
----------------------------------------------------- */

if (
    !isset($_SESSION["pagina_regreso_tarjeta"]) ||
    empty($_SESSION["pagina_regreso_tarjeta"])
) {

    /*
     * Como respaldo utilizamos HTTP_REFERER.
     */

    $referer =
        $_SERVER["HTTP_REFERER"] ?? "";


    if (!empty($referer)) {

        $refererParseado =
            parse_url($referer);

        $refererValido = true;


        /*
         * Si tiene host, verificar que sea
         * el mismo servidor.
         */

        if (
            isset($refererParseado["host"]) &&
            $refererParseado["host"] !==
            $_SERVER["HTTP_HOST"]
        ) {

            $refererValido = false;
        }


        /*
         * No aceptar protocolos externos.
         */

        if (
            isset($refererParseado["scheme"]) &&
            !in_array(
                strtolower($refererParseado["scheme"]),
                ["http", "https"],
                true
            )
        ) {

            $refererValido = false;
        }


        if ($refererValido) {

            /*
             * Intentamos guardar solamente la ruta
             * interna.
             */

            if (
                isset($refererParseado["path"])
            ) {

                $ruta =
                    $refererParseado["path"];


                if (
                    isset(
                        $refererParseado["query"]
                    )
                ) {

                    $ruta .=
                        "?" .
                        $refererParseado["query"];
                }


                $_SESSION[
                    "pagina_regreso_tarjeta"
                ] = $ruta;
            }
        }
    }
}


/*
 * Si todavía no tenemos página anterior,
 * utilizamos cliente.php.
 */

if (
    !isset($_SESSION["pagina_regreso_tarjeta"]) ||
    empty($_SESSION["pagina_regreso_tarjeta"])
) {

    $_SESSION["pagina_regreso_tarjeta"] =
        "cliente.php";
}


/* =====================================================
   OBTENER PÁGINA ANTERIOR
===================================================== */

$paginaAnterior =
    $_SESSION["pagina_regreso_tarjeta"];


/* =====================================================
   PROCESAR FORMULARIO
===================================================== */

if (
    $_SERVER["REQUEST_METHOD"] === "POST"
) {


    /* =================================================
       VALIDAR CSRF
    ================================================= */

    if (
        !tokenCsrfSesionValido(
            "tarjeta",
            $_POST["csrf_token"] ?? null
        )
    ) {

        $mensaje =
            "La sesión expiró. Actualice la página.";

        $tipo = "danger";

    } else {


        /* =============================================
           OBTENER DATOS
        ============================================= */

        $numero_tarjeta = preg_replace(
            '/[^0-9]/',
            '',
            $_POST["numero_tarjeta"] ?? ""
        );


        $titular = strtoupper(
            trim(
                $_POST["titular"] ?? ""
            )
        );


        $fecha_vencimiento = trim(
            $_POST["fecha_vencimiento"] ?? ""
        );


        $tipo_tarjeta = trim(
            $_POST["tipo_tarjeta"] ?? ""
        );


        $cvc = preg_replace(
            '/[^0-9]/',
            '',
            $_POST["cvc"] ?? ""
        );


        /* =============================================
           VALIDACIONES
        ============================================= */

        if (
            empty($numero_tarjeta) ||
            empty($titular) ||
            empty($fecha_vencimiento) ||
            empty($tipo_tarjeta) ||
            empty($cvc)
        ) {

            $mensaje =
                "Debe completar todos los campos.";

            $tipo = "danger";


        } elseif (
            !preg_match(
                '/^[0-9]{13,19}$/',
                $numero_tarjeta
            )
        ) {

            $mensaje =
                "El número de tarjeta debe tener entre 13 y 19 dígitos.";

            $tipo = "danger";


        } elseif (
            !preg_match(
                '/^[0-9]{3,4}$/',
                $cvc
            )
        ) {

            $mensaje =
                "El CVC debe tener entre 3 y 4 dígitos.";

            $tipo = "danger";


        } elseif (
            !preg_match(
                '/^[0-9]{4}-[0-9]{2}$/',
                $fecha_vencimiento
            )
        ) {

            $mensaje =
                "La fecha de vencimiento no es válida.";

            $tipo = "danger";


        } elseif (
            !in_array(
                $tipo_tarjeta,
                [
                    "Credito",
                    "Debito"
                ],
                true
            )
        ) {

            $mensaje =
                "El tipo de tarjeta seleccionado no es válido.";

            $tipo = "danger";


        } else {


            /* =========================================
               CONVERTIR FECHA
            ========================================= */

            $fecha =
                $fecha_vencimiento . "-01";


            /* =========================================
               BUSCAR TARJETA EN TARJETA_BANCO
            ========================================= */

            $sql = "
                SELECT
                    id_tarjeta_banco,
                    banco,
                    numero_tarjeta,
                    titular,
                    fecha_vencimiento,
                    tipo_tarjeta,
                    cvc,
                    estado
                FROM tarjeta_banco
                WHERE UPPER(TRIM(titular)) =
                      UPPER(TRIM(?))
                  AND fecha_vencimiento = ?
                  AND tipo_tarjeta = ?
                  AND estado = 'Activa'
            ";


            $stmt = mysqli_prepare(
                $conexion,
                $sql
            );


            if (!$stmt) {

                $mensaje =
                    "No fue posible preparar la consulta de la tarjeta.";

                $tipo = "danger";

            } else {


                mysqli_stmt_bind_param(
                    $stmt,
                    "sss",
                    $titular,
                    $fecha,
                    $tipo_tarjeta
                );


                if (
                    !mysqli_stmt_execute($stmt)
                ) {

                    $mensaje =
                        "No fue posible consultar las tarjetas.";

                    $tipo = "danger";

                    mysqli_stmt_close($stmt);

                } else {


                    $resultado =
                        mysqli_stmt_get_result(
                            $stmt
                        );


                    if ($resultado === false) {

                        $mensaje =
                            "No fue posible obtener los datos de la tarjeta.";

                        $tipo = "danger";

                        mysqli_stmt_close($stmt);

                    } else {


                        $tarjetaEncontrada = null;


                        /* =================================
                           DESCIFRAR Y COMPARAR
                        ================================= */

                        while (
                            $fila =
                            mysqli_fetch_assoc(
                                $resultado
                            )
                        ) {


                            /*
                             * DESCIFRAR NÚMERO
                             */

                            $numeroOriginal =
                                descifrarDato(
                                    $fila["numero_tarjeta"],
                                    $CLAVE_CIFRADO
                                );


                            /*
                             * DESCIFRAR CVC
                             */

                            $cvcOriginal =
                                descifrarDato(
                                    $fila["cvc"],
                                    $CLAVE_CIFRADO
                                );


                            if (
                                $numeroOriginal === false ||
                                $cvcOriginal === false
                            ) {

                                continue;
                            }


                            /*
                             * COMPARAR NÚMERO
                             */

                            $numeroCorrecto =
                                hash_equals(
                                    (string)
                                    $numeroOriginal,
                                    (string)
                                    $numero_tarjeta
                                );


                            /*
                             * COMPARAR CVC
                             */

                            $cvcCorrecto =
                                hash_equals(
                                    (string)
                                    $cvcOriginal,
                                    (string)
                                    $cvc
                                );


                            if (
                                $numeroCorrecto &&
                                $cvcCorrecto
                            ) {

                                $tarjetaEncontrada =
                                    $fila;

                                break;
                            }
                        }


                        mysqli_stmt_close($stmt);


                        /* =================================
                           TARJETA NO ENCONTRADA
                        ================================= */

                        if (
                            $tarjetaEncontrada === null
                        ) {

                            $mensaje =
                                "Los datos de la tarjeta no son correctos.";

                            $tipo = "danger";

                        } else {


                            $tarjeta =
                                $tarjetaEncontrada;


                            $idTarjetaBanco =
                                (int)
                                $tarjeta[
                                    "id_tarjeta_banco"
                                ];


                            /* =================================
                               BUSCAR SI YA ESTÁ ASOCIADA
                            ================================= */

                            $sql2 = "
                                SELECT
                                    id_tarjeta,
                                    estado
                                FROM tarjeta_cliente
                                WHERE id_usuario = ?
                                  AND id_tarjeta_banco = ?
                                LIMIT 1
                            ";


                            $stmt2 =
                                mysqli_prepare(
                                    $conexion,
                                    $sql2
                                );


                            if (!$stmt2) {

                                $mensaje =
                                    "No fue posible verificar la tarjeta.";

                                $tipo = "danger";

                            } else {


                                mysqli_stmt_bind_param(
                                    $stmt2,
                                    "ii",
                                    $id_usuario,
                                    $idTarjetaBanco
                                );


                                mysqli_stmt_execute(
                                    $stmt2
                                );


                                $resultado2 =
                                    mysqli_stmt_get_result(
                                        $stmt2
                                    );


                                $tarjetaExistente =
                                    mysqli_fetch_assoc(
                                        $resultado2
                                    );


                                mysqli_stmt_close(
                                    $stmt2
                                );


                                /* =================================
                                   SI YA EXISTE
                                ================================= */

                                if (
                                    $tarjetaExistente !== null
                                ) {


                                    $idTarjetaExistente =
                                        (int)
                                        $tarjetaExistente[
                                            "id_tarjeta"
                                        ];


                                    $estadoActual =
                                        $tarjetaExistente[
                                            "estado"
                                        ];


                                    /*
                                     * YA ESTÁ ACTIVA
                                     */

                                    if (
                                        $estadoActual === "Activa"
                                    ) {

                                        $mensaje =
                                            "Esta tarjeta ya está activa en su cuenta.";

                                        $tipo =
                                            "warning";


                                    } else {


                                        /*
                                         * VOLVERLA A ACTIVAR
                                         */

                                        $sqlActivar = "
                                            UPDATE tarjeta_cliente
                                            SET estado = 'Activa'
                                            WHERE id_tarjeta = ?
                                              AND id_usuario = ?
                                        ";


                                        $stmtActivar =
                                            mysqli_prepare(
                                                $conexion,
                                                $sqlActivar
                                            );


                                        if (!$stmtActivar) {

                                            $mensaje =
                                                "No fue posible reactivar la tarjeta.";

                                            $tipo =
                                                "danger";

                                        } else {


                                            mysqli_stmt_bind_param(
                                                $stmtActivar,
                                                "ii",
                                                $idTarjetaExistente,
                                                $id_usuario
                                            );


                                            if (
                                                mysqli_stmt_execute(
                                                    $stmtActivar
                                                )
                                            ) {


                                                /*
                                                 * ÚLTIMOS 4
                                                 */

                                                $ultimos4 =
                                                    substr(
                                                        $numero_tarjeta,
                                                        -4
                                                    );


                                                /*
                                                 * BITÁCORA
                                                 */

                                                $detalleBitacora =
                                                    "Tarjeta reactivada. " .
                                                    "Banco: " .
                                                    $tarjeta["banco"] .
                                                    ". Tipo: " .
                                                    $tarjeta["tipo_tarjeta"] .
                                                    ". Terminación: ****" .
                                                    $ultimos4 .
                                                    ".";


                                                $usuarioBitacora =
                                                    $_SESSION[
                                                        "usuario"
                                                    ] ?? "Sistema";


                                                registrarBitacora(
                                                    $usuarioBitacora,
                                                    "REACTIVAR TARJETA",
                                                    "tarjeta_cliente",
                                                    $detalleBitacora,
                                                    $idTarjetaExistente
                                                );


                                                $mensaje =
                                                    "La tarjeta ya estaba registrada y fue activada nuevamente.";

                                                $tipo =
                                                    "success";


                                            } else {

                                                $mensaje =
                                                    "No fue posible activar nuevamente la tarjeta.";

                                                $tipo =
                                                    "danger";
                                            }


                                            mysqli_stmt_close(
                                                $stmtActivar
                                            );
                                        }
                                    }


                                } else {


                                    /* =================================
                                       NO EXISTE
                                       INSERTAR NUEVA
                                    ================================= */

                                    mysqli_begin_transaction(
                                        $conexion
                                    );


                                    $stmt3 = null;


                                    try {


                                        /*
                                         * CIFRAR NÚMERO
                                         */

                                        $numeroCifradoCliente =
                                            cifrarDato(
                                                $numero_tarjeta,
                                                $CLAVE_CIFRADO
                                            );


                                        /*
                                         * ÚLTIMOS 4
                                         */

                                        $ultimos4 =
                                            substr(
                                                $numero_tarjeta,
                                                -4
                                            );


                                        /*
                                         * DATOS DE BD
                                         */

                                        $titularBD =
                                            $tarjeta["titular"];


                                        $fechaBD =
                                            $tarjeta[
                                                "fecha_vencimiento"
                                            ];


                                        $tipoBD =
                                            $tarjeta[
                                                "tipo_tarjeta"
                                            ];


                                        $bancoBD =
                                            $tarjeta["banco"];


                                        /*
                                         * INSERTAR
                                         */

                                        $sql3 = "
                                            INSERT INTO tarjeta_cliente
                                            (
                                                id_usuario,
                                                id_tarjeta_banco,
                                                numero_tarjeta,
                                                titular,
                                                fecha_vencimiento,
                                                tipo_tarjeta,
                                                banco,
                                                estado
                                            )
                                            VALUES
                                            (
                                                ?,
                                                ?,
                                                ?,
                                                ?,
                                                ?,
                                                ?,
                                                ?,
                                                'Activa'
                                            )
                                        ";


                                        $stmt3 =
                                            mysqli_prepare(
                                                $conexion,
                                                $sql3
                                            );


                                        if (!$stmt3) {

                                            throw new Exception(
                                                "No fue posible preparar el registro."
                                            );
                                        }


                                        mysqli_stmt_bind_param(
                                            $stmt3,
                                            "iisssss",
                                            $id_usuario,
                                            $idTarjetaBanco,
                                            $numeroCifradoCliente,
                                            $titularBD,
                                            $fechaBD,
                                            $tipoBD,
                                            $bancoBD
                                        );


                                        if (
                                            !mysqli_stmt_execute(
                                                $stmt3
                                            )
                                        ) {

                                            throw new Exception(
                                                mysqli_stmt_error(
                                                    $stmt3
                                                )
                                            );
                                        }


                                        /*
                                         * ID
                                         */

                                        $idTarjetaCliente =
                                            mysqli_insert_id(
                                                $conexion
                                            );


                                        if (
                                            $idTarjetaCliente <= 0
                                        ) {

                                            throw new Exception(
                                                "No se pudo obtener el ID de la tarjeta."
                                            );
                                        }


                                        mysqli_stmt_close(
                                            $stmt3
                                        );

                                        $stmt3 = null;


                                        /*
                                         * BITÁCORA
                                         */

                                        $detalleBitacora =
                                            "Tarjeta registrada. " .
                                            "Banco: " .
                                            $bancoBD .
                                            ". Tipo: " .
                                            $tipoBD .
                                            ". Terminación: ****" .
                                            $ultimos4 .
                                            ".";


                                        $usuarioBitacora =
                                            $_SESSION[
                                                "usuario"
                                            ] ?? "Sistema";


                                        if (
                                            !registrarBitacora(
                                                $usuarioBitacora,
                                                "REGISTRAR TARJETA",
                                                "tarjeta_cliente",
                                                $detalleBitacora,
                                                $idTarjetaCliente
                                            )
                                        ) {

                                            throw new Exception(
                                                "No fue posible registrar la acción en la bitácora."
                                            );
                                        }


                                        /*
                                         * COMMIT
                                         */

                                        if (
                                            !mysqli_commit(
                                                $conexion
                                            )
                                        ) {

                                            throw new Exception(
                                                "No fue posible confirmar el registro."
                                            );
                                        }


                                        /*
                                         * ÉXITO
                                         */

                                        $mensaje =
                                            "Tarjeta registrada correctamente.";

                                        $tipo =
                                            "success";


                                    } catch (
                                        Throwable $e
                                    ) {


                                        mysqli_rollback(
                                            $conexion
                                        );


                                        if (
                                            $stmt3 instanceof mysqli_stmt
                                        ) {

                                            mysqli_stmt_close(
                                                $stmt3
                                            );
                                        }


                                        error_log(
                                            "ERROR REGISTRAR TARJETA: " .
                                            $e->getMessage()
                                        );


                                        $mensaje =
                                            "No fue posible registrar la tarjeta.";

                                        $tipo =
                                            "danger";
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
    }
}


/* =====================================================
   RENOVAR TOKEN CSRF
===================================================== */

renovarTokenCsrfSesion(
    "tarjeta"
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
        Registrar tarjeta - EcoFauna
    </title>


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
        href="../css/styleCliente.css?v=3"
    >

    <link rel="stylesheet" href="../css/ecofauna-unified.css?v=1">
</head>


<body class="ecofauna-unified">
<div class="alert alert-info text-center m-3" role="note">Demostración de portafolio: pagos simulados. Utiliza únicamente las tarjetas ficticias de prueba.</div>


<!-- =====================================================
     NAVBAR
===================================================== -->

<nav class="navbar navbar-cliente">

    <div class="container-fluid px-4">

        <a
            class="navbar-brand"
            href="cliente.php"
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

    </div>

</nav>


<!-- =====================================================
     CONTENIDO
===================================================== -->

<main class="container contenido-principal">

    <section class="carrito-card">

        <div class="card shadow-lg border-0">

            <div class="card-body p-4">


                <h2 class="text-success mb-4">

                    <i class="bi bi-credit-card-fill"></i>

                    Registrar tarjeta

                </h2>


                <!-- =================================================
                     MENSAJE
                ================================================== -->

                <?php if ($mensaje !== ""): ?>

                    <div
                        class="alert alert-<?=
                            htmlspecialchars(
                                $tipo,
                                ENT_QUOTES,
                                "UTF-8"
                            )
                        ?>"
                    >

                        <?php if ($tipo === "success"): ?>

                            <i class="bi bi-check-circle-fill"></i>

                        <?php elseif ($tipo === "warning"): ?>

                            <i class="bi bi-exclamation-circle-fill"></i>

                        <?php else: ?>

                            <i class="bi bi-exclamation-triangle-fill"></i>

                        <?php endif; ?>


                        <?= htmlspecialchars(
                            $mensaje,
                            ENT_QUOTES,
                            "UTF-8"
                        ) ?>

                    </div>

                <?php endif; ?>


                <!-- =================================================
                     FORMULARIO
                ================================================== -->

                <form
                    method="POST"
                    autocomplete="off"
                >


                    <?= campoCsrfSesion("tarjeta") ?>


                    <!-- NÚMERO -->

                    <div class="mb-3">

                        <label class="form-label">

                            Número de tarjeta

                        </label>


                        <input
                            type="text"
                            name="numero_tarjeta"
                            class="form-control"
                            maxlength="19"
                            placeholder="0000 0000 0000 0000"
                            inputmode="numeric"
                            autocomplete="cc-number"
                            required
                        >

                    </div>


                    <!-- TITULAR -->

                    <div class="mb-3">

                        <label class="form-label">

                            Titular de la tarjeta

                        </label>


                        <input
                            type="text"
                            name="titular"
                            class="form-control"
                            placeholder="Nombre como aparece en la tarjeta"
                            autocomplete="cc-name"
                            required
                        >

                    </div>


                    <!-- FECHA / CVC -->

                    <div class="row">

                        <div class="col-md-6 mb-3">

                            <label class="form-label">

                                Fecha vencimiento

                            </label>


                            <input
                                type="month"
                                name="fecha_vencimiento"
                                class="form-control"
                                autocomplete="cc-exp"
                                required
                            >

                        </div>


                        <div class="col-md-6 mb-3">

                            <label class="form-label">

                                CVC

                            </label>


                            <input
                                type="password"
                                name="cvc"
                                maxlength="4"
                                class="form-control"
                                placeholder="123"
                                inputmode="numeric"
                                autocomplete="cc-csc"
                                required
                            >

                        </div>

                    </div>


                    <!-- TIPO -->

                    <div class="mb-3">

                        <label class="form-label">

                            Tipo de tarjeta

                        </label>


                        <select
                            name="tipo_tarjeta"
                            class="form-select"
                            required
                        >

                            <option value="">
                                Seleccione
                            </option>


                            <option value="Credito">
                                Crédito
                            </option>


                            <option value="Debito">
                                Débito
                            </option>

                        </select>

                    </div>


                    <!-- INFORMACIÓN -->

                    <div class="alert alert-info">

                        <i class="bi bi-shield-lock"></i>

                        La tarjeta será validada antes de asociarla
                        a su cuenta.

                        <br>

                        <small>

                            Los datos sensibles de la tarjeta no se
                            almacenan en la bitácora.

                        </small>

                    </div>


                    <!-- BOTÓN REGISTRAR -->

                    <button
                        type="submit"
                        class="btn btn-success btn-carrito"
                    >

                        <i class="bi bi-save"></i>

                        Registrar tarjeta

                    </button>


                    <!-- BOTÓN REGRESAR -->

                    <a
                        href="<?= htmlspecialchars(
                            $paginaAnterior,
                            ENT_QUOTES,
                            "UTF-8"
                        ) ?>"
                        class="btn btn-secondary btn-carrito"
                    >

                        <i class="bi bi-arrow-left"></i>

                        Regresar

                    </a>


                </form>

            </div>

        </div>

    </section>

</main>


</body>

</html>