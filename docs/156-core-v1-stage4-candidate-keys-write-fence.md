# CORE-V1-STAGE4-CANDIDATE-KEYS-WRITE-FENCE-001

Status: `PASS`

## Scope

Materialize exactly the eight authoritative Stage-4 candidate-key nodes in their `write_fence` phase, orders **1190–1260**:

1. `MIG-CK-IDENTITY`
2. `MIG-CK-ASSETS_RESOURCES`
3. `MIG-CK-TRAINING`
4. `MIG-CK-FINANCE`
5. `MIG-CK-LICENSES`
6. `MIG-CK-EXAMS`
7. `MIG-CK-COMMERCE`
8. `MIG-CK-EVENTS`

The global read-only preflight barrier is already **39/39 PASS**, and the Commerce lineage prerequisite recovery is PASS. This gate is the first Stage-4 schema-mutating write-fence tranche.

## Exact write effect

The gate installs exactly **28 PostgreSQL UNIQUE constraints** using the names and ordered columns produced by the frozen migration DAG.

No index node, FK node, CHECK/exclusion constraint node, trigger, projection, compatibility column, backfill, reconciliation, validation or contract phase may be pulled into this tranche.

The installer is fail-closed:

- target table and columns must already exist,
- a pre-existing named constraint is accepted only when table, type and ordered columns match exactly,
- a conflicting same-name definition aborts,
- PostgreSQL itself rechecks uniqueness at installation time, so conflicts introduced after the earlier preflight cannot pass,
- automatic destructive `down` remains forbidden.

## Candidate state

- Stage-4 DAG: **170 nodes**.
- Materialized nodes before/after: **157 / 157**.
- Materialized steps: **157 -> 165**.
- Preflight: **39/39 PASS**.
- Write-fence steps: **0 -> 8**.
- Remaining authoritative write-fence steps after PASS: **44**.
- Plan identity remains `d2fd6bc999dc2a5ee024e9b91bf00f5a5dad29575554a0ba42eba67107c23f10`.
- Previous execution identity: `7cdea7410b15a1e1ef884e50d4e2a9ac519eb568569d140f82b19cc5deaa142e`.
- Candidate execution identity: `84108f592c5cdff50cea4316ce05c5ad53db61e177b69c8916e04eed54214429`.
- PKK provider runtime remains **FROZEN_UNTIL_EXPLICIT_UNFREEZE**.

## Runtime proof

The feature test executes the full controlled `preflight` phase before `write_fence`, exactly as `MigrationPlan::assertPhaseEntry` requires. The schema mutation and migration repository rows are wrapped in an outer PostgreSQL test transaction and rolled back afterward; migration evidence uses an isolated temporary journal.

PASS requires all 28 exact candidate keys, zero unrelated FK/CHECK/exclusion/trigger mutation, zero domain-row mutation, exact registry hashes, full PostgreSQL suite and deterministic restore.


## Closure evidence

`CORE-V1-STAGE4-CANDIDATE-KEYS-WRITE-FENCE-001` is closed **PASS**.

Validation-only helper:

- PR **#87** closed without merge,
- helper commit: `b4817162d59cfe8e8afb8b1def448c70f836e2a6`,
- helper tree: `5f265cceed931c61d1ba0afdee5fde5ad3332647`,
- helper Implementation CI #413 / run `34779848058`: **5/5 PASS**,
- PostgreSQL: **238 tests / 3367 assertions**,
- deterministic restore: **121 -> 121**, `RESTORE_DRILL_HARNESS=PASS`.

Clean accepted implementation:

- accepted commit: `a08b6f2792131a787ea62a16c4e807062fb5b6b1`,
- accepted tree: `5f265cceed931c61d1ba0afdee5fde5ad3332647`,
- helper/accepted tree match: **PASS**,
- accepted Implementation CI #414 / run `34780065273`: **5/5 PASS**,
- PostgreSQL: **238 tests / 3367 assertions**,
- deterministic restore: **121 -> 121**, `RESTORE_DRILL_HARNESS=PASS`.

Final state:

- Stage-4 DAG: **170 nodes**,
- materialized nodes: **157**,
- materialized steps: **165**,
- global preflight: **39/39 PASS**,
- write-fence steps: **8 / 52**,
- exact candidate-key UNIQUE constraints: **28 PASS**,
- plan identity unchanged,
- execution identity: `84108f592c5cdff50cea4316ce05c5ad53db61e177b69c8916e04eed54214429`,
- PKK provider runtime remains **FROZEN_UNTIL_EXPLICIT_UNFREEZE**.

Next gate: **CORE-V1-STAGE4-INDEXES-WRITE-FENCE-001** — exactly ten `MIG-IDX-*` write-fence steps, orders 1270–1360.
