# 176. CORE-V1-LEARNING-CREDENTIAL-SINGLE-PDF-001 — closure

Data: 2026-09-14

**Status:** `PASS`

## Exact scope

This gate materializes one physical canonical HTTP binding shared by two required-operation rows:

- `learning_accounts.download_handoff_pdf`,
- shared alias `license_credentials.single_pdf`.

The physical endpoint is:

- `GET /api/v1/students/{studentId}/learning-accounts/{accountId}/access-handoffs/{handoffId}/pdf`.

No schema, migration, OpenAPI path, bulk credential reset flow, pricing/VAT authority or PKK/PWPW runtime was added.

## Authority boundary

The endpoint requires:

- active authenticated tenant context,
- `student_access.download_credentials_pdf`,
- exact tenant-scoped Student -> LearningAccount -> AccessHandoff target,
- an operational learning account,
- a handoff credential-version snapshot that is not ahead of the current credential authority.

The later reprint is deliberately secret-free. It may expose the current canonical login identifier and password-state information, but it never recovers, reconstructs or persists a historical plaintext password.

The PDF is rendered dynamically from current nonsecret authority and the selected handoff context. It does not create a new FileAsset, a new handoff or a new credential version.

## Executable evidence

Initial implementation head:

`de29b744842fdb5ce46d8d4aee2b5fbe96df77d3`

Corrective heads:

- `e9393c5afac7a17f8ed834959b5bea351d2a7f03` — synchronized the audit catalog expectation and asserted the new audit action,
- `4496da6500daa62a07346cef58beecb128240a7f` — synchronized the idempotent reseed count.

Both corrective commits changed only `tests/Feature/FoundationReferenceCatalogTest.php`; production runtime remained unchanged.

Final exact-head evidence on `4496da6500daa62a07346cef58beecb128240a7f`:

- Implementation CI #506 / run `34826538061`: **5/5 PASS**
- API Contract Gate #388 / run `34826538024`: **PASS**
- PostgreSQL: **289 tests / 5320 assertions — PASS**
- deterministic restore: **121 -> 121 PASS**
- restore schema fingerprint: `09c57afe2501c79a3a684c0a695b4619114f94ddc24c9bca33728d99c1abb155`
- frontend quality: **PASS**
- Pint + PHPStan: **PASS**
- contracts/traceability: **PASS**
- secret scan: **PASS**

## Security and preservation

This gate does not:

- return an old plaintext password,
- persist a secret-bearing reprint,
- mutate `user_password_management.credential_version` during normal reprint,
- create a new credential handoff,
- create or replace a FileAsset,
- expose a foreign-tenant Student, LearningAccount or AccessHandoff,
- activate the blocked bulk reset branch,
- invent expected credential versions for bulk reset targets,
- read or modify PKK/PWPW provider runtime,
- invent pricing or VAT authority.

The emitted download is a private, non-cacheable PDF and every successful download records the allowlisted audit action `learning_account_credentials_pdf_downloaded`.

## Closure effect

The previous derived count was **13 repo-actionable missing physical HTTP bindings**.

This gate closes exactly **one physical binding**, leaving a derived **12** before the next full repository closure re-audit.

The two required-operation identifiers above are aliases of the same physical route/operation and therefore are not double-counted.

`license_credentials.bulk_pdf` remains a separate missing binding.

## Next safe slice

Run a new repository closure re-audit on the exact accepted tree before selecting another implementation slice.

The remaining HTTP backlog has material dependency blockers: PKK-backed Organization Settings remains frozen, password-reset token authority is absent, social OAuth provider/runtime authority is absent, Student Progress lacks its online-learning progress authority, commerce order creation lacks canonical server-side price/VAT authority, and bulk credential reset lacks expected credential versions per reset target.

The prior UI-only classification of Course Completion must also be re-audited: the current backend deliberately rejects `training_completed` as well as the Vue UI blocking it, so any completion work is a full runtime + UI gate with formal hour/requirement/exam eligibility — not a cosmetic UI unlock.

PKK/PWPW runtime remains frozen.
