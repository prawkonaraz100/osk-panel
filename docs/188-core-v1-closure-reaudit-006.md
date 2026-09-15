# 188. CORE-V1-CLOSURE-AUDIT-006 — repository closure re-audit

Data: 2026-09-14

**Status:** `AUDIT_COMPLETE_SOCIAL_SCHEMA_CORRECTIVE_REQUIRED`

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

The first audit commit itself was also validated:

- Implementation CI #534 / run `34853157294`: **5/5 PASS**
- API Contract Gate #422 / run `34853157233`: **PASS**
- PostgreSQL: **309 tests / 5537 assertions — PASS**
- deterministic restore: **121 -> 121 PASS**
- same schema fingerprint.

## HTTP differential from audit 005

Previous full repository audit:

`c168b1520e59c90feb3f2c73c409fa5eff55bc04`

Canonical required-operation registry is byte-identical:

`c36142e3af2615393347fadfec265af2eb610fb7`

Physical route authority is byte-identical:

`7547c38176c50653cfec8b1debe061e822a57bd3`

Therefore Social Authority closed **zero physical HTTP bindings**.

HTTP inventory remains:

- canonical required inline HTTP operations: **172**
- physical bindings present: **147**
- physical bindings missing: **25**
- frozen PKK/PWPW missing: **14**
- provider-specific payment webhook missing: **1**
- repo-actionable HTTP bindings missing: **10**

## Social authority result

`CORE-V1-AUTH-SOCIAL-AUTHORITY-001` resolves the application/security semantics for:

- `auth.social_redirect`
- `auth.social_callback`

However the closure audit found a physical database invariant gap that must be corrected before runtime implementation.

## Newly surfaced physical schema gap

Canonical database authority requires:

`specs/database/core-schema.yml#critical_constraints.social_provider_subject_unique`

with:

- table: `auth_social_accounts`
- unique columns: `[provider, provider_subject]`

The same authority also states:

`auth_social_accounts.unique_current_provider_subject: true`

But the executable Stage-4 identity migration tree does not materialize this invariant.

Verified absence:

- `MIG-IDX-IDENTITY` write-fence contains no `auth_social_accounts` index,
- `MIG-CK-IDENTITY` contains no social provider/subject key,
- `MIG-CON-IDENTITY` contains no equivalent constraint,
- `MIG-FK-IDENTITY` contains no equivalent boundary,
- `MIG-TRG-IDENTITY` contains no equivalent trigger,
- identity validate migrations contain no social provider/subject enforcement,
- `Stage4IdentityTriggerGuardsTest` contains no social subject uniqueness proof.

The existing Stage-4 migration files are frozen by their execution identity and must **not** be edited in place.

Application-only advisory locking is not accepted as a substitute for the canonical physical uniqueness invariant because it would protect only cooperating writers.

## Correct classification

### Social operations — schema-corrective blocked — 2

- `auth.social_redirect`
- `auth.social_callback`

Accepted application authority exists, but runtime must not start until the physical subject uniqueness invariant is materialized and validated by a new registered Stage-5 migration extension.

### Other authority-blocked HTTP operations — 8

Password recovery — 2:

- `auth.password_forgot`
- `auth.password_reset`

Student Progress — 1:

- `students.progress`

Commerce order creation — 2:

- `license_orders.create`
- `exam_orders.create`

Organization/settings — 3:

- `organization.update`
- `organization.settings.get`
- `organization.settings.update`

## Non-HTTP repository P1

Newly surfaced open non-HTTP P1: **1**

- missing physical enforcement of `social_provider_subject_unique`

Previously closed non-HTTP slices remain closed.

## Result

Repository completion: **false**

Open repo P0: **0**

Repo-actionable missing HTTP bindings: **10**

Implementation-ready missing HTTP bindings: **0**

Schema-corrective-blocked social HTTP bindings: **2**

Other authority-blocked HTTP bindings: **8**

Additional open non-HTTP repo P1: **1**

Result:

`AUDIT_COMPLETE_SOCIAL_SCHEMA_CORRECTIVE_REQUIRED`

## Next safe gate

`CORE-V1-STAGE5-SOCIAL-SUBJECT-UNIQUE-AUTHORITY-001`

The next gate must define an isolated Stage-5 migration extension that:

- does not mutate the frozen Stage-4 DAG or Stage-4 execution identity,
- preflights duplicate `(provider, provider_subject)` rows and fails closed if any exist,
- never auto-deletes, rewrites, or chooses a duplicate winner,
- installs a physical unique index/constraint on `auth_social_accounts(provider, provider_subject)`,
- validates the exact index after installation,
- uses the shared migration advisory lock and explicit registered execution identity,
- is not discoverable through default `php artisan migrate`,
- has no automatic destructive down,
- keeps PKK/PWPW frozen.

Only after that corrective is PASS may a new closure audit reclassify the two social HTTP operations as implementation-ready.
