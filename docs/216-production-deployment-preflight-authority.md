# 216. Production deployment preflight authority

Data: 2026-09-15

**Gate:** `PROD-DEPLOY-PREFLIGHT-001`  
**Status:** `IMPLEMENTATION_CANDIDATE`

## Starting authority

Accepted repository tip:

`4c713b05b88fd9e56d8b4a76fe69bde93080ff77`

Core V1, production runtime substrate and the bounded technical-TTL retention
executor are already accepted.

This gate does not reopen those authorities.

## Problem

The repository can build an immutable SHA-addressed release and can smoke
`/health/live` / `/health/ready`, but a target deployment can still be
misconfigured with local/test defaults.

Examples include:

- `APP_DEBUG=true`,
- HTTP application/reset URLs,
- PostgreSQL `sslmode=prefer`,
- insecure session cookies,
- `log` mail transport,
- Moto/local S3 credentials and endpoint,
- missing internal-exam verifier keys,
- missing sensitive-identifier lookup key,
- empty incident contact references,
- privileged retention executor left enabled.

These are repository-detectable configuration failures and should be rejected
before a production rollout.

## Safe gate

The command:

`php artisan operations:production:preflight --json`

is deliberately read-only.

It:

- performs no DB mutation,
- performs no HTTP/provider call,
- does not inspect or print secret values,
- reports only check codes and pass/fail state,
- exits non-zero when any required core check fails.

PKK/PWPW is explicitly not required. Payment-provider-specific webhook state is
also not required by this provider-neutral preflight.

## Full-core configuration

The preflight treats already accepted Core V1 functionality as deployable only
when its required local configuration is present:

- HTTPS application and password-reset URLs,
- non-debug production mode,
- structured JSON stderr logs,
- PostgreSQL with non-optional SSL,
- Redis-backed cache/session/queue,
- secure HTTP-only session cookie,
- durable failed-job visibility,
- S3 object storage without known local test defaults,
- real mail transport,
- independent sensitive-identifier lookup HMAC key,
- internal-exam TTL/verifier/storage configuration,
- incident contact references,
- privileged retention executor disabled at normal baseline.

The preflight proves only configuration shape, not that external services are
reachable or operational.

## Release artifact hardening

The release builder is also tightened to exclude runtime state from:

- `storage/logs`,
- `storage/framework/cache/data`,
- `storage/framework/sessions`,
- `storage/framework/views`.

A separate verifier checks:

- exact Git-SHA artifact name,
- SHA-256 checksum,
- manifest SHA/name/checksum identity,
- absence of environment files,
- absence of forbidden runtime state.

The accepted-branch release job must execute that verifier before upload.

## Evidence that still cannot come from this gate

Production remains blocked until target-environment evidence exists for:

1. PostgreSQL PITR/off-primary backup and measured RPO/RTO,
2. object-storage versioning and restore,
3. secret-manager or equivalent protected injection,
4. real monitoring dashboards and alert routes,
5. real paging smoke,
6. actual reconciliation scheduler + alert delivery,
7. target release smoke.

The repository must not convert those external facts into synthetic PASS claims.
