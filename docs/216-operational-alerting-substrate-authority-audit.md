# 216. Operational alerting substrate authority audit

Data: 2026-09-15

**Gate:** `PROD-OPERATIONAL-ALERTING-001`  
**Status:** `IMPLEMENTATION_CANDIDATE`

## Starting authority

Accepted branch:

`4c713b05b88fd9e56d8b4a76fe69bde93080ff77`

Core V1, production runtime substrate and bounded retention executor are already
closed. This gate does not reopen them.

## Repository-owned gap

Production policy requires monitoring and critical alerting, while the accepted
repository has structured logs, health checks, incident runbooks and a
reconciliation scheduler but no application-level alert delivery substrate.

The repository can safely provide the transport contract and smoke harness. It
cannot truthfully prove that a real operator receives a page without target
environment configuration.

## Transport authority

The first alert transport is provider-neutral HTTPS webhook delivery.

Requirements:

- disabled by default,
- endpoint injected through environment,
- HTTPS only,
- HMAC-SHA256 signature using an environment-injected secret,
- bounded request timeout,
- no endpoint or secret in logs,
- no automatic vendor selection,
- no personal data in the alert envelope.

A failed or disabled delivery never fabricates success.

## Event allowlist

This gate authorizes exactly two event codes:

1. `synthetic_smoke` — safe production paging smoke signal,
2. `reconciliation_findings` — aggregated finding counts from the existing
   read-only reconciliation scanner.

The reconciliation alert may contain only:

- policy version,
- total finding count,
- finding counts by scope.

It may not contain organization IDs, entity IDs, Student data, payment details,
PKK data or raw finding payloads.

## Smoke command

`operations:alert:smoke` requires the exact confirmation token:

`SEND-SYNTHETIC-OPERATIONAL-ALERT`

The command succeeds only when the configured sink confirms an HTTP success
response. A disabled sink or failed delivery returns a failing exit code.

## Remaining environment evidence

Even after this gate passes, production still must prove:

- the real destination is configured,
- contact references resolve to real operators,
- the synthetic alert is received and acknowledged,
- dashboards/monitors are active,
- the scheduler runs in the target environment and reconciliation failures
  reach the real alert destination.

Those are deployment evidence, not repository claims.

## Boundaries preserved

PKK/PWPW remains frozen and optional. Payment-provider-specific webhook work
remains an external boundary.
