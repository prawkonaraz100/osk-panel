# 202. CORE-V1-COMMERCE-ORDER-CREATE-AUTHORITY-001

Data: 2026-09-14

**Status:** `IN_VALIDATION`

## Scope

This authority closes the remaining product and concurrency questions required before:

- `license_orders.create`
- `exam_orders.create`

It does **not** add either HTTP binding and executes no DDL.

The authority defines:

1. the trusted current pricing source;
2. exact catalog/SKU resolution;
3. order-creation idempotency and transaction shape;
4. initial provider-neutral payment-attempt semantics;
5. zero-total semantics;
6. the isolated Stage-5 materialization contract for the missing per-organization order-sequence allocator.

## Current pricing authority

Current prices are not inferred from historical `order_items`, license duration, UI text or client input.

The authoritative current pricing source is trusted server configuration:

`commerce.order_create.pricing_by_catalog_code`

Deployment supplies entries keyed by the immutable `commerce_catalog_items.code`.

Each configured entry contains exactly the current server-owned commercial facts needed to place an order:

- `currency` — uppercase ISO-4217-style 3-letter code;
- `list_unit_amount_minor` — integer >= 0;
- `charged_unit_amount_minor` — integer >= 0 and <= list amount;
- `vat_rate_basis_points` — integer 0..10000;
- `display_name` — nonblank snapshot source;
- `pricing_revision` — nonblank immutable identifier for the pricing decision used by this placement.

There are deliberately **no repository default prices**. Missing or malformed deployment pricing fails closed. Runtime must never invent a price/VAT or fall back to historical order lines.

For each line:

`unit_discount_amount_minor = list_unit_amount_minor - charged_unit_amount_minor`

`line_total_minor = quantity * charged_unit_amount_minor`

All arithmetic must be overflow-checked.

One Order has one currency. Mixed configured currencies in one request are rejected before insert.

## Exact license SKU resolution

For each `LicenseOrderRequest.items[].product_id`:

1. resolve that exact active `license_products.id`;
2. resolve exactly one active `commerce_catalog_items` row where:
   - `product_kind = license`,
   - `license_product_id = requested product_id`;
3. use the catalog row's immutable `code` to resolve current pricing config;
4. reject zero or multiple active mappings.

The request never supplies catalog code, price, discount, VAT, total or order number.

The immutable product snapshot includes at minimum:

- catalog item id,
- stable catalog code,
- `license` product kind,
- display name from trusted pricing config,
- exact license product id,
- license product code,
- duration days,
- activation mode.

## Exact internal-exam SKU resolution

The internal-exam request intentionally has no product id.

Server configuration therefore supplies one stable selector:

`commerce.order_create.internal_exam_catalog_code`

Runtime resolves exactly that unique `commerce_catalog_items.code` and requires:

- `product_kind = internal_exam`,
- `license_product_id IS NULL`,
- `active = true`.

Pricing is then resolved from `pricing_by_catalog_code[internal_exam_catalog_code]`.

No price, product id or arbitrary catalog code is accepted from the request.

## Pricing and product snapshots

Historical `order_items` remain the durable order-time evidence.

`product_snapshot` is server-owned and contains exact catalog identity plus source reference details.

`pricing_snapshot` is server-owned and contains at minimum:

- list unit amount,
- charged unit amount,
- discount amount,
- VAT basis points,
- pricing revision,
- pricing resolution timestamp.

`snapshot_hash` is SHA-256 over a deterministic canonical JSON representation of the product and pricing snapshots. Historical snapshots are never recomputed after configuration changes.

## Order sequence authority

Canonical DB-COM-005 requires:

`organization_commerce_order_sequences(organization_id, next_order_sequence)`

The frozen Stage4 history is immutable, therefore materialization is an isolated Stage-5 corrective defined in:

`specs/database/commerce-order-sequence-migration-extension.yml`

Table contract:

- `organization_id uuid PRIMARY KEY`,
- exact FK to `organizations.id` with RESTRICT semantics,
- `next_order_sequence bigint NOT NULL CHECK >= 1`.

Runtime allocation may never use application `MAX(order_sequence)+1`.

### Existing-organization initialization

The one-time migration backfill may derive the allocator pointer from already accepted order history:

`next_order_sequence = COALESCE(MAX(orders.order_sequence), 0) + 1`

This is migration initialization only; it is not runtime allocation and does not rewrite any existing Order number.

Every existing organization receives an allocator row.

### Organizations created after the corrective

Before first order placement, runtime may bootstrap a missing sequence row to `1` only after proving the organization has no existing orders. It then locks the exact allocator row `FOR UPDATE`.

If an allocator row is missing while orders already exist, runtime fails closed rather than reconstructing a sequence ad hoc.

### Allocation transaction

Under the exact allocator row lock:

1. reserve current `next_order_sequence`;
2. increment the allocator;
3. insert the new Order with the reserved value in the same transaction.

Gaps after rollback/retry are acceptable. Sequence reuse after committed order is forbidden.

## Order-create idempotency

Existing `ResourceIdempotency` remains the authority.

Operation keys:

- `license_orders.create`
- `exam_orders.create`

The idempotency claim occurs before business row locks.

Same key + same canonical request hash replays the original created Order.

Same key + different canonical request hash conflicts.

The request hash includes the normalized payment method and the exact requested product/quantity payload. Client prices never enter the hash because they are not accepted.

## Payment method and first attempt

`payment_method` is normalized to lowercase and must match:

`^[a-z0-9_.-]{2,64}$`

For authoritative total **greater than zero**, order placement creates exactly one provider-neutral pending `payments` attempt in the same transaction:

- `provider = normalized payment_method`,
- `provider_payment_id = NULL`,
- unpredictable public payment reference,
- amount/currency equal exact Order payable snapshot,
- status `pending`,
- no provider network I/O.

This is only local intent. It does not confirm payment and creates no `order_payment_settlements`.

Later payment initiation/reconciliation/provider flows keep their existing DB-COM-002 authority.

## Zero-total order

The request still validates `payment_method` because it is part of the existing API request contract, but no external payment attempt is created when authoritative total is zero.

In the same order-creation transaction:

- `zero_total_settled_at = now`,
- `booked_at = zero_total_settled_at`,
- no `payments` row is created,
- no `order_payment_settlements` row is created,
- one pending `order_fulfillments` row is created with `source_kind = zero_total` and null settlement payment id.

This preserves DB-COM-002 and DB-COM-003.

## Canonical create lock order

No provider/network I/O occurs in the transaction.

Order creation uses:

1. generic idempotency claim;
2. trusted pricing/config resolution;
3. begin DB transaction;
4. validate active exact catalog/product mapping;
5. ensure/bootstrap allocator row only under the missing-row rule above;
6. lock exact organization allocator row `FOR UPDATE`;
7. recheck exact catalog/product eligibility inside the transaction;
8. reserve/increment order sequence;
9. insert Order;
10. insert immutable OrderItems in deterministic catalog-item-id order;
11. for nonzero total insert the first pending Payment;
12. for zero total persist zero-total paid resolution and pending fulfillment;
13. verify the existing deferred DB order-total/snapshot guards;
14. commit;
15. return the canonical Order projection.

The new-Order flow does not enter fulfillment for nonzero orders before trusted payment settlement.

## Failure behavior

Fail closed when:

- current pricing configuration is missing/malformed,
- requested license product is inactive/missing,
- exact active license catalog mapping is zero or ambiguous,
- internal-exam catalog code is missing or resolves to wrong/inactive kind,
- pricing currencies differ inside one order,
- checked arithmetic overflows,
- allocator state is missing/inconsistent for an organization with existing orders.

No fallback to client values or historical snapshots is allowed.

## Isolated allocator corrective

The migration authority defines:

- root: `database/migrations/stage5/commerce-order-sequence`,
- node: `S5COM-TBL-ORGANIZATION-COMMERCE-ORDER-SEQUENCES`,
- phases: `preflight -> expand -> backfill -> validate`,
- shared PostgreSQL advisory lock: `[519662, 5001]`,
- explicit separate plan/implementation registry,
- default Laravel root migration discovery disabled,
- no automatic destructive down,
- Stage4 identities unchanged,
- existing formal-documents and social-identity Stage5 extensions unchanged.

The authority gate itself creates no table.

## Explicit non-scope

- provider network call: forbidden,
- provider webhook implementation: forbidden,
- payment confirmation: forbidden,
- client price/VAT/discount/total authority: forbidden,
- automatic fulfillment for nonzero order before settlement: forbidden,
- Stage4 mutation: forbidden,
- PKK/PWPW runtime: forbidden.

## Safe continuation after PASS

1. materialize `CORE-V1-STAGE5-COMMERCE-ORDER-SEQUENCE-001`;
2. exact-head CI/API and central closure;
3. fresh closure audit;
4. only then implement `license_orders.create` and `exam_orders.create`.
