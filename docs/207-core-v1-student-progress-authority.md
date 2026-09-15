# 207. CORE-V1-STUDENT-PROGRESS-AUTHORITY-001

Data: 2026-09-15

**Status:** `PASS`

## Problem

Closure Audit 014 proves that `students.progress` is the sole remaining repository-actionable Core V1 HTTP gap.

The screen intent is confirmed, but the repository cannot safely implement the endpoint by inventing one aggregate percentage, hard-coding observed competitor totals, or treating missing learning data as zero.

This authority defines the exact read-projection semantics and the minimum isolated Stage5 schema needed before runtime/UI implementation.

## Exact context

Progress is always scoped by:

1. active organization,
2. exact Student,
3. exact `StudentLearningAccount` belonging to that Student,
4. selected driving category.

Permission remains:

`students.progress.view`

The selected LearningAccount language is a label/content preference. It is not an identity key and never changes tenant or subject binding.

## Stable learning-source binding

A local LearningAccount is not matched to an external/current learning runtime by e-mail, login, name or PESEL.

Canonical binding:

`learning_progress_source_bindings`

Each binding stores:

- exact organization, Student and LearningAccount,
- stable `source_system`,
- opaque `source_subject_ref`,
- opaque `source_access_ref`,
- status/version and timestamps.

`source_access_ref` represents the exact learning-access context, not only a global person.

It is globally unique within a source system while active/current. The same source subject may back several local contexts only when the source provides distinct access-context references.

Binding creation is trusted only from:

- a learning-account provisioning adapter returning authoritative source references, or
- a reviewed authoritative mapping import.

Identifier guessing is forbidden.

## Multi-account isolation

Progress never aggregates across local LearningAccounts.

A source implementation that only knows a global subject and cannot distinguish access contexts may be used only when that source subject maps to exactly one active local LearningAccount. Any ambiguity fails closed as unavailable.

This preserves the existing rule that one global User may participate in multiple organizations without letting one OSK read activity belonging to another access context.

## Source contract

Normalized source contract:

`learning-progress.v1`

A request includes exact subject, exact access context and category.

The response must echo those exact dimensions and carry:

- source snapshot/revision reference,
- source observation timestamp,
- test metrics,
- question metrics,
- content capability metrics,
- topic breakdown.

Service communication uses deployment-owned service credentials over TLS.

The browser never receives service credentials and cannot submit projection values.

No source/network call occurs while a PostgreSQL transaction is open.

## Local projection

Canonical current projection:

`student_learning_progress_projections`

Key:

`organization_id + student_learning_account_id + driving_category_id`

The projection is a cache/read model, not a second learning ledger.

It stores the last normalized source snapshot, account-version snapshot, source timestamps and SHA-256 payload hash.

Default freshness policy:

`learning_progress.max_age_seconds = 300`

When missing or stale, runtime may refresh synchronously **outside** a DB transaction.

Failure behavior:

- last good snapshot exists -> return it as `stale`;
- no snapshot exists -> `source_unavailable`;
- no binding -> `unbound`;
- source response dimension mismatch -> fail closed and do not overwrite last good data.

A refresh failure never zeroes prior metrics.

## Metric availability states

Every metric family has one of:

- `available`,
- `no_activity`,
- `unavailable`,
- `outside_core_v1`.

Rules:

- `no_activity` means a real authoritative capability exists and counts are genuinely zero;
- zero denominator yields a **null percentage**, not a fabricated 0%;
- `unavailable` has null numeric fields;
- `outside_core_v1` has null numeric fields;
- UI must never render unavailable data as if it were measured 0%.

## Test metrics

Population:

terminal, verifiable mock-exam attempts for the exact subject/access/category.

`conducted_count = passed_count + failed_count`

Percentages:

- `passed_percent = passed_count / conducted_count * 100`
- `failed_percent = failed_count / conducted_count * 100`

Rounded to two decimals.

Zero conducted tests -> both percentages `null`.

Pass/fail authority must be an immutable terminal source result.

Forbidden:

- using answer-correctness `score_percent` as the legal/mock-exam pass decision;
- recomputing a historical result from mutable current question weights;
- silently classifying an unverifiable legacy attempt.

This is important because the current learning application has question/session data, but a generic correctness percentage is not equivalent to the official points result.

## Question metrics

Current denominator is dynamic:

all current active, deliverable questions for the exact category.

`answered_count`:

distinct currently available questions with at least one recorded attempt.

`total_attempts`:

sum of attempts for those current questions.

Invariant:

`total_attempts = correct_attempts + incorrect_attempts`

Percentages:

- correct attempts / total attempts,
- incorrect attempts / total attempts.

Zero attempts -> percentages `null`.

Removed/non-deliverable questions remain historical learning facts but do not inflate the current available denominator.

## Topic breakdown

Topic rows use stable domain keys, never display labels as keys.

Groups are:

- `basic`,
- `specialized`.

For each topic:

- dynamic current available count,
- distinct answered current-question count,
- localized display label,
- actual label language code.

LearningAccount language is preferred. If the source has no translation, fallback is allowed only when the response identifies the actual fallback language.

Observed values such as **2185** are evidence snapshots, never constants.

## Handbook and content completion

A content unit counts as completed only when the learning source emits an explicit authoritative completion fact.

The recovered historical lesson cursor contract `STEP_VIEWED`/resume position is **not completion evidence** and must not be promoted into completed units.

For handbook:

- if no authoritative completion source exists, state = `unavailable`;
- no zero values are fabricated.

Formula when the capability exists:

`progress_percent = completed_units / total_units * 100`

Control-question completion uses:

`completed_control_questions / available_control_questions * 100`

Zero denominators -> null.

## Lectures

The Core V1 implementation baseline explicitly classifies `lectures` outside Core V1.

The StudentProgress contract keeps the lectures slot so the confirmed screen capability is not erased.

Until that module is deliberately activated:

`lectures.state = outside_core_v1`

All lecture numeric metrics are null.

The observed competitor value **774** is never a hard-coded total.

## HTTP representation

Authorized existing Student/account/category context returns HTTP 200 even when the projection state is:

- `unbound`,
- `source_unavailable`,
- `stale`.

Those are truthful product states, not missing-resource conditions.

Foreign-tenant Student/account combinations remain not visible.

Unknown category is a validation failure.

## UI release rule

The currently disabled **Postęp** tab may be enabled only after:

1. projection schema is physically materialized;
2. runtime `students.progress` binding is implemented;
3. UI distinguishes real zero/no-activity from unavailable/outside-core;
4. tenant/account/category isolation tests pass.

Placeholder zeroes are forbidden.

## Isolated Stage5 schema corrective

Stage4 is frozen.

The minimum new physical boundary is defined in:

`specs/database/student-progress-projection-migration-extension.yml`

It adds exactly:

- `learning_progress_source_bindings`,
- `student_learning_progress_projections`.

There is no heuristic backfill.

Existing LearningAccounts remain unbound until an authoritative binding exists.

The authority gate itself executes no DDL.

## Explicit non-scope

- no HTTP route in this authority gate,
- no UI enablement,
- no Stage4 mutation,
- no identifier-based source matching,
- no PKK/PWPW runtime,
- no payment-provider webhook,
- no lecture module implementation,
- no fabrication of handbook completion,
- no hard-coded 2185/774 totals.

## Safe continuation

After exact-head authority PASS:

`CORE-V1-STAGE5-STUDENT-PROGRESS-PROJECTION-001`

That gate may materialize only the two registered Stage5 tables and their exact invariants.

A fresh closure audit is required after the schema corrective before any `students.progress` HTTP implementation.


## Exact-head validation evidence

Validated authority head:

`f0e275991eafa4de3594b8ca8dd94f0095a370c8`

Tree:

`d71a791915e5564e5dd3d07a582d3d03dd51fbbf`

Validation:

- Implementation CI #582 / run `34915537700`: **5/5 PASS**
- API Contract Gate #478 / run `34915537698`: **PASS**
- PostgreSQL: **339 tests / 5961 assertions — PASS**
- deterministic restore: **122 -> 122 PASS**
- restore fingerprint: `f3cab286cc8f0aabef219971d90afe424a8dab694c47ade2f517a3d95970716d`
- `RESTORE_DRILL_HARNESS=PASS`
- backend Pint/PHPStan, frontend, contracts/traceability and secret scan: **PASS**

No DDL, HTTP binding, UI enablement, PKK/PWPW runtime or provider webhook behavior was introduced by the authority gate.

## Authority closure effect

`CORE-V1-STUDENT-PROGRESS-AUTHORITY-001 = PASS`

The sole repository-actionable Core V1 HTTP gap remains `students.progress`, but it is no longer authority-blocked. It is now schema-corrective blocked by the isolated projection extension.

Next gate:

`CORE-V1-STAGE5-STUDENT-PROGRESS-PROJECTION-001`

That gate may materialize exactly the two registered Stage5 tables and their invariants. A fresh closure audit remains mandatory before the HTTP runtime/UI gate.
