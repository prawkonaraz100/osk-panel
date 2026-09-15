# 231. Documentation ↔ code consistency re-audit

Data: 2026-09-15

**Gate:** `DOCS-CODE-CONSISTENCY-REAUDIT-002`  
**Branch:** `docs/deep-consistency-reaudit-002`  
**Starting main:** `20e6989486bd27c9331a8f5dd55acc6f2b0a3d2b`  
**Code/business-runtime changes:** **none**  
**PKK/PWPW runtime changes:** **none — frozen boundary preserved**

## 1. Authority used for this audit

Current implementation truth is resolved in this order for the relevant question:

1. live Git ref `main` for exact current repository head,
2. `docs/227-current-project-status-authority.md`,
3. `specs/current-project-status.yml`,
4. physical runtime code such as `routes/web.php`, controllers/services, Vue components and migrations,
5. `specs/traceability/implementation/*.yml`,
6. semantic design/security/database/screen authorities,
7. historical preservation, mapping and gate ledgers.

Historical `READY_FOR_IMPLEMENTATION`, `OPEN/PENDING`, `implementation_started: false` and similar point-in-time values are preserved when they are evidence, but they are not current backlog unless the current-status authorities say so.

## 2. Runtime/API reconciliation rechecked

The physical API v1 inventory was compared again against preserved OpenAPI operations.

Observed runtime:
- **160** physical `/api/v1` Laravel bindings,
- **173** preserved OpenAPI HTTP operations.

The 15 OpenAPI-only operations remain intentional:
- **14 PKK/PWPW provider operations** — `FROZEN_UNTIL_EXPLICIT_UNFREEZE`,
- **1 payment-provider webhook** — `EXTERNAL_PROVIDER_BOUNDARY_NOT_IMPLEMENTED`.

Two runtime-only compatibility/development operations remain intentionally outside the canonical preserved operation inventory:
- `GET /api/v1/development/sample/legal/terms/current`,
- `POST /api/v1/internal-exam-stations/heartbeat`.

No hidden API drift was found.

## 3. Confirmed inconsistencies repaired

### 3.1 Top-level current-status evidence

`README.md`, `docs/227-current-project-status-authority.md` and
`specs/current-project-status.yml` mixed an embedded historical evidence SHA
with wording that could be read as the live current head.

Correction:
- exact live-head authority is now explicitly Git ref `main`,
- embedded SHA is explicitly a verified evidence baseline, not a self-referential current-head contract,
- latest fully verified pre-reaudit baseline is
  `20e6989486bd27c9331a8f5dd55acc6f2b0a3d2b`,
- Implementation CI `34997297399` / #702 was 6/6 PASS,
- API Contract Gate `34997297369` / #526 was PASS.

### 3.2 Application module inventory

`app/Modules/README.md` still stated that later core modules were intentionally
absent after the original S5 foundation.

That statement was false relative to the current tree.

Correction:
- the materialized Core V1 application modules are now listed,
- historical S5-foundation context is preserved as history,
- provider-specific PKK/PWPW application runtime is explicitly absent/frozen.

### 3.3 Course Completion traceability

`specs/traceability/implementation/StudentsCourses.yml` referenced a nonexistent
`Tests\\Feature\\CourseCompletionRuntimeTest`.

Runtime coverage actually exists in `InternalExamCoreTest`.

Correction:
- traceability now points to the concrete completion tests:
  - `test_course_completion_zero_requirement_is_atomic_and_http_idempotent`,
  - `test_course_completion_requires_current_minutes_and_exam_evidence`,
  - `test_course_completion_uses_still_passed_attempt_not_latest_management_status_across_requirement_supersession`,
  - plus `CourseCompletionUiContractTest`.

### 3.4 Initial license on Student create

The same traceability file said `initial_license` remained contract-visible but
runtime rejected it until Learning Access/Licenses.

That was false.

Current code:
- validates `initial_license`,
- resolves `licenses.assign`,
- delegates to `LicenseService`,
- atomically creates the learning-account/license assignment for the newly created Student,
- is covered by `LearningAccessCoreTest::test_student_create_initial_license_is_atomic_and_server_binds_new_student_id`.

Correction: status is now `IMPLEMENTED`.

### 3.5 Organization Settings traceability/design

Historical wording still described Settings UI/runtime as an implementation
candidate or next gate.

Correction:
- traceability points to
  `resources/js/modules/OrganizationSettings/SettingsWorkspace.vue`,
- provider-neutral settings design now records materialized controller/service/UI runtime,
- PKK remains frozen and is not called by provider-neutral settings.

### 3.6 Screen status drift

Several screen specs still reported mapping-readiness rather than current runtime.

Corrected as implemented:
- `specs/screens/students.yml`,
- `specs/screens/staff.yml`,
- `specs/screens/vehicles.yml`,
- `specs/screens/student-formal-documents.yml`.

Purchase screens were clarified in the opposite direction:
- `/licencje/wykup` — **UI route not materialized**, backend license order create implemented,
- `/egzamin-wewnetrzny/wykup` — **UI route not materialized**, backend exam order create implemented.

### 3.7 Terms/sample-data boundary

Older design/screen wording still described registration and accepted-Terms
document resolution as blocked without acknowledging `SAMPLE-DATA-001`.

Correction:
- development sample Terms discovery/document resolution is recorded,
- it remains explicitly non-production,
- real production versioned legal-document authority remains required.

### 3.8 Historical Stage 4 DB metadata

Multiple Stage 4 bounded-context specs intentionally preserve values such as:
- `implementation_started: false`,
- `Laravel_migrations_created: false`,
- intermediate `OPEN/PENDING` statuses.

Without context they could be misread as current repository state.

Correction:
- those values are preserved,
- affected files now identify themselves as historical Stage-4 point-in-time authority,
- current materialization is explicitly identified as materialized/runtime-tested,
- physical truth remains migrations/registry/tests plus current-status authority.

No database invariant was changed.

### 3.9 Production/privacy status drift

`specs/privacy/retention-schedule.yml` still described the privileged executor as
a partial future boundary although `PROD-RETENTION-EXECUTOR-001` is accepted.

Correction:
- current bounded technical TTL executor for `idempotency_records` is recorded as implemented,
- future data-class expansion still requires separate reviewed authority.

`specs/operations/production-readiness.yml` also had a gate-local
`PENDING_PROMOTION` value that could be read as current.

Correction:
- it is now marked as historical gate evidence,
- later repository closure remains PASS,
- production itself remains **not ready** because target external evidence is missing.

### 3.10 Preservation/menu aggregates

Observed reference-product menu routes and `READY_FOR_IMPLEMENTATION` statuses
were being mixed too easily with physical OSK Panel runtime routes.

Correction:
- `routes/web.php` is explicitly physical route authority,
- preservation/menu records remain capability evidence,
- `/integracja-pkk`, `/licencje/wykup` and
  `/egzamin-wewnetrzny/wykup` are not claimed as current physical SPA routes,
- `specs/admin-osk-services.yml` and `specs/functional-requirements.yml`
  no longer expose already-closed preimplementation work as current gaps.

## 4. Intentional differences retained

The re-audit deliberately did **not** convert every historical `DEFERRED` value
to `IMPLEMENTED`.

Example:
- the first two entries in
  `specs/traceability/implementation/CalendarTraining.yml` still say
  `DEFERRED_UI_UNTIL_CALENDAR_FEATURE_SLICE`.

This is correct for those exact capabilities: TrainingSession attendance,
terminal completion and manual training-hour correction exist in backend runtime,
but the current `CalendarWorkspace.vue` does not expose direct UI for those
specific commands.

Historical closure/gate ledgers are also retained when clearly classified as
point-in-time history.

## 5. PKK/PWPW conclusion

The audit confirms the intended boundary:

Implemented:
- local course-scoped PKK identity,
- manual entry,
- encryption/versioning,
- provider-neutral database substrate.

Not implemented / frozen:
- PWPW provider adapter,
- provider credentials runtime,
- provider-specific controller/service,
- provider HTTP routes,
- provider Vue UI,
- fetch/import/live calls,
- provider status/signing/reconciliation semantics.

Core service does **not** require PWPW integration.

## 6. Current real remaining productization gaps

The current-status authorities converge on:
- Registration UI,
- License purchase UI,
- Internal-exam purchase UI,
- browser end-to-end golden paths.

Production go-live additionally remains:
- `BLOCKED_EXTERNAL_EVIDENCE`.

Provider-specific PKK/PWPW remains:
- `FROZEN_UNTIL_EXPLICIT_UNFREEZE`.

## 7. Verification rule

This re-audit changes documentation/spec classification only. It does not alter
business runtime, migrations, API bindings or Vue behavior.

The branch must still pass repository CI before merge. After merge, the exact
main head must receive normal post-merge validation before this re-audit is
considered closed.
