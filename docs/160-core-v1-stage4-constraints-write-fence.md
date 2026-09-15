# CORE-V1-STAGE4-CONSTRAINTS-WRITE-FENCE-001

Status: `PASS`

This gate materializes only the ten frozen Stage-4 `MIG-CON-*` write-fence steps after the accepted FK write-fence PASS.

## Exact scope

- 10 write-fence migrations, orders **1480–1570**.
- 48 PostgreSQL CHECK constraints copied exactly from the accepted global preflight contracts.
- every CHECK is created **NOT VALID** so new writes are fenced immediately while historical validation remains reserved for the later `validate` phase.
- each CHECK carries a deterministic `prawkonaraz:constraint-write-fence:v1:<sha256>` signature.
- resume is fail-closed on table, constraint type, validation state and signature.
- each migration reruns its accepted `ConstraintPreflight` immediately before DDL.

No FK, index, user trigger, projection, backfill, reconciliation, validation or contract step belongs to this gate.

## Domain counts

Identity 4, Resources 4, Training 8, Calendar 6, PKK 4, Finance 2, Licenses 4, Exams 5, Commerce 6, Events 5. **Total: 48**.

## Candidate state

- Stage-4 DAG: **170 nodes**
- materialized nodes: **157**
- materialized steps: **196**
- global preflight: **39/39**
- write-fence: **39/52**
- remaining write-fence after this gate: **13**
- plan identity unchanged: `d2fd6bc999dc2a5ee024e9b91bf00f5a5dad29575554a0ba42eba67107c23f10`
- candidate execution identity: `905d440b34b7e88fca28890cdf2bb5d919eb5d98d891e07138cf716873659ef7`

PKK provider runtime remains `FROZEN_UNTIL_EXPLICIT_UNFREEZE`.

PASS requires exact DDL/signatures, zero target-row mutation, zero new indexes/user triggers, full PostgreSQL runtime, deterministic restore, helper CI 5/5 and clean accepted-tree promotion.

## Closure evidence

`CORE-V1-STAGE4-CONSTRAINTS-WRITE-FENCE-001` is closed **PASS**.

Validation-only helper:

- PR **#91** closed without merge,
- helper commit: `4fced773eceecf6d0164ec26d9e5a7177409c657`,
- helper tree: `238d76e1861298a55380998e55ab18e8a377f176`,
- helper Implementation CI #430 / run `34789231697`: **5/5 PASS**,
- PostgreSQL: **242 tests / 4948 assertions**,
- deterministic restore: **121 -> 121**, `RESTORE_DRILL_HARNESS=PASS`.

Clean accepted implementation:

- accepted commit: `93c38c1a4a6e384c9110ace8ef1deed8104c393c`,
- accepted tree: `238d76e1861298a55380998e55ab18e8a377f176`,
- helper/accepted tree match: **PASS**,
- accepted Implementation CI #431 / run `34789463583`: **5/5 PASS**,
- PostgreSQL: **242 tests / 4948 assertions**,
- deterministic restore: **121 -> 121**, `RESTORE_DRILL_HARNESS=PASS`.

Final state:

- Stage-4 DAG: **170 nodes**,
- materialized nodes: **157**,
- materialized steps: **196**,
- global preflight: **39/39 PASS**,
- write-fence steps: **39/52**,
- CHECK constraints added in this tranche: **48 PASS**, all initially **NOT VALID**,
- target-domain row mutation: **0**,
- new indexes: **0**,
- new user triggers: **0**,
- execution identity: `905d440b34b7e88fca28890cdf2bb5d919eb5d98d891e07138cf716873659ef7`,
- PKK provider runtime remains **FROZEN_UNTIL_EXPLICIT_UNFREEZE**.

Next gate: **CORE-V1-STAGE4-TRIGGERS-WRITE-FENCE-001**, exactly nine `MIG-TRG-*` write-fence steps. Projection nodes remain a separate later gate. Expected state after trigger PASS: **205 materialized steps / 48 of 52 write-fence steps / 4 projection write-fence steps remaining**.
