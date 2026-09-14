# 184. CORE-V1-CLOSURE-AUDIT-004 — repository closure re-audit

Data: 2026-09-14

**Status:** `AUDIT_COMPLETE_ONE_IMPLEMENTATION_READY_SLICE`

## Exact audited tree

Accepted audit parent:

`2ff5b842dfdb3ada88befe28b1942434a09ae9bf`

This tree contains:

- closed `CORE-V1-AUTH-REGISTER-001`,
- closed `CORE-V1-BULK-CREDENTIAL-RESET-AUTHORITY-001`.

Final exact-head evidence:

- Implementation CI #521 / run `34841002304`: **5/5 PASS**
- API Contract Gate #406 / run `34841002311`: **PASS**
- PostgreSQL: **300 tests / 5447 assertions — PASS**
- deterministic restore: **121 -> 121 PASS**
- restore schema fingerprint: `09c57afe2501c79a3a684c0a695b4619114f94ddc24c9bca33728d99c1abb155`
- backend Pint + PHPStan: **PASS**
- frontend quality: **PASS**
- contracts/traceability: **PASS**
- secret scan: **PASS**

## Differential inventory proof

Baseline audit 003 tip:

`1feeefa99b68bc751cb971714137a9911d16c177`

Required-operation registry SHA is unchanged across audit 003 -> current tree:

- before: `c36142e3af2615393347fadfec265af2eb610fb7`
- after: `c36142e3af2615393347fadfec265af2eb610fb7`

Therefore canonical required inline HTTP operations remain **172**.

Routing changed from:

- `235a96b26a70b120efb9026b011c7e8040f7bdad`

to:

- `93d5b6ec5f8784926e6eb0cfabda7e2adc1553f2`

The route diff adds the physical `POST /api/v1/auth/register` binding and does not add a bulk credential document runtime route.

No other missing HTTP operation gained a physical runtime binding in this interval.

## HTTP inventory

Previous audit 003:

- required physical bindings present: **145**
- missing physical bindings total: **27**
- frozen PKK missing: **14**
- provider-specific payment webhook missing: **1**
- repo-actionable missing: **12**

This audit:

- canonical required inline HTTP operations: **172**
- required physical bindings present: **146**
- missing physical bindings total: **26**
- frozen PKK/PWPW missing bindings: **14**
- provider-specific payment webhook missing bindings: **1**
- repo-actionable HTTP bindings missing: **11**

Therefore:

`26 - 14 - 1 = 11`

## Closed since audit 003

### auth.register

`CORE-V1-AUTH-REGISTER-001` closed one physical binding.

The implementation uses the reviewed registration authority and existing Stage-4 schema. It does not activate PKK/PWPW.

## Reclassified since audit 003

### license_credentials.bulk_pdf

The operation remains physically missing, but its former contract blocker is closed by `CORE-V1-BULK-CREDENTIAL-RESET-AUTHORITY-001`.

The canonical request now transports per-target expected credential version for reset targets and publishes the required read-only version information.

Status changes from:

`BLOCKED_RESET_BRANCH_MISSING_EXPECTED_CREDENTIAL_VERSIONS`

to:

`IMPLEMENTATION_READY_DB_LIC_004_TRANSPORT_AUTHORITY_CLOSED`.

This is the **only** implementation-ready missing HTTP binding on the audited tree.

## Remaining repo-actionable HTTP bindings — 11

### Identity/Auth — 4

Still blocked:

- `auth.password_forgot`
- `auth.password_reset`
- `auth.social_redirect`
- `auth.social_callback`

Registration is no longer missing.

### Student Progress — 1

Still blocked:

- `students.progress`

Reason remains missing online-learning question/test/handbook/lecture progress authority.

### Commerce order creation — 2

Still blocked:

- `license_orders.create`
- `exam_orders.create`

Reason remains missing canonical current server-side price/VAT authority.

### Learning credential bulk PDF — 1

Implementation-ready:

- `license_credentials.bulk_pdf`

The reset concurrency/permission/secret policy is now fully specified by DB-LIC-004 plus the synchronized HTTP transport authority.

### Organization/settings — 3

Still blocked by frozen PKK-coupled canonical fields:

- `organization.update`
- `organization.settings.get`
- `organization.settings.update`

## Non-HTTP repository P1

- Course Completion: **CLOSED**
- Resource Asset UI: **CLOSED**
- additional open non-HTTP P1: **0**

## Result

Repository completion: **false**

Open repo P0: **0**

Repo-actionable missing HTTP bindings: **11**

Implementation-ready missing bindings: **1**

Blocked missing bindings: **10**

The sole next safe implementation gate is:

`CORE-V1-BULK-CREDENTIAL-RESET-001`

No PKK/PWPW runtime may be touched.

## Next gate

Implement `license_credentials.bulk_pdf` exactly under:

- `docs/183-core-v1-bulk-credential-reset-authority.md`,
- `specs/design/bulk-credential-reset.yml`,
- `specs/database/licenses-learning-access.yml#DB-LIC-004`.

The implementation must fail closed on permissions, expected versions, duplicate-user version disagreement and any inability to render the full secret-bearing PDF before password mutation.
