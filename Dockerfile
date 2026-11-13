# syntax=docker/dockerfile:1
#
# ECOING - Inspección Ligera · imagen `ecoing-app`
# ================================================
#
# Apache 2.4 + PHP 8.2 (mod_php) sirviendo `web/` y `api/`.
#
# ARQUITECTURA
# ------------
# El QNAP TS-932PX usa un Annapurna Labs AL324 (ARM Cortex-A57, ARMv8 64 bits).
# Todas las imágenes base de este archivo son multiarquitectura y publican
# `linux/arm64/v8`; ninguna es exclusiva de amd64:
#
#   · composer:2.8       -> linux/amd64, linux/arm64/v8, linux/arm/v7, ...
#   · php:8.2-apache     -> linux/amd64, linux/arm64/v8, linux/arm/v7, ...
#
# Para construir explícitamente para el NAS desde un PC x86:
#   docker buildx build --platform linux/arm64 -t ecoing-app:1.0 .
#
# EXTENSIONES
# -----------
# Solo se compila lo que el código usa de verdad (auditado en `api/` y en
# `api/composer.lock`):
#
#   pdo_mysql  -> api/config/database.php (PDO, no mysqli: mysqli NO se instala)
#   gd         -> saveUploadedImage(), FPDF, PhpSpreadsheet
#   exif       -> orientación EXIF en saveUploadedImage() y utils_imagen.php
#   zip        -> ZipArchive en exportar_linea_pdf.php + PhpSpreadsheet
#   opcache    -> no lo pide el código; reduce a la mitad el arranque de PHP en ARM
#
# Ya vienen habilitadas en la imagen oficial y no hace falta instalarlas:
#   mbstring, fileinfo, iconv, ctype, filter, json, zlib,
#   dom, libxml, simplexml, xml, xmlreader, xmlwriter
#
# `intl` NO se instala: PhpSpreadsheet solo lo sugiere para el asistente de
# formatos numéricos y este proyecto no lo usa.

# ---------------------------------------------------------------------------
# Etapa 1 · dependencias PHP
# ---------------------------------------------------------------------------
FROM composer:2.8 AS vendor

WORKDIR /build
COPY api/composer.json api/composer.lock ./

# --ignore-platform-reqs: la imagen de Composer no trae gd/zip, pero la imagen
# final sí. Las versiones quedan fijadas por composer.lock.
RUN composer install \
        --no-dev \
        --no-interaction \
        --no-progress \
        --prefer-dist \
        --optimize-autoloader \
        --classmap-authoritative \
        --ignore-platform-reqs

# ---------------------------------------------------------------------------
# Etapa 2 · runtime
# ---------------------------------------------------------------------------
FROM php:8.2-apache-bookworm AS runtime

LABEL org.opencontainers.image.title="ECOING Inspección Ligera" \
      org.opencontainers.image.description="API REST + web administrativa (Apache 2.4 / PHP 8.2)" \
      org.opencontainers.image.vendor="ECOING" \
      org.opencontainers.image.licenses="proprietary"

ENV APACHE_DOCUMENT_ROOT=/var/www/html \
    PHP_INI_DIR=/usr/local/etc/php \
    COMPOSER_ALLOW_SUPERUSER=1

# Cabeceras de desarrollo para compilar gd y zip. Se retiran al terminar; las
# librerías de runtime que las extensiones acaben necesitando se detectan con
# `ldd` y se marcan como manuales para que `--auto-remove` no se las lleve.
# Es el mismo patrón que usan las imágenes oficiales de PHP.
RUN set -eux; \
    savedAptMark="$(apt-mark showmanual)"; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        libfreetype6-dev \
        libjpeg62-turbo-dev \
        libpng-dev \
        libwebp-dev \
        libzip-dev \
    ; \
    docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp; \
    docker-php-ext-install -j"$(nproc)" \
        gd \
        zip \
        exif \
        pdo_mysql \
        opcache \
    ; \
    apt-mark auto '.*' > /dev/null; \
    apt-mark manual $savedAptMark > /dev/null; \
    ldd "$(php -r 'echo ini_get("extension_dir");')"/*.so \
        | awk '/=>/ { so = $(NF-1); if (index(so, "/usr/local/") == 1) next; gsub("^/(usr/)?", "", so); printf "*/%s\n", so }' \
        | sort -u \
        | xargs -r dpkg-query --search 2>/dev/null \
        | cut -d: -f1 \
        | sort -u \
        | xargs -r apt-mark manual \
    ; \
    apt-get purge -y --auto-remove -o APT::AutoRemove::RecommendsImportant=false; \
    rm -rf /var/lib/apt/lists/*; \
    \
    # Fallar aquí, en la construcción, y no en el primer arranque: sin estas
    # extensiones el sistema no funciona, y sin curl el HEALTHCHECK miente.
    php -m; \
    php -r 'foreach (["gd","zip","exif","pdo_mysql","mbstring","fileinfo","iconv","dom","simplexml","xmlreader","xmlwriter","ctype","filter","zlib","json","Zend OPcache"] as $e) { if (!extension_loaded($e)) { fwrite(STDERR, "FALTA la extension $e\n"); exit(1); } } echo "extensiones PHP verificadas\n";'; \
    command -v curl > /dev/null || { echo 'FALTA curl (lo necesita el HEALTHCHECK)' >&2; exit 1; }

# Configuración de PHP y de Apache. `.user.ini` del repositorio solo funciona
# con CGI/FPM, así que aquí los límites viven en un .ini real de mod_php.
COPY docker/php/ecoing.ini "$PHP_INI_DIR/conf.d/zz-ecoing.ini"
COPY docker/apache/ecoing.conf /etc/apache2/sites-available/000-default.conf
COPY docker/apache/security.conf /etc/apache2/conf-available/ecoing-security.conf

# Se desactivan mod_status (expone métricas del servidor) y mod_autoindex
# (listaría directorios). `|| true` porque si una versión de la imagen base no
# los trae habilitados, a2dismod devuelve error y no es motivo para fallar.
RUN set -eux; \
    a2enconf ecoing-security; \
    a2dismod -f status autoindex || true; \
    rm -f /etc/apache2/conf-enabled/other-vhosts-access-log.conf

# Código de la aplicación. Solo `web/` y `api/` entran en el DocumentRoot:
# `database/`, `scripts/`, `docs/` y `pruebaoffline/` quedan fuera de la imagen
# (ver .dockerignore) y se usan desde el host.
COPY --chown=www-data:www-data web/ /var/www/html/web/
COPY --chown=www-data:www-data api/ /var/www/html/api/
COPY --from=vendor --chown=www-data:www-data /build/vendor/ /var/www/html/api/vendor/

# Puntos de montaje del NAS. El entrypoint los crea y ajusta permisos si el
# volumen llega vacío; nunca deben quedar en el filesystem efímero.
RUN set -eux; \
    mkdir -p /data/imagenes /data/pdf /data/backups/db /data/logs /data/exports; \
    chown -R www-data:www-data /data; \
    chmod -R 0775 /data

# Validar la configuración de Apache aquí, con las carpetas de la aplicación ya
# copiadas. Un error de sintaxis en el vhost debe romper la construcción, no
# descubrirse cuando el contenedor no arranca en el NAS.
RUN apache2ctl configtest

COPY docker/entrypoint.sh /usr/local/bin/ecoing-entrypoint
# El repositorio se edita en Windows: si el archivo llega con CRLF, el kernel
# buscaría el intérprete "/usr/bin/env bash\r". `.gitattributes` ya fuerza LF;
# esto lo garantiza también cuando el contexto de build se copia a mano.
RUN sed -i 's/\r$//' /usr/local/bin/ecoing-entrypoint \
 && chmod +x /usr/local/bin/ecoing-entrypoint \
 && bash -n /usr/local/bin/ecoing-entrypoint

VOLUME ["/data"]
EXPOSE 80

HEALTHCHECK --interval=30s --timeout=10s --start-period=40s --retries=3 \
    CMD curl -fsS -o /dev/null http://127.0.0.1/api/health.php || exit 1

ENTRYPOINT ["/usr/local/bin/ecoing-entrypoint"]
CMD ["apache2-foreground"]
