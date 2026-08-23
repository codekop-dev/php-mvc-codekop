# syntax=docker/dockerfile:1
ARG PHP_VERSION=8.4
FROM dunglas/frankenphp:php${PHP_VERSION}

RUN apt-get update && apt-get install -y --no-install-recommends \
        libsqlite3-dev \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql pdo_sqlite opcache \
    && rm -rf /var/lib/apt/lists/*

COPY docker/benchmark/frankenphp/php.ini /usr/local/etc/php/conf.d/zz-codekop.ini
COPY docker/benchmark/frankenphp/Caddyfile /etc/frankenphp/Caddyfile
COPY docker/benchmark/_shared/jit-entrypoint.sh /usr/local/bin/jit-entrypoint.sh
RUN chmod +x /usr/local/bin/jit-entrypoint.sh

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY composer.json composer.lock ./
RUN composer install \
        --no-dev \
        --no-interaction \
        --no-progress \
        --no-scripts \
        --optimize-autoloader

COPY . .

RUN composer dump-autoload --no-dev --optimize \
    && mkdir -p storage/sessions storage/queue/pending storage/queue/processing storage/queue/failed \
    && find storage -type d -exec chmod 770 {} \; \
    && find storage -type f -exec chmod 660 {} \;

EXPOSE 80
ENTRYPOINT ["/usr/local/bin/jit-entrypoint.sh"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]
