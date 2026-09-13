# CORE-V1-STAGE4-INDEXES-PREFLIGHT-001

Status: `PASS`

## Scope

Materialize exactly the ten authoritative Stage-4 index preflight nodes in canonical order: `MIG-IDX-IDENTITY`, `MIG-IDX-RESOURCES`, `MIG-IDX-TRAINING`, `MIG-IDX-CALENDAR_GIST`, `MIG-IDX-PKK`, `MIG-IDX-FINANCE`, `MIG-IDX-LICENSES`, `MIG-IDX-EXAMS`, `MIG-IDX-COMMERCE`, `MIG-IDX-EVENTS`.

This slice is read-only preflight. It detects duplicate or overlap conditions that would block later index/exclusion write-fence work. It must not create any index, UNIQUE constraint, exclusion constraint, trigger, compatibility column, or other target-schema effect.

## Authority boundary

- Stage-4 DAG: **170 nodes**.
- Plan identity: `d2fd6bc999dc2a5ee024e9b91bf00f5a5dad29575554a0ba42eba67107c23f10` (unchanged).
- Helper executable prefix: **136 nodes / 136 steps**.
- Preflight prefix: **18 nodes** = 8 candidate-key + 10 index preflights.
- Global preflight authority: **39 nodes**; **21 remain** before write-fence.
- `write_fence`: **0 materialized steps**.
- Helper execution identity: `bf71200c44672f2942071dd15f5c89d3a9a6991563dc3edcdb7bab6cb965af94`.
- PKK provider runtime: **FROZEN_UNTIL_EXPLICIT_UNFREEZE**.

## Compatibility decisions

The frozen matrix label `student_payment_idempotency_unique` does not re-authorize nullable `student_payments.idempotency_key`. Canonical finance idempotency remains `infrastructure_idempotency_records`. If a legacy deployment still has the old column, the preflight only scans its non-null tenant-scoped values for duplicates.

Commerce source-order-item ordinals are scanned where the canonical physical column already exists. The preflight does not invent the currently absent `internal_exam_inventory_entries.source_order_item_grant_ordinal`; that compatibility gap belongs to the later reviewed write-fence/backfill sequence.

## Pass requirements

Exact ten-node canonical suffix; exact SHA-256 registry; PostgreSQL duplicate/overlap scans PASS; `btree_gist` present; target schema signature unchanged; write-fence empty; helper CI 5/5; helper PR closed without merge; exact-tree clean promotion; accepted-push CI 5/5.


## Closure evidence

`CORE-V1-STAGE4-INDEXES-PREFLIGHT-001` is closed **PASS**.

Validation-only helper:

- PR: **#82**, closed without merge.
- helper head: `012682ec45f138d3ba888f1a63ad69b8b7a14f0b`.
- validated helper tree: `e1da0d8b69eeab5a72d66fd4622382dd0c9e97c2`.
- helper Implementation CI: run `34772768005` / #390 — **5/5 PASS**.
- PostgreSQL: **234 tests / 3205 assertions**.
- deterministic restore: **121 -> 121**, `RESTORE_DRILL_HARNESS=PASS`.

Clean accepted implementation:

- accepted commit: `2ee609e1787f33457c0369db26ce9c6f4ae2414d`.
- accepted tree: `e1da0d8b69eeab5a72d66fd4622382dd0c9e97c2`.
- exact helper/accepted tree match: **PASS**.
- accepted Implementation CI: run `34772982881` / #391 — **5/5 PASS**.
- PostgreSQL: **234 tests / 3205 assertions**.
- deterministic restore: **121 -> 121**, `RESTORE_DRILL_HARNESS=PASS`.
- backend static analysis: **PASS_ZERO_ERRORS**.
- frontend quality: **PASS**.
- contracts and traceability: **PASS**.
- secret scan: **PASS**.

Migration authority after PASS:

- Stage-4 DAG: **170 nodes**.
- Stage-4 materialized nodes: **136**.
- Stage-4 materialized steps: **136**.
- preflight nodes materialized: **18 / 39**.
- remaining preflight nodes before write-fence: **21**.
- write-fence steps materialized: **0**.
- plan identity remains `d2fd6bc999dc2a5ee024e9b91bf00f5a5dad29575554a0ba42eba67107c23f10`.
- execution identity is `bf71200c44672f2942071dd15f5c89d3a9a6991563dc3edcdb7bab6cb965af94`.
- Stage-5 formal-document authority remains **11 steps**.
- API inventory remains **187 / 173 / 14**.
- FORMAL-DOC-011 remains **PASS**.
- PKK provider runtime remains **FROZEN_UNTIL_EXPLICIT_UNFREEZE**.

Next gate: **CORE-V1-STAGE4-FOREIGN-KEYS-PREFLIGHT-001**, covering exactly the 11 authoritative `MIG-FK-*` preflight nodes in order 1370-1470. Global write-fence remains closed.
