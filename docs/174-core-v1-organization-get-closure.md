# 174. CORE-V1-ORGANIZATION-GET-001 — closure

Data: 2026-09-14

**Status:** `PASS`

## Exact scope

This gate materializes exactly one canonical operation:

- `GET /api/v1/organization` — `organization.get`.

No schema, migration, Organization Settings write path or integration runtime changed.

## Authority boundary

The endpoint resolves the active membership and rechecks:

- `organization.view`,
- organization scope.

It then reads the exact `organizations` row selected by the authenticated membership.

The response projects only canonical organization fields:

- `id`,
- `name`,
- `nip`,
- `phone`,
- `timezone`,
- `status`.

The optional OpenAPI field `osk_registry_number` is deliberately not synthesized and is not read from `pkk_integration_settings`. That keeps this read-only organization summary outside the frozen PKK/PWPW boundary.

## Executable evidence

Exact implementation head:

`873ed80ece9d1908c1ff39408f14f593a196c0a6`

Final evidence:

- Implementation CI #500 / run `34822157005`: **5/5 PASS**
- API Contract Gate #380 / run `34822156971`: **PASS**
- PostgreSQL: **284 tests / 5278 assertions — PASS**
- deterministic restore: **121 -> 121 PASS**
- restore schema fingerprint: `09c57afe2501c79a3a684c0a695b4619114f94ddc24c9bca33728d99c1abb155`
- frontend quality: **PASS**
- Pint + PHPStan: **PASS**
- contracts/traceability: **PASS**
- secret scan: **PASS**

## Preservation

This gate does not:

- mutate Organization Settings,
- expose or modify PKK integration configuration,
- infer an OSK registry number,
- activate PKK/PWPW provider runtime,
- alter tenant selection or authorization rules.

## Closure effect

The previous derived count was **15 repo-actionable missing HTTP bindings**.

This gate closes exactly one binding, leaving a derived **14** before the next full repository closure re-audit.

## Next safe slice

`organization.accepted_terms.get` is dependency-closed:

- immutable source: `terms_acceptances`,
- exact document version source: `legal_documents`,
- permission: `organization.view`,
- technical IP/user-agent/request metadata must not be serialized,
- `document_url` may remain `null` until a real versioned local document resolver exists.

PKK/PWPW runtime remains frozen.
