# syntax=docker/dockerfile:1.7

FROM php:8.4-fpm-alpine AS runtime-base

ENV OPCACHE_VALIDATE_TIMESTAMPS=0

RUN apk add --no-cache \
        fcgi \
        icu-libs \
        libzip \
        postgresql-libs \
    && apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        icu-dev \
        libzip-dev \
        linux-headers \
        postgresql-dev \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath \
        intl \
        opcache \
        pcntl \
        pdo_pgsql \
        pgsql \
        zip \
    && apk del .build-deps \
    && rm -rf /tmp/* /var/cache/apk/*

RUN addgroup -g 1000 -S appuser \
    && adduser -u 1000 -S -G appuser appuser \
    && mkdir -p /var/www/html \
    && chown -R appuser:appuser /var/www/html

COPY docker/php/php.ini /usr/local/etc/php/conf.d/99-smartcanteen.ini
COPY docker/php/opcache.ini /usr/local/etc/php/conf.d/99-opcache.ini
COPY docker/php/www.conf /usr/local/etc/php-fpm.d/zz-smartcanteen.conf
COPY docker/entrypoint.sh /usr/local/bin/docker-entrypoint.sh

RUN sed -i 's/\r$//' /usr/local/bin/docker-entrypoint.sh \
    && chmod +x /usr/local/bin/docker-entrypoint.sh

WORKDIR /var/www/html

ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]
CMD ["php-fpm"]

HEALTHCHECK --interval=15s --timeout=5s --start-period=15s --retries=3 \
    CMD SCRIPT_NAME=/fpm-ping SCRIPT_FILENAME=/fpm-ping REQUEST_METHOD=GET cgi-fcgi -bind -connect 127.0.0.1:9000 | grep -q pong || exit 1

# -----------------------------------------------------------------------------
# Stage: composer-deps (installs production PHP dependencies with exact PHP 8.4)
# -----------------------------------------------------------------------------
FROM runtime-base AS composer-deps

COPY --from=composer:2.8 /usr/bin/composer /usr/bin/composer
RUN apk add --no-cache unzip

WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
    --no-interaction \
    --prefer-dist \
    --no-scripts \
    --no-autoloader

COPY artisan ./
COPY app ./app
COPY bootstrap ./bootstrap
COPY config ./config
COPY database ./database
COPY routes ./routes

RUN composer dump-autoload \
    --no-dev \
    --optimize \
    --classmap-authoritative \
    --no-scripts

# -----------------------------------------------------------------------------
# Stage: dev (local development & automated PHPUnit / Pint execution)
# -----------------------------------------------------------------------------
FROM runtime-base AS dev

ENV OPCACHE_VALIDATE_TIMESTAMPS=1

COPY --from=composer:2.8 /usr/bin/composer /usr/bin/composer
RUN apk add --no-cache git unzip \
    && git config --system --add safe.directory /var/www/html \
    && mkdir -p /var/www/html/vendor \
    && chown -R appuser:appuser /var/www/html/vendor

USER appuser
EXPOSE 9000

# -----------------------------------------------------------------------------
# Stage: production (immutable, non-root, no composer/tests/dev dependencies)
# -----------------------------------------------------------------------------
FROM runtime-base AS production

ENV APP_ENV=production \
    APP_DEBUG=false \
    OPCACHE_VALIDATE_TIMESTAMPS=0

COPY --from=composer-deps --chown=appuser:appuser /app/vendor ./vendor
COPY --chown=appuser:appuser artisan composer.json ./
COPY --chown=appuser:appuser app ./app
COPY --chown=appuser:appuser bootstrap ./bootstrap
COPY --chown=appuser:appuser config ./config
COPY --chown=appuser:appuser database ./database
COPY --chown=appuser:appuser public ./public
COPY --chown=appuser:appuser resources ./resources
COPY --chown=appuser:appuser routes ./routes
COPY --chown=appuser:appuser storage ./storage

RUN rm -rf storage/logs/*.log bootstrap/cache/*.php \
    && chown -R appuser:appuser storage bootstrap/cache

USER appuser
EXPOSE 9000
