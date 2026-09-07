# Independent release publication check — 2026-09-07

All five selected releases are unpublished and verification is blocked. The human PR merges are confirmed and merged-head implementation CI passes. Release-on-record stops at the protected-main prerequisite before creating tags or releases. Every live main branch reports `protected: false`; ruleset lists are empty.

| Package | Selected version | Observed main / merge SHA | Failed release run |
|---|---|---|---|
| Canonical JSON | 0.1.0 | d96697a6372890d6d67ca65ffdf2bd203f685cbb | https://github.com/kumwe/canonical-json/actions/runs/34116151642 |
| Computation | 0.1.0 | e9664532e95858ea533270e1b5adc73f742104c5 | https://github.com/kumwe/computation/actions/runs/34116072487 |
| Access Context | 0.1.1 | d75ce7d2c17c14febb943a4e726d579235ceaa6e | https://github.com/kumwe/access-context/actions/runs/34116359776 |
| Localization | 0.1.1 | 1dbdfcc6410d02d2561133f7c92666d2a4790d1f | https://github.com/kumwe/localization/actions/runs/34116318416 |
| Contribution | 0.1.1 | 8155757141699d7cd1da8499644662846d66be95 | https://github.com/kumwe/contribution/actions/runs/34116236940 |

Each expected tag/release lookup returns 404. Canonical JSON and Computation have empty GitHub release lists and empty Packagist version arrays. Access Context and Localization expose only 0.1.0 on Packagist. Contribution's registry request was not completed; its GitHub release/tag absence is independently confirmed. The three existing 0.1.0 GitHub releases remain `immutable: false`; prior failed release records are retained separately and were not reverified here.

No candidate source/dist consumer was run because there is no published candidate artifact. No release attestation was emitted: the current D-GOV-4 schema requires an observed release tag and source archive digest even for failed records. Supplying them for these unpublished candidates would fabricate evidence. The separate diagnostic JSON records explicitly preserve `unpublished` and `blocked` status.

Maintainers must configure protected main and enable immutable releases before retrying release-on-record. These checks must succeed against actual published artifacts in a new verification pass before any dependent publication or App adoption. No repository source, PR, branch, tag, release, or settings were changed by verification.

App PR 135 remains open, ready, and mergeable with a clean status at head `f406b40747c4da2e6e74544809be2fa2ff79f3d1`. All three workflows on that exact head completed successfully: Kumwe CI 34114632034, Development Compose acceptance 34114631863, Kumwe security 34114631872. The complete workflow observation is in `app135-head-workflows.json`.

Observed API snapshots, logs, registry evidence, and evidence SHA-256 inventories are in each package directory. Diagnostics are not release attestations and authorize no downstream state transition.
