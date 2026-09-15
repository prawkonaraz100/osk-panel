# 220. Operational alerting substrate authority

Data: 2026-09-15

**Gate:** `PROD-OPERATIONAL-ALERTING-001`  
**Status:** `IMPLEMENTATION_CANDIDATE`

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
