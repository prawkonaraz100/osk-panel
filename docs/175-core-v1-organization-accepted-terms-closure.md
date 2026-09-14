# 175. CORE-V1-ORGANIZATION-ACCEPTED-TERMS-001 — closure

Data: 2026-09-14

**Status:** `PASS`

## Exact scope

This gate materializes exactly one canonical operation:

- `GET /api/v1/organization/accepted-terms` — `organization.accepted_terms.get`.

No schema, migration, Organization Settings write path, PKK/PWPW runtime or document resolver was added.

## Authority boundary

The endpoint resolves the active membership and rechecks:

- `organization.view`,
- organization scope.

It reads only the authenticated tenant's append-only `terms_acceptances` rows and resolves the exact immutable accepted version through `legal_documents`.

The response exposes only:

- `version`,
- `accepted_at`,
- `accepted_by_user_id`,
- `document_url`.

`document_url` is deliberately `null` until a real versioned local document resolver exists.

The following technical metadata is never serialized:

- `ip_hash`,
- `user_agent`,
- `request_id`,
- `legal_document_id`,
- `organization_id`.

## Executable evidence

Exact implementation head:

`6d7fd7162feec8883bfb43f4fdc236e726ac2f3c`

Final evidence:

- Implementation CI #502 / run `34823611100`: **5/5 PASS**
- API Contract Gate #383 / run `34823611083`: **PASS**
- PostgreSQL: **286 tests / 5296 assertions — PASS**
- deterministic restore: **121 -> 121 PASS**
- restore schema fingerprint: `09c57afe2501c79a3a684c0a695b4619114f94ddc24c9bca33728d99c1abb155`
- frontend quality: **PASS**
- Pint + PHPStan: **PASS**
- contracts/traceability: **PASS**
- secret scan: **PASS**

## Preservation

This gate does not:

- mutate any terms acceptance history,
- expose request/IP/user-agent metadata,
- synthesize a document URL,
- read or modify PKK integration settings,
- activate PKK/PWPW provider runtime,
- change pricing/VAT authority,
- change credential reset semantics.

## Closure effect

The previous derived count was **14 repo-actionable missing HTTP bindings**.

This gate closes exactly one binding, leaving a derived **13** before the next full repository closure re-audit.

## Next safe slice

The next dependency-closed credential slice is the existing single handoff PDF binding:

- `learning_accounts.download_handoff_pdf`,
- shared alias `license_credentials.single_pdf`.

It may render only from the selected tenant-scoped handoff/account state.

Historical plaintext passwords must never be recovered. A normal reprint is therefore secret-free; a fresh plaintext password may exist only at the already-authorized create/reset handoff boundary.

The bulk `license_credentials.bulk_pdf` operation remains separately blocked for its `regenerate_credentials_when_required=true` branch until the contract carries expected credential versions for every reset target.

PKK/PWPW runtime remains frozen.
