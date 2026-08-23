# syntax=docker/dockerfile:1
ARG PHP_VERSION=8.4
FROM php:${PHP_VERSION}-cli

RUN apt-get update && apt-get install -y --no-install-recommends \
        libsqlite3-dev \
        libssl-dev \
        libbrotli-dev \
        zlib1g-dev \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql pdo_sqlite opcache \
    && pecl install swoole \
    && docker-php-ext-enable swoole \
    && rm -rf /var/lib/apt/lists/*

COPY docker/benchmark/swoole/php.ini /usr/local/etc/php/conf.d/zz-codekop.ini
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
COPY docker/benchmark/swoole/server.php ./swoole-server.php

RUN composer dump-autoload --no-dev --optimize \
    && mkdir -p storage/sessions storage/queue/pending storage/queue/processing storage/queue/failed \
    && find storage -type d -exec chmod 770 {} \; \
    && find storage -type f -exec chmod 660 {} \;

EXPOSE 80
ENTRYPOINT ["/usr/local/bin/jit-entrypoint.sh"]
CMD ["php", "swoole-server.php"]
