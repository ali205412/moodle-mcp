FROM composer:2 AS composer
FROM node:20-bookworm-slim AS node20
FROM php:8.2-cli-bookworm AS node22

RUN apt-get update && apt-get install -y --no-install-recommends \
    ca-certificates \
    curl \
    xz-utils \
 && rm -rf /var/lib/apt/lists/* \
 && mkdir -p /opt/node22 \
 && curl -fsSL https://nodejs.org/dist/v22.11.0/node-v22.11.0-linux-x64.tar.xz \
    | tar -xJ --strip-components=1 -C /opt/node22

FROM php:8.2-cli-bookworm

ENV DEBIAN_FRONTEND=noninteractive \
    COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_HOME=/tmp/composer

RUN apt-get update && apt-get install -y --no-install-recommends \
    ca-certificates \
    curl \
    default-mysql-client \
    git \
    libfreetype6-dev \
    libicu-dev \
    libjpeg62-turbo-dev \
    libonig-dev \
    libpng-dev \
    libpq-dev \
    libxml2-dev \
    libzip-dev \
    locales \
    postgresql-client \
    rsync \
    unzip \
    zip \
 && sed -i '/en_AU.UTF-8/s/^# //g' /etc/locale.gen \
 && locale-gen \
 && docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-install -j"$(nproc)" \
    exif \
    gd \
    intl \
    mysqli \
    opcache \
    pdo_mysql \
    pdo_pgsql \
    pgsql \
    soap \
    zip \
 && rm -rf /var/lib/apt/lists/*

COPY --from=composer /usr/bin/composer /usr/local/bin/composer
COPY --from=node20 /usr/local /opt/node20
COPY --from=node22 /opt/node22 /opt/node22
COPY docker/plugin-ci/run-tests.sh /usr/local/bin/run-plugin-ci

RUN printf "max_input_vars=5000\n" > /usr/local/etc/php/conf.d/moodle-plugin-ci.ini \
 && chmod +x /usr/local/bin/run-plugin-ci

WORKDIR /work
