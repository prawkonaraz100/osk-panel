# 194. CORE-V1-STAGE5-SOCIAL-SUBJECT-UNIQUE-001

Data: 2026-09-14

**Status:** `PASS`

## Scope

Materialize the already-approved isolated Stage-5 corrective for the missing canonical invariant:

`auth_social_accounts(provider, provider_subject)`

The corrective must be non-partial and therefore covers revoked historical rows as well as current rows.

## Immutable identities

Plan:

`85319b7c91cf9046e0ac8c00772aad141462e3897363b32d0ba222a50f73fe4d`

Execution:

`b4a73588390d837fbb460d374114585064445462ddcba667d3919c7052f76cd0`

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

## Corrective history

- initial materialization: `dceadadb247724433807369fa04d18ecc77c617c`
- console formatting corrective: `57db12f29f7c6ac7be94b192fff7819a92b2c79a`
- FoundationSchema facade import corrective: `0064641b99769cd856e59567630b7e40297d1bf8`
- executable migration import corrective: `6d660c30d30efb2bf47d8c2b0fb4af86867aa358`

Accepted implementation tree:

`46f21f07acfae18ff28bd06f595cf7f9d3fe716c`

The last corrective changed executable migration content only, therefore the plan identity remained stable while the execution identity correctly changed from the failed executable identity to:

`b4a73588390d837fbb460d374114585064445462ddcba667d3919c7052f76cd0`

## Exact-head validation evidence

- Implementation CI #552 / run `34876371311`: **5/5 PASS**
- API Contract Gate #443 / run `34876371299`: **PASS**
- PostgreSQL: **319 tests / 5637 assertions — PASS**
- deterministic restore: **121 -> 121 PASS**
- restore fingerprint: `09c57afe2501c79a3a684c0a695b4619114f94ddc24c9bca33728d99c1abb155`
- `RESTORE_DRILL_HARNESS=PASS`
- backend Pint/PHPStan, frontend, contracts/traceability and secret scan: **PASS**

Migration validation output proves:

- social plan identity: `85319b7c91cf9046e0ac8c00772aad141462e3897363b32d0ba222a50f73fe4d`
- social execution identity: `b4a73588390d837fbb460d374114585064445462ddcba667d3919c7052f76cd0`
- nodes: **1**
- implemented nodes: **1**
- implemented steps: **3**
- frozen Stage4 plan/execution identities unchanged
- existing formal-documents plan/execution identities unchanged

The executable test suite proves the exact non-partial PostgreSQL unique B-tree exists and rejects duplicate provider-subject pairs even across revoked history.

## Closure effect

The non-HTTP P1 `social_provider_subject_unique_physical_enforcement_missing` is closed.

This gate closes **zero HTTP bindings**. A fresh closure audit must reclassify `auth.social_redirect` and `auth.social_callback`; it must not implement them implicitly.
