# CORE-V1-STAGE4-CANDIDATE-KEYS-WRITE-FENCE-001

Status: `IN_VALIDATION`

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
- Candidate execution identity: `d4ae7c11668cbdfd313d2026f1acea573814a490c5ba75f25dcf682c852348f2`.
- PKK provider runtime remains **FROZEN_UNTIL_EXPLICIT_UNFREEZE**.

## Runtime proof

The feature test executes the full controlled `preflight` phase before `write_fence`, exactly as `MigrationPlan::assertPhaseEntry` requires. The schema mutation and migration repository rows are wrapped in an outer PostgreSQL test transaction and rolled back afterward; migration evidence uses an isolated temporary journal.

PASS requires all 28 exact candidate keys, zero unrelated FK/CHECK/exclusion/trigger mutation, zero domain-row mutation, exact registry hashes, full PostgreSQL suite and deterministic restore.
