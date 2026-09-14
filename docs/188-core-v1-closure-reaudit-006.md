# 188. CORE-V1-CLOSURE-AUDIT-006 — repository closure re-audit

Data: 2026-09-14

**Status:** `AUDIT_COMPLETE_TWO_IMPLEMENTATION_READY_SOCIAL_BINDINGS`

## Exact audited tree

Accepted Social Authority closure commit:

`ae828457041b543263d96a0c3f60b83e28d8b850`

Tree:

`850985e644d051af5a892dd4687b275b79eb8468`

Final closure evidence on that exact tree:

- Implementation CI #533 / run `34852165354`: **5/5 PASS**
- API Contract Gate #421 / run `34852165204`: **PASS**
- PostgreSQL: **309 tests / 5537 assertions — PASS**
- deterministic restore: **121 -> 121 PASS**
- restore schema fingerprint: `09c57afe2501c79a3a684c0a695b4619114f94ddc24c9bca33728d99c1abb155`
- `RESTORE_DRILL_HARNESS=PASS`
- backend Pint + PHPStan: **PASS**
- frontend quality: **PASS**
- contracts/traceability: **PASS**
- secret scan: **PASS**

## Differential proof from audit 005

Previous full repository audit:

`c168b1520e59c90feb3f2c73c409fa5eff55bc04`

The canonical required-operation registry is byte-identical:

- before: `c36142e3af2615393347fadfec265af2eb610fb7`
- after: `c36142e3af2615393347fadfec265af2eb610fb7`

The physical route authority is also byte-identical:

- before: `7547c38176c50653cfec8b1debe061e822a57bd3`
- after: `7547c38176c50653cfec8b1debe061e822a57bd3`

Therefore this authority tranche closed **zero physical HTTP bindings**.

The audit-005 -> audit-006 interval changes only:

- Social OAuth narrative authority,
- Social OAuth machine authority,
- Social OAuth OpenAPI authority annotations,
- authority contract test,
- central authority closure/provenance.

No runtime route/controller/service implementation was added.

## HTTP inventory

Canonical required inline HTTP operations: **172**

Required physical bindings present: **147**

Missing physical bindings total: **25**

External/frozen missing bindings:

- PKK/PWPW: **14**
- provider-specific payment webhook: **1**

Repo-actionable missing HTTP bindings:

`25 - 14 - 1 = 10`

Therefore the repo-actionable physical gap count remains **10**.

## Reclassification caused by Social Authority

`CORE-V1-AUTH-SOCIAL-AUTHORITY-001` is PASS and resolves the previously missing authority for exactly:

- `auth.social_redirect`
- `auth.social_callback`

Those two operations remain physically missing, but are now **implementation-ready**.

Their accepted authority requires:

- trusted server-side provider allowlist/config,
- OAuth2 authorization-code flow with PKCE S256,
- one-time server-side state bound to the framework session,
- safe local ReturnUrl authority,
- no provider-token persistence,
- existing-current-subject sign-in,
- authenticated existing-user linking,
- unauthenticated first-link only when both provider and local e-mail are verified,
- no auto-registration,
- no automatic re-link of historical revoked subject,
- no social principal for organization-managed learner identity.

## Remaining repo-actionable HTTP bindings — 10

### Implementation-ready — 2

- `auth.social_redirect`
- `auth.social_callback`

### Authority-blocked — 8

Identity/Auth — 2:

- `auth.password_forgot`
- `auth.password_reset`

Blocker: one-time password-reset lifecycle authority is still absent.

Student Progress — 1:

- `students.progress`

Blocker: product evidence exists, but canonical durable persistence/projection authority is not yet materialized.

Commerce order creation — 2:

- `license_orders.create`
- `exam_orders.create`

Blocker: current server-side pricing/VAT runtime authority is not yet complete; existing DB snapshots alone must not be used as current price authority.

Organization/settings — 3:

- `organization.update`
- `organization.settings.get`
- `organization.settings.update`

Blocker: full canonical contracts remain coupled to PKK/OSK-provider fields while PKK/PWPW is explicitly frozen.

## Frozen/external boundaries

PKK/PWPW missing bindings remain **14** and stay:

`FROZEN_UNTIL_EXPLICIT_UNFREEZE`

`payment_webhook.receive` remains provider-specific external authority and stays outside the repo-actionable count.

## Non-HTTP repository P1

Additional open repo-actionable non-HTTP P1: **0**

No changed file in this authority interval reopens Course Completion, Resource Asset UI, Stage-4 database closure, or other previously closed repository P1.

## Result

Repository completion: **false**

Open repo P0: **0**

Repo-actionable missing HTTP bindings: **10**

Implementation-ready missing HTTP bindings: **2**

Authority-blocked repo-actionable missing HTTP bindings: **8**

Additional open non-HTTP repo P1: **0**

Result:

`AUDIT_COMPLETE_TWO_IMPLEMENTATION_READY_SOCIAL_BINDINGS`

## Next safe gate

`CORE-V1-AUTH-SOCIAL-001`

Exact implementation scope is limited to:

- `GET /api/v1/auth/social/{provider}/redirect`
- `GET /api/v1/auth/social/{provider}/callback`

The implementation must not:

- add a migration,
- create social registration,
- persist provider access/refresh tokens,
- accept external ReturnUrls,
- auto-relink revoked provider subjects,
- link organization-managed learner identities,
- activate PKK/PWPW,
- change password-reset or Commerce authority.

After exact-head runtime closure, run `CORE-V1-CLOSURE-AUDIT-007`.
