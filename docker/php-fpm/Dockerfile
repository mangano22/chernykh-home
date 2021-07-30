FROM php:8.0-fpm-alpine3.14

RUN apk update \
    && apk --no-cache add \
    php8-pdo_pgsql \
    php8-intl

COPY --chown=www-data:www-data . /var/www
COPY docker/php-fpm/php-fpm.conf /usr/local/etc/php-fpm.d/www.conf
COPY docker/php-fpm/99-custom.ini /usr/local/etc/php/conf.d/99-custom.ini

WORKDIR /var/www