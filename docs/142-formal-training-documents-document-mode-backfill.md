# 142. Formal training documents — deterministic legacy paper-mode backfill

Status: `FORMAL-DOC-005 validation candidate`

## Scope

FORMAL-DOC-005 materializes only the **backfill** phase for the existing course-enrollment document-mode node.

It does not:

- rewrite the frozen Stage-4 170-node authority,
- change the Stage-5 extension plan identity,
- add final `NOT NULL` contract constraints,
- validate the deferred same-tenant cross-table foreign keys,
- add template effective-interval exclusion,
- add document-event append-only enforcement,
- generate or approve formal training documents,
- implement PKK provider runtime.

PKK remains frozen.

## Registered backfill step

The extension plan identity remains:

`34cada121f4fd4963b1461308fb517c50ee647005ce9421f4d1af40c80d44b99`

The registry now contains six total steps:

- 4 × `expand`,
- 1 × `preflight`,
- 1 × `backfill`.

The FORMAL-DOC-005 execution identity is:

`fc32a8fc010561cd395af881a10191c76bc9c53266d34bdab56eaa382f061094`

The registered backfill migration is:

`database/migrations/stage5/formal-documents/backfill/S5DOC-ALTER-COURSE-ENROLLMENTS-DOCUMENT-MODE/2026_09_13_000060_backfill_formal_document_mode_legacy_rows.php`

SHA-256:

`8ac8fdd6b306480e5ed7f4ec501cb941e911e90c004735ac6ee036d5aee0410c`

## Deterministic conversion

Only rows with the exact legacy state:

- `document_mode IS NULL`,
- `document_mode_selected_at IS NULL`,
- `document_mode_selected_by_user_id IS NULL`

are eligible.

Each eligible row is converted to:

- `document_mode = 'paper'`,
- `document_mode_selected_at = created_at`,
- `document_mode_selected_by_user_id = NULL`.

The backfill deliberately does **not** use current wall-clock time for historical selection. The durable course `created_at` value is the deterministic authority selected in Gate 2/4.

Existing explicit `paper` or `electronic` selections are not rewritten.

## Defensive revalidation

A previously successful preflight is required by the controlled executor before the backfill phase may enter.

Because data could theoretically drift after preflight, the backfill re-checks the same ambiguity boundaries inside the write transaction before changing rows:

- partial legacy selection metadata,
- selected mode without timestamp,
- unsupported non-null mode,
- formal document revision already bound to a null-mode course,
- missing durable `created_at`.

Any such state fails closed.

## Concurrency boundary

The backfill runs inside one PostgreSQL transaction and takes:

`LOCK TABLE course_enrollments IN SHARE ROW EXCLUSIVE MODE`

before re-checking invariants and updating legacy rows.

This prevents a concurrent application write from changing the relevant course-enrollment population between defensive validation and the deterministic update.

The Stage-5 controlled executor still also holds the shared migration advisory lock `(519662, 5001)`.

## Postconditions

A successful backfill requires:

- number of updated rows equals the pre-update eligible legacy count,
- zero rows remain with `document_mode IS NULL`,
- zero selected rows remain without `document_mode_selected_at`,
- the Laravel migration repository records the backfill exactly once.

Automatic destructive `down()` is forbidden.

## Runtime proof

FORMAL-DOC-005 tests prove:

1. backfill cannot run before the registered preflight is applied,
2. a clean legacy row becomes `paper` with `selected_at = created_at`,
3. `document_mode_selected_by_user_id` remains null for migrated legacy history,
4. existing `electronic` selection is not overwritten,
5. business `updated_at` is not rewritten by the historical backfill,
6. drift introduced after a successful preflight is detected and the backfill is not recorded,
7. re-running the controlled backfill command after success is idempotent,
8. the next `validate` phase remains fail-closed because it is not yet materialized.

## FORMAL-DOC-005 PASS criteria

PASS requires one exact tree proving:

- Stage-4 remains **170 / 112 / 112** with unchanged identities,
- Stage-5 plan identity remains `34cada12...`,
- Stage-5 execution identity is exactly `fc32a8fc...`,
- total materialized extension steps are exactly 6,
- backfill file SHA-256 validates,
- preflight-before-backfill ordering is enforced,
- deterministic paper/created_at conversion passes on PostgreSQL,
- existing explicit electronic mode is preserved,
- drift-after-preflight fails closed,
- failed backfill is not recorded as applied,
- repeated controlled backfill is idempotent,
- zero null document modes remain after a successful backfill fixture,
- validate remains fail-closed,
- backend/static analysis, frontend, contracts/traceability, secret scan and restore drill remain green.

## Next gate after PASS

**FORMAL-DOC-006 — validate formal-document relational and immutability constraints.**

That gate may materialize the Stage-5 `validate` phase for the document-mode check and the three new formal-document tables. It must not yet perform the final document-mode `NOT NULL` contract unless the validate evidence proves the population is safe.
