# CORE-V1-STAGE4-CONSTRAINTS-PREFLIGHT-001

Status: `PASS`

## Scope

Materialize exactly the ten authoritative Stage-4 constraint nodes in their `preflight` phase only, orders **1480–1570**:

1. `MIG-CON-IDENTITY`
2. `MIG-CON-RESOURCES`
3. `MIG-CON-TRAINING`
4. `MIG-CON-CALENDAR`
5. `MIG-CON-PKK`
6. `MIG-CON-FINANCE`
7. `MIG-CON-LICENSES`
8. `MIG-CON-EXAMS`
9. `MIG-CON-COMMERCE`
10. `MIG-CON-EVENTS`

This slice completes the authoritative global preflight prefix: **39 / 39 nodes**. It remains read-only. No CHECK, FK, UNIQUE, exclusion constraint, trigger, compatibility column, backfill, reconciliation or write-fence effect may be created here.

## Candidate authority

- Stage-4 DAG: **170 nodes**.
- Materialized before: **147 nodes / 147 steps**.
- Candidate after this helper: **157 nodes / 157 steps**.
- Preflight after helper: **39 / 39**.
- Remaining preflight nodes before write-fence: **0**.
- Materialized write-fence steps: **0**.
- Plan identity remains `d2fd6bc999dc2a5ee024e9b91bf00f5a5dad29575554a0ba42eba67107c23f10`.
- Previous execution identity: `488bedcef5d2aabef34e294f8fa0c2b48ef392e804cc83c6cd75c8ba5bfd8b1c`.
- Candidate execution identity: `d2da7eb3ccb082ca6b0106fbe4b1e543815c9236cc1a8bd62cf470c93459b660`.
- PKK provider runtime remains **FROZEN_UNTIL_EXPLICIT_UNFREEZE**.

## Safety boundary

The preflight evaluates row-local closed-value, numeric-bound, time-order, nullable-pair, terminal-tuple, source-XOR and publisher-state predicates that belong to the later constraint layer. Any violating legacy row fails closed and requires reviewed remediation.

Cross-row final-state equivalence, append-only enforcement and exact projection-set guards remain owned by later `MIG-TRG-*` nodes. FK orphan/same-tenant validation remains owned by the already-PASS `MIG-FK-*` preflight. Duplicate/overlap validation remains owned by the already-PASS `MIG-IDX-*` preflight.

The helper must leave both target schema signature and target row counts unchanged.

## Gate after PASS

A PASS here completes global preflight **39/39**, but does not itself authorize arbitrary write-fence execution. The next gate must explicitly select the first safe write-fence tranche from the frozen order and cutover contract.


## Closure evidence

`CORE-V1-STAGE4-CONSTRAINTS-PREFLIGHT-001` is closed **PASS**.

Validation-only helper:

- validation PR **#85** was closed without merge,
- validated helper commit: `b7f128e6c0424203c9287da74a21a41dde043319`,
- validated helper tree: `4712abdf1dc05e24e1ce815559ca62d71e7bd6f3`,
- helper Implementation CI: run `34776362980` / #406 — **5/5 PASS**,
- PostgreSQL: **236 tests / 3228 assertions**,
- deterministic restore: **121 -> 121**, `RESTORE_DRILL_HARNESS=PASS`.

Clean accepted implementation:

- accepted implementation commit: `0405103563b0c9f0e808b9cf61c0aed747445654`,
- accepted implementation tree: `4712abdf1dc05e24e1ce815559ca62d71e7bd6f3`,
- exact helper/accepted tree match: **PASS**,
- accepted Implementation CI: run `34776617578` / #407 — **5/5 PASS**,
- PostgreSQL: **236 tests / 3228 assertions**,
- deterministic restore: **121 -> 121**, `RESTORE_DRILL_HARNESS=PASS`,
- backend static analysis: **PASS_ZERO_ERRORS**,
- frontend quality: **PASS**,
- contracts and traceability: **PASS**,
- secret scan: **PASS**.

Migration authority after PASS:

- Stage-4 DAG: **170 nodes**,
- Stage-4 materialized nodes: **157**,
- Stage-4 materialized steps: **157**,
- global preflight prefix: **39 / 39**,
- remaining preflight nodes before write-fence: **0**,
- write-fence steps materialized: **0**,
- plan identity remains `d2fd6bc999dc2a5ee024e9b91bf00f5a5dad29575554a0ba42eba67107c23f10`,
- execution identity is `d2da7eb3ccb082ca6b0106fbe4b1e543815c9236cc1a8bd62cf470c93459b660`,
- Stage-5 formal-document authority remains **11 steps**,
- API inventory remains **187 / 173 / 14**,
- FORMAL-DOC-011 remains **PASS**,
- PKK provider runtime remains **FROZEN_UNTIL_EXPLICIT_UNFREEZE**.

Next gate: **CORE-V1-STAGE4-CANDIDATE-KEYS-WRITE-FENCE-001**.

It covers exactly the eight authoritative `MIG-CK-*` nodes in their `write_fence` phase, orders 1190–1260. Because those nodes are already materialized in `preflight`, a PASS keeps **157 materialized nodes** and increases materialized steps **157 -> 165**. No index, FK, constraint, trigger, backfill or reconciliation node may be pulled into that tranche.
