# PRI Facilities Request: image for the Radiant tiers (drez-containerize, PHP track, D-003).
# The app runs on PostgreSQL (D-013). In the image it reads every setting from the
# tier's environment file: TIER=test or live switches config.php, config.local.json
# and pg.env off (facilities/lib/serverenv.php, env.template.txt), and AUTH_MODE=proxy
# takes the NetID from the university's authenticating proxy (D-014).
# Storage (uploads, imports, GIS extracts) lives in /var/www/html/storage, a named
# volume per tier in compose.yaml.
# Image for a PHP web application with PDO on PostgreSQL or SQLite.
# Settings arrive from the tier's environment file (compose.yaml env_file);
# config.php, data files and installer lock files never enter the image.
# Pinned to the PHP the app runs on this computer (tools/php, 8.3.33) and to one
# Debian release, so a rebuild on Radiant never moves PHP or the OS underneath it.
FROM php:8.3.33-apache-trixie

# PDO drivers: pdo_pgsql for PostgreSQL on Radiant, pdo_sqlite for an app
# still on SQLite. The official image builds pdo_sqlite in already; the last
# command fails the build if either driver is missing.
RUN apt-get update \
 && apt-get install -y --no-install-recommends libpq-dev \
 && docker-php-ext-install pdo_pgsql \
 && rm -rf /var/lib/apt/lists/* \
 && php -m | grep -qi '^pdo_pgsql$' \
 && php -m | grep -qi '^pdo_sqlite$'

# Production php.ini: errors logged, never shown to the browser.
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

# Serve the app's document root on port 8080, so Apache runs entirely as
# www-data and never as root.
ENV APACHE_DOCUMENT_ROOT=/var/www/html/facilities
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
 && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf \
 && sed -ri -e 's/^Listen 80$/Listen 8080/' /etc/apache2/ports.conf \
 && sed -ri -e 's/<VirtualHost \*:80>/<VirtualHost *:8080>/' /etc/apache2/sites-available/000-default.conf \
 && a2enmod rewrite headers

# Code only; .dockerignore keeps data, config.php and lock files out.
COPY --chown=www-data:www-data . /var/www/html/

# storage/ is a named volume per tier (compose.yaml). .dockerignore keeps it out of
# the context, so without this line Docker creates the mount point as root and
# www-data cannot write a single upload. A fresh named volume copies this owner.
# runtime/ holds the session files (runtime_dir() in facilities/lib/paths.php).
RUN install -d -o www-data -g www-data -m 0750 /var/www/html/storage /var/www/html/runtime

USER www-data
EXPOSE 8080

# health.php?db=1 answers 503 when the database is unreachable, and
# file_get_contents returns false on any status other than 2xx.
HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
  CMD ["php", "-r", "exit(@file_get_contents('http://127.0.0.1:8080/health.php?db=1') === false ? 1 : 0);"]
