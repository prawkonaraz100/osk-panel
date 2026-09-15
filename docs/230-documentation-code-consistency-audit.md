# 230. Documentation ↔ code consistency audit

Data: 2026-09-15

**Status:** `PASS_AFTER_DOCUMENTATION_SYNC`  
**Scope:** `main@7ee7093b1d86498e270f2cd5710c2b5bf9ffad19` + documentation-sync branch  
**Code changes in this audit:** **none**

## 1. Purpose and authority

This audit compares current documentation/specification claims against the materialized repository:

- Laravel physical routes: `routes/web.php`,
- application runtime: `app/Modules/**`,
- Stage 4/5 migrations and database guards: `database/migrations/**`, `app/Support/Migrations/**`,
- Vue workspaces/routes: `resources/js/**`,
- executable evidence: `tests/**`,
- canonical API contract: `specs/api/**`,
- current project status: `docs/227-current-project-status-authority.md` and `specs/current-project-status.yml`.

For **current implementation status**, `docs/227` + `specs/current-project-status.yml` override historical roadmaps, consolidation plans, mapping-readiness documents and old closure snapshots.

## 2. Result summary

After this sync:

- Core V1 repository status remains **complete**; this audit does not reopen Core domain/database work.
- PKK/PWPW provider integration remains **FROZEN_UNTIL_EXPLICIT_UNFREEZE**.
- Local, course-scoped PKK identity is **implemented** and can be entered manually.
- Provider-neutral Stage 4 PKK database substrate is **materialized**, but this is not a live PWPW integration.
- No PKK provider application controller/service, physical PKK HTTP route or PKK Vue workspace exists.
- Registration UI is not implemented; non-production sample Terms discovery exists and unblocks development only.
- License-purchase UI, internal-exam-purchase UI and browser E2E golden paths remain productization gaps.
- Production remains **BLOCKED_EXTERNAL_EVIDENCE**.

## 3. PKK/PWPW — exact runtime boundary

### Implemented now

Current code implements:

1. Formal `CourseEnrollment` with a local PKK identity in `pkk_profiles`.
2. PKK value encryption-at-rest and keyed lookup-hash handling.
3. Versioned/superseded PKK identity history when PKK or formal course context changes.
4. Manual PKK entry in the Course form.
5. Provider-neutral Stage 4 relational/invariant substrate, including:
   - `pkk_operations`,
   - `pkk_operation_attempts`,
   - `pkk_provider_profile_snapshots`,
   - lifecycle/reconciliation/configuration/signature/payload-protection structures,
   - fail-closed PostgreSQL guards without provider I/O.

Executable evidence includes `StudentsCoursesCoreTest`, `Stage4PkkTriggerGuardsTest` and provider-neutral Organization Settings tests.

### Not implemented / frozen

The repository currently does **not** implement:

- import/fetch from PWPW,
- PWPW live calls,
- provider-specific request/response DTOs,
- provider-specific status mapping,
- PKK provider credentials/onboarding runtime,
- test-connection runtime,
- provider command controller/service,
- physical PKK HTTP route bindings,
- provider-specific signed-XML semantics,
- provider-specific retry/reconciliation truth,
- PKK operational Vue workspace.

Unfreeze condition:

`AUTHORITATIVE_PWPW_GUIDANCE_OR_CONTRACT_AND_FRESH_REDIAGNOSIS`.

The service therefore works without PWPW integration/import. This freeze does **not** remove the local PKK identity used by the formal Course model.

## 4. API contract ↔ physical route audit

The audit counted:

- **160** physical `/api/v1` route bindings in `routes/web.php`,
- **173** HTTP operations in canonical OpenAPI.

OpenAPI-only operations: **15**.

Classification:

- **14 PKK/PWPW operations** — preserved future/provider-neutral contract, currently frozen and deliberately not physically bound,
- **1 provider payment webhook** — external-provider boundary, deliberately not physically bound.

Runtime additionally contains two intentional non-canonical/compatibility endpoints:

- `GET /api/v1/development/sample/legal/terms/current` — non-production sample Terms discovery,
- `POST /api/v1/internal-exam-stations/heartbeat` — compatibility alias; canonical operation remains the documented exam-station heartbeat capability.

The OpenAPI module files now mark deferred operations with `x-runtime-status`, so OpenAPI presence is no longer ambiguous with physical implementation.

## 5. Domain-model drift corrected

Two concrete PKK documentation drifts were corrected:

1. `docs/05-domain-model.md` previously described `pkk_operations.pkk_profile_id` as nullable.  
   Materialized Stage 4 schema requires `pkk_profile_id uuid NOT NULL`.

2. `PkkProfile` was described as a provider snapshot in older aggregate wording.  
   Current model separates:
   - `PkkProfile` = local, versioned course-scoped PKK identity,
   - `PkkProviderProfileSnapshot` = separate append-only provider observation history.

This now matches migrations and database guards.

## 6. Historical status drift corrected

The following documents contained historically correct but currently misleading active wording:

- `docs/09-roadmap.md`,
- `docs/81-developer-consolidation-plan.md`,
- `docs/90-preimplementation-checklist.md`,
- `docs/92-developer-readiness-score.md`,
- `docs/94-consolidation-next-actions.md`,
- `docs/95-open-items-severity.md`,
- `docs/99-staged-design-and-implementation-plan.md`.

They are now explicitly marked **historical/superseded** where appropriate. Their old state/gate evidence is preserved; they are no longer valid current backlog sources.

Likewise, mapping aggregates `docs/01`, `docs/02`, `docs/15` and `docs/16` now state that `READY_FOR_IMPLEMENTATION` is historical mapping readiness, not current code status.

## 7. Registration / sample Terms correction

Historical Auth closure correctly recorded that registration UI was blocked by the lack of public Terms discovery at that time.

Later `SAMPLE-DATA-001` changed the development boundary:

- sample Terms metadata endpoint exists,
- sample Terms document renders locally,
- registration backend accepts the sample version through normal validation,
- sample mode fails closed in production.

Current truthful state:

- registration backend: implemented,
- registration UI: **not implemented**,
- development Terms discovery: **implemented as sample-only**,
- production real Terms authority/resolver: **still required**.

Historical Auth docs now retain their original gate truth while recording the later development-only unblock.

## 8. Test-strategy correction

`docs/84-test-strategy.md` previously read as though a PKK fake provider and browser E2E suite were current obligations already available.

It now distinguishes:

- executable current tests: local PKK identity + provider-neutral DB guards without provider I/O,
- future PKK adapter tests: deferred until explicit unfreeze,
- browser E2E matrix: target strategy; the suite is still a productization gap.

## 9. Production-operation correction

PKK credentials are no longer listed as an unconditional Core production-release requirement.

They become relevant only after:

1. explicit PKK unfreeze,
2. authoritative PWPW requirements/contract,
3. verified adapter implementation.

Frozen PKK/PWPW remains non-blocking for Core go-live.

## 10. Current repository-owned gaps after audit

This audit did not discover a new Core domain/database blocker.

Current productization gaps remain:

1. registration UI,
2. license purchase UI,
3. internal-exam purchase UI,
4. browser E2E golden paths,
5. frontend routing/state architecture remains lightweight,
6. target production external evidence.

PKK/PWPW is **not** part of that execution sequence until PWPW information is received and verified.

## 11. Files intentionally treated as historical evidence

Historical gate/audit documents are not rewritten to pretend they occurred under today's state. Instead, current-status notes were added only where old wording could plausibly be mistaken for a current instruction.

Rule:

- history stays history,
- current authority explicitly points to `docs/227` / `specs/current-project-status.yml`,
- no plan is described as completed unless code/evidence proves it,
- no preserved future contract is described as physically implemented unless a route/service/UI/test binding exists.

## 12. Final consistency rule

For future work:

1. determine current implementation state from `docs/227` + `specs/current-project-status.yml`,
2. verify physical truth in code/routes/migrations/tests,
3. use OpenAPI/screens/reverse-engineering specs to preserve required capability,
4. treat `x-runtime-status` deferred operations as contract/evidence, not physical runtime,
5. keep PKK provider integration frozen until authoritative PWPW requirements are received,
6. after PWPW guidance arrives, re-diagnose preserved PKK API/DB/security assumptions before implementing any provider-specific behavior.
