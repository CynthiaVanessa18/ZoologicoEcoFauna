<?php
require_once __DIR__ . '/../../soporte/sesion.php';
if (!isset($_SESSION['usuario'], $_SESSION['id_login']) || ($_SESSION['rol'] ?? '') !== 'Administrador') {
    header('Location: ../index.php');
    exit;
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/funciones.php';
$error = '';
$mensaje = $_SESSION['mensaje_animales'] ?? '';
unset($_SESSION['mensaje_animales']);
$datos = array_fill_keys(['codigo_animal','nombre_animal','fecha_nacimiento','fecha_entrada','peso','altura','id_habitat'], '');
$datos['fecha_entrada'] = date('Y-m-d');
$id = (int) (filter_var($_GET['editar'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0);
$confirmar = (int) (filter_var($_GET['confirmar'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0);
$formulario = isset($_GET['nuevo']) || $id > 0;
$tieneFoto = false;
$animalEliminar = null;
$busqueda = is_string($_GET['buscar'] ?? null) ? mb_substr(trim($_GET['buscar']), 0, 100) : '';
$habitatFiltro = max(0, (int) (filter_var($_GET['habitat'] ?? 0, FILTER_VALIDATE_INT) ?: 0));
$pagina = max(1, (int) (filter_var($_GET['pagina'] ?? 1, FILTER_VALIDATE_INT) ?: 1));
$habitats = [];
$lista = ['animales' => [], 'total' => 0, 'pagina' => 1, 'paginas' => 1];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!tokenCsrfSesionValido('animales_admin', $_POST['csrf_token'] ?? null)) {
            http_response_code(403);
            throw new DomainException('La sesión del formulario venció. Recarga la página e inténtalo de nuevo.');
        }
        $accion = textoAnimal($_POST, 'accion');
        $idTexto = textoAnimal($_POST, 'id_animal');
        $idValidado = filter_var($idTexto, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($idValidado === false || !in_array($accion, ['guardar', 'eliminar'], true) || ($accion === 'eliminar' && $idValidado === 0)) {
            throw new DomainException('La operación solicitada no es válida.');
        }
        $id = $idValidado;
        if ($accion === 'eliminar') {
            eliminarAnimal($conexion, $id);
            $_SESSION['mensaje_animales'] = 'Animal eliminado correctamente.';
        } else {
            $formulario = true;
            foreach (array_keys($datos) as $campo) {
                $datos[$campo] = textoAnimal($_POST, $campo);
            }
            $validados = validarAnimal($_POST);
            $foto = recibirFotoAnimal($_FILES['foto'] ?? null);
            guardarAnimal($conexion, $validados, $id, $foto, isset($_POST['quitar_foto']));
            $_SESSION['mensaje_animales'] = $id > 0 ? 'Cambios guardados correctamente.' : 'Animal registrado correctamente.';
        }
        renovarTokenCsrfSesion('animales_admin');
        header('Location: animales.php', true, 303);
        exit;
    } catch (DomainException $excepcion) {
        $error = $excepcion->getMessage();
    } catch (Throwable $excepcion) {
        error_log('Administración de animales: ' . $excepcion->getMessage());
        $error = 'No se pudo completar la operación. Inténtalo nuevamente.';
    }
}
try {
    $stmt = consultaAnimal($conexion, 'SELECT id_habitat, nombre, estado FROM habitat ORDER BY nombre');
    $habitats = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
    mysqli_stmt_close($stmt);
    if ($id > 0 && $formulario) {
        $stmt = consultaAnimal($conexion, 'SELECT codigo_animal,nombre_animal,fecha_nacimiento,fecha_entrada,peso,altura,id_habitat,(foto IS NOT NULL AND OCTET_LENGTH(foto)>0) AS tiene_foto FROM animal WHERE id_animal=?', 'i', [$id]);
        $actual = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
        if (!$actual) {
            $formulario = false;
            throw new DomainException('No se encontró el animal seleccionado.');
        }
        $tieneFoto = (bool) $actual['tiene_foto'];
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $datos = $actual;
        }
    }
    if ($confirmar > 0) {
        $stmt = consultaAnimal($conexion, 'SELECT id_animal,nombre_animal,codigo_animal FROM animal WHERE id_animal=?', 'i', [$confirmar]);
        $animalEliminar = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
        if (!$animalEliminar) {
            throw new DomainException('No se encontró el animal seleccionado.');
        }
    }
    $lista = listarAnimales($conexion, $busqueda, $habitatFiltro, $pagina);
} catch (DomainException $excepcion) {
    $error = $excepcion->getMessage();
} catch (Throwable $excepcion) {
    error_log('Consulta de animales: ' . $excepcion->getMessage());
    $error = 'No fue posible cargar los animales. Inténtalo nuevamente.';
}
$esAdmin = true;
$prefijo = '../';
$titulo = 'Administración de animales';
require __DIR__ . '/encabezado.php';
?>
<?php if ($mensaje): ?><div class="aviso exito" role="status"><?= escaparAnimal($mensaje) ?></div><?php endif; ?>
<?php if ($error): ?><div class="aviso error" role="alert"><?= escaparAnimal($error) ?></div><?php endif; ?>
<?php if ($animalEliminar): ?>
<section class="panel confirmacion"><h2>Eliminar <?= escaparAnimal($animalEliminar['nombre_animal']) ?></h2><p>Se eliminará el registro <?= escaparAnimal($animalEliminar['codigo_animal']) ?>. Esta acción no se puede deshacer. Si tiene historial o cuidadores asociados, se conservará.</p>
<form action="animales.php" method="post" class="acciones"><?= campoCsrfSesion('animales_admin') ?><input type="hidden" name="accion" value="eliminar"><input type="hidden" name="id_animal" value="<?= (int) $animalEliminar['id_animal'] ?>"><button class="boton peligro" type="submit">Confirmar eliminación</button><a class="boton secundario" href="animales.php">Cancelar</a></form></section>
<?php endif; ?>
<?php if ($formulario): ?>
<section class="panel"><h2><?= $id > 0 ? 'Editar animal' : 'Registrar animal' ?></h2><p class="suave">Los campos marcados con * son obligatorios.</p>
<form action="animales.php<?= $id > 0 ? '?editar=' . $id : '?nuevo=1' ?>" method="post" enctype="multipart/form-data">
<?= campoCsrfSesion('animales_admin') ?><input type="hidden" name="accion" value="guardar"><input type="hidden" name="id_animal" value="<?= $id ?>">
<div class="form-grid">
<label>Código *<input name="codigo_animal" maxlength="20" pattern="[A-Za-z0-9_-]{1,20}" placeholder="ANI-011" required value="<?= escaparAnimal($datos['codigo_animal']) ?>"></label>
<label>Nombre *<input name="nombre_animal" maxlength="100" required value="<?= escaparAnimal($datos['nombre_animal']) ?>"></label>
<label>Fecha de nacimiento *<input type="date" name="fecha_nacimiento" min="1000-01-01" max="<?= date('Y-m-d') ?>" required value="<?= escaparAnimal($datos['fecha_nacimiento']) ?>"></label>
<label>Fecha de entrada *<input type="date" name="fecha_entrada" min="1000-01-01" max="<?= date('Y-m-d') ?>" required value="<?= escaparAnimal($datos['fecha_entrada']) ?>"></label>
<label>Peso (kg) *<input type="number" name="peso" min="0.01" max="99999999.99" step="0.01" required value="<?= escaparAnimal($datos['peso']) ?>"></label>
<label>Altura (m) *<input type="number" name="altura" min="0.01" max="999.99" step="0.01" required value="<?= escaparAnimal($datos['altura']) ?>"></label>
<label>Hábitat<select name="id_habitat"><option value="">Sin asignar</option><?php foreach ($habitats as $h): if ($h['estado'] !== 'Activo' && (int) $datos['id_habitat'] !== (int) $h['id_habitat']) continue; ?><option value="<?= (int) $h['id_habitat'] ?>" <?= (int) $datos['id_habitat'] === (int) $h['id_habitat'] ? 'selected' : '' ?>><?= escaparAnimal($h['nombre'] . ($h['estado'] !== 'Activo' ? ' (inactivo)' : '')) ?></option><?php endforeach; ?></select></label>
<label>Fotografía<input type="file" name="foto" accept="image/jpeg,image/png,image/webp"><small>JPG, PNG o WebP. Máximo 10 MB y 4000 × 4000 píxeles. Al editar, deja este campo vacío para conservar la foto.</small></label>
</div>
<?php if ($tieneFoto): ?><div class="foto-actual"><img src="../animales/foto.php?id=<?= $id ?>" alt="Fotografía actual"><label><input type="checkbox" name="quitar_foto" value="1"> Quitar fotografía actual</label></div><?php endif; ?>
<div class="acciones"><button class="boton" type="submit">Guardar animal</button><a class="boton secundario" href="animales.php">Cancelar</a></div>
</form></section>
<?php endif; ?>
<div class="encabezado-lista"><div><span class="etiqueta verde">Registro de fauna</span><h2><?= $lista['total'] ?> animales</h2></div><a class="boton" href="animales.php?nuevo=1">+ Registrar animal</a></div>
<form class="filtros panel" method="get" action="animales.php"><label>Buscar<input type="search" name="buscar" maxlength="100" placeholder="Nombre o código" value="<?= escaparAnimal($busqueda) ?>"></label><label>Hábitat<select name="habitat"><option value="0">Todos los hábitats</option><?php foreach ($habitats as $h): ?><option value="<?= (int) $h['id_habitat'] ?>" <?= $habitatFiltro === (int) $h['id_habitat'] ? 'selected' : '' ?>><?= escaparAnimal($h['nombre']) ?></option><?php endforeach; ?></select></label><button class="boton" type="submit">Buscar</button><a href="animales.php">Limpiar</a></form>
<?php if (!$lista['animales']): ?><div class="panel vacio"><h3>No hay animales para mostrar</h3><p>Registra un animal o prueba otros filtros.</p></div><?php else: ?>
<div class="panel tabla-contenedor"><table><caption class="solo-lector">Animales registrados</caption><thead><tr><th>Animal</th><th>Hábitat</th><th>Peso / altura</th><th>Acciones</th></tr></thead><tbody>
<?php foreach ($lista['animales'] as $a): ?><tr><td><div class="animal-celda"><?php if ($a['tiene_foto']): ?><img loading="lazy" src="../animales/foto.php?id=<?= (int) $a['id_animal'] ?>" alt="<?= escaparAnimal($a['nombre_animal']) ?>"><?php endif; ?><div><strong><?= escaparAnimal($a['nombre_animal']) ?></strong><small><?= escaparAnimal($a['codigo_animal']) ?></small></div></div></td><td><?= escaparAnimal($a['habitat'] ?? 'Sin asignar') ?></td><td><?= escaparAnimal($a['peso']) ?> kg / <?= escaparAnimal($a['altura']) ?> m</td><td><div class="acciones"><a href="animales.php?editar=<?= (int) $a['id_animal'] ?>">Editar</a><a class="texto-peligro" href="animales.php?confirmar=<?= (int) $a['id_animal'] ?>">Eliminar</a></div></td></tr><?php endforeach; ?>
</tbody></table></div><?php endif; ?>
<?php $rutaPagina = 'animales.php'; require __DIR__ . '/paginacion.php'; require __DIR__ . '/pie.php'; ?>
