# Published releases and the following extraction batch

## Latest merge follow-up

The next five package PRs are now merged, but their selected releases remain unpublished. Fresh independent
checks confirmed that all five release workflows stopped at the protected-main precondition before tag or
release creation. The full observation and exact workflow links are in
[publication-check/README.md](publication-check/README.md). These are publication diagnostics, not passing
release attestations. The earlier failed 0.1.0 evidence below remains historical evidence for those versions.

Canonical JSON and Computation 0.1.0, plus Access Context, Localization and Contribution 0.1.1, still need
protected-main publication, immutable release configuration and fresh artifact verification. Human merge
and green implementation CI alone do not satisfy that gate. App PR #135's preceding head
`f406b40747c4da2e6e74544809be2fa2ff79f3d1` completed CI, Compose acceptance and security successfully.

The maintainer merged the six package PRs after the initial audit. Their release tags identify the
human merge commits below, and their merged-source package CI and release-on-record workflows passed.
Publication and successful code checks are separate from independent release verification.

| Package | Published version | Release source commit |
| --- | --- | --- |
| [Transaction](https://github.com/kumwe/transaction/releases/tag/v0.1.1) | 0.1.1 | `701f426feb33efc85cf6d5efc766fd6b865d2e9d` |
| [Sequence](https://github.com/kumwe/sequence/releases/tag/v0.2.0) | 0.2.0 | `021c63f5f768daadcdb476f810e8d1fdbf2e2938` |
| [Secret Envelope](https://github.com/kumwe/secret-envelope/releases/tag/v0.1.0) | 0.1.0 | `46faafab5b7c2e418cd4c8264eea4eef7a6fbe93` |
| [Access Context](https://github.com/kumwe/access-context/releases/tag/v0.1.0) | 0.1.0 | `34241cbd0cc67934536d2921eca14b063be6fb81` |
| [Localization](https://github.com/kumwe/localization/releases/tag/v0.1.0) | 0.1.0 | `672e330e2cae89dc71769d647f6d01fd2c1dea08` |
| [Contribution](https://github.com/kumwe/contribution/releases/tag/v0.1.0) | 0.1.0 | `0504e87c836ca61edadc92df4203d6ccba8f0eca` |

## Independent release results

Fresh verification sessions with no Phase 1 conversation history examined Access Context, Localization
and Contribution because these are the direct inputs to the next dependent framework families.
Exact source, tag, merge, Packagist and downloaded distribution identities agreed. The independently
downloaded archives installed in fresh no-development, authoritative Composer consumers. Their public
symbols, examples, archive contents and relevant DI lifetimes passed.

The three results remain **failed** under the supplied Version 2 release protocol:

- GitHub reports mutable release objects and no enforcing tag rulesets. Full commit IDs and archive
  digests identify the tested content, but do not establish an enforced immutable version binding.
- Current `main` branches report `protected: false`. The required protected-main release path cannot be
  attested. Human merges are observed; the review does not claim knowledge of historical settings.
- Published SBOM and signed-provenance artifacts were not located. Their package-specific applicability
  is recorded as unresolved; this is not the sole failure basis.

The external results and supporting diagnostic records are staged under this audit's `release-results/`
directory. They are deliberately outside the released artifacts and are not App adoption records.
They must not be described as successful release attestations. A later consumer migration uses the
schema-prescribed evidence path only when its actual migration ledger and release evidence exist.

- [Access Context result](release-results/access-context/RELEASE-VERIFICATION-FAILED.yaml)
- [Localization result](release-results/localization/RELEASE-VERIFICATION-FAILED.yaml)
- [Contribution result](release-results/contribution/RELEASE-VERIFICATION-FAILED.yaml)

## Required maintainer release configuration

Version 2 sections 07 and 11 require protected-main publication and immutable release coordinates.
Configure the package release branches to require reviewed PRs and their package CI checks, and prevent
force pushes and deletion. Enable GitHub release immutability before the merge that publishes the next
release. GitHub documents the setting under repository **Settings > General > Releases**, or the
organization's repository release policy. The setting applies to future releases, so enabling it alone
does not retroactively verify an existing version.

See GitHub's [release protection instructions](https://docs.github.com/en/code-security/how-tos/secure-your-supply-chain/establish-provenance-and-integrity/prevent-release-changes)
and [immutable release guarantees](https://docs.github.com/en/code-security/concepts/supply-chain-security/immutable-releases).

Preserve existing versions and failed evidence. Publish a separately reviewed successor under the
required protections, then repeat independent verification against that exact source and artifact.
Do not move a published tag, silently reinterpret a failure as verified, or use an unverified branch
as a dependency. Repository administration and publication were not performed by this audit.

## Current independent package work

- [Canonical JSON #1](https://github.com/kumwe/canonical-json/pull/1) establishes semantic metadata,
  explicit serialization rules and a language-neutral conformance corpus. It supplies no production PHP
  encoder. Distinct App canonicalizer families retain their own semantics and execution tests until the
  ordered native cutover.
- [Computation #1](https://github.com/kumwe/computation/pull/1) implements the Phase 1A contract baseline
  after independent review of the transport and native ownership draft. Its metadata, program/plan,
  bounded batch/result, finding, compatibility and refusal contracts select no unverified semantic
  dependency. Opaque payload coordinates do not attest the payload's semantic validity or release.
  This baseline supplies no native binding, executor algorithm, provider or App runtime cutover.

The two packages own their contract, boundary, hostile-input, corpus and archive tests. Their release
documentation carries the same maintainer configuration and independent-verification prerequisites.
Neither package establishes Engine availability, native parity, App adoption or roadmap acceptance.

## Prepared dependent boundaries

Access Control's refreshed closure identifies 35 existing portable types. Its canonical `Capability`
currently belongs to Extension SDK, so SDK successor adoption must precede App integration. Concrete
authorization, closed system identities, trusted activation and built-in ownership tables remain App
responsibilities. Numeric-string identifier loss and control-byte admission need package regressions.

Business Definition's plausible closure contains 36 existing portable types. It must reuse the three
Sequence types, resolve the SDK-owned field-presentation contract outside its dependency ceiling, and
preserve site ownership when composing Contribution. Formula AST/validation semantics can move, while
the existing PHP execution APIs cannot ship as a runtime substitute for the planned native VM.

Its 19 directly related test files contain 132 named methods at the recorded baseline. Fifteen files
and 110 methods primarily belong in package tests or corpus, with 26 expression methods requiring an
AST/execution split. Sequence already owns 12 formatting methods. App retains adapter, persistence,
trust, transaction, lifecycle and delivery evidence. These are inventories for later implementation,
not permission to delete tests before adoption.

The exact source and test maps are retained in the [Access Control](access-control-closure.md) and
[Business Definition](business-definition-closure.md) closure audits, with a
[machine-readable Definition inventory](business-definition-closure.json).
The new Access Control and Business Definition PRs are development drafts against explicitly unverified
exact dependency versions, with publication blocked. They do not authorize dependent publication or App
adoption before the selected upstream release evidence passes. The existing Conversion 0.1.2 and SDK 0.2.4
transitional approvals do not substitute for verified
legacy release records where a new dependent task requires those inputs.
