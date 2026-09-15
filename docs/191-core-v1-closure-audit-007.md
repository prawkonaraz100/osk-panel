# 191. CORE-V1-CLOSURE-AUDIT-007

Data: 2026-09-14

**Status:** `AUDIT_COMPLETE_PROVIDER_NEUTRAL_ORGANIZATION_SETTINGS_IMPLEMENTATION_READY`

## Audited accepted tip

`379e3d02710891774c8e37ed1ff9cc7b7391c34a`

Tree:

`c66ca9ca9b5c1483b0fc7aba1a3668f8d1219297`

Prerequisite:

`CORE-V1-ORGANIZATION-SETTINGS-PROVIDER-NEUTRAL-AUTHORITY-001 = PASS`

## Exact-head closure evidence

- Implementation CI #541 / run `34867224998`: **5/5 PASS**
- API Contract Gate #430 / run `34867225007`: **PASS**
- PostgreSQL: **311 tests / 5575 assertions — PASS**
- deterministic restore: **121 -> 121 PASS**
- restore fingerprint: `09c57afe2501c79a3a684c0a695b4619114f94ddc24c9bca33728d99c1abb155`
- `RESTORE_DRILL_HARNESS=PASS`

## Canonical HTTP inventory

The canonical required-operation authority and physical route tree are unchanged since audit 006:

- canonical required HTTP operations: **172**
- physical bindings present: **147**
- missing physical bindings: **25**
- frozen PKK/PWPW bindings: **14**
- provider-specific payment webhook: **1**
- repo-actionable missing bindings: **10**

This audit changes classification only; it does not claim a new physical binding.

## Reclassification

The provider-neutral authority removes PKK as a prerequisite for:

- `organization.update`
- `organization.settings.get`
- `organization.settings.update`

These three operations are now **implementation-ready**.

The dedicated PKK/PWPW routes remain frozen and are not part of this implementation tranche.

## Remaining repo-actionable HTTP gaps

### Implementation-ready — 3

- `organization.update`
- `organization.settings.get`
- `organization.settings.update`

### Schema-corrective blocked — 2

- `auth.social_redirect`
- `auth.social_callback`

Reason: physical `auth_social_accounts(provider, provider_subject)` uniqueness corrective remains to be materialized under `CORE-V1-STAGE5-SOCIAL-SUBJECT-UNIQUE-001`.

### Authority-blocked — 5

- `auth.password_forgot`
- `auth.password_reset`
- `students.progress`
- `license_orders.create`
- `exam_orders.create`

## Non-HTTP repo P1

One additional P1 remains open:

- physical enforcement of `social_provider_subject_unique`

No repo P0 is open.

## PKK rule

PKK/PWPW remains:

`FROZEN_UNTIL_EXPLICIT_UNFREEZE`

The service must remain usable without importing, fetching or configuring PKK. No provider-neutral runtime may introduce a hidden PKK dependency.

## Safe continuation

Next gate:

`CORE-V1-ORGANIZATION-SETTINGS-001`

It may implement exactly the three provider-neutral bindings above.

Forbidden in that gate:

- PKK/PWPW provider calls,
- PKK configuration mutation,
- PKK route activation,
- email-change behavior without dedicated identity authority,
- unrelated schema/migration changes.
