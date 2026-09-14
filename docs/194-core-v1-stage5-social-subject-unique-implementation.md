# 194. CORE-V1-STAGE5-SOCIAL-SUBJECT-UNIQUE-001

Data: 2026-09-14

**Status:** `IN_VALIDATION`

## Scope

Materialize the already-approved isolated Stage-5 corrective for the missing canonical invariant:

`auth_social_accounts(provider, provider_subject)`

The corrective must be non-partial and therefore covers revoked historical rows as well as current rows.

## Immutable identities

Plan:

`85319b7c91cf9046e0ac8c00772aad141462e3897363b32d0ba222a50f73fe4d`

Execution:

`268c9c386667d28480c8ed5bf6fe83f812efd16dede03a6b606cb1ddf3bf2ef9`

Root:

`database/migrations/stage5/social-identity`

Node:

`S5SOC-IDX-AUTH-SOCIAL-PROVIDER-SUBJECT-UNIQUE`

## Materialized phases

1. `preflight`
   - requires PostgreSQL,
   - requires `auth_social_accounts`,
   - fails closed when any duplicate `(provider, provider_subject)` group exists,
   - performs no automatic remediation.

2. `write_fence`
   - creates `auth_social_accounts_provider_subject_unique`,
   - exact non-partial unique B-tree,
   - key order exactly `provider, provider_subject`,
   - same-name wrong definition fails closed rather than being replaced.

3. `validate`
   - proves exact PostgreSQL catalog shape,
   - requires unique/valid/ready index,
   - requires no predicate and no expressions,
   - checks duplicate groups remain absent.

## Execution boundary

The extension:

- has a separate plan and implementation registry,
- is not discoverable by default root `php artisan migrate`,
- executes only through `migration:stage5:social-identity:controlled`,
- uses the shared PostgreSQL advisory lock `[519662, 5001]`,
- records migration execution evidence,
- requires reviewed resume after interrupted/failed manual-review evidence,
- forbids automatic destructive down.

## Preservation

Frozen Stage4 remains exactly:

- plan `d2fd6bc999dc2a5ee024e9b91bf00f5a5dad29575554a0ba42eba67107c23f10`
- execution `82da84d3efb312d78b432ad6081491c04b03569a0f250722d6befc11ca233712`
- authority blob `ad5f2aa2e14ef248b95dd3dda0d1cbcb2e69d441`
- 170 nodes / 170 implemented / 261 steps

The existing formal-documents extension remains exactly:

- plan `34cada121f4fd4963b1461308fb517c50ee647005ce9421f4d1af40c80d44b99`
- execution `31704fcab61761aa9a952d824dc543a6f46e7a3717792d0a9349cefaaf57651f`

No Social OAuth HTTP runtime is implemented by this gate.

PKK/PWPW remains `FROZEN_UNTIL_EXPLICIT_UNFREEZE`.
