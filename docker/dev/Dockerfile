# PHP 8.5.10 CLI on Debian Bookworm, pinned by digest; the native engine requires Linux/glibc.
FROM php:8.5.10-cli-bookworm@sha256:b80dfc7d2bc0fc97755620a0dfb3d5e8e9cbf70a2970ea2d5c9dc64154b31422

COPY tools/install-native-engine.sh /tmp/kumwe-install/tools/install-native-engine.sh
COPY resources/native-runtime/source.json /tmp/kumwe-install/resources/native-runtime/source.json

ENV KUMWE_NATIVE_EXPECTED_TUPLE=/usr/local/etc/kumwe/native-expected-tuple.json

# Keep the former image's www-data identity; Compose may still select the developer's host UID.
# Runtime libraries remain installed after the temporary PHP/native build dependencies are purged.
RUN set -eux; \
    test "$(dpkg --print-architecture)" = amd64; \
    groupmod --gid 82 www-data; \
    usermod --uid 82 --gid 82 www-data; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        bash ca-certificates curl git libicu72 libonig5 libpq5 libstdc++6 libzip4; \
    apt-get install -y --no-install-recommends \
        $PHPIZE_DEPS \
        cmake libicu-dev libonig-dev libpq-dev libzip-dev; \
    docker-php-ext-install -j"$(getconf _NPROCESSORS_ONLN)" intl mbstring pcntl pdo_mysql pdo_pgsql zip; \
    pecl install redis-6.3.0; \
    docker-php-ext-enable redis; \
    bash /tmp/kumwe-install/tools/install-native-engine.sh /usr/local/lib/kumwe-native; \
    install -m 0644 /usr/local/lib/kumwe-native/kumwe_engine.ini "$PHP_INI_DIR/conf.d/kumwe_engine.ini"; \
    install -d /usr/local/etc/kumwe; \
    install -m 0644 /usr/local/lib/kumwe-native/native-expected-tuple.json "$KUMWE_NATIVE_EXPECTED_TUPLE"; \
    apt-get purge -y --auto-remove -o APT::AutoRemove::RecommendsImportant=false \
        $PHPIZE_DEPS cmake libicu-dev libonig-dev libpq-dev libzip-dev; \
    rm -rf /var/lib/apt/lists/* /tmp/kumwe-install /tmp/pear; \
    php -r 'foreach (["intl", "kumwe_engine", "mbstring", "Zend OPcache", "pcntl", "pdo_mysql", "pdo_pgsql", "redis", "zip"] as $extension) { if (!extension_loaded($extension)) { fwrite(STDERR, "Missing PHP extension: {$extension}\n"); exit(1); } }'

# composer:2, pinned by digest for the same reason as the base image.
COPY --from=composer@sha256:4d71c3c2109c61d5415544264b59ad4087e4c5b7244481723664138fd36d5040 /usr/bin/composer /usr/local/bin/composer

WORKDIR /app

USER www-data

EXPOSE 8080
