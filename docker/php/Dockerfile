# syntax=docker/dockerfile:1.7

# PHP 8.5.10 FPM on Debian Trixie supplies glibc; the released native engine builds with GCC 13.
ARG PHP_IMAGE=php:8.5.10-fpm-trixie@sha256:70076c1cae0cd0ba6761832417e3a1df3e5560f0544eb0fe40357373e54420fe
ARG COMPOSER_IMAGE=composer:2
ARG NGINX_IMAGE=nginx:1.30.4-alpine3.24

FROM ${COMPOSER_IMAGE} AS composer

FROM ${PHP_IMAGE} AS php-base

COPY tools/install-native-engine.sh /tmp/kumwe-install/tools/install-native-engine.sh
COPY resources/native-runtime/source.json /tmp/kumwe-install/resources/native-runtime/source.json

ENV KUMWE_NATIVE_EXPECTED_TUPLE=/usr/local/etc/kumwe/native-expected-tuple.json

# Preserve the former Alpine www-data identity so existing storage volumes remain writable.
# Install runtime libraries explicitly; remove PHP/native compiler dependencies after building.
# Refresh inherited Debian packages before compiling against the pinned PHP image.
RUN set -eux; \
    test "$(dpkg --print-architecture)" = amd64; \
    groupmod --gid 82 www-data; \
    usermod --uid 82 --gid 82 www-data; \
    apt-get update; \
    apt-get upgrade -y --no-install-recommends; \
    apt-get install -y --no-install-recommends \
        bash ca-certificates curl gosu libicu76 libonig5 libpq5 libstdc++6 libzip5 tzdata; \
    apt-get install -y --no-install-recommends \
        $PHPIZE_DEPS \
        cmake gcc-13 g++-13 libicu-dev libonig-dev libpq-dev libzip-dev; \
    docker-php-ext-install -j"$(getconf _NPROCESSORS_ONLN)" intl mbstring pcntl pdo_mysql pdo_pgsql zip; \
    pecl install redis-6.3.0 apcu-5.1.28; \
    docker-php-ext-enable redis apcu; \
    CC=gcc-13 CXX=g++-13 bash /tmp/kumwe-install/tools/install-native-engine.sh /usr/local/lib/kumwe-native; \
    install -m 0644 /usr/local/lib/kumwe-native/kumwe_engine.ini "$PHP_INI_DIR/conf.d/kumwe_engine.ini"; \
    install -d /usr/local/etc/kumwe; \
    install -m 0644 /usr/local/lib/kumwe-native/native-expected-tuple.json "$KUMWE_NATIVE_EXPECTED_TUPLE"; \
    apt-get purge -y --auto-remove -o APT::AutoRemove::RecommendsImportant=false \
        $PHPIZE_DEPS cmake gcc-13 g++-13 libicu-dev libonig-dev libpq-dev libzip-dev; \
    rm -rf /var/lib/apt/lists/* /tmp/kumwe-install /tmp/pear; \
    php -r 'foreach (["apcu", "ctype", "fileinfo", "filter", "intl", "kumwe_engine", "mbstring", "openssl", "pcntl", "Zend OPcache", "pdo", "pdo_mysql", "pdo_pgsql", "redis", "sodium", "zip"] as $extension) { if (!extension_loaded($extension)) { fwrite(STDERR, "Missing PHP extension: {$extension}\n"); exit(1); } }'; \
    { \
        echo 'expose_php=Off'; \
        echo 'display_errors=Off'; \
        echo 'display_startup_errors=Off'; \
        echo 'log_errors=On'; \
        echo 'error_log=/proc/self/fd/2'; \
        echo 'memory_limit=256M'; \
        echo 'max_execution_time=60'; \
        echo 'post_max_size=8M'; \
        echo 'upload_max_filesize=8M'; \
        echo 'session.cookie_httponly=1'; \
        echo 'session.cookie_samesite=Lax'; \
        echo 'session.cookie_secure=1'; \
        echo 'session.use_strict_mode=1'; \
        echo 'opcache.enable=1'; \
        echo 'opcache.enable_cli=0'; \
        echo 'opcache.validate_timestamps=0'; \
        echo 'opcache.jit=disable'; \
    } > /usr/local/etc/php/conf.d/zz-kumwe-production.ini; \
    { \
        echo '[global]'; \
        echo 'error_log = /var/www/kumwe/storage/logs/php-fpm.log'; \
        echo ''; \
        echo '[www]'; \
        echo 'access.log = /dev/null'; \
        echo 'clear_env = no'; \
        echo 'catch_workers_output = yes'; \
        echo 'decorate_workers_output = no'; \
        echo 'ping.path = /fpm-ping'; \
        echo 'pm = dynamic'; \
        echo 'pm.max_children = 20'; \
        echo 'pm.start_servers = 2'; \
        echo 'pm.min_spare_servers = 2'; \
        echo 'pm.max_spare_servers = 6'; \
        echo 'pm.max_requests = 500'; \
    } > /usr/local/etc/php-fpm.d/zz-kumwe.conf

RUN <<'SH'
set -eu
install -m 0755 /dev/null /usr/local/bin/kumwe-entrypoint
cat > /usr/local/bin/kumwe-entrypoint <<'ENTRYPOINT'
#!/bin/sh
set -eu

load_secret() {
    variable_name="$1"
    file_variable_name="${variable_name}_FILE"
    secret_file="$(printenv "$file_variable_name" 2>/dev/null || true)"

    if [ -z "$secret_file" ]; then
        return 0
    fi

    if [ ! -r "$secret_file" ]; then
        echo "Secret file for ${variable_name} is not readable." >&2
        exit 78
    fi

    secret_value="$(cat -- "$secret_file")"

    if [ -z "$secret_value" ]; then
        echo "Secret file for ${variable_name} is empty." >&2
        exit 78
    fi

    export "${variable_name}=${secret_value}"
    unset "$file_variable_name"
}

load_secret APP_SECRET
load_secret EXTENSION_RUNTIME_SIGNING_KEY
load_secret EXTENSION_RUNTIME_PREVIOUS_KEYS
load_secret DB_PASSWORD
load_secret REDIS_PASSWORD

if [ -z "${KUMWE_INSTANCE_ID:-}" ]; then
    KUMWE_INSTANCE_ID="$(cat /etc/hostname)"
    export KUMWE_INSTANCE_ID
fi

materialize_runtime() {
    case "${1:-}" in
        php-fpm)
            ;;
        php)
            case "${2:-}:${3:-}" in
                bin/kumwe:queue:work|bin/kumwe:schedule:run)
                    ;;
                *)
                    return 0
                    ;;
            esac
            ;;
        *)
            return 0
            ;;
    esac

    if [ "$(id -u)" -eq 0 ]; then
        gosu www-data:www-data php bin/kumwe extension:runtime:materialize
    else
        php bin/kumwe extension:runtime:materialize
    fi
}

materialize_runtime "$@"

if [ "${1:-}" = 'php-fpm' ]; then
    if [ "$(id -u)" -eq 0 ]; then
        gosu www-data:www-data php bin/kumwe extension:runtime:watch --interval=10 --reload-pid=1 &
    else
        php bin/kumwe extension:runtime:watch --interval=10 --reload-pid=1 &
    fi
fi

if [ "$(id -u)" -eq 0 ]; then
    exec gosu www-data:www-data "$@"
fi

exec "$@"
ENTRYPOINT
SH

WORKDIR /var/www/kumwe

# An empty default for the dependency-cache build context. A builder that has a warm Composer
# cache overrides it (docker buildx build --build-context composer-cache=…) so the vendor stage
# reads known dist archives locally instead of fetching every one from api.github.com at build
# time; a plain build keeps this empty stage and behaves exactly as before.
FROM scratch AS composer-cache

FROM php-base AS vendor

COPY --from=composer /usr/bin/composer /usr/local/bin/composer
COPY composer.json composer.lock ./

RUN --mount=type=bind,from=composer-cache,target=/tmp/composer-cache-seed \
    set -eux; \
    if [ -n "$(ls -A /tmp/composer-cache-seed 2>/dev/null)" ]; then \
        install -d /root/.composer/cache/files; \
        cp -R /tmp/composer-cache-seed/. /root/.composer/cache/files/; \
    fi; \
    for attempt in 1 2 3; do \
        if composer install \
            --classmap-authoritative \
            --no-ansi \
            --no-dev \
            --no-interaction \
            --no-progress \
            --no-scripts \
            --prefer-dist; then \
            exit 0; \
        else \
            status="$?"; \
        fi; \
        if [ "$status" -ne 100 ]; then \
            exit "$status"; \
        fi; \
        if [ "$attempt" -lt 3 ]; then \
            echo "Composer dependency download failed on attempt ${attempt}; retrying." >&2; \
            sleep $((10 * attempt)); \
        fi; \
    done; \
    echo 'Composer dependencies could not be installed after three bounded attempts.' >&2; \
    exit "$status"

COPY . ./

RUN set -eux; \
    composer dump-autoload --classmap-authoritative --no-ansi --no-dev --no-interaction; \
    composer check-platform-reqs --no-dev; \
    rm -rf storage/cache/* storage/logs/* storage/sessions/* storage/tmp/*; \
    install -d -o www-data -g www-data \
        storage/cache \
        storage/logs \
        storage/media \
        storage/sessions \
        storage/tmp \
        public/assets/extensions \
        extensions; \
    install -d -m 0700 -o www-data -g www-data \
        storage/private \
        storage/private/report-exports

FROM php-base AS runtime

ARG KUMWE_RELEASE=development
ENV APP_ENV=production \
    APP_DEBUG=false \
    KUMWE_RELEASE=${KUMWE_RELEASE}

COPY --from=vendor --chown=www-data:www-data /var/www/kumwe /var/www/kumwe

USER www-data
ENTRYPOINT ["/usr/local/bin/kumwe-entrypoint"]
CMD ["php-fpm", "-F"]
STOPSIGNAL SIGQUIT
EXPOSE 9000

HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD ["php-fpm", "-t"]

FROM ${NGINX_IMAGE} AS web

COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY --from=vendor --chown=nginx:nginx /var/www/kumwe/public /var/www/kumwe/public

RUN set -eux; \
    apk upgrade --no-cache; \
    sed -i 's#^pid .*#pid /tmp/nginx.pid;#' /etc/nginx/nginx.conf; \
    sed -i '/http {/a\    client_body_temp_path /tmp/client_temp;\n    proxy_temp_path /tmp/proxy_temp;\n    fastcgi_temp_path /tmp/fastcgi_temp;\n    uwsgi_temp_path /tmp/uwsgi_temp;\n    scgi_temp_path /tmp/scgi_temp;' /etc/nginx/nginx.conf

USER nginx
ENTRYPOINT ["nginx"]
CMD ["-g", "daemon off;"]
EXPOSE 8080

HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD wget --quiet --output-document=- http://127.0.0.1:8080/health/live >/dev/null || exit 1
