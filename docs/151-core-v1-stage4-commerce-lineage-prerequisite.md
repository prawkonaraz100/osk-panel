# CORE-V1-STAGE4-COMMERCE-LINEAGE-PREREQ-001

Status: **IN VALIDATION**

## Why this prerequisite exists

The Stage-4 closure sequence reached the eight authoritative candidate-key nodes after all table nodes had been materialized.

Before materializing `MIG-CK-COMMERCE`, the physical `order_items` table was compared against the already-closed DB-COM-001 / DB-COM-004 commerce authority. That comparison found one executable-schema omission:

`order_items.license_product_id`

The field is required to support the exact purchase lineage target:

`license_inventory_entries(organization_id, source_order_item_id, license_product_id)`
→
`order_items(organization_id, id, license_product_id)`.

The candidate-key DAG already declares:

`order_item_candidate_key_org_id_license_product`.

Creating that UNIQUE target without the required column is impossible. Adding the column inside a candidate-key node would mix an expand/table-shape correction into a `candidate_key` node and would violate the authoritative node type and phase boundary.

## Exact correction

This prerequisite corrects the already-registered pre-go-live implementation of:

`MIG-TBL-ORDER_ITEMS`

by adding exactly:

`license_product_id uuid NULL`

to the canonical `CREATE TABLE order_items` shape.

No new Stage-4 DAG node and no new Stage-4 implementation step is created.

Before and after this prerequisite:

- Stage-4 DAG nodes: **170**
- materialized nodes: **118**
- materialized steps: **118**
- plan identity: unchanged
- authority blob: unchanged

Only the implementation file SHA-256 and therefore the Stage-4 execution identity change.

Corrected execution identity candidate:

`d238e4aab3a4364aef855d8f79d765083a05d3757995c6353386306a79e19b1c`

## Why the column is nullable

The bounded commerce authority distinguishes product kinds:

- `license` → exact `license_product_id` required by later constraints,
- `internal_exam` → `license_product_id = NULL`,
- `generic_service` → `license_product_id = NULL`.

The candidate-key prerequisite therefore must not invent a global NOT NULL contract.

The later constraint/index/FK nodes own the product-kind matrix and exact relational enforcement. This prerequisite only restores the missing physical column needed by those future nodes.

## Existing databases that already applied the previous migration file

The controlled migration executor records migration names. Therefore an environment that has already applied the earlier `2026_09_10_001010_create_order_items_table` will not automatically rerun the modified file merely because its repository hash changed.

This gate does **not** hide that fact.

Safety rule:

- a fresh/rebuilt Stage-4 database must contain `order_items.license_product_id`,
- a previously applied database missing the column is **not** silently considered compatible,
- the upcoming `MIG-CK-COMMERCE` preflight must explicitly verify the prerequisite column and STOP if it is absent,
- production or durable pre-existing data may only be advanced after an explicitly reviewed forward remediation for that environment,
- blind mutation inside candidate-key write-fence is forbidden.

There is no evidence in this gate that authorizes rewriting already-applied production migration history.

## Boundary preserved

This prerequisite does **not** materialize:

- candidate keys,
- secondary indexes,
- foreign keys,
- checks,
- exclusion constraints,
- triggers,
- projections.

In particular it does not create any of the five `MIG-CK-COMMERCE` UNIQUE targets.

## Required proof

PASS requires:

1. canonical Stage-4 plan remains 170 nodes,
2. materialized state remains 118 nodes / 118 steps,
3. `order_items.license_product_id` exists as nullable PostgreSQL UUID on a fresh controlled Stage-4 build,
4. no `MIG-CK-COMMERCE` UNIQUE target is materialized,
5. implementation registry hash matches the corrected file,
6. execution identity equals `d238e4aab3a4364aef855d8f79d765083a05d3757995c6353386306a79e19b1c`,
7. full PostgreSQL suite passes,
8. deterministic restore drill passes,
9. Stage-5 formal-document authority remains unchanged,
10. API contract remains 187/173/14,
11. PKK provider runtime remains frozen.

## Next step after PASS

Return to:

`CORE-V1-STAGE4-CANDIDATE-KEYS-001`

and materialize the exact eight candidate-key nodes with only their authoritative phases:

`preflight → write_fence`.

Candidate-key preflight must include an explicit prerequisite-column check for `order_items.license_product_id`.
