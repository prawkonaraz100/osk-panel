# 185. CORE-V1-BULK-CREDENTIAL-RESET-001 — closure

Data: 2026-09-14

**Status:** `PASS`

## Exact scope

This gate materializes the single implementation-ready HTTP binding identified by `CORE-V1-CLOSURE-AUDIT-004`:

- `POST /api/v1/learning-accesses/bulk-access-document`
- requirement id: `license_credentials.bulk_pdf`

The implementation is bound exactly to the accepted authority in:

- `docs/183-core-v1-bulk-credential-reset-authority.md`
- `specs/design/bulk-credential-reset.yml`
- `specs/database/licenses-learning-access.yml#DB-LIC-004`

No database schema or migration changed.

PKK/PWPW runtime remains frozen.

## Runtime behavior

The endpoint now supports both canonical DB-LIC-004 modes.

### Nonsecret combined PDF

When `regenerate_credentials_when_required` is false or omitted:

- every target is a learning-account target,
- no password is changed,
- historical plaintext is never recovered,
- one combined secret-free PDF is rendered,
- a completed idempotent retry can render the same nonsecret batch again without creating another batch or another handoff.

### Reset + immediate secret combined PDF

When `regenerate_credentials_when_required=true`:

- every target must carry `expected_credential_version`,
- `licenses.access_documents.download` is required,
- `student_access.reset_password` is additionally required for every target,
- all tenant/scope/eligibility checks complete before password mutation,
- expected credential versions are checked under the canonical locks,
- selected accounts resolving to the same global User must agree on the expected credential version,
- one fresh password/hash mutation occurs per unique global User,
- one credential-version increment occurs per unique global User,
- the whole secret-bearing combined PDF is rendered in memory before any durable password write,
- password, batch, handoff, audit/domain/outbox and idempotency metadata effects commit all-or-none.

The fresh plaintext password exists only in the immediate command response memory/PDF bytes.

It is not persisted in relational storage, FileAsset/object storage, audit/domain/outbox payloads or idempotency replay metadata.

A completed reset request cannot replay the secret-bearing PDF.

## Fail-closed proof

Executable tests cover at least:

- nonsecret replay without duplicate batch/handoffs or password mutation,
- reset mutation of each unique User exactly once,
- secret response non-replayability,
- stale expected credential version rejection,
- missing per-target reset permission rejection,
- cross-tenant/scope rejection,
- duplicate target rejection,
- disagreement between expected versions for multiple accounts resolving to one User,
- full-PDF render failure before any password write or batch metadata commit,
- redacted durable metadata.

## Implementation ancestry

Runtime materialization:

`49f297d9c16ddc242ebe30a0c29428b88cba2f04`

Pint corrective:

`227f94aebdfbcf15804e6d82e61dca398d9b8798`

PHPStan row-shape corrective / exact implementation head:

`804e5fbfd265ca9f62bc6930072f2973844d2706`

The corrective commits do not change the accepted credential-reset authority.

## Exact-head executable evidence

Exact implementation head:

`804e5fbfd265ca9f62bc6930072f2973844d2706`

Exact tree:

`7ebd3aecd096d9c3ed0c1e5ef38cd014d89b8e14`

Evidence on that exact head:

- Implementation CI #525 / run `34846941879`: **5/5 PASS**
- API Contract Gate #412 / run `34846941699`: **PASS**
- PostgreSQL: **308 tests / 5518 assertions — PASS**
- deterministic restore: **121 -> 121 PASS**
- restore schema fingerprint: `09c57afe2501c79a3a684c0a695b4619114f94ddc24c9bca33728d99c1abb155`
- `RESTORE_DRILL_HARNESS=PASS`
- backend Pint: **PASS**
- backend PHPStan: **PASS**
- frontend quality: **PASS**
- contracts/traceability: **PASS**
- secret scan: **PASS**

## Preservation

This gate does not:

- add or alter database schema,
- add a migration,
- create a historical password-recovery path,
- persist a secret-bearing combined PDF,
- allow download permission alone to reset passwords,
- weaken optimistic concurrency,
- create provider/payment authority,
- invent Student Progress authority,
- alter current server-side price/VAT authority,
- implement the blocked organization/settings operations,
- implement password-forgot/password-reset or social OAuth authority,
- activate or partially activate PKK/PWPW runtime.

## Closure effect

`CORE-V1-CLOSURE-AUDIT-004` reported:

- repo-actionable missing physical HTTP bindings: **11**
- implementation-ready missing bindings: **1**
- blocked missing bindings: **10**

This gate materializes exactly the one implementation-ready binding:

- `license_credentials.bulk_pdf`

Derived count before the next full repository audit:

- repo-actionable missing physical HTTP bindings: **10**
- implementation-ready missing bindings: **0**, subject to exact-tree re-audit
- previously blocked bindings: **10**, subject to reclassification only if the exact tree proves new authority

The derived count must be confirmed by a fresh repository closure audit.

## Next safe gate

`CORE-V1-CLOSURE-AUDIT-005`

The audit must recompute the exact closure tree and confirm:

- `license_credentials.bulk_pdf` is physically bound,
- repo-actionable missing HTTP count is 10 if no concurrent binding appeared,
- no additional non-HTTP P1 reopened,
- no blocked HTTP operation became implementation-ready without explicit new authority,
- PKK/PWPW remains `FROZEN_UNTIL_EXPLICIT_UNFREEZE`.
