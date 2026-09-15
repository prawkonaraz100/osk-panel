# 181. CORE-V1-AUTH-REGISTER-AUTHORITY-001 — registration authority

Data: 2026-09-14

**Status:** `PASS`

## Purpose

This gate closes the authority gap that blocked the required operation:

- `POST /api/v1/auth/register`
- requirement id: `auth.register`

It is an explicit `OWN_PRODUCT_DECISION` under `docs/10-gap-register.md`.

This authority gate does **not** implement the route yet. It proves a registration lifecycle that uses only already-materialized Stage-4 tables and existing RBAC/legal-document authority.

No schema or migration change is required.

## Existing physical authority

Registration can be materialized from the existing tables:

- `users`
- `user_password_management`
- `auth_login_identifiers`
- `organizations`
- `organization_settings`
- `organization_contact_addresses`
- `organization_memberships`
- `permissions`
- `permission_scope_options`
- `membership_permissions`
- `membership_permission_scopes`
- `legal_documents`
- `terms_acceptances`
- audit/domain/outbox infrastructure

The canonical Owner template and catalog version already exist in `specs/security/permissions.yml`:

- template: `Owner`
- catalog version: `core-v1-2026-09-05`

## Contract normalization required before runtime

The current `RegistrationRequest.address: string|null` cannot be mapped safely to the canonical structured address table.

Free-text parsing is forbidden and `organization_settings.preferences` is explicitly forbidden as business-address storage.

Therefore the implementation gate must replace that placeholder with an optional structured object:

`company_address`

When present, it contains:

- `street` — required
- `house_number` — required
- `unit_number` — optional
- `postal_code` — required
- `city` — required
- `country_code` — optional, default `PL`

The old free-text `address` field is superseded and must not be silently accepted or discarded.

The registration operation and path stay unchanged.

## Marketing-consent authority

The existing boolean `marketing_consent` must never be silently dropped.

A new request field is required by the implementation contract:

`marketing_consent_version`

Rules:

- when `marketing_consent=false`, `marketing_consent_version` must be absent/null and no marketing acceptance row is written,
- when `marketing_consent=true`, `marketing_consent_version` is required,
- the version must resolve to exactly one immutable `legal_documents` row with `document_type=marketing_consent`,
- the document must already be published and effective at command time,
- the exact document is recorded through append-only `terms_acceptances` in the same registration transaction.

Absence of a marketing acceptance means **no consent**.

This gate deliberately does not define withdrawal or future marketing-send eligibility. Until a separate withdrawal/eligibility authority exists, a historical registration acceptance must not be treated as perpetual permission to send marketing.

This decision only ensures the registration request is faithfully persisted rather than ignored.

## Terms authority

`accepted_terms_version` remains required.

It must resolve to exactly one `legal_documents` row where:

- `document_type=terms`,
- `version` exactly equals the request value,
- `published_at <= command_time`,
- `effective_from IS NULL OR effective_from <= command_time`.

Unknown, unpublished, future-effective or ambiguous terms fail closed.

One append-only `terms_acceptances` row is inserted for the exact accepted document in the same transaction.

## User identity authority

The new principal is a normal global self-service identity:

- `users.status=active`,
- `users.first_name` and `users.last_name` come from the request,
- `users.password_hash` is created only through Laravel `Hash::make`,
- plaintext password is never persisted or emitted to audit/domain/outbox.

The email is normalized exactly as the existing login flow expects:

- trim,
- lowercase,
- valid email,
- max 320,
- no whitespace ambiguity.

A single current `auth_login_identifiers` row is created with:

- `identifier_type=email`,
- normalized email,
- `is_primary_for_type=true`,
- `verified_at=null`,
- `revoked_at=null`.

Registration does not invent an e-mail verification lifecycle.

A duplicate current global login identifier rejects the whole command atomically.

## Password-management authority

Because registration creates a local password owned by the user, the initial password-management row is:

- `management_mode=self_service`,
- `managing_organization_id=null`,
- `credential_version=1`,
- `password_changed_at=command_time`.

This follows the existing credential invariant that a non-null `users.password_hash` requires a positive credential version.

The newly registered Owner is never `organization_managed`.

## Organization bootstrap

The same transaction creates:

- active `organizations` row,
- `organization_settings` row at version 1,
- optional `organization_contact_addresses` row only when `company_address` is present,
- active Owner `organization_memberships` row.

Request mapping:

- `organization_name -> organizations.name`
- `nip -> organizations.nip`
- `phone -> organizations.phone`
- structured `company_address -> organization_contact_addresses`

No PKK settings are created or activated by registration.

PKK/PWPW remains frozen.

## Owner RBAC bootstrap

The initial membership is the trusted organization-creation bootstrap exception already allowed by the Identity authority.

It must materialize the current `Owner` template from `specs/security/permissions.yml` at catalog version `core-v1-2026-09-05`.

The membership stores:

- `status=active`,
- `is_owner=true`,
- `role_template_code=Owner`,
- `role_template_catalog_version=core-v1-2026-09-05`,
- `version=1`,
- `authorization_version=1`.

For every known permission in the catalog:

- materialize exactly one `membership_permissions` decision,
- Owner-included permissions are `granted=true`,
- any future/nonincluded decision resolved by the selected template is `granted=false`,
- granted permissions receive exactly the scopes defined by the Owner template strategy,
- denied permissions receive no scope rows.

Runtime authorization never consults `role_template_code`; the materialized decisions remain authority.

The protected Owner baseline must be present before commit.

## Session behavior

Registration does **not** implicitly create an authenticated application session.

The user receives a created account/organization and authenticates through the existing `auth.login` flow.

This avoids inventing a second registration-specific session lifecycle.

## Atomicity

The command is one database transaction.

Any failure in:

- email uniqueness,
- terms resolution,
- marketing document resolution,
- address validation,
- password hashing/persistence,
- Owner permission materialization,
- protected Owner baseline,
- audit/domain/outbox recording,

rolls back the entire registration.

No orphan Organization, User, membership, legal acceptance or partial permission set may remain.

## Audit and event boundary

Implementation must add one allowlisted registration audit/domain event:

`auth.registration.completed`

The event is recorded only after the Owner membership exists, inside the same transaction.

Safe payload may contain identifiers and nonsecret state needed for provenance, but must not contain:

- password or password hash,
- full IP address,
- raw user agent,
- legal-document content,
- PKK data,
- secret values.

`terms_acceptances` retains its existing hashed/request metadata authority.

## Required negative evidence

Implementation must prove at minimum:

1. duplicate normalized e-mail leaves no partial Organization/User,
2. unknown or future terms version leaves no partial state,
3. `marketing_consent=true` without exact version is rejected,
4. unknown/future marketing version is rejected,
5. `marketing_consent=false` creates no marketing acceptance,
6. non-null legacy free-text `address` is rejected after contract normalization,
7. incomplete structured company address is rejected,
8. password plaintext never appears in DB/audit/domain/outbox,
9. password-management row is self-service with credential version 1,
10. Owner template is fully materialized and protected baseline is present,
11. no auth session is implicitly created,
12. PKK/PWPW tables/runtime are untouched.

## Closure effect after implementation

This authority gate itself closes no HTTP binding.

After a successful `CORE-V1-AUTH-REGISTER-001` implementation and exact-head closure audit:

- repo-actionable missing HTTP bindings should decrease from **12 to 11**,
- Identity/Auth missing bindings should decrease from **5 to 4**.

Those counts must be re-audited rather than assumed.

## Next gate

`CORE-V1-AUTH-REGISTER-001`

Scope:

- normalize `RegistrationRequest` as defined here,
- materialize the existing `auth.register` route/controller/service,
- add executable negative tests,
- add traceability,
- add registration audit policy,
- no schema/migration change,
- no password-reset/social OAuth work,
- no PKK runtime.


## Development sample-terms exception — 2026-09-15

User explicitly approved a temporary example Terms document so registration UI work can
continue before the production legal text is finalized.

The existing registration authority remains unchanged:

- `accepted_terms_version` is still required,
- the version must still resolve to exactly one published/effective
  `legal_documents` row,
- registration still writes the exact append-only acceptance,
- no legal-document validation bypass exists.

The non-production sample bootstrap adds:

- version: `sample-terms-v1`,
- content: `resources/views/legal/sample-terms-v1.blade.php`,
- local document URL: `/regulamin/sample-terms-v1`,
- development metadata endpoint:
  `GET /api/v1/development/sample/legal/terms/current`,
- seeder: `DevelopmentSampleDataSeeder`.

The sample document is clearly labelled as development-only and is enabled only through
`SAMPLE_DATA_ENABLED`. Enabling sample data in `APP_ENV=production` is forbidden.

This unblocks development of registration UI. Production registration still requires
a real approved Terms document/discovery policy before go-live.
