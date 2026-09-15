# 206. CORE-V1-CLOSURE-AUDIT-014

Data: 2026-09-15

**Status:** `AUDIT_COMPLETE_STUDENT_PROGRESS_AUTHORITY_REQUIRED`

## Audited accepted tip

`ce5521ac4afa19ecee21ef2269b51f610fe16137`

Tree:

`896fec77ee55e46f63ba48eac73001c888f1da9c`

Prerequisite:

`CORE-V1-COMMERCE-ORDER-CREATE-001 = PASS`

## Exact-head closure evidence

- Implementation CI #580 / run `34910768003`: **5/5 PASS**
- API Contract Gate #475 / run `34910767890`: **PASS**
- PostgreSQL: **338 tests / 5926 assertions — PASS**
- deterministic restore: **122 -> 122 PASS**
- restore fingerprint: `f3cab286cc8f0aabef219971d90afe424a8dab694c47ade2f517a3d95970716d`
- `RESTORE_DRILL_HARNESS=PASS`
- backend Pint/PHPStan, frontend, contracts/traceability and secret scan: **PASS**

## Full canonical HTTP inventory

This audit recomputes the HTTP inventory from the complete machine-readable required-operation authority rather than counting only inline YAML rows.

The structural API gate reports:

- canonical OpenAPI HTTP operations: **173**
- HTTP requirement rows: **187**

Independent normalized comparison of all unique required `(method,path)` pairs against Laravel physical routes reports:

- canonical required HTTP operations: **173**
- physical bindings present: **157**
- missing physical bindings: **16**

The 16 missing bindings are exactly:

- **14 PKK/PWPW HTTP operations** — frozen,
- `payment_webhook.receive` — external/provider-specific,
- `students.progress` — repository-actionable.

No other canonical HTTP binding is missing.

## Historical inventory correction

Audits before this closure reported **172** canonical HTTP operations because their quick helper counted inline requirement rows and omitted one unique multi-line HTTP requirement:

`license_management.expand_history`

`GET /students/{studentId}/learning-accounts/{accountId}/license-assignments`

That operation already has a physical runtime route and was never a missing binding.

Therefore historical counts of **154/172** and **156/172** are corrected methodologically to **155/173** and **157/173** respectively. Missing-operation counts are unchanged.

The API structural gate was already authoritative and consistently reported **173**.

## Repository closure state

- repo P0 open: **0**
- additional non-HTTP repo P1 open: **0**
- repo-actionable missing HTTP bindings: **1**
- schema-corrective blocked HTTP bindings: **0**
- implementation-ready missing HTTP bindings: **0**
- authority-blocked missing HTTP bindings: **1**

Repository Core V1 is therefore **not yet complete**.

## Sole remaining repo-actionable HTTP gap

### `students.progress`

Canonical route:

`GET /students/{studentId}/progress`

Required context:

- exact tenant-scoped Student,
- exact `StudentLearningAccount`,
- selected driving category,
- language/content context implied by the selected learning account.

Confirmed response capability includes independent metrics for:

- tests,
- questions,
- handbook,
- lectures,
- question taxonomy topics.

The current OpenAPI `StudentProgress` schema is intentionally generic and does not yet define sufficient semantic authority to implement these metrics safely.

## Runtime/UI evidence

The accepted Student workspace still renders **Postęp** as a disabled tab.

There is no physical `GET /students/{studentId}/progress` route.

Existing architecture names `LearningProgressProjection` as part of the Licensing/Learning boundary, but the accepted physical database schema does not materialize a canonical progress projection.

Therefore the missing route is not a small controller omission.

## Why direct implementation remains forbidden

The confirmed screen contract requires:

- passed/failed test counts and percentages,
- answered/available question counts,
- correct/incorrect percentages,
- handbook completed/total/progress/control-question metrics,
- lecture completed/total/progress/control-question metrics,
- basic/specialized taxonomy breakdown,
- dynamic available totals,
- contextual aggregation by Student + LearningAccount + category,
- translatable taxonomy labels.

Still unresolved:

1. exact source-of-truth for each metric family;
2. exact denominator/formula semantics for all percentages;
3. exact treatment of repeated question attempts;
4. exact test-attempt population included in passed/failed metrics;
5. exact handbook completion authority;
6. exact lecture completion authority;
7. control-question calculation for handbook/lectures;
8. cross-system identity/binding between OSK LearningAccount and the learning runtime;
9. freshness/rebuild semantics of the projection;
10. behavior when one Student has multiple LearningAccounts;
11. category and language consistency rules;
12. unavailable/not-yet-materialized content domains.

Returning hard-coded zeroes or inferred totals would falsely convert an unimplemented source into valid progress evidence and is forbidden.

## Architecture boundary

Student Progress is a **read projection**, not a second learning source of truth.

The authority must preserve:

- global User identity remains separate from tenant Student and StudentLearningAccount;
- OSK does not invent its own question/test learning ledger;
- learning runtime remains source authority for learning events/results it owns;
- OSK may materialize a tenant-readable projection only through an explicit stable subject/account binding;
- projection data must be rebuildable or refreshable from authoritative source facts;
- unavailable metric families must be represented explicitly as unavailable/not-materialized, not silently as 0%;
- dynamic totals come from current authoritative content/category scope, never observed competitor constants such as 2185 or 774.

## UI boundary

The disabled **Postęp** tab must remain disabled until the authority and runtime projection are closed.

The implementation must not enable the tab with placeholder numbers.

## External/deferred boundaries

### PKK/PWPW

All 14 missing PKK/PWPW bindings remain:

`FROZEN_UNTIL_EXPLICIT_UNFREEZE`

Core service operation remains independent of PKK.

### Payment provider webhook

`payment_webhook.receive` remains an external-provider boundary until an authoritative provider adapter/signature contract exists.

It is not a repository-actionable Core V1 P1.

## Audit result

`CORE_V1_REPOSITORY_ACTIONABLE_P1_REMAINS_1_AUTHORITY_BLOCKED`

The sole remaining repository-actionable Core V1 HTTP gap is `students.progress`.

## Safe continuation

Next gate:

`CORE-V1-STUDENT-PROGRESS-AUTHORITY-001`

Authority gate only.

It must define:

- canonical LearningProgressProjection contract,
- stable LearningAccount-to-learning-runtime subject binding,
- exact metric source authority,
- formulas and null/unavailable semantics,
- dynamic category/taxonomy totals,
- projection freshness/rebuild rules,
- tenant and account isolation,
- multi-account behavior,
- exact future runtime/UI release boundary.

The authority gate must **not**:

- add the HTTP route,
- enable the UI tab,
- fabricate handbook/lecture data,
- hard-code observed competitor totals,
- activate PKK/PWPW runtime,
- implement provider payment webhook behavior.

After authority PASS, a fresh closure audit is required before `students.progress` may become implementation-ready.
