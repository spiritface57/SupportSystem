FROM php:8.3-fpm

RUN apt-get update \
  && apt-get install -y --no-install-recommends \
    autoconf \
    g++ \
    make \
  && docker-php-ext-install pdo_mysql \
  && pecl install redis \
  && docker-php-ext-enable redis \
  && apt-get purge -y --auto-remove \
    autoconf \
    g++ \
    make \
  && rm -rf /var/lib/apt/lists/*
