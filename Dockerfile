FROM php:8.2-apache-bookworm AS php-extensions

# Debian package revisions follow the pinned Bookworm base image security updates.
# hadolint ignore=DL3008
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libfreetype6-dev \
        libicu-dev \
        libjpeg62-turbo-dev \
        libonig-dev \
        libpng-dev \
        libzip-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath \
        gd \
        intl \
        mbstring \
        mysqli \
        opcache \
        pdo_mysql \
        zip \
    && rm -rf /var/lib/apt/lists/*

FROM php:8.2-apache-bookworm AS runtime

ENV TZ=Asia/Shanghai

# Keep only the shared libraries required by the compiled PHP extensions.
# hadolint ignore=DL3008
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libfreetype6 \
        libicu72 \
        libjpeg62-turbo \
        libonig5 \
        libpng16-16 \
        libzip4 \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

COPY --from=php-extensions /usr/local/lib/php/extensions/ /usr/local/lib/php/extensions/
COPY --from=php-extensions /usr/local/etc/php/conf.d/ /usr/local/etc/php/conf.d/

WORKDIR /var/www/html

COPY . /var/www/html
COPY docker/apache-vhost.conf /etc/apache2/sites-available/000-default.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/loopdeck.ini
COPY docker/entrypoint.sh /usr/local/bin/loopdeck-entrypoint

RUN cp -a /var/www/html/config /opt/loopdeck-config \
    && sed -i 's/\r$//' /usr/local/bin/loopdeck-entrypoint \
    && chmod 0755 /usr/local/bin/loopdeck-entrypoint

# The single-quoted script is intentionally evaluated by PHP, not the shell.
# hadolint ignore=SC2016
RUN php -r '$required = ["curl", "fileinfo", "gd", "intl", "json", "mbstring", "mysqli", "openssl", "pdo_mysql", "sodium", "zip"]; foreach ($required as $extension) { if (!extension_loaded($extension)) { fwrite(STDERR, "Missing PHP extension: {$extension}\n"); exit(1); } }'

EXPOSE 80

ENTRYPOINT ["loopdeck-entrypoint"]
CMD ["apache2-foreground"]
