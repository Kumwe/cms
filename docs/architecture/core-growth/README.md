# Core Growth Records

A Core Growth Record is App saying, on record, why a piece of reusable-looking behaviour stays in App
instead of moving to the Kumwe package that would own it. `composer kumwe:core-growth-check` reads these
records; without an approved one, growth in a portable layer fails the build. The gate is explained in the
[governance guide](../governance/README.md) section 2; this page is how to write the record.

## When a record is required

- A new class-like — class, interface, trait, enum — or a widened public surface (a new or changed public
  method, property or constant) in a **portable layer**: `shared`, `domain` or `application`.
- Any FQCN, in any layer, that the responsibility-overlap rule flags: a package public symbol with the same
  short name, the same kind and at least half of the new symbol's public method names. The record must
  list that package symbol under `overlap_reviewed`.

## When it is not

- Host-layer growth — `infrastructure`, `presentation`, `delivery`, `kernel` — that is an adapter, a
  handler, a factory or composition. `composer kumwe:core-growth-record` records the `implements` and
  `extends` facts as host evidence; no record file is needed.
- A private change that composes existing public package APIs without adding an FQCN or changing a public
  signature. The surface digest does not move, so there is nothing to record.
- A public signature that now names the package symbol a migration ledger maps a retired App name to. The
  surface differs from the baseline only by that rename, so `composer kumwe:core-growth-record` re-records it
  as `renamed` and the entry keeps its growth evidence.
- Removing a symbol. Re-record the baseline.

If you are unsure whether the behaviour is portable, run the Capability Reuse Review (`AGENTS.md`
section 5) and let the decision order answer: reuse, extend the owning package, propose a focused package,
App-specific, or stop for review. A record documents the fourth outcome only.

## The file

`docs/architecture/core-growth/KUMWE-CGR-YYYY-NNN.md` — the next number in this directory, this year. YAML
front matter (schema
[`core-growth-record.v1.schema.json`](../governance/schemas/core-growth-record.v1.schema.json); example
[`core-growth-record.v1.example.md`](../governance/examples/core-growth-record.v1.example.md)), then seven
H2 sections in order.

### Front matter

```yaml
schema: kumwe-core-growth-record/v1
id: KUMWE-CGR-2026-001
title: "…"
symbols:
  - Kumwe\App\…
layer: application
capability_index_sha256: "<hex inspected>"
packages_reviewed:
  - package: kumwe/transaction
    version: 0.1.0
    symbols_inspected:
      - Kumwe\Transaction\TransactionManager
    source_inspected:
      - vendor/kumwe/transaction/src
    tests_inspected:
      - vendor/kumwe/transaction/tests
search_terms:
  - "transaction boundary"
  - "afterCommit"
required_capability: "…"
consumers:
  - "src/…"
overlap_reviewed: []
decision: approved      # approved | pending | rejected
decided_by: "…"
reviewer: "…"
decided_on: "2026-09-02"
pull_request: null
```

Field notes:

- `symbols` — the FQCNs the record approves. Every one must exist in `src/`; no two records may name the
  same FQCN.
- `layer` — the layer of those symbols as `docs/architecture/layers.json` classifies them.
- `capability_index_sha256` — the `Index digest` line of `docs/architecture/capability-index.md` at the
  time of the review. A reviewer regenerates the index and compares.
- `packages_reviewed` — every package whose responsibility is adjacent, with the exact locked version and
  the symbols, source and tests actually opened. "I remembered the API" is not a review.
- `search_terms` — the business nouns, actions, responsibilities, invariants and error names searched, not
  only the proposed class name.
- `consumers` — the App paths that will use the symbols.
- `overlap_reviewed` — the package symbols the overlap rule flagged (or that you judged similar) and
  dismissed, each with its reason in the body.
- `decision` — `approved`, `pending` or `rejected`. `approved` requires a non-empty `reviewer`. A `pending`
  or `rejected` record cited by the baseline fails the gate.
- `pull_request` — `null` until the PR exists; then its URL.

Write the record in the strict YAML subset (governance guide section 8): two-space indentation, block
mappings and sequences, plain or double-quoted scalars, no multi-line scalars, no anchors. The template
above is reproduced verbatim from the specification and abbreviates lists as flow sequences
(`[Kumwe\App\…]`); the strict reader accepts only empty flow collections, so write every non-empty list
in a real record as a block sequence. The example file is the form the validator accepts.

### Body sections

Seven H2 headings, in this order, all required:

1. `## Capability required` — what the App needs, stated as behaviour and invariants, not as a class.
2. `## Why existing package APIs are insufficient` — per package reviewed, what its public API does and
   does not do for this need, citing the symbols inspected.
3. `## Why extending the owning package is inappropriate` — why the responsibility does not belong to the
   package that is closest to it. "It would need a release" is not a reason.
4. `## Why a new focused package is inappropriate` — why no coherent portable bounded context exists for
   this behaviour.
5. `## App-specific responsibility` — which of host composition, authority, adapter, persistence,
   orchestration, security enforcement, delivery, deployment or recovery this is, and what would break if
   it lived outside App.
6. `## Tests proving the boundary` — the tests that show App owns exactly this and duplicates nothing:
   name the test classes and what each pins.
7. `## Decision` — the decision, who took it, who reviewed it, and the conditions under which it should be
   revisited (for example, when a named package next releases).

### Decision values

| Value | Meaning | Gate effect |
|---|---|---|
| `approved` | Reviewer agreed App owns the symbols | Baseline may cite the record; portable growth passes |
| `pending` | Written, awaiting review | Baseline may not cite it; the growth still fails |
| `rejected` | Reviewer refused App ownership | Baseline may not cite it; move the behaviour upstream |

### How a record is approved

The [standing maintainer mandate](../../../AGENTS.md#standing-maintainer-mandate--2026-09-12) delegates
routine ownership decisions within an assigned objective to agents. Complete the Capability Reuse Review
and obtain a reasoned review of the package boundary; an independent agent may provide that review. When
the evidence supports App ownership, record `decision: approved`, name the actual decision maker and
reviewer, set the decision date, and re-record the baseline. State that the decision uses the standing
mandate. Do not invent a human review event or use a human's identity for an agent's review. Keep a record
pending when the technical ownership question remains unresolved; the mandate does not waive the gate.

A human collaborator can also approve through a pull-request review. The `Core growth approval` workflow
(`.github/workflows/core-growth-approval.yml`) runs on every submitted review: when a human collaborator
with write access approves a same-repository pull request, every `decision: pending` record whose
`pull_request` names that pull request is set to `approved` with the reviewer's login and the review date,
the baseline is re-recorded with `composer kumwe:core-growth-record`, and the result is committed to the
pull-request branch under the reviewer's own identity. A review by a bot, by a read-only account, or on a
pull request without a pending record changes nothing. A reviewer may still do the same by hand: set the
three fields, re-record, and commit them together.

### After writing it

```
[ ] record the completed ownership review under the standing mandate, or leave pending if unresolved
[ ] composer kumwe:core-growth-record       # writes growth.record into the baseline after approval
[ ] composer kumwe:core-growth-check        # confirms the completed inventory and approved ownership
[ ] commit the record, the baseline and the PR "Capability reuse review" section together
```

## A worked example, in prose

Suppose the administrator needs a step-up rule: a user may post a closed-period journal only after a
second factor within the last five minutes, and only when the site's configuration allows step-up at all.
The agent drafts `Kumwe\App\Application\Authorization\StepUpPostingAuthority`, an application-layer class
with `decide()` and `explain()` methods. The gate flags it as portable growth.

The review inspects `composer.lock`, finds no `kumwe/access-control` release installed yet and no package
exporting an authorization decision type, and records the index digest. It opens the three installed
packages' charters: Conversion owns money and quantity conversion, the Extension SDK owns extension
contracts, Producer owns Studio documents. None claims authorization. The search terms — "step-up",
"posting authority", "closed period", "second factor", "authorization decision" — hit App's own
`AuthorizationGateway`, `Identity` and `BusinessSecurity` modules and nothing under `vendor/kumwe`.

Reuse is impossible: no public package API decides anything. Extending an owning package is inappropriate
because no installed package owns authorization, and the future `access-control` package will own
canonical contexts and decision vocabulary, not App's rule about App's own period lock and TOTP freshness.
A new focused package is inappropriate because the rule reads two App-owned facts — the period lock and the
session's second-factor timestamp — and has no consumer outside App. The responsibility is authority and
security enforcement, which the policy names as App-owned. The boundary tests pin that the class consumes
`AuthorizationGateway` and the session port through constructors, that it refuses without a fresh second
factor on every surface, and that no class of the same name and shape exists in any installed package.

The record lists the one FQCN, layer `application`, the three packages reviewed with versions and the
files opened, the search terms, `overlap_reviewed: []`, and `decision: approved` with the reviewer named.
`composer kumwe:core-growth-record` writes `growth.record: KUMWE-CGR-2026-001` for the FQCN, and the
"Decision" section says the record is revisited when `kumwe/access-control` first releases, so that its
decision vocabulary replaces the App-local one if it can.
