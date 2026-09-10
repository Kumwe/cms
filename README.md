# Kumwe App

Kumwe is a modern CMS, built with disciplined engineering and AI acceleration. It is two platforms
behind one set of rules: a content-management system — managed pages, media, nested menus, and
governed publishing workflows in a graphical administrator — and a business application platform — a
typed business definition and record runtime with policies, approvals, reports, and an isolated
client portal. Both halves run through the same application services, so what the browser allows, the
REST API, CLI, MCP tools, workers, and scheduler allow, and what one refuses, the others refuse too.

- Content, media, and navigation are managed records with revisions, workflow states, and audit trails.
- Business definitions declare typed entities, relationships, views, actions, and safe formulas; the
  runtime generates the administrator and portal surfaces, the REST contract, and the CLI/MCP tools.
- The interface is presented in the language a request resolves to, and an operator changes the
  wording their people read — relabelling "Client" as "Patient" or "Learner" — from a screen, per
  message and without a deployment.
- Extensions install through a signed pipeline into a compiled, verified runtime — plugins,
  components, templates, languages, and message catalogues, without rebuilding the application image.
- Automation is durable: database-backed queues, leases, bounded retries, and recurring schedules.

Entry points: [`AGENTS.md`](AGENTS.md) is the operator checklist for changing this repository, the
[coding standard](docs/coding-standard.md), [demo profiles](docs/demo-profiles.md),
[workers and scheduler](docs/automation.md), and the full [documentation index](docs/README.md).

## Quick start: the full demonstration

Docker Engine with Compose v2 is the shortest path. The copied environment selects the documentation
site and the Vast Development Method (VDM) business dataset by default, and `database:migrate`
installs both.

```bash
git clone https://github.com/kumwe/app.git
cd app
cp .env.example .env
docker compose run --rm app composer install --no-interaction --prefer-dist
docker compose run --rm app php bin/kumwe database:migrate
docker compose up -d --wait
curl --fail http://localhost:8080/health/ready
```

The app container runs as user `${KUMWE_UID:-1000}:${KUMWE_GID:-1000}` over the mounted checkout. If
your host user is not UID 1000, export matching identifiers before the first compose command so the
checkout stays writable from inside the container:

```bash
export KUMWE_UID="$(id -u)" KUMWE_GID="$(id -g)"
```

Create the owner account, then complete the demonstration — sign-ins and example extensions — with
one command. Passwords never travel as arguments; they arrive in files only you can read. Paths
passed to the container must be container-visible, which is why the flags below use the `/app` prefix
(the checkout is mounted there):

```bash
install -m 0600 /dev/null .admin-password
# Put a password of at least 12 characters in .admin-password.
docker compose run --rm app php bin/kumwe user:create-admin \
  --email=owner@example.com \
  --name="Site owner" \
  --password-file=/app/.admin-password
docker compose run --rm app php bin/kumwe demo:install \
  --admin-email=owner@example.com \
  --admin-password-file=/app/.admin-password \
  --credentials-file=/app/storage/private/demo-access-credentials.json
rm .admin-password
```

`demo:install` provisions the VDM demonstration cast — five staff accounts and six portal client
organizations with nine members — and installs the shipped example extensions (`announcements`,
`asset-inspection`, and the `horizon-theme` site theme, which installs as
selectable and is never activated for you). Each new account receives a generated password written
exactly twice: to the command output and to the owner-only credentials file, which lands on the host
at `storage/private/demo-access-credentials.json`.

Re-running the command is safe: existing accounts and installed examples are confirmed, no password
is re-issued, and the credentials file is only created on a run that actually generated a new
password — otherwise the command reports that existing sign-ins remain valid and touches nothing.

Sign in at <http://localhost:8080/administrator> with the owner or a staff account, and at
<http://localhost:8080/portal> with a portal member. The site content and business records were
already installed by `database:migrate`; the [getting-started guide](docs/getting-started.md)
continues from here.

### Without Docker

First provision the [native computation runtime](docs/operations/native-runtime.md).
The same flow runs on host PHP 8.5 with MariaDB (or MySQL/PostgreSQL) and Redis reachable from the
process. Point `DB_HOST` and `REDIS_HOST` in `.env` at your services (for example `127.0.0.1`), then:

```bash
composer install
cp .env.example .env   # edit DB_HOST and REDIS_HOST first
php bin/kumwe database:migrate
sh tools/development-server.sh   # serves http://localhost:8080
```

Run the same `user:create-admin` and `demo:install` commands with host-absolute paths — for example
`--admin-password-file="$PWD/.admin-password"` and
`--credentials-file="$PWD/storage/private/demo-access-credentials.json"`. The password and
credentials file rules are identical: absolute paths, regular files, no group or other permission
bits. Composer-project and release-ZIP installations follow the
[production install guide](docs/operations/install.md) instead; `bin/kumwe-install` walks the same
steps interactively.

## Starting clean

For an empty installation, choose the blank datasets in `.env` before the first migration:

```dotenv
KUMWE_SITE_CONTENT_PROFILE=blank
KUMWE_BUSINESS_PROFILE=none
```

Then run the same `database:migrate` and create the first administrator with `user:create-admin` as
above. Each dataset's choice is frozen independently when its first reconciliation begins; later
migration runs refuse a different value rather than switching profiles, so decide before the first
migrate. With `none` selected, `demo:install` skips the demonstration cast cleanly and can still
install the example extensions if you want them. See [demo profiles](docs/demo-profiles.md) for the
selector contract.

## Running it

The development Compose stack runs three services: `app` (the PHP built-in server behind a dedicated
asset router, plus a watcher that keeps the extension runtime verified), `database` (MariaDB by
default; MySQL and PostgreSQL are supported), and `redis`. `docker compose up -d --wait` returns only
once `/health/ready` answers. `KUMWE_HTTP_PORT` in `.env` moves the published port.

Background work is real infrastructure, not an afterthought. Queued report exports are completed by a
worker on the `exports` queue — without one they stay queued forever:

```bash
php bin/kumwe queue:work --queue=default --sleep-ms=1000
php bin/kumwe queue:work --queue=exports --sleep-ms=1000
php bin/kumwe schedule:run --loop
```

Production Compose runs the web, PHP-FPM, one-shot migrate, database, and Redis services, and starts
the same worker and scheduler commands as dedicated services:

```bash
docker compose -f compose.production.yaml --profile automation up -d worker scheduler
```

[Workers and scheduler](docs/automation.md) covers queues, schedules, and operating rules;
[operations](docs/operations/README.md) covers deployment, backup, and upgrades.

## Studio content authoring

Studio is the target contextual page builder and editor for Kumwe content: creating or editing content should open
the same Studio workspace for layout, blocks, typed fields, and values, inline or expanded, without first visiting a
separate Studio or Blueprint catalogue. Extensions can contribute governed Studio blocks and fields to authorized
targets through the same host integration.

That unified journey is not yet complete in App. The current integration opens a Blueprint-only composition route
from an already-created Content-type version; Content model and entry writes still use separate forms. The single
App-side statement of the target, exact current gap, PHP host boundary, and small-goal implementation sequence is
[Studio authoring in Kumwe App](docs/studio-composition-authoring.md).

Studio's browser code is compiled before deployment. Kumwe's server authority is PHP, and an installed production
App never requires Node.js, npm, a JavaScript development server, or a server-side JavaScript process to start,
author, preview, publish, or render content.

## Testing

```bash
composer qa
```

That is the local gate every change runs before it is pushed; CI runs the same
member set. The authoritative member list is
[`docs/quality/contract.json`](docs/quality/contract.json), reproduced in
[`AGENTS.md`](AGENTS.md) section 6 — this file deliberately does not carry a
third copy. A fresh sandbox is provisioned by `bash tools/agent-setup.sh`
([`AGENTS.md`](AGENTS.md) section 0).

Contributors who change browser sources use Node.js and npm to build and test the committed browser assets. These
are source-development commands, not production installation or server-operation steps:

```bash
npm ci
npm run check
npm run build
npm run test:browser
```

Every supported engine — MariaDB, MySQL 8.4, PostgreSQL 17 — runs the same services and migrations;
select one per installation with `DB_DRIVER` and `KUMWE_DATABASE_IMAGE` and re-run the same suites.
[Development and testing](docs/development.md) documents the local and CI contracts;
[CONTRIBUTING.md](CONTRIBUTING.md) and [`AGENTS.md`](AGENTS.md) describe the workflow.

## Contributing and extending

- Read [`AGENTS.md`](AGENTS.md) first; the [coding standard](docs/coding-standard.md) is normative
  for every contributor, human or automated.
- Scaffold an extension with `php bin/kumwe extension:scaffold` and build against
  [extensions](docs/extensions.md) and [templates](docs/templates.md); everything installs through
  the signed pipeline.
- Demonstration data is packaged as versioned [demo profiles](docs/demo-profiles.md). Build a site,
  then turn it into a shareable, installable profile with `php bin/kumwe demo:export-profile` — forks
  ship their own demonstrations by dropping a profile beside the released ones.
- Report problems and propose changes on [GitHub issues](https://github.com/kumwe/app/issues) and
  [discussions](https://github.com/kumwe/app/discussions).

## Supported runtime

| Layer | Supported choice |
|---|---|
| PHP | 8.5 |
| Database | MariaDB current LTS (default), MySQL 8.4 LTS, PostgreSQL 17 |
| Persistence | Doctrine DBAL 4 with one portable schema and repository boundary |
| Redis | Current Redis 8 image line for cache, locks, rate limits, and coordination |
| Web | nginx and PHP-FPM release images |

The machine-readable API contract is [api/openapi/kumwe-v1.json](api/openapi/kumwe-v1.json). Run
`php bin/kumwe list` from an installed release for the CLI command index.

## License

Copyright 2022-2026 Vast Development Method Trading Pty Ltd

Kumwe is free and open source software, licensed under the [Apache License 2.0](LICENSE); see also
the [NOTICE](NOTICE) file.

In plain terms: you are free to use, modify, and distribute Kumwe, in open or closed form —
commercial products and proprietary extensions are welcome. If you build on it, we would love to
hear from you, and patches are always appreciated; the license requires neither. The software is
provided as-is, without warranty or liability, and the Kumwe name and logos are not part of the
code license.
