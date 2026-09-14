# 200. CORE-V1-CLOSURE-AUDIT-011

Data: 2026-09-14

**Status:** `AUDIT_COMPLETE_PASSWORD_RECOVERY_IMPLEMENTATION_READY`

## Audited accepted tip

`3b679788dbb382ed543e5e41118a48e760c1b6c6`

Tree:

`4bf711937dcf49d47a11aecbc37fda526512c850`

Prerequisite:

`CORE-V1-AUTH-PASSWORD-RECOVERY-AUTHORITY-001 = PASS`

## Exact-head closure evidence

- Implementation CI #561 / run `34888270586`: **5/5 PASS**
- API Contract Gate #453 / run `34888270600`: **PASS**
- PostgreSQL: **326 tests / 5745 assertions — PASS**
- deterministic restore: **121 -> 121 PASS**
- restore fingerprint: `09c57afe2501c79a3a684c0a695b4619114f94ddc24c9bca33728d99c1abb155`
- `RESTORE_DRILL_HARNESS=PASS`

## Canonical HTTP inventory

No runtime binding changed in the authority gate:

- canonical required HTTP operations: **172**
- physical bindings present: **152**
- missing physical bindings: **20**
- frozen PKK/PWPW bindings: **14**
- provider-specific payment webhook: **1**
- repo-actionable missing HTTP bindings: **5**

No repo P0 is open.

No additional non-HTTP repo P1 is open.

## Reclassification

The password-recovery lifecycle is now fully defined.

The following operations are **implementation-ready**:

- `auth.password_forgot`
- `auth.password_reset`

## Remaining repo-actionable HTTP gaps

### Implementation-ready — 2

- `auth.password_forgot`
- `auth.password_reset`

### Authority-blocked — 3

Student progress:

- `students.progress`

Commerce order creation:

- `license_orders.create`
- `exam_orders.create`

### Schema-corrective blocked — 0

No repo-actionable HTTP operation remains schema-corrective blocked.

## Password recovery implementation boundary

The runtime gate may implement exactly:

- `POST /api/v1/auth/password/forgot`
- `POST /api/v1/auth/password/reset`

It must preserve the approved authority:

- public enumeration-safe forgot response,
- self-service identities only,
- verified primary e-mail delivery,
- 32-byte cache-backed one-time token,
- 1800-second TTL,
- one live token per User,
- reissue invalidates previous token,
- credential-version snapshot,
- token consumed before DB mutation,
- all active application sessions revoked after reset,
- no schema or migration change,
- no Laravel password-broker table,
- no organization-managed credential mutation,
- no PKK/PWPW dependency.

## PKK/PWPW

All 14 explicit PKK/PWPW bindings remain:

`FROZEN_UNTIL_EXPLICIT_UNFREEZE`

Core service operation remains independent of PKK.

## Safe continuation

Next gate:

`CORE-V1-AUTH-PASSWORD-RECOVERY-001`

A fresh closure audit is required after its physical runtime bindings PASS.
