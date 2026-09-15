# 173. CORE-V1-SERVICE-ENTITLEMENTS-001 — closure

Data: 2026-09-14

**Status:** `PASS`

## Exact scope

This gate materializes exactly two canonical operations:

- `GET /api/v1/service-entitlements` — `service_entitlements.list`,
- `POST /api/v1/service-entitlements/{entitlementId}/activate` — `service_entitlements.activate`.

No schema, migration, purchase-payment confirmation flow or provider integration changed.

## Authority boundary

The runtime reuses the existing `DB-COM-007` authorities:

- `service_entitlements` is the durable generic-service right,
- `service_activations` is immutable activation evidence,
- explicit entitlements are `available` with zero activations before activation,
- activated entitlements have exactly one activation,
- `granted` cannot survive the transaction boundary,
- terminal `expired` / `revoked` states cannot receive a new activation.

The Stage-4 unique index, same-tenant FK and deferred final-state trigger remain the final database boundary.

## List authorization and projection

Listing requires:

- active tenant membership,
- `purchases.view`,
- organization scope.

Rows are restricted to the authenticated organization before projection. The response is built only from canonical entitlement and activation rows and exposes the existing OpenAPI `ServiceEntitlement` fields.

## Explicit activation

Activation requires:

- active tenant membership,
- `purchases.create`,
- organization scope,
- `activation_mode = explicit`.

The command boundary is:

1. atomically claim tenant-scoped `Idempotency-Key`,
2. lock the exact entitlement,
3. reject foreign, immediate or terminal targets,
4. if exact activation already exists with `status = activated`, return the existing success without another side effect,
5. otherwise require `status = available`,
6. insert exactly one `service_activations` row,
7. move entitlement projection to `activated`,
8. record one audit/domain-event/outbox intent in the same local transaction,
9. complete the idempotency record.

A retry with a different idempotency key after a successful activation still returns the existing activation and does not create a second activation or second audit event.

## Preserved payment boundary

Explicit entitlement activation is **not** payment success.

This gate does not:

- let payment/browser return activate an explicit entitlement,
- create purchase fulfillment,
- grant new service entitlements from an order,
- alter immediate-mode fulfillment semantics,
- add pricing or catalog authority,
- touch PKK/PWPW runtime.

## Executable evidence

Exact implementation head:

`787926b86355d3cf877c9db2c0bd1f1fa8117a80`

Final evidence:

- Implementation CI #498 / run `34819121371`: **5/5 PASS**
- API Contract Gate #377 / run `34819121415`: **PASS**
- PostgreSQL: **282 tests / 5273 assertions — PASS**
- deterministic restore: **121 -> 121 PASS**
- restore schema fingerprint: `09c57afe2501c79a3a684c0a695b4619114f94ddc24c9bca33728d99c1abb155`
- frontend quality: **PASS**
- Pint + PHPStan: **PASS**
- contracts/traceability: **PASS**
- secret scan: **PASS**

## Closure effect

The previous derived count was **17 repo-actionable missing HTTP bindings**.

This gate closes exactly two bindings, leaving a derived **15** before the next full repository closure re-audit.

## Next safe slice

The remaining commerce order-create bindings are **not** safe to materialize yet: `commerce_catalog_items` carries stable product identity but no server-authoritative price/VAT source, while `order_items` requires immutable price, discount, VAT and total snapshots. No price source will be invented.

The next candidate is therefore a read-only authority proof for:

- `learning_accounts.download_handoff_pdf`,
- `license_credentials.bulk_pdf`.

Any implementation must preserve the existing DB-LIC-004 rule that historical plaintext passwords are unrecoverable and secret-bearing output may only originate from a fresh authorized handoff/reset boundary.
