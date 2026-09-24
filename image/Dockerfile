# OpenCart on the official PHP-FPM Alpine image.
#
# There is no official, vendor or maintained community image for OpenCart
# (Bitnami's stopped being free in 2025). This image puts the official release
# zip's upload/ directory in /usr/src/opencart; the stack's `setup` service
# copies it to the code volume on first run. OpenCart 4.1.0.4 includes fixes
# for PHP 8.5 and for Alpine/musl (GLOB_BRACE emulation).

ARG PHP_VERSION=8.5
FROM php:${PHP_VERSION}-fpm-alpine

# PHP extensions required by OpenCart (curl, openssl, zlib and opcache are
# already in the base image).
RUN set -eux; \
    apk add --no-cache su-exec; \
    apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS freetype-dev libjpeg-turbo-dev libpng-dev libwebp-dev libzip-dev; \
    docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp; \
    docker-php-ext-install -j"$(nproc)" gd mysqli zip; \
    runDeps="$( \
        scanelf --needed --nobanner --format '%n#p' --recursive /usr/local/lib/php/extensions \
            | tr ',' '\n' | sort -u \
            | awk 'system("[ -e /usr/local/lib/" $1 " ]") == 0 { next } { print "so:" $1 }' \
    )"; \
    apk add --no-cache --virtual .opencart-rundeps $runDeps; \
    apk del --no-network .build-deps; \
    php -m | grep -qi -e '^gd$' -e '^mysqli$'

COPY opcache.ini /usr/local/etc/php/conf.d/opcache-recommended.ini

# OpenCart release: https://github.com/opencart/opencart/releases
# OC_SHA256 verifies the zip; leave it empty to skip the check.
ARG OC_VERSION=4.1.0.4
ARG OC_SHA256=cdef537f5a0a7e1b4ddbe16f589dd2e80b10150a09bfbe93ce76ef2d5c3218c0
RUN set -eux; \
    apk add --no-cache --virtual .fetch-deps unzip; \
    curl -fsSL -o /tmp/opencart.zip \
        "https://github.com/opencart/opencart/releases/download/${OC_VERSION}/opencart-${OC_VERSION}.zip"; \
    if [ -n "${OC_SHA256}" ]; then echo "${OC_SHA256}  /tmp/opencart.zip" | sha256sum -c -; fi; \
    unzip -q /tmp/opencart.zip 'upload/*' -d /tmp/opencart; \
    mv /tmp/opencart/upload /usr/src/opencart; \
    rm -rf /tmp/opencart /tmp/opencart.zip; \
    chown -R www-data:www-data /usr/src/opencart; \
    apk del --no-network .fetch-deps

# Mount points for the code and storage volumes, owned by www-data.
RUN mkdir -p /var/www/storage && chown www-data:www-data /var/www/html /var/www/storage

ENV OC_VERSION=${OC_VERSION}
WORKDIR /var/www/html
