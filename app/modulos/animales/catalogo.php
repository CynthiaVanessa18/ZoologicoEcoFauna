<?php
require_once __DIR__ . '/../../soporte/sesion.php';
if (!isset($_SESSION['usuario'], $_SESSION['id_login']) || ($_SESSION['rol'] ?? '') !== 'Cliente') {
    header('Location: index.php');
    exit;
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/funciones.php';
$busqueda = is_string($_GET['buscar'] ?? null) ? mb_substr(trim($_GET['buscar']), 0, 100) : '';
$habitatFiltro = max(0, (int) (filter_var($_GET['habitat'] ?? 0, FILTER_VALIDATE_INT) ?: 0));
$pagina = max(1, (int) (filter_var($_GET['pagina'] ?? 1, FILTER_VALIDATE_INT) ?: 1));
$error = '';
$habitats = [];
$lista = ['animales' => [], 'total' => 0, 'pagina' => 1, 'paginas' => 1];
try {
    $lista = listarAnimales($conexion, $busqueda, $habitatFiltro, $pagina);
    $stmt = consultaAnimal($conexion, 'SELECT DISTINCT h.id_habitat,h.nombre FROM habitat h INNER JOIN animal a ON a.id_habitat=h.id_habitat ORDER BY h.nombre');
    $habitats = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
    mysqli_stmt_close($stmt);
} catch (Throwable $excepcion) {
    error_log('Catálogo de animales: ' . $excepcion->getMessage());
    $error = 'No fue posible cargar el catálogo. Inténtalo nuevamente.';
}
$esAdmin = false;
$prefijo = '';
$titulo = 'Conoce nuestros animales';
require __DIR__ . '/encabezado.php';
?>
<?php if ($error): ?><div class="aviso error" role="alert"><?= escaparAnimal($error) ?></div><?php endif; ?>
<form class="filtros panel" method="get" action="animales.php"><label>Busca un animal<input type="search" name="buscar" maxlength="100" placeholder="Por ejemplo: león o tucán" value="<?= escaparAnimal($busqueda) ?>"></label><label>Explora por hábitat<select name="habitat"><option value="0">Todos los hábitats</option><?php foreach ($habitats as $h): ?><option value="<?= (int) $h['id_habitat'] ?>" <?= $habitatFiltro === (int) $h['id_habitat'] ? 'selected' : '' ?>><?= escaparAnimal($h['nombre']) ?></option><?php endforeach; ?></select></label><button class="boton" type="submit">Explorar</button><a href="animales.php">Ver todos</a></form>
<div class="encabezado-lista"><div><span class="etiqueta verde">Habitantes de EcoFauna</span><h2><?= $lista['total'] ?> <?= $lista['total'] === 1 ? 'animal encontrado' : 'animales encontrados' ?></h2></div><a href="habitats.php">Conocer los hábitats →</a></div>
<?php if (!$lista['animales'] && !$error): ?><section class="panel vacio"><h3>No encontramos animales con esos filtros</h3><p>Prueba otro nombre o explora todos los hábitats.</p><a class="boton" href="animales.php">Ver todos</a></section><?php endif; ?>
<div class="catalogo">
<?php foreach ($lista['animales'] as $a): ?>
<article class="animal-tarjeta">
<?php if ($a['tiene_foto']): ?><img class="animal-foto" loading="lazy" src="animales/foto.php?id=<?= (int) $a['id_animal'] ?>" alt="<?= escaparAnimal($a['nombre_animal']) ?>"><?php else: ?><div class="sin-foto"><span aria-hidden="true">❧</span><small>Fotografía próximamente</small></div><?php endif; ?>
<div class="animal-info"><span class="etiqueta verde"><?= escaparAnimal($a['habitat'] ?? 'Hábitat por asignar') ?></span><h3><?= escaparAnimal($a['nombre_animal']) ?></h3>
<dl><div><dt>Peso</dt><dd><?= escaparAnimal(number_format((float) $a['peso'], 2, ',', '.')) ?> kg</dd></div><div><dt>Altura</dt><dd><?= escaparAnimal(number_format((float) $a['altura'], 2, ',', '.')) ?> m</dd></div></dl>
<?php if ($a['zona']): ?><p class="suave">Zona: <?= escaparAnimal($a['zona']) ?></p><?php endif; ?>
<p class="suave">En EcoFauna desde <?= escaparAnimal(date('Y', strtotime($a['fecha_entrada']))) ?>.</p></div>
</article>
<?php endforeach; ?>
</div>
<?php $rutaPagina = 'animales.php'; require __DIR__ . '/paginacion.php'; ?>
<section class="visita"><div><h2>Conócelos de cerca</h2><p>Prepara tu recorrido y disfruta de una visita a EcoFauna.</p></div><a class="boton" href="comprar_boletos.php">Planifica tu visita</a></section>
<?php require __DIR__ . '/pie.php'; ?>
