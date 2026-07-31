# syntax=docker/dockerfile:1.7

FROM serversideup/php:8.2-fpm-nginx-alpine@sha256:57919c0ed10e91318b87c518f4a2e25e98a259086d440955f692b095734c0508 AS runtime

ENV TZ=Asia/Shanghai \
    PHP_DATE_TIMEZONE=Asia/Shanghai \
    PHP_MAX_EXECUTION_TIME=300 \
    PHP_MEMORY_LIMIT=256M \
    PHP_POST_MAX_SIZE=20M \
    PHP_UPLOAD_MAX_FILE_SIZE=20M \
    PHP_OPCACHE_ENABLE=1 \
    PHP_OPCACHE_VALIDATE_TIMESTAMPS=0 \
    PHP_REALPATH_CACHE_TTL=600 \
    NGINX_CLIENT_MAX_BODY_SIZE=20M \
    SHOW_WELCOME_MESSAGE=false

USER 0

# The base image removes transient build packages after extension installation.
# hadolint ignore=DL3018
RUN apk add --no-cache tzdata \
    && install-php-extensions \
        bcmath \
        gd \
        intl \
        mysqli \
    && rm -rf /tmp/* /var/cache/apk/*

WORKDIR /var/www/html

COPY --chown=82:82 . /var/www/html
COPY --chown=root:root --chmod=0755 docker/entrypoint.sh /etc/entrypoint.d/50-loopdeck.sh
COPY --chown=root:root --chmod=0644 docker/nginx-security.conf /etc/nginx/server-opts.d/loopdeck-security.conf
COPY --chown=root:root --chmod=0644 docker/php.ini /usr/local/etc/php/conf.d/zz-loopdeck.ini

RUN cp -a /var/www/html/config /opt/loopdeck-config \
    && mkdir -p \
        /var/lib/loopdeck/config \
        /var/lib/loopdeck/runtime \
        /var/lib/loopdeck/sessions \
        /var/lib/loopdeck/uploads \
        /var/www/html/runtime \
        /var/www/html/public/static/uploads \
    && cp -a /opt/loopdeck-config/. /var/lib/loopdeck/config/ \
    && chown -R 82:82 /opt/loopdeck-config /var/lib/loopdeck /var/www/html/runtime /var/www/html/public/static/uploads

# Fail the remote build immediately if a required runtime extension is absent.
# The single-quoted script is intentionally evaluated by PHP, not the shell.
# hadolint ignore=SC2016
RUN php -r '$required = ["bcmath", "curl", "fileinfo", "gd", "intl", "json", "mbstring", "mysqli", "openssl", "pdo_mysql", "sodium", "zip"]; foreach ($required as $extension) { if (!extension_loaded($extension)) { fwrite(STDERR, "Missing PHP extension: {$extension}\n"); exit(1); } }'

USER 82:82

EXPOSE 8080
