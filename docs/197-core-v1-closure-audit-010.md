# 197. CORE-V1-CLOSURE-AUDIT-010

Data: 2026-09-14

**Status:** `AUDIT_COMPLETE_NO_IMPLEMENTATION_READY_REQUIRES_NEXT_AUTHORITY`

## Audited accepted tip

`a619d8f520cd8bbe20985e9f6b0f2bbff1aab84f`

Tree:

`0e428db19eef3f86338e870b75f2080d65192b73`

Prerequisite:

`CORE-V1-AUTH-SOCIAL-001 = PASS`

## Exact-head closure evidence

- Implementation CI #558 / run `34885813014`: **5/5 PASS**
- API Contract Gate #450 / run `34885813185`: **PASS**
- PostgreSQL: **325 tests / 5719 assertions — PASS**
- deterministic restore: **121 -> 121 PASS**
- restore fingerprint: `09c57afe2501c79a3a684c0a695b4619114f94ddc24c9bca33728d99c1abb155`
- `RESTORE_DRILL_HARNESS=PASS`

## Canonical HTTP inventory

- canonical required HTTP operations: **172**
- physical bindings present: **152**
- missing physical bindings: **20**
- frozen PKK/PWPW bindings: **14**
- provider-specific payment webhook: **1**
- repo-actionable missing HTTP bindings: **5**

No repo P0 is open.

No additional non-HTTP repo P1 is open.

No repo-actionable HTTP operation remains schema-corrective blocked.

## Remaining repo-actionable HTTP gaps

### Authority-blocked — 5

Identity password recovery:

- `auth.password_forgot`
- `auth.password_reset`

Student progress:

- `students.progress`

Commerce order creation:

- `license_orders.create`
- `exam_orders.create`

### Implementation-ready — 0

No remaining repo-actionable HTTP operation may be implemented without a new focused authority gate.

## Password recovery blocker

The current repository does not yet define a complete executable reset-token lifecycle.

Existing framework auth configuration does not provide repository-owned canonical values for reset-token persistence/lifetime/throttle semantics, and no Core V1 authority currently settles:

- token storage/hash model,
- issue/reissue invalidation rule,
- expiration,
- one-time claim,
- account-state checks,
- reset completion transaction,
- session-revocation effect,
- enumeration-safe response,
- rate limiting,
- interaction with `user_password_management.management_mode`.

This is the smallest remaining authority family and does not require PKK/PWPW.

## Student progress blocker

Strong UI/product intent exists, but no complete canonical persisted projection/query authority yet defines the exact source-of-truth and aggregation semantics.

## Commerce create blocker

Read-side commerce is present, but safe order creation still needs canonical server-side pricing/VAT snapshot authority plus allocator/idempotency resolution.

## PKK/PWPW

All 14 explicit PKK/PWPW missing bindings remain:

`FROZEN_UNTIL_EXPLICIT_UNFREEZE`

Core service operation remains independent of PKK.

## Safe continuation

Next gate selected:

`CORE-V1-AUTH-PASSWORD-RECOVERY-AUTHORITY-001`

Reason:

- smallest remaining authority family,
- self-contained IdentityTenant scope,
- two HTTP gaps unlocked by one authority,
- no schema conclusion is assumed before authority design,
- no PKK/PWPW dependency.

The authority gate itself must not bind HTTP routes or execute schema changes.
