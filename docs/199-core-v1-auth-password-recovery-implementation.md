# 199. CORE-V1-AUTH-PASSWORD-RECOVERY-001

Data: 2026-09-14

**Status:** `PASS`

## Scope

Materialize exactly:

- `auth.password_forgot`
- `auth.password_reset`

Routes:

- `POST /api/v1/auth/password/forgot`
- `POST /api/v1/auth/password/reset`

## Forgot flow

The public forgot endpoint:

- normalizes the login identifier consistently with local login,
- rate-limits by SHA-256 of IP plus normalized identifier,
- returns the same 202 empty response for unknown and ineligible identities below the limit,
- resolves only a current non-revoked login identifier,
- issues only for an active `self_service` User,
- requires that same User to have exactly one current verified primary e-mail,
- serializes issuance per User with the shared cache lock primitive,
- keeps exactly one live token per User,
- invalidates the previous token on reissue,
- sends only through a secret-safe synchronous mail transport,
- invalidates a newly issued token if delivery fails.

## Token model

- 32 random bytes,
- base64url external representation,
- cache lookup by SHA-256(token),
- raw token never persisted,
- 1800-second TTL,
- token state contains only User id, credential-version snapshot and expiry,
- a per-User pointer selects the only live token,
- raw token is present only in request memory and the synchronous reset e-mail payload.

## Reset flow

Reset rate-limits by a hash of requester IP.

A valid claim:

1. hashes the supplied token,
2. acquires a token-scoped cache lock,
3. verifies token state and the per-User live-token pointer,
4. consumes state and pointer before database mutation,
5. locks `user_password_management`,
6. locks `users`,
7. rechecks active User, `self_service` mode and credential-version snapshot,
8. replaces `users.password_hash`,
9. increments credential version,
10. stamps `password_changed_at`,
11. revokes every active application auth session with reason `password_reset`.

Invalid, expired, replayed, superseded or authority-stale claims return the same `PASSWORD_RESET_INVALID` response.

## Delivery safety

Configuration:

- `PASSWORD_RESET_URL_TEMPLATE` may override the local reset destination and must contain `{token}`,
- production requires HTTPS,
- `PASSWORD_RESET_MAILER` may select an explicit synchronous mailer,
- log transport is rejected because raw reset tokens must never enter logs,
- failover/roundrobin mailers are accepted only when every child transport is secret-safe.

## Explicit non-scope

- no migration/schema change,
- no Laravel password-broker table,
- no organization-managed password mutation,
- no social-account mutation,
- no e-mail identifier mutation,
- no PKK/PWPW runtime.

## Exact-head validation evidence

Accepted implementation head:

`6ff71b6e4d2016868817a6d27b46fd55ea417c64`

Tree:

`e751479770dab3a19722b5868b502c5ae92fc572`

Validation:

- Implementation CI #563 / run `34889977751`: **5/5 PASS**
- API Contract Gate #456 / run `34889977729`: **PASS**
- PostgreSQL: **331 tests / 5808 assertions — PASS**
- deterministic restore: **121 -> 121 PASS**
- restore fingerprint: `09c57afe2501c79a3a684c0a695b4619114f94ddc24c9bca33728d99c1abb155`
- `RESTORE_DRILL_HARNESS=PASS`
- backend Pint/PHPStan, frontend, contracts/traceability and secret scan: **PASS**

The acceptance suite proves enumeration-safe forgot semantics, verified-primary-email eligibility, one-live-token reissue invalidation, one-time/replay rejection, credential-version invalidation, organization-managed fail-closed behavior, synchronous secret-safe delivery, password replacement, credential-version increment and revocation of all active application auth sessions.

## Closure effect

Exactly two canonical HTTP bindings are closed:

- `auth.password_forgot`
- `auth.password_reset`

Physical HTTP bindings move **152 -> 154**.

Missing physical bindings move **20 -> 18**.

Repo-actionable missing HTTP bindings move **5 -> 3**.

The remaining repo-actionable HTTP gaps are:

- `students.progress`
- `license_orders.create`
- `exam_orders.create`

PKK/PWPW remains frozen and unchanged.
