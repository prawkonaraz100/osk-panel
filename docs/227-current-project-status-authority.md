# 227. Current project status authority

Data: 2026-09-15

**Authority:** `CURRENT-PROJECT-STATUS-001`  
**Status:** `CURRENT`

## Purpose

Ten dokument jest bieżącym source-of-truth dla pytania „na jakim etapie jest
`osk-panel`?”. Nie zastępuje legal/design/API/database specs. Zastępuje natomiast
historyczne interpretowanie starych statusów typu `READY_FOR_IMPLEMENTATION`
jako aktualnego backlogu.

## Runtime authority

Canonical publication branch:

`main`

Exact current repository HEAD authority: **Git ref `main`**. The document intentionally does not claim that an embedded SHA is the forever-current HEAD, because editing this file changes the HEAD.

Latest fully verified accepted `main` baseline before the current Internal Exam Purchase candidate:

`5fd3dfb4cceadd634fbc9b379b69f99b958079d0`

This is the License Purchase closure/status sync promoted by PR #126 after correcting
the lifecycle-state contract test. Embedded evidence below is a verified accepted
baseline, not a substitute for reading the live `main` ref.

Accepted Implementation CI:

`35018649697` / run #741 — **6/6 PASS**

Accepted immutable artifact for this verified baseline:

- artifact ID: `10416163884`,
- name: `osk-panel-5fd3dfb4cceadd634fbc9b379b69f99b958079d0`,
- release archive SHA-256:
  `93ce0b7bc76bec3424a4b4c336a92376b8511b3a82637e22b69aa61fd37579eb`,
- GitHub uploaded artifact ZIP SHA-256:
  `69bd158267a7c274bddfdb818202ad43e6645859f4afc7f52d4c0f6248fe0ce2`.

Runtime suite:

- PostgreSQL: **386 tests / 6392 assertions — PASS**,
- deterministic restore: **122 -> 122 PASS**,
- restored schema fingerprint:
  `f3cab286cc8f0aabef219971d90afe424a8dab694c47ade2f517a3d95970716d`.

## Repository branch authority

- `main` is the only canonical base/publication branch for new repository work.
- `docs-consolidation-2026-09-05` is historical/non-canonical after PR #117 and must
  not receive new accepted work.
- New task branches start from verified `main` and merge back to `main` through PR
  validation; no force-push/history rewrite of `main`.
- Full push/release Implementation CI is authoritative only on `main`.
- Historical branch cleanup is **PASS**. A guarded snapshot preserved the historical
  tips, verified all live refs against their exact SHA values, and cleanup run
  `34990474440` removed 287 verified historical refs.
- Live branch count was reduced from **289 -> 2**. The retained refs are `main` and
  `archive/branch-snapshot-2026-09-15`; the archive branch is evidence-only and must
  never be merged into `main`.
- Repository setting `delete_branch_on_merge` was observed as `false` during the
  2026-09-15 audit, so future merged task branches still require explicit cleanup.

## Core V1

`CORE_V1_REPOSITORY_COMPLETE = TRUE`

`REPOSITORY_ACTIONABLE_P0 = 0`

`REPOSITORY_ACTIONABLE_P1_FOR_CORE_LAUNCH = 0`

Backend/domain/API/database implementation exists for the Core V1 scope,
including:

- identity/session/password recovery/social auth,
- tenant isolation and permission-based authorization,
- organization settings backend,
- locations/staff/vehicles/uploads,
- students and formal course enrollment,
- training requirements and recognized external training,
- calendar, availability, training sessions and hour ledger,
- student finance,
- license inventory/assignment/learning access,
- internal exams and stations,
- commerce orders/purchase history/dashboard,
- activity/notifications/audit,
- formal training documents,
- student progress,
- reconciliation, retention and production operations substrate.

PKK/PWPW is intentionally deferred and does not block Core launch.

### PKK/PWPW truth boundary

The code audit distinguishes local PKK identity from provider integration:

- formal `CourseEnrollment` currently creates/maintains an encrypted, course-scoped local `pkk_profiles` identity and the UI accepts PKK manually,
- provider-neutral Stage 4 database substrate exists (PKK operations/attempts/snapshots/signature/reconciliation/configuration tables plus DB guards),
- there is **no PKK application controller/service, no physical PKK HTTP route and no PKK Vue workspace**,
- OpenAPI/required-operation PKK entries are preserved future contract/evidence and are not claims of active runtime,
- import from PWPW, test-connection, live calls, provider statuses, signed-XML provider semantics and provider reconciliation remain `FROZEN_UNTIL_EXPLICIT_UNFREEZE`,
- unfreeze requires authoritative PWPW requirements/contract and a fresh comparison against the preserved provider-neutral model.

The Core service therefore works without PWPW import/integration; this does not remove the local formal-course PKK identity.

## Frontend/productization status

Functional SPA workspaces exist for:

- login/password recovery,
- registration,
- dashboard,
- students/courses,
- locations,
- staff,
- vehicles,
- calendar,
- purchase history,
- license management,
- internal exam management,
- student finance/progress/learning access/formal documents,
- provider-neutral organization settings.

Completed productization work:

- `AUTH-RECOVERY-UI-001`: accepted on `d6b2089903ac830581b8606914cd484f1207dee2`; PR #111; accepted CI #665 **6/6 PASS**. Public SPA routes exist for `/login`, `/forgot-password` and `/reset-password`.
- `REGISTRATION-UI-001`: accepted on `da8b5c35590c77f512a17e21f1e173bce46a6088`; PR #123; accepted CI #733 **6/6 PASS**. Public `/register` uses the existing `POST /api/v1/auth/register`, discovers the exact Terms version through the non-production sample resolver instead of hardcoding it, fails closed without Terms authority, keeps marketing consent false without a separately versioned marketing authority, creates no implicit authenticated session and only renders the discovered Terms target after same-origin URL resolution. Production registration still requires a real published/versioned Terms authority.
- `LICENSE-PURCHASE-UI-001`: accepted on `394c24d73eb7514e68f4996342b769f4a35b432c`; PR #125; accepted CI #738 **6/6 PASS**. `/licencje/wykup` reads the server price projection from `GET /api/v1/license-products`, creates multi-variant orders through `POST /api/v1/license-orders`, never sends browser-owned price/VAT/discount/total fields, visibly labels sample pricing, fails closed when pricing authority is absent and keeps provider-specific payment redirects/callbacks deferred.
- `ORGANIZATION-SETTINGS-UI-001`: accepted on `4006607dd2aecdc6fabb97a34ba5edf40d07729b`; PR #113; accepted CI #675 **6/6 PASS**. `/ustawienia` uses the provider-neutral settings contract, keeps e-mail read-only, does not read or mutate PKK while frozen, and does not synthesize a terms document URL.

Completed development support:

- `SAMPLE-DATA-001`: **accepted** on `2f42eaaff8fc41cddee5ddba5c39f1d58033ed5a`; PR #115; candidate head `275e9dc47c5b507d49b96cf4ce79205b0ec78d49`; candidate Implementation CI #680 **5/5 PASS** plus API Contract #511 **PASS**; accepted Implementation CI #681 **6/6 PASS** plus API Contract #512 **PASS**. PostgreSQL: **384 tests / 6348 assertions**; restore drill **PASS**; release artifact `10401437249`, archive digest `sha256:197ed111da545c12c07add947a1760be82858eed9830f4ba84ce4bc327046540`. The accepted scope is a clearly labelled non-production sample Terms document and sample license pricing only; production use remains forbidden.

Current productization candidate — **not yet accepted on `main`**:

- `INTERNAL-EXAM-PURCHASE-UI-001`: branch `productization/internal-exam-purchase-ui-001` materializes `/egzamin-wewnetrzny/wykup`, adds read-only `GET /api/v1/internal-exam/purchase-offer` and extends non-production sample pricing with a separate `SAMPLE-INTERNAL-EXAM` catalog item. Offer preview and order placement use the same server catalog selector and `CommercePricingCatalog`; the browser sends only integer `quantity` and provider-neutral `payment_method`. The observed 1.23 PLN demo value is not runtime price authority. Acceptance still requires exact-head CI and promotion to `main`.

Current productization gaps confirmed by code audit:

1. accept `INTERNAL-EXAM-PURCHASE-UI-001` on `main`,
2. no browser E2E suite for complete user golden paths,
3. frontend routing is currently a lightweight pathname shell rather than a full
   router/state-management architecture.

Registration UI is accepted on `main`. This does **not** remove the production legal prerequisite: a real published/versioned Terms authority remains required before production registration can be enabled.

These are the next repository-owned product gaps. They do not reopen the already
closed Core domain/database authority.

## HTTP contract/runtime reconciliation

The accepted-main deep audit on 2026-09-15 found **160 physical `/api/v1` route bindings** versus **173 HTTP operations in canonical OpenAPI**. The current Internal Exam Purchase candidate adds exactly one matched physical/OpenAPI operation, so the candidate branch contains **161 physical bindings** versus **174 OpenAPI operations**; the gap remains **15**. Those 15 OpenAPI-only operations are intentional deferred boundaries: 14 PKK/PWPW operations and one provider payment webhook. Runtime additionally exposes two intentional non-canonical/compatibility endpoints: the non-production sample-terms discovery endpoint and the `internal-exam-stations/heartbeat` compatibility alias. Detailed first-pass evidence and the independent second-pass verification/corrections are recorded in `docs/230-documentation-code-consistency-audit.md` (sections 1–12 preserve the first pass; section 13 records the re-audit).

## Production status

`PRODUCTION_REPOSITORY_SUBSTRATE_COMPLETE = TRUE`

`PRODUCTION_READY = FALSE`

`GO_LIVE_STATUS = BLOCKED_EXTERNAL_EVIDENCE`

External evidence tracker: GitHub issue #106.

The repository cannot truthfully manufacture target facts such as real PITR,
off-site backup, human paging receipt, target scheduler execution or HTTPS target
release smoke.

## Deferred/outside-current-scope

- PKK/PWPW: `FROZEN_UNTIL_EXPLICIT_UNFREEZE`,
- provider-specific payment webhook: `EXTERNAL_PROVIDER_BOUNDARY`,
- business cards, advertising, lectures and instructor-training reference
  modules: outside current Core V1,
- historical/optional invoice and impersonation capabilities remain non-blocking.

## Next execution order

1. close the remaining confirmed frontend productization gaps,
2. add browser E2E golden paths,
3. choose/provision target infrastructure,
4. collect all nine external evidence classes against one immutable release,
5. run final fail-closed go-live evidence validation.

Do not reactivate PKK as part of this sequence.
