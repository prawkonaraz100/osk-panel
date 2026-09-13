# CORE-V1-STAGE4-FOREIGN-KEYS-PREFLIGHT-001

Status: `PASS`

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


## Closure evidence

`CORE-V1-STAGE4-FOREIGN-KEYS-PREFLIGHT-001` is closed **PASS**.

Validation-only helper:

- superseded PR **#83** was closed without merge after GitHub Actions concurrency stalled during cancellation,
- primary validation PR **#84** was closed without merge,
- validated helper commit: `a169fbe2e5b2e50fe6866d01b124c95b6e6c3705`,
- validated helper tree: `f403a0f0f2c6840c0342ab25e9a6fb745b141e89`,
- helper Implementation CI: run `34774368859` / #402 — **5/5 PASS**,
- PostgreSQL: **235 tests / 3216 assertions**,
- deterministic restore: **121 -> 121**, `RESTORE_DRILL_HARNESS=PASS`.

Clean accepted implementation:

- accepted commit: `962b28ca288a91b0f06d2cb6e46313c69fe87c3d`,
- accepted tree: `f403a0f0f2c6840c0342ab25e9a6fb745b141e89`,
- exact helper/accepted tree match: **PASS**,
- accepted Implementation CI: run `34774619790` / #403 — **5/5 PASS**,
- PostgreSQL: **235 tests / 3216 assertions**,
- deterministic restore: **121 -> 121**, `RESTORE_DRILL_HARNESS=PASS`,
- backend static analysis: **PASS_ZERO_ERRORS**,
- frontend quality: **PASS**,
- contracts and traceability: **PASS**,
- secret scan: **PASS**.

Migration authority after PASS:

- Stage-4 DAG: **170 nodes**,
- Stage-4 materialized nodes: **147**,
- Stage-4 materialized steps: **147**,
- preflight nodes materialized: **29 / 39**,
- remaining preflight nodes before write-fence: **10**,
- write-fence steps materialized: **0**,
- plan identity remains `d2fd6bc999dc2a5ee024e9b91bf00f5a5dad29575554a0ba42eba67107c23f10`,
- execution identity is `488bedcef5d2aabef34e294f8fa0c2b48ef392e804cc83c6cd75c8ba5bfd8b1c`,
- Stage-5 formal-document authority remains **11 steps**,
- API inventory remains **187 / 173 / 14**,
- FORMAL-DOC-011 remains **PASS**,
- PKK provider runtime remains **FROZEN_UNTIL_EXPLICIT_UNFREEZE**.

Next gate: **CORE-V1-STAGE4-CONSTRAINTS-PREFLIGHT-001**, covering exactly the ten authoritative `MIG-CON-*` preflight nodes in orders 1480-1570. Their PASS completes **39/39** preflight nodes; the global write-fence remains forbidden until then.
