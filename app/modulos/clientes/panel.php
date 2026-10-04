<?php

/* =====================================================
   INICIO DE SESIÓN Y CONEXIÓN
===================================================== */

require_once __DIR__ . "/../../soporte/sesion.php";

require_once __DIR__ . "/../../../config/database.php";

/** @var mysqli $conexion */
/**
 * Protege un texto antes de mostrarlo en el HTML.
 */
function escapar(string $texto): string
{
    return htmlspecialchars($texto, ENT_QUOTES, "UTF-8");
}
function formatearFecha(?string $fecha, string $textoAlternativo): string
{
    if (empty($fecha)) {
        return $textoAlternativo;
    }
    $timestamp = strtotime($fecha);
    if ($timestamp === false) {
        return $textoAlternativo;
    }

    return date("d/m/Y", $timestamp);
}

if (
    !isset($_SESSION["usuario"], $_SESSION["rol"], $_SESSION["id_login"]) ||
    $_SESSION["rol"] !== "Cliente"
) {
    header("Location: index.php");
    exit();
}
$nombreUsuario = escapar($_SESSION["usuario"]);
$idUsuario = $_SESSION["id_usuario"] ?? null;
$entradasCompradas = 0;
$proximaVisita = null;
$ultimaCompra = null;

if ($idUsuario !== null) {

    $sqlResumen = "
        SELECT
            COUNT(*) AS total_entradas,

            MIN(
                CASE
                    WHEN ddz.fecha >= CURDATE()
                    THEN ddz.fecha
                    ELSE NULL
                END
            ) AS proxima_visita,

            MAX(te.fecha_emision) AS ultima_compra

        FROM ticket_entrada AS te

        LEFT JOIN disponibilidad_dia_zoo AS ddz
            ON te.id_disponibilidad = ddz.id_disponibilidad

        WHERE te.id_usuario = ?
          AND te.estado = 'Pagada'
    ";

    $stmtResumen = mysqli_prepare($conexion, $sqlResumen);

    if ($stmtResumen) {
        mysqli_stmt_bind_param($stmtResumen, "i", $idUsuario);
        mysqli_stmt_execute($stmtResumen);
        $resultadoResumen = mysqli_stmt_get_result($stmtResumen);
        $resumen = mysqli_fetch_assoc($resultadoResumen);
        mysqli_stmt_close($stmtResumen);
        if ($resumen) {
            $entradasCompradas = (int) ($resumen["total_entradas"] ?? 0);

            $proximaVisita = $resumen["proxima_visita"] ?? null;

            $ultimaCompra = $resumen["ultima_compra"] ?? null;
        }
    }
}

$proximaVisitaTexto = formatearFecha($proximaVisita, "Sin programar");
$ultimaCompraTexto = formatearFecha($ultimaCompra, "Sin registros");
$sqlProductos = "
    SELECT
        p.id_Producto,
        p.nombre_producto,
        i.foto,
        i.precio_venta,
        i.stock
    FROM inventario_tienda i
    INNER JOIN Producto p
        ON i.id_producto = p.id_Producto
    WHERE i.stock > 0
      AND p.estado = 'Activo'
    ORDER BY p.nombre_producto
    LIMIT 4
";

$resultadoProductos = mysqli_query($conexion, $sqlProductos);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inicio - EcoFauna</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/cliente-home.css?v=1">
</head>
<body class="cliente-home">
<nav class="home-nav" aria-label="Navegación principal">
    <div class="home-shell home-nav-inner">
        <a class="home-brand" href="cliente.php" aria-label="EcoFauna, inicio">
            <img src="img/LogoEcoFauna1.png" alt="EcoFauna">
        </a>
        <div class="home-links">
            <a class="active" href="cliente.php"><i class="bi bi-house-door-fill"></i><span>Inicio</span></a>
            <a href="animales.php"><i class="bi bi-feather"></i><span>Animales</span></a>
            <a href="habitats.php"><i class="bi bi-tree-fill"></i><span>Hábitats</span></a>
            <a href="#conservacion"><i class="bi bi-leaf-fill"></i><span>Conservación</span></a>
            <a href="comprar_boletos.php"><i class="bi bi-geo-alt-fill"></i><span>Visítanos</span></a>
        </div>
        <div class="home-account">
            <a href="mi_perfil.php" class="home-profile">
                <i class="bi bi-person-circle"></i>
                <div><small>Mi cuenta</small><strong><?= $nombreUsuario ?></strong></div>
            </a>
            <form action="logout.php" method="POST">
                <?= campoCsrfSesion("logout") ?>
                <button class="home-logout" type="submit" title="Cerrar sesión" aria-label="Cerrar sesión"><i class="bi bi-box-arrow-right"></i></button>
            </form>
        </div>
    </div>
</nav>
<header class="home-hero">
    <div class="home-shell">
        <div class="hero-copy">
            <span class="hero-kicker">Bienvenido, <?= $nombreUsuario ?></span>
            <h1>Zoológico <span>EcoFauna</span></h1>
            <p>Conservamos especies, educamos para la vida y conectamos personas con la naturaleza.</p>
            <div class="hero-actions">
                <a class="home-btn home-btn-primary" href="animales.php"><i class="bi bi-feather"></i> Ver animales</a>
                <a class="home-btn home-btn-outline" href="comprar_boletos.php"><i class="bi bi-calendar2-check"></i> Planifica tu visita</a>
            </div>
        </div>
    </div>
</header>
<section class="home-shell stats-wrap" aria-label="Resumen del visitante">
    <div class="home-stats">
        <article class="home-stat"><span class="home-stat-icon"><i class="bi bi-ticket-perforated-fill"></i></span><div><strong><?= $entradasCompradas ?></strong><small>Entradas adquiridas</small></div></article>
        <article class="home-stat"><span class="home-stat-icon"><i class="bi bi-calendar-heart-fill"></i></span><div><strong><?= escapar($proximaVisitaTexto) ?></strong><small>Próxima visita</small></div></article>
        <article class="home-stat"><span class="home-stat-icon"><i class="bi bi-bag-check-fill"></i></span><div><strong><?= escapar($ultimaCompraTexto) ?></strong><small>Última compra</small></div></article>
        <article class="home-stat"><span class="home-stat-icon"><i class="bi bi-shield-check"></i></span><div><strong>Conservación</strong><small>Protegemos nuestro futuro</small></div></article>
    </div>
</section>
<main class="home-shell home-main">
    <?php if ($idUsuario === null): ?>
        <div class="alert alert-warning">Esta cuenta de demostración todavía no está vinculada a un registro personal.</div>
    <?php endif; ?>
    <div class="section-heading"><span>Descubre EcoFauna</span><h2>Explora nuestros hábitats</h2><p>Conoce algunos de los ambientes y especies que puedes visitar.</p></div>
    <div class="home-grid">
        <section class="habitat-grid" aria-label="Hábitats destacados">
            <article class="habitat-card"><div class="habitat-photo"><img src="uploads/habitats/reserva_de_herb__voros_773142821c.webp" alt="Reserva de herbívoros"><span><i class="bi bi-tree-fill"></i></span></div><div class="habitat-info"><h3>Reserva de Herbívoros</h3><p>Amplios espacios naturales para especies de sabana.</p><a href="habitats.php">Ver más <i class="bi bi-arrow-right"></i></a></div></article>
            <article class="habitat-card"><div class="habitat-photo"><img src="uploads/habitats/bosque_tropical_caf01db3e6.jpg" alt="Bosque tropical"><span><i class="bi bi-leaf-fill"></i></span></div><div class="habitat-info"><h3>Bosque Tropical</h3><p>Biodiversidad y vegetación en un entorno exuberante.</p><a href="habitats.php">Ver más <i class="bi bi-arrow-right"></i></a></div></article>
            <article class="habitat-card"><div class="habitat-photo"><img src="uploads/habitats/laguna_de_aves_c046181ac9.jpg" alt="Laguna de aves"><span><i class="bi bi-droplet-fill"></i></span></div><div class="habitat-info"><h3>Laguna de Aves</h3><p>Vida entre el agua, el cielo y la vegetación natural.</p><a href="habitats.php">Ver más <i class="bi bi-arrow-right"></i></a></div></article>
        </section>
        <aside class="visit-panel">
            <h2>Tu próxima aventura</h2>
            <div class="visit-item"><div class="visit-date"><i class="bi bi-calendar2-event"></i><strong><?= $entradasCompradas ?></strong></div><div><h3>Entradas disponibles</h3><p>Consulta tus boletos, fechas y estados desde tu cuenta.</p></div></div>
            <div class="visit-item"><div class="visit-date"><i class="bi bi-clock"></i><strong>9</strong></div><div><h3>Llega temprano</h3><p>Te recomendamos iniciar el recorrido por la mañana.</p></div></div>
            <a class="visit-link" href="mis_entradas.php">Ver mis entradas <i class="bi bi-arrow-right"></i></a>
        </aside>
    </div>
    <section class="services-strip" id="servicios">
        <div class="services-strip-head"><h2>Servicios para tu visita</h2></div>
        <div class="services-row">
            <a class="service-link" href="mis_entradas.php"><i class="bi bi-ticket-perforated-fill"></i><span><strong>Mis entradas</strong><small>Consulta tus boletos</small></span></a>
            <a class="service-link" href="comprar_boletos.php"><i class="bi bi-calendar-plus-fill"></i><span><strong>Comprar entradas</strong><small>Reserva una fecha</small></span></a>
            <a class="service-link" href="tienda/cliente.php"><i class="bi bi-shop"></i><span><strong>Tienda en línea</strong><small>Productos EcoFauna</small></span></a>
            <a class="service-link" href="habitats.php"><i class="bi bi-map-fill"></i><span><strong>Mapa de hábitats</strong><small>Prepara el recorrido</small></span></a>
        </div>
    </section>
    <section class="conservation" id="conservacion">
        <span class="conservation-icon"><i class="bi bi-heart-fill"></i></span>
        <div><h2>Apoya la conservación</h2><p>Cada visita ayuda a cuidar nuestros animales y sus hábitats para las futuras generaciones.</p></div>
        <a class="home-btn home-btn-primary" href="comprar_boletos.php"><i class="bi bi-heart"></i> Quiero visitar</a>
    </section>
</main>
<footer class="home-footer">
    <div class="home-shell footer-grid">
        <div class="footer-brand"><img src="img/LogoEcoFauna1.png" alt="EcoFauna"><p>Una experiencia para conectar con la naturaleza.</p></div>
        <div class="footer-column"><h3>Enlaces rápidos</h3><a href="cliente.php">Inicio</a><a href="animales.php">Animales</a><a href="habitats.php">Hábitats</a><a href="comprar_boletos.php">Visítanos</a></div>
        <div class="footer-column"><h3>Tu cuenta</h3><a href="mi_perfil.php">Mi perfil</a><a href="mis_entradas.php">Mis entradas</a><a href="tienda/cliente.php">Tienda</a></div>
        <div class="footer-column"><h3>EcoFauna</h3><span><i class="bi bi-clock"></i> Abierto todos los días</span><span><i class="bi bi-shield-check"></i> Visita segura</span><div class="footer-social"><a href="#" aria-label="Instagram"><i class="bi bi-instagram"></i></a><a href="#" aria-label="Facebook"><i class="bi bi-facebook"></i></a></div></div>
    </div>
</footer>
</body>
</html>