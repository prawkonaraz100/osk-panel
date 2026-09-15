# 220. Operational alerting substrate authority

Data: 2026-09-15

**Gate:** `PROD-OPERATIONAL-ALERTING-001`  
**Status:** `PASS_REPOSITORY_ALERT_SUBSTRATE`

## Starting authority

Accepted repository:

`162b78c51359af8d78b60a7261bd7cab438fe717`

The preceding repository gates already provide production configuration validation and a strict external go-live evidence validator. This gate adds only the missing repository-owned alert transport substrate.

## Provider-neutral transport

The application may emit operational alerts to one deployment-injected HTTPS endpoint.

The request:

- is JSON,
- is signed with HMAC-SHA256,
- carries an opaque event UUID,
- does not log the endpoint or secret,
- contains only event-specific allowlisted context.

No paging vendor is selected in source control.

## Reconciliation integration

When the existing read-only reconciliation scanner reports findings, it emits the `reconciliation_findings` operational event.

Its alert context is limited to:

- reconciliation policy version,
- total finding count,
- aggregate counts by reconciliation scope.

Organization, user and entity identifiers are not part of the payload.

The scanner itself remains read-only and continues to fail when `--fail-on-findings` is used.

## Production configuration guard

The existing production preflight is extended to fail closed unless:

- operational alerting is enabled,
- the endpoint uses HTTPS,
- an alert HMAC secret is injected.

A preflight PASS proves only configuration shape. It does not prove route reachability or human receipt.

## Synthetic smoke

`php artisan operations:alert:smoke --confirm=SEND-SYNTHETIC-OPERATIONAL-ALERT --json`

The smoke command refuses execution without the exact confirmation token.

A repository test uses an HTTP fake. Real production execution remains external go-live evidence and must be independently confirmed as reaching the intended operator.

## Boundaries

This gate does not:

- provision a monitoring vendor,
- create dashboards,
- configure a paging provider account,
- claim a real operator received a page,
- activate production traffic,
- unfreeze PKK/PWPW,
- implement the payment-provider-specific webhook.


## Repository closure

Validated candidate:

`2dae7bcc9bd3606c83d7710dffa723f434c10084`

Implementation CI run:

`34944229712`

Exact candidate result:

- backend-quality — PASS,
- frontend-quality — PASS,
- contracts-and-traceability — PASS,
- secret-scan — PASS,
- runtime-tests-and-migrations — PASS,
- PostgreSQL-backed test suite — PASS as part of runtime job,
- deterministic restore drill harness — PASS as part of runtime job,
- release-artifact — SKIPPED on pull request by workflow design.

Repository status therefore means:

`OPERATIONAL_ALERT_SUBSTRATE=PASS`

It proves the repository-owned provider-neutral transport, signed payload contract,
fail-closed production configuration guard and synthetic smoke command.

It does **not** mean:

`PRODUCTION_ALERT_DELIVERY=PASS`

Real endpoint configuration, route reachability, monitoring activation and receipt
by an intended operator remain external go-live evidence.
