# 172. CORE-V1-AUTH-ACCOUNT-CLOSURE-001 — closure

Data: 2026-09-14

**Status:** `PASS`

## Exact scope

This gate materializes exactly one canonical operation:

- `POST /api/v1/auth/account-closure-requests` — `auth.account_closure_request`.

The operation records a durable **request** to close the authenticated global User account. It does not delete the User, memberships, formal records, finance history, audit history or any other retained business data.

No schema or Stage-4 migration changed.

## Authorization and scope boundary

The endpoint requires a live authenticated application session only, exactly as declared by `authenticated_user`.

It deliberately does **not** require an active organization membership or selected tenant context. The canonical request is therefore global:

- `account_closure_requests.user_id = authenticated user`,
- `organization_id = NULL`,
- `status = pending`.

This matches the existing global pending-closure invariant and remains valid when an organization membership is suspended or when the auth session has no selected membership.

## Idempotency and pending-request invariant

The command uses the existing global scope of `idempotency_records`:

- `organization_id = NULL`,
- operation key `auth.account_closure_request`,
- UUID `Idempotency-Key`,
- same key + same request hash replays the same response,
- same key + different request hash returns conflict.

If another idempotency key is used while the same User already has a global `pending` closure request, the existing pending request is returned and no second business request, audit event or outbox intent is created.

## Atomic global audit/outbox

Creation of a new pending request records, in the same local transaction:

1. the canonical `account_closure_requests` row,
2. one `platform_global` audit row with `actor_kind = global_user`,
3. one matching `platform_global` domain event,
4. one pending outbox message,
5. the completed global idempotency record.

The audit projection contains only allowlisted lifecycle state evidence:

- before: `state = absent`,
- after: `state = pending`.

The free-form user closure reason remains in the business request row and is not copied into audit/outbox payloads.

## Explicit non-scope

This gate does not:

- perform account deletion,
- revoke all memberships or sessions,
- execute retention or anonymization policy,
- rewrite formal or financial history,
- activate organization-scoped closure semantics,
- resolve Organization Settings primary-email policy,
- activate PKK/PWPW provider runtime.

Those remain separate reviewed flows.

## Executable evidence

Implementation commit:

`66713286f06deae5c1cae70ed9614f6711657aea`

Corrective commits:

- `be49f83c2e65f57a3142e0187789f4ffd07d6baf` — align static catalog/outbox cardinality contracts and PHPStan annotations,
- `95ad8f2658ada2894c944c1a4cf1785ded1ecd5a` — make Laravel query-result typing explicit without changing runtime semantics.

Final evidence on exact head `95ad8f2658ada2894c944c1a4cf1785ded1ecd5a`:

- Implementation CI #496 / run `34817810942`: **5/5 PASS**
- API Contract Gate #374 / run `34817810892`: **PASS**
- PostgreSQL: **279 tests / 5245 assertions — PASS**
- deterministic restore: **121 -> 121 PASS**
- restore schema fingerprint: `09c57afe2501c79a3a684c0a695b4619114f94ddc24c9bca33728d99c1abb155`
- frontend quality: **PASS**
- Pint + PHPStan: **PASS**
- contracts/traceability: **PASS**
- secret scan: **PASS**

## Closure effect

The last closed repository audit count was **18 repo-actionable missing HTTP bindings**. The blocked `students.progress` binding was not fabricated or counted as closed.

This gate closes exactly one binding, leaving a derived **17** before the next full repository closure re-audit.

## Next safe slice

The next dependency-closed slice is:

- `service_entitlements.list`,
- `service_entitlements.activate`.

Its database authority is already final: tenant binding, explicit-vs-immediate activation mode, unique activation evidence and deferred final-state equivalence are enforced by the existing Stage-4 write fence. The implementation must reuse those invariants, must not make payment success activate an explicit entitlement, and must not touch frozen PKK/PWPW runtime.
