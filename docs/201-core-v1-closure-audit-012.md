# 201. CORE-V1-CLOSURE-AUDIT-012

Data: 2026-09-14

**Status:** `AUDIT_COMPLETE_COMMERCE_SCHEMA_AND_PRICING_AUTHORITY_REQUIRED`

## Audited accepted tip

`e785e7c3ce6ceeb1fa3cb2321595c4b9a561a056`

Tree:

`dce8e006433b25cbb20732113e082a3522c7f89f`

Prerequisite:

`CORE-V1-AUTH-PASSWORD-RECOVERY-001 = PASS`

## Exact-head closure evidence

- Implementation CI #564 / run `34891249628`: **5/5 PASS**
- API Contract Gate #457 / run `34891249812`: **PASS**
- PostgreSQL: **331 tests / 5808 assertions — PASS**
- deterministic restore: **121 -> 121 PASS**
- restore fingerprint: `09c57afe2501c79a3a684c0a695b4619114f94ddc24c9bca33728d99c1abb155`
- `RESTORE_DRILL_HARNESS=PASS`

## Canonical HTTP inventory

After password recovery:

- canonical required HTTP operations: **172**
- physical bindings present: **154**
- missing physical bindings: **18**
- frozen PKK/PWPW bindings: **14**
- provider-specific payment webhook: **1**
- repo-actionable missing HTTP bindings: **3**

No repo P0 is open.

One additional non-HTTP repo P1 is open: the canonical DB-COM-005 per-organization order-sequence allocator is not physically materialized.

No repo-actionable operation remains schema-corrective blocked.

## Remaining repo-actionable HTTP gaps

### Authority/schema-blocked — 3

Student progress:

- `students.progress`

Commerce order creation:

- `license_orders.create`
- `exam_orders.create`

### Implementation-ready — 0

No remaining repo-actionable HTTP operation may be implemented without a new focused authority gate.

## Commerce create diagnosis

The canonical request contracts are already intentionally client-light:

- license order supplies product ids, quantities and payment method,
- internal-exam order supplies quantity and payment method,
- neither request supplies price, discount, VAT or total.

This is correct: the client must not become pricing authority.

The database contract already requires server-owned immutable pricing snapshots, order totals, order sequence allocation, idempotency and exact catalog identity. However the executable repository currently has **no canonical current pricing source**:

- `commerce_catalog_items` contains stable sellable identity but no current price/VAT fields,
- `license_products` contains entitlement/product behavior but no current commerce price,
- historical `order_items` snapshots are history and cannot be reused as current pricing authority.

Therefore implementing create endpoints now would require guessing current price/VAT or trusting client values, both forbidden.

## Additional physical schema discrepancy

DB-COM-005 defines the order-number allocation authority as:

`organization_commerce_order_sequences(organization_id, next_order_sequence)`

with allocation under an exact per-organization row lock.

The accepted executable repository does **not** currently materialize this table:

- there is no Stage4 or Stage5 migration for `organization_commerce_order_sequences`,
- there is no runtime allocator using it,
- repository code search finds no `next_order_sequence` implementation,
- `orders.order_sequence` is physically present, but application `MAX(order_sequence)+1` allocation is explicitly forbidden by DB-COM-005.

The frozen Stage4 migration history must not be edited. This allocator therefore requires an isolated post-Stage4 corrective authority and executable migration before either order-create endpoint can become implementation-ready.

This is a non-HTTP repo P1 because the missing physical concurrency authority is required by the canonical DB-COM-005 contract.

## Focused authority direction

Next authority may define a provider-neutral current pricing registry without changing historical order snapshot semantics.

The focused authority must settle **two independent prerequisites**:

1. a trusted current pricing source;
2. an isolated post-Stage4 materialization plan for the missing per-organization order-sequence allocator.

The smallest safe current-pricing design is a trusted server-side pricing registry keyed by stable `commerce_catalog_items.code`, with at least:

- currency,
- list unit amount in minor units,
- charged unit amount in minor units,
- VAT rate basis points,
- display-name snapshot source,
- pricing revision identifier.

Historical OrderItem remains the durable immutable evidence after placement.

The authority must also settle:

- exact SKU resolution for license product ids,
- exact SKU resolution for the internal-exam request that has no product id,
- whether initial `payment_method` creates the first provider-neutral pending payment attempt atomically with order placement,
- order-sequence allocation and lock order,
- reuse of existing `ResourceIdempotency`,
- zero-total behavior without inventing provider confirmation,
- no provider network call and no webhook behavior in the create gate.

## Student progress remains blocked

Student Progress has confirmed UI intent but still lacks a complete source-of-truth projection for:

- test/question aggregates,
- handbook/lecture completion,
- dynamic available totals,
- exact percentage formulas,
- learning-account/category aggregation semantics.

It remains authority-blocked until that projection is deliberately defined rather than inferred from the observed UI.

## PKK/PWPW

All 14 explicit PKK/PWPW bindings remain:

`FROZEN_UNTIL_EXPLICIT_UNFREEZE`

Core service operation remains independent of PKK.

## Safe continuation

Next gate selected:

`CORE-V1-COMMERCE-ORDER-CREATE-AUTHORITY-001`

Authority gate only:

- no HTTP binding,
- no provider network call,
- no payment webhook implementation,
- authority may define an isolated Stage5 allocator corrective because this audit has now proven it unavoidable,
- authority itself must not execute DDL,
- frozen Stage4 files and identities remain immutable,
- no PKK/PWPW runtime.
