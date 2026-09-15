# 218. Production go-live evidence gate

Data: 2026-09-15

**Gate:** `PROD-GO-LIVE-EVIDENCE-001`  
**Status:** `PASS_REPOSITORY_EXTERNAL_EVIDENCE_VALIDATOR`

## Purpose

Repository hardening now has health/readiness, immutable artifacts, bounded retention and a fail-closed production configuration preflight.

The remaining blockers are external facts. This gate does not invent those facts. It provides one strict, machine-readable validator that refuses go-live evidence unless the complete required set is supplied for one exact immutable release.

## Command

`php artisan operations:go-live:evidence <manifest.json> --release=<40-char-sha> --artifact-sha256=<64-char-sha256> --json`

The command performs no network calls, no business writes and no production activation.

## Sanitized manifest

The manifest is deliberately narrow. Every evidence row contains exactly:

- `id`,
- `status=PASS`,
- `observed_at`,
- `evidence_ref`,
- typed `details`.

Unknown fields are rejected. This prevents the manifest from becoming a dumping ground for credentials, paging tokens, phone numbers, raw backup metadata or personal contact information.

`evidence_ref` is a short opaque/query-free reference to an external operational record.

## Required evidence set

The gate requires:

1. target production configuration preflight,
2. target infrastructure restore drill,
3. backup + PITR evidence,
4. object versioning + restore evidence,
5. secret-manager/equivalent runtime injection evidence,
6. monitoring dashboards + critical alert routes,
7. contact roster resolution + paging smoke reaching a human,
8. reconciliation scheduler execution + alert delivery reaching an operator,
9. target release smoke bound to the exact release SHA and artifact SHA-256.

## Disaster recovery enforcement

The existing authority already defines measurable limits, so the validator enforces them rather than accepting a free-form PASS:

- PostgreSQL RPO <= 5 minutes,
- PostgreSQL RTO <= 60 minutes,
- object RPO <= 60 minutes,
- object RTO <= 240 minutes,
- isolated restore,
- critical integrity assertions,
- empty-Redis recovery,
- restore drill no older than 90 days.

## Truth boundary

A successful validation means only:

`GO_LIVE_EVIDENCE=PASS`

It does **not**:

- switch traffic,
- change DNS,
- provision infrastructure,
- enable provider integrations,
- claim the repository itself observed production,
- store secrets or private contact details.

Production activation remains an explicit operator/deployment action.

PKK/PWPW remains frozen and is not part of the required evidence set. Provider-specific payment evidence is not required while that provider boundary is not launched.


## Repository closure

Validated candidate:

`8e252c9f091216375825ccada208f3a5c17600b1`

Implementation CI run:

`34940714606`

Result:

- backend-quality — PASS,
- frontend-quality — PASS,
- contracts-and-traceability — PASS,
- secret-scan — PASS,
- runtime-tests-and-migrations — PASS,
- PostgreSQL — **364 tests / 6140 assertions**,
- deterministic CI restore — **122 -> 122 PASS**,
- restore schema fingerprint — `f3cab286cc8f0aabef219971d90afe424a8dab694c47ade2f517a3d95970716d`.

The restore proof remains CI-emulated and explicitly reports
`production_target_evidence=false`.

Repository status therefore means:

`GO_LIVE_EVIDENCE_VALIDATOR=PASS`

It does **not** mean:

`PRODUCTION_GO_LIVE_EVIDENCE=PASS`.
