# CORE-V1-STAGE4-FOREIGN-KEYS-PREFLIGHT-001

Status: `IN_VALIDATION`

## Scope

Materialize exactly the eleven authoritative Stage-4 foreign-key nodes in their `preflight` phase only:

1. `MIG-FK-IDENTITY`
2. `MIG-FK-RESOURCES`
3. `MIG-FK-TRAINING`
4. `MIG-FK-CALENDAR`
5. `MIG-FK-PKK`
6. `MIG-FK-FINANCE`
7. `MIG-FK-LICENSES`
8. `MIG-FK-EXAMS`
9. `MIG-FK-COMMERCE`
10. `MIG-FK-PURCHASE_DOWNSTREAM`
11. `MIG-FK-EVENTS`

This gate is read-only. It validates existing relation targets using the frozen Stage-4 contract and PostgreSQL `MATCH SIMPLE` null participation semantics. It detects orphan rows, cross-tenant references and exact-context mismatches before any FK write-fence effect is allowed.

## Authority boundary

- Stage-4 DAG: **170 nodes**.
- Materialized before this gate: **136 nodes / 136 steps**.
- Candidate state: **147 nodes / 147 steps**.
- Preflight prefix after candidate: **29 / 39 nodes**.
- Remaining preflight nodes before write-fence after PASS: **10**.
- Write-fence materialized steps: **0**.
- Plan identity remains `d2fd6bc999dc2a5ee024e9b91bf00f5a5dad29575554a0ba42eba67107c23f10`.
- Candidate execution identity: `488bedcef5d2aabef34e294f8fa0c2b48ef392e804cc83c6cd75c8ba5bfd8b1c`.
- PKK provider runtime remains **FROZEN_UNTIL_EXPLICIT_UNFREEZE**.

## Safety rules

The FK preflight scanner may only:

- require PostgreSQL,
- verify source and target table/column presence,
- test participating non-null source tuples against exact target tuples,
- fail closed when an orphan or tenant/context mismatch exists.

It may not:

- create any FK or UNIQUE constraint,
- create indexes or triggers,
- backfill or rewrite rows,
- auto-reparent cross-tenant data,
- fabricate missing historical identifiers,
- enter the global write-fence.

Known compatibility columns reserved for later write-fence/backfill are not invented here. In particular, this slice does not add the deferred Commerce license-product compatibility column or the internal-exam purchase grant ordinal.

## Next after PASS

Exactly ten `MIG-CON-*` preflight nodes remain (orders 1480-1570). Only after all **39/39** preflight nodes are materialized and validated may the global write-fence phase be considered.
