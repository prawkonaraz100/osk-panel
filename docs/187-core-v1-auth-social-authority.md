# 187. CORE-V1-AUTH-SOCIAL-AUTHORITY-001 — social sign-in authority

Data: 2026-09-14

**Status:** `IN_VALIDATION`

This gate defines the exact authority for:

- `GET /api/v1/auth/social/{provider}/redirect`
- `GET /api/v1/auth/social/{provider}/callback`

It does not implement the routes yet and it does not add schema.

Existing Stage-4 authority already provides `auth_social_accounts`, `auth_login_identifiers`, `user_password_management`, and `auth_sessions`. Existing session runtime already provides local ReturnUrl validation and application-session creation.

The provider path is only an allowlisted selector. Provider endpoints and deployment credentials come only from trusted server configuration. Missing or disabled configuration fails closed.

Redirect flow uses a one-time random state value with a ten-minute TTL, bound to the framework session and provider. It also uses PKCE S256. The post-login ReturnUrl is validated before state issuance and stored server-side with the pending flow.

Callback atomically consumes state once. Missing, expired, mismatched, or replayed state is rejected. Provider errors consume state without starting the code exchange. Provider network I/O occurs outside database transactions.

Provider response material is normalized to a stable subject plus optional verified e-mail. Provider tokens and raw profile payloads are transient and are not persisted, logged, or copied into audit/outbox payloads.

Identity resolution is intentionally conservative:

- an existing current provider+subject link authenticates its active User;
- a historically revoked provider+subject is never automatically re-linked;
- a never-linked subject may be linked only to an existing active User whose current local e-mail identifier is already verified and exactly matches the verified provider e-mail;
- an `organization_managed` learner identity may not acquire a social principal;
- no User, Organization, legal acceptance, or marketing consent is synthesized from provider data.

After identity resolution, session handoff reuses the existing application session authority. The framework session is regenerated before the new auth session is materialized. Exactly one active membership may be selected automatically; otherwise tenant context remains null. Redirect after login uses only the state-bound validated local path.

This gate preserves the PKK/PWPW freeze and does not change password reset, payment providers, or registration semantics.

Candidate validation requires full Implementation CI, API Contract Gate, PostgreSQL runtime, deterministic restore, Pint/PHPStan, frontend, contracts/traceability, and secret scan.
