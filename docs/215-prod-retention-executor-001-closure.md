# 215. PROD-RETENTION-EXECUTOR-001 closure

Data: 2026-09-15

**Gate:** `PROD-RETENTION-EXECUTOR-001`  
**Status:** `PASS_ACCEPTED`

## Repository result

The repository now contains a bounded privileged retention executor for exactly
one technical TTL class:

`idempotency_records`

No ordinary application role can invoke retention through HTTP. There is no
automatic retention schedule.

Destructive execution is disabled by default and requires explicit environment
enablement, exact policy version, nonblank reason, explicit execute flag, exact
confirmation token, a PostgreSQL advisory transaction lock and the configured
server-side candidate-count fence.

## Validated candidate

Exact candidate:

`be492fa0f73ae461b3bb5f9074ccea55e61fcda5`

Implementation CI:

`34926812714`

All five required jobs passed.

PostgreSQL:

- **355 tests passed**,
- **6095 assertions**.

Deterministic restore:

- **122 -> 122 tables**,
- fingerprint `f3cab286cc8f0aabef219971d90afe424a8dab694c47ade2f517a3d95970716d`,
- `RESTORE_DRILL_HARNESS=PASS`,
- `production_target_evidence=false`.

## Safety closure

The gate proves that:

- the retention cutoff is derived by the server from policy version
  `2026-09-12-v1`,
- only `completed` idempotency rows past that cutoff are eligible,
- recent rows and noncompleted rows are preserved,
- over-limit execution deletes nothing and writes
  `partial_requires_review` evidence,
- successful delete and completed evidence are transactional,
- deleted response payload is not copied into retention evidence,
- published outbox is not executable,
- audit/domain-event write fences are not bypassed,
- formal training, finance, Student data and legal-hold-aware classes are not
  executable,
- PKK/PWPW remains frozen and optional,
- payment-provider-specific webhook work remains external/deferred.

## Remaining production boundary

This gate is repository hardening only.

It does not prove:

- real production retention execution,
- target database backup/PITR,
- production object-storage recovery,
- legal-hold processing for business data,
- retention of audit/domain-event history,
- published outbox cleanup.

Those require separate environment evidence or separately reviewed authority.

## Accepted promotion

The gate is accepted on:

`0b10d222407695be32a544987e6496cdc16554be`

Accepted Implementation CI:

`34928461181`

Result:

- backend-quality — PASS,
- frontend-quality — PASS,
- contracts-and-traceability — PASS,
- secret-scan — PASS,
- runtime-tests-and-migrations — PASS,
- release-artifact — PASS.

Accepted runtime proof remains:

- **355 tests passed**,
- **6095 assertions**,
- restore **122 -> 122 tables**,
- fingerprint `f3cab286cc8f0aabef219971d90afe424a8dab694c47ade2f517a3d95970716d`,
- `RESTORE_DRILL_HARNESS=PASS`.

Immutable release artifact:

`osk-panel-0b10d222407695be32a544987e6496cdc16554be`

Artifact SHA-256:

`002ae2702f10527789e5c4bbda446110d54c03cf027eff7bbca3a31b0006da1d`

GitHub artifact ID:

`10380647820`

This accepted evidence closes the repository-owned portion of
`PROD-RETENTION-EXECUTOR-001`.
