# Semantica Docs

Documentación de los productos de **Semantica Digital**: manuales, procesos y guías técnicas reunidos en un solo lugar.

Es el visor y el administrador de la documentación. Reemplaza la documentación de **carbono** (tabla `documentacion` y archivos `/documentacion/{id}.md`) y el front de documentación de **teneso**.

El diseño sigue la línea visual de Holmio (Semantica ERP).

Para instalarlo en un servidor de producción, vea **[DESPLIEGUE.md](DESPLIEGUE.md)**.

## Tecnologías

- PHP 8.2+ y Symfony 7.4
- Doctrine ORM 3 con PostgreSQL 16
- Twig y AssetMapper (sin Node ni webpack), con Stimulus y Turbo

## Requisitos

- PHP 8.2 o superior con las extensiones `ctype`, `iconv`, `dom`, `xml`, `intl`, `mbstring` y `pdo_pgsql`
- Composer 2
- PostgreSQL 16 (local o con Docker)

## Instalación

```bash
composer install
```

Cree el archivo `.env` en la raíz del proyecto. No se versiona porque lleva las credenciales, y Symfony no arranca sin él:

```dotenv
APP_ENV=dev
APP_SECRET=          # se completa con: bin/console app:generar-secret --guardar
APP_SHARE_DIR=var/share
DEFAULT_URI=http://localhost
DATABASE_URL="postgresql://usuario:clave@127.0.0.1:5432/semantica_docs?serverVersion=16&charset=utf8"
```

Genere el `APP_SECRET` (64 caracteres aleatorios; nunca reutilice el de otro entorno):

```bash
bin/console app:generar-secret --guardar
```

Si prefiere Docker, `docker compose up -d database` levanta PostgreSQL con los valores por defecto de `compose.yaml`.

Cree la base de datos y aplique las migraciones. La migración inicial crea las tablas vacías, la extensión `unaccent` y la función `f_unaccent` que usa la búsqueda:

```bash
bin/console doctrine:database:create
bin/console doctrine:migrations:migrate
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

El Markdown puede traer HTML (los `.md` de teneso son HTML de su editor visual). Por eso todo el HTML generado pasa por `symfony/html-sanitizer` con una lista blanca: se conservan las etiquetas de formato y se quitan scripts, eventos (`on*`), estilos en línea, formularios, iframes y enlaces `javascript:`. Esto aplica al visor y a la vista previa.

## Migración de datos desde carbono

Se hace una sola vez y de forma manual (no hay comando de importación). Reglas que deben respetarse:

- **Conservar los ids** de `documentacion.codigo_documentacion_pk` en `documento.id`: holmio enlaza la ayuda por ese número (`/documento/{id}`).
- Los ids de `proyecto` también se conservan.
- El contenido va en `documento.contenido` en Markdown. Puede incluir HTML (los `.md` de teneso lo son); el sanitizador lo limpia al mostrarlo.
- Las secciones replican el menú de teneso (`teneso/src/app/_metronic/layout/components/aside/menu.ts`): aplicación > menú > grupo, y cada documento en la sección de su grupo.
- Al insertar ids explícitos en PostgreSQL hay que reiniciar las secuencias, o el siguiente documento creado desde el administrador chocará:

```sql
SELECT setval(pg_get_serial_sequence('documento', 'id'), (SELECT MAX(id) FROM documento) + 1, false);
SELECT setval(pg_get_serial_sequence('proyecto', 'id'), (SELECT MAX(id) FROM proyecto) + 1, false);
SELECT setval(pg_get_serial_sequence('seccion', 'id'), (SELECT MAX(id) FROM seccion) + 1, false);
```

## Estructura

| Ruta | Contenido |
|---|---|
| `src/Controller/` | Controladores (`VisorController`: visor público y búsqueda; `SeguridadController`: login y logout) |
| `src/Controller/Admin/` | Administrador de documentación |
| `src/Form/` | Formularios |
| `src/Markdown/` | Renderizador de Markdown y sanitizador de HTML |
| `src/Twig/` | Funciones Twig del visor (URL de documentos, videos, fragmentos de búsqueda) |
| `src/Documentacion/` | Árbol de navegación del visor |
| `src/Entity/`, `src/Repository/` | Entidades y repositorios de Doctrine |
| `src/Security/` | Reglas de autenticación |
| `src/Command/` | Comandos de consola (`app:crear-usuario`, `app:generar-secret`) |
| `migrations/` | Migraciones de Doctrine |
| `templates/` | Plantillas Twig |
| `assets/styles/` | CSS. Los colores del tema están en variables `--sem-*` dentro de `app.css` |
| `assets/controllers/` | Controladores Stimulus (`editor_markdown_controller.js`: editor EasyMDE) |
| `assets/images/semantica/` | Logos de Semantica Digital |

## Convenciones

- Los nombres de negocio van en español (entidades, rutas, variables, carpetas de plantillas). El código de framework e infraestructura va en inglés.
- Los cambios de esquema se hacen con migraciones: después de cambiar una entidad, `bin/console doctrine:migrations:diff` genera la migración; se revisa, se prueba y se sube junto con el cambio. No use `doctrine:schema:update` en ninguna base compartida.

## Pruebas

```bash
bin/phpunit
```

Cubren el renderizador de Markdown (19 vectores de XSS y phishing, conservación del formato legítimo y del HTML de teneso, documentos de más de 20.000 caracteres e índice de títulos), las funciones Twig del visor, la validación de que la sección de un documento sea de su mismo proyecto y `app:generar-secret` (que nunca toca el `.env` real). Ninguna prueba usa la base de datos.

## Comandos útiles

```bash
bin/console cache:clear
bin/console debug:router
bin/console doctrine:migrations:status
bin/console doctrine:migrations:diff
bin/console doctrine:migrations:migrate
bin/console app:generar-secret
bin/console lint:twig templates
bin/phpunit
```
