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

`d6b2089903ac830581b8606914cd484f1207dee2`

Accepted Implementation CI:

`34964671420` / run #665 — **6/6 PASS**

Accepted immutable artifact:

- artifact ID: `10395187416`,
- name: `osk-panel-d6b2089903ac830581b8606914cd484f1207dee2`,
- release archive SHA-256:
  `516fa5aafe9c4a1a2a5bfb488e3fb3ae0bbfa3377d82e05a5797f3b9f70af7ce`.

Runtime suite:

- PostgreSQL: **380 tests / 6299 assertions — PASS**,
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
- student finance/progress/learning access/formal documents.

Candidate workspace also exists for `/ustawienia`, but it is not listed as completed productization until the gate is accepted.

Completed productization work:

- `AUTH-RECOVERY-UI-001`: accepted on `d6b2089903ac830581b8606914cd484f1207dee2`; PR #111; accepted CI #665 **6/6 PASS**. Public SPA routes now exist for `/login`, `/forgot-password` and `/reset-password`. Registration UI remains separately deferred pending safe public legal-document version discovery.

Current productization gaps confirmed by code audit:

1. organization settings UI is an implementation candidate on `productization/organization-settings-ui-001`; `/ustawienia` is materialized with provider-neutral data/settings save, but the gate is not accepted until exact-head CI + clean promotion + accepted 6/6,
2. no license purchase UI route despite backend `POST /api/v1/license-orders`,
3. no internal-exam purchase UI route despite backend `POST /api/v1/internal-exam/orders`,
4. no browser E2E suite for complete user golden paths,
5. frontend routing is currently a lightweight pathname shell rather than a full
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
