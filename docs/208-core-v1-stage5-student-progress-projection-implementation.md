# 208. CORE-V1-STAGE5-STUDENT-PROGRESS-PROJECTION-001

Data: 2026-09-15

**Status:** `CANDIDATE`

## Scope

Materialize the isolated Stage-5 schema corrective approved by:

`CORE-V1-STUDENT-PROGRESS-AUTHORITY-001 = PASS`

Exactly two tables are introduced:

- `learning_progress_source_bindings`
- `student_learning_progress_projections`

No `students.progress` HTTP binding and no progress UI enablement is part of this gate.

## Immutable identities

Plan:

`d844b3e5c74844e6e9a357aa3271ff9b68bac93dc7fdcd6fa28d3ab44b9c978a`

Execution:

`53df27f3f7e54939067c73bea202162e0e07244321f3a6c98d2e9e24592730de`

Authority blob:

`9f9b000fcbee2086c18da3ab0ac21e966c3a4c90`

Root:

`database/migrations/stage5/student-progress`

## Materialized nodes

1. `S5PROG-TBL-LEARNING-PROGRESS-SOURCE-BINDINGS`
2. `S5PROG-TBL-STUDENT-LEARNING-PROGRESS-PROJECTIONS`

Each node executes only:

`preflight -> expand -> validate`

There is deliberately no heuristic backfill.

## Binding invariants

The source binding is tied to the exact:

`organization + Student + StudentLearningAccount`

It uses opaque source subject/access references and never derives identity from e-mail, login, PESEL or name.

Database constraints enforce:

- one binding per local learning account,
- global source access-context uniqueness inside a source system,
- exact tenant/student/account foreign key,
- active/revoked status only,
- positive version,
- nonblank source references.

## Projection invariants

The current projection key is:

`organization_id + student_learning_account_id + driving_category_id`

The projection additionally carries the exact source binding and exact Student identity. Composite foreign keys prevent a projection from reusing a binding belonging to another tenant/account/student.

Snapshot hash is lowercase SHA-256 and versions are positive.

## Preservation

- frozen Stage4 identities unchanged,
- formal-documents Stage5 identities unchanged,
- social-identity Stage5 identities unchanged,
- commerce allocator Stage5 identities unchanged,
- default Laravel root migration discovery remains disabled,
- shared PostgreSQL advisory lock remains `[519662, 5001]`,
- automatic destructive down is forbidden.

## Explicit non-scope

- no HTTP route,
- no UI enablement,
- no learning-provider implementation,
- no identifier guessing,
- no fake zero progress,
- no PKK/PWPW runtime,
- no payment-provider webhook.

## Safe continuation

After exact-head PASS, run a fresh Core V1 closure audit.

Only that audit may reclassify `students.progress` from schema-corrective blocked to implementation-ready.
