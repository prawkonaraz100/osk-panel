# 177. CORE-V1-CLOSURE-AUDIT-002 — repository closure re-audit

Data: 2026-09-14

**Status:** `AUDIT_COMPLETE_REPO_P1_REMAINS`

## Exact audited tree

Accepted audit parent:

`9b2b7513093680c061ed806ac03918d0146158b8`

This is the clean closure commit for `CORE-V1-LEARNING-CREDENTIAL-SINGLE-PDF-001`.

Final closure evidence on that exact tree:

- Implementation CI #507 / run `34827280051`: **5/5 PASS**
- API Contract Gate #389 / run `34827280065`: **PASS**
- PostgreSQL: **289 tests / 5320 assertions — PASS**
- deterministic restore: **121 -> 121 PASS**
- restore schema fingerprint: `09c57afe2501c79a3a684c0a695b4619114f94ddc24c9bca33728d99c1abb155`

## HTTP inventory

Canonical required inline HTTP operations audited: **172**.

Current runtime covers **145** of those required physical bindings.

Missing physical bindings: **27**.

Classification:

- frozen PKK/PWPW bindings: **14**
- provider-specific payment webhook: **1**
- repo-actionable HTTP bindings: **12**

Therefore:

`27 - 14 - 1 = 12`

The repository is not complete.

## Remaining repo-actionable HTTP bindings — 12

### Identity / auth — 5

- `auth.register`
- `auth.password_forgot`
- `auth.password_reset`
- `auth.social_redirect`
- `auth.social_callback`

Current blockers:

- registration contract carries `marketing_consent`, but the accepted identity schema has no canonical marketing-consent persistence/history authority,
- forgot/reset require a one-time reset-token lifecycle, but the accepted core table set has no password-reset token authority; raw/recoverable token persistence must not be invented,
- social account identity rows exist, but no social provider runtime/package/configuration or provider-state authority is materialized in the application.

Do not bind these routes by silently dropping contract fields, storing raw reset secrets, or inventing provider behavior.

### Student Progress — 1

- `students.progress`

The confirmed screen is online-learning progress, not OSK formal-hour progress.

The current Stage-4 schema does not materialize authoritative question/test attempt, handbook or lecture-progress state from which this endpoint can be truthfully projected.

Training Hour Ledger must not be substituted for online-learning progress.

### Commerce order creation — 2

- `license_orders.create`
- `exam_orders.create`

The database contract requires immutable server-owned product/display/price/VAT/discount snapshots and server-authoritative line/order totals.

The API requests carry product/quantity/payment intent, not client-authoritative price or VAT.

No canonical current server-side price/VAT authority is materialized for the two order-create commands.

Do not synthesize prices, use UI constants, use historical snapshots as current price, or accept client totals.

### Learning credential bulk PDF — 1

- `license_credentials.bulk_pdf`

The non-reset path is conceptually safe, but the same canonical operation exposes `regenerate_credentials_when_required=true`.

The request supplies only account IDs and does not carry the required expected credential version per reset target.

Do not mutate credentials under download permission alone and do not invent implicit optimistic-concurrency versions.

### Organization / settings — 3

- `organization.update`
- `organization.settings.get`
- `organization.settings.update`

The canonical organization update schema still contains `osk_registry_number`.

The settings projection/update contract includes `pkk_api_data`.

PKK/PWPW runtime is explicitly frozen, so these bindings must not be partially implemented while pretending the full canonical contract is satisfied.

A partial non-PKK projection may only be introduced by an explicit contract change, not by runtime omission.

## Frozen / external boundaries — not repo-actionable count

### PKK/PWPW — 14

All fourteen missing PKK canonical bindings remain frozen until explicit unfreeze and authoritative guidance.

No provider runtime, credential protocol, XML/signing, retry/reconciliation or mutating PKK UI is to be invented.

### Payment webhook — 1

`payment_webhook.receive` remains provider-specific external authority.

The local provider-neutral commerce model does not authorize inventing signature validation, remote event semantics or provider reconciliation truth.

## Non-HTTP repository P1

### Course Completion — runtime + UI

This is no longer accurately classified as UI-only.

The existing stage-transition HTTP route is present, but:

- backend `CourseEnrollmentService::changeStage` deliberately rejects `training_completed`,
- `StudentCourseWorkspace.vue` also blocks selecting `training_completed`.

Existing database authority already establishes major pieces:

- current TrainingRequirementProfile freshness,
- current OSK credited minutes,
- recognized external minutes,
- current theory/practical requirement flags,
- internal exam attempt/result evidence,
- atomic completed lifecycle state and history shape.

However the Stage-5 runtime does not yet have one explicit, reviewed completion-eligibility rule that binds the required internal-exam PASS evidence to the current requirement authority without accidentally using a mutable management projection or an obsolete historical context.

Therefore this P1 must be closed as a full runtime + UI gate, not by removing the Vue guard.

### Resource Asset UI

Closed by `CORE-V1-RESOURCE-ASSET-UI-001`; no longer open.

## Stage-4 and preservation

- Stage-4 migration DAG materialization remains complete.
- restore remains **121 -> 121 PASS**.
- schema fingerprint is unchanged.
- PKK/PWPW remains frozen.
- pricing/VAT authority remains uninvented.
- bulk reset expected-version requirement remains uninvented.

## Result

Repository completion: **false**

Open repo P0: **0**

Repo-actionable missing HTTP bindings: **12**

Additional non-HTTP repo P1:

- Course Completion runtime + UI.

Result:

`AUDIT_COMPLETE_REPO_P1_REMAINS`

## Next safe gate

`CORE-V1-COURSE-COMPLETION-AUTHORITY-001`

This is an authority/contract gate before implementation.

It must prove, without schema invention:

1. exact current TrainingRequirementProfile freshness checks,
2. exact theory/practical minute totals from current OSK ledger plus current recognized external training,
3. exact semantics for a required internal exam to be satisfied,
4. whether exam satisfaction is bound to the current profile/revision and how later requirement changes affect prior PASS evidence,
5. treatment of invalidated/technical-abort/failed/newer attempts,
6. CourseEnrollment lock and expected-version ordering,
7. atomic `training_completed + completed_at + lifecycle event + audit/outbox`,
8. negative tests proving no completion on stale requirements, insufficient minutes or unsatisfied required exam.

No PKK runtime, no new pricing authority, no bulk reset contract changes and no cosmetic UI-only unlock are allowed in this authority gate.
