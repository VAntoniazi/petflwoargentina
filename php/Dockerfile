# Dockerfile.php
FROM php:8.3-fpm-alpine AS base

# libs em runtime
RUN apk add --no-cache bash tzdata git curl icu-libs libzip libpng libjpeg-turbo libwebp freetype \
    imagemagick imagemagick-libs

# deps de build
RUN apk add --no-cache --virtual .build-deps $PHPIZE_DEPS icu-dev libzip-dev libpng-dev freetype-dev \
    libjpeg-turbo-dev libwebp-dev imagemagick-dev

# extensões nativas
RUN docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
 && docker-php-ext-install -j$(nproc) \
    pdo_mysql mysqli intl zip gd bcmath exif pcntl opcache

# redis (opcional)
RUN pecl install redis && docker-php-ext-enable redis

# imagick (opcional)
RUN pecl install imagick && docker-php-ext-enable imagick

# limpeza
RUN apk del .build-deps && rm -rf /tmp/* /var/cache/apk/*

# timezone
ENV TZ=America/Sao_Paulo
RUN ln -snf /usr/share/zoneinfo/$TZ /etc/localtime && echo $TZ > /etc/timezone

WORKDIR /var/www/html
EXPOSE 9000
CMD ["php-fpm"]
