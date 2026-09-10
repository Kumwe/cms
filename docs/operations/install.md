# Install Kumwe in production

Kumwe ships as versioned Docker images, a Composer project, and a self-contained release ZIP. All methods use PHP 8.5, the same CLI and migrations, and the same administrator. MariaDB LTS is the default database; MySQL 8.4 and PostgreSQL 17 are alternatives.

Every release method already contains Kumwe's compiled browser assets, including Studio. Production installation
and startup require PHP, not Node.js or npm: do not run `npm install`, `npm build`, Vite, or a JavaScript server
on the production host. Node/npm commands elsewhere in this repository belong only to contributor and release
build environments.

Choose an immutable released version, verify it as described in [Release verification](release-verification.md), and keep site configuration outside source control.

## Docker images

This is the recommended reproducible deployment.

### 1. Prepare the release and secrets

Obtain `compose.production.yaml` from the matching signed release. Create four independent secrets outside the project:

```bash
install -d -m 0700 /srv/kumwe/secrets
openssl rand -base64 48 > /srv/kumwe/secrets/app-secret
openssl rand -base64 48 > /srv/kumwe/secrets/runtime-signing-key
openssl rand -base64 32 | tr -d '\n' > /srv/kumwe/secrets/database-password
openssl rand -base64 32 | tr -d '\n' > /srv/kumwe/secrets/redis-password
chmod 0600 /srv/kumwe/secrets/*
```

Set deployment inputs in a protected service environment or operator shell:

```bash
export KUMWE_RELEASE=2.0.0
export KUMWE_APP_IMAGE_REF=ghcr.io/kumwe/app/app:2.0.0
export KUMWE_WEB_IMAGE_REF=ghcr.io/kumwe/app/web:2.0.0
export KUMWE_BASE_URL=https://cms.example.org
export KUMWE_TRUSTED_HOSTS=cms.example.org
export KUMWE_TRUSTED_PROXIES=10.20.0.10
export KUMWE_APP_SECRET_FILE=/srv/kumwe/secrets/app-secret
export KUMWE_RUNTIME_SIGNING_KEY_FILE=/srv/kumwe/secrets/runtime-signing-key
export KUMWE_DEPLOYMENT_ID=production-2-0-0
export KUMWE_REPLICA_ID=cms-primary
export KUMWE_SITE_CONTENT_PROFILE=documentation
export KUMWE_BUSINESS_DEMO=true
export KUMWE_DB_PASSWORD_FILE=/srv/kumwe/secrets/database-password
export KUMWE_REDIS_PASSWORD_FILE=/srv/kumwe/secrets/redis-password
```

Production change control should replace version tags with verified digests. To select MySQL or PostgreSQL, add the coherent variables from [Deployment](deploy.md#database-choice).

The example above installs the default Kumwe documentation site and the separate VDM business demonstration. For a
clean production database with no example pages or business records, set both values before the first migration:

```bash
export KUMWE_SITE_CONTENT_PROFILE=blank
export KUMWE_BUSINESS_DEMO=false
```

`placeholder` remains available as the compact legacy site-content choice. Profile selection is not a credential
bootstrap: no option creates a user, password, token, or secret. Create every production identity explicitly, as in
step 3 below.

Each dataset's choice is frozen independently when its first reconciliation passes validation and begins. Once
recorded, a later failure does not release it. Keep each recorded value stable in the service environment for every
subsequent migration. A mismatch is refused so an upgrade cannot silently switch datasets. To recover from an
accidental configuration change, restore the original values and rerun the migration;
customize installed examples through the normal administrator and business services instead of changing selectors.
On later releases, untouched site-content fixtures may advance or retire while operator changes remain untouched. VDM
definitions may advance only while untouched. Operators may edit runtime records normally; applied VDM manifest
create, relation, action, archive, and policy checkpoints are immutable, and later manifests may append new operations
but may not rewrite or remove an applied fixture.

### 2. Validate and start

```bash
docker compose -f compose.production.yaml config --quiet
docker compose -f compose.production.yaml pull
docker compose -f compose.production.yaml up -d
docker compose -f compose.production.yaml ps
curl --fail --silent http://127.0.0.1:8080/health/ready
```

The one-shot `migrate` service applies the portable Doctrine schema and reconciles the frozen initial-data profiles
before `app` starts. The shared Compose environment passes both selectors to that service and every application
process. Add `--profile automation` to run the worker and scheduler.

### 3. Create the owner

```bash
install -m 0600 /dev/null /srv/kumwe/secrets/administrator-password
# Put a password of at least 12 characters in the file.
docker compose -f compose.production.yaml run --rm \
  --volume /srv/kumwe/secrets/administrator-password:/run/secrets/administrator-password:ro \
  app php bin/kumwe user:create-admin \
  --email=owner@example.com \
  --name="Site owner" \
  --password-file=/run/secrets/administrator-password
rm /srv/kumwe/secrets/administrator-password
```

Open the canonical `/administrator` URL, sign in, and follow [Administrator](../administration.md).

## Composer project

Provision the [native computation runtime](native-runtime.md) before running Composer on the host.
The published Docker images already include it.

Composer can deploy the complete CMS as a project package. The post-create hook starts an interactive installer when run in a terminal. Once `kumwe/app` is registered on Packagist, use:

```bash
composer create-project kumwe/app:^2.0 /srv/kumwe \
  --no-dev \
  --prefer-dist
```

Until Packagist registration is complete, resolve the signed GitHub release through its VCS repository:

```bash
composer create-project \
  --repository='{"type":"vcs","url":"https://github.com/kumwe/app.git"}' \
  kumwe/app:^2.0 /srv/kumwe \
  --no-dev \
  --prefer-dist
```

The installer asks for the canonical HTTPS URL, initial site-content and business-demo profiles,
MariaDB/MySQL/PostgreSQL connection, table prefix, Redis connection, and first administrator. It writes an
owner-readable `.env`, runs `database:migrate`, and creates the owner through the public CLI. It generates application
secrets locally and never derives a known credential from the selected profile.

For non-interactive automation, Composer installs files without guessing credentials. Run the installer in a protected terminal before serving the site:

```bash
cd /srv/kumwe
php bin/kumwe-install
```

Requirements for a native installation:

- Linux x86_64 with glibc and the admitted `ext-kumwe_engine` 1.0.3 native runtime;
- PHP 8.5 with all extensions required by `composer.json` and the PDO driver for the selected database (`pdo_mysql` for MariaDB/MySQL or `pdo_pgsql` for PostgreSQL);
- Composer 2 for installation and locked upgrades;
- a supported database and Redis 8 endpoint;
- writable `storage/` and `extensions/` for the PHP-FPM service account;
- a web server whose only document root is `/srv/kumwe/public`;
- separate long-running `queue:work` and `schedule:run --loop` services.

Give each native long-running service a stable, distinct `KUMWE_PROCESS_ID` override (for example,
`application-runtime`, `queue-worker`, and `scheduler`). Keep the generated deployment and replica
identities stable for that deployment; concurrently running replicas must use distinct replica IDs.

Composer scripts never run with a web-server identity or an unrestricted database administrator account. After installation, remove interactive shell access that is not operationally required and protect `.env` from the web server and backups according to the site's secret policy.

## Release ZIP

The ZIP contains production dependencies and is intended for hosts that cannot run Composer on the server.

1. Download `kumwe-app-VERSION.zip`, `SHA256SUMS`, and the Cosign bundle from the GitHub release.
2. Verify the checksum, signature, and provenance.
3. Extract into a new versioned directory, never over a running release.
4. Run `php bin/kumwe-install` in a protected terminal.
5. Set the web document root to the extracted `public/` directory.
6. Configure worker and scheduler services, then run the [deployment acceptance](deploy.md#post-deployment-acceptance).

Example extraction:

```bash
install -d -m 0750 /srv/kumwe/releases/2.0.0
unzip -q kumwe-app-2.0.0.zip -d /srv/kumwe/releases/2.0.0
cd /srv/kumwe/releases/2.0.0
php bin/kumwe-install
```

Keep `.env`, media, extension packages, sessions, logs, and other persistent state outside disposable release directories where the host permits. Use an atomic symlink or release switch for upgrades.

## Complete installation checklist

- `/health/live` and `/health/ready` succeed through the real proxy.
- The persisted content/business profile selections match the deployment environment and the chosen fixtures are present or absent as intended.
- The owner can sign in and a limited account is denied a forbidden route.
- A page can complete the workflow and render publicly.
- Menu, user/group, setting, extension, REST, MCP, worker, and scheduler smoke checks pass.
- Backup creation, backup verification, and clean-target recovery are exercised.
- Exact application, web, database, and Redis digests are recorded.

Continue with [Production deployment](deploy.md), [Monitoring](monitoring.md), and [Backup and restore](backup-restore.md).
