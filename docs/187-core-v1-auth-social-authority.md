# 187. CORE-V1-AUTH-SOCIAL-AUTHORITY-001 — social sign-in authority

Data: 2026-09-14

**Status:** `PASS`

This gate defines the exact authority for:

- `GET /api/v1/auth/social/{provider}/redirect`
- `GET /api/v1/auth/social/{provider}/callback`

It does not implement the routes yet and it does not add schema.

Existing Stage-4 authority already provides `auth_social_accounts`, `auth_login_identifiers`, `user_password_management`, and `auth_sessions`. Existing session runtime already provides local ReturnUrl validation and application-session creation.

A repository check found an important current constraint: normal registration creates the local e-mail login identifier with `verified_at = null`. Therefore public social callback may not treat a merely matching unverified local e-mail as ownership proof.

## Provider boundary

The provider path is only an allowlisted selector.

Provider endpoints and deployment credentials come only from trusted server configuration. Missing or disabled configuration fails closed.

Redirect uses a one-time random state with a ten-minute TTL, bound to the framework session and provider, plus PKCE S256. The validated local ReturnUrl and initiation mode are stored server-side with the state.

## Two initiation modes

### Authenticated linking

If redirect starts from an already active application session, state records the initiating User.

Callback may attach a never-before-linked provider subject to exactly that active User after rechecking:

- same framework session,
- active application auth session,
- active User,
- provider subject has no current or historical link,
- password-management mode is not `organization_managed`.

Provider e-mail equality is not required in this branch because control of the existing local authenticated session is the local-account proof.

Successful linking preserves the current application session; it does not create a second auth session.

### Sign-in

If redirect starts without an active application session:

- an existing current provider+subject link may authenticate its active User;
- a historically revoked provider+subject is never automatically re-linked;
- a never-linked subject may be linked by e-mail only when the provider e-mail is verified **and** the matching current local e-mail identifier is already locally verified.

Because normal registration currently leaves `verified_at = null`, this verified-email branch is explicitly **not** social signup and is not assumed to cover ordinary newly registered accounts.

If no safe target exists, no User or Organization is created. The callback returns only a safe local error redirect.

## Callback security

Callback atomically consumes state once.

Missing, expired, mismatched, or replayed state is rejected. Provider errors consume state without starting the code exchange. Provider network I/O occurs outside database transactions.

Provider response material is normalized to a stable subject plus optional verified e-mail. Provider tokens and raw profile payloads are transient and are not persisted, logged, or copied into audit/outbox payloads.

An existing current provider subject is authoritative and may not be remapped by a changed provider e-mail.

## Session handoff

For sign-in, after identity resolution:

- an existing application session is revoked when being replaced,
- the framework session is regenerated,
- `AuthSessionService::startSession` creates the application auth session,
- exactly one active membership may be selected automatically,
- zero or multiple active memberships produce null tenant context,
- redirect uses only the state-bound validated local ReturnUrl.

For authenticated linking, the existing session is preserved.

## Preservation

This authority does not:

- add a table or migration,
- change password-reset semantics,
- create social auto-registration,
- persist OAuth provider tokens,
- trust an unverified local e-mail for public linking,
- re-link revoked subjects automatically,
- allow organization-managed learner identities to gain a social principal,
- allow external/open ReturnUrls,
- activate PKK/PWPW,
- change payment-provider behavior.

Candidate validation requires full Implementation CI, API Contract Gate, PostgreSQL runtime, deterministic restore, Pint/PHPStan, frontend, contracts/traceability, and secret scan.

## Exact-head validation evidence

Authority candidate ancestry:

- initial provider-safe authority: `22ee9272a05d02e223a75f2c63779634a9a7ce3d`
- authenticated-link correction: `7ff20236b620a22a07c4bd56e916c5d894cc370b`
- standalone contract-test path corrective / exact accepted authority head: `df4b67c10611ce48f7b39276bc957a69393d3a71`

Exact accepted authority tree:

`c1ceb82ef27d76eaa9ef64e6b49ea4bc449d8871`

Validation on the exact accepted authority head:

- Implementation CI #530 / run `34851280825`: **5/5 PASS**
- API Contract Gate #418 / run `34851280784`: **PASS**
- PostgreSQL: **309 tests / 5537 assertions — PASS**
- deterministic restore: **121 -> 121 PASS**
- restore schema fingerprint: `09c57afe2501c79a3a684c0a695b4619114f94ddc24c9bca33728d99c1abb155`
- `RESTORE_DRILL_HARNESS=PASS`
- backend Pint + PHPStan: **PASS**
- frontend quality: **PASS**
- contracts/traceability: **PASS**
- secret scan: **PASS**

The failed predecessor #529 was caused only by using Laravel `base_path()` inside a standalone PHPUnit test. No authority semantics changed in that corrective commit.

## Closure effect

This authority gate closes **zero physical HTTP bindings**.

It makes exactly two still-missing canonical operations eligible for implementation:

- `auth.social_redirect`
- `auth.social_callback`

A fresh repository closure audit is required before runtime implementation. The audit must preserve the physical-binding count until routes are actually materialized and must not reclassify password reset, Student Progress, Commerce order creation, Organization/settings, PKK/PWPW, or the payment webhook without independent authority.

## Next gate

`CORE-V1-CLOSURE-AUDIT-006`

