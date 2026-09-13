# FORMAL-DOC-010 — Uploads / Assets runtime

Status: `FORMAL-DOC-010 PASS`

## Scope

This gate materializes the already canonical Uploads / Assets contract:

- `POST /uploads/presign`,
- direct PUT to private S3-compatible object storage,
- `POST /uploads/{uploadId}/complete`,
- tenant-owned `file_assets` lifecycle.

No new database migration or API operation is introduced.

## Client-upload purposes

Gate 10 accepts exactly the client-owned purposes already required by materialized core runtime:

- `staff_photo`,
- `vehicle_photo`,
- `vehicle_document`,
- `formal_training_signed_scan`.

Server-generated purposes such as canonical formal training documents remain forbidden through the client upload API.

## Parent binding and authorization

Every presign request carries:

- `parent_type`,
- `parent_id`.

The backend derives authorization from purpose and parent.

Examples:

- new staff photo → organization + `staff.create`,
- existing staff photo → staff profile + `staff.edit`,
- new vehicle photo → organization + `vehicles.create`,
- existing vehicle photo/document → vehicle + `vehicles.edit`,
- formal signed scan → exact `formal_training_document` + `formal_documents.deliver` resolved through the owning course/student scope.

For `formal_training_signed_scan`:

- the formal document must exist in the active organization,
- the immutable document revision must be paper mode,
- assigned-student scope is enforced through the owning course,
- electronic documents cannot receive a paper signed-scan upload reservation.

The parent binding is persisted in a server-generated storage key and is re-authorized again during completion.

## Reservation and immutable materialization

Presign creates a tenant-owned `file_assets` row with status `pending`.

The presigned URL always targets a temporary key:

`upload-reservations/{organization}/{purpose}/{parent_type}/{parent_id}/{upload_id}`

Completion:

1. re-resolves active tenant membership,
2. re-authorizes the persisted parent binding,
3. rejects expired reservations,
4. requires the uploaded object to exist,
5. verifies exact byte size,
6. verifies reserved/completion SHA-256 when supplied,
7. detects MIME from content,
8. requires declared and detected MIME to match the purpose allowlist,
9. performs purpose-appropriate content validation,
10. writes verified bytes to a different final key,
11. re-reads and hashes the final object,
12. atomically changes `file_assets` to `ready`,
13. removes the temporary reservation object.

Final key:

`assets/{organization}/{purpose}/{parent_type}/{parent_id}/{upload_id}`

A still-valid old presigned PUT URL may recreate or overwrite only the obsolete reservation key; it cannot mutate the `ready` business asset.

## Validation policy

Current content validation is deliberately described as `content_validation_v1`, not as antivirus or malware scanning.

- PDF: detected `application/pdf`, PDF header and EOF marker,
- PNG: detected `image/png` and PNG signature,
- JPEG: detected `image/jpeg` and JPEG SOI/EOI markers,
- WebP: detected `image/webp` and RIFF/WEBP signature.

Maximum sizes are purpose-bound in `config/uploads.php`.

Gate 10 does **not** claim that ClamAV or another malware engine is present. A future production security policy may add malware scanning without changing the Uploads API contract.

## Idempotency

`/uploads/{uploadId}/complete` requires a UUID `Idempotency-Key`.

A replay of the same completion request returns the same safe `FileAsset` result without creating another asset.

A completion with a new key after the asset is already `ready` is naturally idempotent and returns the same asset after parent re-authorization.

## Formal signed scan handoff

A successfully completed `formal_training_signed_scan` becomes a normal same-tenant ready `file_assets` row.

FORMAL-DOC-009 then consumes that exact asset id through:

`POST /formal-training-documents/{documentId}/delivery-events`

with:

`event_type = signed_scan_attached`.

No direct PDF editing, electronic-signature claim, or PKK/PWPW runtime is added.

## API contract

The canonical API inventory remains unchanged:

- **187 HTTP requirement rows**,
- **173 unique canonical operations**,
- **14 shared operation aliases**.

Gate 10 only tightens the existing upload request schema by requiring the parent binding.

## Runtime proof required

FORMAL-DOC-010 must prove:

1. canonical `uploads.presign` and `uploads.complete` routes are executable,
2. unsupported/server-generated purposes fail closed,
3. parent-domain permission is required at presign and completion,
4. formal signed scan enforces assigned-student scope,
5. paper/electronic mode separation is preserved,
6. declared size must equal uploaded byte size,
7. declared and detected MIME must match,
8. reserved and completion SHA-256 are verified,
9. invalid content is marked `rejected` and never becomes `ready`,
10. valid content becomes same-tenant `ready`,
11. completion is idempotent,
12. final asset bytes are re-hashed after materialization,
13. reservation key and final key are different,
14. a late write to the old reservation key cannot mutate the ready asset,
15. completed formal signed scan can be attached through FORMAL-DOC-009 delivery runtime,
16. no new Stage-4 or Stage-5 migration step is introduced,
17. Stage-4 170/112/112 authority remains unchanged,
18. Stage-5 formal-document 11-step execution identity remains unchanged,
19. API contract remains 187/173/14,
20. PKK provider runtime remains frozen.

## Explicitly out of scope

- public asset storage,
- arbitrary client-defined purposes,
- client-supplied storage keys or disks,
- direct upload of server-generated formal documents,
- antivirus/malware-engine claims,
- direct canonical PDF editing,
- statutory electronic-signature mechanisms,
- PKK/PWPW operations.


## Gate closure evidence

FORMAL-DOC-010 is closed as PASS on the exact implementation tree `84ad523a6734d66842755bfc41642c1ce7cfe33e`.

Validation-only helper:

- helper commit: `8809ebf830eb5e7c141d96d273c11ef243265e51`,
- validation PR: #69,
- PR merged: false,
- helper Implementation CI: #344 / run `34747599325` — **5/5 PASS**,
- helper API Contract Gate: #348 / run `34747599327` — **PASS**,
- PostgreSQL: **230 tests / 3122 assertions**,
- deterministic restore: **115 source tables → 115 restored tables**, `RESTORE_DRILL_HARNESS=PASS`.

Clean accepted implementation:

- accepted commit: `994061bac8b30ff19320a5f415efad230c76b80a`,
- accepted tree: `84ad523a6734d66842755bfc41642c1ce7cfe33e`,
- accepted Implementation CI: #345 / run `34747883792` — **5/5 PASS**,
- accepted API Contract Gate: #349 / run `34747883844` — **PASS**,
- PostgreSQL: **230 tests / 3122 assertions**,
- deterministic restore: **115 → 115**, `RESTORE_DRILL_HARNESS=PASS`.

The accepted tree preserves:

- Stage-4 authority: **170 nodes / 112 implemented nodes / 112 implemented steps**,
- Stage-5 formal-document migration authority: **11 materialized steps**, unchanged plan/execution identities,
- API contract: **187 requirement rows / 173 canonical operations / 14 aliases**,
- no new migration step,
- no new API operation,
- PKK provider runtime: **FROZEN_UNTIL_EXPLICIT_UNFREEZE**.

The next implementation slice is `FORMAL-DOC-011`: formal-documents UI for preview, approval, freshness, delivery history and the signed-scan upload handoff.
