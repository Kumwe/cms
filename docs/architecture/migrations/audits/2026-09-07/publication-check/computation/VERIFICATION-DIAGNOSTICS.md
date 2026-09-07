# computation 0.1.0 verification blocked

No published candidate exists. Human merge of [PR #1](https://github.com/kumwe/computation/pull/1) is observed at `e9664532e95858ea533270e1b5adc73f742104c5`; main is unprotected. The [release workflow](https://github.com/kumwe/computation/actions/runs/34116072487) failed at “Require maintainer-protected main before publication”. Tag and release probes return 404, and Packagist lists no versions.

No archive or post-publication consumer work can be verified. Dependent work remains blocked. A maintainer must resolve branch protection and publication prerequisites, then a fresh verifier must check the actual immutable release.

D-GOV-4 prescribes the release-attestation schema for failed results, but its required observed tag/archive URL/archive SHA-256 fields cannot be populated for an absent artifact. No schema-valid attestation was fabricated. See VERIFICATION-DIAGNOSTICS.json for the explicit schema limitation and evidence inventory.
