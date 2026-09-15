# 217. PROD-DEPLOY-PREFLIGHT-001 closure

Data: 2026-09-15

**Gate:** `PROD-DEPLOY-PREFLIGHT-001`  
**Status:** `PASS_REPOSITORY_DEPLOYMENT_PREFLIGHT`

## Candidate evidence

Exact validated implementation:

`426348b8574050ab8291ab9ac499319d28431a95`

Implementation CI:

`34933620170`

Result:

- backend-quality — PASS,
- frontend-quality — PASS,
- contracts-and-traceability — PASS,
- secret-scan — PASS,
- runtime-tests-and-migrations — PASS.

PostgreSQL-backed suite:

- **358 tests passed**,
- **6124 assertions**.

Deterministic CI restore:

- **122 -> 122 tables**,
- critical tables checked: 10,
- fingerprint: `f3cab286cc8f0aabef219971d90afe424a8dab694c47ade2f517a3d95970716d`,
- snapshot RPO in CI: 0 seconds,
- Redis recovered empty: true,
- Moto S3 version restore: PASS,
- `RESTORE_DRILL_HARNESS=PASS`,
- `production_target_evidence=false`.

## Repository-owned deployment preflight closed

The repository now contains a read-only command:

`php artisan operations:production:preflight --json`

It fails closed on unsafe production configuration while performing no DB
mutation, no HTTP/provider call and no secret-value output.

The validated checks cover:

- production/non-debug application mode,
- HTTPS non-local application and password-reset URLs,
- configured APP_KEY,
- JSON stderr logging with non-debug level,
- PostgreSQL and non-optional SSL mode,
- Redis cache/session/queue,
- secure HTTP-only JSON sessions,
- durable failed-job visibility,
- S3 bucket/region and rejection of known local test defaults,
- real mail transport and non-placeholder sender,
- independent sensitive-identifier lookup key,
- internal-exam TTL, verifier-key, HTTPS and S3 document-storage config,
- incident contact references,
- retention executor disabled at normal production baseline.

## Release artifact verification

The accepted-branch release job is wired to verify before upload:

- exact Git-SHA archive name,
- SHA-256 checksum,
- manifest SHA/name/checksum identity,
- no environment file,
- no runtime logs,
- no runtime cache,
- no runtime sessions,
- no compiled runtime views.

The release builder itself excludes that runtime state.

## External evidence remains external

This gate does **not** make the repository or target environment fully
production-ready by itself.

Still required on the actual target environment:

1. PostgreSQL PITR/off-primary backup drill,
2. object-storage versioning and restore drill,
3. measured target RPO/RTO,
4. secret-manager or equivalent protected injection evidence,
5. monitoring dashboards and alert routes,
6. paging smoke reaching a real operator,
7. actual reconciliation scheduler and alert-delivery smoke,
8. target release smoke.

## Deferred boundaries preserved

PKK/PWPW remains **FROZEN** and is not required for the core deployment
preflight.

The payment-provider-specific webhook remains an external provider boundary and
is not made a prerequisite by this provider-neutral gate.

## Promotion rule

This closure commit itself must pass exact-head Implementation CI before
promotion to `docs-consolidation-2026-09-05`.

After promotion the accepted-branch push must additionally prove the
`release-artifact` job, including the new immutable artifact verification
step.

Only that accepted evidence completes this gate.
