from php:8.3-fpm-alpine

RUN apk add --no-cache \
    curl \
    git \
    unzip \
    libzip-dev \
    oniguruma-dev \
    autoconf \
    build-base \
    gzip

RUN docker-php-ext-install \
    pdo_mysql \
    zip \
    mbstring \
    pcntl
    
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

COPY . /var/www/html

WORKDIR /var/www/html

RUN composer install --no-dev --optimize-autoloader

RUN chown -R www-data:www-data /var/www/html

RUN gzip -d /var/www/html/resources/references.json.gz

RUN php /var/www/html/scripts/train.php
