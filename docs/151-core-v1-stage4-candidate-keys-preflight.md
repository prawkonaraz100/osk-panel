# CORE-V1-STAGE4-CANDIDATE-KEYS-001 — candidate-key preflight prefix

Status: `PASS`

## Purpose

This corrective slice materializes the first safe Stage-4 **preflight prefix** after the 118-node expand tranche.

Exact nodes:

1. `MIG-CK-IDENTITY`
2. `MIG-CK-ASSETS_RESOURCES`
3. `MIG-CK-TRAINING`
4. `MIG-CK-FINANCE`
5. `MIG-CK-LICENSES`
6. `MIG-CK-EXAMS`
7. `MIG-CK-COMMERCE`
8. `MIG-CK-EVENTS`

The immutable Stage-4 DAG remains **170 nodes** and the authority blob remains
`ad5f2aa2e14ef248b95dd3dda0d1cbcb2e69d441`.

## Proven orchestration defect in the previously proposed corrective sequence

The accepted central gate previously described this slice as materializing both:

`preflight → write_fence`

for the eight candidate-key nodes.

That sequence is not executable under the canonical Stage-4 migration runner.

`MigrationPlan::assertPhaseEntry()` enforces a **global phase barrier**. The immutable plan contains:

- 118 nodes in `expand`,
- **39 nodes in `preflight`**:
  - 8 candidate-key nodes,
  - 10 index nodes,
  - 11 foreign-key nodes,
  - 10 constraint nodes,
- **52 nodes in `write_fence`**:
  - the 39 above,
  - 9 trigger nodes,
  - 4 projection nodes.

Therefore no Stage-4 `write_fence` invocation may execute until all 39 authoritative
`preflight` nodes are materialized and the complete preflight phase is applied.

Bypassing that barrier, registering only eight write-fence steps and calling the candidate
keys physically installed would contradict the executable authority.

The canonical plan itself is **not changed**. Only the corrective implementation sequence is
narrowed to the executable safe prefix.

## Exact result of this slice

Before:

- materialized nodes: **118**
- materialized steps: **118**
- execution identity:
  `bba8fb733d634e180b2133057dd73dcabae116d344496027b2f1ecaf0f48de6c`

Candidate after this slice:

- materialized nodes: **126**
- materialized steps: **126**
- registered preflight steps: **8**
- registered write-fence steps: **0**
- execution identity:
  `ea9cbf83c22be708f22676be11aef4bf18d3c22f1520e13a22cb52743a37b882`

“Materialized node” here means the node now has its first authoritative executable phase
registered. It does **not** mean the candidate-key UNIQUE constraint has already been applied.

## Preflight behavior

Every candidate-key preflight:

- runs only through `migration:controlled`,
- requires PostgreSQL,
- verifies every required source table,
- verifies every required key column that already exists,
- performs a duplicate scan matching PostgreSQL ordinary `UNIQUE` NULL semantics,
- never moves, rewrites, reparents or deletes business data,
- never creates a UNIQUE constraint,
- never creates a secondary index, FK, check, exclusion, trigger or projection,
- has no destructive automatic down.

Duplicate detection only compares rows whose complete key tuple is non-null, matching ordinary
PostgreSQL `UNIQUE` behavior where null values are distinct.

## Candidate-key matrix validated by this preflight

The future write-fence authority targets 28 candidate keys:

### Identity — 4

- `organization_memberships(id, user_id)`
- `organization_memberships(organization_id, id)`
- `organization_memberships(organization_id, id, user_id)`
- `auth_login_identifiers(id, user_id)`

### Assets/resources — 4

- `file_assets(organization_id, id)`
- `staff_profiles(organization_id, id)`
- `locations(organization_id, id)`
- `vehicles(organization_id, id)`

### Training — 6

- `students(organization_id, id)`
- `student_learning_accounts(organization_id, id)`
- `student_learning_accounts(organization_id, id, student_id)`
- `course_enrollments(organization_id, id)`
- `course_enrollments(organization_id, id, student_id)`
- `training_sessions(organization_id, id)`

### Student finance — 1

- `student_charges(organization_id, id, student_id, currency)`

### Licenses — 2

- `license_inventory_entries(organization_id, id)`
- `license_assignments(organization_id, id)`

### Internal exams — 4

- `internal_exam_inventory_entries(organization_id, id)`
- `internal_exam_attempts(organization_id, id)`
- `internal_exam_accesses(organization_id, id)`
- `exam_stations(organization_id, id)`

### Commerce — 5

- `orders(organization_id, id)`
- `order_items(organization_id, id)`
- `order_items(organization_id, id, product_kind)`
- `order_items(organization_id, id, license_product_id)`
- `order_items(organization_id, id, commerce_catalog_item_id)`

### Events — 2

- `audit_logs(organization_id, id)`
- `domain_events(organization_id, id)`

## Commerce prerequisite found by preflight design

The canonical DB-COM authority requires
`order_items(organization_id, id, license_product_id)`, but the currently accepted expand
table does not yet contain `order_items.license_product_id`.

The preflight explicitly recognizes this one known compatibility prerequisite and does **not**
invent or infer historical values.

This slice does **not** add the column, because preflight is read-only.

When the global write-fence phase becomes executable, the Commerce write-fence may add
`license_product_id uuid NULL` compatibly before materializing its candidate key. Historical
license-product backfill and product-kind final-state enforcement remain owned by the later
Commerce backfill/constraint sequence. Guessing legacy product identity is forbidden.

## Executable proof

`Stage4CandidateKeysPreflightTest` runs the real:

`migration:controlled --phase=preflight`

with exact plan and execution identities after the full 118-node expand phase is applied.

The test requires:

- all 8 preflight migrations recorded as applied,
- no candidate-key UNIQUE constraints created,
- no `order_items.license_product_id` schema mutation during preflight,
- write-fence remains unmaterialized,
- migration plan validation remains PASS.

## Remaining Stage-4 sequence after this PASS

The registry will contain 126 of 170 nodes. Remaining unmaterialized nodes: **44**:

- indexes: 10
- foreign keys: 11
- constraints: 10
- triggers: 9
- projections: 4

However the next executable goal is **not write-fence**. The remaining **31 preflight nodes**
must first be materialized:

- 10 index preflights,
- 11 foreign-key preflights,
- 10 constraint preflights.

Only after the full 39-node preflight phase passes may the 52-node write-fence phase begin.

The immediate next corrective slice is:

`CORE-V1-STAGE4-INDEXES-PREFLIGHT-001`

covering exactly the 10 `MIG-IDX-*` nodes in authoritative topological order.

## Preservation

This slice does not change:

- Stage-4 plan identity or authority blob,
- Stage-5 formal-document 11-step migration authority,
- canonical API inventory (**187 / 173 / 14**),
- FORMAL-DOC-011 PASS,
- provider-neutral groundwork,
- PKK provider runtime freeze.

PKK remains:

`FROZEN_UNTIL_EXPLICIT_UNFREEZE`.


## Closure evidence

`CORE-V1-STAGE4-CANDIDATE-KEYS-001` is closed **PASS** on the exact validated implementation tree.

Validation-only helper:

- validation PR: **#80**, closed without merge,
- helper commit: `87161711afc10288da971fffdc03209a2fb5f038`,
- helper tree: `56f6ec80671aff4ee7d52f7d09c82249a9a5716d`,
- helper Implementation CI: run `34765461033` / #386 — **5/5 PASS**,
- PostgreSQL: **233 tests / 3190 assertions**,
- deterministic restore: **121 → 121**, `RESTORE_DRILL_HARNESS=PASS`.

Clean accepted implementation:

- accepted commit: `db981e7679ebee63532238c6cfbcc143fbc6699f`,
- accepted tree: `56f6ec80671aff4ee7d52f7d09c82249a9a5716d`,
- exact helper/accepted tree match: **PASS**,
- accepted Implementation CI: run `34770650185` / #387 — **5/5 PASS**,
- PostgreSQL: **233 tests / 3190 assertions**,
- deterministic restore: **121 → 121**, `RESTORE_DRILL_HARNESS=PASS`,
- backend static analysis: **PASS_ZERO_ERRORS**,
- frontend quality: **PASS**,
- contracts and traceability: **PASS**,
- secret scan: **PASS**.

Migration authority after PASS:

- Stage-4 DAG: **170 nodes**,
- Stage-4 materialized nodes: **126**,
- Stage-4 materialized steps: **126**,
- Stage-4 plan identity remains `d2fd6bc999dc2a5ee024e9b91bf00f5a5dad29575554a0ba42eba67107c23f10`,
- Stage-4 execution identity is `ea9cbf83c22be708f22676be11aef4bf18d3c22f1520e13a22cb52743a37b882`,
- all **8** candidate-key preflight nodes are materialized,
- candidate-key UNIQUE effects remain intentionally unmaterialized,
- global write-fence remains closed,
- remaining preflight nodes before write-fence: **31**,
- Stage-5 formal-document authority remains **11 steps**,
- API inventory remains **187 / 173 / 14**,
- FORMAL-DOC-011 remains **PASS**,
- PKK provider runtime remains **FROZEN_UNTIL_EXPLICIT_UNFREEZE**.

Next gate: **CORE-V1-STAGE4-INDEXES-PREFLIGHT-001**, covering exactly the 10 authoritative `MIG-IDX-*` preflight nodes. No write-fence node may execute in that slice.
