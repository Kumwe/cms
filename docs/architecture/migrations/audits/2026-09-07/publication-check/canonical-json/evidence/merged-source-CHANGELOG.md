# Changelog

## Unreleased

## 0.1.0

### Added

- Generic canonical JSON semantic profile, portable finding vocabulary and bounded operation metadata.
- Language-neutral conformance corpus and explicit native/PHP FQCN ownership contract.
- Package-owned semantic, boundary, corpus, architecture, public API and clean archive consumer gates.
- Phase 1 handoff for KUMWE-MIG-2026-007 / KUMWE-CS-2026-007, NRM-2026-009.

### Compatibility

- No executor is shipped. App production behavior and its executor tests remain unchanged.
- Explicit depth, node and output bounds tighten previously unbounded traversal. Future cutover must
  prove consumer compatibility before adopting those refusals. Definition/runtime/OpenAPI profiles
  are deliberately not unified with the generic profile.
