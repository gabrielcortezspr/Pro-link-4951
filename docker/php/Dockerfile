FROM php:8.2-fpm-alpine

RUN apk add --no-cache git unzip icu-dev oniguruma-dev libzip-dev $PHPIZE_DEPS \
 && docker-php-ext-install pdo_mysql mbstring intl zip opcache \
 && pecl install redis && docker-php-ext-enable redis \
 && apk del $PHPIZE_DEPS

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY docker/php/php.ini /usr/local/etc/php/conf.d/prolink.ini

RUN adduser -D -u 1000 prolink \
 && mkdir -p /var/www/html/storage/cache /var/www/html/storage/logs \
 && chown -R prolink:prolink /var/www/html

USER prolink
