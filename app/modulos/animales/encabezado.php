<?php /* Plantilla interna: requiere sesión validada por la página. */ ?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= escaparAnimal($titulo) ?> · EcoFauna</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display&family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= $prefijo ?>css/animales.css?v=1">
</head>
<body>
<header class="barra">
    <a class="marca" href="<?= $prefijo . ($esAdmin ? 'admin.php' : 'cliente.php') ?>"><img src="<?= $prefijo ?>img/LogoEcoFauna1.png" alt=""><span>EcoFauna<small><?= $esAdmin ? 'Administración de animales' : 'Descubre nuestra fauna' ?></small></span></a>
    <nav aria-label="Navegación principal">
        <a href="<?= $prefijo . ($esAdmin ? 'admin.php' : 'cliente.php') ?>">Inicio</a>
        <?php if (!$esAdmin): ?><a href="habitats.php">Hábitats</a><a href="comprar_boletos.php">Planifica tu visita</a><?php endif; ?>
        <form action="<?= $prefijo ?>logout.php" method="post"><?= campoCsrfSesion('logout') ?><button class="boton secundario" type="submit">Salir</button></form>
    </nav>
</header>
<section class="hero">
    <div class="contenedor"><span class="etiqueta"><?= $esAdmin ? 'Cuidado y organización' : 'Conoce, aprende y conserva' ?></span><h1><?= escaparAnimal($titulo) ?></h1><p><?= $esAdmin ? 'Mantén actualizada la información de los animales y sus hábitats.' : 'Conoce a los habitantes de EcoFauna y descubre dónde encontrarlos en tu próxima visita.' ?></p></div>
</section>
<main class="contenedor contenido">
