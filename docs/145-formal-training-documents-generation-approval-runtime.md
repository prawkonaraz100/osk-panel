# 145. Formal training documents — generation and approval runtime

Status: `FORMAL-DOC-008 PASS`

## Scope

FORMAL-DOC-008 activates the first application runtime on top of the fully contracted Stage-5 formal-document schema.

It implements:

- current-evidence preview,
- exact effective template binding,
- approval of one exact evidence/template/course-version snapshot,
- deterministic canonical PDF rendering,
- tenant-owned `file_assets` persistence,
- immutable formal document revision creation,
- append-only `generated` and `approved` events,
- audited/outbox approval,
- revision history list,
- verified PDF download.

It does not implement:

- direct PDF editing,
- mutable prior revisions,
- qualified electronic signature, XAdES or an equivalent legal-signature claim,
- print/presentation/signed-scan lifecycle commands,
- PKK provider runtime or any PWPW call.

PKK remains frozen.

## Permissions

New permission codes:

- `formal_documents.view`,
- `formal_documents.approve`,
- `formal_documents.download`.

All use the existing `student_scoped` resolver. Tenant and assigned-student semantics therefore remain identical to the existing Courses domain.

Role-template intent:

- Owner: all current core permissions through the existing Owner strategy,
- OfficeAdmin: receives the permissions through `courses_and_training`,
- Instructor: view/download; approval is an explicit conditional grant,
- Lecturer: view/download.

Permission checks are still performed against materialized membership decisions at runtime. Role-template names are not authorization shortcuts.

## HTTP contract

Gate 8 adds four canonical operations:

1. `GET /course-enrollments/{courseEnrollmentId}/formal-documents/preview`
2. `GET /course-enrollments/{courseEnrollmentId}/formal-documents`
3. `POST /course-enrollments/{courseEnrollmentId}/formal-documents`
4. `GET /formal-training-documents/{documentId}/file`

The canonical API inventory grows from **180 / 166** to **184 HTTP requirement rows / 170 unique operations**. Existing 14 shared-operation aliases remain unchanged.

Approval requires:

- UUID `Idempotency-Key`,
- exact `If-Match: "vN"` CourseEnrollment version,
- document type,
- current requirements revision,
- exact evidence SHA-256,
- exact template id/version/renderer/hash binding returned by preview.

Unknown request fields are rejected.

## Canonical evidence bundle

Evidence is rebuilt server-side. Client input never supplies formal facts.

The bundle contains only canonical sources currently available in the repository:

- Student identity fields required by the present document design,
- CourseEnrollment version, requirements revision, category, training type, started-at, location, lead instructor and document mode,
- exact current TrainingRequirementProfile,
- lead instructor identity/authorization snapshot,
- ordered TrainingHourLedger rows, with TrainingSession/instructor context when a row has a session,
- ordered current RecognizedExternalTraining projection matching current course category and training type,
- deterministic OSK/external/combined minute totals.

Plaintext PESEL and PKK are not loaded into the evidence bundle.

The theory journal uses the same evidence authority but renders only theory ledger lines. It does not invent curriculum module names when no canonical module-level source exists.

## Deterministic evidence hash

Associative evidence maps are recursively key-sorted. Ordered lists retain their canonical database order.

The evidence bundle is encoded as JSON with stable Unicode/slash/number options and SHA-256 hashed.

The hash therefore changes when a relevant canonical fact changes, including a direct database drift that fails to increment CourseEnrollment version.

Approval always rebuilds evidence under a locked CourseEnrollment and compares it to the preview hash. Stale previews fail closed.

## Template authority

Two initial platform-global template bindings are seeded:

- `training_record_card / v1`,
- `theory_delivery_journal / v1`.

Both use renderer:

`formal-training-v1`

and deterministic template content hashes derived from document type, version and renderer version.

Preview resolves exactly one effective template. Approval resolves again and requires the client-provided binding to match the still-effective immutable template row exactly.

## Approval transaction

Approval:

1. authorizes the exact course through `formal_documents.approve`,
2. locks CourseEnrollment,
3. verifies `If-Match`,
4. verifies requirements revision,
5. rebuilds canonical evidence,
6. verifies exact evidence hash,
7. resolves and verifies exact template binding,
8. reuses an already-existing identical evidence/template/renderer document if present,
9. renders deterministic canonical PDF bytes,
10. writes a tenant `file_assets` row with status `ready`,
11. allocates the next per-course/per-document-type revision,
12. inserts one immutable `formal_training_documents` row with approval and generation snapshots,
13. inserts append-only `generated` and `approved` events,
14. records `formal_document.approved` audit/domain/outbox evidence.

A correction never updates a prior document row. Correct the canonical source, rebuild preview, approve again, and revision N+1 is created.

## Renderer

`FormalTrainingDocumentRenderer` is deterministic for the same:

- evidence bundle,
- template version/hash,
- renderer version,
- document type.

Reviewer identity and approval timestamp are intentionally not embedded in the PDF bytes; they are immutable database/event metadata. This preserves the authority rule that identical evidence + template + renderer yields identical canonical content bytes.

The rendered training record includes current requirement minima and accepted minute totals. It does not hardcode 26 hours.

The theory journal explicitly states that module names are not generated without a canonical module-level source.

## Download integrity

Download:

- authorizes the document through its owning course,
- requires a same-tenant `ready` asset with purpose `formal_training_document`,
- requires asset SHA-256 to equal immutable document `content_hash`,
- rehashes the stored bytes and verifies stored byte length,
- fails closed if bytes/metadata differ,
- records `formal_document.downloaded` audit/outbox evidence.

Unlike the internal-exam answer sheet flow, Gate 8 does not silently reconstruct missing bytes because the full historical evidence JSON is not separately persisted as a recoverable snapshot. Missing or corrupted canonical bytes therefore fail closed rather than fabricate a replacement.

## API idempotency and deterministic deduplication

Approval is protected at two levels:

- standard tenant-scoped UUID idempotency record,
- database deterministic evidence uniqueness.

A retry with the same idempotency key replays the safe response.

A new idempotency key for the exact same evidence/template/renderer resolves to the existing immutable document rather than creating a fake new revision.

## Audit privacy

New audit actions:

- `formal_document.approved`,
- `formal_document.downloaded`.

They use the existing safe `resources.lifecycle.v1` payload validator. Audit/outbox payloads contain document state/type metadata only; no Student PESEL, PKK, PDF content or raw evidence payload is copied into audit/outbox.

## Runtime proof

FORMAL-DOC-008 tests must prove:

1. preview returns exact course/requirements versions, evidence hash, template binding and canonical totals,
2. approval materializes exactly one immutable revision, ready asset and generated/approved events,
3. same Idempotency-Key replay returns the same revision,
4. a new idempotency key with unchanged evidence still does not create a duplicate revision,
5. PDF download bytes match immutable content SHA-256,
6. approval/download audit and outbox evidence are written,
7. preview becomes `fresh` after approval,
8. direct evidence drift after preview is rejected even if course version did not change,
9. reviewed source correction plus new course version produces revision 2 and preserves revision 1,
10. theory journal uses the same canonical evidence without inventing module names,
11. cross-tenant preview and download fail closed,
12. API contract remains structurally complete at 184 requirement rows / 170 canonical operations,
13. Stage-4 170/112/112 and Stage-5 11-step migration authority remain unchanged,
14. PKK provider runtime remains frozen.

## Next gate after PASS

**FORMAL-DOC-009 — materialize document delivery lifecycle and freshness projection.**

That gate may add explicit print, signed-scan attachment and electronic-presentation events plus a reusable stale/current projection. It must not claim any statutory electronic-signature mechanism without separate legal and technical verification.


## Closure evidence

FORMAL-DOC-008 is closed PASS on the exact clean-promoted implementation tree.

- validation-only PR: #65, closed without merge,
- validated helper commit: `f579b4edc1583ee75ca4f3cae4f608f2f1c58cbd`,
- validated helper tree: `c09c6ccc780af787adc71ce714a2db55890e64cf`,
- helper Implementation CI: run `34743084750`, 5/5 PASS,
- helper API Contract Gate: run `34743084752`, PASS,
- accepted implementation commit: `05a6f4df9552075a3c34a9ede262654e6ef47404`,
- accepted implementation tree: `c09c6ccc780af787adc71ce714a2db55890e64cf`,
- accepted Implementation CI: run `34743244625`, 5/5 PASS,
- accepted API Contract Gate: run `34743244478`, PASS,
- PostgreSQL suite: **224 tests / 3028 assertions**,
- deterministic restore: **115 → 115**, `RESTORE_DRILL_HARNESS=PASS`,
- backend Pint/PHPStan: PASS,
- frontend lint/typecheck/build/audit: PASS,
- contracts and changed-module traceability: PASS,
- secret scan: PASS,
- Stage-4 authority remains **170 / 112 / 112**,
- Stage-5 formal-document migration authority remains **11 steps** with execution identity `31704fcab61761aa9a952d824dc543a6f46e7a3717792d0a9349cefaaf57651f`,
- PKK provider runtime remains `FROZEN_UNTIL_EXPLICIT_UNFREEZE`.

Next gate: **FORMAL-DOC-009 — materialize document delivery lifecycle and freshness projection**.
