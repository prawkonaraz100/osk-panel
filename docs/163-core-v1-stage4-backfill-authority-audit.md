# CORE-V1-STAGE4-BACKFILL-001 — authority audit

Status: `PASS`

This gate closes the complete seven-node Stage-4 `backfill` phase without entering reconciliation, validation, contract cleanup, or PKK provider activation.

## Exact scope

1. `MIG-FK-PURCHASE_DOWNSTREAM`
2. `MIG-FK-EVENTS`
3. `MIG-TRG-EVENTS`
4. `MIG-PRJ-CALENDAR-RESOURCE-CLAIMS`
5. `MIG-PRJ-PURCHASE-HISTORY`
6. `MIG-PRJ-ORGANIZATION-ACTIVITY`
7. `MIG-PRJ-NOTIFICATIONS`

## Backfill policy

Automatic mutation is restricted to exact durable evidence that remains legal under the already-active write fences.

- Calendar claims are inserted only for exact canonical schedule-owner/resource/time tuples and only when the owner is conflict-free and has no mismatched legacy claim.
- Purchase-history `booked_at` is populated only from exact settlement/fulfillment authority with a confirmed full-order payment, or from exact zero-total settlement authority.
- Immutable purchase lineage and immutable event/activity/notification history are not rewritten by automatic backfill.
- Rows that need reviewed historical remediation remain unchanged and are explicitly deferred to `reconcile`.
- Timestamp proximity, names, amounts alone, UUID order/similarity, same-tenant coincidence, nearest-row matching, or current-state inference are forbidden evidence.

The backfill runs with the Stage-4 write fences active; no trigger or constraint is disabled to make legacy data pass.

## Machine evidence

- Stage-4 plan identity: `d2fd6bc999dc2a5ee024e9b91bf00f5a5dad29575554a0ba42eba67107c23f10`
- Stage-4 execution identity: `1526b852d3af464c8f6138ba13e0aa0fe9b9a64d77a9df7619a2709e512f1bde`
- materialized nodes: **170 / 170**
- materialized steps: **216**
- preflight: **39 / 39**
- write-fence: **52 / 52**
- backfill: **7 / 7**
- reconcile: **0 / 7**
- hardened package head: `c7e780771e30f65fe8637de4eced10f3a4680bbe`
- hardened package CI #466 / run `34801447459`: **5 / 5 PASS**
- canonical backfill head: `bc66fc14d693d74022f8d89a81545066e935cced`
- canonical CI #467 / run `34801804245`: **5 / 5 PASS**
- PostgreSQL: **253 tests / 5028 assertions — PASS**
- deterministic restore: **121 -> 121 PASS**
- restore table count: **121 -> 121**
- PKK provider runtime: **FROZEN_UNTIL_EXPLICIT_UNFREEZE**

## Next phase barrier

The next phase is `reconcile` and contains the same seven nodes. It is not an automatic heuristic cleanup phase. It may apply only reviewed, explicit remediation backed by durable evidence. Any ambiguity remains blocking and may not be converted into a guessed relation, grant, payment state, event source, historical recipient, actor, or read state.

No `validate` or `contract` step may be registered until the reconcile gate is closed.
