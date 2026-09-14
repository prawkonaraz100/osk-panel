# 196. CORE-V1-AUTH-SOCIAL-001

Data: 2026-09-14

**Status:** `IN_VALIDATION`

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
- binds provider, framework-session hash, local return URL, mode, initiating user/session, PKCE verifier and expiry,
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
