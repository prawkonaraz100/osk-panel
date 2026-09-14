# 198. CORE-V1-AUTH-PASSWORD-RECOVERY-AUTHORITY-001

Data: 2026-09-14

**Status:** `IN_VALIDATION`

## Problem

`auth.password_forgot` and `auth.password_reset` already exist in the canonical API inventory, but runtime implementation has been blocked because the repository did not define a complete reset-token lifecycle.

The framework password-broker configuration is intentionally not treated as hidden authority: its repository values for table, expiry and throttle are currently unset.

## Decision

Core V1 self-service password recovery uses the existing global identity model:

- password hash authority: `users.password_hash`
- password-management authority: `user_password_management`
- durable credential epoch: `user_password_management.credential_version`

No second durable credential authority is created.

## Eligibility

Forgot-password is public and enumeration-safe.

The server resolves the supplied normalized current login identifier only internally. A reset token may be issued only when:

- the resolved User is `active`,
- `user_password_management.management_mode = self_service`,
- the same User has a current, non-revoked, verified primary e-mail identifier.

`organization_managed`, `unclassified`, absent, revoked, inactive or unverified identities receive the same public **202** forgot response and no token.

A username may identify the User, but delivery still goes only to that User's current verified primary e-mail.

## Token authority

Reset tokens are ephemeral security state and do not require a new database table.

Runtime v1 uses the shared server-side cache (Redis in production/test infrastructure):

- 32 random bytes,
- base64url external token,
- cache lookup by SHA-256(token),
- raw token never persisted,
- 30-minute TTL,
- one live token per User,
- per-User issuance lock,
- reissue invalidates the previous token,
- token state binds `user_id`, credential-version snapshot and expiry.

A per-User cache pointer identifies the currently valid token hash.

The raw token may exist only in request memory and the outbound verified-email delivery payload.

## Delivery

Runtime v1 uses synchronous Laravel mail delivery so the raw token is not serialized into a generic queue/outbox.

The reset destination is deployment configuration containing a required `{token}` placeholder. Production configuration must use HTTPS.

Delivery failure:

1. invalidates the newly issued token,
2. is reported internally without the token,
3. still returns public **202**, preserving enumeration safety.

## Forgot rate limit

Key material contains no raw identifier:

`SHA-256(IP || normalized identifier)`

Policy:

- 5 attempts,
- 300-second decay,
- same behavior whether or not an eligible account exists.

## Reset claim

The reset endpoint validates request shape/password before token claim.

Token claim is one-time and fail-closed:

1. hash incoming token,
2. acquire a token-scoped cache lock,
3. read token state,
4. require the per-User pointer to equal this token hash,
5. delete token state and pointer,
6. release lock.

Replay, missing, expired or superseded token fails with the same generic `PASSWORD_RESET_INVALID` response.

The token is consumed before database mutation. An unexpected database failure after claim requires a new forgot flow rather than reusing uncertain security state.

## Reset transaction

Lock order:

1. `user_password_management FOR UPDATE`
2. `users FOR UPDATE`

Recheck:

- User is active,
- management mode is still `self_service`,
- current `credential_version` equals the issue snapshot.

Atomic mutation:

- hash and replace `users.password_hash`,
- increment `credential_version` by 1,
- set `password_changed_at`,
- revoke every active `auth_sessions` row for that User with reason `password_reset`.

Changing the password or credential-management authority after token issuance therefore invalidates the old reset attempt.

Social-account links and login identifiers are unchanged.

## API semantics

`POST /api/v1/auth/password/forgot`

- public,
- request: `identifier`,
- success/eligible/unknown/ineligible response below rate limit: **202 empty**,
- no account-existence signal.

`POST /api/v1/auth/password/reset`

- public,
- request: `token`, `password`,
- valid reset: **200 empty**,
- invalid/expired/replayed/ineligible token: **422 PASSWORD_RESET_INVALID**,
- reason is not distinguished publicly.

Password validation remains aligned with the existing local-password baseline: required non-empty string, max 1024. This gate does not invent a separate password-strength policy.

## Explicit non-scope

- no HTTP binding in this authority gate,
- no migration or schema change,
- no Laravel password-broker table,
- no OSK-managed credential reset changes,
- no e-mail change/verification design,
- no social-account changes,
- no PKK/PWPW runtime.

## Expected release effect

After exact-head authority PASS and a fresh closure audit, exactly two operations may become implementation-ready:

- `auth.password_forgot`
- `auth.password_reset`
