# CORE-V1-STAGE4-INDEXES-WRITE-FENCE-001

Status: `PASS`

## Purpose

This gate materializes the first ten index-class nodes of the Stage-4 `write_fence` phase after the global **39/39 preflight PASS**, candidate-key write-fence PASS and both commerce-lineage prerequisite recoveries.

Exact nodes, in frozen topological order:

1. `MIG-IDX-IDENTITY`
2. `MIG-IDX-RESOURCES`
3. `MIG-IDX-TRAINING`
4. `MIG-IDX-CALENDAR_GIST`
5. `MIG-IDX-PKK`
6. `MIG-IDX-FINANCE`
7. `MIG-IDX-LICENSES`
8. `MIG-IDX-EXAMS`
9. `MIG-IDX-COMMERCE`
10. `MIG-IDX-EVENTS`

The gate adds **10 executable steps** to already-materialized nodes, therefore the candidate state is:

- Stage-4 DAG: **170 nodes**,
- materialized nodes: **157**,
- materialized steps: **175**,
- preflight: **39/39**,
- write-fence: **18/52**.

## Physical effects

The tranche installs exactly:

- **52 active PostgreSQL UNIQUE btree indexes** defined by the accepted preflight contracts,
- **4 PostgreSQL GiST exclusion constraints** protecting overlapping Calendar claims for Student, Instructor, Vehicle and Location.

Every created object carries a deterministic definition signature used for reviewed resume. An existing object with the expected name but a conflicting table, columns, type/access method or signature fails closed.

No row is rewritten. No FK, CHECK, trigger, backfill, reconciliation, validation or contract step is pulled into this gate.

## Student Finance legacy idempotency

`student_payments.idempotency_key` is not canonical command-idempotency authority. The accepted DB-FIN/DB-COM contract assigns that role to `idempotency_records` and explicitly forbids promoting the nullable legacy Payment column as a parallel authority after cutover.

Therefore this gate **does not create** either `student_payment_idempotency_unique` or `student_payment_idempotency_unique_legacy_compatibility_only` on the fresh-build schema. The canonical Finance index created here is `course_cost_charge_origin_unique_per_course`.

## PKK freeze preservation

`MIG-IDX-PKK` installs schema-level integrity indexes already present in the frozen Stage-4 migration authority. It does **not** enable provider dispatch, provider integration, external PKK runtime or any provider-facing feature.

PKK provider runtime remains:

`FROZEN_UNTIL_EXPLICIT_UNFREEZE`

## Candidate identities

- plan identity: `d2fd6bc999dc2a5ee024e9b91bf00f5a5dad29575554a0ba42eba67107c23f10`
- previous execution identity: `d3d460992bfe56a0c3828689bffffaf842cc9b88bd103a5e71b5c6082ba00cd4`
- candidate execution identity: `411d0c2c1493fb2ea052e89b5c919f2b7e3ae0f746301107b0a7c08610486f71`

## PASS requirements

PASS requires:

- exact ten write-fence steps registered in frozen order,
- exact migration SHA-256 registry,
- all **52 UNIQUE indexes** present with exact table/ordered-column/signature metadata,
- all **4 GiST exclusion constraints** present with exact table/ordered-column/signature metadata,
- full earlier-phase barrier enforcement,
- no legacy Student Payment idempotency authority resurrection,
- no FK/CHECK/trigger or row mutation outside intended index/exclusion scope,
- full Implementation CI **5/5**,
- PostgreSQL suite PASS,
- deterministic restore PASS,
- helper tree clean-promoted unchanged to accepted branch.

After PASS, the next safe prefix is **CORE-V1-STAGE4-FOREIGN-KEYS-WRITE-FENCE-001**, exactly 11 `MIG-FK-*` write-fence steps.


## Closure evidence

`CORE-V1-STAGE4-INDEXES-WRITE-FENCE-001` is closed **PASS**.

Validation-only helper:

- PR **#89** closed without merge,
- helper commit: `153f43e16ea8beb54909a479ef455ae37851df31`,
- helper tree: `738f3c5e069f2bdb76a46cb48ae994c429b2fb88`,
- helper Implementation CI #420 / run `34783546637`: **5/5 PASS**,
- PostgreSQL: **240 tests / 3774 assertions**,
- deterministic restore: **121 -> 121**, `RESTORE_DRILL_HARNESS=PASS`.

Clean accepted implementation:

- accepted commit: `9890cf8a5fbd3c9d772b8a9d5d16eaeda299d7c3`,
- accepted tree: `738f3c5e069f2bdb76a46cb48ae994c429b2fb88`,
- helper/accepted tree match: **PASS**,
- accepted Implementation CI #421 / run `34783752688`: **5/5 PASS**,
- PostgreSQL: **240 tests / 3774 assertions**,
- deterministic restore: **121 -> 121**, `RESTORE_DRILL_HARNESS=PASS`.

Final state:

- Stage-4 DAG: **170 nodes**,
- materialized nodes: **157**,
- materialized steps: **175**,
- global preflight: **39/39 PASS**,
- write-fence steps: **18/52**,
- active UNIQUE btree indexes in this tranche: **52 PASS**,
- Calendar GiST exclusions in this tranche: **4 PASS**,
- legacy `student_payments.idempotency_key` authority resurrection: **0**,
- execution identity: `411d0c2c1493fb2ea052e89b5c919f2b7e3ae0f746301107b0a7c08610486f71`,
- PKK provider runtime remains **FROZEN_UNTIL_EXPLICIT_UNFREEZE**.

Next gate: **CORE-V1-STAGE4-FOREIGN-KEYS-WRITE-FENCE-001**, exactly eleven `MIG-FK-*` write-fence steps, orders 1370–1470.
