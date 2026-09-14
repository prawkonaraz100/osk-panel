# 192. CORE-V1-ORGANIZATION-SETTINGS-001 closure

Data: 2026-09-14

**Status:** `PASS`

## Scope

Implemented exactly the three provider-neutral operations released by `CORE-V1-CLOSURE-AUDIT-007`:

- `organization.update`
- `organization.settings.get`
- `organization.settings.update`

No PKK/PWPW runtime was activated.

## Runtime materialization

Physical Laravel bindings now exist for:

- `PATCH /api/v1/organization`
- `GET /api/v1/organization/settings`
- `PATCH /api/v1/organization/settings`

The implementation:

- uses `organization_settings.version` as optimistic-concurrency authority,
- requires `If-Match` on both mutation operations,
- requires `Idempotency-Key` on settings PATCH,
- preserves tenant permission checks,
- performs atomic settings mutation with audit/outbox,
- supports partial existing-address updates,
- reads e-mail from the current canonical auth identifier but does not mutate e-mail,
- does not require `organization.view` as a hidden extra permission after an authorized settings mutation,
- never exposes or mutates PKK integration data from provider-neutral settings,
- returns a conflict for stale settings versions instead of leaking an internal error.

## PKK independence evidence

Runtime tests prove both cases:

1. ordinary settings GET/PATCH work when no `pkk_integration_settings` record exists;
2. an existing PKK integration record with marker values is neither exposed nor mutated by provider-neutral settings.

PKK/PWPW remains:

`FROZEN_UNTIL_EXPLICIT_UNFREEZE`

## Corrective history

Runtime candidate sequence:

- `803ed4d36665a4476e846f97195a2f6a71a3272f` — materialize provider-neutral HTTP runtime
- `beb2d4909cdcded1471d90b24e4e687556958cf9` — PHPStan address fallback corrective
- `32fac6f33e8823cc03377cad0cb4a9451b16f715` — preserve pre-existing foundation service contract
- `3059d06d268f864b18c8f0bbe98e11e70a984593` — map stale settings version to HTTP conflict

Exact accepted implementation tree:

`58977711a5ea45037e6568535aac9aae658b72b6`

## Exact-head validation evidence

- Implementation CI #546 / run `34871761320`: **5/5 PASS**
- API Contract Gate #436 / run `34871761256`: **PASS**
- PostgreSQL: **316 tests / 5619 assertions — PASS**
- deterministic restore: **121 -> 121 PASS**
- restore fingerprint: `09c57afe2501c79a3a684c0a695b4619114f94ddc24c9bca33728d99c1abb155`
- `RESTORE_DRILL_HARNESS=PASS`
- backend Pint/PHPStan, frontend, contracts/traceability and secret scan: **PASS**

## Closure effect

Compared with closure audit 007, exactly three missing physical bindings are closed:

- physical bindings: **147 -> 150**
- missing physical bindings: **25 -> 22**
- repo-actionable missing HTTP bindings: **10 -> 7**

The 14 frozen PKK/PWPW bindings and the provider-specific payment webhook remain unchanged.

## Next

Run a fresh closure re-audit before choosing the next implementation family.
