# 210. CORE-V1-STUDENT-PROGRESS-001

Data: 2026-09-15

**Status:** `CANDIDATE`

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
