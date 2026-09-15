# 193. CORE-V1-CLOSURE-AUDIT-008

Data: 2026-09-14

**Status:** `AUDIT_COMPLETE_SOCIAL_SCHEMA_CORRECTIVE_NEXT`

## Audited accepted tip

`4a24f9fcb66f76cd92e7de7329ae8ab6cacc8629`

Tree:

`12245313d8db23b09afd4ad81e2c04b7aaa01ec2`

Prerequisite:

`CORE-V1-ORGANIZATION-SETTINGS-001 = PASS`

## Exact-head closure evidence

- Implementation CI #547 / run `34872638376`: **5/5 PASS**
- API Contract Gate #437 / run `34872638395`: **PASS**
- PostgreSQL: **316 tests / 5619 assertions — PASS**
- deterministic restore: **121 -> 121 PASS**
- restore fingerprint: `09c57afe2501c79a3a684c0a695b4619114f94ddc24c9bca33728d99c1abb155`
- `RESTORE_DRILL_HARNESS=PASS`

Live Stage4 migration authority remains:

- plan identity: `d2fd6bc999dc2a5ee024e9b91bf00f5a5dad29575554a0ba42eba67107c23f10`
- execution identity: `82da84d3efb312d78b432ad6081491c04b03569a0f250722d6befc11ca233712`
- authority blob: `ad5f2aa2e14ef248b95dd3dda0d1cbcb2e69d441`
- nodes: **170/170**
- implemented steps: **261**

## Canonical HTTP inventory after Organization/Settings

- canonical required HTTP operations: **172**
- physical bindings present: **150**
- missing physical bindings: **22**
- frozen PKK/PWPW bindings: **14**
- provider-specific payment webhook: **1**
- repo-actionable missing HTTP bindings: **7**

No repo P0 is open.

## Remaining repo-actionable HTTP gaps

### Schema-corrective blocked — 2

- `auth.social_redirect`
- `auth.social_callback`

The social authority is already PASS, but physical enforcement of:

`auth_social_accounts(provider, provider_subject)`

is still missing. The isolated Stage5 corrective authority is already closed under:

`CORE-V1-STAGE5-SOCIAL-SUBJECT-UNIQUE-AUTHORITY-001`

Therefore no new product/API authority is required before the database corrective.

### Authority-blocked — 5

- `auth.password_forgot`
- `auth.password_reset`
- `students.progress`
- `license_orders.create`
- `exam_orders.create`

### Implementation-ready — 0

No remaining HTTP operation is safe to implement before the social schema corrective or a new authority gate.

## Non-HTTP repo P1

One open non-HTTP P1 remains:

- physical `social_provider_subject_unique` enforcement

The frozen Stage4 migration history must not be edited.

## PKK/PWPW

All 14 explicit PKK/PWPW missing bindings remain:

`FROZEN_UNTIL_EXPLICIT_UNFREEZE`

The provider-neutral OSK service is now physically usable without PKK settings endpoints.

## Safe continuation

Next gate:

`CORE-V1-STAGE5-SOCIAL-SUBJECT-UNIQUE-001`

Exact scope:

- isolated root `database/migrations/stage5/social-identity`,
- node `S5SOC-IDX-AUTH-SOCIAL-PROVIDER-SUBJECT-UNIQUE`,
- phases `preflight -> write_fence -> validate`,
- duplicate preflight fails closed,
- no automatic remediation,
- non-partial unique B-tree on `(provider, provider_subject)`,
- catalog validation plus executable duplicate-rejection/control proof,
- Stage4 plan/execution identity unchanged,
- existing formal-documents extension unchanged,
- no Social OAuth HTTP runtime in this gate,
- no PKK/PWPW runtime.
