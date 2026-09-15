# 228. Auth + password recovery UI authority

Data: 2026-09-15

**Gate:** `AUTH-RECOVERY-UI-001`  
**Status:** `VALIDATED_CANDIDATE_PENDING_ACCEPTED_PROMOTION`

## Starting authority

Accepted repository:

`7a84f5e32752efdd7955ceecc02daec0f91f6a7a`

Accepted CI #657: **6/6 PASS**.

Umbrella productization tracker: #110.

## Purpose

Close the first confirmed frontend productization gap without changing identity
authority, database schema or API semantics.

## Public SPA routes

- `/login`
- `/forgot-password`
- `/reset-password?token=<opaque-token>`

The reset route matches the existing default `PASSWORD_RESET_URL_TEMPLATE`.

## Backend reuse

UI must use only the already accepted operations:

- `POST /api/v1/auth/login`,
- `POST /api/v1/auth/password/forgot`,
- `POST /api/v1/auth/password/reset`.

No authentication rule is reimplemented as frontend authority.

## Login

- identifier + password are posted directly to the backend,
- optional remember-me is passed through,
- optional return URL must be local before the UI sends it,
- backend `AuthSessionService::safeReturnUrl` remains authoritative,
- successful login navigates only to the backend-returned `return_url`,
- unauthenticated API responses from non-auth pages redirect to
  `/login?return_url=<local-current-path>`.

## Forgot password

The UI must preserve account-enumeration resistance.

A successful `202` always displays the same generic message. The browser must
not infer whether a user, verified e-mail or self-service password mode exists.

## Reset password

- token is read from the current URL only,
- token is never copied to localStorage or sessionStorage,
- token is never rendered to the page,
- after success the query token is removed with `history.replaceState`,
- backend remains responsible for one-time claim, credential epoch validation,
  password hashing and revoking all active application sessions.

## Shared API client correction

Existing forgot/reset endpoints return successful empty response bodies
(`202` and `200`). The shared API client must therefore accept any successful
empty body rather than trying to JSON-decode it.

## Explicitly deferred

Registration UI is not part of this gate.

`POST /api/v1/auth/register` requires an exact published
`accepted_terms_version`. The accepted repository has no unauthenticated public
current-legal-document discovery endpoint. Hardcoding a legal version in Vue is
forbidden.

Social provider buttons are also excluded because provider enablement is
deployment configuration and no public provider-registry discovery contract is
defined.

## Non-goals

- no schema/migration changes,
- no session authority changes,
- no new auth endpoint,
- no social-provider hardcoding,
- no legal-document version hardcoding,
- no PKK/PWPW activation,
- no production-ready claim.


## Candidate validation evidence

Exact validated candidate head before closure:

`b7a7706bc0d504dca622e4abca2eea9f205b2f1b`

Implementation CI `34963155681` / run #661: **5/5 PASS**.

- backend-quality: PASS,
- frontend-quality: PASS,
- contracts-and-traceability: PASS,
- secret-scan: PASS,
- runtime-tests-and-migrations: PASS,
- PostgreSQL: **380 tests / 6299 assertions — PASS**,
- deterministic restore: **122 -> 122 PASS**,
- schema fingerprint: `f3cab286cc8f0aabef219971d90afe424a8dab694c47ade2f517a3d95970716d`.

This evidence validates the candidate only. The gate is not part of accepted runtime until clean promotion and accepted-branch 6/6 PASS complete.
