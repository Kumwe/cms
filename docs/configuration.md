# Configuration

Kumwe separates site settings from deployment configuration.

- A user with `settings.manage` changes safe site behavior in **Administrator → Settings**. These values are audited and stored in the database.
- Operators provide infrastructure, credentials, trusted-network boundaries, and release identity through environment variables or protected secret files. These values are intentionally unavailable to browser users.

This prevents a compromised administrator session from changing database credentials, reading secrets, disabling HTTPS boundaries, or replacing the running release.

## Administrator settings

The settings screen manages:

| Setting | Purpose |
|---|---|
| Site name | Public name used by the active template |
| Homepage page | Stable ID of the managed Page rendered at `/` |
| Default locale | Default language/locale identifier |
| Timezone | Site scheduling and display timezone |
| Search indexing enabled | Whether public indexing is permitted |
| Site logo and footer | Global public identity, independent of homepage fields |
| Primary menu | Database menu used for public navigation and canonical paths |
| Presentation schemes | Reusable validated palettes, active scheme, browser color mode |
| Interaction treatment | Button style, button shape, and header treatment |

Saving settings requires `settings.manage`, a valid administrator session, and a CSRF token. The update records the actor and changed keys in the audit log. Settings supplied by an extension must declare a schema, validation rules, secret classification, and capability before they appear in the interface.

The clean installation seeds the corporate navy-and-teal scheme, plus ocean and graphite alternatives. Administrators can add, edit, remove, and select schemes without editing JSON or CSS. Kumwe validates every color as an exact `#RRGGBB` token, enforces WCAG AA text contrast for the core foreground/background pairs, and renders only fixed CSS-property names, so database-managed design choices cannot inject arbitrary stylesheet rules. The built-in Twig site reads the selected values immediately; an installed site template may replace markup while still receiving the same managed presentation view model.

Disabling search indexing makes the dynamic `/robots.txt` disallow crawling and adds `X-Robots-Tag: noindex, nofollow, noarchive` to public page responses. It is a crawler instruction, not access control; keep private content unpublished and protected by authorization.

## Application environment

Start from `.env.example` for development. Production Compose maps operator-facing `KUMWE_*` variables into these application variables.

| Variable | Meaning | Production guidance |
|---|---|---|
| `APP_ENV` | `development`, `testing`, or `production` | `production` |
| `APP_DEBUG` | Detailed development failures | `false` |
| `APP_BASE_URL` | Canonical absolute URL | HTTPS URL |
| `APP_PUBLIC_SITE` | Explicit site served by public content and theme routes | Canonical site identifier; defaults to `default` |
| `KUMWE_SITE_CONTENT_PROFILE` | Initial managed content, discovered from the shipped `resources/demo/content` manifests; this release ships `documentation`, `placeholder`, and `blank` | `documentation` |
| `KUMWE_BUSINESS_PROFILE` | Named business demonstration dataset, discovered from `resources/demo/business`, or `none`; wins over `KUMWE_BUSINESS_DEMO` when set | `vdm` or `none` |
| `KUMWE_BUSINESS_DEMO` | Legacy boolean alias selecting the released VDM business example (`true`) or `none` (`false`) | Prefer `KUMWE_BUSINESS_PROFILE` |
| `APP_TRUSTED_HOSTS` | Comma-separated accepted hostnames | Exact public hosts |
| `APP_TRUSTED_PROXIES` | Comma-separated proxy address ranges | Only the actual proxy network |
| `APP_MAX_BODY_BYTES` | Maximum parsed request body | Match proxy and PHP limits |
| `APP_ADMIN_SESSION_SECONDS` | Administrator session lifetime | 300–604800 seconds |
| `APP_SECRET` | Session and application secret | At least 32 random bytes; prefer `APP_SECRET_FILE` in containers |
| `EXTENSION_RUNTIME_SIGNING_KEY_ID` | Active versioned runtime-publication key ID | Stable lowercase identifier |
| `EXTENSION_RUNTIME_SIGNING_KEY` | Dedicated runtime-publication signing secret | Independent 32+ byte secret file |
| `EXTENSION_RUNTIME_PREVIOUS_KEYS` | JSON key-ID/secret overlap set | Retain only during controlled rotation |
| `RECORD_ENCRYPTION_KEY` | Dedicated business-record secret-field key | Independent 32+ byte secret; unset keeps the `APP_SECRET`-derived key |
| `RECORD_ENCRYPTION_KEY_ID` | Identifier new record envelopes carry | Stable versioned identifier; requires `RECORD_ENCRYPTION_KEY` |
| `RECORD_ENCRYPTION_PREVIOUS_KEYS` | JSON key-ID/secret set for retired record keys | Retain until re-encryption and revision retention have passed |
| `RECORD_ENCRYPTION_LEGACY_SECRET` | Previous `APP_SECRET`, so `application-secret-v1` survives its rotation | Set before rotating `APP_SECRET`; drop after re-encryption |
| `KUMWE_RELEASE` | Running release identifier | Exact deployed version |
| `KUMWE_DEPLOYMENT_ID` | Stable rollout identity | Explicit deployment identifier |
| `KUMWE_REPLICA_ID` | Stable replica identity | Unique per concurrently running replica |
| `KUMWE_PROCESS_ID` | Stable process role identity | `app-runtime`, `queue-worker`, or `scheduler` |
| `EXTENSIONS_ALLOW_UNSIGNED_LOCAL` | Allow unsigned local packages | Must be `false` when `APP_ENV=production`; boot refuses the combination |
| `EXTENSIONS_CONFORMANCE_ADMISSION` | Whether admission collects advisory authoring checks | `scan` (default); `off` skips only advisory checks and is refused in production. The earlier `enforce` and `warn` spellings are still accepted and select `scan` |
| `EXTENSIONS_REVOCATION_FEED_URL` | Upstream revocation list origin | Absolute `https://` URL or absolute path to a local mirror; unset consumes no feed |
| `EXTENSIONS_REVOCATION_FEED_KEY` | Pinned Ed25519 public key the feed is verified against | Base64 32-byte key, or `_FILE`; required with the URL and never taken from the trust store |
| `EXTENSIONS_REVOCATION_FEED_MAX_STALE_SECONDS` | How long a verified fetch stays fresh | 3600 to 2592000; default 172800, after which the feed reads as stale |
| `KUMWE_LOG_LEVEL` | Lowest severity written to the log stream | Unset; `config/observability.php` declares `info`. Set it — not `APP_DEBUG` — to change verbosity |
| `KUMWE_METRICS_ENABLED` | Whether `/metrics` answers at all | Unset (off). Set `true` only where a scraper exists |
| `KUMWE_METRICS_TOKEN` | Bearer token a private `/metrics` requires | 32+ random bytes; prefer `KUMWE_METRICS_TOKEN_FILE` in containers |
| `KUMWE_STUDIO_BROWSER_BASE_URL` | Base the pinned Studio browser module and enhancement runtime load from, in the npm package layout `<base>/<package>@<version>/dist/browser/<path>` | `https://cdn.jsdelivr.net/npm` (default); a self-hosted mirror or site-absolute path keeping the same layout for air-gapped sites |
| `EXTENSIONS_ALLOW_UNSIGNED_LOCAL` | Allow unsigned local packages | `false` in production |

`KUMWE_STUDIO_BROWSER_BASE_URL` decides where a browser fetches the two Studio runtime assets the App never
serves itself: the contextual authoring module the Content editor mounts and the enhancement runtime published
pages defer. Producer pins both to exact package paths and subresource-integrity values, so whichever origin is
configured, a byte that differs from the pinned release fails in the browser instead of running. The default is
the public npm registry CDN; an exact HTTPS mirror or a site-absolute path (for example `/vendor/npm`, served by
the web server from an extracted copy of the eight packages) keeps the same `<package>@<version>/dist/browser/`
layout. Only the configured origin is added to the `script-src` directive of the pages that load it; every
other directive stays same-origin.

`KUMWE_LOG_LEVEL` exists so log verbosity stops riding on `APP_DEBUG`. Turning debug on to chase one
incident also widens the detail `ProblemDetailsMiddleware` puts into a 500 response, which turns a
logging decision into a disclosure decision; the level variable changes only what is written. It accepts
the Monolog names in lower case — `debug`, `info`, `notice`, `warning`, `error`, `critical`, `alert`,
`emergency` — and a value outside that set stops the boot rather than defaulting.

### Initial data profiles

`database:migrate` reconciles example data after the schema is current. The two selectors are independent:

| Site content | Business demo | Result on a new database |
|---|---|---|
| `documentation` | `true` | Kumwe learning site plus the VDM business workflow; the default |
| `documentation` | `false` | Kumwe learning site without business examples |
| `placeholder` | Either | Original compact homepage, with VDM data only when enabled |
| `blank` | `true` | Empty managed site plus the VDM business workflow |
| `blank` | `false` | Empty primary menu, no homepage content, and no VDM business records |

Set these values before the first migration. Each dataset is validated independently. When its first reconciliation
begins, Kumwe persists the selection, manifest version, and canonical manifest checksum; stable fixture-to-resource
mappings and last-applied states are recorded as fixtures are applied. Once a selection is recorded, a later failure
does not release it, and later runs refuse a different profile instead of silently replacing installed data. Restore
the original environment value if an upgrade reports selection drift; changing profiles in place is not a migration
or recovery mechanism.

Released site-content manifests reconcile repeatably: untouched fixtures may advance or retire, while administrator
changes remain untouched. VDM definitions may advance only while untouched. Operators may edit runtime records
normally; applied VDM manifest create, relation, action, archive, and policy checkpoints are immutable. A later
manifest may append a new operation but may not rewrite or remove an applied fixture. The business example contains
fictional operational data and public VDM context, but no user, password, API token, or secret. Administrator and
portal identities are always created explicitly through normal security workflows.

### Development Compose HTTP binding

The development `compose.yaml` has an explicit host-port contract separate from the container's fixed internal port:

| Variable | Default | Purpose |
|---|---|---|
| `KUMWE_HTTP_HOST` | `localhost` | Hostname used to derive the development `APP_BASE_URL` |
| `KUMWE_HTTP_BIND` | `127.0.0.1` | Host interface on which Docker publishes the port |
| `KUMWE_HTTP_PORT` | `8080` | Published host port and canonical development URL port |

Set `KUMWE_HTTP_PORT=9900` to serve the Compose installation at `http://localhost:9900`. Compose injects the corresponding `APP_BASE_URL` automatically while the PHP server continues to listen on port 8080 inside the container. `APP_BASE_URL` remains the canonical setting for direct PHP execution and production mapping, but changing it alone cannot alter a Docker port publication.

The development app starts through `tools/development-server.sh`. That launcher verifies the signed extension runtime before accepting traffic, refreshes the readiness marker continuously, and uses the dedicated static-file router. A successfully migrated development site must therefore keep `/health/ready` at HTTP 200 and serve compiled CSS, JavaScript, media, and extension assets directly.

`APP_TRUSTED_PROXIES` accepts individual IPv4/IPv6 addresses and CIDR ranges (for example,
`10.20.0.10,192.0.2.0/24,2001:db8:5::/64`). Never set it to all networks. Kumwe accepts `Forwarded`, or the
`X-Forwarded-For`, `X-Forwarded-Proto`, `X-Forwarded-Host`, and `X-Forwarded-Port` family, only from a matching
immediate peer. Configure the edge proxy to replace client-supplied forwarding headers. Kumwe walks proxy chains
from right to left and stops at the first untrusted address; malformed or ambiguous metadata is discarded atomically.

## Database

| Variable | MariaDB default | MySQL | PostgreSQL |
|---|---|---|---|
| `DB_DRIVER` | `mariadb` | `mysql` | `pgsql` |
| `DB_HOST` | `database` | `database` | `database` |
| `DB_PORT` | `3306` | `3306` | `5432` |
| `DB_SERVER_VERSION` | Release-compatible MariaDB version string | `8.4` | `17` |
| `DB_TABLE_PREFIX` | `kumwe_` | `kumwe_` | `kumwe_` |

`DB_NAME`, `DB_USER`, and `DB_PASSWORD` identify the database. Production containers load the password from `DB_PASSWORD_FILE`. `DB_SSLMODE` accepts `disable`, `prefer`, `require`, `verify-ca`, or `verify-full`; public or cross-network database connections should use certificate verification.

The table prefix is a canonical lowercase identifier of at most 28 bytes. It starts with a letter, separates
segments with one underscore, and ends in one underscore (for example, `tenant_eu_`). This preserves prefix
identity and leaves room for every core table within the portable 63-byte identifier limit. The database account
needs permission to create and alter Kumwe-prefixed tables while migrations run. It does not need global or
server-administration privileges.

## Redis

| Variable | Purpose |
|---|---|
| `REDIS_HOST` | Redis hostname |
| `REDIS_PORT` | Redis port, normally `6379` |
| `REDIS_PASSWORD` | Redis authentication secret; use `REDIS_PASSWORD_FILE` in containers |
| `REDIS_DATABASE` | Logical database number, `0` by default |
| `REDIS_NAMESPACE` | Installation-specific key prefix, `kumwe.app` by default |
| `KUMWE_REDIS_IMAGE` | Redis image reference used by Compose |

The supplied Compose deployment tracks the supported Redis 8 line for easy updates. Production change control should resolve and record the tested digest before rollout. Redis backs administrator-login throttling and supplies the shared runtime primitives for disposable caches and distributed locks; the selected relational database remains authoritative. Use a distinct namespace for installations that share a Redis endpoint.

## Secret files

The production entrypoint recognizes `APP_SECRET_FILE`, `EXTENSION_RUNTIME_SIGNING_KEY_FILE`, `DB_PASSWORD_FILE`, and `REDIS_PASSWORD_FILE`. Each file must be readable only by the deployment service, contain exactly one non-empty secret, and remain outside the repository and release package. The entrypoint loads the value into the process and unsets the file-variable name before launching PHP.

The application itself resolves `APP_SECRET_FILE`, `RECORD_ENCRYPTION_KEY_FILE`, `RECORD_ENCRYPTION_PREVIOUS_KEYS_FILE`, `RECORD_ENCRYPTION_LEGACY_SECRET_FILE`, and `EXTENSION_RUNTIME_PREVIOUS_KEYS_FILE`, so a bare-metal or systemd deployment gets the same mounted-secret discipline without an entrypoint of its own. Each path must be absolute, a readable regular file, and not a symbolic link. Supplying both a variable and its `_FILE` companion is refused at boot rather than resolved by precedence. Record-key provisioning and the rotation procedure are described in [business security](business-security.md#record-encryption-key-lifecycle).

After changing process configuration, replace the affected web and worker containers. After changing administrator settings, no container restart is required.
