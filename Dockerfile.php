FROM php:8.2-fpm

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        git \
        unzip \
        libpq-dev \
    ; \
    rm -rf /var/lib/apt/lists/*

# habilita MySQL + Postgres (resolve "could not find driver" do pgsql)
RUN docker-php-ext-install \
    pdo \
    pdo_mysql \
    mysqli \
    pdo_pgsql \
    pgsql

WORKDIR /var/www/html
