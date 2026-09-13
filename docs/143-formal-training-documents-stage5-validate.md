# 143. Formal training documents — relational and immutability validation

Status: `FORMAL-DOC-006 validation candidate`

## Scope

FORMAL-DOC-006 materializes the complete Stage-5 **validate** phase for all four formal-document extension nodes.

The Stage-5 plan identity remains:

`34cada121f4fd4963b1461308fb517c50ee647005ce9421f4d1af40c80d44b99`

The Stage-5 execution identity becomes:

`5fc0930972a65f08406d23cd01a8c37d6ab99e2ca7039307fe3ae928f1286a68`

The registry contains exactly:

- 4 × `expand`,
- 1 × `preflight`,
- 1 × `backfill`,
- 4 × `validate`,
- **10 total steps**.

FORMAL-DOC-006 does not materialize `contract`.

## Course document-mode validation

The validate migration:

- requires zero courses with null `document_mode`,
- requires zero courses with null `document_mode_selected_at`,
- validates the existing `paper|electronic` domain check,
- creates and validates a completeness check for the already backfilled population,
- preserves the physical columns as nullable until the later contract gate,
- installs a PostgreSQL trigger preventing normal document-mode selection changes when server time is at or after either the old or new `started_at` boundary.

This closes the migration and direct-SQL bypass boundary without pretending the final column-level `NOT NULL` contract has already been applied.

## Template validation

The template validate migration:

- preflights existing effective intervals for overlap,
- installs PostgreSQL `btree_gist`,
- adds an exclusion constraint preventing overlapping effective intervals for the same `document_type`,
- allows adjacent half-open intervals,
- allows independent intervals across distinct document types,
- prevents UPDATE or DELETE of a template after any formal document revision references it.

Unused template rows remain administratively mutable subject to the interval/domain constraints.

## Formal document validation

The document validate migration first fails closed if any existing revision:

- references a missing course,
- references a course from another organization,
- references a missing/global/cross-tenant canonical asset,
- references a template whose exact document type/version/renderer/hash snapshot does not match.

After the prechecks, it creates supporting non-partial unique indexes and validates exact foreign keys for:

- `(organization_id, course_enrollment_id)` → course enrollment,
- `(organization_id, asset_id)` → tenant file asset,
- exact template identity plus document type/version/renderer/content-hash snapshot.

Every formal training document revision becomes immutable after INSERT. UPDATE and DELETE fail at the database boundary; a correction must create a new revision.

## Event validation

The event validate migration:

- requires exact same-tenant relation to the formal document revision,
- requires every non-null optional asset to be same-tenant,
- requires the optional asset to be `ready` at attachment time,
- locks the selected asset row during the readiness check,
- makes document events append-only by rejecting UPDATE and DELETE.

No generic JSON or raw-personal-data payload channel is introduced.

## Candidate-key support

The validate phase adds only the candidate indexes necessary for exact composite foreign keys:

- `course_enrollments (organization_id, id)`,
- `file_assets (organization_id, id)`,
- `formal_training_documents (organization_id, id)`,
- exact template snapshot candidate key.

These do not rewrite the frozen Stage-4 migration authority or execution identity.

## Registered validate files

1. `2026_09_13_000070_validate_formal_document_mode.php`
   - SHA-256 `730018eede38bf2d5232622fb5b2dfb275018fac6f7f1778cf74469847aecf5e`

2. `2026_09_13_000080_validate_formal_training_document_templates.php`
   - SHA-256 `df52677b316893f0bc5c8c26310263ba4c70a786ece191cbaf06aa1b754881ac`

3. `2026_09_13_000090_validate_formal_training_documents.php`
   - SHA-256 `f61ee56ac06bca191a6f277f87e3c7dea2bb40f7fbd0758fb0a9b2cf4be177be`

4. `2026_09_13_000095_validate_formal_training_document_events.php`
   - SHA-256 `498238b1faf7b76f4406a9c8d5fdc9518a6e54a8ead114f811e859a1fc1f4dbb`

The `000095` suffix is deliberate: the registry requires lexical migration-name order, so it preserves course → templates → documents → events execution order without making `000100` sort before `000070`.

## Runtime proof

The FORMAL-DOC-006 feature proof covers:

1. all four validate steps materialize and controlled re-entry is idempotent,
2. `contract` remains fail-closed,
3. physical document-mode columns remain nullable before contract,
4. explicit null mode state is rejected after validation,
5. document mode may change before start but not at/after `started_at`,
6. same-type template intervals cannot overlap,
7. adjacent and distinct-document-type intervals remain valid,
8. exact same-tenant course and asset relations are enforced by PostgreSQL,
9. template document-type/version/renderer/hash snapshot must match,
10. document revisions reject UPDATE and DELETE,
11. used template rows reject UPDATE and DELETE,
12. event document relation is same-tenant,
13. optional event asset must be same-tenant and ready,
14. events reject UPDATE and DELETE.

The test teardown explicitly removes Gate-6-only validate artifacts so earlier phase-specific regression tests continue to prove their historical boundaries independently.

## Preservation

FORMAL-DOC-006 must preserve:

- Stage-4 plan identity `d2fd6bc999dc2a5ee024e9b91bf00f5a5dad29575554a0ba42eba67107c23f10`,
- Stage-4 execution identity `1d2f1d3a47a4a09b749d0b164137857a241c07afbaad02789ec21e03cb4118ce`,
- Stage-4 counts **170 / 112 / 112**,
- Stage-5 plan identity `34cada12...`,
- PKK provider runtime frozen.

## PASS criteria

PASS requires one exact tree proving:

- exact four validate migration hashes,
- Stage-5 execution identity `5fc09309...`,
- exactly 10 materialized Stage-5 steps,
- validate controlled execution PASS on PostgreSQL,
- contract phase still fail-closed,
- document-mode completeness and post-start lock PASS,
- template interval exclusion and used-template immutability PASS,
- same-tenant course/asset/document FKs PASS,
- exact template snapshot FK PASS,
- immutable document revision PASS,
- ready optional event asset guard PASS,
- append-only event history PASS,
- no final column-level NOT NULL contract yet,
- Stage-4 170/112/112 preservation PASS,
- backend/static analysis PASS,
- frontend quality PASS,
- contracts/traceability PASS,
- secret scan PASS,
- deterministic restore harness PASS.

## Next gate after PASS

**FORMAL-DOC-007 — contract the course document-mode columns to the final NOT NULL shape.**

FORMAL-DOC-007 may materialize only the reserved `contract` phase of `S5DOC-ALTER-COURSE-ENROLLMENTS-DOCUMENT-MODE`. Formal document generation/approval runtime and PKK provider runtime remain outside this gate.
