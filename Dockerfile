FROM php:8.3-fpm-alpine

# system deps
RUN apk add --no-cache git unzip libpq-dev bash

# PHP extensions
RUN docker-php-ext-install pdo pdo_pgsql

# Composer
COPY --from=composer:2.7 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Install PHP deps (will run again after mount, but this speeds first build)
COPY composer.json /var/www/html/composer.json
RUN composer install --no-interaction --no-scripts --prefer-dist

# Dev helpers
RUN echo "date.timezone=UTC" > /usr/local/etc/php/conf.d/timezone.ini

CMD ["php-fpm", "-F"]

