# 168. CORE-V1-AUTH-SESSION-LIFECYCLE-001 — closure

Data: 2026-09-14

**Status:** `PASS`

This gate materializes the first local HTTP authentication/session slice on top of the already-final Stage-4 identity authority.

## Exact scope

Implemented canonical operations:

- `POST /api/v1/auth/login` — `auth.login`
- `POST /api/v1/auth/logout` — `auth.logout`
- `GET /api/v1/auth/sessions` — `auth.sessions_list`
- `DELETE /api/v1/auth/sessions/{sessionId}` — `auth.session_revoke`

Not included:

- registration,
- password forgot/reset,
- account closure request,
- social/OAuth flows,
- Organization Settings,
- PKK provider runtime.

No migration or schema change was introduced.

## Existing authority reused

The implementation uses the existing final model only:

`auth_login_identifiers -> users -> auth_sessions -> organization_memberships -> membership_permissions/scopes`

No parallel identity, session, tenant or permission store was created.

## Login security boundary

Login:

- normalizes the global current login identifier;
- resolves only non-revoked identifiers;
- requires an active User and a valid stored password hash;
- applies an identifier+IP login rate limit;
- rotates the Laravel framework session before binding application auth authority;
- stores only SHA-256 of the framework session reference in `auth_sessions`;
- stores an IP hash rather than raw IP;
- validates `return_url` against local allowlisted application paths;
- updates `users.last_login_at`;
- selects `organization_membership_id` only when exactly one active membership exists.

If multiple active memberships exist, tenant context remains `NULL`. The implementation does not guess an OSK.

## Logout and session management

Logout does not require the previously selected membership to remain active. It revokes the durable `auth_sessions` row and invalidates the framework session.

Own-session list/revoke:

- requires a live current auth session;
- rechecks the selected active membership;
- requires `sessions.manage.own`;
- requires the `own` scope with the registered owner resolver;
- lists only active sessions for the same global User;
- treats a foreign User session identifier as not found;
- revocation changes state and never deletes session history.

## Executable evidence

Implementation head:

`38244f17cc64671888ab46c1c012bdcb56ec7781`

CI #484 proved the complete PostgreSQL runtime but exposed one static-analysis-only issue: the global `LogicException` import was missing.

Corrective exact head:

`f08396fed68515df034a26951d52e3763a519812`

Final evidence:

- Implementation CI #485 / run `34810823513`: **5 / 5 PASS**
- API Contract Gate #358 / run `34810823541`: **PASS**
- PostgreSQL: **269 tests / 5152 assertions — PASS**
- deterministic restore: **121 -> 121 PASS**
- restore schema fingerprint: `09c57afe2501c79a3a684c0a695b4619114f94ddc24c9bca33728d99c1abb155`
- Pint: **PASS**
- PHPStan: **PASS**
- contracts/traceability: **PASS**
- secret scan: **PASS**
- frontend quality: **PASS**

## Closure effect

The 2026-09-14 zero-gap re-audit counted **24 repo-actionable missing HTTP bindings** before this gate. This gate closes four of them, leaving a derived **20** before the next full closure re-audit.

The historical re-audit remains a point-in-time record and is not rewritten.

## Next safe slice

The next dependency-closed non-PKK slice is `CORE-V1-RESOURCE-ASSET-UI-001`: connect the already implemented UploadsAssets transport and vehicle-document APIs to the confirmed Resources UI.

PKK/PWPW runtime remains frozen until explicit unfreeze.
