# 216. Production target configuration preflight

Data: 2026-09-15

**Gate:** `PROD-TARGET-CONFIG-PREFLIGHT-001`  
**Status:** `IMPLEMENTATION_CANDIDATE`

## Why this gate exists

The repository already emits an immutable exact-SHA release artifact and exposes
canonical liveness/readiness checks.

A remaining repository-owned risk is deployment with development-like
configuration, for example:

- `APP_DEBUG=true`,
- HTTP application/reset URLs,
- text/file logging instead of JSON stderr,
- non-Redis session/cache/queue settings,
- local filesystem instead of S3,
- log/array mail transport,
- missing incident contact references,
- missing sensitive-identifier lookup key,
- privileged retention executor left enabled outside a maintenance window.

This gate adds a read-only, fail-closed configuration preflight.

## Command

`php artisan operations:production:preflight --json`

The command does not contact PWPW, a payment provider, or mutate business
state.

It emits only check names/statuses/messages. Secret values are never included.

## Important semantic boundary

A successful command means:

`configuration_status=PASS`

It deliberately still emits:

`production_ready=false`

and:

`go_live_status=BLOCKED_EXTERNAL_EVIDENCE`

because repository code cannot prove that target PITR, object restore,
monitoring, paging or scheduler delivery actually happened.

## External evidence still required

The command keeps the existing go-live evidence list explicit:

1. target PostgreSQL restore/PITR drill,
2. production backup evidence,
3. object versioning + restore evidence,
4. secret-manager or equivalent injection evidence,
5. monitoring dashboards + alert routes,
6. contact roster + paging smoke,
7. reconciliation scheduler + alert delivery smoke,
8. target release smoke.

## Deferred providers

PKK/PWPW remains frozen and is not required for the core service.

Provider-specific payment webhook behavior remains an external boundary until a
provider contract exists.

Neither is fabricated as a preflight requirement.
