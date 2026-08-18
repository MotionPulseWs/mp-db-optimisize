# MotionPulse Optimize Database

Plugin de WordPress que limpia y reduce el tamaño de la base de datos eliminando metadatos huérfanos y duplicados, revisiones, papelera y campos ACF sin dueño — sin romper las traducciones ni los datos de plugins desactivados.

Pensado para instalaciones con **Elementor** y **ACF**, donde la tabla `postmeta` crece hasta volverse el mayor peso de la base de datos.

**Versión actual:** 3.3 · **Autor:** Kadir Kevin ([MotionPulse](https://github.com/MotionPulseWs))

---

## Qué hace

Ejecución automática (diaria, vía WP-Cron):

- Elimina **metadatos huérfanos** (`postmeta` cuyo post ya no existe).
- Elimina **metadatos duplicados reales** (filas idénticas en `post_id` + `meta_key` + `meta_value`).
- Elimina **revisiones de posts** y su metadata.
- Vacía la **papelera** (posts con estado `trash`) y su metadata.
- Elimina **campos ACF huérfanos** (`_acf_*`, `field_*`, `_field_*` sin post asociado o en papelera).
- Ejecuta `OPTIMIZE TABLE` sobre `posts` y `postmeta`.
- Registra el tamaño de la base de datos antes y después, y calcula la reducción.

Revisión manual (nunca automática):

- Lista los **Custom Post Types no registrados** y las **taxonomías no registradas** que existen en la base de datos, con su número de elementos, para que el administrador decida qué borrar.

## Seguridad de los datos

Este plugin borra filas de forma directa e irreversible. Tres decisiones de diseño existen precisamente para evitar pérdidas de datos:

**1. Los CPT y taxonomías huérfanos NO se borran automáticamente.**
Que un post type no esté registrado no significa que sea basura: puede pertenecer a un plugin desactivado temporalmente, o registrarse sólo en el admin. La rutina automática corre bajo WP-Cron, donde muchos tipos no están registrados. Por eso sólo se **escanean** y se muestran en una tabla; el borrado requiere una acción explícita del administrador, con confirmación.

**2. Los metadatos multivaluados se respetan.**
WordPress permite legítimamente varias filas con la misma `meta_key` y distinto valor (`add_post_meta` con `$unique = false`). Sólo se consideran duplicados las filas idénticas también en `meta_value`.

**3. Compatible con TranslatePress - Multilingual.**
TranslatePress no duplica posts por idioma: guarda las traducciones en sus propias tablas `<prefijo>trp_*`. El optimizador nunca opera sobre ellas, excluye sus post types y los posts vinculados por meta keys `trp_*`, y muestra en el panel las tablas protegidas que ha detectado.

> Aun así, **haz siempre una copia de seguridad de la base de datos antes de la primera ejecución.**

## Requisitos

- WordPress 5.0 o superior
- PHP 7.0 o superior
- **MySQL 8.0+ o MariaDB 10.2+** — la detección de duplicados usa la función de ventana `ROW_NUMBER() OVER (PARTITION BY ...)`, no disponible en versiones anteriores.
- Permisos `manage_options` para acceder al panel
- WP-Cron activo (o un cron real apuntando a `wp-cron.php`)

## Instalación

Desde el ZIP de la release:

1. Descarga el archivo `mp-db-optimisize-x.y.zip` desde [Releases](https://github.com/MotionPulseWs/mp-db-optimisize/releases).
2. En WordPress ve a **Plugins → Añadir nuevo → Subir plugin**.
3. Selecciona el ZIP, instala y activa.

Manualmente:

```bash
cd wp-content/plugins
git clone https://github.com/MotionPulseWs/mp-db-optimisize.git
```

Y activa el plugin desde el escritorio de WordPress.

## Uso

Al activarlo, el plugin programa una primera optimización a los 10 segundos y después una ejecución diaria. No requiere configuración.

En el menú lateral aparece **MotionPulse DB**, donde puedes:

- Ver el estado de la última optimización y su fecha.
- Ver el tamaño actual de la base de datos, el tamaño previo y la reducción conseguida.
- Ver cuántos elementos se han eliminado por categoría.
- Pulsar **Optimizar ahora** para lanzar una ejecución manual.
- Revisar y borrar a mano los CPT y taxonomías huérfanos detectados.

Para limitar el crecimiento futuro por revisiones, añade esto a tu `wp-config.php`:

```php
// Limita a 3 revisiones por post
define('WP_POST_REVISIONS', 3);
```

## Estructura del proyecto

```
optimize-db.php          Cabecera, constantes, cron, assets, AJAX
includes/functions.php   Toda la lógica de base de datos
admin/admin-page.php     Panel de administración
admin/css/               Estilos del panel
admin/js/                Refresco de estadísticas por AJAX
```

El plugin no tiene dependencias, ni build, ni gestor de paquetes: es PHP y jQuery. El estado se guarda íntegramente en opciones de WordPress con prefijo `mpodb_`, sin tablas propias.

## Desarrollo

Para generar el ZIP distribuible:

```bash
bash build-zip.sh
```

Genera `mp-db-optimisize-<versión>.zip` en la raíz, con la carpeta `mp-db-optimisize/` dentro y sin archivos de desarrollo. Los `.zip` están ignorados por Git.

### Qué archivos van dentro del ZIP

Si necesitas montar el ZIP a mano (sin `bash`, por ejemplo en Windows sin Git Bash), la carpeta que subas a WordPress debe llamarse `mp-db-optimisize` y contener **sólo** esto:

```
mp-db-optimisize/
├── optimize-db.php
├── index.php
├── includes/
│   └── functions.php
├── admin/
│   ├── admin-page.php
│   ├── css/
│   │   └── admin-style.css
│   └── js/
│       └── admin-script.js
```

**No incluyas** `.git/`, `.gitignore`, `.gitattributes`, `.vscode/`, `CLAUDE.md`, `build-zip.sh`, ni la carpeta `build/`: son archivos de desarrollo, no forman parte del plugin y si acaban dentro del ZIP no rompen nada, pero no deben subirse a un sitio de producción. `README.md` tampoco es necesario en el sitio, aunque no causa problemas si se incluye.

El nombre de la carpeta raíz dentro del ZIP importa: WordPress la usa como slug del plugin. Si subes el ZIP con un nombre de carpeta distinto a `mp-db-optimisize`, un sitio que ya tenga el plugin instalado quedará con dos copias en vez de actualizar la existente.

La forma más fiable de no equivocarte es seguir usando `bash build-zip.sh` — hace exactamente esta selección de archivos de forma automática.

Convenciones del código: funciones, opciones y nonces con prefijo `mpodb_`; clases CSS e IDs con prefijo `mpodb-`; código procedural sin clases; comentarios e interfaz en español.

Si vas a añadir cualquier operación nueva sobre tablas o posts, respeta las salvaguardas existentes: usa `mpodb_is_table_safe_to_touch()` para operaciones a nivel de tabla y `mpodb_get_translatepress_exclusion_sql()` para consultas sobre posts. Nunca asumas el prefijo `wp_`: usa siempre `$wpdb->prefix`.

## Contribuir

Las incidencias y pull requests son bienvenidas en [GitHub](https://github.com/MotionPulseWs/mp-db-optimisize/issues). Si reportas un fallo, incluye la versión de WordPress, de PHP y de MySQL/MariaDB, y los plugins multiidioma o de campos personalizados que tengas activos.

## Licencia

GPLv2 o posterior, la licencia estándar de los plugins de WordPress.

## Apoya el proyecto

Este plugin es gratuito y de uso libre. Si te ha ahorrado tiempo, puedes [invitarnos a un café](https://www.paypal.com/ncp/payment/MUCAMQDHUBSXN) ☕
