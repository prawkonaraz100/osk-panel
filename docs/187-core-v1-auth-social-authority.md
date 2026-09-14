# 187. CORE-V1-AUTH-SOCIAL-AUTHORITY-001 — social sign-in authority

Data: 2026-09-14

**Status:** `IN_VALIDATION`

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
