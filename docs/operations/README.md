# Operations

These runbooks cover production installation, deployment, monitoring, recovery, upgrades, release verification, and incident response.

- [Native runtime](native-runtime.md): pinned Engine build, PHP ABI admission, and host provisioning.
- [Install](install.md): Docker images, Composer project, or release ZIP.
- [Deploy](deploy.md): hardened container topology, database choice, image pinning, and acceptance.
- [Configuration](../configuration.md): environment, secrets, database, Redis, and browser-managed settings.
- [Monitor](monitoring.md): health contracts, signals, logs, and audit records.
- [Back up and restore](backup-restore.md): complete backup, verification, clean-target recovery, and drills.
- [Upgrade](upgrade.md): forward-only migrations and atomic application replacement.
- [Verify releases](release-verification.md): checksums, signatures, provenance, images, and SBOMs.
- [Respond to incidents](incident-response.md): preservation, containment, recovery, and review.

Run commands as an unprivileged deployment account. Keep TLS termination, firewall policy, secret storage, and off-host backup storage outside Kumwe's application release. For commands that mutate durable state, capture the exact release and database engine in the change record.
