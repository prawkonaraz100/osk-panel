# FORMAL-DOC-011 — Formal documents UI

Status: `FORMAL-DOC-011 validation candidate`

## Scope

This gate materializes the already accepted formal-document runtime in the existing student profile. It introduces no new backend operation, database migration, document schema, permission, or PKK/PWPW dependency.

The UI is mounted in:

`/kursanci/{student_id}`

inside the existing **Profil kursanta** flow, after the course list and before Student Finance.

The implementation component is:

`resources/js/modules/FormalDocuments/FormalTrainingDocumentsPanel.vue`

The panel remains course-scoped. When the student has more than one course, the operator explicitly selects the course whose documentation is being reviewed.

## Existing API contract reused

FORMAL-DOC-011 consumes only operations already accepted by FORMAL-DOC-008 through FORMAL-DOC-010:

- `formal_documents.preview`,
- `formal_documents.list`,
- `formal_documents.freshness`,
- `formal_documents.approve`,
- `formal_documents.download`,
- `formal_documents.events.list`,
- `formal_documents.delivery.record`,
- `uploads.presign`,
- `uploads.complete`.

The canonical API inventory therefore remains:

- **187 HTTP requirement rows**,
- **173 unique canonical operations**,
- **14 shared operation aliases**.

## Read model and freshness

For the selected course, the panel loads:

1. immutable formal-document revision history,
2. the reusable current-source freshness projection,
3. the current server-generated preview for `training_record_card`,
4. the current server-generated preview for `theory_delivery_journal`.

The browser does **not** build or alter the evidence bundle.

The UI shows:

- document mode,
- combined theory minutes,
- combined practical minutes,
- current freshness,
- latest revision,
- approval timestamp,
- abbreviated evidence hash,
- older immutable revisions.

If current source evidence differs from the latest document evidence, the UI shows **Wymaga regeneracji**.

A stale historical revision remains downloadable and its history remains readable, but delivery actions for that document type are disabled until a new exact revision is approved.

A freshness read never creates a lifecycle event.

## Exact approval boundary

Approval always uses the exact preview returned by the server.

The request carries:

- `If-Match: "v{course_version}"`,
- `requirements_revision`,
- `evidence_bundle_hash`,
- `template_id`,
- `template_version`,
- `renderer_version`,
- `template_content_hash`.

The approval request is idempotent.

The UI cannot submit its own evidence facts and cannot edit the canonical PDF. Source corrections must still happen through the existing audited source-domain commands and then produce a new preview/revision.

## Immutable revision history

Each accepted formal document revision remains independently accessible.

The UI supports:

- canonical PDF download,
- append-only lifecycle history,
- prior-revision download,
- prior-revision history.

Regeneration never overwrites or deletes an old business revision.

## Paper delivery

For a fresh paper-mode revision, the UI exposes:

- **Potwierdź wydruk** → append `printed`,
- **Dodaj podpisany skan** → signed-scan upload handoff.

The UI explicitly states:

- handwritten signature happens outside the system,
- the signed scan is a historical attachment to one exact immutable revision,
- the signed scan does not replace the canonical PDF.

## Signed-scan handoff

The signed-scan UI uses the FORMAL-DOC-010 transport exactly as accepted.

Client-side preconditions:

- PDF only,
- maximum 25 MiB,
- SHA-256 calculated in the browser.

Flow:

1. `POST /uploads/presign`,
2. purpose `formal_training_signed_scan`,
3. parent type `formal_training_document`,
4. parent id = exact formal-document revision id,
5. direct PUT to the private presigned object URL,
6. `POST /uploads/{uploadId}/complete` with the SHA-256,
7. `POST /formal-training-documents/{documentId}/delivery-events` with `signed_scan_attached`.

The server remains authoritative for tenant/student scope, content validation, MIME, size, hash, ready status and exact parent binding.

### Partial-failure recovery

If upload completion succeeds but the final `signed_scan_attached` command fails, the UI retains the ready asset id in the current session and exposes **Ponów podpięcie**.

The operator is explicitly told not to upload the file again. The retry performs only the delivery-event attachment command.

## Electronic delivery

For a fresh electronic-mode revision the UI exposes **Oznacz jako przedstawiony**, which appends `electronic_presented`.

The UI explicitly states that this records only delivery/presentation of the immutable revision.

It does **not** claim:

- qualified electronic signature,
- XAdES,
- that authenticated approval equals any statutory electronic-signature mechanism.

## Archived and cancelled contexts

For an archived student or cancelled course:

- existing document history remains readable,
- existing PDF revisions remain downloadable,
- new approval and delivery actions are disabled in the UI.

Server-side authorization remains authoritative even if client state is stale.

## Document-mode boundary

FORMAL-DOC-011 does not invent a new document-mode mutation operation.

The UI displays the mode returned by the formal-document preview/revision. Changing `paper/electronic` remains outside this UI slice because no accepted Course API operation currently exposes that mutation.

## Security and privacy

The panel:

- receives no plaintext PESEL,
- receives no plaintext PKK,
- cannot choose storage disk or storage key,
- cannot upload server-generated canonical formal PDFs through the client upload API,
- relies on existing `student_scoped` formal-document authorization,
- does not add PKK provider runtime.

## Screen authority and traceability

New screen authority:

`specs/screens/student-formal-documents.yml`

Existing student-detail authority now references that screen rather than duplicating the formal-document workflow.

Module traceability is materialized in:

- `specs/traceability/implementation/FormalDocuments.yml`,
- `specs/traceability/implementation/UploadsAssets.yml`,
- `specs/traceability/implementation/StudentsCourses.yml`.

Executable UI wiring is protected by:

`Tests\\Unit\\FormalDocumentsUiContractTest`

## Authority preservation

FORMAL-DOC-011 must preserve:

- Stage-4: **170 nodes / 112 implemented nodes / 112 implemented steps**,
- Stage-5 formal-document migration plan identity `34cada121f4fd4963b1461308fb517c50ee647005ce9421f4d1af40c80d44b99`,
- Stage-5 execution identity `31704fcab61761aa9a952d824dc543a6f46e7a3717792d0a9349cefaaf57651f`,
- Stage-5 materialized steps: **11**,
- API inventory: **187 / 173 / 14**,
- PKK provider runtime: **FROZEN_UNTIL_EXPLICIT_UNFREEZE**.

## Gate validation

FORMAL-DOC-011 is PASS only if the exact candidate tree proves:

1. formal documents panel is mounted in existing student profile,
2. both document types load server preview/list/freshness,
3. approval uses exact preview version/revision/hash/template binding,
4. stale revisions cannot be delivered,
5. prior immutable revisions remain downloadable and auditable,
6. paper print writes append-only delivery history,
7. paper signed scan performs presign → PUT → complete → attach,
8. partial attach failure retries without reupload,
9. electronic presentation is mode-specific and makes no e-signature claim,
10. archived/cancelled presentation disables new mutations,
11. no new API operation,
12. no new migration,
13. frontend lint/typecheck/build/audit PASS,
14. UI contract test PASS,
15. backend static analysis PASS,
16. PostgreSQL runtime suite PASS,
17. deterministic restore harness PASS,
18. traceability PASS,
19. secret scan PASS,
20. Stage-4/Stage-5 authorities remain unchanged,
21. PKK provider runtime remains frozen.
