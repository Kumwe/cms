#!/usr/bin/env bash
#
# Kumwe unified agent environment bootstrap.
#
# This is the ONE canonical setup entry point for every coding agent and every
# sandbox — Claude Code, OpenAI Codex, Cursor, or any other harness — and for
# humans who want the same lane. Vendor configuration files (.claude/,
# .devcontainer/, a cloud environment's "setup script" field) may only ever
# point here; no setup logic lives anywhere else.
#
#   bash tools/agent-setup.sh
#
# The script is idempotent and degrades gracefully: it installs what the
# sandbox allows, skips what it cannot reach, and ends with a capability
# report telling the agent exactly which verification tiers work here:
#
#   Tier 0  dependency-free gates      php tools/verify-*.php, roadmap checks
#   Tier 1  static + unit lane         composer cs / analyse / test:unit + architecture
#   Tier 2  database-backed lane       composer test / test:integration (MariaDB + Redis)
#   Front   frontend lane              npm run check && npm run build
#
# Outbound domains this script may need are listed in tools/agent-egress.txt.
# When a step fails on a blocked domain, allow the named line in the sandbox's
# network policy and re-run; every step is safe to repeat.
#
# Results needed by later shells are written to .agent-env (gitignored);
# source it before running the database-backed lane:  . ./.agent-env

set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

ENV_FILE="$ROOT/.agent-env"
TIER0=yes TIER1=no TIER2=no FRONTEND=no
NOTES=()

say() { printf '\n== %s\n' "$*"; }
note() { NOTES+=("$*"); printf '   %s\n' "$*"; }

# Composer refuses plugins as root unless this is set; harmless otherwise.
export COMPOSER_ALLOW_SUPERUSER=1
export DEBIAN_FRONTEND=noninteractive

# ---------------------------------------------------------------- git history
say "Git history"
if [ "$(git rev-parse --is-shallow-repository 2>/dev/null)" = "true" ]; then
    if git fetch -q --unshallow origin 2>/dev/null; then
        note "Deepened the shallow clone; history-dependent evidence tests can run."
    else
        note "Clone is shallow and could not be deepened; evidence-citation tests will fail. Not a code defect."
    fi
else
    note "Full history present."
fi

# ------------------------------------------------------------------------ php
# The platform requirement is PHP 8.5, and platform identity is the last delta
# between a sandbox and CI: 8.5-only deprecations fail PHPStan and the suite
# there while an 8.4 sandbox cannot see them. When the sandbox can reach the
# sury package source (the PHP lines in tools/agent-egress.txt), install the
# real 8.5 with the pcov driver so even the coverage ratchet measures locally;
# when it cannot, degrade to the platform override exactly as before.
say "PHP"
if ! command -v php8.5 >/dev/null 2>&1 && command -v apt-get >/dev/null 2>&1; then
    CODENAME="$(. /etc/os-release 2>/dev/null && echo "${VERSION_CODENAME:-}")"
    if [ -n "$CODENAME" ] \
        && curl -fsm 6 -o /dev/null "https://packages.sury.org/php/dists/$CODENAME/Release" 2>/dev/null; then
        note "Installing PHP 8.5 with pcov from packages.sury.org (cached by the environment snapshot)…"
        (
            set -e
            curl -fsSL -m 20 https://packages.sury.org/php/apt.gpg -o /usr/share/keyrings/sury-php.gpg
            printf 'deb [signed-by=/usr/share/keyrings/sury-php.gpg] https://packages.sury.org/php/ %s main\n' \
                "$CODENAME" > /etc/apt/sources.list.d/sury-php.list
            apt-get update -q
            apt-get install -y -q php8.5-cli php8.5-intl php8.5-mbstring php8.5-xml php8.5-curl \
                php8.5-zip php8.5-mysql php8.5-pgsql php8.5-redis php8.5-sqlite3 php8.5-pcov
            update-alternatives --set php /usr/bin/php8.5
        ) >/tmp/agent-setup-php.log 2>&1 \
            && note "PHP 8.5 installed and selected; the sandbox now matches the CI platform." \
            || note "PHP 8.5 install failed (see /tmp/agent-setup-php.log); continuing on the system PHP."
    else
        note "packages.sury.org is not reachable; allow the PHP lines in tools/agent-egress.txt for exact platform parity."
    fi
fi
PHP_BIN="php"
if command -v php8.5 >/dev/null 2>&1; then
    PHP_BIN="php8.5"
fi
PHP_VERSION="$("$PHP_BIN" -r 'echo PHP_VERSION;' 2>/dev/null || echo none)"
PLATFORM_FLAG=""
case "$PHP_VERSION" in
    8.5.*|8.6.*|9.*) note "PHP $PHP_VERSION matches the platform requirement." ;;
    none) note "No PHP binary found; nothing beyond Tier 0 documentation checks can run." ;;
    *)
        PLATFORM_FLAG="--ignore-platform-req=php"
        note "PHP $PHP_VERSION < 8.5: composer will run with $PLATFORM_FLAG. Unit, architecture, functional and almost all integration tests pass, but the extension-admission integration tests (about nine) correctly REFUSE under PHP < 8.5 because extension manifests demand it. For those, allow the packages.sury.org line in tools/agent-egress.txt and install php8.5."
        ;;
esac

# --------------------------------------------------------------- composer deps
# A sandbox that blocks GitHub's zipball hosts can still install from git
# sources, except for packages whose lock entry carries no source at all
# (phpstan/phpstan ships dist-only). For those, build the byte-equivalent
# archive from an authenticated git clone and place it where composer's
# cache expects the dist download, keyed exactly as FileDownloader does
# (packages from Packagist carry no dist shasum, so composer accepts the
# structurally equivalent git archive).
seed_distonly_composer_cache() {
    command -v git >/dev/null 2>&1 || return 0
    [ -f composer.lock ] || return 0
    "$PHP_BIN" -r '
        $lock = json_decode(file_get_contents("composer.lock"), true);
        foreach (array_merge($lock["packages"] ?? [], $lock["packages-dev"] ?? []) as $p) {
            if (isset($p["source"])) { continue; }
            $url = $p["dist"]["url"] ?? "";
            if (($p["dist"]["type"] ?? "") !== "zip") { continue; }
            if (!preg_match("#api\.github\.com/repos/([^/]+/[^/]+)/zipball#", $url, $m)) { continue; }
            echo $p["name"], " ", sha1($url), " ", $p["dist"]["reference"], " ", $m[1], "\n";
        }' 2>/dev/null | while read -r name key ref repo; do
        cache_file="${COMPOSER_CACHE_DIR:-$HOME/.cache/composer}/files/$name/$key.zip"
        [ -f "$cache_file" ] && continue
        seed_tmp="$(mktemp -d)"
        if git init -q "$seed_tmp" \
            && git -C "$seed_tmp" remote add origin "https://github.com/$repo.git" \
            && git -C "$seed_tmp" fetch -q --depth 1 origin "$ref" >/dev/null 2>&1 \
            && mkdir -p "$(dirname "$cache_file")" \
            && git -C "$seed_tmp" archive --format=zip \
                --prefix="$(printf '%s' "$repo" | tr / -)-$(printf '%.7s' "$ref")/" \
                -o "$cache_file" "$ref" >/dev/null 2>&1; then
            printf '   Seeded composer cache for dist-only %s from git.\n' "$name"
        else
            rm -f "$cache_file"
        fi
        rm -rf "$seed_tmp"
    done
}

say "Native computation runtime"
if ! php -r 'exit(extension_loaded("kumwe_engine") && phpversion("kumwe_engine") === "1.0.1" ? 0 : 1);' \
    || [ ! -r "${KUMWE_NATIVE_EXPECTED_TUPLE:-/usr/local/lib/kumwe-native/native-expected-tuple.json}" ]; then
    if command -v apt-get >/dev/null 2>&1 && [ "$(id -u)" = 0 ]; then
        apt-get update -q
        apt-get install -y -q php8.5-dev autoconf build-essential cmake curl pkg-config
    fi
    bash tools/install-native-engine.sh || {
        note "Native provisioning failed. PHP 8.5 development headers, a C++20 compiler and CMake 3.25+ are required on Linux x86_64/glibc."
        exit 1
    }
    native_scan="$(php -r 'echo PHP_CONFIG_FILE_SCAN_DIR;')"
    export PHP_INI_SCAN_DIR="$native_scan:/usr/local/lib/kumwe-native"
    export KUMWE_NATIVE_EXPECTED_TUPLE=/usr/local/lib/kumwe-native/native-expected-tuple.json
fi

say "Composer dependencies"
# An environment snapshot may carry a vendor/ tree built from an older composer.lock, so
# presence alone proves nothing: after the library adoption such a tree lacked the service
# manager and the kumwe libraries, and every bin/kumwe command failed before the first test.
# Composer's dry run says whether the installed set matches the lock without touching the
# network when it does; only a matching tree skips the install.
VENDOR_CURRENT=no
if [ -f vendor/autoload.php ] && [ "$PHP_VERSION" != "none" ]; then
    if composer install --no-interaction --no-progress --prefer-dist --dry-run $PLATFORM_FLAG 2>&1 \
        | grep -q 'Nothing to install, update or remove'; then
        VENDOR_CURRENT=yes
    else
        note "vendor/ is present but does not match composer.lock; reinstalling."
    fi
elif [ -f vendor/autoload.php ]; then
    VENDOR_CURRENT=yes
fi
if [ "$VENDOR_CURRENT" = yes ]; then
    note "vendor/ matches composer.lock; skipping install."
    TIER1=yes
elif [ "$PHP_VERSION" != "none" ]; then
    if composer install --no-interaction --no-progress --prefer-dist $PLATFORM_FLAG >/tmp/agent-setup-composer.log 2>&1; then
        note "composer install (dist) succeeded."
        TIER1=yes
    else
        seed_distonly_composer_cache
        if composer install --no-interaction --no-progress --prefer-source $PLATFORM_FLAG >/tmp/agent-setup-composer.log 2>&1; then
            note "composer install succeeded from git sources (dist downloads blocked)."
            TIER1=yes
        else
            note "composer install FAILED (see /tmp/agent-setup-composer.log). Usually the sandbox blocks api.github.com / codeload.github.com — allow the 'Composer' lines in tools/agent-egress.txt and re-run."
        fi
    fi
fi

# --------------------------------------------------------------------- node.js
# The frontend lane requires the Node major from package.json engines; agent
# sandboxes often default to an older system Node while shipping nvm. Select
# or install the required major through nvm where present, and record the
# binary path for later shells via .agent-env below.
say "Node.js"
REQUIRED_NODE_MAJOR=24
NODE_BIN_DIR=""
node_major() { node -p 'process.versions.node.split(".")[0]' 2>/dev/null || echo 0; }
if [ "$(node_major)" -lt "$REQUIRED_NODE_MAJOR" ]; then
    for nvm_dir in "${NVM_DIR:-}" /opt/nvm "$HOME/.nvm"; do
        if [ -n "$nvm_dir" ] && [ -s "$nvm_dir/nvm.sh" ]; then
            export NVM_DIR="$nvm_dir"
            # shellcheck disable=SC1091
            . "$nvm_dir/nvm.sh" >/dev/null 2>&1
            nvm install "$REQUIRED_NODE_MAJOR" >/dev/null 2>&1
            nvm alias default "$REQUIRED_NODE_MAJOR" >/dev/null 2>&1
            nvm_node="$(nvm which "$REQUIRED_NODE_MAJOR" 2>/dev/null || true)"
            if [ -n "$nvm_node" ]; then
                NODE_BIN_DIR="$(dirname "$nvm_node")"
                PATH="$NODE_BIN_DIR:$PATH"
            fi
            break
        fi
    done
fi
if [ "$(node_major)" -ge "$REQUIRED_NODE_MAJOR" ]; then
    note "Node $(node --version 2>/dev/null) satisfies the engines requirement."
else
    note "Node >= $REQUIRED_NODE_MAJOR unavailable (system Node $(node --version 2>/dev/null || echo none), no usable nvm); the frontend lane may fail engine checks."
fi

# ------------------------------------------------------------------- node deps
say "Frontend dependencies"
if [ -d node_modules ]; then
    note "node_modules already present; skipping install."
    FRONTEND=yes
elif command -v npm >/dev/null 2>&1; then
    if npm ci --no-audit --no-fund >/tmp/agent-setup-npm.log 2>&1; then
        note "npm ci succeeded."
        FRONTEND=yes
    else
        note "npm ci failed (see /tmp/agent-setup-npm.log); frontend lane unavailable. Allow the npm registry line in tools/agent-egress.txt."
    fi
else
    note "npm not found; frontend lane unavailable."
fi

# --------------------------------------------------------------------- MariaDB
# Mirrors the CI database job exactly: database kumwe_test, user kumwe,
# password kumwe_test, table prefix kumwe_ (.github/workflows/ci.yml).
say "Database (MariaDB, CI-identical)"
db_ready() { (exec 3<>/dev/tcp/127.0.0.1/3306) 2>/dev/null && exec 3>&- && return 0; return 1; }

provision_db() {
    local client=""
    command -v mariadb >/dev/null 2>&1 && client="mariadb"
    [ -z "$client" ] && command -v mysql >/dev/null 2>&1 && client="mysql"
    [ -z "$client" ] && return 1
    "$client" -uroot 2>/dev/null <<'SQL'
CREATE DATABASE IF NOT EXISTS kumwe_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'kumwe'@'localhost' IDENTIFIED BY 'kumwe_test';
CREATE USER IF NOT EXISTS 'kumwe'@'127.0.0.1' IDENTIFIED BY 'kumwe_test';
CREATE USER IF NOT EXISTS 'kumwe'@'%' IDENTIFIED BY 'kumwe_test';
GRANT ALL PRIVILEGES ON `kumwe_test`.* TO 'kumwe'@'localhost';
GRANT ALL PRIVILEGES ON `kumwe_test`.* TO 'kumwe'@'127.0.0.1';
GRANT ALL PRIVILEGES ON `kumwe_test`.* TO 'kumwe'@'%';
FLUSH PRIVILEGES;
SQL
}

if db_ready; then
    note "A database server is already listening on 3306."
    provision_db && note "kumwe_test database and kumwe user ensured." || note "Could not provision kumwe_test as root; assuming it already exists."
    TIER2=yes
else
    if ! command -v mariadbd >/dev/null 2>&1 && ! command -v mysqld >/dev/null 2>&1; then
        if command -v apt-get >/dev/null 2>&1; then
            note "Installing mariadb-server via apt (cached by the environment snapshot)…"
            (apt-get update -q && apt-get install -y -q mariadb-server) >/tmp/agent-setup-apt.log 2>&1 \
                || note "apt install failed (see /tmp/agent-setup-apt.log). Allow the distro-mirror lines in tools/agent-egress.txt."
        else
            note "No apt and no MariaDB binary; database lane unavailable."
        fi
    fi
    if command -v mariadbd >/dev/null 2>&1 || command -v mysqld >/dev/null 2>&1; then
        # One consistent collation story before the first table exists: the parent schema's bare
        # `CHARACTER SET utf8mb4` DDL takes the server's charset default, and a default that differs
        # from the Doctrine-created tables produces "Illegal mix of collations" all over the suite.
        if [ -d /etc/mysql/mariadb.conf.d ]; then
            printf '[mysqld]\ncharacter-set-server = utf8mb4\ncollation-server = utf8mb4_unicode_ci\ninit_connect = "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"\n' \
                > /etc/mysql/mariadb.conf.d/99-kumwe-collation.cnf 2>/dev/null || true
        fi
        (service mariadb start || service mysql start || mariadbd-safe >/dev/null 2>&1 &) >/dev/null 2>&1
        for _ in $(seq 1 30); do db_ready && break; sleep 1; done
        if db_ready; then
            provision_db && note "MariaDB started; kumwe_test database and kumwe user ensured."
            TIER2=yes
        else
            note "MariaDB installed but did not start; database lane unavailable."
        fi
    fi
fi

# ------------------------------------------------------------------ PostgreSQL
# The engine-portability blind spot is real: a CASE-typing defect that MariaDB
# coerces and PostgreSQL refuses reached CI because the sandbox only ran one
# engine. Where the platform ships PostgreSQL, provision it too, so the
# cross-engine lane runs locally before any push.
say "PostgreSQL (cross-engine lane)"
pg_ready() { (exec 3<>/dev/tcp/127.0.0.1/5432) 2>/dev/null && exec 3>&- && return 0; return 1; }
if ! pg_ready && command -v pg_ctlcluster >/dev/null 2>&1; then
    (service postgresql start) >/dev/null 2>&1
    for _ in $(seq 1 15); do pg_ready && break; sleep 1; done
fi
if pg_ready; then
    su -c "psql -q -tc \"SELECT 1 FROM pg_roles WHERE rolname = 'kumwe'\"" postgres 2>/dev/null | grep -q 1 \
        || su -c "psql -q -c \"CREATE USER kumwe PASSWORD 'kumwe_test';\"" postgres >/dev/null 2>&1
    su -c "psql -q -tc \"SELECT 1 FROM pg_database WHERE datname = 'kumwe_test'\"" postgres 2>/dev/null | grep -q 1 \
        || su -c "psql -q -c \"CREATE DATABASE kumwe_test OWNER kumwe;\"" postgres >/dev/null 2>&1
    note "PostgreSQL ready; run the cross-engine lane with DB_DRIVER=pgsql DB_PORT=5432 after sourcing .agent-env."
else
    note "PostgreSQL unavailable; the cross-engine lane stays CI-only here."
fi

# ----------------------------------------------------------------------- Redis
say "Redis"
redis_ready() { (exec 3<>/dev/tcp/127.0.0.1/6379) 2>/dev/null && exec 3>&- && return 0; return 1; }
if redis_ready; then
    note "Redis is already listening on 6379."
elif command -v redis-server >/dev/null 2>&1; then
    (redis-server --daemonize yes --save '' --appendonly no) >/dev/null 2>&1
    sleep 1
    redis_ready && note "Redis started." || note "Redis present but failed to start."
elif command -v apt-get >/dev/null 2>&1; then
    (apt-get install -y -q redis-server) >/tmp/agent-setup-apt-redis.log 2>&1 \
        && (redis-server --daemonize yes --save '' --appendonly no) >/dev/null 2>&1
    redis_ready && note "Redis installed and started." || note "Redis unavailable; parts of the integration lane will skip or fail."
fi
redis_ready || TIER2=no

# ------------------------------------------------------------------ .agent-env
# The exact environment the CI database job exports, minus engine matrixing. The
# application secret survives re-runs: a cached sandbox keeps its database, and
# re-keying the secret every session would orphan whatever that database already
# holds under keyed fingerprints.
say "Writing .agent-env"
APP_SECRET_VALUE=""
if [ -f "$ENV_FILE" ]; then
    APP_SECRET_VALUE="$(sed -n "s/^export APP_SECRET='\(.*\)'$/\1/p" "$ENV_FILE" | head -1)"
fi
if [ -z "$APP_SECRET_VALUE" ]; then
    APP_SECRET_VALUE="$(openssl rand -base64 48 2>/dev/null | tr -d '\n' || echo kumwe-local-agent-secret-kumwe-local-agent-secret)"
fi
cat > "$ENV_FILE" <<EOF
# Generated by tools/agent-setup.sh — source before the database-backed lane.
export APP_ENV=testing
export APP_DEBUG=false
export APP_BASE_URL=https://kumwe.test
export APP_TRUSTED_HOSTS=kumwe.test
export APP_SECRET='$APP_SECRET_VALUE'
export EXTENSIONS_ALLOW_UNSIGNED_LOCAL=true
export DB_DRIVER=mariadb
export DB_HOST=127.0.0.1
export DB_PORT=3306
export DB_NAME=kumwe_test
export DB_USER=kumwe
export DB_PASSWORD=kumwe_test
export DB_TABLE_PREFIX=kumwe_
export DB_SSLMODE=disable
export REDIS_HOST=127.0.0.1
export REDIS_PORT=6379
export REDIS_DATABASE=0
export REDIS_NAMESPACE=kumwe.local
export KUMWE_SITE_CONTENT_PROFILE=blank
export KUMWE_BUSINESS_DEMO=false
export COMPOSER_ALLOW_SUPERUSER=1
EOF
if [ -n "$NODE_BIN_DIR" ]; then
    printf 'export PATH="%s:$PATH"\n' "$NODE_BIN_DIR" >> "$ENV_FILE"
fi
if [ -n "${PHP_INI_SCAN_DIR:-}" ]; then
    printf 'export PHP_INI_SCAN_DIR=%q\n' "$PHP_INI_SCAN_DIR" >> "$ENV_FILE"
fi
printf 'export KUMWE_NATIVE_EXPECTED_TUPLE=%q\n' \
    "${KUMWE_NATIVE_EXPECTED_TUPLE:-/usr/local/lib/kumwe-native/native-expected-tuple.json}" >> "$ENV_FILE"
note ".agent-env written."

# ----------------------------------------------------------------- test schema
# CI installs the immutable parent schema and migrates before the suite runs; a
# turnkey sandbox does the same, under the complete .agent-env the suite itself
# uses — the console kernel requires the full application environment, not just
# the database half. The collation normalizer runs before and after the
# migrations so every utf8mb4 table converges on one collation whatever the
# server's charset default resolves to (see tools/agent-collation-normalize.php).
if [ "$TIER2" = yes ] && [ "$TIER1" = yes ]; then
    say "Test schema (CI-identical)"
    if bash -c '
            set -uo pipefail
            set -a; . "'"$ENV_FILE"'"; set +a
            if ! php -r "exit((new PDO(\"mysql:host=127.0.0.1;dbname=kumwe_test\",\"kumwe\",\"kumwe_test\"))->query(\"SHOW TABLES LIKE \\\"kumwe_sites\\\"\")->fetchColumn() === false ? 1 : 0);" 2>/dev/null; then
                php tests/Support/install-parent-schema.php || exit 1
            fi
            php tools/agent-collation-normalize.php || exit 1
            php bin/kumwe database:migrate || exit 1
            php tools/agent-collation-normalize.php || exit 1
        ' >/tmp/agent-setup-schema.log 2>&1; then
        note "Parent schema installed, collations normalized, migrations current."
    else
        note "Schema preparation failed (see /tmp/agent-setup-schema.log); run the steps by hand before the integration lane."
        TIER2=partial
    fi
fi

# ---------------------------------------------------------------------- report
say "Capability report"
printf '   Tier 0 (dependency-free doc/roadmap gates)   %s\n' "yes"
printf '   Tier 1 (static + unit + architecture lane)   %s\n' "$TIER1"
printf '   Tier 2 (database-backed integration lane)    %s\n' "$TIER2"
printf '   Frontend lane (npm run check / build)        %s\n' "$FRONTEND"
printf '\n'
printf '   Always available:  php tools/verify-docblocks.php src && php tools/verify-roadmap.php\n'
[ "$TIER1" = yes ] && printf '   Before any push:   composer qa   (baseline:check ... cs, analyse, test)\n'
[ "$TIER2" = yes ] && printf '   Database lane:     . ./.agent-env && composer test:integration\n'
pg_ready && printf '   Cross-engine lane: DB_DRIVER=pgsql DB_PORT=5432 DB_SERVER_VERSION=16 with the same .agent-env\n'
[ "$TIER1" = yes ] || printf '   BLOCKED: composer dependencies missing — see tools/agent-egress.txt.\n'
printf '\n   Full gate reference: AGENTS.md section 6. Egress allowlist: tools/agent-egress.txt.\n'
