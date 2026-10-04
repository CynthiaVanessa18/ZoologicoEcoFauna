<?php
// php tests/verificar_animales.php [--preparar-http]
// Crea una base desechable; nunca modifica las tablas de la instalación.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require dirname(__DIR__) . '/config/database.php';
require dirname(__DIR__) . '/app/modulos/animales/funciones.php';
$rutaEstado = __DIR__ . '/manual/animales-http.json';
if (in_array('--limpiar-http', $argv, true)) {
    $estado = json_decode(file_get_contents($rutaEstado), true, 512, JSON_THROW_ON_ERROR);
    if (!preg_match('/^ecofauna_test_animales_[a-f0-9]{10}$/D', $estado['base'] ?? '')) throw new RuntimeException('Base de prueba inválida.');
    mysqli_query($conexion, 'DROP DATABASE IF EXISTS `' . $estado['base'] . '`');
    foreach ($estado['sesiones'] as $idSesion) {
        if (preg_match('/^[a-zA-Z0-9,-]{16,128}$/D', $idSesion)) {
            $archivoSesion = dirname(__DIR__) . '/storage/sesiones/sess_' . $idSesion;
            if (is_file($archivoSesion)) unlink($archivoSesion);
        }
    }
    unlink($rutaEstado);
    echo "Base y sesiones de prueba retiradas.\n";
    exit;
}
if (in_array('--preparar-http', $argv, true) && is_file($rutaEstado)) throw new RuntimeException('Ejecuta --limpiar-http antes de preparar otra prueba.');
$comprobaciones = 0;
function comprobarAnimal(bool $resultado, string $mensaje): void {
    global $comprobaciones;
    $comprobaciones++;
    if (!$resultado) throw new RuntimeException($mensaje);
}
function rechazarAnimal(callable $operacion, string $mensaje): void {
    try { $operacion(); } catch (DomainException $e) { comprobarAnimal(true, $mensaje); return; }
    throw new RuntimeException($mensaje);
}
$datos = ['codigo_animal'=>'PRUEBA-001','nombre_animal'=>'Tucán de prueba','fecha_nacimiento'=>'2020-01-01','fecha_entrada'=>'2021-01-01','peso'=>'0.65','altura'=>'0.55','id_habitat'=>''];
comprobarAnimal(validarAnimal($datos)['id_habitat'] === null, 'Hábitat opcional incorrecto.');
foreach ([['fecha_nacimiento'=>'2020-02-31'], ['fecha_entrada'=>'2019-01-01'], ['fecha_nacimiento'=>'2999-01-01'], ['peso'=>'-1'], ['peso'=>'1.001'], ['altura'=>'1000'], ['nombre_animal'=>str_repeat('a',101)], ['codigo_animal'=>'<script>'], ['nombre_animal'=>[]], ['id_habitat'=>'-2']] as $invalido) {
    rechazarAnimal(fn()=>validarAnimal(array_replace($datos,$invalido)), 'Dato inválido aceptado.');
}
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=');
comprobarAnimal(validarContenidoFotoAnimal($png) === 'image/png', 'Foto PNG rechazada.');
rechazarAnimal(fn()=>validarContenidoFotoAnimal('<?php echo 1;'), 'Archivo ejecutable aceptado.');
comprobarAnimal(validarContenidoFotoAnimal($png . str_repeat("\0", 3*1024*1024)) === 'image/png', 'Foto mayor de 2 MB rechazada.');
comprobarAnimal(validarContenidoFotoAnimal($png . str_repeat("\0", TAMANO_MAXIMO_FOTO_ANIMAL - strlen($png))) === 'image/png', 'Foto de 10 MB rechazada.');
rechazarAnimal(fn()=>validarContenidoFotoAnimal(str_repeat('x',TAMANO_MAXIMO_FOTO_ANIMAL+1)), 'Archivo demasiado grande aceptado.');
$basePrueba = 'ecofauna_test_animales_' . bin2hex(random_bytes(5));
$conservar = false;
mysqli_query($conexion, "CREATE DATABASE `$basePrueba` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
try {
    mysqli_select_db($conexion,$basePrueba);
    $sql = file_get_contents(dirname(__DIR__) . '/database/bd.sql');
    foreach (['habitat','animal','bitacora'] as $tabla) {
        if (!preg_match('/CREATE TABLE ' . $tabla . '\s*\(.*?;/s',$sql,$coincidencia)) throw new RuntimeException('No se encontró el esquema.');
        mysqli_query($conexion,$coincidencia[0]);
    }
    foreach (['historialmedicoanimal','alertavacuna','vacunacion','alimentacion_animal','cuidador_animal'] as $tabla) {
        mysqli_query($conexion,"CREATE TABLE $tabla (id INT AUTO_INCREMENT PRIMARY KEY, id_animal INT NOT NULL, FOREIGN KEY(id_animal) REFERENCES animal(id_animal) ON DELETE CASCADE) ENGINE=InnoDB");
    }
    mysqli_query($conexion,"INSERT INTO habitat (nombre,tipo_habitat,zona,area_m2,capacidad_animales,descripcion,estado) VALUES ('Bosque de prueba','Bosque','Norte',100,1,'Hábitat de prueba','Activo'),('Reserva inactiva','Bosque','Sur',100,5,'Prueba','Inactivo')");
    $_SESSION = ['usuario'=>'admin_prueba','rol'=>'Administrador','id_login'=>1];
    $validos = validarAnimal($datos);
    $id = guardarAnimal($conexion,$validos,0,$png,false);
    comprobarAnimal($id > 0,'No se creó el animal.');
    rechazarAnimal(fn()=>guardarAnimal($conexion,$validos,0,null,false),'Código duplicado aceptado.');
    $editados = validarAnimal(array_replace($datos,['nombre_animal'=>'Tucán actualizado','id_habitat'=>'1']));
    guardarAnimal($conexion,$editados,$id,null,false);
    $fila = mysqli_fetch_assoc(mysqli_query($conexion,"SELECT * FROM animal WHERE id_animal=$id"));
    comprobarAnimal($fila['foto'] === $png && $fila['nombre_animal'] === 'Tucán actualizado' && (int)$fila['id_habitat'] === 1,'La edición perdió datos.');
    $otro = validarAnimal(array_replace($datos,['codigo_animal'=>'PRUEBA-002','id_habitat'=>'1']));
    rechazarAnimal(fn()=>guardarAnimal($conexion,$otro,0,null,false),'Se superó la capacidad.');
    $otro['id_habitat'] = 2;
    rechazarAnimal(fn()=>guardarAnimal($conexion,$otro,0,null,false),'Se asignó un hábitat inactivo.');
    $lista = listarAnimales($conexion,'actualizado',1,500);
    comprobarAnimal($lista['total'] === 1 && $lista['pagina'] === 1,'Búsqueda/filtro/paginación incorrectos.');
    comprobarAnimal(listarAnimales($conexion,"' OR 1=1 --",0,1)['total'] === 0,'Búsqueda no parametrizada.');
    foreach (['historialmedicoanimal','alertavacuna','vacunacion','alimentacion_animal','cuidador_animal'] as $tabla) {
        mysqli_query($conexion,"INSERT INTO $tabla (id_animal) VALUES ($id)");
        rechazarAnimal(fn()=>eliminarAnimal($conexion,$id),'Se borró historial asociado.');
        comprobarAnimal((int)mysqli_fetch_row(mysqli_query($conexion,"SELECT COUNT(*) FROM $tabla"))[0] === 1,'Se perdió una relación.');
        mysqli_query($conexion,"DELETE FROM $tabla WHERE id_animal=$id");
    }
    guardarAnimal($conexion,$editados,$id,null,true);
    comprobarAnimal(mysqli_fetch_assoc(mysqli_query($conexion,"SELECT foto FROM animal WHERE id_animal=$id"))['foto'] === null,'No se quitó la foto.');
    eliminarAnimal($conexion,$id);
    comprobarAnimal(listarAnimales($conexion,'',0,1)['total'] === 0,'No se eliminó el animal sin relaciones.');
    comprobarAnimal((int)mysqli_fetch_row(mysqli_query($conexion,'SELECT COUNT(*) FROM bitacora'))[0] === 4,'Bitácora inconsistente.');
    // Generar más de una página para verificar filtros y límites.
    for ($i=1;$i<=13;$i++) {
        $d = validarAnimal(array_replace($datos,['codigo_animal'=>'DEMO-'.$i,'nombre_animal'=>'Animal de prueba '.$i]));
        guardarAnimal($conexion,$d,0,$i===1?$png:null,false);
    }
    $segunda = listarAnimales($conexion,'',0,2);
    comprobarAnimal($segunda['total'] === 13 && count($segunda['animales']) === 1 && $segunda['paginas'] === 2,'Paginación incorrecta.');
    if (in_array('--preparar-http',$argv,true)) {
        require dirname(__DIR__) . '/app/soporte/sesion.php';
        $sesiones = [];
        session_write_close();
        foreach (['Administrador','Cliente'] as $rol) {
            $idSesion = bin2hex(random_bytes(16));
            session_id($idSesion);
            if (!session_start()) throw new RuntimeException('No se pudo preparar la sesión de prueba.');
            $_SESSION = ['usuario'=>'prueba_http','rol'=>$rol,'id_login'=>1,'ultima_actividad'=>time()];
            $sesiones[$rol] = session_id();
            session_write_close();
        }
        $ruta = __DIR__ . '/manual/animales-http.json';
        file_put_contents($ruta,json_encode(['base'=>$basePrueba,'sesiones'=>$sesiones]));
        $conservar = true;
    }
    echo "$comprobaciones comprobaciones de animales superadas.\n";
} finally {
    if (!$conservar) mysqli_query($conexion,"DROP DATABASE `$basePrueba`");
}
