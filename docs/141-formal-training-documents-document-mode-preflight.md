# 141. Formal training documents — legacy document-mode preflight

Status: `FORMAL-DOC-004 validation candidate`

## Scope

FORMAL-DOC-004 materializes and executes only the **preflight** phase for:

`S5DOC-ALTER-COURSE-ENROLLMENTS-DOCUMENT-MODE`

This gate is intentionally read-only with respect to business data.

It does not:

- backfill `document_mode`,
- backfill `document_mode_selected_at`,
- enforce final `NOT NULL`,
- create or modify formal document revisions,
- add later cross-table foreign keys,
- implement document generation,
- touch PKK provider runtime.

PKK remains frozen.

## Registry state

The Stage-5 formal-documents plan identity remains:

`34cada121f4fd4963b1461308fb517c50ee647005ce9421f4d1af40c80d44b99`

The registry now contains:

- 4 expand steps,
- 1 preflight step,
- 5 total executable steps.

The resulting execution identity is:

`7c04995e966765a254785a9a75619cc7b1b7df0bcf514be92516e1c7eee68352`

The preflight migration file is SHA-256 bound as:

`27d4afecefa1ab5b6a3c4cb9459695bb982799d607c59791baa0901d46ed9704`

## What is considered deterministically backfillable

A legacy course row is eligible for the next paper-mode backfill only when all three Stage-5 selection columns remain unset:

- `document_mode IS NULL`,
- `document_mode_selected_at IS NULL`,
- `document_mode_selected_by_user_id IS NULL`.

The existing durable `course_enrollments.created_at` is the intended selection-time source for that later backfill.

FORMAL-DOC-004 verifies the source timestamp is available but does not write it yet.

## Fail-closed states

Preflight refuses execution when it finds any of the following:

1. a legacy row with `document_mode IS NULL` but a selection timestamp or selecting user already present;
2. a row with a selected document mode but no selection timestamp;
3. a non-null mode outside `paper|electronic`;
4. a formal training document revision already bound to a course whose document mode is still null;
5. a legacy row without the durable source timestamp required for deterministic backfill.

These are treated as review blockers rather than silently normalized.

## Why formal documents block ambiguous legacy conversion

If a formal document revision already exists for a course with no durable selected mode, later assigning a mode could create a false historical interpretation.

Therefore FORMAL-DOC-004 refuses that state instead of choosing a value after the fact.

This is deliberately stricter than merely checking that a SQL `UPDATE` could be executed.

## Runtime proof

The dedicated PostgreSQL test contract proves:

- a clean legacy `NULL/NULL/NULL` row passes preflight,
- passing preflight does not mutate those business columns,
- partial legacy selection state fails closed,
- selected mode without selection timestamp fails closed,
- an existing formal document revision against a null-mode course fails closed,
- failed preflight is not recorded as an applied Laravel migration,
- the next `backfill` phase remains closed because no backfill migration is registered.

Migration execution evidence uses an isolated test journal so intentional negative preflight tests cannot contaminate later runtime checks.

## Stage-4 preservation

FORMAL-DOC-004 does not modify the frozen Stage-4 authority:

- plan identity remains `d2fd6bc999dc2a5ee024e9b91bf00f5a5dad29575554a0ba42eba67107c23f10`,
- execution identity remains `1d2f1d3a47a4a09b749d0b164137857a241c07afbaad02789ec21e03cb4118ce`,
- DAG remains 170 nodes,
- implemented Stage-4 nodes/steps remain 112 / 112.

## FORMAL-DOC-004 PASS criteria

PASS requires one exact tree proving:

- Stage-5 plan identity is unchanged,
- Stage-5 execution identity is exactly `7c04995e...`,
- exactly one preflight step is registered,
- the preflight file SHA-256 validates,
- clean legacy state passes on PostgreSQL,
- no business data is mutated by preflight,
- all ambiguous/unsafe states above fail closed,
- backfill remains unmaterialized and fail-closed,
- Stage-4 remains **170 / 112 / 112**,
- backend/static analysis, frontend, contracts/traceability, secret scan and restore drill remain green.

## Next gate after PASS

**FORMAL-DOC-005 — deterministic legacy paper-mode backfill.**

The backfill must repeat the safety assumptions defensively, then convert only eligible legacy `NULL/NULL/NULL` rows to:

- `document_mode = 'paper'`,
- `document_mode_selected_at = course_enrollments.created_at`,
- `document_mode_selected_by_user_id = NULL`.

It must preserve already selected valid rows unchanged and must not yet perform the final `NOT NULL` contract.
