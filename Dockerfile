FROM php:8.3-cli-alpine

RUN apk add --no-cache \
    curl \
    git \
    unzip \
    libzip-dev \
    oniguruma-dev \
    autoconf \
    build-base \
    linux-headers \
    openssl-dev \
    brotli-dev \
    zlib-dev \
    gzip

RUN docker-php-ext-install \
    pdo_mysql \
    zip \
    mbstring \
    pcntl \
    sockets \
    opcache

RUN pecl install swoole-5.1.3 \
    && docker-php-ext-enable swoole

RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

COPY . /var/www/html

WORKDIR /var/www/html

RUN composer install --no-dev --optimize-autoloader

RUN gzip -d /var/www/html/resources/references.json.gz

RUN php /var/www/html/scripts/train.php

RUN php -m | grep -i swoole
RUN php -i | grep -E "opcache.enable|opcache.enable_cli"

CMD ["php", "server.php"]