FROM php:8.4-cli

# The panel declares ext-intl, ext-json, ext-mbstring, ext-pdo and ext-zip; the
# base image already has json, mbstring, pdo and pdo_sqlite. bcmath is a
# transitive requirement (s1lentium/iptools). pdo_mysql is included so this same
# container can talk to a real panel database if you ever want it to.
#
# Between them these are everything `composer install` needs, which is why no
# --ignore-platform-reqs appears anywhere in this workspace.
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git \
        unzip \
        libicu-dev \
        libzip-dev \
    && docker-php-ext-install -j"$(nproc)" bcmath intl zip pdo_mysql \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Composer refuses to run as root without this, and everything in a throwaway
# dev container is root.
ENV COMPOSER_ALLOW_SUPERUSER=1

WORKDIR /workspace
