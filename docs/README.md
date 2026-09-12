# Kumwe documentation

The [Kumwe Interface Standard 1.0](interface-standard/README.md) is the normative contract for all
administrator, portal, generated, extension, and installable-template graphical surfaces.

Use this index to install, operate, administer, integrate, extend, or evolve Kumwe.

## Install a site

- [Getting started](getting-started.md): launch the development stack and create the owner.
- [Configuration](configuration.md): administrator settings, environment variables, secrets, database selection, and Redis.
- [Demo profiles](demo-profiles.md): discoverable content and business demonstration datasets, selection, and authoring.
- [Production installation](operations/install.md): released Docker images, Composer project, and release ZIP.
- [Production deployment](operations/deploy.md): topology, image pinning, proxy boundary, and deployment checks.

## Use and administer Kumwe

- [Administrator](administration.md): content workflow, menus, users, groups, permissions, settings, tokens, extensions, and templates.
- [Business definitions](business-definitions.md): typed entities, fields, relationships, safe formulas, publication, and extension ownership.
- [Transactional business runtime](business-runtime.md): schema plans, typed relational records, bounded queries, recovery, and lifecycle.
- [Business groups](business-groups.md): several related businesses on one installation, declared groups, shared master data, isolated accounting, and consolidated reporting.
- [Business security](business-security.md): typed policy, field disclosure, record encryption keys, memberships, approvals, step-up, and tokens.
- [Generated business surfaces](architecture/generated-business-surfaces.md): shared UI, REST/OpenAPI, CLI, MCP, and custom-handler runtime.
- [Machine-contract boundaries](machine-contract/README.md): extension SDK versus Dart client SDK, REST/CLI/MCP ownership, and the production `app`/`web` split.
- [Ordinary-user portal](portal.md): isolated sessions, account security, approvals, and trusted contributions.
- [Command-line interface](cli.md): installation, health, tokens, extensions, workers, schedules, and MCP stdio.
- [Workers and scheduler](automation.md): durable jobs, retries, recurring work, and worker operation.
- [Templates](templates.md): build, install, activate, and verify a public design.
- [Interface translation](interface-translation.md): locale negotiation, the message-identifier grammar, the
  catalogue override chain that also adapts a vertical's terminology, and right-to-left presentation.
- [Content translation](content-translation.md): translation groups, per-locale slugs and publication state,
  the declared fallback, automatic `hreflang`, the shipped language selector, and locale variants on
  business definition labels and on extension-contributed content.

## Integrate and extend Kumwe

- [REST API](rest-api.md): authentication, content, navigation, identity, optimistic concurrency, and retry safety.
- [MCP](mcp.md): stdio and Streamable HTTP transports, capabilities, tools, resources, and safe writes.
- [Studio authoring in Kumwe App](studio-composition-authoring.md): the single App-side mapping from Studio's
  canonical product contract to Content authoring, PHP authority, production runtime requirements, extension
  targets, and integration qualification. See also the
  [artifact/recovery boundary](studio-artifact-persistence.md) and [media host adapter](studio-media-host.md).
- [Studio content projection](studio-content-projection.md): read-only Content models and entries,
  Blueprint bindings, composition overrides, lossless mappings, and fail-closed diagnostics.
- [Extensions](extensions.md): manifests, providers, events, migrations, dependencies, signatures, lifecycle, and tests.
- [Business integrations and extension SDK](business-integrations.md): schema-4 events, inbox/outbox, automation,
  reports, projections, compatibility, conformance, and recovery.
- [OpenAPI contract](../api/openapi/kumwe-v1.json): machine-readable REST v1 schema.

## Operate production

- [Runnable production demonstration](demonstration.md)
- [Operations index](operations/README.md)
- [Monitoring and health](operations/monitoring.md)
- [Backup and restore](operations/backup-restore.md)
- [Upgrade](operations/upgrade.md)
- [Release verification](operations/release-verification.md)
- [Incident response](operations/incident-response.md)
- [Security policy](../SECURITY.md)

## Maintain and evolve the project

- [Operator checklist](../AGENTS.md): the one road for any agent or person changing this repository — where
  code lives, which gate watches a given change, and the recipes that keep the quality lane green.
- [Architecture folder map](architecture/map.md): if you want to touch X, that is where Y lives.
- [Development and testing](development.md): local checks, database matrix, deployment tests, and release gates.
- [Architecture guide](architecture/README.md): stable boundaries, persistence choice, delivery parity, extension model, and growth paths.
- [Coding standard](coding-standard.md): documentation blocks, types, naming, structure, and errors, for human and agent contributors.
- [Contributing](../CONTRIBUTING.md): repository workflow and contribution requirements.
- [Programme status](roadmap/STATUS.md): live open work. Finished work is in [`CHANGELOG.md`](../CHANGELOG.md).

Run `php bin/kumwe list` in an installed release for the exact CLI command index. Public documentation describes released behavior; temporary plans and internal implementation status do not belong here.
