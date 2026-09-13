# CORE-V1-STAGE4-INTERNAL-EXAM-PURCHASE-ORDINAL-PREREQ-RECOVERY-001

Status: `IN_VALIDATION`

## Why this corrective gate exists

The candidate-key write-fence tranche is closed PASS, but the next authoritative index write-fence requires the frozen paid Internal Exam purchase provenance shape.

The accepted fresh-build `internal_exam_inventory_entries` table is missing `source_order_item_grant_ordinal integer NULL`, even though DB-COM authority requires every paid purchased exam unit to retain an immutable `source_order_item_id + source_order_item_grant_ordinal` tuple and `MIG-IDX-COMMERCE` must later install the corresponding partial unique index.

The existing index preflight tolerated the missing column. That is not safe once write-fence is the next phase.

## Exact scope

- restore `internal_exam_inventory_entries.source_order_item_grant_ordinal integer NULL` to the canonical fresh-build shape,
- make the Internal Exam purchase-ordinal check in `MIG-IDX-COMMERCE` fail closed when the column is absent,
- refresh exactly two migration SHA-256 values and Stage-4 execution identity,
- preserve **170 DAG nodes / 157 materialized nodes / 165 materialized steps / 39 preflight / 8 write-fence**,
- do not materialize any index write-fence step in this corrective gate.

## Existing applied databases

An environment that already recorded the old Internal Exam Inventory expand migration but lacks the ordinal column must STOP before index write-fence and receive an explicitly reviewed forward remediation. This gate corrects canonical fresh-build authority; it does not silently mutate already-applied databases.

## Candidate authority

- plan identity: `d2fd6bc999dc2a5ee024e9b91bf00f5a5dad29575554a0ba42eba67107c23f10`,
- previous execution identity: `84108f592c5cdff50cea4316ce05c5ad53db61e177b69c8916e04eed54214429`,
- candidate execution identity: `d3d460992bfe56a0c3828689bffffaf842cc9b88bd103a5e71b5c6082ba00cd4`,
- `MIG-TBL-INTERNAL_EXAM_INVENTORY_ENTRIES` SHA-256: `aa014f17de518c3ac0e3dfc67300c99bb1cdcf74d9ef4953d80cecbf35098998`,
- `MIG-IDX-COMMERCE` preflight SHA-256: `1a564d41d476927e626be4d1f4e3306548b0990ae21b8a050661b839cd158fb3`,
- PKK provider runtime: **FROZEN_UNTIL_EXPLICIT_UNFREEZE**.

## PASS requirements

Full Implementation CI **5/5**, PostgreSQL suite, deterministic restore, exact registry/hash validation, nullable PostgreSQL integer proof for `internal_exam_inventory_entries.source_order_item_grant_ordinal`, fail-closed missing-column proof for `MIG-IDX-COMMERCE`, and proof that write-fence remains exactly **8 steps**.

After PASS, return to **CORE-V1-STAGE4-INDEXES-WRITE-FENCE-001**.
