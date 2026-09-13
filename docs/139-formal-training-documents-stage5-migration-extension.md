# 139. Formal training documents — Stage-5 migration extension authority

Status: `FORMAL-DOC-002 validation candidate`

## Scope

FORMAL-DOC-002 creates a migration authority for formal training documents without reopening the frozen Stage-4 DAG.

The frozen Stage-4 baseline remains:

- plan identity: `d2fd6bc999dc2a5ee024e9b91bf00f5a5dad29575554a0ba42eba67107c23f10`,
- execution identity: `1d2f1d3a47a4a09b749d0b164137857a241c07afbaad02789ec21e03cb4118ce`,
- authority blob: `ad5f2aa2e14ef248b95dd3dda0d1cbcb2e69d441`,
- 170 authoritative nodes,
- 112 materialized nodes / 112 executable steps.

FORMAL-DOC-002 must fail if any of those values change.

PKK remains outside this slice and remains frozen until explicit unfreeze after authoritative PWPW guidance.

## Separate Stage-5 extension

The extension is bound to:

- authority: `specs/database/formal-training-documents-migration-extension.yml`,
- plan: `database/migration-plan/stage5-formal-documents-plan.json`,
- implementation registry: `database/migration-plan/stage5-formal-documents-implementations.json`,
- isolated migration root: `database/migrations/stage5/formal-documents`.

The Stage-5 extension plan identity is:

`34cada121f4fd4963b1461308fb517c50ee647005ce9421f4d1af40c80d44b99`

The empty FORMAL-DOC-002 registry execution identity is:

`8948ce50d6c7f3c8871586945d2e757397600182820cbb0e89d80add12998ade`

## Four authoritative nodes

The extension contains exactly four nodes:

1. `S5DOC-ALTER-COURSE-ENROLLMENTS-DOCUMENT-MODE`
   - owns the staged addition of `document_mode`, `document_mode_selected_at`, and `document_mode_selected_by_user_id`,
   - legacy rows default to `paper`,
   - legacy selection time is derived from the already durable `course_enrollments.created_at`,
   - the node is `manual_review` because it changes an existing high-value course table.

2. `S5DOC-TBL-FORMAL-TRAINING-DOCUMENT-TEMPLATES`
   - creates platform-global immutable template/version authority,
   - requires non-overlapping effective intervals per document type.

3. `S5DOC-TBL-FORMAL-TRAINING-DOCUMENTS`
   - creates tenant-owned immutable document revisions,
   - depends on the course document-mode extension and template authority,
   - requires same-tenant course and asset relations,
   - requires revision uniqueness and deterministic evidence uniqueness.

4. `S5DOC-TBL-FORMAL-TRAINING-DOCUMENT-EVENTS`
   - creates append-only document lifecycle evidence,
   - depends on the formal document revision table,
   - forbids raw personal-data payloads in events.

## Phase authority

The extension reuses the established phase vocabulary:

`expand -> preflight -> write_fence -> backfill -> reconcile -> validate -> contract`

Not every node uses every phase.

The course-enrollment document-mode node explicitly reserves:

`expand -> preflight -> backfill -> validate -> contract`

This prevents an unsafe one-shot `ALTER TABLE ... NOT NULL DEFAULT` rewrite on an already materialized course table.

The intended legacy flow is:

1. expand nullable columns and domain checks,
2. preflight the existing population,
3. backfill `paper` and `document_mode_selected_at = created_at`,
4. validate completeness/domain,
5. contract to the final non-null shape.

No DDL file is materialized in FORMAL-DOC-002.

## Fail-closed executable registry

The Stage-5 implementation registry is intentionally empty:

- implemented nodes: 0,
- implemented steps: 0,
- claim that DDL is implemented: false.

`Stage5FormalDocumentsMigrationPlan` validates:

- authority git-blob identity,
- exact four-node order and dependencies,
- exact phase order,
- extension plan identity,
- Stage-4 baseline preservation,
- registered file hashes and directory isolation,
- execution identity,
- absence of unregistered Stage-5 migration PHP files.

Two explicit commands exist:

- `php artisan migration:stage5:formal-docs:plan:validate --json`,
- `php artisan migration:stage5:formal-docs:controlled ...`.

The controlled executor shares the existing PostgreSQL advisory lock `(519662, 5001)` so Stage-4 and Stage-5 migration work cannot run concurrently.

Until FORMAL-DOC-003 registers concrete migration steps, execution fails before acquiring the lock or touching the database.

## FORMAL-DOC-002 PASS criteria

PASS requires all of the following on one exact tree:

- Stage-4 `170 / 112 / 112` remains unchanged,
- Stage-4 plan identity remains `d2fd6bc9...`,
- Stage-4 execution identity remains `1d2f1d3a...`,
- Stage-5 extension has exactly four nodes,
- Stage-5 plan identity validates as `34cada12...`,
- empty registry identity validates as `8948ce50...`,
- default `php artisan migrate` cannot discover extension migrations,
- the isolated executor refuses execution while registry is empty,
- no PKK runtime/provider work is introduced,
- backend/static analysis, PostgreSQL runtime, frontend, contracts/traceability and secret scan remain green.

## Next gate

After FORMAL-DOC-002 closes, the next single step is:

**FORMAL-DOC-003 — materialize the exact Stage-5 formal-document migration steps in small reviewed phase slices.**

FORMAL-DOC-003 may populate the extension registry and migration root, but it must not rewrite the Stage-4 plan, Stage-4 execution identity, or PKK freeze.
