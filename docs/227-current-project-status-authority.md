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

Accepted main-promotion runtime evidence head:

`d519651171ab5329d1dcc5326160339d4b94bb5b`

Promotion authority: PR #117 merged the previously accepted integration history into
`main` without force-push or squash. The promoted `main` tree is identical to the
verified accepted integration tree.

Accepted Implementation CI:

`34985599149` / run #692 — **6/6 PASS**

Accepted API Contract Gate:

`34985599153` / run #518 — **PASS**

Accepted immutable artifact:

- artifact ID: `10403891440`,
- name: `osk-panel-d519651171ab5329d1dcc5326160339d4b94bb5b`,
- release archive SHA-256:
  `9a3dd441316917e33405f6e071dd72ccd598a2cf20d5bac9f302bec7fec37428`,
- GitHub uploaded artifact ZIP SHA-256:
  `da42e6bd1b38ffe7fd8f8303baeebfece799748eccc600c44eff79a817a4dfb5`.

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
- Historical branch cleanup remains a separate repository-hygiene activity. Branches
  may be deleted only after ancestry or explicit supersession is verified; divergent
  historical tips are not assumed safe merely from their name.
- Repository setting `delete_branch_on_merge` was observed as `false` during the
  2026-09-15 audit, so merged task branches are not currently removed automatically.

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

- `AUTH-RECOVERY-UI-001`: accepted on `d6b2089903ac830581b8606914cd484f1207dee2`; PR #111; accepted CI #665 **6/6 PASS**. Public SPA routes exist for `/login`, `/forgot-password` and `/reset-password`. Registration UI remains separately deferred pending safe public legal-document version discovery.
- `ORGANIZATION-SETTINGS-UI-001`: accepted on `4006607dd2aecdc6fabb97a34ba5edf40d07729b`; PR #113; accepted CI #675 **6/6 PASS**. `/ustawienia` uses the provider-neutral settings contract, keeps e-mail read-only, does not read or mutate PKK while frozen, and does not synthesize a terms document URL.

Current productization support in progress:

- `SAMPLE-DATA-001`: **accepted** on `2f42eaaff8fc41cddee5ddba5c39f1d58033ed5a`; PR #115; candidate head `275e9dc47c5b507d49b96cf4ce79205b0ec78d49`; candidate Implementation CI #680 **5/5 PASS** plus API Contract #511 **PASS**; accepted Implementation CI #681 **6/6 PASS** plus API Contract #512 **PASS**. PostgreSQL: **384 tests / 6348 assertions**; restore drill **PASS**; release artifact `10401437249`, archive digest `sha256:197ed111da545c12c07add947a1760be82858eed9830f4ba84ce4bc327046540`. The accepted scope is a clearly labelled non-production sample Terms document and sample license pricing only; production use remains forbidden and `/licencje/wykup` is still not materialized.

Current productization gaps confirmed by code audit:


1. no license purchase UI route despite backend `POST /api/v1/license-orders`,
2. no internal-exam purchase UI route despite backend `POST /api/v1/internal-exam/orders`,
3. no browser E2E suite for complete user golden paths,
4. frontend routing is currently a lightweight pathname shell rather than a full
   router/state-management architecture.

These are the next repository-owned product gaps. They do not reopen the already
closed Core domain/database authority.

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
