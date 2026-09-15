# 229. AUTH-RECOVERY-UI-001 closure

Data: 2026-09-15

**Gate:** `AUTH-RECOVERY-UI-001`  
**Status:** `PASS_ACCEPTED`

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

## Closure criteria — satisfied

1. closure head `d6b2089903ac830581b8606914cd484f1207dee2` passed exact-head CI #664: **5/5 PASS**,
2. accepted branch was clean fast-forwarded to that exact head,
3. accepted push CI #665 completed **6/6 PASS**, including immutable release artifact.

Accepted release evidence:

- accepted SHA: `d6b2089903ac830581b8606914cd484f1207dee2`,
- PostgreSQL: **380 tests / 6299 assertions — PASS**,
- deterministic restore: **122 -> 122 PASS**,
- artifact ID: `10395187416`,
- artifact name: `osk-panel-d6b2089903ac830581b8606914cd484f1207dee2`,
- release archive SHA-256: `516fa5aafe9c4a1a2a5bfb488e3fb3ae0bbfa3377d82e05a5797f3b9f70af7ce`.

The historical candidate validation above is preserved as validation history.
