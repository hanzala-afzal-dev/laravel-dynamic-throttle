# Development and test container: PHP CLI with every extension the test suite uses.
#
#   docker compose run --rm php composer test
#   PHP_VERSION=8.5 docker compose build php    (try another supported PHP version)
#
# The default is the lowest supported PHP version (ADR-0002), so code written
# here cannot accidentally depend on newer language features.
ARG PHP_VERSION=8.3

FROM composer:2 AS composer
FROM mlocati/php-extension-installer:2.12.0 AS extension-installer

FROM php:${PHP_VERSION}-cli

COPY --from=extension-installer /usr/bin/install-php-extensions /usr/local/bin/
COPY --from=composer /usr/bin/composer /usr/local/bin/composer

RUN install-php-extensions apcu bcmath intl memcached pcntl pcov pdo_mysql pdo_pgsql redis zip \
    && apt-get update \
    && apt-get install -y --no-install-recommends git unzip \
    && rm -rf /var/lib/apt/lists/*

COPY php.ini /usr/local/etc/php/conf.d/zz-dynamic-throttle.ini

# The repository is bind-mounted from the host, which may own the files as another user.
RUN git config --system --add safe.directory /app

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_CACHE_DIR=/tmp/composer-cache

WORKDIR /app
