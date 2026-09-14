# 205. CORE-V1-COMMERCE-ORDER-CREATE-001

Data: 2026-09-15

**Status:** `PASS`

## Scope

Materialize exactly the two repository-actionable commerce create operations released by `CORE-V1-CLOSURE-AUDIT-013`:

- `license_orders.create`
- `exam_orders.create`

Routes:

- `POST /api/v1/license-orders`
- `POST /api/v1/internal-exam/orders`

No provider webhook and no provider network call are part of this gate.

## Prerequisites

- `CORE-V1-COMMERCE-ORDER-CREATE-AUTHORITY-001 = PASS`
- `CORE-V1-STAGE5-COMMERCE-ORDER-SEQUENCE-001 = PASS`
- `CORE-V1-CLOSURE-AUDIT-013 = AUDIT_COMPLETE_COMMERCE_ORDER_CREATE_IMPLEMENTATION_READY`

## Trusted pricing boundary

Order create accepts quantity/product/payment-method intent only.

All monetary authority is server-owned and resolved from:

`commerce.order_create.pricing_by_catalog_code`

The client cannot supply or override:

- currency,
- list unit amount,
- charged unit amount,
- discount,
- VAT,
- product display snapshot,
- pricing revision.

Missing or malformed current pricing fails closed.

## Product and catalog resolution

License order create requires:

- an active `license_products` row,
- exactly one active `commerce_catalog_items` mapping for that exact license product,
- exact `product_kind=license` lineage.

Internal-exam order create requires:

- one configured exact stable catalog code,
- exactly one active matching `internal_exam` catalog row,
- no license-product lineage.

Ambiguous or unavailable catalog authority fails closed.

## Immutable order snapshots

Each created `order_items` row preserves server-owned:

- stable catalog identity,
- product kind and exact license product where applicable,
- quantity,
- currency,
- list and charged unit amount,
- unit discount,
- VAT basis points,
- line total,
- product snapshot,
- pricing snapshot and pricing revision,
- deterministic SHA-256 snapshot hash.

Existing Stage4 commerce snapshot write-fences remain authoritative.

## Per-organization order allocator

Runtime allocation uses only:

`organization_commerce_order_sequences(organization_id, next_order_sequence)`

The exact allocator row is locked `FOR UPDATE`.

The sequence pointer is incremented and the Order is inserted in the same local transaction.

Runtime `MAX(order_sequence)+1` is forbidden.

If historical Orders exist but the allocator row is missing, runtime fails closed instead of reconstructing sequence authority.

## Idempotency

Both create operations use the shared `ResourceIdempotency` boundary.

The payment-method code is normalized before idempotency hashing.

Semantics:

- same key + same normalized request -> replay original created Order,
- same key + different request -> conflict,
- no duplicate Order/Payment/fulfillment effect on replay.

## Nonzero total

For a nonzero total the create transaction records exactly one provider-neutral local `Payment`:

- status `pending`,
- amount/currency copied from trusted Order authority,
- normalized provider/method code,
- opaque public payment reference,
- no provider payment id yet.

The create endpoint performs no provider network I/O and does not fabricate a settlement.

## Zero total

For a zero-total Order:

- `zero_total_settled_at` and `booked_at` are set locally,
- no Payment row is created,
- a pending `order_fulfillments` row with source `zero_total` is created,
- no payment settlement row is fabricated.

This preserves the existing fulfillment pipeline without pretending that an external payment occurred.

## Audit/outbox

The reference catalog now contains explicit current policies for:

- `commerce.license_order.created`
- `commerce.internal_exam_order.created`

The business transaction emits audit log, domain event and outbox intent atomically.

Audit payloads remain under the existing redacted `resources.lifecycle.v1` allow-list.

## Security and tenancy

Runtime requires the existing active organization membership and exact permissions:

- `licenses.purchase`
- `exams.purchase`

Product/order effects are tenant-scoped.

No PKK/PWPW runtime is activated.

## Corrective history

Initial runtime candidate:

`7e7b4678d6f006d81a14f6899fca664fa0fe911f`

Formatting corrective:

`230731cc588e567aff8d6a859b31b7321177d92a`

Static-analysis corrective:

`411647e0b8ede86d17aada7529475a666b69811c`

Audit-policy corrective:

`22f0c65508ca0d35ca348d882d1b0bceb0d44a71`

Audit-policy catalog contract corrective:

`7ebb1faf189580920fdf8798ca4e8e46e7e4bba0`

Final re-seed count corrective:

`a34981bb6b4cf981981a9c58eaea5c1be7752705`

Exact implementation tree:

`99be4925bfb3dee5828666dce8c624a7408456f3`

## Exact-head validation evidence

- Implementation CI #578 / run `34910043748`: **5/5 PASS**
- API Contract Gate #473 / run `34910043582`: **PASS**
- PostgreSQL: **338 tests / 5926 assertions — PASS**
- deterministic restore: **122 -> 122 PASS**
- restore fingerprint: `f3cab286cc8f0aabef219971d90afe424a8dab694c47ade2f517a3d95970716d`
- `RESTORE_DRILL_HARNESS=PASS`
- backend Pint/PHPStan: **PASS**
- frontend quality: **PASS**
- contracts/traceability: **PASS**
- secret scan: **PASS**

The executable suite proves trusted server pricing, exact catalog resolution, allocator sequence progression, immutable snapshots, same-key replay, changed-payload conflict, nonzero pending-payment behavior, zero-total local settlement/fulfillment, fail-closed missing pricing, permission enforcement and tenant isolation.

## Inventory methodology correction

A fresh comparison against the full YAML required-operation authority found one historical audit undercount.

Earlier closure audits reported **172** canonical HTTP operations because their quick inventory counted inline requirement rows and omitted the unique multi-line HTTP row:

- `license_management.expand_history`
- `GET /students/{studentId}/learning-accounts/{accountId}/license-assignments`

That operation has an existing physical route and was never a runtime gap.

The structural API gate correctly reports **173 canonical HTTP operations**.

Therefore the corrected physical baseline before this gate is **155**, not 154. The missing-operation count is unchanged because the omitted operation was already bound.

## Closure effect

Exactly two canonical HTTP bindings are closed:

- `license_orders.create`
- `exam_orders.create`

Physical HTTP bindings move:

**155 -> 157**

Missing physical bindings move:

**18 -> 16**

Repo-actionable missing HTTP bindings move:

**3 -> 1**

The remaining repo-actionable Core V1 HTTP gap is:

- `students.progress`

Unchanged external/deferred gaps:

- 14 explicit PKK/PWPW bindings remain frozen,
- 1 provider-specific payment webhook remains outside repository-actionable core until its adapter contract.

## Explicit non-scope

- no provider network I/O,
- no provider webhook,
- no payment signature verification,
- no provider settlement/reconciliation implementation,
- no Stage4 schema mutation,
- no PKK/PWPW runtime,
- no Student Progress implementation.

## Required continuation

Run a fresh repository closure audit before starting Student Progress implementation.

The next audit must recompute physical bindings and classify `students.progress` from current authority rather than assuming it is immediately implementation-ready.
