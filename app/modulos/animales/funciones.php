<?php

const TAMANO_MAXIMO_FOTO_ANIMAL = 10 * 1024 * 1024;

function escaparAnimal(mixed $valor): string
{
    return htmlspecialchars((string) $valor, ENT_QUOTES, 'UTF-8');
}

function textoAnimal(array $datos, string $campo): string
{
    if (isset($datos[$campo]) && !is_string($datos[$campo])) {
        throw new DomainException('Los datos del formulario no son válidos.');
    }
    return trim($datos[$campo] ?? '');
}

function validarAnimal(array $datos): array
{
    $animal = [];
    foreach (['codigo_animal', 'nombre_animal', 'fecha_nacimiento', 'fecha_entrada', 'peso', 'altura', 'id_habitat'] as $campo) {
        $animal[$campo] = textoAnimal($datos, $campo);
    }
    if (!preg_match('/^[A-Za-z0-9_-]{1,20}$/D', $animal['codigo_animal'])) {
        throw new DomainException('El código debe tener de 1 a 20 letras, números, guiones o guiones bajos.');
    }
    if ($animal['nombre_animal'] === '' || mb_strlen($animal['nombre_animal']) > 100) {
        throw new DomainException('Escribe un nombre de hasta 100 caracteres.');
    }
    foreach (['fecha_nacimiento', 'fecha_entrada'] as $campo) {
        $fecha = DateTimeImmutable::createFromFormat('!Y-m-d', $animal[$campo]);
        if (!$fecha || $fecha->format('Y-m-d') !== $animal[$campo] || $animal[$campo] < '1000-01-01' || $animal[$campo] > date('Y-m-d')) {
            throw new DomainException('Las fechas deben ser válidas y no pueden estar en el futuro.');
        }
    }
    if ($animal['fecha_entrada'] < $animal['fecha_nacimiento']) {
        throw new DomainException('La fecha de entrada no puede ser anterior al nacimiento.');
    }
    foreach (['peso' => 99999999.99, 'altura' => 999.99] as $campo => $maximo) {
        if (!preg_match('/^\d+(?:\.\d{1,2})?$/D', $animal[$campo]) || (float) $animal[$campo] <= 0 || (float) $animal[$campo] > $maximo) {
            throw new DomainException('El peso y la altura deben ser positivos, con un máximo de dos decimales y dentro de los límites indicados.');
        }
    }
    if ($animal['id_habitat'] !== '' && filter_var($animal['id_habitat'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
        throw new DomainException('Selecciona un hábitat válido.');
    }
    $animal['id_habitat'] = $animal['id_habitat'] === '' ? null : (int) $animal['id_habitat'];
    return $animal;
}

function validarContenidoFotoAnimal(string $contenido): string
{
    if ($contenido === '' || strlen($contenido) > TAMANO_MAXIMO_FOTO_ANIMAL) {
        throw new DomainException('La fotografía debe pesar como máximo 10 MB.');
    }
    $imagen = @getimagesizefromstring($contenido);
    if (!$imagen || !in_array($imagen['mime'], ['image/jpeg', 'image/png', 'image/webp'], true) || $imagen[0] > 4000 || $imagen[1] > 4000) {
        throw new DomainException('Selecciona una imagen JPG, PNG o WebP de hasta 4000 × 4000 píxeles.');
    }
    return $imagen['mime'];
}

function recibirFotoAnimal(?array $archivo): ?string
{
    if ($archivo === null || ($archivo['error'] ?? null) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if (($archivo['error'] ?? null) !== UPLOAD_ERR_OK || !is_string($archivo['tmp_name'] ?? null) || !is_uploaded_file($archivo['tmp_name'])) {
        throw new DomainException('No se pudo recibir la fotografía. Elige una imagen de hasta 10 MB.');
    }
    if (filesize($archivo['tmp_name']) > TAMANO_MAXIMO_FOTO_ANIMAL) {
        throw new DomainException('La fotografía debe pesar como máximo 10 MB.');
    }
    $contenido = file_get_contents($archivo['tmp_name']);
    if ($contenido === false) {
        throw new RuntimeException('No se pudo leer la fotografía.');
    }
    validarContenidoFotoAnimal($contenido);
    return $contenido;
}

/** Todas las consultas del módulo usan parámetros y errores controlados. */
function consultaAnimal(mysqli $conexion, string $sql, string $tipos = '', array $parametros = []): mysqli_stmt
{
    $stmt = mysqli_prepare($conexion, $sql);
    if (!$stmt) {
        throw new RuntimeException('No se pudo preparar la consulta.');
    }
    if ($tipos !== '') {
        mysqli_stmt_bind_param($stmt, $tipos, ...$parametros);
    }
    if (!mysqli_stmt_execute($stmt)) {
        throw new RuntimeException('No se pudo ejecutar la consulta.');
    }
    return $stmt;
}

function listarAnimales(mysqli $conexion, string $busqueda, int $habitat, int $pagina): array
{
    $filtro = '%' . $busqueda . '%';
    $where = ' WHERE (a.nombre_animal LIKE ? OR a.codigo_animal LIKE ?) AND (? = 0 OR a.id_habitat = ?)';
    $stmt = consultaAnimal($conexion, 'SELECT COUNT(*) AS total FROM animal a' . $where, 'ssii', [$filtro, $filtro, $habitat, $habitat]);
    $total = (int) mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['total'];
    mysqli_stmt_close($stmt);
    $paginas = max(1, (int) ceil($total / 12));
    $pagina = min(max(1, $pagina), $paginas);
    $stmt = consultaAnimal($conexion, 'SELECT a.id_animal, a.codigo_animal, a.nombre_animal, a.fecha_nacimiento, a.fecha_entrada, a.peso, a.altura, a.id_habitat, (a.foto IS NOT NULL AND OCTET_LENGTH(a.foto) > 0) AS tiene_foto, h.nombre AS habitat, h.zona FROM animal a LEFT JOIN habitat h ON h.id_habitat = a.id_habitat' . $where . ' ORDER BY a.nombre_animal, a.id_animal LIMIT 12 OFFSET ?', 'ssiii', [$filtro, $filtro, $habitat, $habitat, ($pagina - 1) * 12]);
    $animales = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
    mysqli_stmt_close($stmt);
    return compact('animales', 'total', 'paginas', 'pagina');
}

function guardarAnimal(mysqli $conexion, array $animal, int $id, ?string $foto, bool $quitarFoto): int
{
    mysqli_begin_transaction($conexion);
    try {
        $actual = null;
        if ($id > 0) {
            $stmt = consultaAnimal($conexion, 'SELECT id_habitat FROM animal WHERE id_animal = ? FOR UPDATE', 'i', [$id]);
            $actual = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
            mysqli_stmt_close($stmt);
            if (!$actual) {
                throw new DomainException('El animal ya no existe.');
            }
        }
        if ($animal['id_habitat'] !== null) {
            $stmt = consultaAnimal($conexion, 'SELECT capacidad_animales, estado FROM habitat WHERE id_habitat = ? FOR UPDATE', 'i', [$animal['id_habitat']]);
            $habitat = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
            mysqli_stmt_close($stmt);
            $conservarHabitat = $actual && (int) $actual['id_habitat'] === $animal['id_habitat'];
            if (!$habitat || ($habitat['estado'] !== 'Activo' && !$conservarHabitat)) {
                throw new DomainException('Selecciona un hábitat activo.');
            }
            $stmt = consultaAnimal($conexion, 'SELECT id_animal FROM animal WHERE id_habitat = ? AND id_animal <> ? FOR UPDATE', 'ii', [$animal['id_habitat'], $id]);
            $ocupados = mysqli_num_rows(mysqli_stmt_get_result($stmt));
            mysqli_stmt_close($stmt);
            if (!$conservarHabitat && $ocupados >= (int) $habitat['capacidad_animales']) {
                throw new DomainException('El hábitat seleccionado no tiene espacio disponible.');
            }
        }
        $valores = [$animal['codigo_animal'], $animal['nombre_animal'], $animal['fecha_nacimiento'], $animal['fecha_entrada'], $animal['peso'], $animal['altura'], $animal['id_habitat']];
        if ($id > 0) {
            $sql = 'UPDATE animal SET codigo_animal=?, nombre_animal=?, fecha_nacimiento=?, fecha_entrada=?, peso=?, altura=?, id_habitat=?';
            $tipos = 'ssssddi';
            if ($foto !== null || $quitarFoto) {
                $sql .= ', foto=?';
                $tipos .= 's';
                $valores[] = $foto;
            }
            $valores[] = $id;
            $stmt = consultaAnimal($conexion, $sql . ' WHERE id_animal=?', $tipos . 'i', $valores);
        } else {
            $valores[] = $foto;
            $stmt = consultaAnimal($conexion, 'INSERT INTO animal (codigo_animal,nombre_animal,fecha_nacimiento,fecha_entrada,peso,altura,id_habitat,foto) VALUES (?,?,?,?,?,?,?,?)', 'ssssddis', $valores);
            $id = mysqli_insert_id($conexion);
        }
        mysqli_stmt_close($stmt);
        if (!registrarBitacora($_SESSION['usuario'], $actual ? 'EDITAR ANIMAL' : 'CREAR ANIMAL', 'animal', 'Animal: ' . $animal['codigo_animal'], $id)) {
            throw new RuntimeException('No se pudo registrar la operación.');
        }
        mysqli_commit($conexion);
        return $id;
    } catch (Throwable $error) {
        mysqli_rollback($conexion);
        if ($error instanceof mysqli_sql_exception && $error->getCode() === 1062) {
            throw new DomainException('Ya existe un animal con ese código.');
        }
        throw $error;
    }
}

function eliminarAnimal(mysqli $conexion, int $id): void
{
    mysqli_begin_transaction($conexion);
    try {
        $stmt = consultaAnimal($conexion, 'SELECT codigo_animal FROM animal WHERE id_animal=? FOR UPDATE', 'i', [$id]);
        $animal = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
        if (!$animal) {
            throw new DomainException('El animal ya no existe.');
        }
        // Estas relaciones tienen borrado en cascada: se protege todo su historial.
        foreach (['historialmedicoanimal', 'alertavacuna', 'vacunacion', 'alimentacion_animal', 'cuidador_animal'] as $tabla) {
            $stmt = consultaAnimal($conexion, "SELECT id_animal FROM $tabla WHERE id_animal=? LIMIT 1 FOR UPDATE", 'i', [$id]);
            $relacionado = mysqli_num_rows(mysqli_stmt_get_result($stmt)) > 0;
            mysqli_stmt_close($stmt);
            if ($relacionado) {
                throw new DomainException('Este animal tiene historial veterinario, alimentación o cuidadores asociados. No se puede eliminar para conservar esos registros.');
            }
        }
        $stmt = consultaAnimal($conexion, 'DELETE FROM animal WHERE id_animal=?', 'i', [$id]);
        mysqli_stmt_close($stmt);
        if (!registrarBitacora($_SESSION['usuario'], 'ELIMINAR ANIMAL', 'animal', 'Animal: ' . $animal['codigo_animal'], $id)) {
            throw new RuntimeException('No se pudo registrar la operación.');
        }
        mysqli_commit($conexion);
    } catch (Throwable $error) {
        mysqli_rollback($conexion);
        throw $error;
    }
}
