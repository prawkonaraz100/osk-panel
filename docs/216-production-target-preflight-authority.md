# 216. Production target preflight authority

Data: 2026-09-15

**Gate:** `PROD-TARGET-PREFLIGHT-001`  
**Status:** `IMPLEMENTATION_CANDIDATE`

## Purpose

The repository already proves runtime health, deterministic restore behavior,
immutable release artifacts and the bounded privileged retention path.

The next repository-owned production-readiness gap is a fail-closed target
environment preflight that can be executed before an actual deployment is
declared ready.

This gate does not claim that a production environment exists.

## Command

`php artisan operations:production:preflight --json`

Default mode is configuration-only and performs no remote mutation.

Optional controlled live mode:

`php artisan operations:production:preflight --live --json`

Live checks run only after every static production configuration check passes.

## Static safety checks

The preflight requires, without printing secret values:

- production environment and debug disabled,
- HTTPS non-local application URL,
- strong application key,
- independent sensitive-identifier lookup key,
- PostgreSQL,
- non-development database credentials,
- authenticated/managed Redis reference,
- Redis cache/session/queue,
- secure + HTTP-only session cookies,
- JSON session serialization,
- durable failed-job visibility,
- S3-backed default and upload storage,
- non-development S3 bucket/credentials,
- HTTPS custom S3 endpoint when one is configured,
- JSON stderr production logging and non-debug level,
- HTTPS password reset URL,
- production-safe password reset mail transport,
- non-placeholder mail sender,
- all incident contact references injected,
- privileged retention executor disabled at ordinary boot.

## Controlled live checks

When `--live` is requested after static PASS, the command checks:

1. PostgreSQL connectivity with a read-only `SELECT 1`,
2. Redis connectivity,
3. one ephemeral Redis cache round trip,
4. Redis queue visibility via queue size read,
5. one opaque S3 write/read/delete round trip below
   `production-preflight/`.

The command does not write business database state and does not enqueue work.

## Secret handling

The report contains check identifiers, PASS/FAIL states and stable failure
codes only. It does not print:

- app key,
- database password,
- Redis password,
- S3 credentials,
- incident contact references,
- mail credentials.

The repository cannot prove whether environment variables originate from a
secret manager, workload identity or another secure injection layer. That
remains external deployment evidence.

## Explicit external evidence

Even a full PASS does **not** close:

- production PITR/off-primary restore,
- representative object-version restore,
- measured target RPO/RTO,
- secret-manager/injection attestation,
- monitoring dashboards and alert routes,
- paging smoke test,
- scheduler + reconciliation alert-delivery smoke,
- external HTTPS release smoke.

## Provider boundaries

PKK/PWPW remains frozen and optional. It is not checked or required.

Payment-provider-specific webhook behavior remains an external provider
boundary and is not required by this preflight.
