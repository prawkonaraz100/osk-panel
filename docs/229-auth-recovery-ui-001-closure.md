# 229. AUTH-RECOVERY-UI-001 closure

Data: 2026-09-15

**Gate:** `AUTH-RECOVERY-UI-001`  
**Status:** `VALIDATED_CANDIDATE_PENDING_ACCEPTED_PROMOTION`

## Starting accepted authority

`7a84f5e32752efdd7955ceecc02daec0f91f6a7a`

Accepted CI #657: **6/6 PASS**.

## Validated candidate

`b7a7706bc0d504dca622e4abca2eea9f205b2f1b`

Implementation CI `34963155681` / #661: **5/5 PASS**.

Runtime proof:

- PostgreSQL: **380 tests / 6299 assertions — PASS**,
- deterministic restore: **122 -> 122 PASS**,
- schema fingerprint:
  `f3cab286cc8f0aabef219971d90afe424a8dab694c47ade2f517a3d95970716d`.

## Implemented candidate scope

- public SPA `/login`,
- public SPA `/forgot-password`,
- public SPA `/reset-password`,
- login through accepted `POST /api/v1/auth/login`,
- enumeration-safe forgot flow through accepted
  `POST /api/v1/auth/password/forgot`,
- reset flow through accepted `POST /api/v1/auth/password/reset`,
- successful empty-body API responses supported,
- non-auth 401 redirects to login with a local current-path return URL,
- reset token stays URL-only and is removed after success.

## Preserved authority and deferred work

Registration remains **not implemented in this gate**. The accepted registration
contract requires an exact published legal-document version and the repository
still has no unauthenticated public current-legal-document discovery endpoint.
No legal version is hardcoded.

Social provider buttons remain deferred pending provider discovery authority.

PKK/PWPW remains frozen.

## Closure boundary

This closure records a validated candidate. It must not be reported as accepted
until:

1. this closure head passes exact-head CI,
2. accepted branch is clean fast-forwarded to that exact head,
3. accepted push CI is 6/6 PASS including immutable release artifact.
