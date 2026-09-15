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

Last fully verified `main` baseline before the second documentation consistency re-audit:

`20e6989486bd27c9331a8f5dd55acc6f2b0a3d2b`

Promotion provenance: PR #117 merged the previously accepted integration history into
`main` without force-push or squash at `d519651171ab5329d1dcc5326160339d4b94bb5b`.
Commit `bb6f4639fbf1098f131e579dedbb610e0e7c7d97` established `main` as the sole canonical base/publication branch. PR #119 finalized branch-hygiene/status synchronization. PR #120 then merged the first deep docs↔code consistency audit as `20e6989486bd27c9331a8f5dd55acc6f2b0a3d2b`, followed by a full post-merge validation. Embedded evidence below is a verified baseline, not a substitute for reading the live `main` ref.

Accepted Implementation CI:

`34997297399` / run #702 — **6/6 PASS**

Accepted API Contract Gate for the promoted application/contract tree:

`34997297369` / run #526 — **PASS**

Accepted immutable artifact for this verified baseline:

- artifact ID: `10409070006`,
- name: `osk-panel-20e6989486bd27c9331a8f5dd55acc6f2b0a3d2b`,
- release archive SHA-256:
  `0c9434895683f28bad1f96df127fe31813588e25269509ac1c2f95cc17ac47f3`,
- GitHub uploaded artifact ZIP SHA-256:
  `163bf641abe1759bffb0967b0fe22bfe765c31c3ec62c8bcbd8b7315e4736607`.

Runtime suite:

- PostgreSQL: **384 tests / 6348 assertions — PASS**,
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

- `AUTH-RECOVERY-UI-001`: accepted on `d6b2089903ac830581b8606914cd484f1207dee2`; PR #111; accepted CI #665 **6/6 PASS**. Public SPA routes exist for `/login`, `/forgot-password` and `/reset-password`. Registration UI is still not implemented. `SAMPLE-DATA-001` now unblocks development with a non-production sample-terms resolver; production registration still requires a real published legal-document version/authority.
- `ORGANIZATION-SETTINGS-UI-001`: accepted on `4006607dd2aecdc6fabb97a34ba5edf40d07729b`; PR #113; accepted CI #675 **6/6 PASS**. `/ustawienia` uses the provider-neutral settings contract, keeps e-mail read-only, does not read or mutate PKK while frozen, and does not synthesize a terms document URL.

Completed development support:

- `SAMPLE-DATA-001`: **accepted** on `2f42eaaff8fc41cddee5ddba5c39f1d58033ed5a`; PR #115; candidate head `275e9dc47c5b507d49b96cf4ce79205b0ec78d49`; candidate Implementation CI #680 **5/5 PASS** plus API Contract #511 **PASS**; accepted Implementation CI #681 **6/6 PASS** plus API Contract #512 **PASS**. PostgreSQL: **384 tests / 6348 assertions**; restore drill **PASS**; release artifact `10401437249`, archive digest `sha256:197ed111da545c12c07add947a1760be82858eed9830f4ba84ce4bc327046540`. The accepted scope is a clearly labelled non-production sample Terms document and sample license pricing only; production use remains forbidden and `/licencje/wykup` is still not materialized.

Current productization gaps confirmed by code audit:


1. no registration UI; development is unblocked by sample Terms, while production legal publication authority/content is still required,
2. no license purchase UI route despite backend `POST /api/v1/license-orders`,
3. no internal-exam purchase UI route despite backend `POST /api/v1/internal-exam/orders`,
4. no browser E2E suite for complete user golden paths,
5. frontend routing is currently a lightweight pathname shell rather than a full
   router/state-management architecture.

These are the next repository-owned product gaps. They do not reopen the already
closed Core domain/database authority.

## HTTP contract/runtime reconciliation

Deep code audit on 2026-09-15 found **160 physical `/api/v1` route bindings** versus **173 HTTP operations in canonical OpenAPI**. The 15 OpenAPI-only operations are intentional deferred boundaries: 14 PKK/PWPW operations and one provider payment webhook. Runtime additionally exposes two intentional non-canonical/compatibility endpoints: the non-production sample-terms discovery endpoint and the `internal-exam-stations/heartbeat` compatibility alias. Detailed first-pass evidence and the independent second-pass verification/corrections are recorded in `docs/230-documentation-code-consistency-audit.md` (sections 1–12 preserve the first pass; section 13 records the re-audit).

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
