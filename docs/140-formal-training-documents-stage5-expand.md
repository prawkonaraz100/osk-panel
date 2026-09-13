# 140. Formal training documents — Stage-5 expand slice

Status: `FORMAL-DOC-003 PASS`

## Scope

FORMAL-DOC-003 materializes only the **expand** phase of the isolated Stage-5 formal-documents extension.

It does not:

- rewrite the frozen Stage-4 170-node authority,
- change the Stage-4 plan or execution identity,
- perform legacy backfill,
- enforce final `NOT NULL` on course document mode,
- add the later same-tenant cross-table foreign keys,
- add template effective-interval exclusion,
- add append-only database enforcement,
- generate formal documents,
- implement any PKK provider runtime.

PKK remains frozen.

## Registered expand steps

The extension keeps plan identity:

`34cada121f4fd4963b1461308fb517c50ee647005ce9421f4d1af40c80d44b99`

FORMAL-DOC-003 changes only the extension execution identity by registering four exact migration files:

`87efc23f47c0eb334f145c278cc3081283c3ca4369ca79348ffc1fcca7ce82f6`

The registered expand order is:

1. `S5DOC-ALTER-COURSE-ENROLLMENTS-DOCUMENT-MODE`
2. `S5DOC-TBL-FORMAL-TRAINING-DOCUMENT-TEMPLATES`
3. `S5DOC-TBL-FORMAL-TRAINING-DOCUMENTS`
4. `S5DOC-TBL-FORMAL-TRAINING-DOCUMENT-EVENTS`

All files live below:

`database/migrations/stage5/formal-documents/expand`

and therefore remain invisible to default root `php artisan migrate`.

## Course document-mode expand

The existing `course_enrollments` table receives nullable fields:

- `document_mode varchar(16)`,
- `document_mode_selected_at timestamptz`,
- `document_mode_selected_by_user_id uuid`.

Legacy rows are deliberately not rewritten in expand.

To prevent new null drift while legacy rows are still pending preflight/backfill:

- `document_mode` receives the database default `paper`,
- `document_mode_selected_at` receives the PostgreSQL server timestamp default,
- a `NOT VALID` domain check allows legacy `NULL` but rejects any new value outside `paper|electronic`.

The later preflight/backfill/contract path remains responsible for existing rows and final non-null enforcement.

## New tables

### formal_training_document_templates

Expand creates platform-global template metadata with:

- document type,
- template version,
- renderer version,
- template content hash,
- effective interval,
- local domain/range checks,
- per-document-type template-version uniqueness.

The later validate phase remains responsible for the non-overlapping effective-interval guard and used-template immutability.

### formal_training_documents

Expand creates immutable-revision storage shape with:

- tenant and course identity,
- document type and revision,
- selected document mode snapshot,
- template and renderer snapshots,
- course and requirements revision snapshots,
- evidence bundle hash,
- canonical asset/content hash,
- optional approval identity/timestamp,
- generator/timestamps.

Expand already enforces local domain checks plus:

- positive revision/version snapshots,
- approval identity/timestamp pairing,
- revision uniqueness,
- deterministic evidence uniqueness.

The later validate phase remains responsible for exact same-tenant relations to course/assets and other cross-table integrity that depends on external candidate-key authority.

### formal_training_document_events

Expand creates the append-only evidence shape with the six authorized event types:

- `generated`,
- `approved`,
- `printed`,
- `signed_scan_attached`,
- `electronic_presented`,
- `regeneration_detected`.

There is no generic JSON payload column, so this table does not create a new raw-PII payload channel.

The later validate phase will enforce exact tenant/document/asset relations and database-level append-only behavior.

## Execution and test harness

The existing isolated executor remains:

`migration:stage5:formal-docs:controlled`

It still shares PostgreSQL advisory lock `(519662, 5001)` with Stage-4 execution.

The test harness now:

1. validates and materializes the Stage-4 expand baseline,
2. validates the Stage-5 formal-documents registry,
3. executes the four Stage-5 expand migrations,
4. resets the three new formal-document tables between feature tests.

Permanent Implementation CI now explicitly validates both migration registries before running the PostgreSQL suite.

## FORMAL-DOC-003 PASS criteria

PASS requires one exact tree proving:

- Stage-4 remains **170 / 112 / 112** with unchanged identities,
- Stage-5 plan identity remains `34cada12...`,
- Stage-5 execution identity becomes exactly `87efc23f...`,
- exactly four Stage-5 nodes/steps are materialized and all are `expand`,
- all registered migration SHA-256 hashes validate,
- the controlled expand executor succeeds on PostgreSQL,
- a repeated expand invocation is idempotent,
- new course rows default to `paper` and receive a server selection timestamp,
- invalid document-mode writes are rejected while legacy nulls remain temporarily valid,
- formal template/document/event tables materialize with local checks,
- preflight remains fail-closed because no preflight migration is registered,
- default Laravel migration discovery still cannot see the Stage-5 tree,
- backend/static analysis, frontend, contracts/traceability, secret scan and restore drill remain green.

## Next gate after PASS

**FORMAL-DOC-004 — legacy document-mode preflight.**

That gate will inspect the existing `course_enrollments` population before any backfill. It must fail closed on states that cannot be deterministically converted to the Gate-1 `paper` default and must not yet perform the backfill itself.


## Gate closure evidence

FORMAL-DOC-003 is closed on the exact machine tree `2946856d278ac2f2cedb5c6f46d4ad39bfc2678d`.

Validation-only evidence:

- helper head: `835a6da4165f8f292acbd99531add1bfb846ff65`,
- validation PR: #55, closed without merge,
- helper Implementation CI #287 / run `34734143995`: **5/5 PASS**,
- PostgreSQL suite: **195 tests / 2808 assertions**,
- deterministic restore drill: **PASS**,
- restore schema table count: **115 -> 115**.

Clean accepted authority evidence:

- accepted implementation commit: `bd1d8b0a1ce755ad71992eb50d943df0e85f8cfa`,
- accepted implementation tree: `2946856d278ac2f2cedb5c6f46d4ad39bfc2678d`,
- accepted Implementation CI #288 / run `34734264265`: **5/5 PASS**,
- PostgreSQL suite: **195 tests / 2808 assertions**,
- deterministic restore drill: **PASS**,
- restore schema table count: **115 -> 115**,
- backend Pint + PHPStan: **PASS**,
- frontend lint + typecheck + build + audit: **PASS**,
- contracts and traceability: **PASS**,
- accepted push secret scan: **PASS**.

The Stage-4 baseline remains exactly **170 nodes / 112 implemented nodes / 112 implemented steps** with unchanged identities. The Stage-5 formal-documents extension remains a separate authority with exactly four materialized expand steps.

No preflight, backfill, final contract or PKK provider runtime is claimed by FORMAL-DOC-003.
