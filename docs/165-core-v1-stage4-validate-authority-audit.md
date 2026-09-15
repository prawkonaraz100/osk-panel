# CORE-V1-STAGE4-VALIDATE-001 — authority audit

Status: `PASS`

This gate closes the complete 34-node Stage-4 `validate` phase after exact-evidence backfill and reviewed reconciliation.

## Exact scope

- 11 foreign-key validation nodes
- 10 CHECK-constraint validation nodes
- 9 trigger validation nodes
- 4 projection validation nodes

Validation does not introduce new business authority. Foreign keys and CHECK constraints are validated from their already-installed signed `NOT VALID` write fences. Trigger and projection validation asserts the exact installed signed definitions and required final-state postconditions.

## Machine evidence

- Stage-4 plan identity: `d2fd6bc999dc2a5ee024e9b91bf00f5a5dad29575554a0ba42eba67107c23f10`
- Stage-4 execution identity: `23e259f945fc619556180b7e047c36cdfbf098157b3774d1f647729169de1aa4`
- materialized nodes: **170 / 170**
- materialized steps: **257**
- validate: **34 / 34**
- contract: **0 / 4**
- corrective exact head: `1d286430469b971266d91b82f2e998e99d54bc82`
- exact-head CI #478 / run `34806801979`: **5 / 5 PASS**
- PostgreSQL: **258 tests / 5079 assertions — PASS**
- deterministic restore: **121 -> 121 PASS**
- PKK provider runtime: **FROZEN_UNTIL_EXPLICIT_UNFREEZE**

## Validated cutover postconditions

- every Stage-4 FK/CHECK selected for validation is present with its signed authority definition and `convalidated=true`;
- all trigger/projection guard definitions still exactly match the write-fence authority;
- purchase lineage, event lineage, calendar claims, purchase history, activity and notification postconditions remain resolved;
- a newly opened reconciliation case after reconcile blocks validate instead of being ignored;
- no write fence is disabled and no business history is rewritten to make validation pass.

## Next phase barrier

The only remaining Stage-4 migration phase is `contract`, containing exactly four projection nodes.

Contract is a separate reviewed release boundary. It may remove or deauthorize only an explicitly superseded projection compatibility path after:
1. validate PASS;
2. zero required unresolved P0/P1 cases;
3. a recorded fallback/previous-application compatibility decision;
4. stopped or deauthorized superseded writers;
5. successful isolated restore evidence; and
6. authorized acceptance of the destructive/deauthorization scope.

If a node has no superseded physical compatibility path, its contract step may be an explicit no-op assertion. Contract may not delete canonical source events, formal financial/audit history, or any business history to manufacture a clean cutover.
