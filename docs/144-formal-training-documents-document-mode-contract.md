# 144. Formal training documents — final document-mode contract

Status: `FORMAL-DOC-007 PASS`

## Scope

FORMAL-DOC-007 materializes the single reserved Stage-5 `contract` step for:

`S5DOC-ALTER-COURSE-ENROLLMENTS-DOCUMENT-MODE`

It does not change the formal-document template, revision or event tables, does not add document-generation runtime and does not touch PKK provider integration.

The Stage-5 plan identity remains:

`34cada121f4fd4963b1461308fb517c50ee647005ce9421f4d1af40c80d44b99`

The Stage-5 execution identity becomes:

`31704fcab61761aa9a952d824dc543a6f46e7a3717792d0a9349cefaaf57651f`

The registry contains exactly **11 steps**:

- 4 × `expand`,
- 1 × `preflight`,
- 1 × `backfill`,
- 4 × `validate`,
- 1 × `contract`.

## Final physical shape

After the contract succeeds:

- `course_enrollments.document_mode` is **NOT NULL**,
- `course_enrollments.document_mode_selected_at` is **NOT NULL**,
- `course_enrollments.document_mode_selected_by_user_id` remains nullable.

The nullable actor is deliberate. Deterministically migrated legacy history uses:

- mode = `paper`,
- selected_at = original `created_at`,
- selected_by_user_id = `NULL`.

No fabricated historical actor is introduced.

## Preconditions

The controlled executor already requires all earlier registered phases to be applied before entering `contract`.

The contract migration additionally fails closed unless:

1. zero course rows have null mode or selection timestamp,
2. `course_enrollments_document_mode_check` exists and is validated,
3. `course_enrollments_document_mode_complete_check` exists and is validated.

This prevents a forged migration-repository state from turning the final physical contract into the first data-quality check.

## Contract DDL

The migration performs only:

`ALTER COLUMN document_mode SET NOT NULL`

and

`ALTER COLUMN document_mode_selected_at SET NOT NULL`.

The existing defaults remain unchanged:

- unspecified new mode defaults to `paper`,
- unspecified selection timestamp defaults to PostgreSQL `CURRENT_TIMESTAMP`.

The Gate-6 database guard preventing normal mode changes at or after `started_at` remains active.

## Registered contract file

`database/migrations/stage5/formal-documents/contract/S5DOC-ALTER-COURSE-ENROLLMENTS-DOCUMENT-MODE/2026_09_13_000110_contract_formal_document_mode_not_null.php`

SHA-256:

`2be16061c5f7b6736071a72712f5f24dd371b1b048ec9dfc64ca1eb1f4c817f9`

Restart classification remains `manual_review` because this step tightens an existing high-value course table.

Automatic destructive `down()` remains forbidden.

## Runtime proof

FORMAL-DOC-007 tests prove:

1. contract cannot enter before the validate phase is fully applied,
2. both required columns become physically `NOT NULL`,
3. legacy selection actor remains nullable,
4. PAPER and server-timestamp defaults still work for omitted values,
5. explicitly null mode/timestamp are rejected by PostgreSQL,
6. controlled contract execution is idempotent and recorded exactly once,
7. missing validated constraint evidence makes contract fail closed,
8. failed contract execution is not recorded as applied.

## Preservation

FORMAL-DOC-007 must preserve:

- Stage-4 plan identity `d2fd6bc999dc2a5ee024e9b91bf00f5a5dad29575554a0ba42eba67107c23f10`,
- Stage-4 execution identity `1d2f1d3a47a4a09b749d0b164137857a241c07afbaad02789ec21e03cb4118ce`,
- Stage-4 counts **170 / 112 / 112**,
- Stage-5 plan identity `34cada12...`,
- four validate constraints/guards from FORMAL-DOC-006,
- PKK provider runtime frozen.

## PASS criteria

PASS requires one exact tree proving:

- contract migration SHA-256 `2be16061...`,
- execution identity `31704fca...`,
- exactly 11 materialized Stage-5 steps,
- exactly one contract step for the course document-mode node,
- validate-before-contract ordering PASS,
- required validate-constraint evidence PASS,
- final physical nullability `NO / NO / YES`,
- PAPER/server timestamp defaults preserved,
- explicit NULL rejection PASS,
- controlled contract idempotency PASS,
- failed contract not recorded as applied PASS,
- Stage-4 170/112/112 preservation PASS,
- backend/static analysis PASS,
- frontend quality PASS,
- contracts/traceability PASS,
- secret scan PASS,
- deterministic restore harness PASS.

## Next gate after PASS

**FORMAL-DOC-008 — formal document generation and approval runtime.**

That gate may begin the application/API service boundary for building an exact evidence bundle, reviewer approval, deterministic revision creation and canonical asset linkage defined by the existing formal-document authority. It must not silently invent an electronic-signature mechanism and must not unfreeze PKK provider runtime.


## Gate closure evidence

FORMAL-DOC-007 is closed on the exact machine tree `152abe41b6e6ed4106206e026975823a008271a8`.

Validation-only evidence:

- validation helper commit: `4a5771e82822de805c35d0a621c441a6bda354ab`,
- validation helper tree: `152abe41b6e6ed4106206e026975823a008271a8`,
- validation PR: **#63**, closed without merge,
- helper Implementation CI #306 / run `34739748797`: **5/5 PASS**,
- PostgreSQL suite: **219 tests / 2952 assertions**,
- deterministic restore drill: **PASS**,
- restore schema table count: **115 -> 115**.

Clean accepted implementation evidence:

- accepted implementation commit: `85cd8c8dffa989ccacaa2c5db3ec51fb7bfbf4a6`,
- accepted implementation tree: `152abe41b6e6ed4106206e026975823a008271a8`,
- accepted Implementation CI #307 / run `34739895639`: **5/5 PASS**,
- PostgreSQL suite: **219 tests / 2952 assertions**,
- deterministic restore drill: **PASS**,
- restore schema table count: **115 -> 115**,
- backend Pint + PHPStan: **PASS**,
- frontend lint + typecheck + build + audit: **PASS**,
- contracts and traceability: **PASS**,
- accepted push secret scan: **PASS**.

The Stage-4 baseline remains exactly **170 nodes / 112 implemented nodes / 112 implemented steps** with unchanged identities.

The Stage-5 formal-documents registry contains exactly **11 steps**: four expand, one preflight, one backfill, four validate and one final contract step. The final physical document-mode nullability is **NO / NO / YES** for `document_mode`, `document_mode_selected_at` and `document_mode_selected_by_user_id` respectively.

PKK provider runtime remains frozen until explicit unfreeze after authoritative PWPW guidance.
