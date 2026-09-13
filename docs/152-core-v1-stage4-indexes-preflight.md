# CORE-V1-STAGE4-INDEXES-PREFLIGHT-001

Status: `IN_VALIDATION`

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
