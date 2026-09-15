# 210. CORE-V1-STUDENT-PROGRESS-001

Data: 2026-09-15

**Status:** `PASS`

## Prerequisite

`CORE-V1-CLOSURE-AUDIT-015 = AUDIT_COMPLETE_STUDENT_PROGRESS_IMPLEMENTATION_READY`

## Scope

This gate closes the final repository-actionable Core V1 HTTP gap:

`GET /students/{studentId}/progress`

The runtime is read-only and uses the already-materialized canonical Student Progress projection.

## Runtime context

Every read is bound to the exact:

- organization,
- Student,
- StudentLearningAccount,
- active driving category.

Authorization uses:

`students.progress.view`

A learning account belonging to another Student or tenant is not visible.

## Truthful states

The runtime exposes:

- `ready`,
- `stale`,
- `unbound`,
- `source_unavailable`.

Missing or unavailable data is represented with explicit states and nullable numeric fields.

It never substitutes missing source facts with zeroes.

A last-good projection becomes stale when:

- the LearningAccount version changes, or
- `learning_progress.max_age_seconds` is exceeded.

Repository default freshness age:

`300 seconds`

## UI

The existing disabled **Postęp** tab becomes active.

The panel allows selection of:

- exact learning account,
- exact driving category.

It renders:

- passed/failed test metrics,
- answered/available and correct/incorrect question metrics,
- handbook state/progress,
- lectures as the authoritative `outside_core_v1` state where applicable,
- basic and specialized topic counters from dynamic projection data.

There are no constants for observed totals such as `2185` or `774`.

## Preservation

This gate does not:

- create or infer source bindings,
- guess identity from login/e-mail/name/PESEL,
- add browser or administrator writes to progress,
- implement the learning-source provider,
- activate PKK/PWPW,
- implement payment webhooks,
- implement the lectures module,
- mutate Stage4 authority.

## Expected closure effect

Before:

- canonical required operations: 173
- physical bindings: 157
- missing: 16
- repo-actionable missing: 1

After this candidate:

- canonical required operations: 173
- physical bindings: 158
- missing: 15
- repo-actionable missing: 0
- frozen PKK missing: 14
- external payment webhook missing: 1

After exact-head PASS, a final Core V1 closure audit is mandatory before accepted-branch promotion.


## Closure evidence

Validated exact head:

`68cf0cdf551a0e05f37660aab94165c475ac5c58`

Validated tree:

`2fa11cf97cbbc610b0585d35bcbfdca0b87d0135`

- Implementation CI run `34920570247`: **5/5 PASS**
- API Contract Gate PR run `34920570232`: **PASS**
- API Contract Gate push run `34920566447`: **PASS**
- PostgreSQL: **346 tests / 6011 assertions — PASS**
- deterministic restore: **122 -> 122 PASS**
- restore fingerprint: `f3cab286cc8f0aabef219971d90afe424a8dab694c47ade2f517a3d95970716d`
- backend Pint/PHPStan: **PASS**
- frontend lint/typecheck/build/audit: **PASS**
- contracts/traceability: **PASS**
- secret scan: **PASS**

Independent physical-route recomputation reports:

- canonical required HTTP operations: **173**
- physical bindings present: **158**
- missing physical bindings: **15**
- repository-actionable missing bindings: **0**
