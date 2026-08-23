# syntax=docker/dockerfile:1
ARG PHP_VERSION=8.4
FROM php:${PHP_VERSION}-fpm

RUN apt-get update && apt-get install -y --no-install-recommends \
        nginx \
        libsqlite3-dev \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql pdo_sqlite opcache \
    && rm -rf /var/lib/apt/lists/*

COPY docker/benchmark/php-fpm-nginx/php.ini /usr/local/etc/php/conf.d/zz-codekop.ini
COPY docker/benchmark/php-fpm-nginx/www.conf /usr/local/etc/php-fpm.d/zz-codekop.conf
COPY docker/benchmark/php-fpm-nginx/nginx.conf /etc/nginx/sites-enabled/default
COPY docker/benchmark/php-fpm-nginx/start.sh /usr/local/bin/start.sh
COPY docker/benchmark/_shared/jit-entrypoint.sh /usr/local/bin/jit-entrypoint.sh
RUN chmod +x /usr/local/bin/start.sh /usr/local/bin/jit-entrypoint.sh

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
    && chown -R www-data:www-data storage \
    && find storage -type d -exec chmod 750 {} \; \
    && find storage -type f -exec chmod 640 {} \;

EXPOSE 80
ENTRYPOINT ["/usr/local/bin/jit-entrypoint.sh"]
CMD ["/usr/local/bin/start.sh"]
