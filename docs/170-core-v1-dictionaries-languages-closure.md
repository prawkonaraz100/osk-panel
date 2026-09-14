# 170. CORE-V1-DICTIONARIES-LANGUAGES-001 — closure

Data: 2026-09-14

**Status:** `PASS`

## Exact scope

This gate materializes exactly one missing canonical HTTP binding:

- `GET /api/v1/languages` — `dictionaries.languages.list`.

The endpoint reuses the existing final `languages` table and existing active-membership authority. No schema, migration or external integration was introduced.

## Authority boundary

The endpoint returns only the active global language dictionary:

- `code`,
- `label`.

Rows are ordered deterministically by code and inactive entries are not returned.

This endpoint deliberately does **not** inspect or infer `license_product_language_capabilities`. Product-specific language availability remains authoritative only in the product capability endpoints.

## Executable evidence

Exact implementation head:

`a25d4a5b4e30ca037d0d203400e38ca821b6836a`

Final evidence:

- Implementation CI #490 / run `34813917123`: **5/5 PASS**
- API Contract Gate #366 / run `34813917131`: **PASS**
- PostgreSQL: **273 tests / 5185 assertions — PASS**
- deterministic restore: **121 -> 121 PASS**
- restore schema fingerprint: `09c57afe2501c79a3a684c0a695b4619114f94ddc24c9bca33728d99c1abb155`
- frontend quality: **PASS**
- Pint + PHPStan: **PASS**
- contracts/traceability: **PASS**
- secret scan: **PASS**

## Closure effect

The last closure accounting had **20 repo-actionable missing HTTP bindings**. This gate closes exactly one, leaving a derived **19** before the next full repository closure re-audit.

## Next safe slice

The next dependency-closed gap is `CORE-V1-AUDIT-LOGS-LIST-001`.

It must use the existing append-only audit authority, enforce `organization.audit.view` with organization scope, apply tenant filtering before optional filters, and serialize only the minimized OpenAPI `AuditLogEntry` fields. Raw `before_redacted_json`, `after_redacted_json`, IP hash, user-agent or internal policy metadata must not be exposed.

PKK/PWPW runtime remains frozen.
