# 178. CORE-V1-COURSE-COMPLETION-AUTHORITY-001 — authority candidate

Data: 2026-09-14

**Status:** `PASS`

## Executable validation

Exact authority candidate head:

`f150da5a13a272ac1c57ee6350adeaea086d7279`

Evidence:

- Implementation CI #509 / run `34829035686`: **5/5 PASS**
- API Contract Gate #391 / run `34829035628`: **PASS**
- PostgreSQL: **289 tests / 5320 assertions — PASS**
- deterministic restore: **121 -> 121 PASS**
- restore schema fingerprint: `09c57afe2501c79a3a684c0a695b4619114f94ddc24c9bca33728d99c1abb155`
- backend Pint + PHPStan: **PASS**
- frontend quality: **PASS**
- contracts/traceability: **PASS**
- secret scan: **PASS**

The validation changed no runtime semantics because this gate contains authority artifacts only.

## Purpose

This gate closes the ambiguity that prevented `training_completed` from being safely implemented.

It does **not** implement completion yet. It defines the exact existing authorities that Stage 5 must use before the backend and UI guard may be removed.

No schema, migration or OpenAPI change is in scope.

## Existing command surface

Completion remains part of the existing command:

- `POST /api/v1/course-enrollments/{courseEnrollmentId}/stage-transitions`
- operation: `course_enrollments.change_stage`
- target: `training_completed`
- permission: `courses.stage.change`
- `Idempotency-Key`: required
- `If-Match`: required.

No second completion endpoint is introduced.

## Current requirement profile

The formal requirement authority at completion time is exactly one current `training_requirement_profiles` row for the course.

It must satisfy:

- `superseded_at IS NULL`,
- its `requirements_revision` equals `course_enrollments.requirements_revision`,
- its `rule_set_version` resolves to the immutable rule-set authority.

Completion reads the **effective current profile**. It does not rerun the rule engine and does not add extra cross-field assumptions on top of an audited manual override.

The effective fields used are:

- `theory_training_required`,
- `minimum_theory_minutes`,
- `internal_theory_exam_required`,
- `practical_training_required`,
- `minimum_practical_minutes`,
- `internal_practical_exam_required`.

Historical profiles are evidence only and are never substituted for the current completion authority.

## Formal minute gate

Current OSK minutes come from signed `training_hour_ledger_entries` for the exact course and training part. Corrections and reversals therefore affect the sum through their signed minute values.

Recognized external minutes count only from current rows that are:

- not superseded,
- not revoked,
- bound to the current course driving category,
- bound to the current course training type.

For each part:

`formal total = current OSK ledger minutes + current recognized external minutes`

If the current effective profile says theory training is required, theory total must be at least `minimum_theory_minutes`.

If the current effective profile says practical training is required, practical total must be at least `minimum_practical_minutes`.

Theory and practical minutes cannot substitute for one another.

Declared course-hour fields are not credited-hour authority.

## Internal-exam satisfaction

The current requirement profile decides whether theory and/or practical internal exam is required.

A required exam part is satisfied only when there exists an exact-tenant, exact-course, exact-part InternalExamAttempt whose current canonical lifecycle status is `passed` and which has its exact immutable InternalExamResult with `passed=true`.

The management “latest attempt status” projection is **not** completion authority.

### Later requirement changes

A still-passed attempt does **not** need to match the current `training_requirement_profile_id` or the current `requirements_revision`.

That is deliberate and follows existing DB-EXAM-002/004 authority:

- an Attempt permanently records the exact requirement profile/revision that authorized it at creation,
- later requirement-profile supersession does not silently rewrite or block the existing Attempt,
- explicit lifecycle invalidation is the command that withdraws its current pass state.

Therefore a later requirement change does not silently erase a formal PASS.

If the current profile still requires that exam part, any still-valid PASS for the exact course and part can satisfy it.

### Multiple attempts

- `invalidated` does not satisfy,
- `failed` does not satisfy,
- `technical_abort` does not satisfy,
- `created` / `in_progress` do not satisfy,
- a newer failed or nonterminal attempt does not revoke an older attempt that is still `passed`,
- explicit post-finish invalidation of a passed attempt removes that attempt from the satisfying set.

This avoids treating a mutable management projection as formal truth.

## Concurrency

`course_enrollments` remains the completion concurrency root and is locked `FOR UPDATE` first.

The completion-relevant requirement, ledger and external-training mutations already serialize through that course row, so they cannot race past a completion decision on the same course.

For each required exam part, the selected qualifying passed attempt must be held under a shared row lock (or equivalent) while completion commits. This serializes completion against explicit invalidation of that exact pass.

A concurrent exam submit that has not committed yet is never assumed. If it commits after a failed completion attempt, completion can be retried.

Later explicit invalidation after completion does not automatically reopen the course or clear `completed_at`; any resulting correction belongs to explicit closed-course correction policy.

## Atomic completion effect

After all checks pass in one transaction:

- `training_stage = training_completed`,
- `completed_at` is set to one authoritative command timestamp,
- interrupted/cancelled projections remain null,
- course version increments exactly once,
- one `completed` lifecycle event is appended,
- audit/domain/outbox intent is written in the same transaction.

The implementation gate will use a dedicated allowlisted audit action:

`course.completed`

Completion is not serialized as an ordinary `course.stage_changed` audit event.

## UI boundary

The existing Vue guard for `training_completed` may be removed only after backend completion eligibility is implemented and validated.

The UI reuses the existing stage-transition request and `If-Match`; it does not precompute formal eligibility as authority.

Backend conflict/rule failures remain fail-closed and are surfaced to the user.

## Required negative evidence

Implementation must prove at minimum:

- stale or missing current requirement profile blocks completion,
- insufficient theory/practical minutes block completion,
- missing required theory/practical PASS blocks completion,
- invalidated PASS does not count,
- newer FAIL does not erase an older still-valid PASS,
- wrong-course or wrong-part PASS cannot count,
- stale `If-Match` cannot partially complete,
- retries do not duplicate version/history/audit effects,
- hour/requirement mutation races serialize on CourseEnrollment,
- pass invalidation vs completion serializes on the exact pass row.

## Preservation

This authority gate does not:

- change Stage-4 schema or migrations,
- change OpenAPI,
- activate PKK/PWPW,
- invent pricing/VAT,
- change bulk credential reset semantics,
- rewrite historical requirement profiles,
- rewrite historical exam attempts/results,
- rewrite historical ledger or external-training rows.

The next step is runtime/UI implementation only after this authority candidate passes its own API/Implementation CI.
