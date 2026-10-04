<?php

require_once __DIR__ . "/../../soporte/sesion.php";
require_once __DIR__ . "/../../../config/database.php";
require_once __DIR__ . "/../../soporte/funciones_tienda.php";

/** @var mysqli $conexion */


if (!clienteTiendaAutenticado()) {

    header("Location: ../index.php");
    exit();

}


if ($_SERVER["REQUEST_METHOD"] !== "POST") {

    header("Location: carrito.php");
    exit();

}


if (!tokenCsrfSesionValido("carrito", $_POST["csrf_token"] ?? null)) {

    $_SESSION["mensaje_tienda"] =
        "La solicitud expiró. Actualiza la página.";

    $_SESSION["tipo_mensaje_tienda"] = "danger";

    header("Location: carrito.php");
    exit();

}



$carrito = normalizarCarritoTienda($_SESSION["carrito"] ?? []);

$_SESSION["carrito"] = $carrito;



if (empty($carrito)) {

    header("Location: cliente.php");
    exit();

}



$id_usuario = (int) $_SESSION["id_usuario"];



/* ===============================
   DATOS PAGO
================================ */


$id_tarjeta = (int) ($_POST["id_tarjeta"] ?? 0);



if ($id_tarjeta <= 0) {

    $_SESSION["mensaje_tienda"] =
        "Debe seleccionar una tarjeta.";

    $_SESSION["tipo_mensaje_tienda"] = "danger";

    header("Location: carrito.php");

    exit();

}



/* ===============================
   DATOS ENTREGA
================================ */


$tipo_entrega = trim($_POST["tipo_entrega"] ?? "");

$id_sucursal = null;

$id_direccion = null;



if ($tipo_entrega == "") {

    $_SESSION["mensaje_tienda"] =
        "Debe seleccionar una forma de entrega.";

    $_SESSION["tipo_mensaje_tienda"] = "danger";

    header("Location: carrito.php");

    exit();

}



$total = 0;

$errorMensaje = "";

$tarjeta = null;



mysqli_begin_transaction($conexion);



try {



    /* ===============================
       VALIDAR PRODUCTOS
    ================================ */


    foreach ($carrito as $id_producto => $cantidad) {



        $stmt = mysqli_prepare(

            $conexion,

            "SELECT

            p.nombre_producto,
            it.precio_venta,
            it.stock

        FROM inventario_tienda it

        INNER JOIN producto p

        ON it.id_producto = p.id_producto

        WHERE it.id_producto=?

        FOR UPDATE"

        );



        mysqli_stmt_bind_param(

            $stmt,

            "i",

            $id_producto

        );



        mysqli_stmt_execute($stmt);



        $resultado = mysqli_stmt_get_result($stmt);



        $producto = mysqli_fetch_assoc($resultado);



        mysqli_stmt_close($stmt);



        if (!$producto) {

            throw new Exception(
                "Producto no encontrado."
            );

        }



        if ($producto["stock"] < $cantidad) {

            throw new Exception(
                "Stock insuficiente para " . $producto["nombre_producto"]
            );

        }



        $total +=
            $producto["precio_venta"] * $cantidad;



    }



    /* ===============================
       VALIDAR TARJETA CLIENTE
    ================================ */


    $stmt = mysqli_prepare(

        $conexion,

        "SELECT

    tc.id_tarjeta,
    tc.numero_tarjeta,
    tc.titular,
    tc.fecha_vencimiento,
    tc.tipo_tarjeta,
    tc.banco

FROM tarjeta_cliente tc

WHERE tc.id_tarjeta=?

AND tc.id_usuario=?

AND tc.estado='Activa'

FOR UPDATE"

    );



    mysqli_stmt_bind_param(

        $stmt,

        "ii",

        $id_tarjeta,

        $id_usuario

    );



    mysqli_stmt_execute($stmt);



    $resultado = mysqli_stmt_get_result($stmt);



    $tarjeta = mysqli_fetch_assoc($resultado);



    mysqli_stmt_close($stmt);



    if (!$tarjeta) {

        throw new Exception(
            "La tarjeta seleccionada no es válida."
        );

    }
/* ===============================
   OBTENER DIRECCIÓN ENTREGA
================================ */

if ($tipo_entrega == "Entrega") {


    $stmt = mysqli_prepare(
        $conexion,
        "SELECT id_direccion
         FROM direccion_entrega
         WHERE id_usuario = ?
         LIMIT 1"
    );


    mysqli_stmt_bind_param(
        $stmt,
        "i",
        $id_usuario
    );


    mysqli_stmt_execute($stmt);


    $resultadoDireccion = mysqli_stmt_get_result($stmt);


    $direccionEntrega = mysqli_fetch_assoc($resultadoDireccion);


    mysqli_stmt_close($stmt);



    if (!$direccionEntrega) {

        throw new Exception(
            "Debe registrar una dirección de entrega."
        );

    }


    $id_direccion = $direccionEntrega["id_direccion"];


}





    /* ===============================
       VALIDAR RETIRO
    ================================ */


    if ($tipo_entrega == "Retiro") {


        $id_sucursal = (int) ($_POST["id_sucursal"] ?? 0);



        if ($id_sucursal <= 0) {


            throw new Exception(

                "Debe seleccionar una sucursal."

            );


        }


    }





    /* ===============================
       CREAR VENTA
    ================================ */


    $stmt = mysqli_prepare(

        $conexion,


        "INSERT INTO venta_tienda

(

    id_usuario,

    metodo_pago,

    tipo_entrega,

    id_direccion,

    id_sucursal,

    id_tarjeta,

    total_factura,

    estado

)

VALUES

(

    ?,

    'Tarjeta',

    ?,

    ?,

    ?,

    ?,

    ?,

    'Pendiente'

)"

    );



    mysqli_stmt_bind_param(

        $stmt,

        "isiiid",

        $id_usuario,

        $tipo_entrega,

        $id_direccion,

        $id_sucursal,

        $tarjeta["id_tarjeta"],

        $total

    );



    mysqli_stmt_execute($stmt);



    $id_venta = mysqli_insert_id($conexion);



    mysqli_stmt_close($stmt);



    if ($id_venta <= 0) {


        throw new Exception(

            "No se pudo crear la venta."

        );


    }





    /* ===============================
       INSERTAR DETALLE VENTA
    ================================ */


    foreach ($carrito as $id_producto => $cantidad) {



        $stmt = mysqli_prepare(

            $conexion,


            "INSERT INTO detalle_venta_tienda

    (

        id_venta_tienda,

        id_producto,

        cantidad,

        precio_unitario,

        subtotal

    )


    SELECT

        ?,

        id_producto,

        ?,

        precio_venta,

        (precio_venta * ?)


    FROM inventario_tienda


    WHERE id_producto=?"

        );



        mysqli_stmt_bind_param(

            $stmt,

            "iiii",

            $id_venta,

            $cantidad,

            $cantidad,

            $id_producto

        );



        mysqli_stmt_execute($stmt);



        mysqli_stmt_close($stmt);





        /* ===============================
           ACTUALIZAR STOCK
        ================================ */


        $stmt = mysqli_prepare(

            $conexion,


            "UPDATE inventario_tienda

    SET stock = stock - ?

    WHERE id_producto=?"

        );



        mysqli_stmt_bind_param(

            $stmt,

            "ii",

            $cantidad,

            $id_producto

        );



        mysqli_stmt_execute($stmt);



        mysqli_stmt_close($stmt);



    }





    /* ===============================
       REGISTRAR PAGO TARJETA
    ================================ */


    $stmt = mysqli_prepare(

        $conexion,


        "INSERT INTO pago_tarjeta

(

    id_tarjeta,

    id_venta_tienda,

    monto,

    estado

)

VALUES

(

    ?,

    ?,

    ?,

    'Aprobado'

)"

    );



    mysqli_stmt_bind_param(

        $stmt,

        "iid",

        $tarjeta["id_tarjeta"],

        $id_venta,

        $total

    );



    mysqli_stmt_execute($stmt);



    mysqli_stmt_close($stmt);






    /* ===============================
       ACTUALIZAR ESTADO VENTA
    ================================ */


    $stmt = mysqli_prepare(

        $conexion,


        "UPDATE venta_tienda

SET estado='Pagada'

WHERE id_venta_tienda=?"

    );



    mysqli_stmt_bind_param(

        $stmt,

        "i",

        $id_venta

    );



    mysqli_stmt_execute($stmt);



    mysqli_stmt_close($stmt);






    $detalleBitacora = sprintf(
        "Compra pagada. Total: ₡%s. Modalidad: %s.",
        number_format((float) $total, 2, ".", ""),
        $tipo_entrega,
    );

    if (!registrarBitacora(
        $_SESSION["usuario"],
        "COMPRA TIENDA",
        "venta_tienda",
        $detalleBitacora,
        $id_venta,
    )) {
        throw new RuntimeException(
            "No fue posible registrar la compra en la bitácora."
        );
    }

    if (!mysqli_commit($conexion)) {
        throw new RuntimeException(
            "No fue posible confirmar la compra."
        );
    }
$detalleEntrega = "";

if ($tipo_entrega == "Entrega") {

    $stmt = mysqli_prepare(
        $conexion,
        "SELECT
            provincia,
            canton,
            distrito,
            direccion,
            nombre_recibe
         FROM direccion_entrega
         WHERE id_direccion=?"
    );

    mysqli_stmt_bind_param(
        $stmt,
        "i",
        $id_direccion
    );

    mysqli_stmt_execute($stmt);

    $resultado = mysqli_stmt_get_result($stmt);

    $direccion = mysqli_fetch_assoc($resultado);

    mysqli_stmt_close($stmt);

} else {

    $stmt = mysqli_prepare(
        $conexion,
        "SELECT
            nombre_sucursal,
            provincia,
            canton,
            distrito,
            direccion
         FROM sucursal
         WHERE id_sucursal=?"
    );

    mysqli_stmt_bind_param(
        $stmt,
        "i",
        $id_sucursal
    );

    mysqli_stmt_execute($stmt);

    $resultado = mysqli_stmt_get_result($stmt);

    $sucursal = mysqli_fetch_assoc($resultado);

    mysqli_stmt_close($stmt);

}


    unset($_SESSION["carrito"]);



    renovarTokenCsrfSesion("carrito");



} catch (Throwable $e) {



    mysqli_rollback($conexion);



    $errorMensaje = $e->getMessage();



    error_log(

        $e->getMessage()

    );



}
?>

<!DOCTYPE html>
<html lang="es">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>
        <?= empty($errorMensaje) ? "Compra realizada" : "Error en la compra" ?> - EcoFauna
    </title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/css/bootstrap.min.css" rel="stylesheet">

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">

    <link
        href="https://fonts.googleapis.com/css2?family=DM+Serif+Display&family=Poppins:wght@400;500;600;700&display=swap"
        rel="stylesheet">

    <link rel="stylesheet" href="../css/styleCliente.css?v=3">

    <link rel="stylesheet" href="../css/ecofauna-unified.css?v=1">
</head>


<body class="ecofauna-unified">


    <nav class="navbar navbar-cliente">

        <div class="container-fluid px-4">


            <a class="navbar-brand" href="cliente.php">

                <img src="../img/LogoEcoFauna1.png" class="logo-navbar-cliente" alt="EcoFauna">


                <span>

                    EcoFauna

                    <small>
                        Portal del visitante
                    </small>

                </span>


            </a>



            <div class="usuario-nav">


                <div class="usuario-info">


                  <a href="../mi_perfil.php" class="usuario-info text-decoration-none">
                    <span class="usuario-avatar">
                        <i class="bi bi-person-fill"></i>
                    </span>
                    <div>
                        <small>Cliente</small>
                        <strong>

                            <?= htmlspecialchars($_SESSION["usuario"]) ?>

                        </strong>

                    </div>
                </a>

                </div>


            </div>


        </div>

    </nav>




    <header class="hero-cliente">


        <div class="container hero-contenido">


            <div class="hero-texto">


                <?php if (empty($errorMensaje)) { ?>


                    <span class="hero-etiqueta">

                        <i class="bi bi-check-circle-fill"></i>

                        Compra completada

                    </span>



                    <h1>

                        ¡Compra realizada correctamente!

                    </h1>



                    <p>

                        Gracias por comprar en EcoFauna.
                        Tu pedido fue registrado exitosamente.

                    </p>



                <?php } else { ?>



                    <span class="hero-etiqueta">

                        <i class="bi bi-exclamation-triangle-fill"></i>

                        Error

                    </span>



                    <h1>

                        No se pudo completar la compra

                    </h1>



                    <p>

                        Revisa la información e inténtalo nuevamente.

                    </p>



                <?php } ?>


            </div>




            <div class="hero-ilustracion">


                <div class="circulo-grande">


                    <i class="bi 
<?= empty($errorMensaje)
    ? "bi-cart-check-fill"
    : "bi-cart-x-fill" ?>">
                    </i>


                </div>



                <span class="burbuja burbuja-uno">


                    <i class="bi 
<?= empty($errorMensaje)
    ? "bi-check-lg"
    : "bi-x-lg" ?>">
                    </i>


                </span>



                <span class="burbuja burbuja-dos">

                    <i class="bi bi-credit-card-fill"></i>

                </span>



                <span class="burbuja burbuja-tres">

                    <i class="bi bi-bag-heart-fill"></i>

                </span>



            </div>


        </div>


    </header>





    <main class="container contenido-principal">


        <section class="carrito-card">


            <div class="card shadow-lg border-0">


                <div class="card-body p-4">



                    <?php if (empty($errorMensaje)) { ?>



                        <div class="alert alert-success text-center">


                            <i class="bi bi-check-circle-fill fs-1"></i>


                            <h3 class="mt-3">

                                ¡Gracias por tu compra!

                            </h3>


                            <p>

                                Tu pedido fue procesado correctamente.

                            </p>


                        </div>




                        <table class="table table-bordered align-middle">

    <tbody>

        <tr>
            <th width="35%">Número de venta</th>
            <td><?= $id_venta ?></td>
        </tr>

        <tr>
            <th>Fecha</th>
            <td><?= date("d/m/Y H:i") ?></td>
        </tr>

        <tr>
            <th>Cliente</th>
            <td><?= htmlspecialchars($_SESSION["usuario"]) ?></td>
        </tr>

        <tr>
            <th>Tipo de entrega</th>
            <td>

                <?php if ($tipo_entrega == "Entrega") { ?>

                    Entrega a domicilio

                <?php } else { ?>

                    Recoger en tienda

                <?php } ?>

            </td>
        </tr>

        <tr>
            <th>Método de pago</th>
            <td>Tarjeta</td>
        </tr>

        <tr>
            <th>Total pagado</th>
            <td>
                <strong class="text-success">
                    ₡<?= number_format($total,2,",",".") ?>
                </strong>
            </td>
        </tr>

        <tr>
            <th>Estado del pedido</th>
            <td>
                <span class="badge bg-success">
                    Pagada
                </span>
            </td>
        </tr>

        <?php if ($tipo_entrega == "Entrega") { ?>

            <tr>

                <th>Dirección de entrega</th>

                <td>

                    <strong>
                        <?= htmlspecialchars($direccion["nombre_recibe"]) ?>
                    </strong>

                    <br>

                    <?= htmlspecialchars($direccion["provincia"]) ?>,
                    <?= htmlspecialchars($direccion["canton"]) ?>,
                    <?= htmlspecialchars($direccion["distrito"]) ?>

                    <br>

                    <?= htmlspecialchars($direccion["direccion"]) ?>

                    <div class="mt-2 text-success">

                        <i class="bi bi-truck"></i>

                        Tiempo estimado:
                        <strong>1 a 3 días hábiles</strong>

                    </div>

                </td>

            </tr>

        <?php } else { ?>

            <tr>

                <th>Lugar de retiro</th>

                <td>

                    <strong>

                        <?= htmlspecialchars($sucursal["nombre_sucursal"]) ?>

                    </strong>

                    <br>

                    <?= htmlspecialchars($sucursal["provincia"]) ?>,
                    <?= htmlspecialchars($sucursal["canton"]) ?>,
                    <?= htmlspecialchars($sucursal["distrito"]) ?>

                    <br>

                    <?= htmlspecialchars($sucursal["direccion"]) ?>

                    <div class="mt-2 text-primary">

                        <i class="bi bi-shop"></i>

                        Puedes retirar tu pedido durante el horario de atención.

                    </div>

                </td>

            </tr>

        <?php } ?>

    </tbody>

</table>





                        <div class="text-center mt-4">



                            <a href="cliente.php" class="btn btn-success btn-carrito">


                                <i class="bi bi-shop"></i>

                                Seguir comprando


                            </a>




                            <a href="mis_compras.php" class="btn btn-outline-success btn-carrito">


                                <i class="bi bi-receipt"></i>

                                Ver compras


                            </a>



                        </div>





                    <?php } else { ?>



                        <div class="alert alert-danger text-center">


                            <i class="bi bi-x-circle-fill fs-1"></i>


                            <h3 class="mt-3">

                                Error en la compra

                            </h3>



                            <p>

                                <?= htmlspecialchars($errorMensaje) ?>

                            </p>



                        </div>




                        <div class="text-center">


                            <a href="carrito.php" class="btn btn-danger btn-carrito">


                                <i class="bi bi-cart"></i>

                                Volver al carrito


                            </a>


                        </div>



                    <?php } ?>



                </div>


            </div>


        </section>


    </main>





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



</body>

</html>
