# Semantica Docs

Documentación de los productos de **Semantica Digital**: manuales, procesos y guías técnicas reunidos en un solo lugar.

Es el visor y el administrador de la documentación. Reemplaza la documentación de **carbono** (tabla `documentacion` y archivos `/documentacion/{id}.md`) y el front de documentación de **teneso**.

El diseño sigue la línea visual de Holmio (Semantica ERP).

## Tecnologías

- PHP 8.2+ y Symfony 7.4
- Doctrine ORM 3 con PostgreSQL 16
- Twig y AssetMapper (sin Node ni webpack), con Stimulus y Turbo

## Requisitos

- PHP 8.2 o superior con las extensiones `pdo_pgsql`, `ctype` e `iconv`
- Composer 2
- PostgreSQL 16 (local o con Docker)

## Instalación

```bash
composer install
```

Cree el archivo `.env` en la raíz del proyecto. No se versiona porque lleva las credenciales, y Symfony no arranca sin él:

```dotenv
APP_ENV=dev
APP_SECRET=          # en producción: un valor aleatorio, ej. el resultado de `openssl rand -hex 32`
APP_SHARE_DIR=var/share
DEFAULT_URI=http://localhost
DATABASE_URL="postgresql://usuario:clave@127.0.0.1:5432/semantica_docs?serverVersion=16&charset=utf8"
MESSENGER_TRANSPORT_DSN=doctrine://default?auto_setup=0
MAILER_DSN=null://null
# Solo para app:importar-carbono
# CARBONO_DATABASE_URL="mysql://usuario:clave@127.0.0.1:3306/bdcarbono"
```

En producción use `APP_ENV=prod` y un `APP_SECRET` propio, y después ejecute `composer dump-env prod`.

Si prefiere Docker, `docker compose up -d database` levanta PostgreSQL con los valores por defecto de `compose.yaml`.

Cree la base de datos, las tablas y lo que necesita la búsqueda (extensión `unaccent` y función `f_unaccent`, que Doctrine no administra):

```bash
bin/console doctrine:database:create
bin/console doctrine:schema:update --force
bin/console app:configurar-busqueda
```

Cree el primer usuario (la contraseña se pide por consola):

```bash
bin/console app:crear-usuario admin "Administrador" --admin
```

## Ejecutar en desarrollo

```bash
symfony serve
# o, sin Symfony CLI:
php -S 127.0.0.1:8000 -t public public/index.php
```

Abra http://127.0.0.1:8000 para el visor (público) o http://127.0.0.1:8000/admin/documento para el administrador (pide iniciar sesión).

## Autenticación

- Los usuarios se guardan en la tabla `usuario` (`App\Entity\Usuario`) y se identifican por `username`.
- No hay registro público. Los usuarios se crean con `app:crear-usuario`.
- El login usa formulario con protección CSRF y la opción "Mantenerme conectado" (1 semana). Después de 5 intentos fallidos se bloquea temporalmente.
- Un usuario con `activo = false` no puede ingresar (`App\Security\UsuarioChecker`).
- Roles: `ROLE_USER` (lector), `ROLE_EDITOR` (edita la documentación) y `ROLE_ADMIN` (incluye a los otros dos). Se asignan con `--editor` o `--admin` en `app:crear-usuario`.
- El visor es público. Solo `/admin` exige sesión con `ROLE_EDITOR`. Al ingresar se va al administrador y al salir se vuelve al visor.

## Modelo de datos

| Entidad | Tabla | Descripción |
|---|---|---|
| `Proyecto` | `proyecto` | Producto documentado (Semantica, RedDoc, Ruteo...). Viene de `proyecto` de carbono, con los mismos ids. |
| `Seccion` | `seccion` | Árbol de navegación de cada proyecto. En el ERP: aplicación > menú > grupo, igual que el menú de teneso. |
| `Documento` | `documento` | Página en Markdown. Viene de `documentacion` de carbono **con el mismo id**, porque holmio enlaza la ayuda por ese número. `modelo` es la entidad del ERP (ej. `TteGuia`). |

El contenido se guarda en la base de datos (columna `contenido`), ya no en archivos `.md`.

## Visor

| URL | Contenido |
|---|---|
| `/` | Proyectos con documentación publicada |
| `/docs/{proyecto}` | Portada del proyecto: menú lateral y tarjetas por sección |
| `/docs/{proyecto}/seccion/{id}` | Subsecciones y documentos de una sección |
| `/docs/{proyecto}/{id}/{titulo}` | Documento, con migas de pan, índice "En esta página", video de YouTube incrustado y enlaces anterior/siguiente |
| `/documento/{id}` | Enlace corto por número, el que usa la ayuda de holmio; redirige a la URL completa |
| `/buscar?q=...&proyecto=...` | Búsqueda de texto completo en español |

- Si cambia el título o el proyecto de un documento, la URL vieja redirige a la nueva.
- El visor no exige sesión. Los borradores (`publicado = false`) y los proyectos inactivos solo los ven los editores con sesión iniciada; para los demás no existen (404) y no aparecen en la búsqueda.
- La búsqueda usa el texto completo de PostgreSQL en español: ignora tildes (`unaccent`), entiende variaciones de las palabras ("facturas" encuentra "factura") y también encuentra palabras incompletas en el título. Por eso el proyecto necesita PostgreSQL.

## Administrador

`/admin/documento` lista, busca (título, modelo o número), crea, edita y elimina documentos. El contenido se escribe en Markdown con [EasyMDE](https://github.com/Ionaru/easy-markdown-editor). La vista previa la genera el servidor con el mismo renderizador del visor (`App\Markdown\Renderizador`, Markdown de GitHub: tablas, listas de tareas, enlaces automáticos).

El Markdown puede traer HTML (los `.md` de teneso son HTML de su editor visual). Por eso todo el HTML generado pasa por `symfony/html-sanitizer` con una lista blanca: se conservan las etiquetas de formato y se quitan scripts, eventos (`on*`), estilos en línea, formularios, iframes y enlaces `javascript:`. Esto aplica al visor, a la vista previa y al contenido importado.

## Migración desde carbono y teneso

`app:importar-carbono` copia proyectos y documentos de la base de carbono, y arma las secciones con el menú de teneso (`data/importacion/teneso-menu.json`, extraído de `teneso/src/app/_metronic/layout/components/aside/menu.ts`).

```bash
# Contenido desde la columna HTML de carbono, convertido a Markdown
bin/console app:importar-carbono --origen="mysql://usuario:clave@127.0.0.1:3306/bdcarbono"

# Contenido desde los .md que editaba teneso (copia de /documentacion del servidor)
bin/console app:importar-carbono --origen=... --directorio=/ruta/a/documentacion

# Contenido descargado de la API de carbono
bin/console app:importar-carbono --origen=... --api=https://semantica.com.co/api/documentacion/archivo
```

Para cada documento el contenido se toma del archivo `.md`, si no existe de la API, y si tampoco de la columna HTML. `--origen` también puede ir en la variable `CARBONO_DATABASE_URL`. Si ya hay documentos, se exige `--reemplazar`, que borra proyectos, secciones y documentos antes de importar. Antes de borrar avisa cuántos documentos se perderán (incluidos los editados en radio) y pide confirmación (`s/N`, por defecto no). En modo no interactivo (cron, scripts) solo continúa si además se pasa `--forzar`. Todo corre en una transacción.

## Estructura

| Ruta | Contenido |
|---|---|
| `src/Controller/` | Controladores (`SeguridadController`: login y logout; `InicioController`: página de inicio) |
| `src/Controller/Admin/` | Administrador de documentación |
| `src/Form/` | Formularios |
| `src/Markdown/`, `src/Twig/` | Renderizador de Markdown y filtro Twig `markdown` |
| `src/Entity/`, `src/Repository/` | Entidades y repositorios de Doctrine |
| `src/Security/` | Reglas de autenticación |
| `src/Command/` | Comandos de consola |
| `templates/` | Plantillas Twig |
| `assets/styles/` | CSS. Los colores del tema están en variables `--sem-*` dentro de `app.css` |
| `assets/controllers/` | Controladores Stimulus (`editor_markdown_controller.js`: editor EasyMDE) |
| `assets/images/semantica/` | Logos de Semantica Digital |
| `data/importacion/` | Datos para la migración desde teneso |

## Convenciones

- Los nombres de negocio van en español (entidades, rutas, variables, carpetas de plantillas). El código de framework e infraestructura va en inglés.
- El proyecto no usa migraciones. Después de cambiar una entidad, revise el SQL con `bin/console doctrine:schema:update --dump-sql` y aplíquelo con `--force`. No use `--complete`: borraría tablas que Doctrine no conoce.
- `app:configurar-busqueda` se puede ejecutar las veces que se quiera; hay que correrlo en cada base nueva.

## Pruebas

```bash
bin/phpunit
```

Cubren el renderizador de Markdown (19 vectores de XSS y phishing, conservación del formato legítimo y del HTML de teneso, documentos de más de 20.000 caracteres e índice de títulos), las funciones Twig del visor, la validación de que la sección de un documento sea de su mismo proyecto, la confirmación de `--reemplazar` del importador y `app:configurar-busqueda`. Ninguna prueba usa la base de datos.

## Comandos útiles

```bash
bin/console cache:clear
bin/console debug:router
bin/console doctrine:schema:update --dump-sql
bin/console doctrine:schema:update --force
bin/console app:configurar-busqueda
bin/console lint:twig templates
bin/phpunit
```
