FROM docker.io/spiralscout/roadrunner:2.12.3 as roadrunner
FROM docker.io/composer:2.6.6 as composer
FROM docker.io/mlocati/php-extension-installer:2.1.77 as php-ext-installer

FROM php:8.1.27-bullseye

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
        mongodb-1.21.5 \
        redis \
        opcache \
        sockets \
        pcntl

# RoadRunner binary only
COPY --from=roadrunner /usr/bin/rr /usr/bin/rr

LABEL org.opencontainers.image.source=https://github.com/jikan-me/jikan-rest

# Minimal runtime dependencies only
RUN set -eux; \
    apt-get update && apt-get install -y --no-install-recommends \
        git \
        unzip \
        curl \
    && rm -rf /var/lib/apt/lists/* \
    && curl -fsSLo /usr/bin/supercronic \
    https://github.com/aptible/supercronic/releases/latest/download/supercronic-linux-$(dpkg --print-architecture) \
    && chmod +x /usr/bin/supercronic \
	&& mkdir /etc/supercronic \
	&& echo '*/5 * * * * php /app/artisan schedule:run' > /etc/supercronic/laravel \
	&& rm -rf /var/lib/apt/lists/* \
	# enable opcache for CLI and JIT, docs: <https://www.php.net/manual/en/opcache.configuration.php#ini.opcache.jit>
	&& echo -e "\nopcache.enable=1\nopcache.enable_cli=1\nopcache.memory_consumption=64\nopcache.interned_strings_buffer=8\nopcache.max_accelerated_files=10000\nopcache.validate_timestamps=0\nrealpath_cache_size=4096K\nrealpath_cache_ttl=600\nopcache.jit_buffer_size=32M\nopcache.jit=1235\n" >> \
	    ${PHP_INI_DIR}/conf.d/docker-php-ext-opcache.ini

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
    --optimize-autoloader

COPY --chown=jikanapi:jikanapi . .

RUN chown -R jikanapi:jikanapi /app

USER jikanapi

EXPOSE 8080 2114

HEALTHCHECK CMD curl --fail http://localhost:2114/health?plugin=http || exit 1

ENTRYPOINT ["/app/docker-entrypoint.sh"]
