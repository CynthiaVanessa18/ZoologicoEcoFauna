<?php

require_once __DIR__ . "/../../soporte/sesion.php";
require_once __DIR__ . "/../../../config/database.php";
require_once __DIR__ . "/../habitats/funciones.php";

/** @var mysqli $conexion */

/*
|--------------------------------------------------------------------------
| CONTROL DE ACCESO
|--------------------------------------------------------------------------
*/

if (
    !isset(
        $_SESSION["usuario"],
        $_SESSION["rol"],
        $_SESSION["id_login"]
    ) ||
    $_SESSION["rol"] !== "Administrador"
) {
    header("Location: ../index.php");
    exit();
}

const CONTEXTO_CSRF_HABITATS = "administracion_habitats";

/*
|--------------------------------------------------------------------------
| REDIRECCIÓN
|--------------------------------------------------------------------------
*/

function redirigirHabitats(
    string $mensaje,
    string $tipo = "success"
): void {

    $_SESSION["mensaje_habitats"] = $mensaje;
    $_SESSION["tipo_mensaje_habitats"] = $tipo;

    renovarTokenCsrfSesion(
        CONTEXTO_CSRF_HABITATS
    );

    header("Location: habitats.php");
    exit();
}

/*
|--------------------------------------------------------------------------
| FUNCIONES DE TEXTO
|--------------------------------------------------------------------------
*/

function longitudHabitat(string $texto): int
{
    return function_exists("mb_strlen")
        ? mb_strlen($texto, "UTF-8")
        : strlen($texto);
}

function textoComparableHabitat(string $texto): string
{
    $texto = trim($texto);

    return function_exists("mb_strtolower")
        ? mb_strtolower($texto, "UTF-8")
        : strtolower($texto);
}

/*
|--------------------------------------------------------------------------
| CONSULTAR HÁBITATS
|--------------------------------------------------------------------------
*/

function listarHabitatsBD(mysqli $conexion): array
{
    $sql = "
        SELECT
            id_habitat AS id,
            nombre,
            tipo_habitat,
            zona,
            area_m2,
            capacidad_animales,
            descripcion,
            caracteristicas,
            foto,
            icono,
            estado,
            fecha_registro,
            fecha_actualizacion
        FROM habitat
        ORDER BY id_habitat DESC
    ";

    $resultado = $conexion->query($sql);

    if (!$resultado) {
        throw new RuntimeException(
            "No fue posible consultar los hábitats: " .
            $conexion->error
        );
    }

    $habitats = [];

    while ($fila = $resultado->fetch_assoc()) {
        $habitats[] = $fila;
    }

    $resultado->free();

    return $habitats;
}

/*
|--------------------------------------------------------------------------
| OBTENER HÁBITAT
|--------------------------------------------------------------------------
*/

function obtenerHabitatBD(
    mysqli $conexion,
    int $id
): ?array {

    $sql = "
        SELECT
            id_habitat AS id,
            nombre,
            tipo_habitat,
            zona,
            area_m2,
            capacidad_animales,
            descripcion,
            caracteristicas,
            foto,
            icono,
            estado,
            fecha_registro,
            fecha_actualizacion
        FROM habitat
        WHERE id_habitat = ?
        LIMIT 1
    ";

    $stmt = $conexion->prepare($sql);

    if (!$stmt) {
        throw new RuntimeException(
            "No fue posible preparar la consulta del hábitat."
        );
    }

    $stmt->bind_param("i", $id);
    $stmt->execute();

    $resultado = $stmt->get_result();

    $habitat =
        $resultado->fetch_assoc() ?: null;

    $stmt->close();

    return $habitat;
}

/*
|--------------------------------------------------------------------------
| VALIDAR NOMBRE DUPLICADO
|--------------------------------------------------------------------------
*/

function existeNombreHabitatBD(
    mysqli $conexion,
    string $nombre,
    int $idExcluir = 0
): bool {

    $sql = "
        SELECT id_habitat
        FROM habitat
        WHERE LOWER(TRIM(nombre)) =
              LOWER(TRIM(?))
    ";

    if ($idExcluir > 0) {
        $sql .= " AND id_habitat <> ?";
    }

    $sql .= " LIMIT 1";

    $stmt = $conexion->prepare($sql);

    if (!$stmt) {
        throw new RuntimeException(
            "No fue posible validar el nombre del hábitat."
        );
    }

    if ($idExcluir > 0) {

        $stmt->bind_param(
            "si",
            $nombre,
            $idExcluir
        );

    } else {

        $stmt->bind_param(
            "s",
            $nombre
        );
    }

    $stmt->execute();

    $resultado = $stmt->get_result();

    $existe =
        $resultado->num_rows > 0;

    $stmt->close();

    return $existe;
}

/*
|--------------------------------------------------------------------------
| INSERTAR HÁBITAT
|--------------------------------------------------------------------------
*/

function insertarHabitatBD(
    mysqli $conexion,
    array $datos
): int {

    $sql = "
        INSERT INTO habitat (
            nombre,
            tipo_habitat,
            zona,
            area_m2,
            capacidad_animales,
            descripcion,
            caracteristicas,
            foto,
            icono,
            estado
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ";

    $stmt = $conexion->prepare($sql);

    if (!$stmt) {
        throw new RuntimeException(
            "No fue posible preparar el registro del hábitat."
        );
    }

    $area =
        (float) $datos["area_m2"];

    $capacidad =
        (int) $datos["capacidad_animales"];

    $stmt->bind_param(
        "sssdisssss",
        $datos["nombre"],
        $datos["tipo_habitat"],
        $datos["zona"],
        $area,
        $capacidad,
        $datos["descripcion"],
        $datos["caracteristicas"],
        $datos["foto"],
        $datos["icono"],
        $datos["estado"]
    );

    if (!$stmt->execute()) {

        $error =
            $stmt->error;

        $stmt->close();

        throw new RuntimeException(
            "No fue posible registrar el hábitat: " .
            $error
        );
    }

    $id =
        (int) $conexion->insert_id;

    $stmt->close();

    return $id;
}

/*
|--------------------------------------------------------------------------
| ACTUALIZAR HÁBITAT
|--------------------------------------------------------------------------
*/

function actualizarHabitatBD(
    mysqli $conexion,
    array $datos
): bool {

    $sql = "
        UPDATE habitat
        SET
            nombre = ?,
            tipo_habitat = ?,
            zona = ?,
            area_m2 = ?,
            capacidad_animales = ?,
            descripcion = ?,
            caracteristicas = ?,
            foto = ?,
            icono = ?,
            estado = ?
        WHERE id_habitat = ?
    ";

    $stmt = $conexion->prepare($sql);

    if (!$stmt) {
        throw new RuntimeException(
            "No fue posible preparar la actualización del hábitat."
        );
    }

    $id =
        (int) $datos["id"];

    $area =
        (float) $datos["area_m2"];

    $capacidad =
        (int) $datos["capacidad_animales"];

    $stmt->bind_param(
        "sssdisssssi",
        $datos["nombre"],
        $datos["tipo_habitat"],
        $datos["zona"],
        $area,
        $capacidad,
        $datos["descripcion"],
        $datos["caracteristicas"],
        $datos["foto"],
        $datos["icono"],
        $datos["estado"],
        $id
    );

    if (!$stmt->execute()) {

        $error =
            $stmt->error;

        $stmt->close();

        throw new RuntimeException(
            "No fue posible actualizar el hábitat: " .
            $error
        );
    }

    $stmt->close();

    return true;
}

/*
|--------------------------------------------------------------------------
| CAMBIAR ESTADO
|--------------------------------------------------------------------------
*/

function cambiarEstadoHabitatBD(
    mysqli $conexion,
    int $id,
    string $estado
): bool {

    $sql = "
        UPDATE habitat
        SET estado = ?
        WHERE id_habitat = ?
    ";

    $stmt = $conexion->prepare($sql);

    if (!$stmt) {
        throw new RuntimeException(
            "No fue posible preparar el cambio de estado."
        );
    }

    $stmt->bind_param(
        "si",
        $estado,
        $id
    );

    if (!$stmt->execute()) {

        $error =
            $stmt->error;

        $stmt->close();

        throw new RuntimeException(
            "No fue posible actualizar el estado: " .
            $error
        );
    }

    $stmt->close();

    return true;
}

/*
|--------------------------------------------------------------------------
| LISTAR ANIMALES
|--------------------------------------------------------------------------
*/

function listarAnimalesBD(
    mysqli $conexion
): array {

    $sql = "
        SELECT
            a.id_animal,
            a.codigo_animal,
            a.nombre_animal,
            a.fecha_nacimiento,
            a.peso,
            a.altura,
            a.id_habitat,
            h.nombre AS nombre_habitat
        FROM animal a
        LEFT JOIN habitat h
            ON h.id_habitat = a.id_habitat
        ORDER BY a.nombre_animal ASC
    ";

    $resultado =
        $conexion->query($sql);

    if (!$resultado) {
        throw new RuntimeException(
            "No fue posible consultar los animales: " .
            $conexion->error
        );
    }

    $animales = [];

    while ($fila = $resultado->fetch_assoc()) {
        $animales[] = $fila;
    }

    $resultado->free();

    return $animales;
}

/*
|--------------------------------------------------------------------------
| OBTENER ANIMALES DE UN HÁBITAT
|--------------------------------------------------------------------------
*/

function obtenerAnimalesHabitatBD(
    mysqli $conexion,
    int $idHabitat
): array {

    $sql = "
        SELECT
            id_animal
        FROM animal
        WHERE id_habitat = ?
        ORDER BY nombre_animal ASC
    ";

    $stmt =
        $conexion->prepare($sql);

    if (!$stmt) {
        throw new RuntimeException(
            "No fue posible consultar los animales del hábitat."
        );
    }

    $stmt->bind_param(
        "i",
        $idHabitat
    );

    $stmt->execute();

    $resultado =
        $stmt->get_result();

    $ids = [];

    while ($fila = $resultado->fetch_assoc()) {

        $ids[] =
            (int) $fila["id_animal"];
    }

    $stmt->close();

    return $ids;
}

/*
|--------------------------------------------------------------------------
| ASIGNAR MÚLTIPLES ANIMALES AL HÁBITAT
|--------------------------------------------------------------------------
*/

function asignarAnimalesHabitatBD(
    mysqli $conexion,
    int $idHabitat,
    array $idsAnimales,
    int $capacidad
): void {

    $idsAnimales =
        array_map(
            "intval",
            $idsAnimales
        );

    $idsAnimales =
        array_filter(
            $idsAnimales,
            static fn(int $id): bool =>
                $id > 0
        );

    $idsAnimales =
        array_values(
            array_unique(
                $idsAnimales
            )
        );

    if (
        count($idsAnimales) >
        $capacidad
    ) {

        throw new RuntimeException(
            "No puede asignar más de " .
            $capacidad .
            " animales a este hábitat."
        );
    }

    $conexion->begin_transaction();

    try {

        $sqlLimpiar = "
            UPDATE animal
            SET id_habitat = NULL
            WHERE id_habitat = ?
        ";

        $stmtLimpiar =
            $conexion->prepare(
                $sqlLimpiar
            );

        if (!$stmtLimpiar) {
            throw new RuntimeException(
                "No fue posible actualizar los animales."
            );
        }

        $stmtLimpiar->bind_param(
            "i",
            $idHabitat
        );

        if (!$stmtLimpiar->execute()) {

            $error =
                $stmtLimpiar->error;

            $stmtLimpiar->close();

            throw new RuntimeException(
                "No fue posible deseleccionar los animales: " .
                $error
            );
        }

        $stmtLimpiar->close();

        if ($idsAnimales !== []) {

            $sqlAsignar = "
                UPDATE animal
                SET id_habitat = ?
                WHERE id_animal = ?
            ";

            $stmtAsignar =
                $conexion->prepare(
                    $sqlAsignar
                );

            if (!$stmtAsignar) {
                throw new RuntimeException(
                    "No fue posible preparar la asignación de animales."
                );
            }

            foreach (
                $idsAnimales
                as $idAnimal
            ) {

                $stmtAsignar->bind_param(
                    "ii",
                    $idHabitat,
                    $idAnimal
                );

                if (
                    !$stmtAsignar->execute()
                ) {

                    $error =
                        $stmtAsignar->error;

                    $stmtAsignar->close();

                    throw new RuntimeException(
                        "No fue posible asignar uno de los animales: " .
                        $error
                    );
                }
            }

            $stmtAsignar->close();
        }

        $conexion->commit();

    } catch (Throwable $excepcion) {

        $conexion->rollback();

        throw $excepcion;
    }
}

/*
|--------------------------------------------------------------------------
| SUBIR FOTO
|--------------------------------------------------------------------------
*/

function subirFotoHabitat(
    array $archivo,
    string $nombreHabitat
): string {

    if (
        !isset($archivo["error"]) ||
        $archivo["error"] === UPLOAD_ERR_NO_FILE
    ) {
        return "";
    }

    if (
        $archivo["error"] !== UPLOAD_ERR_OK
    ) {
        throw new RuntimeException(
            "No fue posible cargar la imagen."
        );
    }

    $tamanoMaximo =
        5 * 1024 * 1024;

    if (
        $archivo["size"] >
        $tamanoMaximo
    ) {

        throw new RuntimeException(
            "La imagen no puede superar los 5 MB."
        );
    }

    $tiposPermitidos = [

        "image/jpeg" => "jpg",

        "image/png" => "png",

        "image/webp" => "webp"
    ];

    $finfo =
        new finfo(
            FILEINFO_MIME_TYPE
        );

    $tipoMime =
        $finfo->file(
            $archivo["tmp_name"]
        );

    if (
        !isset(
            $tiposPermitidos[$tipoMime]
        )
    ) {

        throw new RuntimeException(
            "La imagen debe estar en formato JPG, PNG o WEBP."
        );
    }

    $extension =
        $tiposPermitidos[$tipoMime];

    $nombreBase =
        preg_replace(
            "/[^a-zA-Z0-9_-]/",
            "_",
            $nombreHabitat
        );

    $nombreBase =
        trim(
            $nombreBase,
            "_"
        );

    if (
        $nombreBase === "" ||
        $nombreBase === null
    ) {

        $nombreBase =
            "habitat";
    }

    $nombreArchivo =
        strtolower($nombreBase) .
        "_" .
        bin2hex(
            random_bytes(5)
        ) .
        "." .
        $extension;

    $directorioHabitats =
        __DIR__ . "/../../../storage/uploads/habitats/";

    if (
        !is_dir(
            $directorioHabitats
        )
    ) {

        if (
            !mkdir(
                $directorioHabitats,
                0755,
                true
            )
        ) {

            throw new RuntimeException(
                "No fue posible crear la carpeta uploads/habitats."
            );
        }
    }

    $rutaFisica =
        $directorioHabitats .
        $nombreArchivo;

    if (
        !move_uploaded_file(
            $archivo["tmp_name"],
            $rutaFisica
        )
    ) {

        throw new RuntimeException(
            "No fue posible guardar la imagen en el proyecto."
        );
    }

    return
        "uploads/habitats/" .
        $nombreArchivo;
}

/*
|--------------------------------------------------------------------------
| MENSAJES
|--------------------------------------------------------------------------
*/

$mensaje =
    (string) (
        $_SESSION["mensaje_habitats"] ?? ""
    );

$tipoMensaje =
    (string) (
        $_SESSION["tipo_mensaje_habitats"] ??
        "success"
    );

unset(
    $_SESSION["mensaje_habitats"],
    $_SESSION["tipo_mensaje_habitats"]
);

$error = "";

/*
|--------------------------------------------------------------------------
| ICONOS
|--------------------------------------------------------------------------
*/

$iconos =
    iconosHabitatsPermitidos();

/*
|--------------------------------------------------------------------------
| DATOS DEL FORMULARIO
|--------------------------------------------------------------------------
*/

$datosFormulario = [

    "id" => 0,

    "nombre" => "",

    "tipo_habitat" => "",

    "zona" => "",

    "area_m2" => "",

    "capacidad_animales" => "",

    "descripcion" => "",

    "caracteristicas" => "",

    "foto" => "",

    "icono" => "bi-tree-fill",

    "estado" => "Activo",
];

/*
|--------------------------------------------------------------------------
| ANIMALES SELECCIONADOS
|--------------------------------------------------------------------------
*/

$animalesSeleccionados = [];

/*
|--------------------------------------------------------------------------
| CARGAR HÁBITATS
|--------------------------------------------------------------------------
*/

try {

    $habitats =
        listarHabitatsBD(
            $conexion
        );

} catch (Throwable $excepcion) {

    error_log(
        "Error cargando hábitats: " .
        $excepcion->getMessage()
    );

    $habitats = [];

    $error =
        $excepcion->getMessage();
}

/*
|--------------------------------------------------------------------------
| CARGAR ANIMALES
|--------------------------------------------------------------------------
*/

$animales = [];

try {

    $animales =
        listarAnimalesBD(
            $conexion
        );

} catch (Throwable $excepcion) {

    error_log(
        "Error cargando animales: " .
        $excepcion->getMessage()
    );

    $animales = [];

    if ($error === "") {

        $error =
            "No fue posible cargar los animales.";
    }
}

/*
|--------------------------------------------------------------------------
| PROCESAR POST
|--------------------------------------------------------------------------
*/

if (
    $_SERVER["REQUEST_METHOD"] === "POST"
) {

    $accion =
        $_POST["accion"] ?? "";

    $idsAnimalesSeleccionados =
        $_POST["animales"] ?? [];

    if (
        !is_array(
            $idsAnimalesSeleccionados
        )
    ) {

        $idsAnimalesSeleccionados = [];
    }

    $idsAnimalesSeleccionados =
        array_map(
            "intval",
            $idsAnimalesSeleccionados
        );

    $idsAnimalesSeleccionados =
        array_values(
            array_unique(
                array_filter(
                    $idsAnimalesSeleccionados,
                    static fn(int $id): bool =>
                        $id > 0
                )
            )
        );

    $animalesSeleccionados =
        $idsAnimalesSeleccionados;

    try {

        /*
        |--------------------------------------------------------------------------
        | CSRF
        |--------------------------------------------------------------------------
        */

        if (
            !tokenCsrfSesionValido(
                CONTEXTO_CSRF_HABITATS,
                $_POST["csrf_token"] ?? null
            )
        ) {

            http_response_code(403);

            throw new RuntimeException(
                "La solicitud expiró. Recarga la página e inténtalo nuevamente."
            );
        }

        /*
        |--------------------------------------------------------------------------
        | GUARDAR
        |--------------------------------------------------------------------------
        */

        if (
            $accion === "guardar"
        ) {

            $datosFormulario = [

                "id" =>
                    (int) (
                        $_POST["id"] ?? 0
                    ),

                "nombre" =>
                    trim(
                        $_POST["nombre"] ?? ""
                    ),

                "tipo_habitat" =>
                    trim(
                        $_POST["tipo_habitat"] ?? ""
                    ),

                "zona" =>
                    trim(
                        $_POST["zona"] ?? ""
                    ),

                "area_m2" =>
                    trim(
                        $_POST["area_m2"] ?? ""
                    ),

                "capacidad_animales" =>
                    trim(
                        $_POST["capacidad_animales"] ?? ""
                    ),

                "descripcion" =>
                    trim(
                        $_POST["descripcion"] ?? ""
                    ),

                "caracteristicas" =>
                    trim(
                        $_POST["caracteristicas"] ?? ""
                    ),

                "foto" => "",

                "icono" =>
                    $_POST["icono"] ??
                    "bi-tree-fill",

                "estado" =>
                    $_POST["estado"] ??
                    "Activo",
            ];

            $fotoActual =
                trim(
                    $_POST["foto_actual"] ?? ""
                );

            /*
            |--------------------------------------------------------------------------
            | CAMPOS OBLIGATORIOS
            |--------------------------------------------------------------------------
            */

            if (
                $datosFormulario["nombre"] === "" ||
                $datosFormulario["tipo_habitat"] === "" ||
                $datosFormulario["zona"] === "" ||
                $datosFormulario["area_m2"] === "" ||
                $datosFormulario["capacidad_animales"] === "" ||
                $datosFormulario["descripcion"] === ""
            ) {

                throw new RuntimeException(
                    "Complete todos los campos obligatorios."
                );
            }

            /*
            |--------------------------------------------------------------------------
            | LONGITUDES
            |--------------------------------------------------------------------------
            */

            $limites = [

                "nombre" => 150,

                "tipo_habitat" => 100,

                "zona" => 100,

                "descripcion" => 10000,

                "caracteristicas" => 10000,
            ];

            foreach (
                $limites as $campo => $limite
            ) {

                if (
                    longitudHabitat(
                        $datosFormulario[$campo]
                    ) > $limite
                ) {

                    throw new RuntimeException(
                        "El campo " .
                        str_replace(
                            "_",
                            " ",
                            $campo
                        ) .
                        " supera el límite permitido."
                    );
                }
            }

            /*
            |--------------------------------------------------------------------------
            | ÁREA
            |--------------------------------------------------------------------------
            */

            if (
                !is_numeric(
                    $datosFormulario["area_m2"]
                ) ||
                (float)
                    $datosFormulario["area_m2"] <= 0
            ) {

                throw new RuntimeException(
                    "El área debe ser un número mayor que cero."
                );
            }

            /*
            |--------------------------------------------------------------------------
            | CAPACIDAD
            |--------------------------------------------------------------------------
            */

            if (
                filter_var(
                    $datosFormulario[
                        "capacidad_animales"
                    ],
                    FILTER_VALIDATE_INT
                ) === false ||
                (int)
                    $datosFormulario[
                        "capacidad_animales"
                    ] <= 0
            ) {

                throw new RuntimeException(
                    "La capacidad debe ser un número entero mayor que cero."
                );
            }

            /*
            |--------------------------------------------------------------------------
            | VALIDAR CAPACIDAD
            |--------------------------------------------------------------------------
            */

            if (
                count(
                    $idsAnimalesSeleccionados
                ) >
                (int)
                    $datosFormulario[
                        "capacidad_animales"
                    ]
            ) {

                throw new RuntimeException(
                    "Ha seleccionado " .
                    count(
                        $idsAnimalesSeleccionados
                    ) .
                    " animales, pero el hábitat tiene capacidad para " .
                    (int)
                        $datosFormulario[
                            "capacidad_animales"
                        ] .
                    "."
                );
            }

            /*
            |--------------------------------------------------------------------------
            | ESTADO
            |--------------------------------------------------------------------------
            */

            if (
                !in_array(
                    $datosFormulario["estado"],
                    [
                        "Activo",
                        "Inactivo"
                    ],
                    true
                )
            ) {

                throw new RuntimeException(
                    "El estado seleccionado no es válido."
                );
            }

            /*
            |--------------------------------------------------------------------------
            | ICONO
            |--------------------------------------------------------------------------
            */

            if (
                !array_key_exists(
                    $datosFormulario["icono"],
                    $iconos
                )
            ) {

                throw new RuntimeException(
                    "El icono seleccionado no es válido."
                );
            }

            /*
            |--------------------------------------------------------------------------
            | NOMBRE DUPLICADO
            |--------------------------------------------------------------------------
            */

            if (
                existeNombreHabitatBD(
                    $conexion,
                    $datosFormulario["nombre"],
                    $datosFormulario["id"]
                )
            ) {

                throw new RuntimeException(
                    "Ya existe un hábitat con ese nombre."
                );
            }

            /*
            |--------------------------------------------------------------------------
            | OBTENER HÁBITAT EXISTENTE
            |--------------------------------------------------------------------------
            */

            $habitatExistente = null;

            if (
                $datosFormulario["id"] > 0
            ) {

                $habitatExistente =
                    obtenerHabitatBD(
                        $conexion,
                        $datosFormulario["id"]
                    );

                if (
                    $habitatExistente === null
                ) {

                    throw new RuntimeException(
                        "El hábitat seleccionado no existe."
                    );
                }

                if (
                    !isset($_FILES["foto"]) ||
                    $_FILES["foto"]["error"] ===
                    UPLOAD_ERR_NO_FILE
                ) {

                    $datosFormulario["foto"] =
                        $habitatExistente["foto"] ??
                        "";
                }
            }

            /*
            |--------------------------------------------------------------------------
            | SUBIR FOTO
            |--------------------------------------------------------------------------
            */

            if (
                isset($_FILES["foto"]) &&
                $_FILES["foto"]["error"] !==
                UPLOAD_ERR_NO_FILE
            ) {

                $datosFormulario["foto"] =
                    subirFotoHabitat(
                        $_FILES["foto"],
                        $datosFormulario["nombre"]
                    );
            }

            /*
            |--------------------------------------------------------------------------
            | CREAR
            |--------------------------------------------------------------------------
            */

            if (
                $datosFormulario["id"] <= 0
            ) {

                $idNuevo =
                    insertarHabitatBD(
                        $conexion,
                        $datosFormulario
                    );

                asignarAnimalesHabitatBD(
                    $conexion,
                    $idNuevo,
                    $idsAnimalesSeleccionados,
                    (int)
                        $datosFormulario[
                            "capacidad_animales"
                        ]
                );

                $detalleBitacora =
                    "Se creó el hábitat " .
                    $datosFormulario["nombre"] .
                    " de tipo " .
                    $datosFormulario["tipo_habitat"] .
                    " en la zona " .
                    $datosFormulario["zona"] .
                    " con " .
                    count(
                        $idsAnimalesSeleccionados
                    ) .
                    " animales asignados.";

                if (
                    !registrarBitacora(
                        $_SESSION["usuario"],
                        "CREAR HÁBITAT",
                        "habitat",
                        $detalleBitacora,
                        $idNuevo
                    )
                ) {

                    throw new RuntimeException(
                        "El hábitat fue creado, pero no fue posible registrar la acción en la bitácora."
                    );
                }

                redirigirHabitats(
                    "Hábitat registrado correctamente."
                );
            }

            /*
            |--------------------------------------------------------------------------
            | MODIFICAR
            |--------------------------------------------------------------------------
            */

            actualizarHabitatBD(
                $conexion,
                $datosFormulario
            );

            asignarAnimalesHabitatBD(
                $conexion,
                $datosFormulario["id"],
                $idsAnimalesSeleccionados,
                (int)
                    $datosFormulario[
                        "capacidad_animales"
                    ]
            );

            $detalleBitacora =
                "Se actualizó el hábitat " .
                $datosFormulario["nombre"] .
                " de tipo " .
                $datosFormulario["tipo_habitat"] .
                " en la zona " .
                $datosFormulario["zona"] .
                " con " .
                count(
                    $idsAnimalesSeleccionados
                ) .
                " animales asignados.";

            if (
                !registrarBitacora(
                    $_SESSION["usuario"],
                    "MODIFICAR HÁBITAT",
                    "habitat",
                    $detalleBitacora,
                    $datosFormulario["id"]
                )
            ) {

                throw new RuntimeException(
                    "El hábitat fue actualizado, pero no fue posible registrar la acción en la bitácora."
                );
            }

            redirigirHabitats(
                "Hábitat actualizado correctamente."
            );
        }

        /*
        |--------------------------------------------------------------------------
        | CAMBIAR ESTADO
        |--------------------------------------------------------------------------
        */

        if (
            $accion === "estado"
        ) {

            $id =
                (int) (
                    $_POST["id"] ?? 0
                );

            $nuevoEstado =
                $_POST["nuevo_estado"] ??
                "";

            if (
                $id <= 0 ||
                !in_array(
                    $nuevoEstado,
                    [
                        "Activo",
                        "Inactivo"
                    ],
                    true
                )
            ) {

                throw new RuntimeException(
                    "El hábitat o el estado seleccionado no es válido."
                );
            }

            $habitat =
                obtenerHabitatBD(
                    $conexion,
                    $id
                );

            if (
                $habitat === null
            ) {

                throw new RuntimeException(
                    "El hábitat seleccionado no existe."
                );
            }

            cambiarEstadoHabitatBD(
                $conexion,
                $id,
                $nuevoEstado
            );

            $nombreHabitat =
                (string) (
                    $habitat["nombre"] ??
                    "Hábitat"
                );

            $accionBitacora =
                $nuevoEstado === "Activo"
                    ? "ACTIVAR HÁBITAT"
                    : "INACTIVAR HÁBITAT";

            $detalleBitacora =
                "El hábitat " .
                $nombreHabitat .
                " cambió a " .
                $nuevoEstado .
                ".";

            if (
                !registrarBitacora(
                    $_SESSION["usuario"],
                    $accionBitacora,
                    "habitat",
                    $detalleBitacora,
                    $id
                )
            ) {

                throw new RuntimeException(
                    "El estado fue actualizado, pero no fue posible registrar la acción en la bitácora."
                );
            }

            redirigirHabitats(
                $nuevoEstado === "Activo"
                    ? "Hábitat activado correctamente."
                    : "Hábitat inactivado correctamente."
            );
        }

        throw new RuntimeException(
            "La acción solicitada no es válida."
        );

    } catch (Throwable $excepcion) {

        error_log(
            "Error gestionando hábitats: " .
            $excepcion->getMessage()
        );

        $error =
            $excepcion->getMessage();

        try {

            $habitats =
                listarHabitatsBD(
                    $conexion
                );

        } catch (Throwable $errorBD) {

            $habitats = [];
        }

        try {

            $animales =
                listarAnimalesBD(
                    $conexion
                );

        } catch (Throwable $errorBD) {

            $animales = [];
        }
    }
}

/*
|--------------------------------------------------------------------------
| CARGAR HÁBITAT PARA EDITAR
|--------------------------------------------------------------------------
*/

if (
    $_SERVER["REQUEST_METHOD"] !== "POST"
) {

    $idEditar =
        filter_input(
            INPUT_GET,
            "editar",
            FILTER_VALIDATE_INT
        ) ?: 0;

    if (
        $idEditar > 0
    ) {

        try {

            $habitatEditar =
                obtenerHabitatBD(
                    $conexion,
                    $idEditar
                );

            if (
                $habitatEditar !== null
            ) {

                $datosFormulario =
                    array_merge(
                        $datosFormulario,
                        $habitatEditar
                    );

                $animalesSeleccionados =
                    obtenerAnimalesHabitatBD(
                        $conexion,
                        $idEditar
                    );

            } else {

                $error =
                    "El hábitat seleccionado no existe.";
            }

        } catch (Throwable $excepcion) {

            error_log(
                "Error cargando hábitat para edición: " .
                $excepcion->getMessage()
            );

            $error =
                "No fue posible cargar el hábitat seleccionado.";
        }
    }
}

/*
|--------------------------------------------------------------------------
| FILTROS
|--------------------------------------------------------------------------
*/

$busqueda =
    trim(
        $_GET["buscar"] ?? ""
    );

$estadoFiltro =
    $_GET["estado"] ?? "";

if (
    !in_array(
        $estadoFiltro,
        [
            "",
            "Activo",
            "Inactivo"
        ],
        true
    )
) {

    $estadoFiltro = "";
}

$habitatsFiltrados =
    array_values(
        array_filter(
            $habitats,
            static function (
                array $habitat
            ) use (
                $busqueda,
                $estadoFiltro
            ): bool {

                if (
                    $estadoFiltro !== "" &&
                    ($habitat["estado"] ?? "") !==
                    $estadoFiltro
                ) {

                    return false;
                }

                if (
                    $busqueda === ""
                ) {

                    return true;
                }

                $texto =
                    implode(
                        " ",
                        [
                            $habitat["nombre"] ?? "",
                            $habitat["tipo_habitat"] ?? "",
                            $habitat["zona"] ?? "",
                            $habitat["descripcion"] ?? "",
                            $habitat["caracteristicas"] ?? "",
                        ]
                    );

                return stripos(
                    $texto,
                    $busqueda
                ) !== false;
            }
        )
    );

/*
|--------------------------------------------------------------------------
| PAGINACIÓN
|--------------------------------------------------------------------------
*/

$registrosPorPagina = 5;

$paginaActual =
    filter_input(
        INPUT_GET,
        "pagina",
        FILTER_VALIDATE_INT
    );

if (
    $paginaActual === false ||
    $paginaActual === null ||
    $paginaActual < 1
) {

    $paginaActual = 1;
}

$totalRegistros =
    count(
        $habitatsFiltrados
    );

$totalPaginas =
    max(
        1,
        (int) ceil(
            $totalRegistros /
            $registrosPorPagina
        )
    );

if (
    $paginaActual >
    $totalPaginas
) {

    $paginaActual =
        $totalPaginas;
}

$inicioPagina =
    ($paginaActual - 1) *
    $registrosPorPagina;

$habitatsPagina =
    array_slice(
        $habitatsFiltrados,
        $inicioPagina,
        $registrosPorPagina
    );

/*
|--------------------------------------------------------------------------
| TOTALES
|--------------------------------------------------------------------------
*/

$totalActivos =
    count(
        array_filter(
            $habitats,
            static fn(
                array $habitat
            ): bool =>
                ($habitat["estado"] ?? "") ===
                "Activo"
        )
    );

$totalInactivos =
    count($habitats) -
    $totalActivos;

$editando =
    (int) $datosFormulario["id"] > 0;

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
        Hábitats - Administración EcoFauna
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
        rel="preconnect"
        href="https://fonts.googleapis.com"
    >

    <link
        rel="preconnect"
        href="https://fonts.gstatic.com"
    >

    <link
        rel="preconnect"
        href="https://fonts.gstatic.com"
        crossorigin
    >

    <link
        href="https://fonts.googleapis.com/css2?family=DM+Serif+Display&family=Poppins:wght@400;500;600;700&display=swap"
        rel="stylesheet"
    >

    <link
        rel="stylesheet"
        href="../css/administracion.css"
    >

    <link
        rel="stylesheet"
        href="../css/habitats.css"
    >

    <style>

        /*
        |--------------------------------------------------------------------------
        | FOTOS
        |--------------------------------------------------------------------------
        */

        .foto-habitat-miniatura {

            width: 65px;

            height: 65px;

            object-fit: cover;

            border-radius: 12px;

            display: block;
        }

        .foto-habitat-placeholder {

            width: 65px;

            height: 65px;

            border-radius: 12px;

            display: flex;

            align-items: center;

            justify-content: center;

            background: #e8f3e8;

            font-size: 24px;
        }

        .habitat-foto-tabla {

            flex-shrink: 0;
        }


        /*
        |--------------------------------------------------------------------------
        | SELECTOR DE ANIMALES
        |--------------------------------------------------------------------------
        */

        .selector-animales-habitat {

            border: 1px solid #d8e2d4;

            border-radius: 16px;

            padding: 18px;

            background: #f8faf6;
        }

        .encabezado-selector-animales {

            display: flex;

            justify-content: space-between;

            align-items: center;

            gap: 15px;

            margin-bottom: 15px;
        }

        .encabezado-selector-animales strong {

            display: block;

            color: #304734;

            font-size: 16px;
        }

        .encabezado-selector-animales small {

            color: #6f7c6b;
        }

        .contador-animales-seleccionados {

            background: #607754;

            color: white;

            padding: 7px 12px;

            border-radius: 20px;

            font-size: 13px;

            font-weight: 600;

            white-space: nowrap;
        }

        .lista-animales-habitat {

            display: grid;

            grid-template-columns:
                repeat(
                    2,
                    minmax(0, 1fr)
                );

            gap: 10px;

            max-height: 350px;

            overflow-y: auto;

            padding: 5px;
        }

        .animal-selector-item {

            position: relative;

            display: flex;

            align-items: center;

            gap: 12px;

            padding: 13px;

            background: white;

            border: 1px solid #dce5d8;

            border-radius: 12px;

            cursor: pointer;

            transition: 0.2s ease;
        }

        .animal-selector-item:hover {

            border-color: #607754;

            transform: translateY(-1px);
        }

        .animal-selector-item
        .checkbox-animal-habitat {

            position: absolute;

            opacity: 0;

            pointer-events: none;
        }

        .animal-selector-check {

            width: 23px;

            height: 23px;

            min-width: 23px;

            border: 2px solid #b8c5b3;

            border-radius: 6px;

            display: flex;

            align-items: center;

            justify-content: center;

            color: transparent;

            background: white;
        }

        .checkbox-animal-habitat:checked
        + .animal-selector-check {

            background: #607754;

            border-color: #607754;

            color: white;
        }

        .animal-selector-info {

            display: flex;

            flex-direction: column;

            min-width: 0;
        }

        .animal-selector-info strong {

            color: #304734;

            font-size: 14px;
        }

        .animal-selector-info small {

            color: #788273;

            font-size: 12px;
        }

        .animal-habitat-actual {

            color: #9b6f3e !important;

            margin-top: 2px;
        }

        .ayuda-animales {

            display: block;

            margin-top: 14px;

            color: #6f7c6b;

            font-size: 12px;
        }

        .ayuda-animales i {

            margin-right: 4px;
        }

        .mensaje-sin-animales {

            padding: 20px;

            text-align: center;

            color: #6f7c6b;
        }

        .mensaje-sin-animales i {

            margin-right: 6px;
        }


        /*
        |--------------------------------------------------------------------------
        | PAGINACIÓN
        |--------------------------------------------------------------------------
        */

        .paginacion-habitats {

            display: flex;

            align-items: center;

            justify-content: space-between;

            gap: 20px;

            padding: 20px 22px;

            border-top: 1px solid #e3e9df;

            background: #fbfcfa;

            flex-wrap: wrap;
        }

        .paginacion-info {

            color: #6f7c6b;

            font-size: 13px;

            white-space: nowrap;
        }

        .paginacion-info strong {

            color: #304734;

            font-weight: 700;
        }

        .nav-paginacion-habitats {

            display: flex;

            align-items: center;

            justify-content: center;
        }

        .nav-paginacion-habitats .pagination {

            display: flex;

            align-items: center;

            justify-content: center;

            gap: 6px;

            margin: 0;

            padding: 0;

            list-style: none;
        }

        .nav-paginacion-habitats .page-item {

            display: flex;

            align-items: center;

            justify-content: center;
        }

        .nav-paginacion-habitats .page-link {

            min-width: 38px;

            height: 38px;

            padding: 0 12px;

            display: flex;

            align-items: center;

            justify-content: center;

            gap: 7px;

            border: 1px solid #d8e2d4;

            border-radius: 9px;

            background: white;

            color: #4d624d;

            font-size: 13px;

            font-weight: 600;

            text-decoration: none;

            line-height: 1;

            transition:
                background-color .2s ease,
                border-color .2s ease,
                color .2s ease,
                transform .2s ease;

            box-shadow: none;
        }

        .nav-paginacion-habitats
        .page-item:not(.disabled):not(.active)
        .page-link:hover {

            background: #edf4eb;

            border-color: #607754;

            color: #304734;

            transform: translateY(-1px);
        }

        .nav-paginacion-habitats
        .page-item.active
        .page-link {

            background: #607754;

            border-color: #607754;

            color: white;
        }

        .nav-paginacion-habitats
        .page-item.disabled
        .page-link {

            background: #f1f3ef;

            border-color: #e1e6de;

            color: #a6afa2;

            cursor: not-allowed;

            opacity: .8;
        }

        .nav-paginacion-habitats
        .page-link.puntos {

            min-width: 30px;

            padding: 0 5px;

            border-color: transparent;

            background: transparent;

            color: #879281;

            cursor: default;
        }

        .nav-paginacion-habitats
        .page-link i {

            font-size: 11px;

            line-height: 1;
        }


        /*
        |--------------------------------------------------------------------------
        | RESPONSIVE
        |--------------------------------------------------------------------------
        */

        @media (max-width: 768px) {

            .lista-animales-habitat {

                grid-template-columns: 1fr;
            }

            .encabezado-selector-animales {

                align-items: flex-start;

                flex-direction: column;
            }

            .paginacion-habitats {

                flex-direction: column;

                align-items: center;

                justify-content: center;
            }

            .paginacion-info {

                text-align: center;
            }

            .nav-paginacion-habitats
            .pagination {

                flex-wrap: wrap;
            }
        }

        @media (max-width: 480px) {

            .nav-paginacion-habitats
            .page-link {

                min-width: 36px;

                height: 36px;

                padding: 0 9px;
            }

            .nav-paginacion-habitats
            .page-link span {

                display: none;
            }
        }

    </style>

    <link rel="stylesheet" href="../css/crud-modern.css?v=1">
    <link rel="stylesheet" href="../css/ecofauna-unified.css?v=1">
</head>

<body class="ecofauna-unified crud-modern">




<!-- =====================================================================
     NAVBAR
====================================================================== -->

<nav class="navbar-admin-modulo">

    <div class="container barra-admin-modulo">

        <a
            href="../admin.php"
            class="marca-admin-modulo"
        >

            <img
                src="../img/LogoEcoFauna1.png"
                alt="Logo EcoFauna"
            >

            <span>

                <strong>
                    EcoFauna
                </strong>

                <small>
                    Administración de hábitats
                </small>

            </span>

        </a>

        <div class="acciones-navbar">

            <a
                href="../admin.php"
                class="btn-volver-admin"
            >

                <i class="bi bi-arrow-left"></i>

                Volver al panel

            </a>

        </div>

    </div>

</nav>


<!-- =====================================================================
     HERO
====================================================================== -->

<header class="hero-modulo-admin hero-habitats-admin">

    <div class="container">

        <span class="etiqueta-modulo">

            <i class="bi bi-tree-fill"></i>

            Espacios del zoológico

        </span>

        <h1>
            Administración de hábitats
        </h1>

        <p>

            Registra y administra los espacios del zoológico,
            sus características, capacidad, animales asignados
            y estado.

        </p>

    </div>

</header>


<!-- =====================================================================
     CONTENIDO
====================================================================== -->

<main class="container contenido-modulo-admin">


    <!-- =================================================================
         RESUMEN
    ================================================================== -->

    <section class="resumen-admin-grid">

        <article>

            <i class="bi bi-map-fill"></i>

            <div>

                <small>
                    Hábitats registrados
                </small>

                <strong>
                    <?= count($habitats) ?>
                </strong>

            </div>

        </article>


        <article>

            <i class="bi bi-check-circle-fill"></i>

            <div>

                <small>
                    Hábitats activos
                </small>

                <strong>
                    <?= $totalActivos ?>
                </strong>

            </div>

        </article>


        <article>

            <i class="bi bi-eye-slash-fill"></i>

            <div>

                <small>
                    Inactivos
                </small>

                <strong>
                    <?= $totalInactivos ?>
                </strong>

            </div>

        </article>

    </section>


    <!-- =================================================================
         MENSAJE
    ================================================================== -->

    <?php if ($mensaje !== ""): ?>

        <div
            class="alerta-admin
            <?= $tipoMensaje === "danger"
                ? "alerta-error"
                : "alerta-exito" ?>"
        >

            <i
                class="bi
                <?= $tipoMensaje === "danger"
                    ? "bi-exclamation-triangle-fill"
                    : "bi-check-circle-fill" ?>"
            ></i>

            <?= escaparHabitat($mensaje) ?>

        </div>

    <?php endif; ?>


    <!-- =================================================================
         ERROR
    ================================================================== -->

    <?php if ($error !== ""): ?>

        <div class="alerta-admin alerta-error">

            <i
                class="bi bi-exclamation-triangle-fill"
            ></i>

            <?= escaparHabitat($error) ?>

        </div>

    <?php endif; ?>


    <!-- =================================================================
         FORMULARIO
    ================================================================== -->

    <section
        class="tarjeta-modulo-admin formulario-habitat-card"
    >

        <div class="encabezado-tarjeta-modulo">

            <div>

                <small>

                    <?= $editando
                        ? "Edición"
                        : "Nuevo registro" ?>

                </small>

                <h2>

                    <?= $editando
                        ? "Editar hábitat"
                        : "Registrar hábitat" ?>

                </h2>

            </div>

            <span class="icono-encabezado">

                <i class="bi bi-tree-fill"></i>

            </span>

        </div>


        <form
            method="POST"
            enctype="multipart/form-data"
            class="formulario-admin-grid formulario-habitat-grid"
        >

            <?= campoCsrfSesion(
                CONTEXTO_CSRF_HABITATS
            ) ?>


            <input
                type="hidden"
                name="accion"
                value="guardar"
            >


            <input
                type="hidden"
                name="id"
                value="<?= (int) $datosFormulario["id"] ?>"
            >


            <!-- NOMBRE -->

            <div class="campo-admin">

                <label for="nombre">
                    Nombre *
                </label>

                <input
                    type="text"
                    class="form-control"
                    name="nombre"
                    id="nombre"
                    maxlength="150"
                    required
                    value="<?= escaparHabitat(
                        $datosFormulario["nombre"]
                    ) ?>"
                    placeholder="Ej. Bosque Tropical"
                >

            </div>


            <!-- TIPO -->

            <div class="campo-admin">

                <label for="tipo_habitat">
                    Tipo de hábitat *
                </label>

                <input
                    type="text"
                    class="form-control"
                    name="tipo_habitat"
                    id="tipo_habitat"
                    maxlength="100"
                    required
                    value="<?= escaparHabitat(
                        $datosFormulario["tipo_habitat"]
                    ) ?>"
                    placeholder="Ej. Selva tropical"
                >

            </div>


            <!-- ZONA -->

            <div class="campo-admin">

                <label for="zona">
                    Zona *
                </label>

                <input
                    type="text"
                    class="form-control"
                    name="zona"
                    id="zona"
                    maxlength="100"
                    required
                    value="<?= escaparHabitat(
                        $datosFormulario["zona"]
                    ) ?>"
                    placeholder="Ej. Zona Norte"
                >

            </div>


            <!-- ÁREA -->

            <div class="campo-admin">

                <label for="area_m2">
                    Área (m²) *
                </label>

                <input
                    type="number"
                    class="form-control"
                    name="area_m2"
                    id="area_m2"
                    min="0.01"
                    step="0.01"
                    required
                    value="<?= escaparHabitat(
                        (string)
                        $datosFormulario["area_m2"]
                    ) ?>"
                    placeholder="Ej. 2500.00"
                >

            </div>


            <!-- CAPACIDAD -->

            <div class="campo-admin">

                <label for="capacidad_animales">
                    Capacidad de animales *
                </label>

                <input
                    type="number"
                    class="form-control"
                    name="capacidad_animales"
                    id="capacidad_animales"
                    min="1"
                    step="1"
                    required
                    value="<?= escaparHabitat(
                        (string)
                        $datosFormulario[
                            "capacidad_animales"
                        ]
                    ) ?>"
                    placeholder="Ej. 20"
                >

            </div>


            <!-- ICONO -->

            <div class="campo-admin">

                <label for="icono">
                    Icono
                </label>

                <select
                    class="form-select"
                    name="icono"
                    id="icono"
                >

                    <?php foreach (
                        $iconos
                        as $claseIcono =>
                        $nombreIcono
                    ): ?>

                        <option
                            value="<?= escaparHabitat(
                                $claseIcono
                            ) ?>"
                            <?= $datosFormulario[
                                "icono"
                            ] === $claseIcono
                                ? "selected"
                                : "" ?>
                        >

                            <?= escaparHabitat(
                                $nombreIcono
                            ) ?>

                        </option>

                    <?php endforeach; ?>

                </select>

            </div>


            <!-- ESTADO -->

            <div class="campo-admin">

                <label for="estado">
                    Estado
                </label>

                <select
                    class="form-select"
                    name="estado"
                    id="estado"
                >

                    <option
                        value="Activo"
                        <?= $datosFormulario[
                            "estado"
                        ] === "Activo"
                            ? "selected"
                            : "" ?>
                    >
                        Activo
                    </option>

                    <option
                        value="Inactivo"
                        <?= $datosFormulario[
                            "estado"
                        ] === "Inactivo"
                            ? "selected"
                            : "" ?>
                    >
                        Inactivo
                    </option>

                </select>

            </div>


            <!-- FOTO -->

            <div class="campo-admin">

                <label for="foto">
                    Foto del hábitat
                </label>

                <input
                    type="file"
                    class="form-control"
                    name="foto"
                    id="foto"
                    accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
                >

                <?php if (
                    !empty(
                        $datosFormulario["foto"]
                    )
                ): ?>

                    <small
                        class="text-muted d-block mt-2"
                    >

                        Imagen actual:

                        <?= escaparHabitat(
                            $datosFormulario["foto"]
                        ) ?>

                    </small>

                    <input
                        type="hidden"
                        name="foto_actual"
                        value="<?= escaparHabitat(
                            $datosFormulario["foto"]
                        ) ?>"
                    >

                <?php endif; ?>

            </div>


            <!-- ANIMALES -->

            <div
                class="campo-admin campo-completo"
            >

                <label>
                    Animales asignados al hábitat
                </label>

                <div class="selector-animales-habitat">

                    <?php if (
                        $animales === []
                    ): ?>

                        <div class="mensaje-sin-animales">

                            <i class="bi bi-info-circle"></i>

                            No hay animales registrados.

                        </div>

                    <?php else: ?>

                        <div
                            class="encabezado-selector-animales"
                        >

                            <div>

                                <strong>
                                    Seleccionar animales
                                </strong>

                                <small>
                                    Puede seleccionar varios animales.
                                </small>

                            </div>

                            <span
                                id="contadorAnimalesSeleccionados"
                                class="contador-animales-seleccionados"
                            >

                                <?= count(
                                    $animalesSeleccionados
                                ) ?>

                                seleccionados

                            </span>

                        </div>


                        <div
                            class="lista-animales-habitat"
                        >

                            <?php foreach (
                                $animales
                                as $animal
                            ): ?>

                                <?php

                                $idAnimal =
                                    (int)
                                    $animal[
                                        "id_animal"
                                    ];

                                $seleccionado =
                                    in_array(
                                        $idAnimal,
                                        $animalesSeleccionados,
                                        true
                                    );

                                ?>

                                <label
                                    class="animal-selector-item"
                                    for="animal_<?= $idAnimal ?>"
                                >

                                    <input
                                        type="checkbox"
                                        name="animales[]"
                                        id="animal_<?= $idAnimal ?>"
                                        value="<?= $idAnimal ?>"
                                        class="checkbox-animal-habitat"
                                        <?= $seleccionado
                                            ? "checked"
                                            : "" ?>
                                    >

                                    <span
                                        class="animal-selector-check"
                                    >

                                        <i
                                            class="bi bi-check-lg"
                                        ></i>

                                    </span>

                                    <span
                                        class="animal-selector-info"
                                    >

                                        <strong>

                                            <?= escaparHabitat(
                                                $animal[
                                                    "nombre_animal"
                                                ]
                                            ) ?>

                                        </strong>

                                        <small>

                                            Código:

                                            <?= escaparHabitat(
                                                $animal[
                                                    "codigo_animal"
                                                ]
                                            ) ?>

                                        </small>

                                        <?php if (
                                            !empty(
                                                $animal[
                                                    "nombre_habitat"
                                                ]
                                            ) &&
                                            !$seleccionado
                                        ): ?>

                                            <small
                                                class="animal-habitat-actual"
                                            >

                                                Actualmente:

                                                <?= escaparHabitat(
                                                    $animal[
                                                        "nombre_habitat"
                                                    ]
                                                ) ?>

                                            </small>

                                        <?php endif; ?>

                                    </span>

                                </label>

                            <?php endforeach; ?>

                        </div>


                        <small
                            class="ayuda-animales"
                        >

                            <i
                                class="bi bi-info-circle"
                            ></i>

                            Los animales seleccionados
                            pertenecerán a este hábitat.
                            Si desmarca un animal que ya
                            estaba asignado, quedará sin hábitat.

                        </small>

                    <?php endif; ?>

                </div>

            </div>


            <!-- CARACTERÍSTICAS -->

            <div
                class="campo-admin campo-completo"
            >

                <label for="caracteristicas">
                    Características
                </label>

                <textarea
                    class="form-control"
                    name="caracteristicas"
                    id="caracteristicas"
                    placeholder="Ej. Vegetación abundante, árboles altos, zonas húmedas..."
                ><?= escaparHabitat(
                    $datosFormulario[
                        "caracteristicas"
                    ]
                ) ?></textarea>

            </div>


            <!-- DESCRIPCIÓN -->

            <div
                class="campo-admin campo-completo"
            >

                <label for="descripcion">
                    Descripción *
                </label>

                <textarea
                    class="form-control"
                    name="descripcion"
                    id="descripcion"
                    required
                    placeholder="Describe el ambiente y las condiciones del hábitat."
                ><?= escaparHabitat(
                    $datosFormulario[
                        "descripcion"
                    ]
                ) ?></textarea>

            </div>


            <!-- BOTONES -->

            <div
                class="campo-completo acciones-formulario"
            >

                <?php if ($editando): ?>

                    <a
                        href="habitats.php"
                        class="btn-cancelar-admin"
                    >

                        <i class="bi bi-x-lg"></i>

                        Cancelar edición

                    </a>

                <?php endif; ?>


                <button
                    type="submit"
                    class="btn-guardar-admin"
                >

                    <i class="bi bi-floppy-fill"></i>

                    <?= $editando
                        ? "Guardar cambios"
                        : "Registrar hábitat" ?>

                </button>

            </div>

        </form>

    </section>


    <!-- =================================================================
         LISTA DE HÁBITATS
    ================================================================== -->

    <section
        class="tarjeta-modulo-admin lista-habitats-admin"
    >

        <div
            class="encabezado-tarjeta-modulo encabezado-lista-habitats"
        >

            <div>

                <small>
                    Catálogo
                </small>

                <h2>
                    Hábitats registrados
                </h2>

            </div>

            <span class="contador-registros">

                <?= count(
                    $habitatsFiltrados
                ) ?>

            </span>

        </div>


        <!-- FILTROS -->

        <form
            method="GET"
            class="filtros-habitats-admin"
        >

            <div
                class="campo-busqueda-habitat"
            >

                <i class="bi bi-search"></i>

                <input
                    type="search"
                    name="buscar"
                    value="<?= escaparHabitat(
                        $busqueda
                    ) ?>"
                    placeholder="Buscar nombre, tipo, zona o características"
                >

            </div>


            <select
                name="estado"
                class="form-select"
            >

                <option value="">
                    Todos los estados
                </option>

                <option
                    value="Activo"
                    <?= $estadoFiltro === "Activo"
                        ? "selected"
                        : "" ?>
                >
                    Activos
                </option>

                <option
                    value="Inactivo"
                    <?= $estadoFiltro === "Inactivo"
                        ? "selected"
                        : "" ?>
                >
                    Inactivos
                </option>

            </select>


            <button
                type="submit"
                class="btn-guardar-admin"
            >

                <i class="bi bi-funnel-fill"></i>

                Filtrar

            </button>


            <a
                href="habitats.php"
                class="btn-secundario-admin"
            >

                <i
                    class="bi bi-arrow-counterclockwise"
                ></i>

                Limpiar

            </a>

        </form>


        <!-- TABLA -->

        <div class="table-responsive">

            <table
                class="table tabla-admin tabla-habitats-admin mb-0"
            >

                <thead>

                    <tr>

                        <th>
                            Hábitat
                        </th>

                        <th>
                            Tipo
                        </th>

                        <th>
                            Zona
                        </th>

                        <th>
                            Área
                        </th>

                        <th>
                            Capacidad
                        </th>

                        <th>
                            Estado
                        </th>

                        <th class="text-end">
                            Acciones
                        </th>

                    </tr>

                </thead>


                <tbody>

                <?php if (
                    $habitatsPagina === []
                ): ?>

                    <tr>

                        <td
                            colspan="7"
                            class="estado-vacio-admin"
                        >

                            <i class="bi bi-tree"></i>

                            No se encontraron hábitats
                            con los filtros seleccionados.

                        </td>

                    </tr>

                <?php else: ?>


                    <?php foreach (
                        $habitatsPagina
                        as $habitat
                    ): ?>

                        <tr>

                            <!-- HÁBITAT -->

                            <td>

                                <div
                                    class="habitat-tabla-nombre"
                                >

                                    <div
                                        class="habitat-foto-tabla"
                                    >

                                        <?php if (
                                            !empty(
                                                $habitat[
                                                    "foto"
                                                ]
                                            )
                                        ): ?>

                                            <img
                                                src="../<?= escaparHabitat(
                                                    $habitat[
                                                        "foto"
                                                    ]
                                                ) ?>"
                                                alt="Foto de <?= escaparHabitat(
                                                    $habitat[
                                                        "nombre"
                                                    ] ??
                                                    "Hábitat"
                                                ) ?>"
                                                class="foto-habitat-miniatura"
                                            >

                                        <?php else: ?>

                                            <span
                                                class="foto-habitat-placeholder"
                                            >

                                                <i
                                                    class="bi bi-tree-fill"
                                                ></i>

                                            </span>

                                        <?php endif; ?>

                                    </div>


                                    <div>

                                        <strong>

                                            <?= escaparHabitat(
                                                $habitat[
                                                    "nombre"
                                                ] ??
                                                ""
                                            ) ?>

                                        </strong>


                                        <small>

                                            <?= escaparHabitat(
                                                $habitat[
                                                    "descripcion"
                                                ] ??
                                                ""
                                            ) ?>

                                        </small>

                                    </div>

                                </div>

                            </td>


                            <!-- TIPO -->

                            <td>

                                <?= escaparHabitat(
                                    $habitat[
                                        "tipo_habitat"
                                    ] ?? "—"
                                ) ?>

                            </td>


                            <!-- ZONA -->

                            <td>

                                <?= escaparHabitat(
                                    $habitat[
                                        "zona"
                                    ] ?? "—"
                                ) ?>

                            </td>


                            <!-- ÁREA -->

                            <td>

                                <?= number_format(
                                    (float) (
                                        $habitat[
                                            "area_m2"
                                        ] ?? 0
                                    ),
                                    2,
                                    ",",
                                    "."
                                ) ?>

                                m²

                            </td>


                            <!-- CAPACIDAD -->

                            <td>

                                <?= (int) (
                                    $habitat[
                                        "capacidad_animales"
                                    ] ?? 0
                                ) ?>

                                animales

                            </td>


                            <!-- ESTADO -->

                            <td>

                                <span
                                    class="badge-estado
                                    <?= (
                                        $habitat[
                                            "estado"
                                        ] ?? ""
                                    ) === "Activo"
                                        ? "activa"
                                        : "bloqueada" ?>"
                                >

                                    <i
                                        class="bi bi-circle-fill"
                                    ></i>

                                    <?= escaparHabitat(
                                        $habitat[
                                            "estado"
                                        ] ??
                                        "Inactivo"
                                    ) ?>

                                </span>

                            </td>


                            <!-- ACCIONES -->

                            <td>

                                <div
                                    class="acciones-tabla"
                                >

                                    <!-- EDITAR -->

                                    <a
                                        class="btn-tabla editar"
                                        href="habitats.php?editar=<?= (int) (
                                            $habitat["id"] ?? 0
                                        ) ?>"
                                        title="Editar hábitat"
                                        aria-label="Editar hábitat"
                                    >

                                        <i
                                            class="bi bi-pencil-fill"
                                        ></i>

                                    </a>


                                    <!-- CAMBIAR ESTADO -->

                                    <form
                                        method="POST"
                                    >

                                        <?= campoCsrfSesion(
                                            CONTEXTO_CSRF_HABITATS
                                        ) ?>


                                        <input
                                            type="hidden"
                                            name="accion"
                                            value="estado"
                                        >


                                        <input
                                            type="hidden"
                                            name="id"
                                            value="<?= (int) (
                                                $habitat["id"] ?? 0
                                            ) ?>"
                                        >


                                        <?php

                                        $estaActivo =
                                            (
                                                $habitat[
                                                    "estado"
                                                ] ?? ""
                                            ) === "Activo";

                                        ?>


                                        <input
                                            type="hidden"
                                            name="nuevo_estado"
                                            value="<?= $estaActivo
                                                ? "Inactivo"
                                                : "Activo" ?>"
                                        >


                                        <button
                                            type="submit"
                                            class="btn-tabla
                                            <?= $estaActivo
                                                ? "ocultar"
                                                : "activar" ?>"
                                            title="<?= $estaActivo
                                                ? "Inactivar"
                                                : "Activar" ?> hábitat"
                                            aria-label="<?= $estaActivo
                                                ? "Inactivar"
                                                : "Activar" ?> hábitat"
                                        >

                                            <i
                                                class="bi
                                                <?= $estaActivo
                                                    ? "bi-eye-slash-fill"
                                                    : "bi-eye-fill" ?>"
                                            ></i>

                                        </button>

                                    </form>

                                </div>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                <?php endif; ?>

                </tbody>

            </table>

        </div>


        <!-- =================================================================
             PAGINACIÓN
        ================================================================== -->

        <?php if ($totalPaginas > 1): ?>

            <div class="paginacion-habitats">

                <div class="paginacion-info">

                    Mostrando

                    <strong>
                        <?= $inicioPagina + 1 ?>
                    </strong>

                    -

                    <strong>
                        <?= min(
                            $inicioPagina +
                            $registrosPorPagina,
                            $totalRegistros
                        ) ?>
                    </strong>

                    de

                    <strong>
                        <?= $totalRegistros ?>
                    </strong>

                    hábitats

                </div>


                <nav
                    aria-label="Paginación de hábitats"
                    class="nav-paginacion-habitats"
                >

                    <ul class="pagination">


                        <!-- ANTERIOR -->

                        <li
                            class="page-item
                            <?= $paginaActual <= 1
                                ? "disabled"
                                : "" ?>"
                        >

                            <?php if (
                                $paginaActual > 1
                            ): ?>

                                <a
                                    class="page-link"
                                    href="?<?= http_build_query([
                                        "buscar" =>
                                            $busqueda,

                                        "estado" =>
                                            $estadoFiltro,

                                        "pagina" =>
                                            $paginaActual - 1
                                    ]) ?>"
                                    aria-label="Página anterior"
                                >

                                    <i
                                        class="bi bi-chevron-left"
                                    ></i>

                                    <span>
                                        Anterior
                                    </span>

                                </a>

                            <?php else: ?>

                                <span class="page-link">

                                    <i
                                        class="bi bi-chevron-left"
                                    ></i>

                                    <span>
                                        Anterior
                                    </span>

                                </span>

                            <?php endif; ?>

                        </li>


                        <!-- PRIMERA PÁGINA -->

                        <?php

                        $rangoInicio =
                            max(
                                1,
                                $paginaActual - 2
                            );

                        $rangoFin =
                            min(
                                $totalPaginas,
                                $paginaActual + 2
                            );

                        ?>


                        <?php if (
                            $rangoInicio > 1
                        ): ?>

                            <li class="page-item">

                                <a
                                    class="page-link"
                                    href="?<?= http_build_query([
                                        "buscar" =>
                                            $busqueda,

                                        "estado" =>
                                            $estadoFiltro,

                                        "pagina" =>
                                            1
                                    ]) ?>"
                                >
                                    1
                                </a>

                            </li>


                            <?php if (
                                $rangoInicio > 2
                            ): ?>

                                <li
                                    class="page-item disabled"
                                >

                                    <span
                                        class="page-link puntos"
                                    >
                                        ...
                                    </span>

                                </li>

                            <?php endif; ?>

                        <?php endif; ?>


                        <!-- NÚMEROS -->

                        <?php for (
                            $pagina =
                                $rangoInicio;

                            $pagina <=
                                $rangoFin;

                            $pagina++
                        ): ?>

                            <li
                                class="page-item
                                <?= $pagina ===
                                    $paginaActual
                                    ? "active"
                                    : "" ?>"
                            >

                                <a
                                    class="page-link"
                                    href="?<?= http_build_query([
                                        "buscar" =>
                                            $busqueda,

                                        "estado" =>
                                            $estadoFiltro,

                                        "pagina" =>
                                            $pagina
                                    ]) ?>"
                                >

                                    <?= $pagina ?>

                                </a>

                            </li>

                        <?php endfor; ?>


                        <!-- ÚLTIMA PÁGINA -->

                        <?php if (
                            $rangoFin <
                            $totalPaginas
                        ): ?>


                            <?php if (
                                $rangoFin <
                                $totalPaginas - 1
                            ): ?>

                                <li
                                    class="page-item disabled"
                                >

                                    <span
                                        class="page-link puntos"
                                    >
                                        ...
                                    </span>

                                </li>

                            <?php endif; ?>


                            <li class="page-item">

                                <a
                                    class="page-link"
                                    href="?<?= http_build_query([
                                        "buscar" =>
                                            $busqueda,

                                        "estado" =>
                                            $estadoFiltro,

                                        "pagina" =>
                                            $totalPaginas
                                    ]) ?>"
                                >

                                    <?= $totalPaginas ?>

                                </a>

                            </li>

                        <?php endif; ?>


                        <!-- SIGUIENTE -->

                        <li
                            class="page-item
                            <?= $paginaActual >=
                                $totalPaginas
                                ? "disabled"
                                : "" ?>"
                        >

                            <?php if (
                                $paginaActual <
                                $totalPaginas
                            ): ?>

                                <a
                                    class="page-link"
                                    href="?<?= http_build_query([
                                        "buscar" =>
                                            $busqueda,

                                        "estado" =>
                                            $estadoFiltro,

                                        "pagina" =>
                                            $paginaActual + 1
                                    ]) ?>"
                                    aria-label="Página siguiente"
                                >

                                    <span>
                                        Siguiente
                                    </span>

                                    <i
                                        class="bi bi-chevron-right"
                                    ></i>

                                </a>

                            <?php else: ?>

                                <span class="page-link">

                                    <span>
                                        Siguiente
                                    </span>

                                    <i
                                        class="bi bi-chevron-right"
                                    ></i>

                                </span>

                            <?php endif; ?>

                        </li>

                    </ul>

                </nav>

            </div>

        <?php endif; ?>

    </section>

</main>


<!-- =====================================================================
     JAVASCRIPT
====================================================================== -->

<script>

document.addEventListener(
    "DOMContentLoaded",
    function () {

        const checkboxes =
            document.querySelectorAll(
                ".checkbox-animal-habitat"
            );

        const contador =
            document.getElementById(
                "contadorAnimalesSeleccionados"
            );

        const capacidad =
            document.getElementById(
                "capacidad_animales"
            );


        /*
        |--------------------------------------------------------------------------
        | ACTUALIZAR CONTADOR
        |--------------------------------------------------------------------------
        */

        function actualizarContador() {

            if (!contador) {
                return;
            }

            const seleccionados =
                document.querySelectorAll(
                    ".checkbox-animal-habitat:checked"
                ).length;

            contador.textContent =
                seleccionados +
                (
                    seleccionados === 1
                        ? " seleccionado"
                        : " seleccionados"
                );
        }


        /*
        |--------------------------------------------------------------------------
        | VALIDAR CAPACIDAD
        |--------------------------------------------------------------------------
        */

        function validarCapacidad(evento) {

            if (!capacidad) {
                return;
            }

            const limite =
                parseInt(
                    capacidad.value,
                    10
                ) || 0;

            const seleccionados =
                document.querySelectorAll(
                    ".checkbox-animal-habitat:checked"
                ).length;

            if (
                evento.target.checked &&
                seleccionados > limite
            ) {

                evento.target.checked =
                    false;

                alert(
                    "El hábitat tiene capacidad para " +
                    limite +
                    " animales."
                );
            }

            actualizarContador();
        }


        /*
        |--------------------------------------------------------------------------
        | EVENTOS CHECKBOX
        |--------------------------------------------------------------------------
        */

        checkboxes.forEach(
            function (checkbox) {

                checkbox.addEventListener(
                    "change",
                    validarCapacidad
                );

            }
        );


        /*
        |--------------------------------------------------------------------------
        | VALIDAR CAMBIO DE CAPACIDAD
        |--------------------------------------------------------------------------
        */

        if (capacidad) {

            capacidad.addEventListener(
                "input",
                function () {

                    const limite =
                        parseInt(
                            capacidad.value,
                            10
                        ) || 0;

                    const seleccionados =
                        document.querySelectorAll(
                            ".checkbox-animal-habitat:checked"
                        ).length;

                    if (
                        seleccionados > limite
                    ) {

                        capacidad.setCustomValidity(
                            "La capacidad no puede ser menor que la cantidad de animales seleccionados."
                        );

                    } else {

                        capacidad.setCustomValidity(
                            ""
                        );
                    }

                }
            );
        }


        /*
        |--------------------------------------------------------------------------
        | CONTADOR INICIAL
        |--------------------------------------------------------------------------
        */

        actualizarContador();

    }
);

</script>


</body>

</html>