# CORE-V1-STAGE4-COMMERCE-LINEAGE-PREREQ-RECOVERY-001

Status: `IN_VALIDATION`

## Why this recovery gate exists

The global Stage-4 preflight prefix is closed PASS at **39/39**, but before entering the first write-fence tranche a physical prerequisite was re-audited against the frozen DB-COM-004 contract.

The accepted `MIG-TBL-ORDER_ITEMS` expand implementation is missing `order_items.license_product_id uuid NULL`, while the authoritative `MIG-CK-COMMERCE` node must later produce `UNIQUE (organization_id, id, license_product_id)`.

An older validation-only PR #78 had already diagnosed and proven this correction, but it was never clean-promoted and is stale relative to the accepted branch. This recovery gate reapplies only that physical prerequisite to the current accepted tree.

## Exact scope

- restore `license_product_id uuid NULL` to the canonical fresh-build `order_items`,
- make `MIG-CK-COMMERCE` preflight fail closed when the prerequisite column is absent,
- refresh exactly two migration SHA-256 values and Stage-4 execution identity,
- preserve **170 DAG nodes / 157 materialized nodes / 157 materialized steps / 39 preflight / 0 write-fence**.

No candidate key, index, FK, CHECK, trigger, projection, backfill or reconciliation is materialized here.

## Existing applied databases

An environment that already recorded the old OrderItem expand migration but lacks `license_product_id` is not silently upgraded by changing the canonical file. It must STOP before write-fence and receive an explicitly reviewed forward remediation. Candidate-key write-fence may not invent or backfill the column.

## Candidate authority

- plan identity: `d2fd6bc999dc2a5ee024e9b91bf00f5a5dad29575554a0ba42eba67107c23f10`
- previous execution identity: `d2da7eb3ccb082ca6b0106fbe4b1e543815c9236cc1a8bd62cf470c93459b660`
- candidate execution identity: `7cdea7410b15a1e1ef884e50d4e2a9ac519eb568569d140f82b19cc5deaa142e`
- `MIG-TBL-ORDER_ITEMS` SHA-256: `ed2b9b15cf5e6421b0f1ea215a5bca7538d2b97d237fea33b08d40c3c2f2385e`
- `MIG-CK-COMMERCE` preflight SHA-256: `5a079d585b26297b9950c540b05139e7fe4f159be70f8420aa3a8d887cdcc647`
- PKK provider runtime: **FROZEN_UNTIL_EXPLICIT_UNFREEZE**

## PASS requirements

Full Implementation CI **5/5**, PostgreSQL suite, deterministic restore, exact registry/hash validation, nullable PostgreSQL UUID proof for `order_items.license_product_id`, strict preflight prerequisite enforcement and proof that write-fence remains empty.

After PASS, return to **CORE-V1-STAGE4-CANDIDATE-KEYS-WRITE-FENCE-001**.
