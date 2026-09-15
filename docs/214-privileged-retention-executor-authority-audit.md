# 214. Privileged retention executor authority audit

Data: 2026-09-15

**Gate:** `PROD-RETENTION-EXECUTOR-001`  
**Status:** `IMPLEMENTATION_CANDIDATE`

## Starting authority

Accepted repository authority:

`402fb7eb13278119ebb2ec37090297aaf978856d`

The privacy schedule remains `2026-09-12-v1`.

This gate does not change statutory or product retention durations.

## Materialization correction

The historical H3 document predates final Stage-4 materialization and says that
`data_retention_execution_runs` was not yet materialized.

That is no longer true on the accepted repository. The table is present and the
Stage-4 events write fence makes its existing rows append-only for normal
runtime.

## Safe first executable class

The first privileged executor is intentionally restricted to:

`idempotency_records`

Eligibility is exact:

- `status = completed`,
- `completed_at` is present,
- `completed_at <= server-derived cutoff`,
- cutoff comes from retention policy version `2026-09-12-v1`,
- optional organization scope is exact UUID equality.

The executor never uses request payload, response payload, expiry hint, attempt
count or queue pressure to make a record eligible.

## Privilege boundary

There is no HTTP endpoint.

The command is CLI-only and is not scheduled automatically. Dry-run is the
default. Destructive execution additionally requires:

1. `RETENTION_EXECUTOR_ENABLED=true`,
2. exact policy version,
3. nonblank reason,
4. `--execute`,
5. exact destructive confirmation token,
6. PostgreSQL advisory transaction lock,
7. server-side maximum candidate fence.

Ordinary application permissions do not grant this capability.

## Evidence model

A successful execution writes one final row to
`data_retention_execution_runs` in the same transaction as the delete.

No evidence row is updated after insert.

A candidate set larger than the server-side maximum performs **zero deletes**
and records `partial_requires_review`.

An operational transaction failure rolls back deletion and attempts to append a
separate `failed` evidence row without storing deleted payload.

## Explicit exclusions

This gate does not execute retention for:

- formal Student/Course history,
- lesson-card detail,
- finance,
- contact minimization,
- audit/domain-event history,
- activity/notifications,
- auth sessions,
- file assets,
- logs,
- PKK identity/provider data,
- published outbox.

In particular, the current outbox DB trigger rejects delete. This gate does not
introduce a bypass. Published-outbox cleanup requires a separate reviewed DB
privilege/procedure design.

## PKK boundary

PKK/PWPW remains frozen and optional. No provider payload collection or purge
logic is introduced.

## Validation target

The implementation must prove:

- dry-run is non-destructive,
- cutoff is server-derived,
- only old completed idempotency records are candidates,
- recent and noncompleted records survive,
- disabled executor refuses deletion,
- wrong policy refuses deletion,
- non-allowlisted class refuses deletion,
- row-count fence performs zero deletion,
- final evidence contains counts/reason/policy/cutoff but no deleted payload,
- no HTTP route and no automatic schedule exists.
