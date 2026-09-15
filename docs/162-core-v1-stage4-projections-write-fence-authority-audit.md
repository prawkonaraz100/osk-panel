# CORE-V1-STAGE4-PROJECTIONS-WRITE-FENCE-001 — authority audit

Status: `PASS`

This gate closes the final four Stage-4 `write_fence` projection nodes without entering legacy backfill, reconciliation, validation, contract cleanup, or PKK provider activation.

## Exact scope

1. `MIG-PRJ-CALENDAR-RESOURCE-CLAIMS`
2. `MIG-PRJ-PURCHASE-HISTORY`
3. `MIG-PRJ-ORGANIZATION-ACTIVITY`
4. `MIG-PRJ-NOTIFICATIONS`

The canonical order is the immutable Stage-4 migration DAG order 1670 → 1700.

## Projection authority preserved

### Calendar Resource Claims

`calendar_resource_claims` remains a technical current conflict projection. The write fence verifies that each claim resolves to the exact same-tenant canonical schedule owner and exact resource/time tuple. It does not create or backfill claims in this phase.

### Purchase History

No second purchase-history ledger or mutable status authority is introduced. History remains derived from immutable `orders` / `order_items` snapshots plus canonical payment settlement and fulfillment facts. The projection fence enforces the final-state relationship without materializing a duplicate business source of truth.

### Organization Activity

New projection rows require the exact same-tenant organization `domain_event`, exact current immutable activity policy revision, exact copied event fields, safe snapshots, and immutable projection lifecycle.

### Notifications

New rows require an exact active recipient membership and exact same-tenant organization source event. Recipient/source/payload/audience snapshots are immutable; `read_at` is write-once and server-owned; broadcast materialization must resolve to the exact active membership snapshot.

## Machine evidence

- Stage-4 plan identity: `d2fd6bc999dc2a5ee024e9b91bf00f5a5dad29575554a0ba42eba67107c23f10`
- Stage-4 execution identity: `b0ea9422e89ef355be12c21a10580c202c8c4689f7fdf05908d59c94e81b6ae4`
- materialized nodes: **170 / 170**
- materialized steps: **209**
- preflight: **39 / 39**
- write-fence: **52 / 52**
- backfill: **0 / 7**
- package-proof head: `a3b0d8fa0a87eb7bc8e3e55faf0fa4b4d35e530a`
- package-proof CI #460 / run `34798318766`: **5 / 5 PASS**
- canonical materialization: `469fd8ce91121bd96dd183d980d24a704141b25a`
- exact closure head: `165afa5d07cede9cbe7ccd8d648438fd811255be`
- exact-head CI #462 / run `34799231801`: **5 / 5 PASS**
- PostgreSQL: **252 tests / 5000 assertions — PASS**
- deterministic restore: **121 -> 121 PASS**
- PKK provider runtime: **FROZEN_UNTIL_EXPLICIT_UNFREEZE**

The only correction between the first canonical materialization and the exact closure head is Pint formatting in `Stage4ProjectionGuardsTest`; executable projection semantics and the implementation registry are unchanged.

## Phase barrier

The global write-fence phase is complete. The next phase is `backfill`, but only the seven authoritative nodes may enter it. Backfill may populate only values proven by exact durable evidence. It may not guess by timestamp, name, amount, UUID order/similarity, same-tenant coincidence, nearest row, or current state.

Unproven or conflicting legacy rows remain unchanged and are deferred to the separate `reconcile` phase. Backfill may not disable the installed write fences and may not create missing business effects, purchase grants, training credit, synthetic domain events, synthetic historical recipients, or invented read state.
