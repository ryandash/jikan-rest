FROM docker.io/spiralscout/roadrunner:2025.1.15 AS roadrunner
FROM docker.io/composer:2 AS composer
FROM docker.io/mlocati/php-extension-installer:2.1.77 AS php-ext-installer

FROM php:8.5.12-bookworm

# Composer + extension installer
COPY --from=composer /usr/bin/composer /usr/bin/composer
COPY --from=php-ext-installer /usr/bin/install-php-extensions /usr/local/bin/

ENV COMPOSER_HOME="/tmp/composer" \
    COMPOSER_MEMORY_LIMIT=-1

# Install only required extensions (no xdebug in prod)
RUN set -eux; \
    install-php-extensions \
        intl \
        mbstring \
        mongodb \
        redis \
        opcache \
        sockets \
        pcntl

# RoadRunner binary only
COPY --from=roadrunner /usr/bin/rr /usr/bin/rr

LABEL org.opencontainers.image.source=https://github.com/jikan-me/jikan-rest

# Keep only tools needed after the image is built; unzip is required by Composer.
RUN set -eux; \
    apt-get update && apt-get install -y --no-install-recommends \
        unzip \
        curl \
    && curl -fsSL "https://github.com/aptible/supercronic/releases/download/v0.1.12/supercronic-linux-$(dpkg --print-architecture)" \
        -o /usr/bin/supercronic \
    && chmod +x /usr/bin/supercronic \
    && mkdir -p /etc/supercronic \
    && echo '*/5 * * * * php /app/artisan schedule:run' > /etc/supercronic/laravel \
    && echo -e "\nopcache.enable=1\nopcache.enable_cli=1\nopcache.memory_consumption=64\nopcache.interned_strings_buffer=8\nopcache.max_accelerated_files=10000\nopcache.validate_timestamps=0\nrealpath_cache_size=4096K\nrealpath_cache_ttl=600\nopcache.jit_buffer_size=32M\nopcache.jit=1235\n" >> \
        ${PHP_INI_DIR}/conf.d/docker-php-ext-opcache.ini \
    && rm -rf /var/lib/apt/lists/*

RUN adduser \
    --disabled-password \
    --shell "/sbin/nologin" \
    --home "/nonexistent" \
    --no-create-home \
    --uid "10001" \
    "jikanapi" \
    && mkdir -p /app /var/run/rr /etc/supercronic \
    && chown -R jikanapi:jikanapi /app /var/run/rr /etc/supercronic

WORKDIR /app

# dependency caching
COPY composer.* /app/

RUN composer install \
    --no-dev \
    --prefer-dist \
    --no-interaction \
    --no-progress \
    --optimize-autoloader \
    --no-scripts

COPY --chown=jikanapi:jikanapi . .

RUN mkdir -p /app/bootstrap/cache \
    && composer dump-autoload --no-dev --optimize \
    && chown -R jikanapi:jikanapi /app \
    && chmod +x /app/docker-entrypoint.sh

USER jikanapi

EXPOSE 8080 2114

HEALTHCHECK CMD curl --fail http://localhost:2114/health?plugin=http || exit 1

ENTRYPOINT ["/app/docker-entrypoint.sh"]
