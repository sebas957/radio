# Despliegue de Semantica Docs (`radio`)

Guía para instalar y actualizar el proyecto en un servidor de producción. El proyecto se despliega con el nombre del repositorio, **`radio`**: carpeta `/var/www/html/radio`, sitio de Apache `radio`, logs `radio_*.log`. La base de datos es un **PostgreSQL administrado** (servicio en la nube), no un PostgreSQL instalado en el servidor.

En los ejemplos, `radio.semantica.com.co` es el dominio: reemplácelo por el real.

## Arquitectura

```
Visitantes (visor, sin login) ─┐
Editores (/admin, con login) ──┼──> Cloudflare (opcional) ──> Apache (solo public/) ──> PHP-FPM + Symfony 7.4 ──> PostgreSQL administrado (TLS)
Holmio (/documento/{id}) ──────┘
```

- **Solo `public/` queda expuesto a internet.** `.env`, `var/` y el código quedan fuera del alcance de Apache.
- **El esquema se crea con migraciones** (`migrations/`). La migración inicial crea las tablas vacías, la extensión `unaccent` y la función `f_unaccent` de la búsqueda.
- **Sin Node:** los JS y CSS se compilan con AssetMapper.

## 1. Requisitos del servidor

| Componente | Versión | Para qué |
| --- | --- | --- |
| PHP-FPM | 8.2+ (probado con 8.3) | Ejecutar la aplicación |
| Extensiones PHP | `ctype`, `iconv`, `dom`, `xml`, `intl`, `mbstring`, `pdo_pgsql`, `opcache` | Symfony, sanitizador HTML, fechas en español, base de datos |
| Apache | 2.4 con `proxy_fcgi`, `setenvif`, `headers`, `reqtimeout` | Servidor web |
| Composer, Git | 2.x / cualquiera | Instalar el código y sus dependencias |
| Certbot | cualquiera | Certificado HTTPS |
| PostgreSQL administrado | 16, con la extensión `unaccent` permitida | Datos y búsqueda sin tildes |

En Ubuntu 24.04 (el servidor no necesita PostgreSQL instalado; `postgresql-client` es solo para `psql` y `pg_dump`):

```bash
sudo apt install apache2 php8.3-fpm php8.3-intl php8.3-xml php8.3-mbstring php8.3-pgsql php8.3-opcache \
    postgresql-client composer git certbot python3-certbot-apache
sudo a2enmod proxy_fcgi setenvif headers reqtimeout
sudo a2enconf php8.3-fpm
```

Xdebug **no** debe estar instalado en producción: `php -m | grep -i xdebug` no debe mostrar nada.

## 2. Preparar el PostgreSQL administrado

La aplicación nunca se conecta con el usuario administrador del servicio. Se crean dos roles:

| Rol | Lo usa | Puede |
| --- | --- | --- |
| `radio_owner` | Solo los comandos de despliegue (`doctrine:migrations:migrate`) | Crear y modificar tablas, extensiones y funciones de `bdradio` |
| `radio_app` | La aplicación web (`DATABASE_URL` del `.env`) | Leer, insertar, editar y borrar filas de `bdradio`; nada más |

**En el panel del proveedor:**

1. Permitir conexiones **solo desde la IP del servidor web** (lista de IPs permitidas o red privada/VPC). Nunca abierto a todo internet.
2. Activar la conexión con TLS obligatorio y descargar el certificado de la CA si el proveedor lo entrega.
3. Revisar que la extensión `unaccent` esté permitida. En la mayoría de los proveedores lo está; en Azure hay que agregarla al parámetro `azure.extensions`.
4. Activar los respaldos automáticos (sección 8).

**Con el usuario administrador del servicio** (`psql "postgresql://ADMIN:CLAVE@HOST:PUERTO/postgres?sslmode=require"`). Genere cada clave con `openssl rand -hex 24`:

```sql
CREATE ROLE radio_owner LOGIN PASSWORD 'CLAVE_OWNER';
CREATE ROLE radio_app   LOGIN PASSWORD 'CLAVE_APP';
CREATE DATABASE bdradio OWNER radio_owner ENCODING 'UTF8' TEMPLATE template0;
REVOKE CONNECT ON DATABASE bdradio FROM PUBLIC;
GRANT CONNECT ON DATABASE bdradio TO radio_owner, radio_app;

-- Ninguna consulta de la web puede trabajar más de 3 segundos
ALTER ROLE radio_app SET statement_timeout = '3s';
```

Algunos proveedores solo dejan crear bases y roles desde su panel. En ese caso, cree ahí `bdradio` (dueño `radio_owner`) y los dos roles, y ejecute el resto con `psql`.

**Conectado a `bdradio` como `radio_owner`** (`psql "postgresql://radio_owner:CLAVE_OWNER@HOST:PUERTO/bdradio?sslmode=require"`):

```sql
GRANT USAGE ON SCHEMA public TO radio_app;
-- Permisos sobre todo lo que radio_owner cree ahora y en futuras migraciones
ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO radio_app;
ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT USAGE, SELECT ON SEQUENCES TO radio_app;
```

## 3. Instalar la aplicación

1. **Descargar el código**

    ```bash
    sudo git clone https://github.com/sebas957/radio.git /var/www/html/radio
    sudo chown -R $USER:www-data /var/www/html/radio
    cd /var/www/html/radio
    ```

2. **Crear el `.env`.** No está en el repositorio. `DATABASE_URL` usa `radio_app` y `sslmode=require`; si el proveedor entrega un certificado de CA, agregue `&sslrootcert=/ruta/ca.crt` y use `sslmode=verify-full`.

    ```dotenv
    APP_ENV=prod
    APP_SECRET=
    APP_SHARE_DIR=var/share
    DEFAULT_URI=https://radio.semantica.com.co
    DATABASE_URL="postgresql://radio_app:CLAVE_APP@HOST:PUERTO/bdradio?serverVersion=16&charset=utf8&sslmode=require"
    ```

    ```bash
    chmod 640 .env
    ```

3. **Instalar dependencias de producción.** Composer ejecuta solo `cache:clear`, `assets:install` e `importmap:install`, que descarga EasyMDE y las demás librerías JS.

    ```bash
    composer install --no-dev --optimize-autoloader --classmap-authoritative
    ```

4. **Generar el `APP_SECRET`** propio de este servidor. Nunca use el de `.env.dev`, que es público en GitHub.

    ```bash
    php bin/console app:generar-secret --guardar
    ```

5. **Compilar las variables de entorno y los assets**

    ```bash
    composer dump-env prod             # crea .env.local.php; desde aquí Symfony ya no lee .env
    php bin/console asset-map:compile  # compila JS y CSS en public/assets/
    ```

6. **Crear la base con el rol dueño.** `DATABASE_URL` se pasa solo para este comando; la web sigue usando `radio_app`.

    ```bash
    # Para Symfony (lleva serverVersion y charset)
    export OWNER_URL="postgresql://radio_owner:CLAVE_OWNER@HOST:PUERTO/bdradio?serverVersion=16&charset=utf8&sslmode=require"
    # Para psql y pg_dump, que rechazan serverVersion y charset
    export PG_OWNER="postgresql://radio_owner:CLAVE_OWNER@HOST:PUERTO/bdradio?sslmode=require"

    DATABASE_URL="$OWNER_URL" php bin/console doctrine:migrations:migrate --no-interaction
    ```

    Crea las tablas vacías, la extensión `unaccent` y la función `f_unaccent`. Si falla en `CREATE EXTENSION`, la extensión no está permitida en el proveedor (sección 2, paso 3).

7. **Crear el primer administrador** (la contraseña se pide por consola)

    ```bash
    php bin/console app:crear-usuario admin "Administrador" --admin
    ```

8. **Permisos de archivos y caché.** PHP-FPM (`www-data`) solo necesita escribir en `var/`.

    ```bash
    php bin/console cache:clear
    sudo chown -R www-data:www-data var
    sudo chmod -R u+rwX,g+rX,o-rwx var
    ```

## 4. Configurar Apache y PHP-FPM

Apache publica **únicamente** `/var/www/html/radio/public`. Publicar la raíz del proyecto expone `.env` (con la clave de la base), `var/log` y el código.

Como los proyectos viven dentro de `/var/www/html`, que es el `DocumentRoot` de `000-default`, ningún sitio de Apache debe publicar `/var/www/html` completo: con `000-default` activo, `http://IP-DEL-SERVIDOR/radio/.env` descarga el `.env`. Por eso se deshabilita `000-default` y cada proyecto tiene su propio sitio apuntando a su carpeta `public/`.

**Sitio** `/etc/apache2/sites-available/radio.conf`:

```apache
<VirtualHost *:80>
    ServerName radio.semantica.com.co
    DocumentRoot /var/www/html/radio/public

    <Directory /var/www/html/radio/public>
        AllowOverride None
        Require all granted
        FallbackResource /index.php
    </Directory>

    <FilesMatch \.php$>
        SetHandler "proxy:unix:/run/php/php8.3-fpm.sock|fcgi://localhost"
    </FilesMatch>

    # Ningún archivo oculto (.env, .git...) aunque llegara a estar en public/
    <FilesMatch "^\.">
        Require all denied
    </FilesMatch>

    ErrorLog ${APACHE_LOG_DIR}/radio_error.log
    CustomLog ${APACHE_LOG_DIR}/radio_access.log combined
</VirtualHost>
```

```bash
sudo a2ensite radio && sudo a2dissite 000-default
sudo apachectl configtest && sudo systemctl reload apache2
sudo certbot --apache -d radio.semantica.com.co   # HTTPS y redirección desde http
```

**PHP-FPM**, en `/etc/php/8.3/fpm/pool.d/www.conf`. El límite de procesos es la primera defensa contra la saturación: lo que exceda espera en cola en vez de ahogar la base.

```ini
pm = dynamic
pm.max_children = 10          ; punto de partida: RAM libre para PHP / memoria por proceso
pm.start_servers = 3
pm.min_spare_servers = 2
pm.max_spare_servers = 5
pm.max_requests = 500
request_terminate_timeout = 30s
```

La memoria real por proceso se ve con `ps -o rss= -C php-fpm8.3` bajo carga. Tenga en cuenta también el límite de conexiones del plan de PostgreSQL: `pm.max_children` no debe superarlo.

**PHP de producción**, en `/etc/php/8.3/fpm/conf.d/90-radio.ini`:

```ini
expose_php = Off
display_errors = Off
log_errors = On
opcache.enable = 1
opcache.memory_consumption = 128
opcache.validate_timestamps = 0   ; tras cada despliegue: systemctl reload php8.3-fpm
realpath_cache_size = 4096K
realpath_cache_ttl = 600
```

```bash
sudo systemctl reload php8.3-fpm
```

## 5. Seguridad y protección contra saturación

| Capa | Medida | Estado |
| --- | --- | --- |
| PostgreSQL administrado | Acceso solo desde la IP del servidor, TLS, `statement_timeout = 3s` para `radio_app` | Sección 2 |
| PHP-FPM | `pm.max_children` limita las peticiones simultáneas | Sección 4 |
| Apache | Cabeceras de seguridad y `mod_reqtimeout` contra conexiones lentas | Abajo |
| Firewall | Solo 22, 80 y 443 abiertos | Abajo |
| Cloudflare | Proxy del dominio, regla de límite en `/buscar` (ej. 60 peticiones/min por IP), protección DDoS | Opcional |
| Aplicación | Bloqueo del login tras 5 intentos | Ya existe |
| Aplicación | Límite por IP en la búsqueda, caché de resultados, `trusted_proxies` | **Pendiente en el código** |

**Cabeceras de seguridad**, dentro del `<VirtualHost *:443>` que crea Certbot (`radio-le-ssl.conf`):

```apache
Header always set X-Content-Type-Options "nosniff"
Header always set X-Frame-Options "SAMEORIGIN"
Header always set Referrer-Policy "strict-origin-when-cross-origin"
Header always set Permissions-Policy "camera=(), microphone=(), geolocation=()"
Header always set Strict-Transport-Security "max-age=31536000"
```

Una política de contenido (CSP) queda para después: el sitio usa un script en línea de AssetMapper, Google Fonts, íconos del editor desde un CDN y videos de YouTube, y una CSP incompleta rompe el editor.

**Firewall:**

```bash
sudo ufw allow OpenSSH && sudo ufw allow 'Apache Full' && sudo ufw enable
```

**Si hay Cloudflare u otro proxy delante,** Symfony ve la IP del proxy en lugar de la del visitante, y el bloqueo del login trataría a todos como una sola IP. Antes de activar el proxy hay que configurar `trusted_proxies` en `config/packages/framework.yaml` (pendiente en el código).

**Lo que no debe verse:**

```bash
curl -s -o /dev/null -w "%{http_code}\n" https://radio.semantica.com.co/.env        # 403 o 404
curl -s -o /dev/null -w "%{http_code}\n" https://radio.semantica.com.co/_profiler   # 404
curl -sI https://radio.semantica.com.co/ | grep -i x-debug-token                    # sin salida
```

## 6. Migración de datos desde carbono

Se hace **una sola vez, a mano**, después de la sección 3 y con el rol `radio_owner`. La regla que no se puede romper: **`documento.id` = `documentacion.codigo_documentacion_pk` de carbono**, porque holmio enlaza su ayuda por ese número.

| Origen | Destino (`bdradio`) | Regla |
| --- | --- | --- |
| `proyecto` (MySQL de carbono) | `proyecto` | Mismo `id`; `slug` en minúsculas sin tildes (ej. `semantica`) |
| Menú de teneso (`src/app/_metronic/layout/components/aside/menu.ts`) | `seccion` | Árbol aplicación > menú > grupo, en el proyecto del ERP |
| `documentacion` (MySQL de carbono) | `documento` | Mismo `id`; `titulo`, `modelo` (= `codigo_modelo_fk`), `video`; `seccion_id` = el grupo del menú donde aparece ese id |
| `/documentacion/{id}.md` del servidor de carbono | `documento.contenido` | El contenido vigente; si no hay `.md`, la columna `contenido` de carbono (HTML) |

En desarrollo ya existe una migración de prueba (5 proyectos, 273 secciones, 436 documentos). Para llevarla a producción, exporte **solo** las tres tablas de contenido, nunca `usuario`, que tiene las contraseñas de cada entorno:

```bash
pg_dump --data-only -t proyecto -t seccion -t documento bdradio > documentacion.sql   # en desarrollo
psql "$PG_OWNER" -f documentacion.sql                                                # en producción
```

**Al terminar, reiniciar las secuencias.** Si no se hace, el primer documento creado desde el administrador choca con un id existente.

```sql
SELECT setval(pg_get_serial_sequence('proyecto', 'id'),  (SELECT MAX(id) FROM proyecto)  + 1, false);
SELECT setval(pg_get_serial_sequence('seccion', 'id'),   (SELECT MAX(id) FROM seccion)   + 1, false);
SELECT setval(pg_get_serial_sequence('documento', 'id'), (SELECT MAX(id) FROM documento) + 1, false);
```

**Verificar:**

```sql
-- Documentos con una sección de otro proyecto: debe dar 0
SELECT count(*) FROM documento d JOIN seccion s ON s.id = d.seccion_id WHERE s.proyecto_id <> d.proyecto_id;
-- Totales para comparar con carbono
SELECT (SELECT count(*) FROM proyecto) proyectos, (SELECT count(*) FROM seccion) secciones, (SELECT count(*) FROM documento) documentos;
```

Y abrir `https://radio.semantica.com.co/documento/191` (Recogida) para confirmar que redirige al documento correcto.

## 7. Actualizar a una nueva versión

Antes de subir cambios a `main`, `bin/phpunit` debe pasar en desarrollo (las pruebas no se instalan en producción).

1. Confirmar que el proveedor tiene un respaldo reciente, o crear uno manual (sección 8).
2. `cd /var/www/html/radio && git pull origin main`
3. `composer install --no-dev --optimize-autoloader --classmap-authoritative && composer dump-env prod`
4. `php bin/console asset-map:compile`
5. Migraciones nuevas con el rol dueño: revisar con `DATABASE_URL="$OWNER_URL" php bin/console doctrine:migrations:status` y aplicar con `DATABASE_URL="$OWNER_URL" php bin/console doctrine:migrations:migrate --no-interaction`
6. `php bin/console cache:clear && sudo chown -R www-data:www-data var`
7. `sudo systemctl reload php8.3-fpm` (necesario por `opcache.validate_timestamps = 0`)

**Si algo falla:** `git checkout <commit anterior>` y repetir los pasos 3, 4, 6 y 7. Si una migración ya se aplicó, revertirla con `DATABASE_URL="$OWNER_URL" php bin/console doctrine:migrations:migrate prev`, o restaurar el respaldo.

**Cambios de esquema en desarrollo:** después de modificar una entidad, `php bin/console doctrine:migrations:diff` genera la migración. Se revisa, se prueba y se sube junto con el cambio.

## 8. Respaldos y restauración

El proveedor del PostgreSQL administrado hace los respaldos automáticos. Revise en su panel que estén **activados**, con al menos **7 días de retención** y, si el plan lo permite, recuperación a un momento exacto (PITR). La aplicación no guarda historial de versiones de los documentos, así que el respaldo es la única forma de recuperar contenido borrado o dañado.

**Respaldo manual** (antes de una actualización o de la migración de datos), desde el servidor web:

```bash
pg_dump -Fc "$PG_OWNER" > ~/radio-$(date +%F).dump
```

**Restaurar** un respaldo manual (reemplaza todo el contenido de `bdradio`):

```bash
pg_restore --clean --if-exists --no-owner --role=radio_owner -d "$PG_OWNER" ~/radio-2026-10-20.dump
```

Guarde una copia del `.env` de producción (o al menos `APP_SECRET` y las claves de la base) en un gestor de contraseñas: es lo único que no está ni en GitHub ni en los respaldos.

## 9. Lista de verificación final

- [ ] `.env` con `APP_ENV=prod`, `APP_SECRET` generado con `app:generar-secret` y `DATABASE_URL` con `radio_app` y `sslmode=require`
- [ ] `composer dump-env prod` ejecutado (`.env.local.php` existe)
- [ ] `/.env` y `/_profiler` responden 403 o 404; no aparece `X-Debug-Token`
- [ ] `http://IP-DEL-SERVIDOR/radio/.env` no descarga el archivo (ningún sitio publica `/var/www/html`)
- [ ] Apache publica solo `/var/www/html/radio/public`; `000-default` deshabilitado
- [ ] HTTPS activo y `http://` redirige a `https://`
- [ ] Cabeceras de seguridad presentes (`curl -sI https://radio.semantica.com.co/`)
- [ ] PostgreSQL acepta conexiones solo desde la IP del servidor y con TLS
- [ ] `doctrine:migrations:status` muestra todas las migraciones aplicadas
- [ ] La búsqueda de "guia" encuentra "Guía"
- [ ] Documentos migrados con sus ids: `/documento/191` abre Recogida
- [ ] Secuencias reiniciadas: crear un documento de prueba desde `/admin` y borrarlo
- [ ] Administrador creado; el login se bloquea tras 5 intentos fallidos
- [ ] Respaldos automáticos activados en el proveedor
- [ ] Firewall con 22, 80 y 443
