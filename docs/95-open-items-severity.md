# 95. Open items severity after consolidation

Data: 2026-09-05

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

## Deferred pending external authority

- PKK provider/runtime integration is deferred until authoritative PWPW guidance or contract is received and verified. Existing PKK evidence, contracts and provider-neutral Gate 1 schema groundwork are preserved; no provider-specific behavior may be invented in the meantime. This deferment does not block the remaining core v1 slices.

## P1 before production

- target-infrastructure restore drill proving the core-v1 recovery targets,
- production incident contact roster + paging channel smoke test,
- provider reconciliation jobs.

## P2 can be completed during normal implementation

- exact UI messages,
- noncritical filter persistence,
- optional exports,
- deferred marketing modules.
