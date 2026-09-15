# 169. CORE-V1-RESOURCE-ASSET-UI-001 — closure

Data: 2026-09-14

**Status:** `PASS`

## Exact scope

This gate wires the two confirmed optional photo fields in Resources UI:

- Staff photo from `specs/screens/staff-create.yml`,
- Vehicle photo from `specs/screens/vehicle-edit.yml`.

It does **not** invent file-upload controls for vehicle inspection, OC or AC documents. Their existing validity-date runtime remains unchanged.

## Runtime flow

Both confirmed photo fields now execute the existing canonical transport:

`resource save -> /uploads/presign -> direct private PUT -> /uploads/{id}/complete -> resource PATCH photo_asset_id`

The client calculates SHA-256 before presign and supplies the same digest at completion. Backend UploadsAssets remains authoritative for MIME, size, tenant, purpose, content and ready-state validation.

Create flows materialize Staff/Vehicle first so the upload is bound to a concrete canonical parent. No client-side organization id or parallel ownership model is invented.

If object upload and completion succeed but the final resource PATCH fails, the ready asset id remains in the form and the next Save retries only attachment. The browser does not upload the same bytes again.

Vehicle edit also supports detaching the current photo by writing `photo_asset_id = null`; immutable file-asset history is not physically deleted by this UI action.

## Preservation boundary

- no schema/migration changes,
- no new upload authority,
- no document-file field invented where screen evidence does not confirm one,
- no changes to vehicle document validity history,
- no PKK/PWPW runtime changes.

## Executable evidence

Implementation:

`84e7780ee0c1a6fa32d06359e3e5beac1ed49374`

CI #487 proved frontend and PostgreSQL runtime; backend-quality exposed only Pint formatting in newly touched test files.

Corrective exact head:

`93547bf0294ec9cee617749054ffb3ccdd843290`

Final evidence:

- Implementation CI #488 / run `34812710601`: **5/5 PASS**
- API Contract Gate #363 / run `34812710579`: **PASS**
- PostgreSQL: **271 tests / 5181 assertions — PASS**
- deterministic restore: **121 -> 121 PASS**
- schema fingerprint: `09c57afe2501c79a3a684c0a695b4619114f94ddc24c9bca33728d99c1abb155`
- frontend lint/typecheck/build/audit: **PASS**
- Pint + PHPStan: **PASS**
- contracts/traceability: **PASS**
- secret scan: **PASS**

## Next safe slice

The next dependency-closed HTTP gap is `CORE-V1-DICTIONARIES-LANGUAGES-001`.

It can expose only the already-materialized active global `languages` catalog. Product-specific language availability remains authoritative in product-capability endpoints and must not be inferred here.

PKK/PWPW runtime remains frozen.
