# 195. CORE-V1-CLOSURE-AUDIT-009

Data: 2026-09-14

**Status:** `AUDIT_COMPLETE_SOCIAL_IMPLEMENTATION_READY`

## Audited accepted tip

`cf73fbaaf7cd804d2913fc3312e9b1a8a8fa8de3`

Tree:

`204bf9a152fd1dd485292b8fe229acf64edbbc78`

Prerequisite:

`CORE-V1-STAGE5-SOCIAL-SUBJECT-UNIQUE-001 = PASS`

## Exact-head closure evidence

- Implementation CI #553 / run `34877275151`: **5/5 PASS**
- API Contract Gate #444 / run `34877275075`: **PASS**
- PostgreSQL: **319 tests / 5637 assertions — PASS**
- deterministic restore: **121 -> 121 PASS**
- restore fingerprint: `09c57afe2501c79a3a684c0a695b4619114f94ddc24c9bca33728d99c1abb155`
- `RESTORE_DRILL_HARNESS=PASS`

The isolated social-identity extension remains:

- plan identity `85319b7c91cf9046e0ac8c00772aad141462e3897363b32d0ba222a50f73fe4d`
- execution identity `b4a73588390d837fbb460d374114585064445462ddcba667d3919c7052f76cd0`
- one node / one implemented node / three implemented steps

## Canonical HTTP inventory

No HTTP binding was added by the database corrective:

- canonical required HTTP operations: **172**
- physical bindings present: **150**
- missing physical bindings: **22**
- frozen PKK/PWPW bindings: **14**
- provider-specific payment webhook: **1**
- repo-actionable missing HTTP bindings: **7**

## Reclassification

The physical uniqueness blocker for Social OAuth is now closed.

The following operations are therefore **implementation-ready**:

- `auth.social_redirect`
- `auth.social_callback`

Their existing authority remains `CORE-V1-AUTH-SOCIAL-AUTHORITY-001 = PASS`.

## Remaining repo-actionable HTTP gaps

### Implementation-ready — 2

- `auth.social_redirect`
- `auth.social_callback`

### Authority-blocked — 5

- `auth.password_forgot`
- `auth.password_reset`
- `students.progress`
- `license_orders.create`
- `exam_orders.create`

### Schema-corrective blocked — 0

No repo-actionable HTTP operation remains schema-corrective blocked.

## Non-HTTP repo P1

Additional open non-HTTP P1 count: **0**.

The physical `social_provider_subject_unique` enforcement gap is closed.

No repo P0 is open.

## Social implementation boundary

Next implementation may bind exactly:

- `GET /api/v1/auth/social/{provider}/redirect`
- `GET /api/v1/auth/social/{provider}/callback`

It must preserve the already-approved authority:

- trusted server-config provider allowlist,
- OAuth2 authorization-code + PKCE S256,
- one-time server-side state bound to framework session,
- state TTL 600 seconds,
- atomic state claim,
- provider error consumes state,
- external provider I/O outside DB transactions,
- current provider subject is authoritative,
- historical revoked provider subject cannot auto-relink,
- authenticated existing-user linking allowed only to the initiating active user and forbidden for organization-managed identity,
- unauthenticated first-link only to an existing active user with matching current verified local e-mail and verified provider e-mail,
- no social registration,
- no user or organization auto-creation,
- no provider token persistence,
- sign-in uses existing `AuthSessionService.startSession`,
- local return URL uses existing `AuthSessionService.safeReturnUrl`.

## PKK/PWPW

All explicit PKK/PWPW operations remain:

`FROZEN_UNTIL_EXPLICIT_UNFREEZE`

Social OAuth must have zero PKK/PWPW dependency.

## Safe continuation

Next gate:

`CORE-V1-AUTH-SOCIAL-001`

A fresh closure audit is required after its physical runtime bindings PASS.
