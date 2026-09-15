# 232. LICENSE-PURCHASE-UI-001 closure

Data: 2026-09-15

**Gate:** `LICENSE-PURCHASE-UI-001`  
**Status:** `PASS_ACCEPTED`

## Accepted scope

PR #125 materialized `/licencje/wykup` and
`resources/js/modules/LearningAccess/LicensePurchaseWorkspace.vue` on top of
accepted commerce runtime.

The UI:

- reads products/current pricing projection from `GET /api/v1/license-products`,
- supports multiple license variants in one order,
- submits only `product_id`, integer `quantity` and `payment_method`,
- never sends browser-owned price, VAT, discount or total fields,
- treats browser totals as previews from the server projection only,
- displays the authoritative total returned by `POST /api/v1/license-orders`,
- labels `sample_data=true` pricing,
- fails closed for a product without current pricing authority,
- does not invent a self-service maximum,
- keeps provider-specific redirect/callback behavior deferred,
- preserves the separation between purchase, payment confirmation, inventory grant,
  assignment and activation,
- leaves PKK/PWPW frozen.

## Candidate validation

Exact candidate head:

`394c24d73eb7514e68f4996342b769f4a35b432c`

Implementation CI #737 / run `35014533726`: **5/5 PASS**.

Runtime evidence:

- PostgreSQL: **386 tests / 6390 assertions — PASS**,
- deterministic restore: **122 -> 122 PASS**,
- schema fingerprint:
  `f3cab286cc8f0aabef219971d90afe424a8dab694c47ade2f517a3d95970716d`.

The first validation run exposed only one Pint `single_quote` style violation in the
new PHP contract test. No product/runtime semantics were changed to fix it.

## Accepted main evidence

PR #125 was promoted to `main` by clean fast-forward.

Accepted runtime SHA:

`394c24d73eb7514e68f4996342b769f4a35b432c`

Accepted Implementation CI #738 / run `35015499860`: **6/6 PASS**.

- backend-quality: PASS,
- frontend-quality: PASS,
- contracts-and-traceability: PASS,
- secret-scan: PASS,
- runtime-tests-and-migrations: PASS,
- release-artifact: PASS,
- PostgreSQL: **386 tests / 6390 assertions — PASS**,
- deterministic restore: **122 -> 122 PASS**,
- immutable artifact ID: `10414769162`,
- artifact name:
  `osk-panel-394c24d73eb7514e68f4996342b769f4a35b432c`,
- release archive SHA-256:
  `879fbd07fdeb0297c833b748e9eab4f61d3141714dc028f2ca5de96211d86bc0`,
- GitHub artifact ZIP SHA-256:
  `5aaa069688c25d4b500852bf42fc3589fe606d106c8c4835a6f1132908b2861f`.

This closure-only synchronization changes documentation/status evidence only and does not change runtime behavior, API bindings or database schema.

## Remaining sequence

1. Internal Exam Purchase UI,
2. browser E2E golden paths,
3. E2E-driven fixes,
4. final immutable release candidate,
5. retarget issue #106 to the final release SHA and artifact hash,
6. collect production target evidence.

Production registration still requires real published/versioned Terms authority.
Provider-specific payment integration remains an external/deferred boundary.
PKK/PWPW remains `FROZEN_UNTIL_EXPLICIT_UNFREEZE`.
