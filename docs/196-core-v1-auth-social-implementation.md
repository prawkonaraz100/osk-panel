# 196. CORE-V1-AUTH-SOCIAL-001

Data: 2026-09-14

**Status:** `PASS`

## Scope

Materialize exactly:

- `auth.social_redirect`
- `auth.social_callback`

Routes:

- `GET /api/v1/auth/social/{provider}/redirect`
- `GET /api/v1/auth/social/{provider}/callback`

## Provider boundary

Providers come only from trusted server configuration under `services.social.providers`.

The path provider value is a selector only. An enabled provider must have:

- authorization URL,
- token URL,
- userinfo URL,
- configured redirect URI,
- deployment-injected client id/secret,
- explicit scopes.

Production provider URLs must be HTTPS.

No provider SDK is required.

## Redirect flow

The runtime:

- validates `return_url` through existing `AuthSessionService.safeReturnUrl`,
- defaults local return to `/`,
- detects an active application session to choose `authenticated_link` vs `sign_in`,
- generates at least 32 random bytes for state,
- stores state only by SHA-256 key in server-side cache,
- binds provider, a SHA-256 hash of a 32-byte nonce stored only in the framework session, local return URL, mode, initiating user/session, PKCE verifier and expiry,
- uses a 600-second TTL,
- uses PKCE S256.

## Callback flow

The callback atomically claims state with a cache lock plus get/delete.

State is consumed before provider error handling. Provider mismatch, framework-session mismatch, expiry, replay or missing state fail closed.

Authorization-code exchange and userinfo retrieval occur before any database transaction.

Transient provider material is never written to:

- database,
- framework session,
- audit/outbox,
- logs by this runtime.

## Identity rules

Current non-revoked `(provider, provider_subject)` is authoritative.

Historical revoked subject links are never automatically relinked.

Authenticated linking:

- requires the same framework session,
- requires the initiating auth session and user to remain active,
- may link only the initiating user,
- rejects organization-managed identity,
- does not require provider e-mail matching,
- preserves the existing application session.

Unauthenticated first-link:

- never creates a user or organization,
- requires a provider-verified e-mail,
- requires exactly one current locally verified matching e-mail identifier,
- requires an active local user,
- rejects organization-managed identity.

Normal registration remains unverified and therefore is not a social-signup path.

## Session handoff

Successful sign-in:

- resolves identity before framework-session rotation,
- revokes any application session that appeared in the same framework session before replacement,
- regenerates framework session,
- calls existing `AuthSessionService.startSession`,
- binds exactly one active membership or no tenant when membership is zero/multiple,
- redirects only to the state-bound validated local return URL.

Authenticated linking creates no new application session.

## Failure surface

Expected provider/network/identity-resolution failures redirect to the validated local return surface with a fixed `social_auth=error` marker.

Public failures do not expose whether a local account exists.

## Explicit non-scope

- social registration: forbidden
- automatic user creation: forbidden
- automatic organization creation: forbidden
- provider token persistence: forbidden
- password recovery changes: none
- schema/migration changes: none
- PKK/PWPW runtime: none

## Corrective history

- initial runtime candidate: `0bfab69e03e06ee392d8a5f49fab41b53e08998a`
- framework-session persistence attempt: `d3c78c9d13bc437006b7b38eecfd78f8fba0151a`
- final framework-session nonce binding corrective: `0fc7ab6d59e0669dd1dee6449972c74a7d523e0e`

The final binding deliberately does not depend on the framework's technical session ID. Redirect creates a 32-byte random binding nonce stored in the framework session; only its SHA-256 hash is stored with OAuth state. This preserves the authority requirement that state is bound to the framework session while remaining safe across framework session-ID rotation.

Accepted implementation tree:

`cfcbddf2254aa81f8f5cffbf043f6f34e5e7dd09`

## Exact-head validation evidence

- Implementation CI #557 / run `34884922619`: **5/5 PASS**
- API Contract Gate #449 / run `34884922717`: **PASS**
- PostgreSQL: **325 tests / 5719 assertions — PASS**
- deterministic restore: **121 -> 121 PASS**
- restore fingerprint: `09c57afe2501c79a3a684c0a695b4619114f94ddc24c9bca33728d99c1abb155`
- `RESTORE_DRILL_HARNESS=PASS`
- backend Pint/PHPStan, frontend, contracts/traceability and secret scan: **PASS**

The Social acceptance suite proves:

- allowlisted local return URLs,
- unknown provider fail-closed behavior,
- PKCE S256 redirect construction with no redirect-time provider I/O,
- existing-subject sign-in through existing `AuthSessionService`,
- authenticated linking without provider-email dependency,
- first-link only after verified provider and verified local e-mail match,
- no user auto-creation,
- provider-error state consumption and replay rejection without provider I/O,
- revoked-subject no-relink behavior,
- organization-managed identity rejection,
- no provider-token persistence.

## Closure effect

Exactly two canonical HTTP bindings are closed:

- `auth.social_redirect`
- `auth.social_callback`

Physical HTTP bindings move **150 -> 152**.

Missing physical bindings move **22 -> 20**.

Repo-actionable missing HTTP bindings move **7 -> 5**.

PKK/PWPW bindings remain frozen and unchanged.
