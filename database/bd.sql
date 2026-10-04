-- Todos los datos personales y accesos incluidos son ficticios y exclusivos de demostración.
-- Instalación de demostración. No elimina bases existentes.
CREATE DATABASE ZoologicoDBPortafolio CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE ZoologicoDBPortafolio;
-- =========================================================================
-- 1. TABLAS DE SISTEMA, ROLES Y SEGURIDAD
-- =========================================================================
CREATE TABLE rol(
    id_rol INT AUTO_INCREMENT PRIMARY KEY,
    nombre_rol VARCHAR(100) NOT NULL UNIQUE,
    descripcion VARCHAR(255)
);

CREATE TABLE usuario(
    id_usuario INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(100) NOT NULL,
    correo VARCHAR(100) NOT NULL UNIQUE,
    telefono VARCHAR(20),
    fecha_nacimiento DATE
);

CREATE TABLE usuarios_rol(
    id_usuarios_rol INT AUTO_INCREMENT PRIMARY KEY,
    id_usuario INT NOT NULL UNIQUE,
    id_rol INT NOT NULL,
    usuario VARCHAR(50) NOT NULL UNIQUE,
    contrasena VARCHAR(255) NOT NULL,
    FOREIGN KEY (id_rol) REFERENCES rol(id_rol),
    FOREIGN KEY (id_usuario) REFERENCES usuario(id_usuario)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE seguridad_cuenta(
    id_seguridad INT AUTO_INCREMENT PRIMARY KEY,
    id_usuarios_rol INT NOT NULL UNIQUE,
    intentos_fallidos TINYINT UNSIGNED NOT NULL DEFAULT 0,
    cuenta_bloqueada BOOLEAN NOT NULL DEFAULT FALSE,
    fecha_bloqueo DATETIME NULL,
    ultimo_intento_fallido DATETIME NULL,
    CONSTRAINT fk_seguridad_cuenta_usuario
        FOREIGN KEY (id_usuarios_rol)
        REFERENCES usuarios_rol(id_usuarios_rol)
        ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE recuperacion_contrasena(
    id_recuperacion BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    id_usuarios_rol INT NOT NULL,
    token_hash CHAR(64)
        CHARACTER SET ascii
        COLLATE ascii_bin
        NOT NULL UNIQUE,
    fecha_solicitud DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    fecha_expiracion DATETIME NOT NULL,
    utilizado BOOLEAN NOT NULL DEFAULT FALSE,
    fecha_utilizacion DATETIME NULL,
    ip_solicitud VARCHAR(45) NULL,
    CONSTRAINT fk_recuperacion_usuario
        FOREIGN KEY (id_usuarios_rol)
        REFERENCES usuarios_rol(id_usuarios_rol)
        ON DELETE CASCADE,
    INDEX idx_recuperacion_usuario (
        id_usuarios_rol,
        utilizado,
        fecha_expiracion
    )
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE permisos(
    id_permisos INT AUTO_INCREMENT PRIMARY KEY,
    nombre_permiso VARCHAR(100) NOT NULL,
    descripcion VARCHAR(255)
);

CREATE TABLE asignar_permisos(
    id_asignarpermisos INT AUTO_INCREMENT PRIMARY KEY,
    id_rol INT,
    id_permisos INT,
    FOREIGN KEY(id_rol) REFERENCES rol(id_rol),
    FOREIGN KEY(id_permisos) REFERENCES permisos(id_permisos),
    UNIQUE(id_rol, id_permisos)
);

-- =========================================================================
-- 2. TABLAS DE BITÁCORAS
-- =========================================================================
CREATE TABLE bitacora(
    id_bitacora INT AUTO_INCREMENT PRIMARY KEY,
    usuario VARCHAR(100) NULL,
    accion VARCHAR(100) NOT NULL,
    tabla_afectada VARCHAR(100) NOT NULL,
    registro_id INT NULL,
    detalles TEXT NULL,
    ip VARCHAR(45) DEFAULT '127.0.0.1',
    fecha_hora DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- =========================================================================
-- 3. INVENTARIO TIENDA Y VENTAS TIENDA
-- =========================================================================
CREATE TABLE Producto(
    id_Producto INT AUTO_INCREMENT PRIMARY KEY,
    nombre_producto VARCHAR(100) UNIQUE,
    tipo_producto VARCHAR(50),
    estado VARCHAR(20) DEFAULT 'Activo'
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE inventario_tienda(
    id_inventario_tienda INT AUTO_INCREMENT PRIMARY KEY,
    id_producto INT NOT NULL,
    foto LONGBLOB NULL,
    fecha_ingreso DATE NOT NULL,
    stock INT NOT NULL,
    precio_compra DECIMAL(10, 2) NOT NULL,
    precio_venta DECIMAL(10, 2) NOT NULL,
    FOREIGN KEY(id_producto) REFERENCES Producto(id_Producto) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
CREATE TABLE sucursal (
    id_sucursal INT AUTO_INCREMENT PRIMARY KEY,
    nombre_sucursal VARCHAR(100) NOT NULL,
    provincia VARCHAR(100) NOT NULL,
    canton VARCHAR(100) NOT NULL,
    distrito VARCHAR(100) NOT NULL,
    direccion TEXT NOT NULL,
    telefono VARCHAR(20),
    horario VARCHAR(150),
    estado ENUM('Activa','Inactiva') NOT NULL DEFAULT 'Activa'
);
CREATE TABLE tarjeta_cliente (
    id_tarjeta INT AUTO_INCREMENT PRIMARY KEY,
    id_usuario INT NOT NULL,
    id_tarjeta_banco INT NOT NULL,
    numero_tarjeta VARCHAR(200) NOT NULL,
    titular VARCHAR(150) NOT NULL,
    fecha_vencimiento DATE NOT NULL,
    tipo_tarjeta ENUM('Credito','Debito') NOT NULL,
    banco VARCHAR(100) NOT NULL,
    estado ENUM('Activa','Bloqueada','Inactiva') DEFAULT 'Activa',

    FOREIGN KEY(id_usuario)
        REFERENCES usuario(id_usuario),

    UNIQUE(id_usuario,id_tarjeta_banco)
);
select * from tarjeta_cliente;
CREATE TABLE tarjeta_banco (
    id_tarjeta_banco INT AUTO_INCREMENT PRIMARY KEY,
    banco VARCHAR(100) NOT NULL,
    numero_tarjeta VARCHAR(200) NOT NULL UNIQUE,
    titular VARCHAR(150) NOT NULL,
    fecha_vencimiento DATE NOT NULL,
    tipo_tarjeta ENUM('Credito','Debito') NOT NULL,
    cvc VARCHAR(200) NOT NULL,
    estado ENUM('Activa','Bloqueada') DEFAULT 'Activa'
);
CREATE TABLE direccion_entrega(
    id_direccion INT AUTO_INCREMENT PRIMARY KEY,
    id_usuario INT NOT NULL,
    nombre_recibe VARCHAR(150) NOT NULL,
    provincia VARCHAR(100) NOT NULL,
    canton VARCHAR(100) NOT NULL,
    distrito VARCHAR(100) NOT NULL,
    direccion TEXT NOT NULL,
    fecha_registro DATETIME DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (id_usuario)
        REFERENCES usuario(id_usuario)
        ON DELETE CASCADE
);
CREATE TABLE venta_tienda(
    id_venta_tienda INT AUTO_INCREMENT PRIMARY KEY,
    id_usuario INT NOT NULL,
    fecha_emision DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    metodo_pago VARCHAR(50) NOT NULL DEFAULT 'Tarjeta',
    tipo_entrega ENUM('Entrega','Retiro') NOT NULL,
    id_direccion INT NULL,
    id_sucursal INT NULL,
    id_tarjeta INT NOT NULL,
    total_factura DECIMAL(10,2) NOT NULL,
    estado VARCHAR(20) NOT NULL DEFAULT 'pendiente',
    CONSTRAINT chk_estado_venta
    CHECK (
        estado IN (
            'pendiente',
            'pagada',
            'cancelada',
            'reembolsada'
        )
    ),

    FOREIGN KEY (id_usuario)
        REFERENCES usuario(id_usuario),
    FOREIGN KEY (id_direccion)
        REFERENCES direccion_entrega(id_direccion),
    FOREIGN KEY (id_sucursal)
        REFERENCES sucursal(id_sucursal),
    FOREIGN KEY (id_tarjeta)
        REFERENCES tarjeta_cliente(id_tarjeta)
);

INSERT INTO sucursal (nombre_sucursal, provincia, canton, distrito, direccion, telefono, horario, estado)
 VALUES ('EcoFauna Cartago Centro', 'Cartago', 'Cartago', 'Oriental', '200 metros norte del Parque Central', '0000-0000', 'Lunes a Domingo 8:00 a.m. - 5:00 p.m.', 'Activa');
INSERT INTO sucursal (nombre_sucursal, provincia, canton, distrito, direccion, telefono, horario, estado) 
VALUES ('EcoFauna San José Centro', 'San José', 'San José', 'Catedral', 'Avenida Central, frente al Teatro Nacional', '0000-0000', 'Lunes a Domingo 9:00 a.m. - 6:00 p.m.', 'Activa');
INSERT INTO sucursal (nombre_sucursal, provincia, canton, distrito, direccion, telefono, horario, estado) 
VALUES ('EcoFauna Heredia', 'Heredia', 'Heredia', 'Carmen', '100 metros oeste del Parque Central de Heredia', '0000-0000', 'Lunes a Sábado 8:30 a.m. - 5:30 p.m.', 'Activa');

CREATE TABLE detalle_venta_tienda(
    id_detalle_venta_tienda INT AUTO_INCREMENT PRIMARY KEY,
    id_venta_tienda INT,
    id_Producto INT,
    cantidad INT,
    precio_unitario DECIMAL(10, 2),
    subtotal DECIMAL(10, 2),
    FOREIGN KEY(id_venta_tienda) REFERENCES venta_tienda(id_venta_tienda),
    FOREIGN KEY(id_Producto) REFERENCES Producto(id_Producto) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- =========================================================================
-- 4. VISITANTES, TICKETS Y ENTRADAS
-- =========================================================================

CREATE TABLE tarifa_entrada (
    id_tarifa INT AUTO_INCREMENT PRIMARY KEY,
    categoria VARCHAR(30) NOT NULL UNIQUE,
    precio DECIMAL(10, 2) NOT NULL,
    estado VARCHAR(20)
);

CREATE TABLE disponibilidad_dia_zoo(
    id_disponibilidad INT AUTO_INCREMENT PRIMARY KEY,
    fecha DATE NOT NULL UNIQUE,
    hora_apertura TIME DEFAULT '08:00:00',
    hora_cierre TIME DEFAULT '16:00:00',
    capacidad_total INT NOT NULL,
    capacidad_disponible INT NOT NULL,
    estado VARCHAR(20) DEFAULT 'Disponible',
    id_usuario_admin INT,
    FOREIGN KEY(id_usuario_admin) REFERENCES usuario(id_usuario),
    CONSTRAINT chk_capacidad_disponibilidad CHECK (
        capacidad_total > 0
        AND capacidad_disponible BETWEEN 0 AND capacidad_total
    ),
    CONSTRAINT chk_horario_disponibilidad CHECK (
        hora_apertura < hora_cierre
    ),
    CONSTRAINT chk_estado_disponibilidad CHECK (
        estado IN ('Disponible', 'Completado', 'Cerrado')
    )
);

CREATE TABLE ticket_entrada(
    id_ticket_entrada INT AUTO_INCREMENT PRIMARY KEY,
    id_usuario INT,
    metodo_pago VARCHAR(50),
    fecha_emision DATETIME DEFAULT CURRENT_TIMESTAMP,
    total_ticket DECIMAL(10, 2) NOT NULL,
    iva DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    estado VARCHAR(30),
    id_disponibilidad INT,
    FOREIGN KEY(id_usuario) REFERENCES usuario(id_usuario),
    FOREIGN KEY(id_disponibilidad) REFERENCES disponibilidad_dia_zoo(id_disponibilidad)
);

CREATE TABLE detalle_ticket_entrada(
    id_detalle_ticket_entrada INT AUTO_INCREMENT PRIMARY KEY,
    id_ticket_entrada INT,
    id_tarifa INT,
    cantidad INT,
    precio DECIMAL(10, 2) NOT NULL,
    subtotal DECIMAL(10, 2) NOT NULL,
    FOREIGN KEY (id_ticket_entrada) REFERENCES ticket_entrada(id_ticket_entrada),
    FOREIGN KEY (id_tarifa) REFERENCES tarifa_entrada(id_tarifa)
);

CREATE TABLE boleto_zoologico (
    id_boleto_zoologico INT AUTO_INCREMENT PRIMARY KEY,
    id_ticket_entrada INT NOT NULL,
    codigo_ingreso CHAR(5) UNIQUE,
    estado_ticket VARCHAR(20) DEFAULT 'Activo',
    FOREIGN KEY(id_ticket_entrada) REFERENCES ticket_entrada(id_ticket_entrada)
);
select * from boleto_zoologico;
CREATE TABLE registro_acceso (
    id_registro_acceso INT AUTO_INCREMENT PRIMARY KEY,
    id_entrada INT NOT NULL UNIQUE,
    hora_entrada DATETIME,
    hora_salida DATETIME,
    FOREIGN KEY (id_entrada) REFERENCES boleto_zoologico(id_boleto_zoologico)
);

-- =========================================================================
-- 5. ANIMALES, HÁBITATS, CUIDADOS Y VETERINARIA
-- =========================================================================

CREATE TABLE habitat (
    id_habitat INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(150) NOT NULL,
    tipo_habitat VARCHAR(100) NOT NULL,
    zona VARCHAR(100) NOT NULL,
    area_m2 DECIMAL(10,2) NOT NULL,
    capacidad_animales INT NOT NULL,
    descripcion TEXT NOT NULL,
    caracteristicas TEXT NULL,
    foto VARCHAR(255) NULL,
    icono VARCHAR(100) DEFAULT 'bi-tree-fill',
    estado ENUM('Activo', 'Inactivo') NOT NULL DEFAULT 'Activo',
    fecha_registro DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    fecha_actualizacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;


CREATE TABLE animal (
    id_animal INT AUTO_INCREMENT PRIMARY KEY,
    codigo_animal VARCHAR(20) NOT NULL UNIQUE,
    nombre_animal VARCHAR(100) NOT NULL,
    fecha_nacimiento DATE NOT NULL,
    fecha_entrada DATE NOT NULL,
    peso DECIMAL(10,2) NOT NULL,
    altura DECIMAL(5,2) NOT NULL,
    foto LONGBLOB NULL,
    id_habitat INT NULL,

    CONSTRAINT fk_animal_habitat
        FOREIGN KEY (id_habitat)
        REFERENCES habitat(id_habitat)
        ON UPDATE CASCADE
        ON DELETE SET NULL

) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;

    
CREATE TABLE enfermedad (
    id_enfermedad INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(100) NOT NULL,
    tipo VARCHAR(20) NOT NULL CHECK(tipo IN ('Enfermedad', 'Alergia')),
    descripcion VARCHAR(500) NULL,
    activo TINYINT NOT NULL DEFAULT 1,
    UNIQUE(nombre, tipo),
    CONSTRAINT chk_enfermedad_activa CHECK (activo IN (0, 1))
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE historialmedicoanimal (
    id_historial INT AUTO_INCREMENT PRIMARY KEY,
    id_animal INT NOT NULL,
    id_enfermedad INT NOT NULL,
    fecha_diagnostico DATE NOT NULL,
    fecha_recuperacion DATE NULL,
    tratamiento VARCHAR(500) NULL,
    observaciones VARCHAR(500) NULL,
    activo TINYINT NOT NULL DEFAULT 1,
    FOREIGN KEY(id_animal) REFERENCES animal(id_animal) ON DELETE CASCADE,
    FOREIGN KEY(id_enfermedad) REFERENCES enfermedad(id_enfermedad) ON DELETE CASCADE,
    CONSTRAINT chk_fechas_historial CHECK (
        fecha_recuperacion IS NULL
        OR fecha_recuperacion >= fecha_diagnostico
    ),
    CONSTRAINT chk_historial_activo CHECK (activo IN (0, 1))
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE alertavacuna (
    id_alerta INT AUTO_INCREMENT PRIMARY KEY,
    id_animal INT NOT NULL,
    mensaje VARCHAR(500) NOT NULL,
    fecha_generada DATETIME DEFAULT CURRENT_TIMESTAMP,
    activo TINYINT NOT NULL DEFAULT 1,
    FOREIGN KEY(id_animal) REFERENCES animal(id_animal) ON DELETE CASCADE,
    CONSTRAINT chk_alerta_activa CHECK (activo IN (0, 1))
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE vacunacion (
    id_vacunacion INT AUTO_INCREMENT PRIMARY KEY,
    id_animal INT NOT NULL,
    nombre_vacuna VARCHAR(100) NOT NULL,
    fecha_vacunacion DATE NOT NULL,
    fecha_proxima_vacuna DATE NULL,
    observaciones VARCHAR(500) NULL,
    activo TINYINT NOT NULL DEFAULT 1,
    FOREIGN KEY(id_animal) REFERENCES animal(id_animal) ON DELETE CASCADE,
    CONSTRAINT chk_fechas_vacunacion CHECK (
        fecha_proxima_vacuna IS NULL
        OR fecha_proxima_vacuna >= fecha_vacunacion
    ),
    CONSTRAINT chk_vacunacion_activa CHECK (activo IN (0, 1))
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE inventariozoo (
    id_inventarioZoo INT AUTO_INCREMENT PRIMARY KEY,
    nombre_producto VARCHAR(100) NOT NULL,
    unidad_medida VARCHAR(30) NOT NULL,
    cantidad DECIMAL(10, 2) NOT NULL,
    fecha_caducidad DATE NULL,
    fecha_ingreso DATE NOT NULL,
    activo TINYINT NOT NULL DEFAULT 1,
    CONSTRAINT chk_cantidad_inventario_zoo CHECK (cantidad >= 0),
    CONSTRAINT chk_inventario_zoo_activo CHECK (activo IN (0, 1))
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE alimentacion_animal (
    id_alimentacion INT AUTO_INCREMENT PRIMARY KEY,
    id_animal INT NOT NULL,
    id_inventarioZoo INT NOT NULL,
    hora TIME NOT NULL,
    cantidad_recomendada DECIMAL(10, 2) NOT NULL,
    activo TINYINT NOT NULL DEFAULT 1,
    FOREIGN KEY(id_animal) REFERENCES animal(id_animal) ON DELETE CASCADE,
    FOREIGN KEY(id_inventarioZoo) REFERENCES inventariozoo(id_inventarioZoo) ON DELETE CASCADE,
    CONSTRAINT chk_cantidad_alimentacion CHECK (cantidad_recomendada > 0),
    CONSTRAINT chk_alimentacion_activa CHECK (activo IN (0, 1))
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- =========================================================
-- 1. CUIDADORES
-- =========================================================

CREATE TABLE cuidador (
    id_cuidador INT AUTO_INCREMENT PRIMARY KEY,
    id_usuario INT NOT NULL UNIQUE,
    fecha_contratacion DATE NOT NULL,
    especialidad VARCHAR(100),
    estado ENUM('Activo', 'Inactivo') NOT NULL DEFAULT 'Activo',

    CONSTRAINT fk_cuidador_usuario
        FOREIGN KEY (id_usuario)
        REFERENCES usuario(id_usuario)
        ON DELETE CASCADE
) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;


-- =========================================================
-- 2. HORARIO DEL CUIDADOR
-- =========================================================

CREATE TABLE horario_cuidador (
    id_horario INT AUTO_INCREMENT PRIMARY KEY,
    id_cuidador INT NOT NULL,

    dia_semana ENUM(
        'Lunes',
        'Martes',
        'Miercoles',
        'Jueves',
        'Viernes',
        'Sabado',
        'Domingo'
    ) NOT NULL,

    hora_entrada TIME NOT NULL,
    hora_salida TIME NOT NULL,
      estado ENUM(
        'activo',
        'inactivo'
    ) NOT NULL DEFAULT 'activo',

    CONSTRAINT fk_horario_cuidador
        FOREIGN KEY (id_cuidador)
        REFERENCES cuidador(id_cuidador)
        ON DELETE CASCADE,

    CONSTRAINT chk_horario_cuidador
        CHECK (hora_entrada < hora_salida),

    UNIQUE(id_cuidador, dia_semana)

) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;


-- =========================================================
-- 3. ASIGNACIÓN CUIDADOR - ANIMAL
-- =========================================================

CREATE TABLE cuidador_animal (
    id_cuidador_animal INT AUTO_INCREMENT PRIMARY KEY,
    id_cuidador INT NOT NULL,
    id_animal INT NOT NULL,
    fecha_asignacion DATE NOT NULL,
    fecha_finalizacion DATE NULL,

    responsabilidad VARCHAR(255) NOT NULL,

    estado ENUM(
        'Activo',
        'Finalizado'
    ) NOT NULL DEFAULT 'Activo',

    CONSTRAINT fk_cuidador_animal_cuidador
        FOREIGN KEY (id_cuidador)
        REFERENCES cuidador(id_cuidador)
        ON DELETE CASCADE,

    CONSTRAINT fk_cuidador_animal_animal
        FOREIGN KEY (id_animal)
        REFERENCES animal(id_animal)
        ON DELETE CASCADE,

    CONSTRAINT chk_fechas_asignacion
        CHECK (
            fecha_finalizacion IS NULL
            OR fecha_finalizacion >= fecha_asignacion
        )

) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;

CREATE TABLE tarea (
    id_tarea INT AUTO_INCREMENT PRIMARY KEY,
    id_cuidador_animal INT NOT NULL,
    nombre_tarea VARCHAR(150) NOT NULL,
    descripcion TEXT NULL,
    frecuencia VARCHAR(100) NULL,
    hora_programada TIME NULL,
    observaciones TEXT NULL,
    estado ENUM('Activa', 'Finalizada')
        NOT NULL DEFAULT 'Activa',
    fecha_registro DATETIME
        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_tarea_cuidador_animal
        FOREIGN KEY (id_cuidador_animal)
        REFERENCES cuidador_animal(id_cuidador_animal)
        ON DELETE CASCADE
        ON UPDATE CASCADE
);

-- =========================================================
-- 4. CATÁLOGO DE TAREAS
-- =========================================================

CREATE TABLE tarea_cuidador (
    id_tarea INT AUTO_INCREMENT PRIMARY KEY,

    nombre_tarea VARCHAR(100) NOT NULL UNIQUE,

    descripcion VARCHAR(500),

    estado ENUM(
        'Activa',
        'Inactiva'
    ) NOT NULL DEFAULT 'Activa'

) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;


-- =========================================================
-- 5. TAREAS ASIGNADAS A CUIDADOR + ANIMAL
-- =========================================================

CREATE TABLE cuidador_animal_tarea (
    id_cuidador_animal_tarea INT AUTO_INCREMENT PRIMARY KEY,

    id_cuidador_animal INT NOT NULL,
    id_tarea INT NOT NULL,

    frecuencia VARCHAR(100),

    hora_programada TIME NULL,

    observaciones VARCHAR(500),

    estado ENUM(
        'Activa',
        'Finalizada'
    ) NOT NULL DEFAULT 'Activa',

    CONSTRAINT fk_cat_cuidador_animal
        FOREIGN KEY (id_cuidador_animal)
        REFERENCES cuidador_animal(id_cuidador_animal)
        ON DELETE CASCADE,

    CONSTRAINT fk_cat_tarea
        FOREIGN KEY (id_tarea)
        REFERENCES tarea_cuidador(id_tarea)
        ON DELETE CASCADE,

    UNIQUE(
        id_cuidador_animal,
        id_tarea
    )

) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;

-- =========================================================================
-- 6. CARGA DE DATOS INICIALES Y CONFIGURACIÓN
-- =========================================================================
INSERT INTO
    rol(nombre_rol, descripcion)
VALUES
    ('Administrador', 'Acceso completo'),
    ('Empleado', 'Ventas'),
    ('Veterinario', 'Área médica'),
    ('Cliente', 'Acceso a consultas de cliente');

INSERT INTO
    usuario (nombre, correo, telefono, fecha_nacimiento)
VALUES
    (
        'Administrador General',
        'admin@example.com',
        '0000-0000',
        '1980-01-01'
    ),
    (
        'Empleado Pérez',
        'empleado@example.com',
        '0000-0000',
        '1990-02-02'
    ),
    (
        'Veterinario Gómez',
        'vet@example.com',
        '0000-0000',
        '1985-03-03'
    ),
    (
        'Cliente Ramírez',
        'cliente@example.com',
        '0000-0000',
        '2000-04-04'
    ),
    (
        'Visitante Demo Uno',
        'visitante1@example.com',
        '0000-0000',
        '2000-01-01'
    );

INSERT INTO
    usuarios_rol (id_usuario, id_rol, usuario, contrasena)
VALUES
    (
        1,
        1,
        'admin',
        '$2y$10$FUoDQCQ2d40w9ctuC1AbV.XeyQQub/WwbDV/SyDCaqJxFhwGBxZhy'
    ),
    (
        2,
        2,
        'empleado',
        '$2y$10$FUoDQCQ2d40w9ctuC1AbV.XeyQQub/WwbDV/SyDCaqJxFhwGBxZhy'
    ),
    (
        3,
        3,
        'vet',
        '$2y$10$FUoDQCQ2d40w9ctuC1AbV.XeyQQub/WwbDV/SyDCaqJxFhwGBxZhy'
    ),
    (
        4,
        4,
        'cliente',
        '$2y$10$FUoDQCQ2d40w9ctuC1AbV.XeyQQub/WwbDV/SyDCaqJxFhwGBxZhy'
    ),
    (
        5,
        4,
        'visitante1',
        '$2y$10$FUoDQCQ2d40w9ctuC1AbV.XeyQQub/WwbDV/SyDCaqJxFhwGBxZhy'
    );

INSERT INTO
    seguridad_cuenta (id_usuarios_rol)
SELECT
    id_usuarios_rol
FROM
    usuarios_rol;

DELIMITER //

CREATE TRIGGER tr_AuditarCrearUsuario
AFTER INSERT
    ON usuarios_rol FOR EACH ROW BEGIN
INSERT INTO
    seguridad_cuenta (id_usuarios_rol)
VALUES
    (NEW.id_usuarios_rol);

INSERT INTO
    bitacora(
        usuario,
        accion,
        tabla_afectada,
        registro_id,
        detalles,
        ip
    )
VALUES
    (
        COALESCE(@usuario_sistema, 'Sistema'),
        'CREAR USUARIO',
        'usuarios_rol',
        NEW.id_usuarios_rol,
        CONCAT(
            'Se creó el usuario: ',
            NEW.usuario,
            ' con rol ID: ',
            NEW.id_rol
        ),
        COALESCE(@ip_sistema, '127.0.0.1')
    );

END //

CREATE TRIGGER tr_AuditarModificarUsuario
AFTER UPDATE
    ON usuarios_rol FOR EACH ROW BEGIN
INSERT INTO
    bitacora(
        usuario,
        accion,
        tabla_afectada,
        registro_id,
        detalles,
        ip
    )
VALUES
    (
        COALESCE(@usuario_sistema, 'Sistema'),
        'MODIFICAR USUARIO',
        'usuarios_rol',
        OLD.id_usuarios_rol,
        CONCAT(
            'Se actualizaron los datos de acceso del usuario "',
            OLD.usuario,
            '" (ID: ',
            OLD.id_usuarios_rol,
            ').'
        ),
        COALESCE(@ip_sistema, '127.0.0.1')
    );

END //

CREATE TRIGGER tr_AuditarEliminarUsuario
AFTER
    DELETE ON usuarios_rol FOR EACH ROW BEGIN
INSERT INTO
    bitacora(
        usuario,
        accion,
        tabla_afectada,
        registro_id,
        detalles,
        ip
    )
VALUES
    (
        COALESCE(@usuario_sistema, 'Sistema'),
        'ELIMINAR USUARIO',
        'usuarios_rol',
        OLD.id_usuarios_rol,
        CONCAT('Se eliminó el usuario: ', OLD.usuario),
        COALESCE(@ip_sistema, '127.0.0.1')
    );

END //

DELIMITER ;

INSERT INTO
    enfermedad (nombre, tipo, descripcion)
VALUES
    (
        'Gripe',
        'Enfermedad',
        'Infección respiratoria viral.'
    ),
    (
        'Parásitos intestinales',
        'Enfermedad',
        'Presencia de parásitos en el sistema digestivo.'
    ),
    (
        'Dermatitis',
        'Enfermedad',
        'Irritación o inflamación de la piel.'
    ),
    (
        'Desnutrición',
        'Enfermedad',
        'Deficiencia de nutrientes.'
    ),
    (
        'Infección respiratoria',
        'Enfermedad',
        'Afección del sistema respiratorio.'
    ),
    (
        'Herida',
        'Enfermedad',
        'Lesión física que requiere control veterinario.'
    ),
    (
        'Alergia al mango',
        'Alergia',
        'Reacción adversa al mango.'
    ),
    (
        'Sensibilidad a semillas de girasol',
        'Alergia',
        'Reacción adversa a semillas de girasol.'
    ),
    (
        'Sensibilidad a conservantes de pescado',
        'Alergia',
        'Reacción adversa a conservantes de pescado.'
    ),
    (
        'Alergia a la lechuga romana',
        'Alergia',
        'Reacción adversa a lechuga romana.'
    );

-- =========================================================
-- INSERTAR PERMISOS DEL SISTEMA
-- =========================================================

INSERT INTO permisos (nombre_permiso, descripcion) VALUES

-- VETERINARIO
('CONSULTAR_BITACORA',
 'Permite consultar los registros de la bitacora'),

('GESTIONAR_INVENTARIO_ZOO',
 'Permite gestionar el inventario del zoologico'),

('REGISTRAR_ALIMENTACION',
 'Permite registrar y gestionar la alimentacion de los animales'),

('REGISTRAR_VACUNACION',
 'Permite registrar y gestionar vacunaciones'),

('REGISTRAR_ENFERMEDADES',
 'Permite registrar y gestionar enfermedades y alergias'),

('REGISTRAR_ANIMALES',
 'Permite registrar y gestionar animales'),

('GESTIONAR_PERFIL',
 'Permite consultar y modificar el perfil del usuario'),

('CONSULTAR_HISTORIAL_MEDICO',
 'Permite consultar el historial medico de los animales'),

-- ADMINISTRADOR
('GESTIONAR_USUARIOS',
 'Permite gestionar usuarios del sistema'),

('GESTIONAR_CUIDADORES',
 'Permite gestionar cuidadores'),

('GESTIONAR_TIENDA',
 'Permite gestionar productos, inventario y ventas de la tienda'),

('GESTIONAR_HABITATS',
 'Permite gestionar habitats del zoologico'),

-- CLIENTE
('CONSULTAR_MIS_ENTRADAS',
 'Permite consultar las entradas compradas por el cliente'),

('COMPRAR_ENTRADAS',
 'Permite comprar entradas al zoologico'),

('COMPRAR_TIENDA',
 'Permite comprar productos de la tienda'),

('CONSULTAR_HABITATS',
 'Permite consultar los habitats disponibles del zoologico');


INSERT INTO permisos (nombre_permiso, descripcion) VALUES

(
    'GESTIONAR_TICKETS_VENDIDOS',
    'Permite consultar y gestionar los tickets vendidos'
),

(
    'GESTIONAR_VENTA_ENTRADAS',
    'Permite realizar y gestionar la venta de boletos o entradas'
),

(
    'GESTIONAR_INVENTARIO_ZOO_EMPLEADO',
    'Permite gestionar el inventario del zoologico asignado al empleado'
),

(
    'GESTIONAR_CONTROL_ACCESOS',
    'Permite gestionar el control de entrada y salida de visitantes'
);





INSERT INTO animal (codigo_animal, nombre_animal, fecha_nacimiento, fecha_entrada, peso, altura, foto) VALUES
('ANI-001', 'León', '2020-05-15', '2022-01-20', 190.50, 1.20, NULL),
('ANI-002', 'Jirafa', '2018-08-10', '2021-06-15', 800.00, 4.80, NULL),
('ANI-003', 'Tucán', '2022-03-01', '2023-11-12', 0.65, 0.55, NULL),
('ANI-004', 'Tigre de Bengala', '2019-04-12', '2021-10-05', 220.30, 1.10, NULL),
('ANI-005', 'Oso Polar', '2017-12-01', '2020-03-15', 310.00, 1.50, NULL),
('ANI-006', 'Pingüino de Humboldt', '2021-07-22', '2022-09-10', 4.50, 0.70, NULL),
('ANI-007', 'Mono Capuchino', '2022-01-15', '2023-04-20', 3.20, 0.45, NULL),
('ANI-008', 'Tortuga Gigante', '1995-09-10', '2015-05-12', 245.00, 0.95, NULL),
('ANI-009', 'Elefante Asiático', '2010-06-25', '2018-11-30', 3850.00, 3.20, NULL),
('ANI-010', 'Cebra de Grant', '2020-02-28', '2022-08-14', 315.80, 1.45, NULL);
INSERT INTO
    inventariozoo (
        nombre_producto,
        unidad_medida,
        cantidad,
        fecha_caducidad,
        fecha_ingreso
    )
VALUES
    (
        'Manzana',
        'Kilogramos',
        50.00,
        DATE_ADD(CURDATE(), INTERVAL 60 DAY),
        CURDATE()
    ),
    (
        'Banano',
        'Kilogramos',
        25.00,
        DATE_ADD(CURDATE(), INTERVAL 10 DAY),
        CURDATE()
    ),
    (
        'Carne de res',
        'Kilogramos',
        80.00,
        DATE_SUB(CURDATE(), INTERVAL 3 DAY),
        DATE_SUB(CURDATE(), INTERVAL 15 DAY)
    ),
    (
        'Antibiótico veterinario',
        'Unidades',
        20.00,
        DATE_ADD(CURDATE(), INTERVAL 120 DAY),
        CURDATE()
    ),
    ('Cloro', 'Litros', 15.00, NULL, CURDATE()),
    (
        'Mango',
        'Kilogramos',
        45.00,
        DATE_ADD(CURDATE(), INTERVAL 15 DAY),
        CURDATE()
    ),
    (
        'Semillas de girasol',
        'Kilogramos',
        30.00,
        DATE_ADD(CURDATE(), INTERVAL 45 DAY),
        CURDATE()
    ),
    (
        'Pescado fresco',
        'Kilogramos',
        60.00,
        DATE_ADD(CURDATE(), INTERVAL 7 DAY),
        CURDATE()
    ),
    (
        'Lechuga romana',
        'Kilogramos',
        40.00,
        DATE_ADD(CURDATE(), INTERVAL 5 DAY),
        CURDATE()
    ),
    -- Medicinas
    (
        'Antiparasitario',
        'Unidades',
        40.00,
        DATE_ADD(CURDATE(), INTERVAL 180 DAY),
        CURDATE()
    ),
    (
        'Vitaminas inyectables',
        'Unidades',
        25.00,
        DATE_ADD(CURDATE(), INTERVAL 200 DAY),
        CURDATE()
    ),
    (
        'Vacuna contra rabia',
        'Unidades',
        50.00,
        DATE_ADD(CURDATE(), INTERVAL 300 DAY),
        CURDATE()
    ),
    (
        'Analgésico veterinario',
        'Unidades',
        40.00,
        DATE_ADD(CURDATE(), INTERVAL 150 DAY),
        CURDATE()
    ),
    (
        'Antiinflamatorio veterinario',
        'Unidades',
        35.00,
        DATE_ADD(CURDATE(), INTERVAL 250 DAY),
        CURDATE()
    ),
    (
        'Suero veterinario',
        'Litros',
        25.00,
        DATE_ADD(CURDATE(), INTERVAL 100 DAY),
        CURDATE()
    ),
    (
        'Pomada cicatrizante',
        'Unidades',
        45.00,
        DATE_ADD(CURDATE(), INTERVAL 120 DAY),
        CURDATE()
    ),
    -- Limpieza y herramientas
    (
        'Desinfectante de jaulas',
        'Litros',
        100.00,
        NULL,
        CURDATE()
    ),
    (
        'Jabón antibacterial',
        'Litros',
        120.00,
        NULL,
        CURDATE()
    ),
    (
        'Cloro industrial',
        'Litros',
        150.00,
        NULL,
        CURDATE()
    ),
    (
        'Bolsas para residuos',
        'Paquetes',
        200.00,
        NULL,
        CURDATE()
    ),
    (
        'Guantes desechables',
        'Cajas',
        80.00,
        NULL,
        CURDATE()
    ),
    (
        'Cepillos de limpieza',
        'Unidades',
        30.00,
        NULL,
        CURDATE()
    ),
    (
        'Pala para limpieza',
        'Unidades',
        15.00,
        NULL,
        CURDATE()
    ),
    (
        'Escoba industrial',
        'Unidades',
        20.00,
        NULL,
        CURDATE()
    ),
    (
        'Manguera de agua',
        'Metros',
        100.00,
        NULL,
        CURDATE()
    ),
    (
        'Pinzas veterinarias',
        'Unidades',
        12.00,
        NULL,
        CURDATE()
    ),
    (
        'Jaula transportadora',
        'Unidades',
        10.00,
        NULL,
        CURDATE()
    ),
    (
        'Termómetro veterinario',
        'Unidades',
        8.00,
        NULL,
        CURDATE()
    );

INSERT INTO
    historialmedicoanimal (
        id_animal,
        id_enfermedad,
        fecha_diagnostico,
        fecha_recuperacion,
        tratamiento,
        observaciones
    )
VALUES
    (
        1,
        1,
        '2026-01-10',
        '2026-01-20',
        'Antivirales y reposo térmico.',
        'Recuperado por completo. Sin secuelas.'
    ),
    (
        2,
        4,
        '2025-11-05',
        '2025-12-05',
        'Suplementación de vitaminas y dieta reforzada.',
        'Se estabilizó el peso ideal de la jirafa.'
    ),
    (
        3,
        6,
        '2026-03-01',
        '2026-03-10',
        'Limpieza diaria y pomada cicatrizante en ala izquierda.',
        'Herida cerrada con éxito.'
    ),
    (
        7,
        2,
        '2026-05-15',
        NULL,
        'Desparasitante oral de amplio espectro.',
        'En observación por aislamiento preventivo en cuarentena.'
    ),
    (
        9,
        5,
        '2026-07-01',
        NULL,
        'Antibióticos inyectables y nebulizaciones.',
        'Estado delicado pero estable. Monitoreo diario.'
    ),
    (
        2,
        7,
        '2021-06-15',
        NULL,
        'Evitar alimentos con mango.',
        'Alergia alimentaria crónica.'
    ),
    (
        3,
        8,
        '2023-11-12',
        NULL,
        'Evitar semillas de girasol.',
        'Alergia alimentaria crónica.'
    ),
    (
        6,
        9,
        '2022-09-10',
        NULL,
        'Evitar conservantes de pescado.',
        'Alergia alimentaria crónica.'
    ),
    (
        8,
        10,
        '2015-05-12',
        NULL,
        'Evitar lechuga romana.',
        'Alergia alimentaria crónica.'
    );

DELIMITER $$
CREATE PROCEDURE sp_Registrarboleto(
    IN p_id_ticket INT,
    IN p_codigo_ingreso VARCHAR(50)
) BEGIN
INSERT INTO
    boleto_zoologico (
        id_ticket_entrada,
        codigo_ingreso,
        estado_ticket
    )
VALUES
    (
        p_id_ticket,
        p_codigo_ingreso,
        'Activo'
    );

END $$
DELIMITER ;

DELIMITER $$
DROP PROCEDURE IF EXISTS registrarVenta $$
CREATE PROCEDURE registrarVenta(
    IN p_metodo_pago VARCHAR(50),
    IN p_total DECIMAL(10, 2),
    IN p_iva DECIMAL(10, 2),
    IN p_id_disponibilidad INT,
    OUT p_id_ticket INT
) BEGIN
INSERT INTO
    ticket_entrada(
        fecha_emision,
        metodo_pago,
        total_ticket,
        iva,
        id_disponibilidad,
        estado
    )
VALUES
    (
        NOW(),
        p_metodo_pago,
        p_total,
        p_iva,
        p_id_disponibilidad,
        'Pagada'
    );

SET
    p_id_ticket = LAST_INSERT_ID();

END $$
DELIMITER ;

-- =========================================================================
-- CARGA DE DATOS INICIALES INVENTARIO TIENDA
-- =========================================================================
INSERT INTO
    Producto (id_Producto, nombre_producto, tipo_producto)
VALUES
    (1, 'Manzana tienda', 'Alimento'),
    (2, 'Banano tienda', 'Alimento'),
    (3, 'Refresco embotellado', 'Alimento'),
    (4, 'Gorra eco', 'Souvenir'),
    (5, 'Llavero Tucán', 'Souvenir'),
    (6, 'Camiseta EcoFauna', 'Souvenir');

INSERT INTO
    inventario_tienda (
        id_producto,
        stock,
        precio_compra,
        precio_venta,
        fecha_ingreso
    )
VALUES
    (1, 50, 300.00, 450.00, CURDATE()),
    (2, 100, 100.00, 150.00, CURDATE()),
    (3, 150, 400.00, 700.00, CURDATE()),
    (4, 30, 2000.00, 3500.00, CURDATE()),
    (5, 100, 500.00, 1000.00, CURDATE()),
    (6, 40, 3000.00, 5000.00, CURDATE());

INSERT INTO
    tarifa_entrada (id_tarifa, categoria, precio, estado)
VALUES
    (1, 'Niños', 3500.00, 'Activa'),
    (2, 'Adultos', 6500.00, 'Activa'),
    (3, 'Estudiantes', 4500.00, 'Activa'),
    (4, 'Adulto Mayor', 5000.00, 'Activa'),
    (5, 'Especial', 8000.00, 'Activa'),
    (6, 'Familiar', 12000.00, 'Activa');

INSERT INTO
    disponibilidad_dia_zoo (
        id_disponibilidad,
        fecha,
        capacidad_total,
        capacidad_disponible,
        estado,
        id_usuario_admin
    )
VALUES
    (1, DATE_ADD(CURDATE(), INTERVAL 1 DAY), 100, 100, 'Disponible', 1),
    (2, DATE_ADD(CURDATE(), INTERVAL 2 DAY), 100, 100, 'Disponible', 1),
    (3, DATE_ADD(CURDATE(), INTERVAL 3 DAY), 100, 100, 'Disponible', 1),
    (4, DATE_ADD(CURDATE(), INTERVAL 4 DAY), 100, 100, 'Disponible', 1),
    (5, DATE_ADD(CURDATE(), INTERVAL 5 DAY), 100, 100, 'Disponible', 1),
    (6, DATE_ADD(CURDATE(), INTERVAL 6 DAY), 100, 100, 'Disponible', 1),
    (7, DATE_ADD(CURDATE(), INTERVAL 7 DAY), 100, 100, 'Disponible', 1);

INSERT INTO
    ticket_entrada (
        id_usuario,
        metodo_pago,
        total_ticket,
        estado,
        id_disponibilidad
    )
VALUES
    (NULL, 'Efectivo', 7000, 'Pagada', 1),
    (NULL, 'Tarjeta', 6500, 'Pagada', 1),
    (NULL, 'SINPE', 24000, 'Pagada', 2),
    (NULL, 'Tarjeta', 5000, 'Pendiente', 2),
    (NULL, 'Efectivo', 13500, 'Pagada', 3),
    (NULL, 'SINPE', 16000, 'Pagada', 3),
    (NULL, 'Tarjeta', 13000, 'Pagada', 4),
    (NULL, 'Efectivo', 3500, 'Cancelada', 4),
    (NULL, 'SINPE', 12000, 'Pagada', 5),
    (NULL, 'Tarjeta', 16000, 'Pagada', 5),
    (NULL, 'Efectivo', 10000, 'Pagada', 6),
    (NULL, 'SINPE', 4500, 'Pagada', 6),
    (NULL, 'Tarjeta', 19500, 'Pendiente', 7),
    (NULL, 'Efectivo', 14000, 'Pagada', 7),
    (NULL, 'Tarjeta', 24000, 'Pagada', 1),
    (NULL, 'SINPE', 8000, 'Pagada', 2),
    (NULL, 'Efectivo', 10000, 'Vencida', 3),
    (NULL, 'Tarjeta', 9000, 'Pagada', 4),
    (NULL, 'SINPE', 6500, 'Pagada', 5),
    (NULL, 'Efectivo', 10500, 'Pendiente', 6);

INSERT INTO
    detalle_ticket_entrada (
        id_detalle_ticket_entrada,
        id_ticket_entrada,
        id_tarifa,
        cantidad,
        precio,
        subtotal
    )
VALUES
    (1, 1, 1, 2, 3500, 7000),
    (2, 2, 2, 1, 6500, 6500),
    (3, 3, 6, 2, 12000, 24000),
    (4, 4, 4, 1, 5000, 5000),
    (5, 5, 3, 3, 4500, 13500),
    (6, 6, 5, 2, 8000, 16000),
    (7, 7, 2, 2, 6500, 13000),
    (8, 8, 1, 1, 3500, 3500),
    (9, 9, 6, 1, 12000, 12000),
    (10, 10, 5, 2, 8000, 16000),
    (11, 11, 4, 2, 5000, 10000),
    (12, 12, 3, 1, 4500, 4500),
    (13, 13, 2, 3, 6500, 19500),
    (14, 14, 1, 4, 3500, 14000),
    (15, 15, 6, 2, 12000, 24000),
    (16, 16, 5, 1, 8000, 8000),
    (17, 17, 4, 2, 5000, 10000),
    (18, 18, 3, 2, 4500, 9000),
    (19, 19, 2, 1, 6500, 6500),
    (20, 20, 1, 3, 3500, 10500);


INSERT INTO
    boleto_zoologico (id_ticket_entrada, codigo_ingreso, estado_ticket)
VALUES
    (1, 'A0001', 'Activo'),
    (2, 'A0002', 'Activo'),
    (3, 'A0003', 'Activo'),
    (4, 'A0004', 'Activo'),
    (5, 'A0005', 'Activo'),
    (6, 'A0006', 'Usado'),
    (7, 'A0007', 'Activo'),
    (8, 'A0008', 'Vencido'),
    (9, 'A0009', 'Activo'),
    (10, 'A0010', 'Activo'),
    (11, 'A0011', 'Activo'),
    (12, 'A0012', 'Usado'),
    (13, 'A0013', 'Activo'),
    (14, 'A0014', 'Activo'),
    (15, 'A0015', 'Activo'),
    (16, 'A0016', 'Activo'),
    (17, 'A0017', 'Vencido'),
    (18, 'A0018', 'Activo'),
    (19, 'A0019', 'Activo'),
    (20, 'A0020', 'Activo');

-- =========================================================================
-- 7. TRIGGERS DE VALIDACIÓN POST-CARGA
-- =========================================================================
DELIMITER //
CREATE TRIGGER tr_BloquearInventarioVencido
BEFORE INSERT ON inventariozoo
FOR EACH ROW
BEGIN
    IF NEW.fecha_caducidad IS NOT NULL
       AND NEW.fecha_caducidad < CURDATE() THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Error: No se puede registrar un producto ya vencido.';
    END IF;
END //

CREATE TRIGGER tr_BloquearInventarioVencidoUpdate
BEFORE UPDATE ON inventariozoo
FOR EACH ROW
BEGIN
    IF NEW.activo = 1
       AND NEW.fecha_caducidad IS NOT NULL
       AND NEW.fecha_caducidad < CURDATE() THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Error: No se puede registrar un producto ya vencido.';
    END IF;
END //

CREATE TRIGGER tr_ValidarVacuna
BEFORE INSERT ON vacunacion
FOR EACH ROW
BEGIN
    IF NEW.fecha_vacunacion > CURDATE() THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Error: La fecha de vacunación no puede estar en el futuro.';
    END IF;
END //

CREATE TRIGGER tr_ValidarVacunaUpdate
BEFORE UPDATE ON vacunacion
FOR EACH ROW
BEGIN
    IF NEW.fecha_vacunacion > CURDATE() THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Error: La fecha de vacunación no puede estar en el futuro.';
    END IF;
END //

CREATE TRIGGER tr_SincronizarEstadoDisponibilidad
BEFORE UPDATE ON disponibilidad_dia_zoo
FOR EACH ROW
BEGIN
    IF NEW.capacidad_total <= 0
       OR NEW.capacidad_disponible < 0
       OR NEW.capacidad_disponible > NEW.capacidad_total THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Error: La capacidad disponible no es válida.';
    END IF;

    IF NEW.estado <> 'Cerrado' THEN
        SET NEW.estado = IF(
            NEW.capacidad_disponible = 0,
            'Completado',
            'Disponible'
        );
    END IF;
END //

CREATE TRIGGER tr_ValidarAlimentacionInsert
BEFORE INSERT ON alimentacion_animal
FOR EACH ROW
BEGIN
    DECLARE v_cantidad DECIMAL(10, 2) DEFAULT NULL;
    DECLARE v_fecha_caducidad DATE DEFAULT NULL;
    DECLARE v_activo TINYINT DEFAULT NULL;

    SELECT cantidad, fecha_caducidad, activo
      INTO v_cantidad, v_fecha_caducidad, v_activo
    FROM inventariozoo
    WHERE id_inventarioZoo = NEW.id_inventarioZoo;

    IF v_cantidad IS NULL OR v_activo <> 1 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Error: El alimento no existe o está inactivo.';
    END IF;

    IF v_fecha_caducidad IS NOT NULL
       AND v_fecha_caducidad < CURDATE() THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Error: No se puede asignar un alimento vencido.';
    END IF;

    IF NEW.cantidad_recomendada <= 0
       OR NEW.cantidad_recomendada > v_cantidad THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Error: La cantidad recomendada no es válida.';
    END IF;
END //

CREATE TRIGGER tr_ValidarAlimentacionUpdate
BEFORE UPDATE ON alimentacion_animal
FOR EACH ROW
BEGIN
    DECLARE v_cantidad DECIMAL(10, 2) DEFAULT NULL;
    DECLARE v_fecha_caducidad DATE DEFAULT NULL;
    DECLARE v_activo TINYINT DEFAULT NULL;

    SELECT cantidad, fecha_caducidad, activo
      INTO v_cantidad, v_fecha_caducidad, v_activo
    FROM inventariozoo
    WHERE id_inventarioZoo = NEW.id_inventarioZoo;

    IF NEW.activo = 1 AND (v_cantidad IS NULL OR v_activo <> 1) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Error: El alimento no existe o está inactivo.';
    END IF;

    IF NEW.activo = 1
       AND v_fecha_caducidad IS NOT NULL
       AND v_fecha_caducidad < CURDATE() THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Error: No se puede asignar un alimento vencido.';
    END IF;

    IF NEW.activo = 1
       AND (
           NEW.cantidad_recomendada <= 0
           OR NEW.cantidad_recomendada > v_cantidad
       ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Error: La cantidad recomendada no es válida.';
    END IF;
END //
DELIMITER ;

DROP PROCEDURE IF EXISTS sp_CrearVentaTienda;

DELIMITER $$
CREATE PROCEDURE sp_CrearVentaTienda(
    IN p_id_usuario INT,
    IN p_metodo_pago VARCHAR(50),
    OUT p_id_venta INT
) BEGIN
INSERT INTO
    venta_tienda (
        id_usuario,
        fecha_emision,
        metodo_pago,
        total_factura,
        estado
    )
VALUES
    (
        p_id_usuario,
        NOW(),
        p_metodo_pago,
        0,
        'pagada'
    );

SET
    p_id_venta = LAST_INSERT_ID();

END $$
DELIMITER ;

DROP PROCEDURE IF EXISTS sp_AgregarDetalleVentaTienda;

DELIMITER $$
CREATE PROCEDURE sp_AgregarDetalleVentaTienda(
    IN p_id_venta INT,
    IN p_id_producto INT,
    IN p_cantidad INT
) BEGIN DECLARE v_precio DECIMAL(10, 2);

DECLARE v_stock INT;

IF p_cantidad <= 0 THEN SIGNAL SQLSTATE '45000'
SET
    MESSAGE_TEXT = 'La cantidad debe ser mayor que cero';

END IF;

SELECT
    precio_venta,
    stock INTO v_precio,
    v_stock
FROM
    inventario_tienda
WHERE
    id_producto = p_id_producto
FOR UPDATE;

IF v_precio IS NULL THEN SIGNAL SQLSTATE '45000'
SET
    MESSAGE_TEXT = 'Producto no existe';

END IF;

IF v_stock < p_cantidad THEN SIGNAL SQLSTATE '45000'
SET
    MESSAGE_TEXT = 'Stock insuficiente';

END IF;

INSERT INTO
    detalle_venta_tienda (
        id_venta_tienda,
        id_producto,
        cantidad,
        precio_unitario,
        subtotal
    )
VALUES
    (
        p_id_venta,
        p_id_producto,
        p_cantidad,
        v_precio,
        v_precio * p_cantidad
    );

UPDATE
    inventario_tienda
SET
    stock = stock - p_cantidad
WHERE
    id_producto = p_id_producto
    AND stock >= p_cantidad;

IF ROW_COUNT() = 0 THEN SIGNAL SQLSTATE '45000'
SET
    MESSAGE_TEXT = 'Stock insuficiente';

END IF;

UPDATE
    venta_tienda
SET
    total_factura = (
        SELECT
            SUM(subtotal)
        FROM
            detalle_venta_tienda
        WHERE
            id_venta_tienda = p_id_venta
    )
WHERE
    id_venta_tienda = p_id_venta;

END $$
DELIMITER ;

DROP PROCEDURE IF EXISTS sp_InsertarBitacoraPago;

DELIMITER $$
CREATE PROCEDURE sp_InsertarBitacoraPago(
    IN p_id_usuario INT,
    IN p_id_venta INT,
    IN p_accion VARCHAR(100),
    IN p_estado_anterior VARCHAR(50),
    IN p_estado_nuevo VARCHAR(50),
    IN p_metodo_pago VARCHAR(50),
    IN p_total DECIMAL(10, 2),
    IN p_descripcion TEXT
) BEGIN DECLARE v_usuario VARCHAR(100);

SELECT
    nombre INTO v_usuario
FROM
    usuario
WHERE
    id_usuario = p_id_usuario;

INSERT INTO
    bitacora (
        usuario,
        accion,
        tabla_afectada,
        registro_id,
        detalles,
        ip,
        fecha_hora
    )
VALUES
    (
        v_usuario,
        p_accion,
        'venta_tienda',
        p_id_venta,
        CONCAT(
            'Estado anterior: ',
            p_estado_anterior,
            ' | Estado nuevo: ',
            p_estado_nuevo,
            ' | Método de pago: ',
            p_metodo_pago,
            ' | Total: ₡',
            FORMAT(p_total, 2),
            ' | ',
            p_descripcion
        ),
        '127.0.0.1',
        NOW()
    );

END $$
DELIMITER ;

DROP PROCEDURE IF EXISTS sp_MisComprasTienda;
DELIMITER $$

CREATE PROCEDURE sp_MisComprasTienda(IN p_id_usuario INT)
BEGIN

    SELECT
        vt.id_venta_tienda,
        vt.fecha_emision,
        vt.metodo_pago,
        vt.tipo_entrega,
        vt.total_factura,
        vt.estado,

        de.nombre_recibe,
        de.provincia,
        de.canton,
        de.distrito,
        de.direccion,
        s.nombre_sucursal,
        s.provincia AS sucursal_provincia,
        s.canton AS sucursal_canton,
        s.distrito AS sucursal_distrito,
        s.direccion AS sucursal_direccion,
        p.nombre_producto,
        p.tipo_producto,
        it.foto,
        dvt.cantidad,
        dvt.precio_unitario,
        dvt.subtotal

    FROM venta_tienda vt
        INNER JOIN detalle_venta_tienda dvt
            ON vt.id_venta_tienda = dvt.id_venta_tienda
        INNER JOIN producto p
            ON dvt.id_producto = p.id_producto
        INNER JOIN inventario_tienda it
            ON p.id_producto = it.id_producto

        LEFT JOIN direccion_entrega de
            ON vt.id_direccion = de.id_direccion

        LEFT JOIN sucursal s
            ON vt.id_sucursal = s.id_sucursal

    WHERE vt.id_usuario = p_id_usuario

    ORDER BY
        vt.fecha_emision DESC,
        vt.id_venta_tienda DESC;

END$$

DELIMITER ;

DROP PROCEDURE IF EXISTS sp_MisFacturasTienda;

DELIMITER $$
CREATE PROCEDURE sp_MisFacturasTienda(IN p_id_usuario INT) BEGIN
SELECT
    id_venta_tienda,
    fecha_emision,
    metodo_pago,
    total_factura,
    estado
FROM
    venta_tienda
WHERE
    id_usuario = p_id_usuario
ORDER BY
    fecha_emision DESC;

END $$
DELIMITER ;

DROP PROCEDURE IF EXISTS sp_DetalleCompraTienda;

DELIMITER $$
CREATE PROCEDURE sp_DetalleCompraTienda(IN p_id_venta INT) BEGIN
SELECT
    vt.id_venta_tienda,
    vt.fecha_emision,
    vt.estado,
    p.nombre_producto,
    i.foto,
    d.cantidad,
    d.precio_unitario,
    d.subtotal
FROM
    venta_tienda vt
    INNER JOIN detalle_venta_tienda d ON vt.id_venta_tienda = d.id_venta_tienda
    INNER JOIN producto p ON d.id_producto = p.id_producto
    INNER JOIN inventario_tienda i ON p.id_producto = i.id_producto
WHERE
    vt.id_venta_tienda = p_id_venta
ORDER BY
    vt.fecha_emision DESC;

END $$
DELIMITER ;
CREATE TABLE pago_tarjeta (
    id_pago_tarjeta INT AUTO_INCREMENT PRIMARY KEY,
    id_tarjeta INT NOT NULL,
    id_venta_tienda INT NOT NULL,
    monto DECIMAL(10,2) NOT NULL,
    fecha_pago DATETIME DEFAULT CURRENT_TIMESTAMP,
    estado VARCHAR(20) NOT NULL DEFAULT 'Aprobado',

    FOREIGN KEY (id_tarjeta)
        REFERENCES tarjeta_cliente(id_tarjeta)
        ON DELETE RESTRICT,

    FOREIGN KEY (id_venta_tienda)
        REFERENCES venta_tienda(id_venta_tienda)
        ON DELETE CASCADE,

    CONSTRAINT chk_estado_pago_tarjeta
    CHECK (
        estado IN (
            'Aprobado',
            'Rechazado',
            'Pendiente'
        )
    )
);

INSERT INTO usuario (nombre, correo, telefono, fecha_nacimiento) VALUES
('visitante2', 'visitante2@example.com', '0000-0000', '2000-01-01');

INSERT INTO usuarios_rol (id_usuarios_rol, id_usuario, id_rol, usuario, contrasena) VALUES
(6, 6, 4, 'visitante2', '$2y$10$FUoDQCQ2d40w9ctuC1AbV.XeyQQub/WwbDV/SyDCaqJxFhwGBxZhy');
select * from usuario;

 select * from tarjeta_banco;
 
 
 INSERT INTO tarea_cuidador
    (nombre_tarea, descripcion, estado)
VALUES
    (
        'Alimentación',
        'Proporcionar al animal la alimentación correspondiente según su dieta establecida.',
        'Activa'
    ),
    (
        'Limpieza del recinto',
        'Realizar la limpieza y desinfección del recinto donde se encuentra el animal.',
        'Activa'
    ),
    (
        'Revisión del animal',
        'Realizar una revisión general del estado físico y comportamiento del animal.',
        'Activa'
    ),
    (
        'Control de agua',
        'Verificar que el animal tenga agua limpia y suficiente durante el día.',
        'Activa'
    ),
    (
        'Revisión del recinto',
        'Comprobar que el recinto se encuentre en buenas condiciones de seguridad y mantenimiento.',
        'Activa'
    ),
    (
        'Enriquecimiento ambiental',
        'Preparar y supervisar actividades de enriquecimiento para estimular el comportamiento natural del animal.',
        'Activa'
    ),
    (
        'Limpieza de comederos',
        'Limpiar y desinfectar los recipientes utilizados para proporcionar alimento al animal.',
        'Activa'
    ),
    (
        'Control de comportamiento',
        'Observar y registrar cambios relevantes en el comportamiento habitual del animal.',
        'Activa'
    ),
    (
        'Control de peso',
        'Registrar periódicamente el peso del animal y reportar cambios significativos.',
        'Activa'
    ),
    (
        'Apoyo en revisión veterinaria',
        'Brindar apoyo durante las revisiones veterinarias programadas del animal.',
        'Activa'
    );
    
    
    -- =========================================================================
-- CARGA ADICIONAL DE DATOS
-- Estos registros utilizan valores nuevos para no repetir los existentes.
-- =========================================================================


-- =========================================================================
-- 1. USUARIOS ADICIONALES
-- =========================================================================

INSERT INTO usuario
    (nombre, correo, telefono, fecha_nacimiento)
SELECT
    'Administradora Demo',
    'ana.rodriguez@example.com',
    '0000-0000',
    '1993-06-15'
WHERE NOT EXISTS (
    SELECT 1 FROM usuario
    WHERE correo = 'ana.rodriguez@example.com'
);

INSERT INTO usuario
    (nombre, correo, telefono, fecha_nacimiento)
SELECT
    'Empleado Demo',
    'carlos.hernandez@example.com',
    '0000-0000',
    '1988-09-20'
WHERE NOT EXISTS (
    SELECT 1 FROM usuario
    WHERE correo = 'carlos.hernandez@example.com'
);

INSERT INTO usuario
    (nombre, correo, telefono, fecha_nacimiento)
SELECT
    'Veterinaria Demo',
    'maria.gonzalez@example.com',
    '0000-0000',
    '1995-01-12'
WHERE NOT EXISTS (
    SELECT 1 FROM usuario
    WHERE correo = 'maria.gonzalez@example.com'
);

INSERT INTO usuario
    (nombre, correo, telefono, fecha_nacimiento)
SELECT
    'Cliente Demo Tres',
    'daniel.vargas@example.com',
    '0000-0000',
    '1991-11-08'
WHERE NOT EXISTS (
    SELECT 1 FROM usuario
    WHERE correo = 'daniel.vargas@example.com'
);

INSERT INTO usuario
    (nombre, correo, telefono, fecha_nacimiento)
SELECT
    'Cliente Demo Cuatro',
    'sofia.morales@example.com',
    '0000-0000',
    '1999-04-25'
WHERE NOT EXISTS (
    SELECT 1 FROM usuario
    WHERE correo = 'sofia.morales@example.com'
);


-- =========================================================================
-- 2. USUARIOS_ROL ADICIONALES
-- =========================================================================

INSERT INTO usuarios_rol
    (id_usuario, id_rol, usuario, contrasena)
SELECT
    u.id_usuario,
    1,
    'anrodriguez',
    '$2y$10$FUoDQCQ2d40w9ctuC1AbV.XeyQQub/WwbDV/SyDCaqJxFhwGBxZhy'
FROM usuario u
WHERE u.correo = 'ana.rodriguez@example.com'
AND NOT EXISTS (
    SELECT 1
    FROM usuarios_rol ur
    WHERE ur.usuario = 'anrodriguez'
);


INSERT INTO usuarios_rol
    (id_usuario, id_rol, usuario, contrasena)
SELECT
    u.id_usuario,
    2,
    'chernandez',
    '$2y$10$FUoDQCQ2d40w9ctuC1AbV.XeyQQub/WwbDV/SyDCaqJxFhwGBxZhy'
FROM usuario u
WHERE u.correo = 'carlos.hernandez@example.com'
AND NOT EXISTS (
    SELECT 1
    FROM usuarios_rol ur
    WHERE ur.usuario = 'chernandez'
);


INSERT INTO usuarios_rol
    (id_usuario, id_rol, usuario, contrasena)
SELECT
    u.id_usuario,
    3,
    'mgonzalez',
    '$2y$10$FUoDQCQ2d40w9ctuC1AbV.XeyQQub/WwbDV/SyDCaqJxFhwGBxZhy'
FROM usuario u
WHERE u.correo = 'maria.gonzalez@example.com'
AND NOT EXISTS (
    SELECT 1
    FROM usuarios_rol ur
    WHERE ur.usuario = 'mgonzalez'
);


INSERT INTO usuarios_rol
    (id_usuario, id_rol, usuario, contrasena)
SELECT
    u.id_usuario,
    4,
    'dvargas',
    '$2y$10$FUoDQCQ2d40w9ctuC1AbV.XeyQQub/WwbDV/SyDCaqJxFhwGBxZhy'
FROM usuario u
WHERE u.correo = 'daniel.vargas@example.com'
AND NOT EXISTS (
    SELECT 1
    FROM usuarios_rol ur
    WHERE ur.usuario = 'dvargas'
);


INSERT INTO usuarios_rol
    (id_usuario, id_rol, usuario, contrasena)
SELECT
    u.id_usuario,
    4,
    'smorales',
    '$2y$10$FUoDQCQ2d40w9ctuC1AbV.XeyQQub/WwbDV/SyDCaqJxFhwGBxZhy'
FROM usuario u
WHERE u.correo = 'sofia.morales@example.com'
AND NOT EXISTS (
    SELECT 1
    FROM usuarios_rol ur
    WHERE ur.usuario = 'smorales'
);


-- =========================================================================
-- 3. SEGURIDAD DE CUENTAS
-- =========================================================================

INSERT INTO seguridad_cuenta (id_usuarios_rol)
SELECT ur.id_usuarios_rol
FROM usuarios_rol ur
WHERE ur.usuario IN (
    'anrodriguez',
    'chernandez',
    'mgonzalez',
    'dvargas',
    'smorales'
)
AND NOT EXISTS (
    SELECT 1
    FROM seguridad_cuenta sc
    WHERE sc.id_usuarios_rol = ur.id_usuarios_rol
);


-- =========================================================================
-- 4. ANIMALES ADICIONALES
-- =========================================================================

INSERT INTO animal
    (codigo_animal, nombre_animal, fecha_nacimiento, fecha_entrada, peso, altura, foto)
SELECT
    'ANI-011',
    'Jaguar',
    '2019-03-18',
    '2022-04-10',
    85.50,
    0.75,
    NULL
WHERE NOT EXISTS (
    SELECT 1 FROM animal
    WHERE codigo_animal = 'ANI-011'
);

INSERT INTO animal
    (codigo_animal, nombre_animal, fecha_nacimiento, fecha_entrada, peso, altura, foto)
SELECT
    'ANI-012',
    'Guacamaya Roja',
    '2021-02-10',
    '2023-01-15',
    1.10,
    0.90,
    NULL
WHERE NOT EXISTS (
    SELECT 1 FROM animal
    WHERE codigo_animal = 'ANI-012'
);

INSERT INTO animal
    (codigo_animal, nombre_animal, fecha_nacimiento, fecha_entrada, peso, altura, foto)
SELECT
    'ANI-013',
    'Hipopótamo',
    '2016-07-22',
    '2020-09-05',
    1450.00,
    1.60,
    NULL
WHERE NOT EXISTS (
    SELECT 1 FROM animal
    WHERE codigo_animal = 'ANI-013'
);

INSERT INTO animal
    (codigo_animal, nombre_animal, fecha_nacimiento, fecha_entrada, peso, altura, foto)
SELECT
    'ANI-014',
    'Flamenco Rosado',
    '2022-01-05',
    '2024-02-12',
    2.80,
    1.10,
    NULL
WHERE NOT EXISTS (
    SELECT 1 FROM animal
    WHERE codigo_animal = 'ANI-014'
);

INSERT INTO animal
    (codigo_animal, nombre_animal, fecha_nacimiento, fecha_entrada, peso, altura, foto)
SELECT
    'ANI-015',
    'Panda Rojo',
    '2020-10-14',
    '2023-06-18',
    5.80,
    0.60,
    NULL
WHERE NOT EXISTS (
    SELECT 1 FROM animal
    WHERE codigo_animal = 'ANI-015'
);

INSERT INTO animal
    (codigo_animal, nombre_animal, fecha_nacimiento, fecha_entrada, peso, altura, foto)
SELECT
    'ANI-016',
    'Cocodrilo Americano',
    '2015-05-20',
    '2019-08-11',
    180.00,
    2.70,
    NULL
WHERE NOT EXISTS (
    SELECT 1 FROM animal
    WHERE codigo_animal = 'ANI-016'
);

INSERT INTO animal
    (codigo_animal, nombre_animal, fecha_nacimiento, fecha_entrada, peso, altura, foto)
SELECT
    'ANI-017',
    'Suricata',
    '2022-11-03',
    '2024-03-20',
    0.90,
    0.35,
    NULL
WHERE NOT EXISTS (
    SELECT 1 FROM animal
    WHERE codigo_animal = 'ANI-017'
);

INSERT INTO animal
    (codigo_animal, nombre_animal, fecha_nacimiento, fecha_entrada, peso, altura, foto)
SELECT
    'ANI-018',
    'Rinoceronte Blanco',
    '2014-04-17',
    '2018-07-22',
    2100.00,
    1.80,
    NULL
WHERE NOT EXISTS (
    SELECT 1 FROM animal
    WHERE codigo_animal = 'ANI-018'
);


-- =========================================================================
-- 5. INVENTARIO DEL ZOOLÓGICO ADICIONAL
-- =========================================================================

INSERT INTO inventariozoo
    (nombre_producto, unidad_medida, cantidad, fecha_caducidad, fecha_ingreso)
SELECT
    'Pera',
    'Kilogramos',
    35.00,
    DATE_ADD(CURDATE(), INTERVAL 30 DAY),
    CURDATE()
WHERE NOT EXISTS (
    SELECT 1 FROM inventariozoo
    WHERE nombre_producto = 'Pera'
);

INSERT INTO inventariozoo
    (nombre_producto, unidad_medida, cantidad, fecha_caducidad, fecha_ingreso)
SELECT
    'Papaya',
    'Kilogramos',
    40.00,
    DATE_ADD(CURDATE(), INTERVAL 12 DAY),
    CURDATE()
WHERE NOT EXISTS (
    SELECT 1 FROM inventariozoo
    WHERE nombre_producto = 'Papaya'
);

INSERT INTO inventariozoo
    (nombre_producto, unidad_medida, cantidad, fecha_caducidad, fecha_ingreso)
SELECT
    'Pollo',
    'Kilogramos',
    70.00,
    DATE_ADD(CURDATE(), INTERVAL 5 DAY),
    CURDATE()
WHERE NOT EXISTS (
    SELECT 1 FROM inventariozoo
    WHERE nombre_producto = 'Pollo'
);

INSERT INTO inventariozoo
    (nombre_producto, unidad_medida, cantidad, fecha_caducidad, fecha_ingreso)
SELECT
    'Zanahoria',
    'Kilogramos',
    60.00,
    DATE_ADD(CURDATE(), INTERVAL 20 DAY),
    CURDATE()
WHERE NOT EXISTS (
    SELECT 1 FROM inventariozoo
    WHERE nombre_producto = 'Zanahoria'
);

INSERT INTO inventariozoo
    (nombre_producto, unidad_medida, cantidad, fecha_caducidad, fecha_ingreso)
SELECT
    'Papa',
    'Kilogramos',
    50.00,
    DATE_ADD(CURDATE(), INTERVAL 25 DAY),
    CURDATE()
WHERE NOT EXISTS (
    SELECT 1 FROM inventariozoo
    WHERE nombre_producto = 'Papa'
);

INSERT INTO inventariozoo
    (nombre_producto, unidad_medida, cantidad, fecha_caducidad, fecha_ingreso)
SELECT
    'Uvas',
    'Kilogramos',
    30.00,
    DATE_ADD(CURDATE(), INTERVAL 8 DAY),
    CURDATE()
WHERE NOT EXISTS (
    SELECT 1 FROM inventariozoo
    WHERE nombre_producto = 'Uvas'
);

INSERT INTO inventariozoo
    (nombre_producto, unidad_medida, cantidad, fecha_caducidad, fecha_ingreso)
SELECT
    'Alimento balanceado felinos',
    'Kilogramos',
    100.00,
    DATE_ADD(CURDATE(), INTERVAL 180 DAY),
    CURDATE()
WHERE NOT EXISTS (
    SELECT 1 FROM inventariozoo
    WHERE nombre_producto = 'Alimento balanceado felinos'
);

INSERT INTO inventariozoo
    (nombre_producto, unidad_medida, cantidad, fecha_caducidad, fecha_ingreso)
SELECT
    'Alimento balanceado aves',
    'Kilogramos',
    75.00,
    DATE_ADD(CURDATE(), INTERVAL 160 DAY),
    CURDATE()
WHERE NOT EXISTS (
    SELECT 1 FROM inventariozoo
    WHERE nombre_producto = 'Alimento balanceado aves'
);

INSERT INTO inventariozoo
    (nombre_producto, unidad_medida, cantidad, fecha_caducidad, fecha_ingreso)
SELECT
    'Gasas esterilizadas',
    'Cajas',
    50.00,
    DATE_ADD(CURDATE(), INTERVAL 400 DAY),
    CURDATE()
WHERE NOT EXISTS (
    SELECT 1 FROM inventariozoo
    WHERE nombre_producto = 'Gasas esterilizadas'
);

INSERT INTO inventariozoo
    (nombre_producto, unidad_medida, cantidad, fecha_caducidad, fecha_ingreso)
SELECT
    'Jeringas veterinarias',
    'Cajas',
    30.00,
    DATE_ADD(CURDATE(), INTERVAL 500 DAY),
    CURDATE()
WHERE NOT EXISTS (
    SELECT 1 FROM inventariozoo
    WHERE nombre_producto = 'Jeringas veterinarias'
);


-- =========================================================================
-- 6. ENFERMEDADES ADICIONALES
-- =========================================================================

INSERT INTO enfermedad (nombre, tipo, descripcion)
SELECT
    'Infección ocular',
    'Enfermedad',
    'Inflamación e infección de la zona ocular.'
WHERE NOT EXISTS (
    SELECT 1 FROM enfermedad
    WHERE nombre = 'Infección ocular'
);

INSERT INTO enfermedad (nombre, tipo, descripcion)
SELECT
    'Problemas digestivos',
    'Enfermedad',
    'Alteraciones en el funcionamiento del sistema digestivo.'
WHERE NOT EXISTS (
    SELECT 1 FROM enfermedad
    WHERE nombre = 'Problemas digestivos'
);

INSERT INTO enfermedad (nombre, tipo, descripcion)
SELECT
    'Deshidratación',
    'Enfermedad',
    'Pérdida excesiva de líquidos que requiere hidratación y monitoreo.'
WHERE NOT EXISTS (
    SELECT 1 FROM enfermedad
    WHERE nombre = 'Deshidratación'
);

INSERT INTO enfermedad (nombre, tipo, descripcion)
SELECT
    'Alergia al polen',
    'Alergia',
    'Reacción alérgica provocada por exposición al polen.'
WHERE NOT EXISTS (
    SELECT 1 FROM enfermedad
    WHERE nombre = 'Alergia al polen'
);

INSERT INTO enfermedad (nombre, tipo, descripcion)
SELECT
    'Alergia a frutos secos',
    'Alergia',
    'Reacción adversa a determinados frutos secos.'
WHERE NOT EXISTS (
    SELECT 1 FROM enfermedad
    WHERE nombre = 'Alergia a frutos secos'
);

INSERT INTO enfermedad (nombre, tipo, descripcion)
SELECT
    'Artritis',
    'Enfermedad',
    'Inflamación de las articulaciones que puede afectar la movilidad.'
WHERE NOT EXISTS (
    SELECT 1 FROM enfermedad
    WHERE nombre = 'Artritis'
);


-- =========================================================================
-- 7. HISTORIAL MÉDICO ADICIONAL
-- =========================================================================

INSERT INTO historialmedicoanimal
    (id_animal, id_enfermedad, fecha_diagnostico, fecha_recuperacion, tratamiento, observaciones)
SELECT
    a.id_animal,
    e.id_enfermedad,
    '2026-02-15',
    '2026-02-25',
    'Limpieza ocular y aplicación de gotas veterinarias.',
    'El animal presentó recuperación favorable.'
FROM animal a
JOIN enfermedad e
    ON e.nombre = 'Infección ocular'
WHERE a.codigo_animal = 'ANI-011'
AND NOT EXISTS (
    SELECT 1
    FROM historialmedicoanimal h
    WHERE h.id_animal = a.id_animal
      AND h.id_enfermedad = e.id_enfermedad
      AND h.fecha_diagnostico = '2026-02-15'
);


INSERT INTO historialmedicoanimal
    (id_animal, id_enfermedad, fecha_diagnostico, fecha_recuperacion, tratamiento, observaciones)
SELECT
    a.id_animal,
    e.id_enfermedad,
    '2026-04-03',
    '2026-04-12',
    'Dieta blanda y control de hidratación.',
    'Sin complicaciones posteriores.'
FROM animal a
JOIN enfermedad e
    ON e.nombre = 'Problemas digestivos'
WHERE a.codigo_animal = 'ANI-013'
AND NOT EXISTS (
    SELECT 1
    FROM historialmedicoanimal h
    WHERE h.id_animal = a.id_animal
      AND h.id_enfermedad = e.id_enfermedad
      AND h.fecha_diagnostico = '2026-04-03'
);


INSERT INTO historialmedicoanimal
    (id_animal, id_enfermedad, fecha_diagnostico, fecha_recuperacion, tratamiento, observaciones)
SELECT
    a.id_animal,
    e.id_enfermedad,
    '2026-06-10',
    NULL,
    'Antiinflamatorio y reducción temporal de actividad física.',
    'Se mantiene bajo observación veterinaria.'
FROM animal a
JOIN enfermedad e
    ON e.nombre = 'Artritis'
WHERE a.codigo_animal = 'ANI-018'
AND NOT EXISTS (
    SELECT 1
    FROM historialmedicoanimal h
    WHERE h.id_animal = a.id_animal
      AND h.id_enfermedad = e.id_enfermedad
      AND h.fecha_diagnostico = '2026-06-10'
);


INSERT INTO historialmedicoanimal
    (id_animal, id_enfermedad, fecha_diagnostico, fecha_recuperacion, tratamiento, observaciones)
SELECT
    a.id_animal,
    e.id_enfermedad,
    '2026-07-20',
    NULL,
    'Antihistamínico y control de exposición ambiental.',
    'Se recomienda mantener el recinto libre de polvo y polen.'
FROM animal a
JOIN enfermedad e
    ON e.nombre = 'Alergia al polen'
WHERE a.codigo_animal = 'ANI-014'
AND NOT EXISTS (
    SELECT 1
    FROM historialmedicoanimal h
    WHERE h.id_animal = a.id_animal
      AND h.id_enfermedad = e.id_enfermedad
      AND h.fecha_diagnostico = '2026-07-20'
);


-- =========================================================================
-- 8. HÁBITATS ADICIONALES
-- =========================================================================

INSERT INTO habitat
    (nombre, tipo_habitat, zona, area_m2, capacidad_animales, descripcion, estado)
SELECT
    'Bosque Tropical',
    'Bosque húmedo',
    'Zona Este',
    1200.00,
    12,
    'Espacio con vegetación abundante y áreas de sombra para especies tropicales.',
    'Activo'
WHERE NOT EXISTS (
    SELECT 1 FROM habitat
    WHERE nombre = 'Bosque Tropical'
);

INSERT INTO habitat
    (nombre, tipo_habitat, zona, area_m2, capacidad_animales, descripcion, estado)
SELECT
    'Zona de Grandes Felinos',
    'Sabana controlada',
    'Zona Oeste',
    1800.00,
    8,
    'Área amplia para felinos de gran tamaño con zonas de descanso y agua.',
    'Activo'
WHERE NOT EXISTS (
    SELECT 1 FROM habitat
    WHERE nombre = 'Zona de Grandes Felinos'
);

INSERT INTO habitat
    (nombre, tipo_habitat, zona, area_m2, capacidad_animales, descripcion, estado)
SELECT
    'Laguna de Aves',
    'Humedal',
    'Zona Central',
    950.00,
    25,
    'Laguna artificial destinada a aves acuáticas y especies migratorias.',
    'Activo'
WHERE NOT EXISTS (
    SELECT 1 FROM habitat
    WHERE nombre = 'Laguna de Aves'
);

INSERT INTO habitat
    (nombre, tipo_habitat, zona, area_m2, capacidad_animales, descripcion, estado)
SELECT
    'Reserva de Herbívoros',
    'Pradera',
    'Zona Norte',
    2200.00,
    20,
    'Extensa área de pastoreo para herbívoros de diferentes especies.',
    'Activo'
WHERE NOT EXISTS (
    SELECT 1 FROM habitat
    WHERE nombre = 'Reserva de Herbívoros'
);


-- =========================================================================
-- 9. TARIFAS ADICIONALES
-- =========================================================================

INSERT INTO tarifa_entrada
    (categoria, precio, estado)
SELECT
    'Niños menores de 5 años',
    2500.00,
    'Activa'
WHERE NOT EXISTS (
    SELECT 1 FROM tarifa_entrada
    WHERE categoria = 'Niños menores de 5 años'
);

INSERT INTO tarifa_entrada
    (categoria, precio, estado)
SELECT
    'Adulto Extranjero',
    10000.00,
    'Activa'
WHERE NOT EXISTS (
    SELECT 1 FROM tarifa_entrada
    WHERE categoria = 'Adulto Extranjero'
);

INSERT INTO tarifa_entrada
    (categoria, precio, estado)
SELECT
    'Estudiante Universitario',
    4000.00,
    'Activa'
WHERE NOT EXISTS (
    SELECT 1 FROM tarifa_entrada
    WHERE categoria = 'Estudiante Universitario'
);

INSERT INTO tarifa_entrada
    (categoria, precio, estado)
SELECT
    'Grupo Escolar',
    3000.00,
    'Activa'
WHERE NOT EXISTS (
    SELECT 1 FROM tarifa_entrada
    WHERE categoria = 'Grupo Escolar'
);


-- =========================================================================
-- 10. DISPONIBILIDAD DE DÍAS
-- =========================================================================

INSERT INTO disponibilidad_dia_zoo
    (fecha, hora_apertura, hora_cierre, capacidad_total, capacidad_disponible, estado, id_usuario_admin)
SELECT
    '2026-08-21',
    '08:00:00',
    '16:00:00',
    500,
    500,
    'Disponible',
    1
WHERE NOT EXISTS (
    SELECT 1 FROM disponibilidad_dia_zoo
    WHERE fecha = '2026-08-21'
);

INSERT INTO disponibilidad_dia_zoo
    (fecha, hora_apertura, hora_cierre, capacidad_total, capacidad_disponible, estado, id_usuario_admin)
SELECT
    '2026-08-22',
    '08:00:00',
    '17:00:00',
    500,
    500,
    'Disponible',
    1
WHERE NOT EXISTS (
    SELECT 1 FROM disponibilidad_dia_zoo
    WHERE fecha = '2026-08-22'
);

INSERT INTO disponibilidad_dia_zoo
    (fecha, hora_apertura, hora_cierre, capacidad_total, capacidad_disponible, estado, id_usuario_admin)
SELECT
    '2026-08-23',
    '08:00:00',
    '17:00:00',
    600,
    600,
    'Disponible',
    1
WHERE NOT EXISTS (
    SELECT 1 FROM disponibilidad_dia_zoo
    WHERE fecha = '2026-08-23'
);

INSERT INTO disponibilidad_dia_zoo
    (fecha, hora_apertura, hora_cierre, capacidad_total, capacidad_disponible, estado, id_usuario_admin)
SELECT
    '2026-08-24',
    '08:00:00',
    '16:00:00',
    450,
    450,
    'Disponible',
    1
WHERE NOT EXISTS (
    SELECT 1 FROM disponibilidad_dia_zoo
    WHERE fecha = '2026-08-24'
);

INSERT INTO disponibilidad_dia_zoo
    (fecha, hora_apertura, hora_cierre, capacidad_total, capacidad_disponible, estado, id_usuario_admin)
SELECT
    '2026-08-25',
    '08:00:00',
    '16:00:00',
    450,
    450,
    'Disponible',
    1
WHERE NOT EXISTS (
    SELECT 1 FROM disponibilidad_dia_zoo
    WHERE fecha = '2026-08-25'
);

-- =========================================================================
-- 12. PRODUCTOS DE TIENDA ADICIONALES
-- =========================================================================

INSERT INTO Producto
    (nombre_producto, tipo_producto, estado)
SELECT
    'Taza Tucán',
    'Souvenir',
    'Activo'
WHERE NOT EXISTS (
    SELECT 1 FROM Producto
    WHERE nombre_producto = 'Taza Tucán'
);

INSERT INTO Producto
    (nombre_producto, tipo_producto, estado)
SELECT
    'Pelota Animal',
    'Juguete',
    'Activo'
WHERE NOT EXISTS (
    SELECT 1 FROM Producto
    WHERE nombre_producto = 'Pelota Animal'
);

INSERT INTO Producto
    (nombre_producto, tipo_producto, estado)
SELECT
    'Cuaderno EcoFauna',
    'Papelería',
    'Activo'
WHERE NOT EXISTS (
    SELECT 1 FROM Producto
    WHERE nombre_producto = 'Cuaderno EcoFauna'
);

INSERT INTO Producto
    (nombre_producto, tipo_producto, estado)
SELECT
    'Botella Reutilizable',
    'Accesorio',
    'Activo'
WHERE NOT EXISTS (
    SELECT 1 FROM Producto
    WHERE nombre_producto = 'Botella Reutilizable'
);

INSERT INTO Producto
    (nombre_producto, tipo_producto, estado)
SELECT
    'Peluchito de Tucán',
    'Souvenir',
    'Activo'
WHERE NOT EXISTS (
    SELECT 1 FROM Producto
    WHERE nombre_producto = 'Peluchito de Tucán'
);

INSERT INTO Producto
    (nombre_producto, tipo_producto, estado)
SELECT
    'Pulsera EcoFauna',
    'Souvenir',
    'Activo'
WHERE NOT EXISTS (
    SELECT 1 FROM Producto
    WHERE nombre_producto = 'Pulsera EcoFauna'
);


-- =========================================================================
-- 13. INVENTARIO DE TIENDA ADICIONAL
-- =========================================================================

INSERT INTO inventario_tienda
    (id_producto, stock, precio_compra, precio_venta, fecha_ingreso)
SELECT
    p.id_producto,
    35,
    2500.00,
    4500.00,
    CURDATE()
FROM Producto p
WHERE p.nombre_producto = 'Taza Tucán'
AND NOT EXISTS (
    SELECT 1
    FROM inventario_tienda i
    WHERE i.id_producto = p.id_producto
);

INSERT INTO inventario_tienda
    (id_producto, stock, precio_compra, precio_venta, fecha_ingreso)
SELECT
    p.id_producto,
    50,
    1500.00,
    3000.00,
    CURDATE()
FROM Producto p
WHERE p.nombre_producto = 'Pelota Animal'
AND NOT EXISTS (
    SELECT 1
    FROM inventario_tienda i
    WHERE i.id_producto = p.id_producto
);

INSERT INTO inventario_tienda
    (id_producto, stock, precio_compra, precio_venta, fecha_ingreso)
SELECT
    p.id_producto,
    60,
    1800.00,
    3500.00,
    CURDATE()
FROM Producto p
WHERE p.nombre_producto = 'Cuaderno EcoFauna'
AND NOT EXISTS (
    SELECT 1
    FROM inventario_tienda i
    WHERE i.id_producto = p.id_producto
);

INSERT INTO inventario_tienda
    (id_producto, stock, precio_compra, precio_venta, fecha_ingreso)
SELECT
    p.id_producto,
    45,
    3000.00,
    5500.00,
    CURDATE()
FROM Producto p
WHERE p.nombre_producto = 'Botella Reutilizable'
AND NOT EXISTS (
    SELECT 1
    FROM inventario_tienda i
    WHERE i.id_producto = p.id_producto
);

INSERT INTO inventario_tienda
    (id_producto, stock, precio_compra, precio_venta, fecha_ingreso)
SELECT
    p.id_producto,
    25,
    3500.00,
    6500.00,
    CURDATE()
FROM Producto p
WHERE p.nombre_producto = 'Peluchito de Tucán'
AND NOT EXISTS (
    SELECT 1
    FROM inventario_tienda i
    WHERE i.id_producto = p.id_producto
);

INSERT INTO inventario_tienda
    (id_producto, stock, precio_compra, precio_venta, fecha_ingreso)
SELECT
    p.id_producto,
    100,
    500.00,
    1200.00,
    CURDATE()
FROM Producto p
WHERE p.nombre_producto = 'Pulsera EcoFauna'
AND NOT EXISTS (
    SELECT 1
    FROM inventario_tienda i
    WHERE i.id_producto = p.id_producto
);


-- =========================================================================
-- 14. SUCURSALES ADICIONALES
-- =========================================================================

INSERT INTO sucursal
    (nombre_sucursal, provincia, canton, distrito, direccion, telefono, horario, estado)
SELECT
    'EcoFauna Alajuela',
    'Alajuela',
    'Alajuela',
    'Alajuela',
    '200 metros oeste del Parque Central',
    '0000-0000',
    'Lunes a Domingo 8:00 a.m. - 5:00 p.m.',
    'Activa'
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM sucursal
    WHERE nombre_sucursal = 'EcoFauna Alajuela'
);

INSERT INTO sucursal
    (nombre_sucursal, provincia, canton, distrito, direccion, telefono, horario, estado)
SELECT
    'EcoFauna Cartago Este',
    'Cartago',
    'Cartago',
    'San Nicolás',
    '300 metros este del centro comercial',
    '0000-0000',
    'Lunes a Sábado 8:00 a.m. - 5:00 p.m.',
    'Activa'
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM sucursal
    WHERE nombre_sucursal = 'EcoFauna Cartago Este'
);


-- =========================================================================
-- 15. TAREAS ADICIONALES DEL CUIDADOR
-- =========================================================================

INSERT INTO tarea_cuidador
    (nombre_tarea, descripcion, estado)
SELECT
    'Revisión de bebederos',
    'Comprobar que los bebederos tengan agua limpia y funcionen correctamente.',
    'Activa'
WHERE NOT EXISTS (
    SELECT 1 FROM tarea_cuidador
    WHERE nombre_tarea = 'Revisión de bebederos'
);

INSERT INTO tarea_cuidador
    (nombre_tarea, descripcion, estado)
SELECT
    'Limpieza de zonas de descanso',
    'Limpiar y acondicionar las áreas utilizadas por los animales para descansar.',
    'Activa'
WHERE NOT EXISTS (
    SELECT 1 FROM tarea_cuidador
    WHERE nombre_tarea = 'Limpieza de zonas de descanso'
);

INSERT INTO tarea_cuidador
    (nombre_tarea, descripcion, estado)
SELECT
    'Preparación de dieta',
    'Preparar las raciones alimenticias según las indicaciones establecidas.',
    'Activa'
WHERE NOT EXISTS (
    SELECT 1 FROM tarea_cuidador
    WHERE nombre_tarea = 'Preparación de dieta'
);

INSERT INTO tarea_cuidador
    (nombre_tarea, descripcion, estado)
SELECT
    'Registro de comportamiento',
    'Registrar cambios importantes en el comportamiento diario de los animales.',
    'Activa'
WHERE NOT EXISTS (
    SELECT 1 FROM tarea_cuidador
    WHERE nombre_tarea = 'Registro de comportamiento'
);

INSERT INTO tarea_cuidador
    (nombre_tarea, descripcion, estado)
SELECT
    'Revisión de seguridad',
    'Verificar puertas, cercas y mecanismos de seguridad del recinto.',
    'Activa'
WHERE NOT EXISTS (
    SELECT 1 FROM tarea_cuidador
    WHERE nombre_tarea = 'Revisión de seguridad'
);


-- =========================================================================
-- 16. CUIDADORES ADICIONALES
-- =========================================================================

INSERT INTO cuidador
    (id_usuario, fecha_contratacion, especialidad, estado)
SELECT
    u.id_usuario,
    '2024-01-15',
    'Manejo de aves',
    'Activo'
FROM usuario u
WHERE u.correo = 'maria.gonzalez@example.com'
AND NOT EXISTS (
    SELECT 1
    FROM cuidador c
    WHERE c.id_usuario = u.id_usuario
);

INSERT INTO cuidador
    (id_usuario, fecha_contratacion, especialidad, estado)
SELECT
    u.id_usuario,
    '2024-05-20',
    'Manejo de herbívoros',
    'Activo'
FROM usuario u
WHERE u.correo = 'ana.rodriguez@example.com'
AND NOT EXISTS (
    SELECT 1
    FROM cuidador c
    WHERE c.id_usuario = u.id_usuario
);


-- =========================================================================
-- 17. HORARIOS DE CUIDADORES
-- =========================================================================

INSERT INTO horario_cuidador
    (id_cuidador, dia_semana, hora_entrada, hora_salida, estado)
SELECT
    c.id_cuidador,
    'Miércoles',
    '07:00:00',
    '15:00:00',
    'activo'
FROM cuidador c
JOIN usuario u ON u.id_usuario = c.id_usuario
WHERE u.correo = 'maria.gonzalez@example.com'
AND NOT EXISTS (
    SELECT 1
    FROM horario_cuidador h
    WHERE h.id_cuidador = c.id_cuidador
      AND h.dia_semana = 'Miércoles'
);

INSERT INTO horario_cuidador
    (id_cuidador, dia_semana, hora_entrada, hora_salida, estado)
SELECT
    c.id_cuidador,
    'Jueves',
    '07:00:00',
    '15:00:00',
    'activo'
FROM cuidador c
JOIN usuario u ON u.id_usuario = c.id_usuario
WHERE u.correo = 'maria.gonzalez@example.com'
AND NOT EXISTS (
    SELECT 1
    FROM horario_cuidador h
    WHERE h.id_cuidador = c.id_cuidador
      AND h.dia_semana = 'Jueves'
);

INSERT INTO horario_cuidador
    (id_cuidador, dia_semana, hora_entrada, hora_salida, estado)
SELECT
    c.id_cuidador,
    'Viernes',
    '08:00:00',
    '16:00:00',
    'activo'
FROM cuidador c
JOIN usuario u ON u.id_usuario = c.id_usuario
WHERE u.correo = 'ana.rodriguez@example.com'
AND NOT EXISTS (
    SELECT 1
    FROM horario_cuidador h
    WHERE h.id_cuidador = c.id_cuidador
      AND h.dia_semana = 'Viernes'
);


-- =========================================================================
-- 18. BITÁCORA ADICIONAL
-- =========================================================================

INSERT INTO bitacora
    (usuario, accion, tabla_afectada, registro_id, detalles, ip)
VALUES
(
    'anrodriguez',
    'INSERT',
    'animal',
    (SELECT id_animal FROM animal WHERE codigo_animal = 'ANI-011'),
    'Se registró un nuevo animal en el zoológico.',
    '192.168.1.60'
),
(
    'chernandez',
    'INSERT',
    'historialmedicoanimal',
    1,
    'Se registró una nueva evaluación veterinaria.',
    '192.168.1.61'
),
(
    'mgonzalez',
    'INSERT',
    'alimentacion_animal',
    1,
    'Se registró una alimentación programada.',
    '192.168.1.62'
),
(
    'anrodriguez',
    'UPDATE',
    'inventariozoo',
    1,
    'Se actualizó la cantidad disponible de un producto.',
    '192.168.1.63'
),
(
    'dvargas',
    'INSERT',
    'venta_tienda',
    1,
    'Se registró una compra en la tienda.',
    '192.168.1.64'
);


-- =========================================================================
-- 19. ALIMENTACIÓN DE ANIMALES NUEVOS
-- =========================================================================

INSERT INTO alimentacion_animal
    (id_animal, id_inventarioZoo, hora, cantidad_recomendada, activo)
SELECT
    a.id_animal,
    i.id_inventarioZoo,
    '08:30:00',
    5.00,
    1
FROM animal a
JOIN inventariozoo i
    ON i.nombre_producto = 'Pollo'
WHERE a.codigo_animal = 'ANI-011'
AND NOT EXISTS (
    SELECT 1
    FROM alimentacion_animal aa
    WHERE aa.id_animal = a.id_animal
      AND aa.id_inventarioZoo = i.id_inventarioZoo
);


INSERT INTO alimentacion_animal
    (id_animal, id_inventarioZoo, hora, cantidad_recomendada, activo)
SELECT
    a.id_animal,
    i.id_inventarioZoo,
    '09:00:00',
    2.00,
    1
FROM animal a
JOIN inventariozoo i
    ON i.nombre_producto = 'Uvas'
WHERE a.codigo_animal = 'ANI-012'
AND NOT EXISTS (
    SELECT 1
    FROM alimentacion_animal aa
    WHERE aa.id_animal = a.id_animal
      AND aa.id_inventarioZoo = i.id_inventarioZoo
);


INSERT INTO alimentacion_animal
    (id_animal, id_inventarioZoo, hora, cantidad_recomendada, activo)
SELECT
    a.id_animal,
    i.id_inventarioZoo,
    '10:00:00',
    12.00,
    1
FROM animal a
JOIN inventariozoo i
    ON i.nombre_producto = 'Zanahoria'
WHERE a.codigo_animal = 'ANI-013'
AND NOT EXISTS (
    SELECT 1
    FROM alimentacion_animal aa
    WHERE aa.id_animal = a.id_animal
      AND aa.id_inventarioZoo = i.id_inventarioZoo
);


INSERT INTO alimentacion_animal
    (id_animal, id_inventarioZoo, hora, cantidad_recomendada, activo)
SELECT
    a.id_animal,
    i.id_inventarioZoo,
    '11:00:00',
    1.50,
    1
FROM animal a
JOIN inventariozoo i
    ON i.nombre_producto = 'Manzana'
WHERE a.codigo_animal = 'ANI-015'
AND NOT EXISTS (
    SELECT 1
    FROM alimentacion_animal aa
    WHERE aa.id_animal = a.id_animal
      AND aa.id_inventarioZoo = i.id_inventarioZoo
);


-- =========================================================================
-- 20. CUIDADOR - ANIMAL
-- =========================================================================

INSERT INTO cuidador_animal
    (id_cuidador, id_animal, fecha_asignacion, responsabilidad, estado)
SELECT
    c.id_cuidador,
    a.id_animal,
    CURDATE(),
    'Alimentación, limpieza y supervisión diaria.',
    'Activo'
FROM cuidador c
JOIN usuario u
    ON u.id_usuario = c.id_usuario
JOIN animal a
    ON a.codigo_animal = 'ANI-011'
WHERE u.correo = 'maria.gonzalez@example.com'
AND NOT EXISTS (
    SELECT 1
    FROM cuidador_animal ca
    WHERE ca.id_cuidador = c.id_cuidador
      AND ca.id_animal = a.id_animal
);


INSERT INTO cuidador_animal
    (id_cuidador, id_animal, fecha_asignacion, responsabilidad, estado)
SELECT
    c.id_cuidador,
    a.id_animal,
    CURDATE(),
    'Supervisión, alimentación y control del recinto.',
    'Activo'
FROM cuidador c
JOIN usuario u
    ON u.id_usuario = c.id_usuario
JOIN animal a
    ON a.codigo_animal = 'ANI-012'
WHERE u.correo = 'maria.gonzalez@example.com'
AND NOT EXISTS (
    SELECT 1
    FROM cuidador_animal ca
    WHERE ca.id_cuidador = c.id_cuidador
      AND ca.id_animal = a.id_animal
);


INSERT INTO cuidador_animal
    (id_cuidador, id_animal, fecha_asignacion, responsabilidad, estado)
SELECT
    c.id_cuidador,
    a.id_animal,
    CURDATE(),
    'Alimentación, monitoreo de peso y limpieza.',
    'Activo'
FROM cuidador c
JOIN usuario u
    ON u.id_usuario = c.id_usuario
JOIN animal a
    ON a.codigo_animal = 'ANI-013'
WHERE u.correo = 'ana.rodriguez@example.com'
AND NOT EXISTS (
    SELECT 1
    FROM cuidador_animal ca
    WHERE ca.id_cuidador = c.id_cuidador
      AND ca.id_animal = a.id_animal
);


-- =========================================================================
-- 21. TAREAS ASIGNADAS
-- =========================================================================

INSERT INTO cuidador_animal_tarea
    (id_cuidador_animal, id_tarea, frecuencia, hora_programada, observaciones, estado)
SELECT
    ca.id_cuidador_animal,
    t.id_tarea,
    'Diaria',
    '08:00:00',
    'Revisar el recinto antes de iniciar la actividad.',
    'Activa'
FROM cuidador_animal ca
JOIN animal a
    ON a.id_animal = ca.id_animal
JOIN tarea_cuidador t
    ON t.nombre_tarea = 'Limpieza de zonas de descanso'
WHERE a.codigo_animal = 'ANI-011'
AND NOT EXISTS (
    SELECT 1
    FROM cuidador_animal_tarea cat
    WHERE cat.id_cuidador_animal = ca.id_cuidador_animal
      AND cat.id_tarea = t.id_tarea
);


INSERT INTO cuidador_animal_tarea
    (id_cuidador_animal, id_tarea, frecuencia, hora_programada, observaciones, estado)
SELECT
    ca.id_cuidador_animal,
    t.id_tarea,
    'Diaria',
    '09:30:00',
    'Verificar que el animal consuma la ración completa.',
    'Activa'
FROM cuidador_animal ca
JOIN animal a
    ON a.id_animal = ca.id_animal
JOIN tarea_cuidador t
    ON t.nombre_tarea = 'Preparación de dieta'
WHERE a.codigo_animal = 'ANI-012'
AND NOT EXISTS (
    SELECT 1
    FROM cuidador_animal_tarea cat
    WHERE cat.id_cuidador_animal = ca.id_cuidador_animal
      AND cat.id_tarea = t.id_tarea
);


INSERT INTO cuidador_animal_tarea
    (id_cuidador_animal, id_tarea, frecuencia, hora_programada, observaciones, estado)
SELECT
    ca.id_cuidador_animal,
    t.id_tarea,
    'Diaria',
    '10:30:00',
    'Registrar cualquier cambio de comportamiento.',
    'Activa'
FROM cuidador_animal ca
JOIN animal a
    ON a.id_animal = ca.id_animal
JOIN tarea_cuidador t
    ON t.nombre_tarea = 'Registro de comportamiento'
WHERE a.codigo_animal = 'ANI-013'
AND NOT EXISTS (
    SELECT 1
    FROM cuidador_animal_tarea cat
    WHERE cat.id_cuidador_animal = ca.id_cuidador_animal
      AND cat.id_tarea = t.id_tarea
);


-- =========================================================================
-- 23. DIRECCIONES DE ENTREGA
-- =========================================================================

INSERT INTO direccion_entrega
    (id_usuario, nombre_recibe, provincia, canton, distrito, direccion)
SELECT
    u.id_usuario,
    'Destinataria Demo',
    'Cartago',
    'Cartago',
    'Oriental',
    'Dirección ficticia de demostración 1'
FROM usuario u
WHERE u.correo = 'ana.rodriguez@example.com'
AND NOT EXISTS (
    SELECT 1
    FROM direccion_entrega d
    WHERE d.id_usuario = u.id_usuario
);


INSERT INTO direccion_entrega
    (id_usuario, nombre_recibe, provincia, canton, distrito, direccion)
SELECT
    u.id_usuario,
    'Destinatario Demo',
    'San José',
    'San José',
    'Carmen',
    'Dirección ficticia de demostración 2'
FROM usuario u
WHERE u.correo = 'carlos.hernandez@example.com'
AND NOT EXISTS (
    SELECT 1
    FROM direccion_entrega d
    WHERE d.id_usuario = u.id_usuario
);

-- =========================================================================
-- 25. DETALLES DE LAS COMPRAS
-- =========================================================================

INSERT INTO detalle_venta_tienda
    (id_venta_tienda, id_producto, cantidad, precio_unitario, subtotal)
SELECT
    v.id_venta_tienda,
    p.id_producto,
    1,
    i.precio_venta,
    i.precio_venta
FROM venta_tienda v
JOIN usuario u
    ON u.id_usuario = v.id_usuario
JOIN Producto p
    ON p.nombre_producto = 'Taza Tucán'
JOIN inventario_tienda i
    ON i.id_producto = p.id_producto
WHERE u.correo = 'ana.rodriguez@example.com'
AND v.total_factura = 4500.00
AND NOT EXISTS (
    SELECT 1
    FROM detalle_venta_tienda d
    WHERE d.id_venta_tienda = v.id_venta_tienda
      AND d.id_producto = p.id_producto
);


INSERT INTO detalle_venta_tienda
    (id_venta_tienda, id_producto, cantidad, precio_unitario, subtotal)
SELECT
    v.id_venta_tienda,
    p.id_producto,
    1,
    i.precio_venta,
    i.precio_venta
FROM venta_tienda v
JOIN usuario u
    ON u.id_usuario = v.id_usuario
JOIN Producto p
    ON p.nombre_producto = 'Botella Reutilizable'
JOIN inventario_tienda i
    ON i.id_producto = p.id_producto
WHERE u.correo = 'carlos.hernandez@example.com'
AND v.total_factura = 9500.00
AND NOT EXISTS (
    SELECT 1
    FROM detalle_venta_tienda d
    WHERE d.id_venta_tienda = v.id_venta_tienda
      AND d.id_producto = p.id_producto
);


-- =========================================================================
-- 26. ACTUALIZAR STOCK DE LAS NUEVAS VENTAS
-- =========================================================================

UPDATE inventario_tienda i
JOIN Producto p
    ON p.id_producto = i.id_producto
SET i.stock = i.stock - 1
WHERE p.nombre_producto = 'Taza Tucán'
AND EXISTS (
    SELECT 1
    FROM venta_tienda v
    JOIN usuario u ON u.id_usuario = v.id_usuario
    JOIN detalle_venta_tienda d
        ON d.id_venta_tienda = v.id_venta_tienda
    WHERE u.correo = 'ana.rodriguez@example.com'
      AND d.id_producto = i.id_producto
      AND v.total_factura = 4500.00
);


UPDATE inventario_tienda i
JOIN Producto p
    ON p.id_producto = i.id_producto
SET i.stock = i.stock - 1
WHERE p.nombre_producto = 'Botella Reutilizable'
AND EXISTS (
    SELECT 1
    FROM venta_tienda v
    JOIN usuario u ON u.id_usuario = v.id_usuario
    JOIN detalle_venta_tienda d
        ON d.id_venta_tienda = v.id_venta_tienda
    WHERE u.correo = 'carlos.hernandez@example.com'
      AND d.id_producto = i.id_producto
      AND v.total_factura = 9500.00
);


-- =========================================================================
-- 27. TICKETS / VENTAS DE ENTRADAS ADICIONALES
-- =========================================================================

INSERT INTO ticket_entrada
    (id_usuario, metodo_pago, total_ticket, iva, estado, id_disponibilidad)
SELECT
    u.id_usuario,
    'Efectivo',
    16000.00,
    2080.00,
    'Completado',
    d.id_disponibilidad
FROM usuario u
JOIN disponibilidad_dia_zoo d
    ON d.fecha = '2026-08-21'
WHERE u.correo = 'ana.rodriguez@example.com';


INSERT INTO ticket_entrada
    (id_usuario, metodo_pago, total_ticket, iva, estado, id_disponibilidad)
SELECT
    u.id_usuario,
    'SINPE',
    10000.00,
    1300.00,
    'Completado',
    d.id_disponibilidad
FROM usuario u
JOIN disponibilidad_dia_zoo d
    ON d.fecha = '2026-08-22'
WHERE u.correo = 'carlos.hernandez@example.com';


INSERT INTO ticket_entrada
    (id_usuario, metodo_pago, total_ticket, iva, estado, id_disponibilidad)
SELECT
    u.id_usuario,
    'Tarjeta',
    12000.00,
    1560.00,
    'Completado',
    d.id_disponibilidad
FROM usuario u
JOIN disponibilidad_dia_zoo d
    ON d.fecha = '2026-08-23'
WHERE u.correo = 'maria.gonzalez@example.com';


-- =========================================================================
-- 28. DETALLES DE TICKETS
-- =========================================================================

INSERT INTO detalle_ticket_entrada
    (id_ticket_entrada, id_tarifa, cantidad, precio, subtotal)
SELECT
    t.id_ticket_entrada,
    te.id_tarifa,
    2,
    te.precio,
    te.precio * 2
FROM ticket_entrada t
JOIN usuario u
    ON u.id_usuario = t.id_usuario
JOIN tarifa_entrada te
    ON te.categoria = 'Adultos'
WHERE u.correo = 'ana.rodriguez@example.com'
AND t.total_ticket = 16000.00
AND NOT EXISTS (
    SELECT 1
    FROM detalle_ticket_entrada d
    WHERE d.id_ticket_entrada = t.id_ticket_entrada
);


INSERT INTO detalle_ticket_entrada
    (id_ticket_entrada, id_tarifa, cantidad, precio, subtotal)
SELECT
    t.id_ticket_entrada,
    te.id_tarifa,
    2,
    te.precio,
    te.precio * 2
FROM ticket_entrada t
JOIN usuario u
    ON u.id_usuario = t.id_usuario
JOIN tarifa_entrada te
    ON te.categoria = 'Niños'
WHERE u.correo = 'carlos.hernandez@example.com'
AND t.total_ticket = 10000.00
AND NOT EXISTS (
    SELECT 1
    FROM detalle_ticket_entrada d
    WHERE d.id_ticket_entrada = t.id_ticket_entrada
);


INSERT INTO detalle_ticket_entrada
    (id_ticket_entrada, id_tarifa, cantidad, precio, subtotal)
SELECT
    t.id_ticket_entrada,
    te.id_tarifa,
    1,
    te.precio,
    te.precio
FROM ticket_entrada t
JOIN usuario u
    ON u.id_usuario = t.id_usuario
JOIN tarifa_entrada te
    ON te.categoria = 'Adultos'
WHERE u.correo = 'maria.gonzalez@example.com'
AND t.total_ticket = 12000.00
AND NOT EXISTS (
    SELECT 1
    FROM detalle_ticket_entrada d
    WHERE d.id_ticket_entrada = t.id_ticket_entrada
);


-- =========================================================================
-- 30. ACTUALIZAR CAPACIDAD DE LOS DÍAS
-- =========================================================================

UPDATE disponibilidad_dia_zoo d
LEFT JOIN (
    SELECT
        t.id_disponibilidad,
        SUM(dt.cantidad) AS boletos_vendidos
    FROM ticket_entrada t
    INNER JOIN detalle_ticket_entrada dt
        ON dt.id_ticket_entrada = t.id_ticket_entrada
    WHERE t.estado NOT IN ('Cancelada', 'Vencida')
    GROUP BY t.id_disponibilidad
) ventas
    ON ventas.id_disponibilidad = d.id_disponibilidad
SET
    d.capacidad_disponible =
        d.capacidad_total - COALESCE(ventas.boletos_vendidos, 0)
WHERE d.fecha >= '2026-08-20';

-- =========================================================================
-- AMPLIACIÓN DE HÁBITATS
-- =========================================================================

INSERT IGNORE INTO habitat (id_habitat, nombre, tipo_habitat, zona, area_m2, capacidad_animales, descripcion, estado) VALUES 
(5, 'Aviario Exótico', 'Cúpula de malla', 'Zona Este', 600.00, 25, 'Gran espacio enmallado con árboles altos y fuentes para aves tropicales.', 'Activo'),
(6, 'Reptilario', 'Climatizado', 'Zona Oeste', 400.00, 15, 'Instalaciones con control de temperatura y humedad para reptiles y anfibios.', 'Activo');
-- =========================================================================
-- REGISTRO DE NUEVOS ANIMALES EN LOS HÁBITATS
-- =========================================================================

INSERT IGNORE INTO animal (codigo_animal, nombre_animal, fecha_nacimiento, fecha_entrada, peso, altura, id_habitat) VALUES 
-- Sabana Africana (id_habitat = 1)
('ANI-023', 'Jirafa', '2021-03-12', '2022-05-10', 125.00, 1.10, 1),

-- Santuario Tropical (id_habitat = 2)
('ANI-024', 'Tortuga', '2018-01-15', '2019-06-20', 45.00, 0.70, 2),
('ANI-025', 'Leopardo', '2022-07-01', '2023-01-15', 3.50, 0.40, 2),

-- Aviario Exótico (id_habitat = 3)
('ANI-026', 'León', '2023-02-10', '2023-08-05', 1.20, 0.30, 3),

-- Reptilario (id_habitat = 4)
('ANI-027', 'Ave', '2017-09-20', '2018-11-11', 85.00, 0.50, 4);
select * from tarjeta_cliente;

