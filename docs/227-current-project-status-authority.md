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

`4006607dd2aecdc6fabb97a34ba5edf40d07729b`

Accepted Implementation CI:

`34971537563` / run #675 — **6/6 PASS**

Accepted immutable artifact:

- artifact ID: `10396979267`,
- name: `osk-panel-4006607dd2aecdc6fabb97a34ba5edf40d07729b`,
- release archive SHA-256:
  `c3ca6f4c99dd61099cfc2bb720a502e8808212384d1393a8e8accae17071b962`.

Runtime suite:

- PostgreSQL: **381 tests / 6315 assertions — PASS**,
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
- student finance/progress/learning access/formal documents,
- provider-neutral organization settings.

Completed productization work:

- `AUTH-RECOVERY-UI-001`: accepted on `d6b2089903ac830581b8606914cd484f1207dee2`; PR #111; accepted CI #665 **6/6 PASS**. Public SPA routes exist for `/login`, `/forgot-password` and `/reset-password`. Registration UI remains separately deferred pending safe public legal-document version discovery.
- `ORGANIZATION-SETTINGS-UI-001`: accepted on `4006607dd2aecdc6fabb97a34ba5edf40d07729b`; PR #113; accepted CI #675 **6/6 PASS**. `/ustawienia` uses the provider-neutral settings contract, keeps e-mail read-only, does not read or mutate PKK while frozen, and does not synthesize a terms document URL.

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
