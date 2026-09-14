# CORE-V1-STAGE4-TRIGGERS-WRITE-FENCE-001 — authority audit

Status: `IN_AUDIT`

This document narrows the frozen Stage-4 trigger tranche before any trigger DDL is registered.
The frozen DAG is unchanged.

## Frozen node scope

Exactly nine write-fence nodes, in canonical order:

1. `MIG-TRG-IDENTITY` — order 1580
2. `MIG-TRG-TRAINING` — order 1590
3. `MIG-TRG-CALENDAR` — order 1600
4. `MIG-TRG-PKK` — order 1610
5. `MIG-TRG-FINANCE` — order 1620
6. `MIG-TRG-LICENSES` — order 1630
7. `MIG-TRG-EXAMS` — order 1640
8. `MIG-TRG-COMMERCE` — order 1650
9. `MIG-TRG-EVENTS` — order 1660

Frozen authority says each node produces its domain's **deferrable final-state and append-only guards**.
The first eight nodes are `restart_safe`; `MIG-TRG-EVENTS` is `manual_review` because it also participates in later backfill/reconcile/validate phases.

## Activation rule

- install guard in `write_fence`,
- prove historical consistency later in `validate`,
- never disable a guard to make backfill pass,
- exact existing trigger/function shape is required on resume,
- no projection node is materialized in this gate.

## Domain authority matrix

### Identity

Source: `identity-rbac.yml`.

Mandatory guard families:

- `organization_memberships` is durable: normal hard-delete forbidden and organization/user identity cannot be reparented;
- final revoked membership has no granted permission/scope rows;
- owner governance is a final-state boundary: every organization with membership state committed through this boundary retains at least one active owner;
- every active owner satisfies the protected owner permission baseline;
- owner-count / protected-baseline checks are transaction-final so atomic owner transfer remains possible.

Audit/outbox immutability is **not** owned here; it belongs to Events.

### Training

Source: `students-courses-training.yml`.

Mandatory guard families:

- formal enrollment ↔ Student formal identity final-state guard;
- course creation/rebind ↔ non-archived Student and Student archive ↔ no open course guard;
- CourseEnrollment material version ↔ exactly one lifecycle history event;
- current TrainingRequirementProfile ↔ exact `requirements_revision` freshness;
- training-hour ledger append-only/source semantics;
- completed TrainingSession ↔ attendance/base-credit final-state equivalence;
- current RecognizedExternalTraining ↔ current course category/training-type context;
- required current PKK profile/context freshness for a course, without provider I/O.

### Calendar

Source: `calendar.yml`.

Mandatory guard families:

- CalendarEvent lifecycle history append-only and current-version/history final-state equivalence;
- CalendarEvent resource-claim owner resolution and exact claim-set final state;
- AvailabilitySlot lifecycle history append-only and current-version/history final-state equivalence;
- AvailabilitySlot booking claim owner resolution and exact claim-set final state;
- meeting-place cross-table final-state guard;
- guard may validate `training_session` owner-kind rows, but automatic claim projection is deferred.

### PKK

Source: `pkk.yml`.

Database-only integrity; provider runtime remains frozen.

Mandatory guard families:

- immutable operation/attempt/profile-snapshot context and normal hard-delete prohibition;
- provider profile snapshots append-only;
- PKK operation lifecycle events append-only and current operation version/status ↔ latest event final-state equivalence;
- idempotency-record exact command/result binding and replay-blocking attempt final state;
- reconciliation history append-only and runtime sequence integrity;
- signature handoff context immutable, accepted signed asset/hash write-once, exact lifecycle final state;
- protected payload / wrapping / redacted projection lineage append-only where canonical source-owned;
- integration configuration revisions append-only and current settings revision ↔ exact revision row;
- provider attempt configuration revision binding immutable;
- course operation sequence immutable/contiguous for runtime operations.

No external provider request, retry, reconciliation call, or PWPW integration is activated by this migration gate.

### Finance

Source: finance section of `student-finance-commerce.yml`.

Mandatory guard families:

- StudentCharge financial identity immutable after insert;
- cancellation tuple write-once; normal hard-delete forbidden;
- StudentPayment financial identity immutable after insert except one write-once reversal tuple; normal hard-delete forbidden;
- deferred final state: active non-reversed payment total never exceeds charge amount;
- cancelled charge has zero active non-reversed payment total.

### Licenses / learning access

Source: `licenses-learning-access.yml` and synchronized `core-schema.yml`.

Named guards include:

- `student_learning_account_current_identifier_final_state_guard`;
- `student_access_export_batch_item_count_guard`;
- `user_password_hash_requires_positive_credential_epoch_guard`;
- `user_password_management_state_and_exclusive_principal_guard`;
- `license_product_duration_immutable_after_inventory_reference_guard`;
- `license_inventory_product_binding_immutable_guard`;
- `license_inventory_assignment_activation_final_state_guard`;
- `license_assignment_sequence_contiguous_guard`;
- `license_product_language_capability_history_immutability_guard`;
- `license_assignment_exact_product_capability_time_guard`;
- `license_activation_migration_only_source_runtime_insert_guard`;
- `license_activation_entitlement_chain_guard`;
- `license_activation_language_equals_current_account_write_guard`.

Append-only activation/entitlement history is part of the same domain boundary.

### Exams

Source: `internal-exams.yml` and synchronized `core-schema.yml`.

Named final-state guards include:

- `exam_inventory_ledger_sequence_and_state_guard`;
- `exam_attempt_course_sequence_contiguous_guard`;
- `exam_attempt_status_timestamp_and_final_state_guard`;
- `exam_station_session_transfer_chain_guard`;
- `exam_result_question_evidence_equivalence_guard`;
- `exam_document_evidence_asset_hash_guard`.

Lifecycle histories, finished result/question/document evidence and correction evidence are append-only/immutable as specified by the bounded context.

### Commerce

Source: commerce section of `student-finance-commerce.yml`.

Trigger-owned guard families:

- immutable OrderItem product/pricing snapshot and deferred Order total = exact sum of line totals;
- trusted Payment / PaymentEvent / settlement final-state equivalence and at-most-one business settlement effect;
- fulfillment final-state guard and exact quantity/ordinal grant-set equivalence;
- purchase downstream parent-kind / exact immutable order-item lineage guards;
- ServiceEntitlement source XOR / product snapshot final-state equivalence and activation final state;
- course-cost origin charge amount/context equivalence.

Purchase-history materialized/runtime projection guards are **deferred to `MIG-PRJ-PURCHASE-HISTORY`**.

### Events

Source: `audit-outbox-notifications.yml` and synchronized `core-schema.yml`.

Trigger-owned canonical guard families:

- `audit_log_append_only_guard`;
- `audit_log_payload_policy_guard`;
- immutable audit policy revisions/current-policy binding;
- `domain_event_immutable_guard`;
- required audit ↔ DomainEvent scope/organization equivalence;
- `outbox_domain_event_scope_tenant_equivalence_guard`;
- outbox state/lease fencing guard;
- append-only retention execution evidence.

Activity-feed projection guards are deferred to `MIG-PRJ-ORGANIZATION-ACTIVITY`.
Notification recipient/read-state projection guards are deferred to `MIG-PRJ-NOTIFICATIONS`.

## Projection exclusion boundary

The following four write-fence nodes are explicitly outside this gate:

- `MIG-PRJ-CALENDAR-RESOURCE-CLAIMS`
- `MIG-PRJ-PURCHASE-HISTORY`
- `MIG-PRJ-ORGANIZATION-ACTIVITY`
- `MIG-PRJ-NOTIFICATIONS`

No function/trigger whose only purpose is to populate or maintain those runtime projections may be installed by the nine `MIG-TRG-*` migrations.

## Candidate target after implementation

- Stage-4 DAG: **170 nodes**
- materialized nodes: **157**
- materialized steps: **205**
- global preflight: **39/39**
- write-fence: **48/52**
- projection write-fence steps remaining: **4**
- PKK provider runtime: **FROZEN_UNTIL_EXPLICIT_UNFREEZE**

Implementation must use deterministic function/trigger signatures, exact metadata checks on resume, zero data mutation, and PostgreSQL-backed behavioral tests for every guard family.

## Package validation progress

The trigger definitions remain outside the canonical migration registry until all nine domain packages pass.

- Finance — **PASS**, commit `4db45d74de7ee8126aa0731beb56ea7f199ea85f`, CI #436 / run `34790803072`, PostgreSQL **243 tests / 4955 assertions**, restore **121 -> 121 PASS**.
- Identity — **PASS**, commit `0a8331c6642ab0391102e515f59ab60e95787f68`, CI #438 / run `34791092407`, PostgreSQL **244 tests / 4960 assertions**, restore **121 -> 121 PASS**.
- Training — **PASS**, commit `b29713af76beb205c33ea5a816bfc22f71993526`, CI #439 / run `34791502564`, PostgreSQL **245 tests / 4961 assertions**, restore **121 -> 121 PASS**.
- Calendar — **IN_VALIDATION**; code candidate is present and remains unregistered in `implementations.json`.

