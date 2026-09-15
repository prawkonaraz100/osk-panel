# 179. CORE-V1-COURSE-COMPLETION-001 — closure

Data: 2026-09-14

**Status:** `PASS`

## Exact scope

This gate materializes the previously open Course Completion runtime + UI slice on the existing command surface:

- `POST /api/v1/course-enrollments/{courseEnrollmentId}/stage-transitions`,
- operation `course_enrollments.change_stage`,
- target `training_completed`,
- permission `courses.stage.change`,
- existing `Idempotency-Key` and `If-Match` semantics.

No new completion endpoint, schema, migration or OpenAPI operation was introduced.

The implementation follows the closed authority from:

- `docs/178-core-v1-course-completion-authority.md`,
- `specs/design/course-completion.yml`.

PKK/PWPW runtime remains frozen.

## Materialized completion authority

The backend now allows `training_completed` only after all current formal requirements pass in the same transaction.

Completion requires:

- exactly one current `training_requirement_profiles` row,
- profile `requirements_revision` equal to the locked CourseEnrollment revision,
- the profile rule-set version to resolve to immutable rule-set authority,
- current signed OSK minutes from `training_hour_ledger_entries`,
- current, non-superseded and non-revoked recognized external minutes for the current category and training type,
- independent theory/practical minimum checks when the corresponding current profile flag requires training,
- for every required internal-exam part, an exact-tenant, exact-course, exact-part InternalExamAttempt whose current canonical status remains `passed` and whose immutable result has `passed=true`.

The mutable latest-attempt management projection is not used as completion truth.

A newer failed attempt does not erase an older still-valid pass. Explicit invalidation withdraws that pass.

## Atomic effect

After eligibility succeeds, one transaction:

- sets `training_stage=training_completed`,
- sets `completed_at` to the command timestamp,
- preserves the terminal projection with interrupted/cancelled fields null,
- increments the course version exactly once,
- appends one `completed` CourseEnrollment lifecycle event,
- emits allowlisted audit action `course.completed`,
- writes matching domain/outbox intent in the same transaction.

Normal same-stage idempotent behavior remains unchanged.

## UI boundary

`StudentCourseWorkspace.vue` no longer contains the temporary local block for `training_completed`.

The UI:

- reuses the existing stage-transition endpoint,
- preserves `If-Match`,
- does not calculate formal completion eligibility client-side,
- surfaces backend conflicts/rules,
- uses the completion-specific success message `Szkolenie zostało zakończone.`.

## Executable evidence

Initial implementation head:

`573412e6c47c7ead89a49d5f04f8fc88cf866f8b`

Corrective heads:

- `02a938e37c0b7b1bfe71618bdc0334176d30c2fd` — Pint-only formatting correction,
- `3576c9b0aa555840a1536fa366239a95d345d21e` — CourseEnrollment row typing for PHPStan,
- `72653f5f7da347572d09409fb4ab78dbaa2528ec` — restored the two original `recognizeExternalTraining` row-property accesses after the typing correction was intentionally narrowed back to the completion helper.

Final exact-head evidence on `72653f5f7da347572d09409fb4ab78dbaa2528ec`:

- Implementation CI #514 / run `34832196978`: **5/5 PASS**
- API Contract Gate #397 / run `34832196975`: **PASS**
- PostgreSQL: **295 tests / 5380 assertions — PASS**
- deterministic restore: **121 -> 121 PASS**
- restore schema fingerprint: `09c57afe2501c79a3a684c0a695b4619114f94ddc24c9bca33728d99c1abb155`
- backend Pint + PHPStan: **PASS**
- frontend quality: **PASS**
- contracts/traceability: **PASS**
- secret scan: **PASS**

The PostgreSQL suite grew from 289 / 5320 to 295 / 5380 through the completion runtime and UI contract evidence.

## Acceptance evidence

The implementation proves:

- zero-requirement supplementary completion commits atomically and HTTP replay does not duplicate lifecycle/audit/domain/outbox effects,
- insufficient current formal minutes block completion without partial write,
- recognized external minutes contribute only through current exact-context authority,
- a required theory exam blocks completion until a canonical pass exists,
- a newer FAIL does not erase an older still-valid PASS,
- later requirement-profile supersession alone does not silently invalidate a historical still-passed attempt,
- explicit post-finish invalidation removes a PASS from the satisfying set,
- practical exam requirements are checked independently,
- the old client-side completion placeholder guard is absent.

## Preservation

This gate does not:

- alter Stage-4 schema or migrations,
- alter OpenAPI,
- activate PKK/PWPW,
- invent server-side price/VAT authority,
- invent password-reset token authority,
- invent social OAuth provider/runtime authority,
- substitute Training Hour Ledger for missing online-learning Student Progress authority,
- change bulk credential reset semantics or invent missing expected credential versions,
- reopen a completed course automatically after a later exam invalidation.

## Closure effect

The repository HTTP inventory is unchanged by this gate because the stage-transition HTTP binding already existed before implementation.

Therefore:

- repo-actionable missing physical HTTP bindings remain **12** pending the next full closure audit,
- the separate non-HTTP repository P1 `course_completion` is now **CLOSED**,
- `resource_asset_UI` remains closed from its prior gate.

A fresh repository-wide closure audit is required before declaring any broader repository closure.

## Next safe slice

Run `CORE-V1-CLOSURE-AUDIT-003` on this accepted tree.

The audit must:

- preserve the 12 HTTP missing-binding classification unless the exact tree proves otherwise,
- mark Course Completion runtime + UI as closed,
- re-evaluate whether any other repo-actionable non-HTTP P1 remains,
- keep PKK/PWPW frozen,
- keep blocked Identity/Auth, Student Progress, Commerce and credential-bulk submodes fail-closed unless a canonical authority has appeared.
