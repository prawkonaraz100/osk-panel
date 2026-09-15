# 171. CORE-V1-AUDIT-LOGS-LIST-001 — closure

Data: 2026-09-14

**Status:** `PASS`

## Exact scope

This gate materializes exactly one canonical operation:

- `GET /api/v1/audit-logs` — `audit_logs.list`.

It reuses the existing append-only `audit_logs` authority. No new table, migration, audit writer or alternate activity store was introduced.

## Authorization boundary

The runtime:

1. resolves the current active tenant membership,
2. rechecks `organization.audit.view`,
3. requires its `organization` scope through the registered tenant-resource resolver,
4. restricts the query to `audit_scope = organization` and the active `organization_id`,
5. only then applies optional `entity_type`, `entity_id` and `request_id` filters.

A row from another OSK cannot become visible by supplying matching filter values.

## Minimized projection

The HTTP response exposes only the existing OpenAPI `AuditLogEntry` fields:

- `id`,
- `action`,
- `entity_type`,
- `entity_id`,
- `actor_user_id`,
- `request_id`,
- `reason`,
- `created_at`.

The endpoint does **not** serialize:

- `before_redacted_json`,
- `after_redacted_json`,
- `ip_hash`,
- `user_agent`,
- `audit_policy_version`,
- internal actor-membership identifiers.

The safe dashboard activity projection remains a separate concept and was not reused as raw audit history.

## Executable evidence

Exact implementation head:

`23113bc0077838c102fa5b10373ffdf33d4786b0`

Final evidence:

- Implementation CI #492 / run `34814965702`: **5/5 PASS**
- API Contract Gate #369 / run `34814965703`: **PASS**
- PostgreSQL: **275 tests / 5204 assertions — PASS**
- deterministic restore: **121 -> 121 PASS**
- restore schema fingerprint: `09c57afe2501c79a3a684c0a695b4619114f94ddc24c9bca33728d99c1abb155`
- frontend quality: **PASS**
- Pint + PHPStan: **PASS**
- contracts/traceability: **PASS**
- secret scan: **PASS**

## Closure effect

The previous derived count was **19 repo-actionable missing HTTP bindings**. This gate closes exactly one, leaving **18** before the next full repository closure re-audit.

## Next safe slice

Next is a read-only authority proof for the single `students.progress` gap. It may be materialized only from existing canonical learning/training/exam evidence. The gate must not invent a new progress truth source or mix in frozen PKK/PWPW runtime.
