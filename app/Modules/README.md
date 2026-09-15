# Application modules

This directory is the modular-monolith boundary for OSK Panel application runtime.

## Current materialized Core V1 modules

The repository currently contains application code for:

- `IdentityTenant` — auth/session/registration/password recovery/social identity and tenant context,
- `OrganizationSettings` — provider-neutral organization/settings runtime,
- `AuditNotification` — audit, activity, notifications and atomic outbox coupling,
- `ResourcesCore` — locations, staff, vehicles and dictionaries,
- `UploadsAssets` — private upload/presign/complete asset transport,
- `StudentsCourses` — Students, CourseEnrollment, requirements, recognized external training and local course-scoped PKK identity,
- `StudentProgress` — learning-progress read projection,
- `StudentFinance` — charge/payment ledger and summary,
- `CalendarTraining` — calendar events, availability, driving lessons, TrainingSession and training-hour ledger runtime,
- `LearningAccess` — learning accounts, license inventory/assignment/activation and credential handoff,
- `InternalExams` — internal-exam inventory, attempts, access, station execution and result/document flow,
- `CommerceDashboard` — orders, payment intents, purchase history, entitlements and dashboard projections,
- `FormalDocuments` — formal training document preview/approval/history/delivery/download.

Historical note: `S5-FOUND-001` originally materialized only the first `IdentityTenant`, `OrganizationSettings` and `AuditNotification` foundation. The sentence that later modules were “intentionally absent” is no longer true after Core V1 implementation.

## PKK/PWPW boundary

There is intentionally **no provider-specific PKK/PWPW application module** at this time.

Implemented:
- local encrypted, versioned PKK identity owned by a concrete `CourseEnrollment`,
- manual PKK entry,
- provider-neutral Stage 4 database substrate and guards.

Frozen pending authoritative PWPW guidance/contract:
- provider adapter and credentials runtime,
- import/fetch/live calls,
- provider status mapping,
- signing/reconciliation semantics,
- provider-specific HTTP routes and Vue workspace.

Current implementation/backlog/freeze authority:
- `docs/227-current-project-status-authority.md`,
- `specs/current-project-status.yml`.
