FROM php:8.0-fpm

COPY --chown=www-data:www-data . /var/www
COPY docker/php-fpm/php-fpm.conf /etc/php/8.0/fpm/pool.d/www.conf

WORKDIR /var/www