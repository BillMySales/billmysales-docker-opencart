OpenCart Docker stack
=====================

Docker Compose stack for an OpenCart store, usable for local development and
for simple production deployments (a single server, better than shared
hosting). Maintained by [BillMySales](https://www.billmysales.com).

| Component  | Image                                        | Default version   |
|------------|----------------------------------------------|-------------------|
| Web server | `caddy:<ver>-alpine`                         | 2.11              |
| OpenCart   | built from `image/` (`php:<ver>-fpm-alpine`) | 4.1.0.4 / PHP 8.5 |
| Database   | `mariadb`                                    | 12.3 (LTS)        |
| Mailpit    | `axllent/mailpit` (optional, dev)            | v1.31             |

There is no official, vendor or maintained community Docker image for
OpenCart (Bitnami's stopped being free in 2025, and the Dockerfile bundled in
the release zip doesn't build and uses Apache with a fixed old PHP).
`image/Dockerfile` puts the official release zip on `php:<ver>-fpm-alpine`,
verified with SHA-256 (151 MB). OpenCart 4.1.0.4 includes fixes for PHP 8.5
and for Alpine/musl.

Requirements
------------

- Docker Engine 24+ with the Compose v2 plugin (`docker compose`, 2.24+).
- Development: ports 8104, 8404 and 8025 free on the host.
- Production: a server with ports 80 and 443 reachable, and a DNS record for
  the store's domain pointing to it.
- The first `up` builds the image (PHP extensions are compiled).

Quick start (development)
-------------------------

```shell
cp .env.dev.example .env
docker compose up -d
docker compose logs -f setup   # wait for "==> Done"
```

- Store: http://localhost:8104
- Back office: http://localhost:8104/admin-dev/ (user `admin`, password `admin12345`)
- Mailpit (every email the store sends): http://localhost:8025

Production
----------

```shell
cp .env.prod.example .env
# Fill in OC_URL, SITE_ADDRESS, DB_PASSWORD, DB_ROOT_PASSWORD,
# OC_ADMIN_PASSWORD, OC_ADMIN_EMAIL, OC_ADMIN_DIR and the SMTP_* values.
docker compose up -d
```

- With `SITE_ADDRESS` set to the domain, Caddy gets a Let's Encrypt certificate
  and renews it automatically (certificates live in the `caddy_data` volume).
- Behind an existing Traefik (no host ports), use `overrides/traefik.yaml`
  (see [Overrides](#overrides)).
- Compose refuses to start while a required value is missing.
- Use a hard-to-guess back office directory (`OC_ADMIN_DIR`).
- The `backup` profile is enabled by default in the production template.

Services
--------

| Service    | Profile   | Role                                                          |
|------------|-----------|---------------------------------------------------------------|
| `db`       |           | MariaDB, data in the `db_data` volume.                        |
| `opencart` |           | PHP-FPM + OpenCart (port 9000, internal).                     |
| `caddy`    |           | Web server and TLS, the only published ports (80, 443).       |
| `setup`    |           | One-shot job (`scripts/setup.sh`), runs on every `up`.        |
| `cron`     |           | Runs OpenCart's `cron.php` every `CRON_INTERVAL` seconds.     |
| `backup`   | `backup`  | DB dump + store files archive on a schedule.                  |
| `mailpit`  | `mailpit` | Development SMTP server that catches all mail.                |

Volumes:

| Volume    | Mounted at          | Contents                                                     |
|-----------|---------------------|--------------------------------------------------------------|
| `code`    | `/var/www/html`     | OpenCart (web root): code, images, extensions.               |
| `storage` | `/var/www/storage`  | Cache, logs, sessions, downloads, uploads and Composer       |
|           |                     | libraries, outside the web root.                             |
| `db_data` | `/var/lib/mysql`    | Database.                                                    |

### What `setup` does

- Copies OpenCart from the image to the `code` volume if it is empty.
- Empty database: runs OpenCart's CLI installer (`install/cli_install.php`),
  deletes the `install` directory and moves `system/storage` to the `storage`
  volume. OpenCart's own security check (Dashboard) then reports nothing.
- Renames the back office directory to `OC_ADMIN_DIR` (change it any time).
- On every run, writes `config.php` and `<admin>/config.php` from the
  environment (URL, back office directory, database, storage path), sets
  error display from `OC_DEBUG` and, when `SMTP_HOST` is set, the mail
  settings from `SMTP_*`.
- Once: store name and title, country and zone, timezone, SEO URLs, and the
  currency (`OC_CURRENCY`, created if missing, made default and the only one
  enabled: the sample currencies' rates are relative to USD); stores
  `docker_stack_initialized`, so later changes in the back office are kept.
- Clears the cache only when something changed.

Note: OpenCart's installer always loads its sample catalog (categories,
products, manufacturers); there's no option to skip it. Delete it from the
back office if you don't need it.

Common commands
---------------

```shell
docker compose ps                               # every service "healthy", setup "Exited (0)"
docker compose logs -f caddy opencart cron      # web server, PHP and cron logs
docker compose exec opencart sh                 # shell in the PHP container
docker compose exec db mariadb -u opencart -p opencart   # SQL shell
docker compose down                             # stop, keep data
docker compose down -v                          # stop and DELETE all data
```

Backups
-------

With the `backup` profile, the `backup` service writes
`<timestamp>-db.sql.gz` and `<timestamp>-files.tar.gz` (code, images,
extensions and storage, without caches and sessions) to the `backups` volume
(or `./data/backups` with `overrides/local-dirs.yaml`) at start and then every
`BACKUP_INTERVAL_HOURS`, and deletes files older than `BACKUP_KEEP_DAYS`.
Files are readable by their owner only.

```shell
docker compose run --rm --no-deps backup now                  # back up now
docker compose run --rm --no-deps backup list                 # list timestamps
docker compose stop opencart cron                   # recommended while restoring
docker compose run --rm --no-deps backup restore <timestamp>  # restore DB and store files
docker compose start opencart cron
```

`--no-deps` keeps the command from starting `setup` first (with damaged
data `setup` fails and the restore would never run); the database must
be running (`docker compose up -d db` if the stack is down).

Overrides
---------

Optional compose files in `overrides/`, enabled with `COMPOSE_FILE` in `.env`
(several are combined with `:`). Each file documents its variables.

```shell
COMPOSE_FILE=compose.yaml:overrides/traefik.yaml:overrides/local-dirs.yaml
```

| File                          | Purpose                                                           |
|-------------------------------|-------------------------------------------------------------------|
| `overrides/traefik.yaml`      | Publish through an existing Traefik on a shared external network: |
|                               | no host ports, Traefik terminates TLS (`TRAEFIK_HOST`, ...).      |
| `overrides/local-dirs.yaml`   | Database, code, storage, Caddy and backups in local directories   |
|                               | (`DATA_DIR`, default `./data`) instead of named volumes.          |
| `overrides/extension.yaml`    | Mount an extension into `extension/` from a local directory,      |
|                               | editable live (`EXTENSION_PATH`, `EXTENSION_NAME`).               |

A local `compose.override.yaml` (gitignored) is also loaded automatically by
Docker Compose, for changes specific to one machine.

Configuration
-------------

Every variable is documented in `.env.prod.example`. Main groups:

- **Site and network**: `OC_URL`, `SITE_ADDRESS`, `HTTP_BIND`, `HTTP_PORT`,
  `HTTPS_PORT`.
- **Credentials and back office**: `DB_PASSWORD`, `DB_ROOT_PASSWORD`,
  `OC_ADMIN_PASSWORD`, `OC_ADMIN_EMAIL`, `OC_ADMIN_DIR` (required),
  `OC_ADMIN_USER`.
- **Store** (first install only): `OC_STORE_NAME`, `OC_COUNTRY`, `OC_ZONE`,
  `OC_CURRENCY`, `OC_CURRENCY_TITLE`; `PHP_TIMEZONE`.
- **Versions**: `OC_VERSION` + `OC_SHA256`, `PHP_VERSION`, `OC_IMAGE`,
  `CADDY_VERSION`, `MARIADB_VERSION`.
- **OpenCart / PHP**: `OC_DEBUG`, `PHP_DISPLAY_ERRORS`, `PHP_MEMORY_LIMIT`,
  `UPLOAD_MAX_SIZE` (PHP and Caddy), `PHP_FPM_MAX_CHILDREN` and the rest of the
  FPM pool.
- **Mail**: `SMTP_HOST`, `SMTP_PORT`, `SMTP_SECURE`, `SMTP_USER`,
  `SMTP_PASSWORD` (OpenCart's SMTP client requires a username and password),
  `SMTP_FROM` (store email address).
- **Cron**: `CRON_INTERVAL` (seconds, default 300; each job has its own cycle
  in System > Maintenance > Cron Jobs).
- **Resources and logs**: `*_MEMORY_LIMIT` per service, `LOG_MAX_SIZE`,
  `LOG_MAX_FILE` (Docker log rotation).

Files:

| File                                    | Purpose                                                      |
|-----------------------------------------|--------------------------------------------------------------|
| `image/Dockerfile`, `image/opcache.ini` | OpenCart image.                                              |
| `config/caddy/Caddyfile`                | Web server, TLS, SEO URLs, security headers, blocked paths.  |
| `config/php/php.ini`                    | PHP limits, timezone, sessions, errors.                      |
| `config/php/fpm-pool.conf`              | PHP-FPM pool sizing, from env vars.                          |
| `scripts/setup.sh`                      | Copy, install, storage, back office directory.               |
| `scripts/conf.php`                      | Writes both `config.php` files from the environment.         |
| `scripts/configure.php`                 | Settings (errors, SMTP, one-time store settings).            |
| `scripts/db.php`                        | Install detection.                                           |
| `scripts/cron.sh`, `scripts/cron-autoload.php` | Cron (with a workaround, see below).                  |
| `scripts/backup.sh`                     | Backups and restore.                                         |

Notes:

- The `config.php` files are generated: change the environment (`.env`), not
  the files.
- OpenCart upgrades itself from the back office (Maintenance > Upgrade), which
  updates the files in the `code` volume; `OC_VERSION` only matters when the
  volume is created. Back up first.
- OpenCart 4.1.0.4's `cron.php` doesn't load the Composer autoloader, so every
  cron job fails with `Class "Twig\Loader\FilesystemLoader" not found`. The
  `cron` service loads it first (`scripts/cron-autoload.php` as
  `auto_prepend_file`) without changing OpenCart's files. When bumping
  `OC_VERSION`, check whether `cron.php` loads the autoloader and remove the
  workaround if so.
- The home page title is `meta_title` inside `config_description` (JSON per
  language), not `config_meta_title`.
- OpenCart's admin "Forgotten password" never works (4.1.0.4, and still in
  `master` on 2026-09-25): it always answers "The E-Mail Address was not
  found in our records!", even for an existing admin email.
  `admin/controller/common/forgotten.php` builds its input with
  `$post_info = ['email' => ''] + $this->request->post;`, and PHP's array
  union keeps the left-hand value, so the email is always empty (it should
  be `$this->request->post + ['email' => '']`). Not worked around (the code
  lives in the volume and OpenCart updates itself): change an admin's
  password in the back office (Users) or reset it in the database.
- `.htaccess` files are ignored; the equivalent rules (SEO URLs, blocked
  templates and settings files) are in the Caddyfile, translated from
  OpenCart's `.htaccess.txt`, plus `system/`, `install/`, both `config.php`
  files, `cron.php` and `php.ini` blocked.
- Only English is installed (the installer's only language besides French);
  other languages are extensions from the OpenCart marketplace.
- From inside the containers, the host machine is reachable as
  `host.docker.internal`.

Security
--------

- Client IP headers: PHP gets only the real client IP (as Caddy sees it) in
  `REMOTE_ADDR`, `X-Forwarded-For` and `X-Real-IP`, and no `Client-Ip`,
  `Cf-Connecting-Ip` or `X-Forwarded-Port` (a client could forge them): OpenCart takes the client IP
  from `Cf-Connecting-Ip`, `X-Forwarded-For`, `X-Real-IP` or `Client-Ip`, and
  stores `X-Forwarded-For` with each order.
- No default secrets: compose fails if the required passwords are missing. The
  development template uses public passwords; never use it on a server.
- PHP and OpenCart errors are never shown to visitors (`display_errors` and
  `config_error_display` off unless enabled in the development template); PHP
  errors go to `docker compose logs`, OpenCart's to its error log.
- Production defaults: back office in a custom directory, `install` removed,
  storage outside the web root, `config.php` files read-only, PHP version not
  exposed, core and configuration files blocked, `X-Content-Type-Options`,
  `X-Frame-Options` and `Referrer-Policy` headers.
- PHP gets the real client IP in `REMOTE_ADDR` (logs, login protection) also
  behind Traefik or another proxy on a private network.
- Only Caddy (and Mailpit in development) publishes ports; the database is
  internal. `HTTP_BIND` defaults to `127.0.0.1`.
- Not included: a web application firewall, login rate limiting, or off-site
  backup copies.

Validation
----------

What was checked for this stack (2026-09-24):

- Clean start (`down -v` + `up -d`, image already built) in about 15 s: every
  service `healthy`, `setup` `Exited (0)`; a second run makes no changes.
- Storefront and SEO URLs (`/en-gb/catalog/cameras`, `/en-gb/product/iphone`)
  `200`; back office login; OpenCart's security check empty; blocked paths
  `403`; CLP, Chile and store name applied.
- A setting changed in the back office survives `setup`; changing `OC_URL`
  rewrites both `config.php` files.
- SMTP delivered to Mailpit; cron jobs run (currency, GDPR); backup, retention
  and restore.
- HTTPS with `SITE_ADDRESS=localhost` (Caddy internal CA, HTTP/2);
  production defaults (errors hidden, random back office directory).
- Overrides: Traefik v3.6 routing with no host ports, local directories
  (including backups), an extension mounted and served.
- Not tested: issuing a real Let's Encrypt certificate (needs a public domain),
  and the back office upgrade (4.1.0.4 is the latest release).

Resource usage
--------------

Idle, after a few requests: Caddy ~17 MiB, PHP-FPM ~20–40 MiB, MariaDB
~120 MiB, cron and backup ~1 MiB between runs.

License
-------

[MIT](LICENSE).
