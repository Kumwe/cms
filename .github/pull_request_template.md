## Summary

<!-- One paragraph: what changed and why, written the way the CHANGELOG entry will read. -->

## Identifiers

<!-- Finding IDs, or NRM-YYYY-NNN / KUMWE-CS / KUMWE-MIG when applicable; unplanned work cites the changelog entry. -->

## Capability reuse review

<!-- Required when the diff adds or expands reusable production behaviour under src/ (AGENTS.md section 5). -->

- [ ] Capability required:
- [ ] Exact packages/releases and public symbols inspected:
- [ ] Capability-index digest (`composer kumwe:capability-index`, the `Index digest` line):
- [ ] Decision — reuse / extend the owning package / new focused package / App-specific:
- [ ] Upstream PR and release, when applicable:
- [ ] Core Growth Record (`KUMWE-CGR-YYYY-NNN`) or migration record (`KUMWE-MIG-YYYY-NNN`):
- [ ] Tests proving ownership and absence of duplication:

A reviewer must be able to reproduce the decision from this section alone, without access to any chat history.

## Package test ownership

<!-- Required for every package extraction/adoption, including legacy and native packages. -->

- [ ] Package CI owns portable behavior, boundary/refusal, and applicable semantic conformance tests; link its exact head and test-ownership inventory.
- [ ] Every public type or native ABI capability maps to runnable tests in its owning repository; new exports and stale test references fail the package gate.
- [ ] App test transfers are listed by exact file/method. Mixed suites preserve host authorization, composition, persistence, lifecycle and recovery assertions.
- [ ] Adoption removes duplicate implementation tests with their old classes; `removed_tests` paths are absent and `retained_tests` paths exist in the migration ledger.
- [ ] App neither executes a dependency's unit suite nor attributes vendor classes as App coverage. Cross-layer tests assert the host/binding contract.

## Records moved

<!-- Tick each record this PR regenerated or edited; every one of them is checked by a gate. -->

- [ ] `docs/quality/baseline.json` (`composer baseline:record`)
- [ ] `docs/architecture/capability-index.md` (`composer kumwe:capability-index`)
- [ ] `docs/architecture/governance/core-growth-baseline.json` (`composer kumwe:core-growth-record`)
- [ ] `CHANGELOG.md`
- [ ] `docs/roadmap/STATUS.md` / `docs/roadmap/findings.json`
- [ ] `docs/quality/contract.json`

## Evidence

<!-- Commands run and results; add the cross-engine lane (DB_DRIVER=pgsql DB_PORT=5432) if persistence changed. -->
