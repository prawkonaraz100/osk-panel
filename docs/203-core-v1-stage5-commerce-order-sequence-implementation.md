# 203. CORE-V1-STAGE5-COMMERCE-ORDER-SEQUENCE-001

Data: 2026-09-14

**Status:** `PASS`

## Scope

Materialize the approved isolated Stage-5 DB-COM-005 allocator corrective:

`organization_commerce_order_sequences(organization_id, next_order_sequence)`

No order-create HTTP binding is added by this gate.

## Immutable identities

Plan:

`6d03c38e47ba30d07a3a090d514ac09384e1ea5e668947b95eeaaa6fce49c019`

Execution:

`4dc74dce0acaf9904a013a08c7f85d0539ccb8af6fcff0cc690aae2629100554`

Root:

`database/migrations/stage5/commerce-order-sequence`

Node:

`S5COM-TBL-ORGANIZATION-COMMERCE-ORDER-SEQUENCES`

## Materialized phases

1. `preflight`
   - PostgreSQL only,
   - requires organizations and orders,
   - rejects non-positive, duplicate-per-organization or orphan historical order sequences,
   - rejects bigint exhaustion,
   - rewrites no existing Order.

2. `expand`
   - creates exactly two business columns,
   - organization UUID primary key,
   - exact RESTRICT FK to organizations,
   - next sequence bigint NOT NULL with >=1 check,
   - same-name wrong definition fails closed.

3. `backfill`
   - creates one allocator row for every existing organization,
   - initializes `next_order_sequence = COALESCE(MAX(existing order_sequence), 0) + 1`,
   - never overwrites an existing allocator row,
   - never updates an existing Order.

4. `validate`
   - validates exact PostgreSQL catalog shape,
   - proves every existing organization has an allocator row,
   - proves allocator pointer is greater than every accepted historical order sequence.

## Runtime boundary

The one-time migration may read MAX order history to initialize the allocator. Runtime order placement may not.

Future order-create runtime must allocate under the exact allocator row `FOR UPDATE`, increment the pointer and insert the Order in the same transaction.

## Execution boundary

- separate immutable plan and implementation registry,
- shared PostgreSQL advisory lock `[519662, 5001]`,
- explicit phase command only,
- no default root Laravel migration discovery,
- manual-review restart classification,
- no automatic destructive down.

## Preservation

Frozen Stage4 remains unchanged.

Existing Stage5 formal-documents and social-identity plan/execution identities remain unchanged.

## Explicit non-scope

- no `license_orders.create` binding,
- no `exam_orders.create` binding,
- no pricing runtime,
- no provider network I/O,
- no payment webhook,
- no PKK/PWPW runtime.

## Corrective history

- initial materialization: `d9fb542e096f243a972506450033496be0786b68`
- console formatting corrective: `fc6536580d2107d02e3f0142a44d88cbf8939907`

Accepted implementation tree:

`e200d1570a2afe27d3269d46bdd5493badd43eda`

## Exact-head validation evidence

- Implementation CI #570 / run `34900710998`: **5/5 PASS**
- API Contract Gate #464 / run `34900710973`: **PASS**
- PostgreSQL: **335 tests / 5874 assertions — PASS**
- deterministic restore: **122 -> 122 PASS**
- restore fingerprint: `f3cab286cc8f0aabef219971d90afe424a8dab694c47ade2f517a3d95970716d`
- `RESTORE_DRILL_HARNESS=PASS`
- backend Pint/PHPStan, frontend, contracts/traceability and secret scan: **PASS**

Migration validation proves:

- allocator plan identity: `6d03c38e47ba30d07a3a090d514ac09384e1ea5e668947b95eeaaa6fce49c019`
- allocator execution identity: `4dc74dce0acaf9904a013a08c7f85d0539ccb8af6fcff0cc690aae2629100554`
- nodes: **1**
- implemented nodes: **1**
- implemented steps: **4**
- Stage4 plan/execution identities unchanged
- formal-documents identities unchanged
- social-identity identities unchanged

The executable acceptance suite proves migration initialization from existing Order history:

- existing order sequences `3, 7` -> allocator pointer `8`
- organization with no orders -> allocator pointer `1`
- existing Order rows remain unchanged

## Closure effect

The DB-COM-005 non-HTTP allocator P1 is closed.

This gate closes **zero HTTP bindings**.

A fresh closure audit is required before `license_orders.create` or `exam_orders.create` runtime may be implemented.
