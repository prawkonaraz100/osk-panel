# 212. Production readiness repository audit

Data: 2026-09-15

**Status:** `AUDIT_COMPLETE_REPOSITORY_RUNTIME_SUBSTRATE_REQUIRED`

## Starting authority

Core V1 repository closure is complete at:

`181f33789d4e3b92adc3e132f665e436657d8df9`

`CORE-V1-CLOSURE-AUDIT-016 = PASS_ZERO_REPOSITORY_ACTIONABLE_P0_P1`

This production-readiness audit does **not** reopen Core V1.

## Already closed in repository

The repository already contains and tests:

- tenant isolation and scoped authorization,
- audit logs for critical mutations,
- privacy/retention authority,
- disaster-recovery targets,
- deterministic CI restore drill,
- incident-response roles and runbooks,
- provider-neutral reconciliation scanner,
- 15-minute reconciliation scheduler contract,
- secret scanning,
- PostgreSQL-backed runtime tests,
- frontend/backend/static-analysis/API-contract gates.

## Remaining environment evidence

The following cannot truthfully be proven by repository code alone:

1. target PostgreSQL PITR and off-primary backup copy,
2. production object versioning and restore,
3. measured target RPO/RTO,
4. production secret-manager/injection configuration,
5. real monitoring dashboards and alert routes,
6. incident contact roster and paging smoke test,
7. real scheduler execution and alert delivery,
8. target release smoke test.

They remain go-live evidence, not repository implementation claims.

## Repository-owned gaps found

### 1. Health contract mismatch

Policy in `docs/85-production-operations.md` requires:

- `/health/live`,
- `/health/ready`.

The accepted runtime exposed only the framework `/up` route.

**Classification:** repository-actionable.

### 2. Structured production log channel

Production policy requires structured JSON logs, while the accepted configuration has only generic text-oriented defaults and an optional stderr formatter hook.

**Classification:** repository-actionable.

### 3. Immutable release artifact

Production policy requires an immutable build artifact/container and tagged/reproducible release reference. Existing CI validates source but does not emit a SHA-addressed production artifact with checksum.

**Classification:** repository-actionable substrate.

### 4. Target smoke harness

The repository does not contain a target-environment smoke command for the canonical liveness/readiness contract.

**Classification:** repository-actionable harness; actual target execution remains external evidence.

## Separate hardening not mixed into this gate

The privileged retention executor remains a separate future hardening gate. Its authority is already defined and ordinary application roles still cannot purge data.

This audit does not silently activate deletion behavior.

## Safe continuation

Next gate:

`PROD-READINESS-RUNTIME-001`

Allowed scope:

- canonical liveness/readiness endpoints,
- production JSON stderr logging channel,
- safe API request summary logging,
- exact-SHA release archive + SHA256,
- target health smoke harness,
- executable tests/contracts for all of the above.

Forbidden claims:

- production infrastructure is ready,
- PITR exists,
- paging reaches a human,
- production scheduler has run,
- PKK is active,
- payment-provider webhook exists.
