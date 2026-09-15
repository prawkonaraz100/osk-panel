# 95. Open items severity after consolidation

Data konsolidacji: 2026-09-05
Aktualizacja closure audit: 2026-09-13

**Status dokumentu:** `HISTORICAL_SNAPSHOT_SUPERSEDED`

> Sekcja `Current repository P1` poniżej jest snapshotem z wcześniejszego closure audit i **nie jest aktualnym backlogiem**. Core V1 ma obecnie `repository_actionable_P0=0` i `repository_actionable_P1_for_core_launch=0`; bieżące luki productization są wyłącznie w `docs/227-current-project-status-authority.md` / `specs/current-project-status.yml`. PKK/PWPW pozostaje zamrożone do czasu autorytatywnych wytycznych PWPW.

## P0 before high-risk modules

- none.

## Resolved after consolidation

- calendar conflict enforcement — rozstrzygnięte przez zamknięty Stage-4 Calendar authority i `ADR-0008`; produkcyjna aktywacja rezerwacji nadal wymaga materializacji GiST exclusion boundary zgodnie z migration phase plan.
- sensitive identifier key-management decision — rozdzielono Laravel encryption key ring od keyed lookup HMAC ring; current lookup secret nie może być `APP_KEY`, previous lookup keys są jawnie wspierane podczas rollover, a formalna historia PKK nie jest ukrycie przepisywana; authority: `docs/131-sensitive-identifier-key-management.md`.
- legal driving-entitlement dictionary re-verification — produkcyjny rule-engine ma dokładnie 16 aktywnych kategorii prawa jazdy; zaobserwowane `PT` pozostaje zachowane jako nieaktywny alias UI osobnego dokumentu „pozwolenie na kierowanie tramwajem”, a nie jako siedemnasta kategoria prawa jazdy; authority: `specs/legal/driving-entitlement-dictionary.yml` i `docs/132-legal-driving-entitlement-dictionary.md`.
- privacy/retention schedule — zdefiniowano wersjonowaną politykę per data class z ustawowymi terminami OSK (10 lat / 24 miesiące), finansowym minimum 5 lat, purpose-based profile cleanup, technicznymi TTL oraz legal-hold override; authority: docs/133-privacy-retention-schedule.md, specs/privacy/retention-schedule.yml i config/retention.php.
- RPO/RTO authority — core-v1 recovery targets są jawne i wersjonowane: tier-0 PostgreSQL RPO <= 5 min / RTO <= 60 min, formalne object assets RPO <= 60 min / RTO <= 240 min, rebuildable projections RTO <= 240 min; Redis nie jest durable authority. OpenAPI validator jest również faktycznie aktywny w Implementation CI. Authority: docs/134-disaster-recovery-authority.md, specs/operations/disaster-recovery.yml i config/recovery.php.
- deterministic restore-drill harness — Implementation CI wykonuje po testach realny PostgreSQL dump/isolated restore z fingerprintem i row-countami, odtwarza poprzednią wersję formalnego obiektu w S3 emulatorze oraz potwierdza pusty Redis. Harness jest CI evidence, nie produkcyjnym PITR/off-site drill. Authority: docs/135-restore-drill-harness.md i specs/operations/restore-drill-harness.yml.
- incident response runbooks + ownership — zdefiniowano SEV1/SEV2/SEV3, role Incident Commander/Technical/Privacy/Business/Communications, 9 runbooków oraz warunkowy breach flow zgodny z art. 33–34 RODO; actual contact roster pozostaje deployment evidence. Authority: docs/136-incident-response-runbooks.md, specs/operations/incident-response.yml i config/incident_response.php.
- provider-neutral reconciliation runtime — read-only scanner i scheduler kontrolują commerce settlement/fulfillment, purchase grant cardinality, license inventory, internal-exam ledger/reservations oraz outbox reconciliation bez automatycznej korekty i bez provider-specific truth guessing. PKK jest jawnie poza zakresem i zamrożone. Authority: docs/137-provider-neutral-reconciliation.md, specs/operations/reconciliation.yml i config/reconciliation.php.

## Deferred pending external authority

- PKK provider/runtime integration **and PKK reconciliation are frozen until explicit unfreeze** after authoritative PWPW guidance or contract is received and verified. Existing PKK evidence, contracts and provider-neutral Gate 1 schema groundwork are preserved; no provider-specific behavior may be invented in the meantime. This deferment does not block the remaining core v1 slices.
- remote payment-provider truth lookup remains an adapter-specific boundary. H7 does not invent a provider protocol. Before enabling a provider whose asynchronous state can become ambiguous, its real adapter and reconciliation contract must be validated against that provider's authoritative API/contract.

## Repo-actionable P1 after CORE-V1-CLOSURE-AUDIT-001

Closure audit authority: `docs/149-core-v1-closure-audit.md`.

Formal training documents runtime is **resolved** through FORMAL-DOC-011 and is no longer open P1.

Historical repository P1 at closure-audit snapshot time:

- Stage-4 executable migration materialization: **58/170 DAG nodes still missing**, including 6 core tables and all remaining candidate-key/index/FK/constraint/trigger/projection groups,
- Identity/Auth HTTP runtime required by canonical API and usable panel session lifecycle,
- OSK Settings runtime/UI for the confirmed `/ustawienia` screen,
- provider-neutral license and internal-exam checkout/order creation plus server-side catalog pricing and service-entitlement effects,
- learning-access credential PDFs: exact single handoff PDF and combined bulk PDF,
- confirmed Student Progress API/UI,
- Staff/Vehicle photo upload handoff and Vehicle document UI over the already materialized UploadsAssets runtime,
- CourseEnrollment `training_completed` UI wiring to the already materialized server-side evidence gate,
- remaining small canonical HTTP bindings such as `/languages` and audit-log read API unless explicitly reclassified by a later gate.

Historical snapshot status was `REPO_P1_REMAINS`; został później superseded przez zamknięcie Core V1. Historyczne PASS records pozostają zachowane, ale aktualny status pochodzi z docs/227.

## Deployment evidence before production

These are not repository implementation blockers:

- target-infrastructure restore drill proving the core-v1 recovery targets,
- production incident contact roster + paging channel smoke test,
- production scheduler + alert-delivery smoke test proving reconciliation findings reach an operator.

## P2 can be completed during normal implementation

- exact UI messages,
- noncritical filter persistence,
- optional exports,
- deferred marketing modules.
