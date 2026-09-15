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

Ostatni zaakceptowany runtime authority:

`7a84f5e32752efdd7955ceecc02daec0f91f6a7a`

Accepted Implementation CI:

`34960412395` / run #657 — **6/6 PASS**

Accepted immutable artifact:

- artifact ID: `10392958940`,
- name: `osk-panel-7a84f5e32752efdd7955ceecc02daec0f91f6a7a`,
- release archive SHA-256:
  `50b3ff2edf563a4a8d17dff62849289c5279df74660c492602a69eab9dde5236`.

Runtime suite:

- PostgreSQL: **379 tests / 6278 assertions — PASS**,
- deterministic restore: **122 -> 122 PASS**.

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

- dashboard,
- students/courses,
- locations,
- staff,
- vehicles,
- calendar,
- purchase history,
- license management,
- internal exam management,
- student finance/progress/learning access/formal documents.

Current productization gaps confirmed by code audit:

1. dedicated login/recovery UI is currently an implementation candidate in PR #111 (`AUTH-RECOVERY-UI-001`); it remains an open productization gap until exact-head CI, closure and accepted promotion are complete,
2. no organization settings UI route,
3. no license purchase UI route despite backend `POST /api/v1/license-orders`,
4. no internal-exam purchase UI route despite backend `POST /api/v1/internal-exam/orders`,
5. no browser E2E suite for complete user golden paths,
6. frontend routing is currently a lightweight pathname shell rather than a full
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

1. close `AUTH-RECOVERY-UI-001` without weakening the existing auth/password-recovery authority,
2. close the remaining confirmed frontend productization gaps,
3. add browser E2E golden paths,
4. choose/provision target infrastructure,
5. collect all nine external evidence classes against one immutable release,
6. run final fail-closed go-live evidence validation.

Do not reactivate PKK as part of this sequence.
