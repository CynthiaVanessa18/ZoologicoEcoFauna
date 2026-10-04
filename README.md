# EcoFauna — Gestión de un zoológico

<p align="center">
  <img src="public/assets/animales/pexels-connorscottmcmanus-35152317.jpg" alt="Panda rojo entre los árboles, recurso visual del catálogo de EcoFauna" width="760">
</p>

<p align="center"><strong>Animales, cuidados y experiencias en una sola aplicación web.</strong></p>

Aplicación web de demostración para gestionar animales, hábitats, cuidados veterinarios, personal, entradas y una tienda. Desarrollada con PHP, MySQL/MariaDB, JavaScript y Bootstrap.

**PHP · MySQL/MariaDB · JavaScript · Bootstrap · Composer**

[Funcionalidades](#funcionalidades) · [Modelo de datos](#modelo-de-datos) · [Instalación local](#instalación-local) · [Accesos de demostración](#accesos-de-demostración)

## El proyecto de un vistazo

| Área | Qué permite hacer |
| --- | --- |
| Gestión del zoológico | Administrar animales y hábitats, con fotografías y validación de capacidad. |
| Cuidado animal | Consultar alimentación, vacunas e historial médico y asignar cuidadores. |
| Experiencia del cliente | Explorar el catálogo, comprar productos y adquirir entradas simuladas. |
| Administración | Gestionar usuarios, permisos, inventario y bitácora con acceso por roles. |

<p align="center">
  <img src="public/assets/imagenes/habitat/a-path-through-a-tropical-forest-with-palm-trees-photo.jpg" alt="Bosque tropical, recurso visual de los hábitats de EcoFauna" width="460">
  <img src="public/assets/img/zoo.jpg" alt="Flamencos en un entorno tropical, recurso visual de EcoFauna" width="230">
</p>

<p align="center"><em>Selección de recursos visuales incluidos en el proyecto.</em></p>

El código permite explorar módulos PHP, un modelo de datos relacional, sesiones y permisos por rol, formularios y flujos de inventario, compras y entradas. Incluye cuentas de demostración e instrucciones para ejecutarlo localmente.

## Funcionalidades

- Acceso por roles: administrador, empleado, veterinario y cliente.
- Administración de usuarios, permisos y bitácora de acciones.
- Gestión de animales, hábitats, alimentación, vacunas e historial médico.
- Administración de animales con fotografía, hábitat y validación de capacidad; catálogo para clientes con búsqueda y filtros.
- Asignación de cuidadores, horarios y tareas.
- Inventario, productos, carrito y compras simuladas.
- Disponibilidad, venta de entradas y registro de accesos.
- Recuperación de contraseña por correo, con SMTP opcional.

## Modelo de datos

![Diagrama de relaciones del proyecto](docs/diagramas/DER%20Proyecto%20EcoFauna.png)

## Requisitos

- PHP 8.0 o superior, con `mysqli` (mysqlnd), `openssl` y `mbstring`.
- MySQL o MariaDB con soporte de procedimientos, triggers y restricciones CHECK.
- Composer y Apache; XAMPP permite ejecutar el proyecto localmente.
- Conexión a Internet para los estilos, iconos y fuentes cargados desde CDN.

## Instalación local

1. Copia o clona el proyecto en `C:\xampp\htdocs\ZoologicoPortafolio`.
2. Desde esa carpeta ejecuta `composer install` para instalar las versiones de `composer.lock`.
3. Copia `config/example.php` como `config/local.php`. Completa el acceso a tu base de datos. El archivo local está excluido de Git y bloqueado por Apache.
4. Ejecuta `C:\xampp\php\php.exe scripts/generar_clave.php` y copia el resultado en `ECOFAUNA_ENCRYPTION_KEY` del archivo local.
5. Inicia Apache y MySQL desde XAMPP. Importa `database/bd.sql` con phpMyAdmin en una instalación nueva. El script crea `ZoologicoDBPortafolio`; no debe importarse sobre una base existente ni ejecutarse con la opción de ignorar errores.
6. Abre [EcoFauna local](http://localhost/ZoologicoPortafolio/).

También puedes configurar las variables `ECOFAUNA_*` en el entorno del servidor: tienen prioridad sobre `config/local.php`. Los nombres disponibles figuran en `config/example.php`. No se cargan archivos `.env` automáticamente.

Si ya tienes una instalación, conserva su configuración y su clave de cifrado. Cambiar la clave impide leer las tarjetas previamente cifradas. Los cambios de `database/bd.sql` no alteran tu base existente: contienen datos ficticios para instalaciones nuevas.

## Accesos de demostración

En una instalación nueva, estas cuentas usan la contraseña **`EcoFaunaDemo!2026`**:

| Usuario | Rol |
| --- | --- |
| `admin` | Administrador |
| `empleado` | Empleado |
| `vet` | Veterinario |
| `cliente` | Cliente |

Las cuentas y los datos iniciales son públicos y exclusivamente de demostración. No uses estas cuentas para una instalación con información real. Los correos `example.com` no reciben mensajes.

## Pagos simulados

No hay conexión con bancos ni procesamiento de dinero real. Usa únicamente datos ficticios.

Para cargar una tarjeta en una base de demostración cuyo banco esté vacío:

```powershell
C:\xampp\php\php.exe scripts/cargar_banco_demo.php --demo
```

| Campo | Valor de prueba |
| --- | --- |
| Banco | Banco Demo |
| Número | `4111111111111111` |
| Titular | Cliente Demo |
| Vencimiento | 12/2035 |
| CVC | `123` |

El cargador solo funciona desde consola y se detiene si ya existen tarjetas. La antigua ruta `tienda/banco.php` devuelve 404. El almacenamiento cifrado de tarjetas y CVC pertenece a esta simulación y no debe utilizarse para datos de pago reales.

## Correo opcional

Completa `ECOFAUNA_SMTP_USER` y `ECOFAUNA_SMTP_PASSWORD` con credenciales propias, además del servidor y puerto si son diferentes de Gmail con STARTTLS en el puerto 587. Sin esta configuración no se envían los PIN de recuperación. Para probar el envío usa una cuenta de prueba con una dirección que controles.

## Reiniciar la demostración

La instalación normal no borra bases de datos. Si necesitas empezar de cero, realiza primero una copia de seguridad y ejecuta **manualmente** `database/reiniciar_demo.sql`, que elimina `ZoologicoDBPortafolio`. Después importa `database/bd.sql`. No uses este procedimiento sobre datos que quieras conservar.

## Organización

```text
app/
  modulos/        Páginas y operaciones agrupadas por función
  plantillas/     Plantillas de correo
  soporte/        Sesiones, funciones compartidas y resolución de recursos
config/           Conexión, entorno, rutas y configuración local privada
database/         Instalación y reinicio de la base de demostración
docs/
  diagramas/      Modelo de datos
  manuales/       Manual de usuario local
public/
  assets/         CSS, JavaScript e imágenes de la aplicación
  index.php       Entrada pública de la aplicación
scripts/          Herramientas de consola y generador del manual
storage/
  datos/          Catálogo de hábitats
  sesiones/       Archivos privados de sesión (excluidos de Git)
  uploads/        Imágenes cargadas y recursos de demostración
tests/            Verificaciones de estructura y pruebas locales
vendor/           Dependencias instaladas por Composer (excluidas de Git)
index.php         Entrada compatible con la carpeta de XAMPP
```

Las páginas se agrupan en `app/modulos/`: autenticación, administración, clientes, empleados, entradas, acceso, inventario, hábitats, cuidadores, veterinario, tienda, administración de tienda y usuario.

`config/rutas.php` conecta las URLs existentes con los archivos organizados. Por ejemplo, `admin.php` abre `app/modulos/administracion/panel.php`. Los formularios, las peticiones AJAX y las rutas de imágenes guardadas en la base conservan sus URLs. Solo se ejecutan los módulos enumerados en ese archivo; las funciones y acciones internas no se publican directamente.

Para XAMPP, habilita `mod_rewrite` y `AllowOverride All`: la entrada de la raíz mantiene `http://localhost/ZoologicoPortafolio/`. En un servidor dedicado, configura `public/` como DocumentRoot; el resto de carpetas queda fuera del área pública.

También puedes iniciar una vista local sin Apache desde la raíz del proyecto:

```powershell
C:\xampp\php\php.exe -S 127.0.0.1:8080 -t public scripts/servir.php
```

Abre `http://127.0.0.1:8080/`. MySQL sigue siendo necesario para los módulos con datos.

Ejecuta `C:\xampp\php\php.exe tests/verificar_estructura.php` para comprobar las rutas de módulos, las dependencias locales y la separación entre archivos públicos y privados.

## Publicación y alcance

El repositorio incluye el código y recursos de demostración. `.gitignore` excluye configuración privada, dependencias descargables, registros, copias de seguridad y el manual Word local, que puede contener información de una instalación anterior. En `storage/uploads/` solo se incluyen los recursos existentes enumerados en `.gitignore`; las nuevas cargas se excluyen por defecto. Revisa las imágenes y sus permisos de uso antes de redistribuirlas; no se declara una licencia sobre recursos de terceros.

Apache debe permitir las reglas `.htaccess`. Si usas otro servidor, configura reglas equivalentes para impedir descargas de configuración, SQL, documentos internos y scripts. Mantén las credenciales fuera del repositorio y cambia cualquier contraseña que se haya compartido anteriormente.

El proyecto muestra implementación de módulos PHP, SQL relacional, roles, sesiones, formularios y operaciones de negocio. La autoría y las contribuciones individuales deben describirse según el trabajo efectivamente realizado; este documento no atribuye contribuciones que no hayan sido confirmadas.

## Verificación manual

1. Instalar desde cero y acceder con cada rol de demostración.
2. Comprobar que cada rol accede solo a sus módulos.
3. Registrar una tarjeta ficticia y completar una compra y una entrada simuladas.
4. Revisar inventario, tareas de cuidadores e historial veterinario.
5. Comprobar que `/config/local.php` y `/database/bd.sql` quedan bloqueados (403 en la raíz de XAMPP, 404 con el servidor local), y `/tienda/banco.php` devuelve 404.
6. Configurar SMTP propio para verificar la recuperación de contraseña.
7. En Administración → Animales, registrar y editar un animal. Verificar que aparece en Cliente → Animales y que los filtros funcionan. La eliminación pide confirmación y se bloquea si existen registros veterinarios, alimentación o cuidadores asociados.

Para probar el módulo de animales ejecuta `php tests/verificar_animales.php` con MySQL activo. La prueba crea y elimina una base temporal con prefijo `ecofauna_test_animales_`; necesita permiso para crear bases y no modifica los datos de la instalación.
