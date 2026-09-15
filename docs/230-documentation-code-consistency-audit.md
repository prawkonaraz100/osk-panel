# 230. Documentation ↔ code consistency audit

Data: 2026-09-15

**Status:** `PASS_AFTER_DOCUMENTATION_SYNC`  
**Scope:** `main@7ee7093b1d86498e270f2cd5710c2b5bf9ffad19` + documentation-sync branch  
**Code changes in this audit:** **none**

**Integration result:** first audit merged as PR #120 to `main@20e6989486bd27c9331a8f5dd55acc6f2b0a3d2b`; post-merge Implementation CI #702 (`34997297399`) passed 6/6 and API Contract Gate #526 (`34997297369`) passed. Sections 1–12 below remain the historical record of that first-pass audit scope. The independent second-pass verification is recorded in section 13 of this same audit document so the repository does not create a redundant status document.

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


## 13. Independent second-pass re-audit — 2026-09-15

**Purpose:** verify the documentation corrections against the unchanged materialized runtime and remove remaining cases where historical design/gate metadata could be read as current implementation status.

**Code changes in the second pass:** **none**. The re-audit branch changes documentation/specification files only; application runtime, routes, migrations and tests are used as evidence, not rewritten to fit documentation.

### 13.1 Authority used

For current implementation/backlog/freeze:

- `docs/227-current-project-status-authority.md`,
- `specs/current-project-status.yml`.

For physical truth:

- HTTP/web route binding: `routes/web.php` + referenced controller/service,
- application modules/UI: `app/Modules/**` and `resources/js/**`,
- database materialization: `database/migrations/**`, `app/Support/Migrations/**` and executable DB tests,
- executable behavior: `tests/**`.

Legal/design/preservation decisions retain their existing precedence and were not rewritten to match implementation.

### 13.2 Verified current implementation state

The independent pass reconfirmed:

- exactly **160** physical `/api/v1` bindings in `routes/web.php`,
- exactly **173** canonical OpenAPI HTTP operations and no duplicate `operationId`,
- exactly **15** OpenAPI-only operations: **14 PKK/PWPW** preserved/deferred operations plus **1 provider payment webhook**,
- exactly **2** intentional runtime-only compatibility/development endpoints:
  - `GET /api/v1/development/sample/legal/terms/current`,
  - `POST /api/v1/internal-exam-stations/heartbeat`,
- provider-specific PKK/PWPW controller/service/routes/UI remain absent and frozen; local encrypted course-scoped PKK identity and manual entry remain implemented,
- registration UI is not materialized,
- `/licencje/wykup` is not materialized although `POST /api/v1/license-orders` exists,
- `/egzamin-wewnetrzny/wykup` is not materialized although `POST /api/v1/internal-exam/orders` exists,
- no Playwright/Cypress/browser-E2E suite is present,
- current SPA dispatch remains a lightweight pathname shell in `resources/js/App.vue`.

### 13.3 Retention executor verification

The re-audit also checked the implementation rather than inferring it from the earlier production-readiness plan:

- `app/Support/Privacy/RetentionExecutor.php` exists,
- `app/Console/Commands/RetentionRunCommand.php` exposes the privileged CLI entry point,
- destructive execution is disabled by default through `RETENTION_EXECUTOR_ENABLED=false`,
- the currently executable data class is restricted to `idempotency_records`,
- execution requires exact policy version, nonblank reason, explicit `--execute`, exact confirmation token, PostgreSQL advisory transaction lock and server-side candidate-count fence,
- there is no HTTP retention endpoint and no automatic hard-delete schedule,
- expansion to additional data classes remains a separate reviewed-authority decision.

This is why `specs/privacy/retention-schedule.yml` now describes the executor as `IMPLEMENTED_BOUNDED_TECHNICAL_TTL_IDEMPOTENCY_ONLY` instead of implying that all retention classes have an executable purge path.

### 13.4 Documentation corrections completed in this pass

The pass corrected only documents related to confirmed drift:

- current-status/head evidence wording is non-self-referential: live HEAD authority is the `main` ref, while embedded SHAs are verified evidence baselines,
- Stage 4 point-in-time fields such as `implementation_started`, `Laravel_migrations_created`, `OPEN/PENDING` are explicitly historical where later materialization is proven,
- screen/menu preservation evidence is separated from physical route materialization,
- current module documentation lists the modules actually present in `app/Modules/**`,
- registration/sample-Terms, settings, Student Progress and formal-document status wording is aligned with materialized runtime,
- license/exam purchase screen specs explicitly distinguish preserved screen requirements from currently absent purchase UI,
- retention and production-readiness records distinguish repository substrate evidence from target-production evidence.

No architecture assumption, legal rule or preserved confirmed capability was removed or rewritten merely to fit current code.

### 13.5 Remaining work and acceptance boundary

This re-audit does **not** claim that the remaining productization work is complete. The current repository-owned gaps remain:

1. registration UI,
2. license purchase UI,
3. internal-exam purchase UI,
4. browser E2E golden paths,
5. lightweight frontend routing/state architecture,
6. target production external evidence.

PKK/PWPW remains `FROZEN_UNTIL_EXPLICIT_UNFREEZE` and is not reactivated by this audit.

The documentation corrections are complete as a candidate. Acceptance still requires the normal PR validation and, after promotion, authoritative `main` validation; until those checks exist, this section must not be read as post-merge CI evidence.
