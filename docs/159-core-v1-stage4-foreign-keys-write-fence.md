# CORE-V1-STAGE4-FOREIGN-KEYS-WRITE-FENCE-001

Status: `PASS`

## Purpose

Materialize exactly the eleven authoritative Stage-4 `MIG-FK-*` write-fence steps after the closed index gate:

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

The frozen Stage-4 DAG remains **170 nodes**. This gate advances the implementation registry from **175 to 186 steps** and the write-fence from **18/52 to 29/52** without adding or reordering a DAG node.

## Target-side uniqueness audit

Before FK DDL was written, every referenced column tuple from the eleven accepted FK preflight relation sets was compared against physical PostgreSQL primary keys, UNIQUE constraints and non-partial UNIQUE indexes.

That audit found **18 exact referenced-key tuples** required by the already accepted domain contracts but not yet physically materialized by the earlier CK/IDX prefixes. Examples include:

- `training_sessions(organization_id,id,course_enrollment_id)`,
- `training_hour_ledger_entries(organization_id,id,course_enrollment_id,training_part)`,
- exact PKK operation / attempt / handoff context keys,
- `license_product_language_capabilities(id,language_code)`,
- exact Internal Exam access/session context keys,
- payment/event/settlement exact-context keys,
- `service_entitlements(organization_id,id)`,
- `activity_projection_policy_revisions(event_type,policy_version)`.

These are not new business invariants invented by this gate. Their exact target tuples are already required by the accepted Training, PKK, Licenses, Exams, Commerce and Events contracts and are necessary for PostgreSQL to accept the corresponding composite foreign keys.

## Why the support keys live inside the FK write-fence

The frozen migration matrix contains no separate later candidate-key nodes for these tuples, and the earlier CK/IDX gates are already closed. Rewriting those historical gates would make already-applied databases unable to receive the prerequisite objects.

Therefore the eleven new FK migrations install only the missing **supporting referenced UNIQUE constraints** immediately before the FKs that require them. This preserves:

- the 170-node DAG,
- the already closed 8 CK and 10 index write-fence steps,
- forward applicability to environments that already recorded those 18 earlier write-fence migrations,
- exact source-domain authority.

## Executable behavior

`ForeignKeyWriteFence`:

1. requires PostgreSQL,
2. reruns the accepted orphan / tenant-context preflight against current rows,
3. installs at most the exact 18 known supporting target UNIQUE constraints,
4. proves every referenced tuple has an eligible non-partial valid UNIQUE/PK index,
5. creates each FK with `MATCH SIMPLE ON UPDATE RESTRICT ON DELETE RESTRICT NOT VALID`,
6. writes and rechecks a deterministic definition signature for restart safety,
7. fails closed on any conflicting pre-existing definition.

The eleven relation sets contain exactly **125 foreign keys**. All 125 remain `NOT VALID` after this gate: they enforce new/updated rows while historical validation remains reserved for the later `validate` phase.

## Safety boundary

This gate may create only:

- 18 supporting UNIQUE constraints required as FK targets,
- 125 foreign keys belonging to the eleven `MIG-FK-*` nodes.

It may not create or execute:

- CHECK constraints,
- user triggers,
- projections,
- data backfill,
- reconciliation,
- FK validation,
- contract/destructive cleanup.

Target-domain row mutation is forbidden. PKK provider runtime remains **FROZEN_UNTIL_EXPLICIT_UNFREEZE**.

## Candidate authority

- plan identity: `d2fd6bc999dc2a5ee024e9b91bf00f5a5dad29575554a0ba42eba67107c23f10`
- previous execution identity: `411d0c2c1493fb2ea052e89b5c919f2b7e3ae0f746301107b0a7c08610486f71`
- candidate execution identity: `1c3d384b6b52c68228111f08fb1a1d966f94b2281670a82550860f851cd4745d`
- Stage-4 materialized nodes: **157**
- Stage-4 materialized steps: **186**
- preflight: **39/39**
- write-fence: **29/52**
- remaining write-fence steps after PASS: **23**

## PASS requirements

PASS requires:

- exact eleven FK write-fence steps registered in canonical order,
- exact migration SHA-256 registry,
- full preflight still PASS,
- exactly 18 supporting target UNIQUE constraints,
- exactly 125 signed Stage-4 FK constraints,
- all 125 FKs `NOT VALID`,
- `MATCH SIMPLE / RESTRICT / RESTRICT` metadata exact,
- zero target-domain row mutation,
- zero CHECK or user-trigger scope creep,
- no backfill/reconcile/validate/contract materialization,
- full Implementation CI **5/5**,
- PostgreSQL suite PASS,
- deterministic restore PASS,
- helper tree clean-promoted unchanged to accepted branch.

After PASS, the next safe prefix is **CORE-V1-STAGE4-CONSTRAINTS-WRITE-FENCE-001**, exactly ten `MIG-CON-*` write-fence steps.

## Closure evidence

`CORE-V1-STAGE4-FOREIGN-KEYS-WRITE-FENCE-001` is closed **PASS**.

Validation-only helper:

- PR **#90** closed without merge,
- helper commit: `fddc8678cec37f4444e30d6fef8eda470473796d`,
- helper tree: `77c364fe38674937bd8cc71b60981ccff9c155eb`,
- helper Implementation CI #425 / run `34787640119`: **5/5 PASS**,
- PostgreSQL: **241 tests / 4739 assertions**,
- deterministic restore: **121 -> 121**, `RESTORE_DRILL_HARNESS=PASS`.

Clean accepted implementation:

- accepted commit: `65118865e92e98b83e77cde0c96c2a10cb259944`,
- accepted tree: `77c364fe38674937bd8cc71b60981ccff9c155eb`,
- helper/accepted tree match: **PASS**,
- accepted Implementation CI #426 / run `34787852442`: **5/5 PASS**,
- PostgreSQL: **241 tests / 4739 assertions**,
- deterministic restore: **121 -> 121**, `RESTORE_DRILL_HARNESS=PASS`.

Final state:

- Stage-4 DAG: **170 nodes**,
- materialized nodes: **157**,
- materialized steps: **186**,
- global preflight: **39/39 PASS**,
- write-fence steps: **29/52**,
- supporting referenced UNIQUE constraints in this tranche: **18 PASS**,
- foreign keys created in this tranche: **125 PASS**, all initially **NOT VALID**,
- FK metadata: **MATCH SIMPLE / ON UPDATE RESTRICT / ON DELETE RESTRICT PASS**,
- target-domain row mutation: **0**,
- CHECK/user-trigger scope creep: **0**,
- execution identity: `1c3d384b6b52c68228111f08fb1a1d966f94b2281670a82550860f851cd4745d`,
- PKK provider runtime remains **FROZEN_UNTIL_EXPLICIT_UNFREEZE**.

Next gate: **CORE-V1-STAGE4-CONSTRAINTS-WRITE-FENCE-001**, exactly ten `MIG-CON-*` write-fence steps, expected to advance Stage-4 to **196 materialized steps / 39 of 52 write-fence steps**.
