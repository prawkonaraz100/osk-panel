# 182. CORE-V1-AUTH-REGISTER-001 — closure

Data: 2026-09-14

**Status:** `PASS`

## Exact scope

This gate materializes one previously missing canonical HTTP binding:

- `POST /api/v1/auth/register`
- requirement id: `auth.register`

The implementation follows the accepted authority in:

- `docs/181-core-v1-auth-register-authority.md`
- `specs/design/auth-registration.yml`

No database schema or migration change was introduced.

The existing OpenAPI registration request was normalized to the already-materialized Stage-4 domain model; the operation/path itself was not replaced.

PKK/PWPW runtime remains frozen.

## Registration transaction

One public registration transaction now creates:

- one active global User,
- one primary current e-mail login identifier,
- one self-service password-management authority at credential version 1,
- one active Organization,
- Organization Settings version 1,
- optional structured Organization contact address,
- one active Owner membership,
- the fully materialized current Owner permission/scope snapshot,
- exact append-only legal-document acceptance for Terms,
- exact append-only marketing-consent acceptance only when consent is explicitly true and versioned,
- one redacted `auth.registration.completed` audit/domain/outbox event.

No authenticated application session is created implicitly.

## Contract normalization

The unsafe placeholder:

`address: string|null`

was removed from `RegistrationRequest`.

Registration now accepts optional structured:

`company_address`

with exact canonical fields:

- street,
- house number,
- optional unit number,
- postal code,
- city,
- optional two-letter country code.

Free-text address parsing and business-address storage in preferences are not used.

The marketing request now also carries optional `marketing_consent_version`.

Runtime rules are fail-closed:

- consent `true` requires an exact effective/published `marketing_consent` legal-document version,
- consent `false` creates no marketing acceptance and forbids a non-null version.

A historical acceptance is evidence of the registration action only; this gate does not invent later withdrawal or marketing-send eligibility policy.

## Identity and password authority

The e-mail is trimmed and normalized to lowercase before persistence.

Duplicate current global identifiers reject registration atomically.

The local password is stored only as a Laravel password hash.

The initial `user_password_management` row is exactly:

- `management_mode=self_service`,
- `managing_organization_id=NULL`,
- `credential_version=1`,
- `password_changed_at=command_time`.

Plaintext password is not stored in database, audit, domain-event or outbox payload.

No e-mail verification lifecycle was invented.

## Owner RBAC bootstrap

The new Organization receives one active Owner membership using:

- template `Owner`,
- catalog version `core-v1-2026-09-05`.

The implementation resolves the current permission catalog and materializes one granted decision for every known core permission because the Owner template strategy is:

`all_core_permissions_known_in_this_catalog_version`.

Each granted permission receives the safe Owner scope selected from its canonical scope profile:

- `organization` when that scope is allowed,
- otherwise `own` for own-only permissions.

Runtime authorization continues to use the materialized permission/scope rows, not the role-template label.

The protected Owner baseline is verified before commit.

## Atomic failure behavior

The transaction rolls back completely when any registration prerequisite fails.

Executable tests prove:

- duplicate normalized e-mail leaves no second Organization/User/membership,
- unknown or future Terms versions leave no partial state,
- future Marketing Consent document versions leave no partial state,
- marketing consent cannot be true without a version,
- a marketing version cannot be supplied while consent is false,
- the superseded free-text `address` field is rejected,
- incomplete structured address is rejected,
- false marketing consent writes only the Terms acceptance,
- registration does not create an auth session,
- PKK integration state is not touched.

## Executable evidence

Authority head:

`ea079994384c501b37f28683b446bebe754d8ed9`

Authority evidence:

- Implementation CI #517 / run `34835887772`: **5/5 PASS**
- API Contract Gate #400 / run `34835887958`: **PASS**
- PostgreSQL: **295 tests / 5380 assertions — PASS**
- deterministic restore: **121 -> 121 PASS**
- restore schema fingerprint: `09c57afe2501c79a3a684c0a695b4619114f94ddc24c9bca33728d99c1abb155`

Exact implementation head:

`3a42a75152dde9ae32dc24d126f454080a6f554d`

Implementation evidence:

- Implementation CI #518 / run `34836631741`: **5/5 PASS**
- API Contract Gate #401 / run `34836627118`: **PASS**
- API Contract Gate #402 / run `34836631751`: **PASS**
- PostgreSQL: **300 tests / 5447 assertions — PASS**
- deterministic restore: **121 -> 121 PASS**
- restore schema fingerprint: `09c57afe2501c79a3a684c0a695b4619114f94ddc24c9bca33728d99c1abb155`
- backend Pint + PHPStan: **PASS**
- frontend quality: **PASS**
- contracts/traceability: **PASS**
- secret scan: **PASS**

The registration implementation therefore added five runtime tests and 67 assertions over the accepted authority baseline.

## Preservation

This gate does not:

- add or alter a database table,
- add a migration,
- create a password-reset token authority,
- add social OAuth provider/config/state behavior,
- invent online-learning Student Progress data,
- invent current server-side price/VAT authority,
- change bulk credential-reset concurrency semantics,
- implement or partially activate frozen PKK/PWPW behavior,
- create an implicit authenticated session after registration.

## Closure effect

The prior full closure audit reported **12 repo-actionable missing physical HTTP bindings**.

This gate materializes exactly one of those bindings:

- `auth.register`

Derived count before the next full repository audit:

- repo-actionable missing HTTP bindings: **11**
- Identity/Auth missing bindings: **4**

The four remaining Identity/Auth bindings are still separately blocked:

- `auth.password_forgot`
- `auth.password_reset`
- `auth.social_redirect`
- `auth.social_callback`

The derived count must be confirmed by a fresh repository closure re-audit.

## Next safe gate

`CORE-V1-CLOSURE-AUDIT-004`

The audit must recompute the exact accepted tree and confirm:

- `auth.register` is physically bound,
- repo-actionable HTTP missing count is 11 if no concurrent binding appeared,
- no additional non-HTTP P1 reopened,
- all remaining missing bindings keep their existing authority/freeze classification unless the exact tree proves a change.
