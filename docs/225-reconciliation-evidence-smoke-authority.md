# 225. Reconciliation evidence smoke authority

Data: 2026-09-15

**Gate:** `PROD-RECONCILIATION-EVIDENCE-SMOKE-001`  
**Status:** `PASS_REPOSITORY_SAFE_EVIDENCE_SMOKE`

## Starting authority

Accepted repository:

`4698798cee50e228d3e5e4befa713fe5b3f17a67`

Accepted repository closure remains true for Core runtime. This focused gate exists
only because target evidence item 8 must be collectable without corrupting
production business state.

Operational trackers:

- #106 — target go-live evidence
- #107 — safe reconciliation evidence design

## Problem

The existing validator required
`finding_failure_alert_reached_operator=true`.

Before go-live, proving that literally would incentivize creation of an actual
reconciliation inconsistency in production. That is forbidden.

The repository already proves in CI that real reconciliation findings dispatch
the `reconciliation_findings` event. Target evidence still needs to prove:

1. the real scheduler runs in the target environment;
2. the exact reconciliation alert event path reaches the intended operator.

## Selected safe procedure

Add CLI-only:

`operations:reconciliation:alert:smoke`

with exact confirmation:

`SEND-SYNTHETIC-RECONCILIATION-ALERT`

The command:

- performs no DB write,
- invokes no provider,
- dispatches the exact `reconciliation_findings` event,
- sends aggregate-only synthetic context,
- sets `synthetic_smoke=true`,
- never includes organization, user or entity identifiers,
- requires successful alert delivery for command success.

Target human receipt remains external evidence.

## Evidence contract correction

Go-live evidence policy becomes `2026-09-15-v2`.

For
`reconciliation_scheduler_execution_and_alert_delivery_smoke_test`
the exact details become:

- `scheduler_executed=true`,
- `reconciliation_alert_smoke_reached_operator=true`.

This proves scheduler runtime plus the alert path. It does not claim that a real
business finding occurred before go-live.

## Forbidden

- corrupt production records to manufacture a finding,
- insert fake payment/settlement discrepancies,
- mutate inventory to create inconsistent state,
- rewrite outbox state for smoke,
- bypass triggers or write fences,
- claim synthetic smoke is a real business discrepancy,
- activate PKK/PWPW.

PKK/PWPW remains frozen and optional for Core launch.


## Closure evidence

Validated exact head before closure:

`15c38f8b80ac2a9453223f7c2157052261b23a01`

Implementation CI run `34952486723` / #653: **5/5 PASS**.

- backend-quality: PASS,
- frontend-quality: PASS,
- contracts-and-traceability: PASS,
- secret-scan: PASS,
- runtime-tests-and-migrations: PASS,
- PostgreSQL: **379 tests / 6278 assertions — PASS**,
- deterministic restore: **122 -> 122 PASS**,
- restore schema fingerprint: `f3cab286cc8f0aabef219971d90afe424a8dab694c47ade2f517a3d95970716d`.

PR release-artifact remains skipped by workflow design; accepted-branch promotion must still produce the 6th job and immutable release artifact.
