# 180. CORE-V1-CLOSURE-AUDIT-003 — repository closure re-audit

Data: 2026-09-14

**Status:** `AUDIT_COMPLETE_REPO_P1_REMAINS`

## Exact audited tree

Accepted audit parent:

`eb1f9d3b7fe34ce523c8b951ebf4b4b3bbce97cd`

This is the clean closure commit for `CORE-V1-COURSE-COMPLETION-001`.

Final closure evidence on that exact tree:

- Implementation CI #515 / run `34833344805`: **5/5 PASS**
- API Contract Gate #398 / run `34833344818`: **PASS**
- PostgreSQL: **295 tests / 5380 assertions — PASS**
- deterministic restore: **121 -> 121 PASS**
- restore schema fingerprint: `09c57afe2501c79a3a684c0a695b4619114f94ddc24c9bca33728d99c1abb155`
- backend Pint + PHPStan: **PASS**
- frontend quality: **PASS**
- contracts/traceability: **PASS**
- secret scan: **PASS**

## Differential proof from audit 002

The previous full repository closure audit used accepted tip:

`9b2b7513093680c061ed806ac03918d0146158b8`

The canonical HTTP authority remained byte-identical between that tree and this audited tree:

- `routes/web.php`: `235a96b26a70b120efb9026b011c7e8040f7bdad`
- `specs/api/required-operations-v1.yml`: `c36142e3af2615393347fadfec265af2eb610fb7`
- `specs/api/paths` tree: `e1fd8ad215a4b2c934dc12c864d3ea7d74d47e2c`
- `specs/api/openapi-v1.yaml`: `46d42a9752e74a51ff066705e5ae69a97e676b35`
- `specs/api/openapi-components-v1.yaml`: `35828d89dedb0e91cb0f885ebcb063b663f419b1`
- `specs/api/common-contract.yml`: `553cd6cf95bf41dbcef8e503675236f889f3045e`
- `specs/api/openapi-settings-components.yaml`: `cdac825daaa90c26b911f284dc8acc84c6efcf56`

The compare `9b2b7513… -> eb1f9d3b…` changes only Course Completion runtime/UI, its authority/closure/test evidence, audit catalog/traceability and central-gate documentation. No route tree, required-operation registry, OpenAPI path/component authority, Auth runtime, Commerce order-create runtime or Organization Settings binding was added.

Therefore the audited HTTP inventory from audit 002 is preserved exactly.

## HTTP inventory

Canonical required inline HTTP operations: **172**.

Required physical bindings present: **145**.

Missing physical bindings: **27**.

Classification:

- frozen PKK/PWPW bindings: **14**
- provider-specific payment webhook: **1**
- repo-actionable HTTP bindings: **12**

Therefore:

`27 - 14 - 1 = 12`

Course Completion does not change this count because its stage-transition HTTP binding already existed before the completion implementation gate.

## Remaining repo-actionable HTTP bindings — 12

### Identity / Auth — 5

Still missing:

- `auth.register`
- `auth.password_forgot`
- `auth.password_reset`
- `auth.social_redirect`
- `auth.social_callback`

Blockers remain unchanged:

- the registration contract carries `marketing_consent`, but there is no accepted canonical persistence/history authority for that consent,
- forgot/reset still lacks a canonical one-time password-reset token lifecycle authority,
- social identity rows exist, but provider runtime/configuration/state authority is not materialized.

These routes remain fail-closed. No consent field may be silently dropped, no raw/recoverable reset secret may be invented, and no OAuth provider behavior may be synthesized.

### Student Progress — 1

Still missing:

- `students.progress`

The required screen remains online-learning progress for questions/tests/handbook/lectures. The accepted schema still does not materialize that progress authority.

Training Hour Ledger remains a formal OSK training authority and must not be substituted for online-learning progress.

### Commerce order creation — 2

Still missing:

- `license_orders.create`
- `exam_orders.create`

The canonical current server-side product price/VAT authority is still absent.

The create requests carry product/quantity/payment intent, not authoritative price/VAT totals. Historical snapshots, UI constants and client-provided totals remain forbidden substitutes.

### Learning credential bulk PDF — 1

Still missing:

- `license_credentials.bulk_pdf`

The canonical operation still includes a credential-regeneration branch, but the request does not carry the expected credential version for each reset target.

Credential reset may not be performed under download permission alone and optimistic-concurrency versions may not be invented.

### Organization / settings — 3

Still missing:

- `organization.update`
- `organization.settings.get`
- `organization.settings.update`

The canonical contracts still include PKK/OSK provider-related fields.

PKK/PWPW is frozen until explicit unfreeze and authoritative guidance, so these full canonical bindings remain blocked rather than being partially implemented while omitting frozen fields.

## Frozen / external boundaries

### PKK/PWPW — 14

All fourteen missing PKK bindings remain frozen.

No provider runtime, XML/signing protocol, retry/reconciliation behavior, credentials or mutating PKK UI is introduced.

### Payment webhook — 1

`payment_webhook.receive` remains provider-specific external authority and stays outside the repo-actionable count.

## Non-HTTP repository P1

### Course Completion

**CLOSED** by `CORE-V1-COURSE-COMPLETION-001`.

The existing stage-transition route now:

- evaluates exact current formal completion eligibility in backend authority,
- commits `training_completed + completed_at + lifecycle + course.completed audit/domain/outbox` atomically,
- accepts an older still-valid PASS even if a newer attempt failed,
- rejects explicitly invalidated PASS evidence,
- exposes no client-side formal eligibility authority.

The temporary Vue completion guard is removed.

### Resource Asset UI

Remains **CLOSED** by `CORE-V1-RESOURCE-ASSET-UI-001`.

### Additional non-HTTP P1

No additional repo-actionable non-HTTP P1 is identified on the audited tree.

## Result

Repository completion: **false**

Open repo P0: **0**

Repo-actionable missing HTTP bindings: **12**

Additional open non-HTTP repo P1: **0**

Unblocked repo-actionable implementation slices among the 12 missing bindings: **0** under current accepted authority.

Result:

`AUDIT_COMPLETE_REPO_P1_REMAINS`

The repository is not complete, but the remaining repo-actionable P1 backlog is authority-blocked rather than implementation-ready.

## Safe continuation boundary

Do not start another implementation slice until at least one blocker receives canonical authority or an explicit contract decision.

Safe triggers for resuming implementation are limited to:

1. explicit PKK/PWPW unfreeze plus authoritative integration guidance,
2. accepted marketing-consent persistence/history authority for registration,
3. accepted password-reset token lifecycle authority,
4. accepted social OAuth provider/configuration/state authority,
5. accepted online-learning Student Progress authority,
6. accepted current server-side product price/VAT authority,
7. accepted bulk credential-reset optimistic-concurrency contract carrying expected versions per reset target.

Until one of those changes, implementation work on the 12 missing bindings would require invention and remains forbidden.
