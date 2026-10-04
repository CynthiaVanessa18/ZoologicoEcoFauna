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
   FUNCIÓN PARA DESCIFRAR DATOS
===================================================== */

function descifrarDato(
    $datoCifrado,
    $clave
) {

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
     * Mínimo = 28 bytes
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
   FUNCIÓN PARA ESCAPAR HTML
===================================================== */

function escapar($texto): string
{
    return htmlspecialchars(
        (string) $texto,
        ENT_QUOTES,
        "UTF-8"
    );
}


/* =====================================================
   GENERAR CÓDIGO DE INGRESO
===================================================== */

function generarCodigoIngreso(): string
{
    $caracteres =
        "ABCDEFGHJKLMNPQRSTUVWXYZ23456789";

    $codigo = "E";

    $ultimoIndice =
        strlen($caracteres) - 1;

    for ($i = 0; $i < 4; $i++) {

        $codigo .=
            $caracteres[
                random_int(
                    0,
                    $ultimoIndice
                )
            ];
    }

    return $codigo;
}


/* =====================================================
   CONTROL DE ACCESO
===================================================== */

if (!clienteTiendaAutenticado()) {

    header(
        "Location: ../index.php"
    );

    exit();
}


/* =====================================================
   DATOS DEL USUARIO
===================================================== */

$idUsuario =
    (int) (
        $_SESSION["id_usuario"] ?? 0
    );

if ($idUsuario <= 0) {

    header(
        "Location: ../index.php"
    );

    exit();
}


/* =====================================================
   VARIABLES
===================================================== */

$mensaje = "";
$tipoMensaje = "";

$idTarjetaSeleccionada =
    (int) (
        $_POST["id_tarjeta"] ?? 0
    );


/* =====================================================
   OBTENER TARIFAS
===================================================== */

$sqlTarifas = "
    SELECT
        id_tarifa,
        categoria,
        precio
    FROM Tarifa_Entrada
    WHERE estado = 'Activa'
    ORDER BY id_tarifa ASC
";

$resTarifas =
    mysqli_query(
        $conexion,
        $sqlTarifas
    );

$tarifas = [];

if ($resTarifas) {

    while (
        $fila =
        mysqli_fetch_assoc(
            $resTarifas
        )
    ) {

        $tarifas[] = $fila;
    }
}


/* =====================================================
   OBTENER FECHAS DISPONIBLES
===================================================== */

$sqlFechas = "
    SELECT
        id_disponibilidad,
        fecha,
        capacidad_disponible
    FROM Disponibilidad_Dia_Zoo
    WHERE fecha >= CURDATE()
      AND capacidad_disponible > 0
      AND estado = 'Disponible'
    ORDER BY fecha ASC
";

$resFechas =
    mysqli_query(
        $conexion,
        $sqlFechas
    );

$fechasDisponibles = [];

if ($resFechas) {

    while (
        $fila =
        mysqli_fetch_assoc(
            $resFechas
        )
    ) {

        $fechasDisponibles[] = $fila;
    }
}


/* =====================================================
   OBTENER TARJETAS DEL CLIENTE
===================================================== */

$tarjetas = [];

$sqlTarjetas = "
    SELECT
        id_tarjeta,
        banco,
        tipo_tarjeta,
        numero_tarjeta
    FROM tarjeta_cliente
    WHERE id_usuario = ?
      AND estado = 'Activa'
    ORDER BY id_tarjeta DESC
";

$stmtTarjetas =
    mysqli_prepare(
        $conexion,
        $sqlTarjetas
    );

if ($stmtTarjetas) {

    mysqli_stmt_bind_param(
        $stmtTarjetas,
        "i",
        $idUsuario
    );

    if (
        mysqli_stmt_execute(
            $stmtTarjetas
        )
    ) {

        $resultadoTarjetas =
            mysqli_stmt_get_result(
                $stmtTarjetas
            );

        if ($resultadoTarjetas) {

            while (
                $tarjeta =
                mysqli_fetch_assoc(
                    $resultadoTarjetas
                )
            ) {

                /*
                 * DESCIFRAR SOLAMENTE PARA OBTENER
                 * LOS ÚLTIMOS 4 DÍGITOS
                 */

                $numeroReal =
                    descifrarDato(
                        $tarjeta["numero_tarjeta"],
                        $CLAVE_CIFRADO
                    );

                $ultimosCuatro = "----";

                if (
                    $numeroReal !== false &&
                    preg_match(
                        '/^[0-9]+$/',
                        $numeroReal
                    )
                ) {

                    $ultimosCuatro =
                        substr(
                            $numeroReal,
                            -4
                        );
                }

                $tarjeta["ultimos4"] =
                    $ultimosCuatro;

                $tarjetas[] =
                    $tarjeta;
            }
        }
    }

    mysqli_stmt_close(
        $stmtTarjetas
    );
}


/* =====================================================
   PROCESAR COMPRA
===================================================== */

if (
    $_SERVER["REQUEST_METHOD"] === "POST" &&
    isset(
        $_POST["procesar_compra_boletos"]
    )
) {

    $enTransaccion = false;

    try {

        /* =============================================
           DATOS DEL FORMULARIO
        ============================================== */

        $idDisponibilidad =
            filter_var(
                $_POST["id_disponibilidad"] ?? 0,
                FILTER_VALIDATE_INT
            );

        $tipoPago =
            trim(
                $_POST["tipo_pago"] ?? ""
            );

        $idTarjeta =
            filter_var(
                $_POST["id_tarjeta"] ?? 0,
                FILTER_VALIDATE_INT
            );

        $cantidades =
            $_POST["cantidad"] ?? [];


        /* =============================================
           VALIDAR FECHA
        ============================================== */

        if (
            !$idDisponibilidad ||
            $idDisponibilidad <= 0
        ) {

            throw new DomainException(
                "Debes seleccionar una fecha de visita válida."
            );
        }


        /* =============================================
           VALIDAR MÉTODO DE PAGO
        ============================================== */

        $metodosPermitidos = [
            "Tarjeta",
            "SINPE Móvil",
            "Transferencia"
        ];

        if (
            !in_array(
                $tipoPago,
                $metodosPermitidos,
                true
            )
        ) {

            throw new DomainException(
                "Debes seleccionar un método de pago válido."
            );
        }


        /* =============================================
           MÉTODO DE PAGO
        ============================================== */

        $descripcionMetodoPago =
            $tipoPago;


        /* =============================================
           VALIDAR TARJETA
        ============================================== */

        if (
            $tipoPago === "Tarjeta"
        ) {

            if (
                !$idTarjeta ||
                $idTarjeta <= 0
            ) {

                throw new DomainException(
                    "Debes seleccionar una tarjeta."
                );
            }


            /*
             * La tarjeta debe:
             *
             * 1. Existir.
             * 2. Pertenecer al usuario.
             * 3. Estar activa.
             */

            $sqlTarjeta = "
                SELECT
                    id_tarjeta,
                    banco,
                    tipo_tarjeta,
                    numero_tarjeta
                FROM tarjeta_cliente
                WHERE id_tarjeta = ?
                  AND id_usuario = ?
                  AND estado = 'Activa'
                LIMIT 1
            ";

            $stmtTarjeta =
                mysqli_prepare(
                    $conexion,
                    $sqlTarjeta
                );

            if (!$stmtTarjeta) {

                throw new RuntimeException(
                    "No fue posible validar la tarjeta."
                );
            }

            mysqli_stmt_bind_param(
                $stmtTarjeta,
                "ii",
                $idTarjeta,
                $idUsuario
            );

            if (
                !mysqli_stmt_execute(
                    $stmtTarjeta
                )
            ) {

                $error =
                    mysqli_stmt_error(
                        $stmtTarjeta
                    );

                mysqli_stmt_close(
                    $stmtTarjeta
                );

                throw new RuntimeException(
                    $error
                );
            }

            $resultadoTarjeta =
                mysqli_stmt_get_result(
                    $stmtTarjeta
                );

            $tarjetaSeleccionada =
                mysqli_fetch_assoc(
                    $resultadoTarjeta
                );

            mysqli_stmt_close(
                $stmtTarjeta
            );

            if (
                !$tarjetaSeleccionada
            ) {

                throw new DomainException(
                    "La tarjeta seleccionada no es válida."
                );
            }


            /* =========================================
               DESCIFRAR NÚMERO
            ========================================== */

            $numeroTarjetaReal =
                descifrarDato(
                    $tarjetaSeleccionada[
                        "numero_tarjeta"
                    ],
                    $CLAVE_CIFRADO
                );

            if (
                $numeroTarjetaReal === false ||
                !preg_match(
                    '/^[0-9]+$/',
                    $numeroTarjetaReal
                )
            ) {

                throw new DomainException(
                    "No fue posible validar los datos de la tarjeta."
                );
            }


            /* =========================================
               OBTENER ÚLTIMOS 4
            ========================================== */

            $ultimosCuatro =
                substr(
                    $numeroTarjetaReal,
                    -4
                );


            /* =========================================
               GUARDAR INFORMACIÓN ENMASCARADA
            ========================================== */

           $descripcionMetodoPago = "Tarjeta";
        }


        /* =============================================
           VALIDAR CANTIDADES
        ============================================== */

        if (
            !is_array($cantidades)
        ) {

            throw new DomainException(
                "Las cantidades de boletos no son válidas."
            );
        }

        $cantidadTotalBoletos = 0;

        $subtotalGeneral = 0.0;

        $detallesAInsertar = [];


        foreach (
            $tarifas
            as $tarifa
        ) {

            $idTarifa =
                (int) $tarifa[
                    "id_tarifa"
                ];

            $cantidad =
                filter_var(
                    $cantidades[
                        $idTarifa
                    ] ?? 0,
                    FILTER_VALIDATE_INT,
                    [
                        "options" => [
                            "min_range" => 0,
                            "max_range" => 20
                        ]
                    ]
                );

            if (
                $cantidad === false
            ) {

                throw new DomainException(
                    "La cantidad seleccionada no es válida."
                );
            }

            if (
                $cantidad > 0
            ) {

                $precio =
                    (float) $tarifa[
                        "precio"
                    ];

                $subtotal =
                    round(
                        $precio * $cantidad,
                        2
                    );

                $cantidadTotalBoletos +=
                    $cantidad;

                $subtotalGeneral +=
                    $subtotal;

                $detallesAInsertar[] = [

                    "id_tarifa" =>
                        $idTarifa,

                    "cantidad" =>
                        $cantidad,

                    "precio" =>
                        $precio,

                    "subtotal" =>
                        $subtotal
                ];
            }
        }


        /* =============================================
           VALIDAR CANTIDAD TOTAL
        ============================================== */

        if (
            $cantidadTotalBoletos <= 0
        ) {

            throw new DomainException(
                "Debes seleccionar al menos un boleto."
            );
        }

        if (
            $cantidadTotalBoletos > 50
        ) {

            throw new DomainException(
                "La compra no puede superar los 50 boletos."
            );
        }


        /* =============================================
           CALCULAR IVA Y TOTAL
        ============================================== */

        $subtotalGeneral =
            round(
                $subtotalGeneral,
                2
            );

        $iva =
            round(
                $subtotalGeneral * 0.13,
                2
            );

        $totalTicket =
            round(
                $subtotalGeneral + $iva,
                2
            );


        /* =============================================
           INICIAR TRANSACCIÓN
        ============================================== */

        if (
            !mysqli_begin_transaction(
                $conexion
            )
        ) {

            throw new RuntimeException(
                "No fue posible iniciar la compra."
            );
        }

        $enTransaccion = true;


        /* =============================================
           BLOQUEAR DISPONIBILIDAD
        ============================================== */

        $sqlDisponibilidad = "
            SELECT
                fecha,
                capacidad_disponible
            FROM Disponibilidad_Dia_Zoo
            WHERE id_disponibilidad = ?
              AND fecha >= CURDATE()
              AND estado = 'Disponible'
            FOR UPDATE
        ";

        $stmtDisponibilidad =
            mysqli_prepare(
                $conexion,
                $sqlDisponibilidad
            );

        if (!$stmtDisponibilidad) {

            throw new RuntimeException(
                "No fue posible verificar la disponibilidad: " .
                mysqli_error($conexion)
            );
        }

        mysqli_stmt_bind_param(
            $stmtDisponibilidad,
            "i",
            $idDisponibilidad
        );

        if (
            !mysqli_stmt_execute(
                $stmtDisponibilidad
            )
        ) {

            $error =
                mysqli_stmt_error(
                    $stmtDisponibilidad
                );

            mysqli_stmt_close(
                $stmtDisponibilidad
            );

            throw new RuntimeException(
                $error
            );
        }

        $resultadoDisponibilidad =
            mysqli_stmt_get_result(
                $stmtDisponibilidad
            );

        $disponibilidad =
            mysqli_fetch_assoc(
                $resultadoDisponibilidad
            );

        mysqli_stmt_close(
            $stmtDisponibilidad
        );

        if (
            !$disponibilidad
        ) {

            throw new DomainException(
                "La fecha seleccionada ya no está disponible."
            );
        }

        $capacidadActual =
            (int) $disponibilidad[
                "capacidad_disponible"
            ];

        if (
            $cantidadTotalBoletos >
            $capacidadActual
        ) {

            throw new DomainException(
                "No hay suficiente capacidad disponible. Actualmente hay " .
                $capacidadActual .
                " espacios."
            );
        }


        /* =============================================
           INSERTAR TICKET
        ============================================== */

        $sqlTicket = "
            INSERT INTO Ticket_Entrada
            (
                id_usuario,
                metodo_pago,
                fecha_emision,
                total_ticket,
                iva,
                id_disponibilidad,
                estado
            )
            VALUES
            (
                ?,
                ?,
                NOW(),
                ?,
                ?,
                ?,
                'Pagada'
            )
        ";

        $stmtTicket =
            mysqli_prepare(
                $conexion,
                $sqlTicket
            );

        if (!$stmtTicket) {

            throw new RuntimeException(
                "No fue posible preparar el ticket: " .
                mysqli_error($conexion)
            );
        }

        mysqli_stmt_bind_param(
            $stmtTicket,
            "isddi",
            $idUsuario,
            $descripcionMetodoPago,
            $totalTicket,
            $iva,
            $idDisponibilidad
        );

        if (
            !mysqli_stmt_execute(
                $stmtTicket
            )
        ) {

            $error =
                mysqli_stmt_error(
                    $stmtTicket
                );

            mysqli_stmt_close(
                $stmtTicket
            );

            throw new RuntimeException(
                "No fue posible registrar el ticket: " .
                $error
            );
        }

        $idTicket =
            mysqli_insert_id(
                $conexion
            );

        mysqli_stmt_close(
            $stmtTicket
        );

        if ($idTicket <= 0) {

            throw new RuntimeException(
                "No se pudo obtener el ID del ticket."
            );
        }


        /* =============================================
           INSERTAR DETALLES
        ============================================== */

        $sqlDetalle = "
            INSERT INTO Detalle_Ticket_Entrada
            (
                id_ticket_entrada,
                id_tarifa,
                cantidad,
                precio,
                subtotal
            )
            VALUES
            (
                ?,
                ?,
                ?,
                ?,
                ?
            )
        ";

        $stmtDetalle =
            mysqli_prepare(
                $conexion,
                $sqlDetalle
            );

        if (!$stmtDetalle) {

            throw new RuntimeException(
                "No fue posible preparar los detalles: " .
                mysqli_error($conexion)
            );
        }

        foreach (
            $detallesAInsertar
            as $detalle
        ) {

            mysqli_stmt_bind_param(
                $stmtDetalle,
                "iiidd",
                $idTicket,
                $detalle["id_tarifa"],
                $detalle["cantidad"],
                $detalle["precio"],
                $detalle["subtotal"]
            );

            if (
                !mysqli_stmt_execute(
                    $stmtDetalle
                )
            ) {

                $error =
                    mysqli_stmt_error(
                        $stmtDetalle
                    );

                mysqli_stmt_close(
                    $stmtDetalle
                );

                throw new RuntimeException(
                    "No fue posible registrar el detalle: " .
                    $error
                );
            }
        }

        mysqli_stmt_close(
            $stmtDetalle
        );


        /* =============================================
           GENERAR UN BOLETO POR CADA ENTRADA
        ============================================== */

        $sqlBoleto = "
            INSERT INTO Boleto_Zoologico
            (
                id_ticket_entrada,
                codigo_ingreso,
                estado_ticket
            )
            VALUES
            (
                ?,
                ?,
                'Activo'
            )
        ";

        $stmtBoleto =
            mysqli_prepare(
                $conexion,
                $sqlBoleto
            );

        if (!$stmtBoleto) {

            throw new RuntimeException(
                "No fue posible preparar la generación de boletos: " .
                mysqli_error($conexion)
            );
        }

        $boletosGenerados = 0;

        /*
         * Se genera un código independiente
         * para cada boleto comprado.
         */

        for (
            $i = 0;
            $i < $cantidadTotalBoletos;
            $i++
        ) {

            $codigoInsertado = false;

            for (
                $intento = 1;
                $intento <= 20;
                $intento++
            ) {

                $codigoIngreso =
                    generarCodigoIngreso();

                mysqli_stmt_bind_param(
                    $stmtBoleto,
                    "is",
                    $idTicket,
                    $codigoIngreso
                );

                if (
                    mysqli_stmt_execute(
                        $stmtBoleto
                    )
                ) {

                    $codigoInsertado = true;

                    $boletosGenerados++;

                    break;
                }

                /*
                 * 1062 = código duplicado.
                 * En ese caso se genera otro código.
                 */

                if (
                    mysqli_stmt_errno(
                        $stmtBoleto
                    ) !== 1062
                ) {

                    $error =
                        mysqli_stmt_error(
                            $stmtBoleto
                        );

                    mysqli_stmt_close(
                        $stmtBoleto
                    );

                    throw new RuntimeException(
                        "Error al generar el boleto: " .
                        $error
                    );
                }
            }

            if (!$codigoInsertado) {

                mysqli_stmt_close(
                    $stmtBoleto
                );

                throw new RuntimeException(
                    "No fue posible generar un código único para uno de los boletos."
                );
            }
        }

        mysqli_stmt_close(
            $stmtBoleto
        );

        if (
            $boletosGenerados !==
            $cantidadTotalBoletos
        ) {

            throw new RuntimeException(
                "La cantidad de boletos generados no coincide con la compra."
            );
        }


        /* =============================================
           ACTUALIZAR CAPACIDAD
        ============================================== */

        $sqlActualizar = "
            UPDATE Disponibilidad_Dia_Zoo
            SET capacidad_disponible =
                capacidad_disponible - ?
            WHERE id_disponibilidad = ?
              AND estado = 'Disponible'
              AND capacidad_disponible >= ?
        ";

        $stmtActualizar =
            mysqli_prepare(
                $conexion,
                $sqlActualizar
            );

        if (!$stmtActualizar) {

            throw new RuntimeException(
                "No fue posible preparar la actualización de capacidad: " .
                mysqli_error($conexion)
            );
        }

        mysqli_stmt_bind_param(
            $stmtActualizar,
            "iii",
            $cantidadTotalBoletos,
            $idDisponibilidad,
            $cantidadTotalBoletos
        );

        if (
            !mysqli_stmt_execute(
                $stmtActualizar
            )
        ) {

            $error =
                mysqli_stmt_error(
                    $stmtActualizar
                );

            mysqli_stmt_close(
                $stmtActualizar
            );

            throw new RuntimeException(
                "No fue posible actualizar la capacidad: " .
                $error
            );
        }

        $filasAfectadas =
            mysqli_stmt_affected_rows(
                $stmtActualizar
            );

        mysqli_stmt_close(
            $stmtActualizar
        );

        if (
            $filasAfectadas !== 1
        ) {

            throw new DomainException(
                "La disponibilidad cambió. Intenta nuevamente."
            );
        }


        /* =============================================
           BITÁCORA
        ============================================== */

        $detallesBitacora =
            sprintf(
                "Compra de boletos realizada. " .
                "Ticket #%d. " .
                "Boletos generados: %d. " .
                "Cantidad de boletos: %d. " .
                "Subtotal: ₡%.2f. " .
                "IVA: ₡%.2f. " .
                "Total: ₡%.2f. " .
                "Método de pago: %s. " .
                "Disponibilidad: %d.",

                $idTicket,
                $boletosGenerados,
                $cantidadTotalBoletos,
                $subtotalGeneral,
                $iva,
                $totalTicket,
                $descripcionMetodoPago,
                $idDisponibilidad
            );

        $registrada =
            registrarBitacora(
                $_SESSION["usuario"],
                "COMPRA BOLETOS EN LÍNEA",
                "Ticket_Entrada",
                $detallesBitacora,
                $idTicket
            );

        if (!$registrada) {

            throw new RuntimeException(
                "No fue posible registrar la compra en la bitácora."
            );
        }


        /* =============================================
           CONFIRMAR TRANSACCIÓN
        ============================================== */

        if (
            !mysqli_commit(
                $conexion
            )
        ) {

            throw new RuntimeException(
                "No fue posible confirmar la compra."
            );
        }

        $enTransaccion = false;


        /* =============================================
           REDIRECCIÓN
        ============================================== */

        header(
            "Location: mis_entradas.php?exito=1"
        );

        exit();


    } catch (Throwable $error) {

        if ($enTransaccion) {

            mysqli_rollback(
                $conexion
            );
        }

        error_log(
            "ERROR COMPRA BOLETOS: " .
            $error->getMessage()
        );

        if (
            $error instanceof DomainException
        ) {

            $mensaje =
                $error->getMessage();

        } else {

            /*
             * Para desarrollo mostramos el error real.
             * Luego puedes volver a ocultarlo.
             */

            $mensaje =
                "No fue posible completar la compra: " .
                $error->getMessage();
        }

        $tipoMensaje =
            "danger";
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
        Comprar Entradas - EcoFauna
    </title>


    <!-- =================================================
         BOOTSTRAP
    ================================================== -->

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >


    <!-- =================================================
         ICONOS
    ================================================== -->

    <link
        rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css"
    >


    <!-- =================================================
         FUENTES
    ================================================== -->

    <link
        rel="preconnect"
        href="https://fonts.googleapis.com"
    >

    <link
        rel="preconnect"
        href="https://fonts.gstatic.com"
    >

    <link
        href="https://fonts.googleapis.com/css2?family=DM+Serif+Display&family=Poppins:wght@400;500;600;700&display=swap"
        rel="stylesheet"
    >


    <!-- =================================================
         CSS
    ================================================== -->

    <link
        rel="stylesheet"
        href="css/styleCliente.css?v=5"
    >

    <link rel="stylesheet" href="css/ecofauna-unified.css?v=1">
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
                src="img/LogoEcoFauna1.png"
                class="logo-navbar-cliente"
                alt="Logo EcoFauna"
            >

            <span>

                EcoFauna

                <small>
                    Portal del visitante
                </small>

            </span>

        </a>


        <div class="usuario-nav">

            <a
                href="mi_perfil.php"
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

                        <?= escapar(
                            $_SESSION["usuario"] ?? ""
                        ) ?>

                    </strong>

                </div>

            </a>


            <form
                action="logout.php"
                method="POST"
                class="m-0"
            >

                <?= campoCsrfSesion("logout") ?>


                <button
                    type="submit"
                    class="btn btn-salir"
                >

                    <i class="bi bi-box-arrow-right"></i>

                    Salir

                </button>

            </form>

        </div>

    </div>

</nav>


<!-- =====================================================
     HERO
===================================================== -->

<header class="hero-cliente">

    <div class="container hero-contenido">

        <div class="hero-texto">

            <span class="hero-etiqueta">

                <i class="bi bi-ticket-perforated-fill me-1"></i>

                Reserva Online

            </span>


            <h1>

                Comprar Boletos para el Zoológico

            </h1>


            <p>

                Selecciona tu fecha de visita,
                la cantidad de entradas por categoría
                y obtén tu código de acceso al instante.

            </p>


            <div class="hero-acciones">

                <a
                    href="cliente.php"
                    class="btn btn-secundario"
                >

                    <i class="bi bi-arrow-left me-1"></i>

                    Volver al Portal

                </a>

            </div>

        </div>


        <div class="hero-ilustracion">

            <div class="circulo-grande">

                <i class="bi bi-ticket-detailed-fill"></i>

            </div>


            <span class="burbuja burbuja-uno">

                <i class="bi bi-calendar-check-fill"></i>

            </span>


            <span class="burbuja burbuja-dos">

                <i class="bi bi-credit-card-fill"></i>

            </span>


            <span class="burbuja burbuja-tres">

                <i class="bi bi-shield-check"></i>

            </span>

        </div>

    </div>

</header>


<!-- =====================================================
     CONTENIDO
===================================================== -->

<main class="container contenido-principal">


<?php if ($mensaje !== ""): ?>

    <div
        class="alert alert-<?= escapar($tipoMensaje) ?> alert-dismissible fade show rounded-4 mb-4 shadow-sm"
        role="alert"
    >

        <i class="bi bi-exclamation-triangle-fill me-2"></i>

        <?= escapar($mensaje) ?>


        <button
            type="button"
            class="btn-close"
            data-bs-dismiss="alert"
        ></button>

    </div>

<?php endif; ?>


<form
    method="POST"
    action="comprar_boletos.php"
    id="formCompraBoletos"
>


    <input
        type="hidden"
        name="procesar_compra_boletos"
        value="1"
    >


    <div class="row g-4">


        <!-- =================================================
             IZQUIERDA
        ================================================== -->

        <div class="col-lg-8">


            <!-- =============================================
                 FECHA
            ============================================== -->

            <div class="tarjeta-servicio mb-4">

                <span class="servicio-icono">

                    <i class="bi bi-calendar-event-fill"></i>

                </span>


                <h3>

                    1. Selecciona la Fecha de Visita

                </h3>


                <p class="text-muted">

                    Elige el día que deseas visitar
                    el parque de conservación EcoFauna.

                </p>


                <?php if (
                    empty($fechasDisponibles)
                ): ?>

                    <div class="alert alert-warning mb-0">

                        <i class="bi bi-info-circle me-1"></i>

                        No hay fechas con capacidad disponible.

                    </div>

                <?php else: ?>

                    <div class="row g-3 mt-1">

                        <?php foreach (
                            $fechasDisponibles
                            as $index => $fecha
                        ): ?>

                            <div class="col-md-6">

                                <input
                                    type="radio"
                                    class="btn-check"
                                    name="id_disponibilidad"
                                    id="fecha_<?= (int) $fecha["id_disponibilidad"] ?>"
                                    value="<?= (int) $fecha["id_disponibilidad"] ?>"
                                    <?= $index === 0 ? "checked" : "" ?>
                                    required
                                >


                                <label
                                    class="btn btn-outline-success w-100 p-3 text-start rounded-3 d-flex justify-content-between align-items-center"
                                    for="fecha_<?= (int) $fecha["id_disponibilidad"] ?>"
                                >

                                    <div>

                                        <strong
                                            class="d-block text-dark"
                                        >

                                            <i class="bi bi-calendar3 me-1 text-success"></i>

                                            <?= date(
                                                "d/m/Y",
                                                strtotime(
                                                    $fecha["fecha"]
                                                )
                                            ) ?>

                                        </strong>


                                        <small class="text-muted">

                                            Horario:
                                            08:00 AM - 04:00 PM

                                        </small>

                                    </div>


                                    <span
                                        class="badge bg-success-subtle text-success border border-success-subtle rounded-pill"
                                    >

                                        <?= (int) $fecha[
                                            "capacidad_disponible"
                                        ] ?>

                                        espacios

                                    </span>

                                </label>

                            </div>

                        <?php endforeach; ?>

                    </div>

                <?php endif; ?>

            </div>


            <!-- =============================================
                 BOLETOS
            ============================================== -->

            <div class="tarjeta-servicio">

                <span class="servicio-icono">

                    <i class="bi bi-person-vcard-fill"></i>

                </span>


                <h3>

                    2. Selecciona tus Entradas

                </h3>


                <p class="text-muted">

                    Elige el número de boletos por categoría.

                </p>


                <div
                    class="list-group list-group-flush rounded-3 border"
                >

                    <?php foreach (
                        $tarifas
                        as $tarifa
                    ): ?>

                        <div
                            class="list-group-item p-3 d-flex justify-content-between align-items-center flex-wrap gap-2"
                        >

                            <div>

                                <h5 class="mb-1 fw-bold text-success">

                                    <?= escapar(
                                        $tarifa["categoria"]
                                    ) ?>

                                </h5>


                                <span
                                    class="fs-6 text-dark fw-semibold"
                                >

                                    ₡<?= number_format(
                                        (float) $tarifa["precio"],
                                        2,
                                        ",",
                                        "."
                                    ) ?>


                                    <small class="text-muted">

                                        + IVA

                                    </small>

                                </span>

                            </div>


                            <div>

                                <input
                                    type="number"
                                    class="form-control text-center fw-bold input-cantidad"
                                    name="cantidad[<?= (int) $tarifa["id_tarifa"] ?>]"
                                    id="cant_<?= (int) $tarifa["id_tarifa"] ?>"
                                    value="0"
                                    min="0"
                                    max="20"
                                    data-precio="<?= (float) $tarifa["precio"] ?>"
                                >

                            </div>

                        </div>

                    <?php endforeach; ?>

                </div>

            </div>

        </div>


        <!-- =================================================
             DERECHA
        ================================================== -->

        <div class="col-lg-4">

            <div
                class="card border-0 shadow-lg rounded-4 p-3 sticky-top resumen-compra-entradas"
            >

                <div class="card-body">


                    <h4
                        class="card-title fw-bold text-success mb-3"
                    >

                        <i class="bi bi-receipt me-2"></i>

                        Resumen de Compra

                    </h4>


                    <!-- SUBTOTAL -->

                    <div
                        class="d-flex justify-content-between py-2 border-bottom"
                    >

                        <span class="text-muted">

                            Subtotal:

                        </span>


                        <strong id="resumen_subtotal">

                            ₡0,00

                        </strong>

                    </div>


                    <!-- IVA -->

                    <div
                        class="d-flex justify-content-between py-2 border-bottom"
                    >

                        <span class="text-muted">

                            IVA (13%):

                        </span>


                        <strong id="resumen_iva">

                            ₡0,00

                        </strong>

                    </div>


                    <!-- TOTAL -->

                    <div
                        class="d-flex justify-content-between py-3 border-bottom mb-3 fs-5"
                    >

                        <span class="fw-bold">

                            Total a pagar:

                        </span>


                        <strong
                            class="text-success fs-4"
                            id="resumen_total"
                        >

                            ₡0,00

                        </strong>

                    </div>


                    <!-- =====================================
                         MÉTODO DE PAGO
                    ====================================== -->

                    <div class="mb-4">

                        <label
                            for="tipo_pago"
                            class="form-label fw-bold"
                        >

                            <i class="bi bi-credit-card me-1"></i>

                            Método de Pago

                        </label>


                        <select
                            class="form-select"
                            name="tipo_pago"
                            id="tipo_pago"
                            required
                        >

                            <option value="">

                                Seleccione un método

                            </option>


                            <option value="Tarjeta">

                                Tarjeta de Crédito / Débito

                            </option>


                            <option value="SINPE Móvil">

                                SINPE Móvil

                            </option>


                            <option value="Transferencia">

                                Transferencia Bancaria

                            </option>

                        </select>

                    </div>


                    <!-- =====================================
                         TARJETAS
                    ====================================== -->

                    <div
                        id="contenedorTarjeta"
                        class="mb-4"
                        style="display:none;"
                    >

                        <label
                            for="id_tarjeta"
                            class="form-label fw-bold"
                        >

                            <i class="bi bi-credit-card-2-front me-1"></i>

                            Selecciona una tarjeta

                        </label>


                        <?php if (
                            !empty($tarjetas)
                        ): ?>

                            <select
                                class="form-select"
                                name="id_tarjeta"
                                id="id_tarjeta"
                            >

                                <option value="">

                                    Seleccione una tarjeta

                                </option>


                                <?php foreach (
                                    $tarjetas
                                    as $tarjeta
                                ): ?>

                                    <option
                                        value="<?= (int) $tarjeta["id_tarjeta"] ?>"
                                        <?= (
                                            $idTarjetaSeleccionada ===
                                            (int) $tarjeta["id_tarjeta"]
                                        )
                                            ? "selected"
                                            : "" ?>
                                    >

                                        <?= escapar(
                                            $tarjeta["banco"]
                                        ) ?>

                                        -

                                        <?= escapar(
                                            $tarjeta["tipo_tarjeta"]
                                        ) ?>

                                        |

                                        **** **** ****

                                        <?= escapar(
                                            $tarjeta["ultimos4"]
                                        ) ?>

                                    </option>

                                <?php endforeach; ?>

                            </select>


                            <a
                                href="tienda/registrar_tarjeta.php"
                                class="btn btn-outline-success btn-sm mt-2 w-100"
                            >

                                <i class="bi bi-plus-circle me-1"></i>

                                Agregar otra tarjeta

                            </a>


                        <?php else: ?>

                            <div class="alert alert-warning">

                                <i class="bi bi-credit-card me-1"></i>

                                No tienes ninguna tarjeta registrada.

                            </div>


                            <a
                                href="tienda/registrar_tarjeta.php"
                                class="btn btn-success w-100"
                            >

                                <i class="bi bi-plus-circle me-1"></i>

                                Registrar tarjeta

                            </a>

                        <?php endif; ?>

                    </div>


                    <!-- =====================================
                         MENSAJE DE PAGO
                    ====================================== -->

                    <div
                        id="mensajePago"
                        class="alert alert-info"
                    >

                        <i class="bi bi-shield-check me-1"></i>

                        Selecciona un método de pago
                        para continuar.

                    </div>


                    <!-- =====================================
                         COMPRAR
                    ====================================== -->

                    <button
                        type="submit"
                        class="btn btn-principal w-100 py-3 rounded-3 shadow"
                        <?= empty($fechasDisponibles)
                            ? "disabled"
                            : "" ?>
                    >

                        <i class="bi bi-bag-check-fill me-2"></i>

                        Confirmar y Comprar

                    </button>


                    <a
                        href="cliente.php"
                        class="btn btn-outline-secondary w-100 mt-2 py-2 rounded-3"
                    >

                        Cancelar

                    </a>

                </div>

            </div>

        </div>

    </div>

</form>


</main>


<!-- =====================================================
     FOOTER
===================================================== -->

<footer class="footer-cliente mt-5">

    <div class="container text-center">

        <span>

            <i class="bi bi-tree-fill me-1"></i>

            EcoFauna

        </span>


        <small class="d-block mt-1">

            Conservar • Educar • Proteger

        </small>

    </div>

</footer>


<!-- =====================================================
     BOOTSTRAP JS
===================================================== -->

<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.bundle.min.js"
></script>


<!-- =====================================================
     RESUMEN DE COMPRA
===================================================== -->

<script>

document.addEventListener(
    "DOMContentLoaded",
    function () {

        const inputs =
            document.querySelectorAll(
                ".input-cantidad"
            );

        const subtotalElemento =
            document.getElementById(
                "resumen_subtotal"
            );

        const ivaElemento =
            document.getElementById(
                "resumen_iva"
            );

        const totalElemento =
            document.getElementById(
                "resumen_total"
            );


        function formatoColones(valor) {

            return "₡" +
                valor.toLocaleString(
                    "es-CR",
                    {
                        minimumFractionDigits: 2,
                        maximumFractionDigits: 2
                    }
                );
        }


        function actualizarResumen() {

            let subtotal = 0;

            inputs.forEach(
                function (input) {

                    let cantidad =
                        parseInt(
                            input.value
                        ) || 0;

                    let precio =
                        parseFloat(
                            input.dataset.precio
                        ) || 0;

                    if (cantidad < 0) {

                        cantidad = 0;

                        input.value = 0;
                    }

                    if (cantidad > 20) {

                        cantidad = 20;

                        input.value = 20;
                    }

                    subtotal +=
                        cantidad * precio;
                }
            );


            const iva =
                subtotal * 0.13;

            const total =
                subtotal + iva;


            subtotalElemento.textContent =
                formatoColones(subtotal);

            ivaElemento.textContent =
                formatoColones(iva);

            totalElemento.textContent =
                formatoColones(total);
        }


        inputs.forEach(
            function (input) {

                input.addEventListener(
                    "input",
                    actualizarResumen
                );

                input.addEventListener(
                    "change",
                    actualizarResumen
                );
            }
        );


        actualizarResumen();

    }
);

</script>


<!-- =====================================================
     MÉTODO DE PAGO
===================================================== -->

<script>

document.addEventListener(
    "DOMContentLoaded",
    function () {

        const tipoPago =
            document.getElementById(
                "tipo_pago"
            );

        const contenedorTarjeta =
            document.getElementById(
                "contenedorTarjeta"
            );

        const tarjeta =
            document.getElementById(
                "id_tarjeta"
            );

        const mensajePago =
            document.getElementById(
                "mensajePago"
            );


        function actualizarMetodoPago() {

            /* =========================================
               TARJETA
            ========================================== */

            if (
                tipoPago.value ===
                "Tarjeta"
            ) {

                contenedorTarjeta.style.display =
                    "block";

                if (tarjeta) {

                    tarjeta.required =
                        true;
                }

                mensajePago.className =
                    "alert alert-success";

                mensajePago.innerHTML = `

                    <i class="bi bi-shield-check me-1"></i>

                    Selecciona una de tus tarjetas
                    registradas para realizar el pago.

                `;

                return;
            }


            /* =========================================
               SINPE
            ========================================== */

            if (
                tipoPago.value ===
                "SINPE Móvil"
            ) {

                contenedorTarjeta.style.display =
                    "none";

                if (tarjeta) {

                    tarjeta.required =
                        false;

                    tarjeta.value =
                        "";
                }

                mensajePago.className =
                    "alert alert-info";

                mensajePago.innerHTML = `

                    <i class="bi bi-phone me-1"></i>

                    El pago se realizará mediante
                    SINPE Móvil.

                `;

                return;
            }


            /* =========================================
               TRANSFERENCIA
            ========================================== */

            if (
                tipoPago.value ===
                "Transferencia"
            ) {

                contenedorTarjeta.style.display =
                    "none";

                if (tarjeta) {

                    tarjeta.required =
                        false;

                    tarjeta.value =
                        "";
                }

                mensajePago.className =
                    "alert alert-info";

                mensajePago.innerHTML = `

                    <i class="bi bi-bank me-1"></i>

                    El pago se realizará mediante
                    transferencia bancaria.

                `;

                return;
            }


            /* =========================================
               SIN MÉTODO
            ========================================== */

            contenedorTarjeta.style.display =
                "none";

            if (tarjeta) {

                tarjeta.required =
                    false;

                tarjeta.value =
                    "";
            }

            mensajePago.className =
                "alert alert-info";

            mensajePago.innerHTML = `

                <i class="bi bi-shield-check me-1"></i>

                Selecciona un método de pago
                para continuar.

            `;
        }


        tipoPago.addEventListener(
            "change",
            actualizarMetodoPago
        );


        actualizarMetodoPago();

    }
);

</script>


</body>

</html>