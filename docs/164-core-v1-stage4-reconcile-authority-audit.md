# CORE-V1-STAGE4-RECONCILE-001 — authority audit

Status: `PASS`

This gate closes the complete seven-node Stage-4 `reconcile` phase. The phase is deliberately fail-closed and read-only: it does not guess, repair, reparent, synthesize, merge, or rewrite legacy business history.

## Exact scope

1. `MIG-FK-PURCHASE_DOWNSTREAM`
2. `MIG-FK-EVENTS`
3. `MIG-TRG-EVENTS`
4. `MIG-PRJ-CALENDAR-RESOURCE-CLAIMS`
5. `MIG-PRJ-PURCHASE-HISTORY`
6. `MIG-PRJ-ORGANIZATION-ACTIVITY`
7. `MIG-PRJ-NOTIFICATIONS`

## Reconciliation semantics

- unresolved purchase grant lineage remains blocking;
- event/outbox/activity/notification lineage must resolve to exact durable authority;
- event projection migration cases must be durably reviewed and resolved;
- calendar resource claims must exactly equal their canonical schedule-owner/resource/time tuples;
- purchase history must equal exact settlement or zero-total authority and fulfillment state;
- activity and notification projections must retain exact source, tenant, policy and recipient semantics;
- any ambiguity stops the phase instead of being converted into inferred history.

The seven reconcile migrations only call read-only postcondition assertions. They do not mutate domain rows or reconciliation evidence.

## Machine evidence

- Stage-4 plan identity: `d2fd6bc999dc2a5ee024e9b91bf00f5a5dad29575554a0ba42eba67107c23f10`
- Stage-4 execution identity: `2b8d8001da433ec87402155ef9c3e0149d36018eea9b5a03a63b7a97a54c0f76`
- materialized nodes: **170 / 170**
- materialized steps: **223**
- reconcile: **7 / 7**
- validate: **0 / 34**
- package-proof head: `a9498302da8386dea0877bf58cbce2dd44b9582d`
- package-proof CI #473 / run `34804048295`: **5 / 5 PASS**
- canonical reconcile head: `916c69825e14345f43dbd0f053bdfccd9a141078`
- canonical CI #474 / run `34804728243`: **5 / 5 PASS**
- PostgreSQL: **256 tests / 5057 assertions — PASS**
- deterministic restore: **121 -> 121 PASS**
- PKK provider runtime: **FROZEN_UNTIL_EXPLICIT_UNFREEZE**

## Next phase barrier

The next phase is `validate` with exactly 34 nodes: 11 foreign-key nodes, 10 CHECK-constraint nodes, 9 trigger nodes and 4 projection nodes.

Validation may validate existing `NOT VALID` foreign keys and CHECK constraints and may assert exact installed trigger/projection definitions and final-state postconditions. It may not disable a write fence, rewrite history, invent lineage, silently ignore a newly discovered unresolved case, or enter the four-node contract phase.
