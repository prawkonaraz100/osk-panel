# 183. CORE-V1-BULK-CREDENTIAL-RESET-AUTHORITY-001 — bulk credential reset transport authority

Data: 2026-09-14

**Status:** `PASS`

## Purpose

This gate closes the Stage-5 HTTP transport gap that blocked:

- `POST /api/v1/learning-accesses/bulk-access-document`
- requirement id: `license_credentials.bulk_pdf`

It does **not** invent a new credential lifecycle.

The canonical lifecycle, atomicity, secret-handling and multi-target concurrency authority already exists in:

- `specs/database/licenses-learning-access.yml#DB-LIC-004`

The missing piece was explicitly deferred there as:

`exact_HTTP_mapping: Stage5_acceptance_contract_sync`

The previous HTTP contract exposed only learning-account UUIDs plus `regenerate_credentials_when_required`, so reset mode had no way to carry the mandatory optimistic-concurrency epoch for every reset target.

## Exact authority candidate

Candidate head:

`88b7d377a2e3fd5eb3e4d484cc1145f6153d3abf`

Parent:

`7fd1a66ba012e831dd4ebda73046dfd00404264b`

The parent already contains the independently validated `CORE-V1-AUTH-REGISTER-001` closure. This authority gate was rebased by reconstruction onto that exact accepted parent after the branch moved concurrently; no registration work was overwritten.

Candidate changes exactly:

- `specs/api/paths/licenses.yaml`
- `specs/api/openapi-components-v1.yaml`
- `specs/design/bulk-credential-reset.yml`

No route, controller, service, migration or database schema changed in this gate.

## Existing DB-LIC-004 authority preserved

The transport contract now exposes the already-proven DB-LIC-004 distinction between two modes.

### Nonsecret combined PDF

When credential regeneration is false or omitted:

- every target carries `learning_account_id`,
- `expected_credential_version` is not required,
- credential mutation is forbidden,
- old plaintext recovery remains forbidden,
- the operation requires `licenses.access_documents.download`,
- the output is one combined secret-free PDF.

### Reset + immediate secret combined PDF

When `regenerate_credentials_when_required=true`:

- every target carries `learning_account_id`,
- every target also carries `expected_credential_version`,
- `licenses.access_documents.download` remains required,
- `student_access.reset_password` is additionally required for every target,
- concurrency authority is `user_password_management.credential_version`,
- any missing or stale expected version fails before credential mutation.

Download permission alone can never authorize password reset.

## Duplicate global User semantics

Several selected learning accounts may resolve to the same global User.

DB-LIC-004 already requires grouping by `user_id`.

Therefore:

- expected credential versions supplied for selected accounts resolving to the same User must agree,
- one fresh password is generated per unique global User,
- one password-hash write occurs per unique User,
- one credential-version increment occurs per unique User,
- that same fresh password may appear on each selected account page for that User only inside the same immediate in-memory batch response.

A duplicate learning-account target itself is invalid and must not cause a duplicated handoff item.

## All-or-none reset boundary

Reset mode preserves the DB-LIC-004 deterministic lock order:

1. distinct Students sorted by UUID — `FOR UPDATE`,
2. selected StudentLearningAccounts sorted by UUID — `FOR UPDATE`,
3. distinct Users sorted by UUID — `FOR UPDATE`,
4. UserPasswordManagement rows sorted by User UUID — `FOR UPDATE`.

Before any durable password write the implementation must:

- validate every target shape,
- verify tenant and scope for every selected account,
- acquire all required locks,
- recheck every account's operational eligibility,
- verify matching organization-managed password authority and exclusive-principal guard for every unique User,
- compare every expected credential version after locks,
- generate all plaintext/hash material only in process memory,
- render the entire combined secret-bearing PDF in memory.

Only then may the transaction:

- update all unique Users' password hashes,
- increment each unique credential version exactly once,
- create one StudentAccessExportBatch,
- create one handoff item per selected learning account,
- write redacted audit/domain/outbox/idempotency metadata,
- commit all effects together.

Any validation, authorization, version or render failure before commit leaves:

- password writes: **0**
- credential-version changes: **0**
- handoff items: **0**

## Secret and retry policy

The candidate preserves the existing DB-LIC-004 prohibition on durable secret recovery.

Forbidden:

- plaintext password in relational storage,
- plaintext password in FileAsset or object storage,
- plaintext/password hash/secret PDF bytes in audit, domain events or outbox,
- plaintext/secret PDF bytes in idempotency safe-response snapshots,
- server-side recovery of a previously streamed secret-bearing PDF.

A completed reset batch replay with the same idempotency key:

- does not reset again,
- does not replay plaintext,
- may return only a sanitized nonsecret result or a non-replayable-secret error defined by implementation policy.

A delivery failure after commit never reopens server-side secret recovery. A new secret document requires an explicit new reset batch using current credential versions.

## Projection sync

The runtime already returns:

- `student_learning_accounts.version`
- `user_password_management.credential_version`

in the StudentLearningAccount projection.

The OpenAPI component previously omitted both fields.

This gate publishes them as read-only fields so the client can carry current `expected_credential_version` without guessing.

This is contract synchronization, not a new data source.

## Executable evidence

Exact candidate head:

`88b7d377a2e3fd5eb3e4d484cc1145f6153d3abf`

Evidence:

- Implementation CI #520 / run `34840278908`: **5/5 PASS**
- API Contract Gate push #404 / run `34840274298`: **PASS**
- API Contract Gate PR #405 / run `34840278845`: **PASS**
- PostgreSQL: **300 tests / 5447 assertions — PASS**
- deterministic restore: **121 -> 121 PASS**
- restore schema fingerprint: `09c57afe2501c79a3a684c0a695b4619114f94ddc24c9bca33728d99c1abb155`
- restore harness: **PASS**
- backend Pint + PHPStan: **PASS**
- frontend quality: **PASS**
- contracts/traceability: **PASS**
- secret scan: **PASS**

## Preservation

This authority gate does not:

- add a route or runtime implementation,
- change schema or migrations,
- change DB-LIC-004 semantics,
- permit download permission to reset credentials,
- persist or recover historical plaintext credentials,
- activate PKK/PWPW,
- add pricing/VAT authority,
- add auth password-recovery token authority,
- add OAuth provider/config/state authority,
- add online-learning Student Progress authority.

## Closure effect

The independently accepted Registration gate already derived the repo-actionable missing HTTP count from 12 to 11.

This authority gate closes **zero** physical HTTP bindings.

It changes the classification of exactly one still-missing binding:

`license_credentials.bulk_pdf`

from:

`BLOCKED_RESET_BRANCH_MISSING_EXPECTED_CREDENTIAL_VERSIONS`

to:

`IMPLEMENTATION_READY_DB_LIC_004_TRANSPORT_AUTHORITY_CLOSED`

The exact repository count must be recomputed by a new closure audit rather than assumed from derived arithmetic.

## Next gate

Run `CORE-V1-CLOSURE-AUDIT-004` on the exact accepted authority closure tree.

If the audit confirms the expected classification, the next implementation slice is:

`CORE-V1-BULK-CREDENTIAL-RESET-001`

No PKK/PWPW runtime is permitted.
