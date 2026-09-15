# 88. Consolidation scan 2 — stare agregaty, DB i OpenAPI

Data: 2026-09-05

## Naprawione w tej partii

### Stare agregaty
Odświeżone:
- `docs/01-feature-map.md`,
- `docs/15-current-authenticated-menu-map.md`,
- `docs/16-admin-osk-service-catalog.md`,
- `specs/functional-requirements.yml`,
- `specs/admin-osk-services.yml`.

Agregaty nie utrzymują już głównych starych `TO_VERIFY_AUTH` dla zmapowanego core.

### Physical database schema
Dodano:
- `docs/87-physical-database-schema.md`,
- `specs/database/core-schema.yml`.

Rozstrzygnięto blueprint:
- tenant-aware indexes,
- course-first PKK,
- immutable/auditable ledgers,
- one license activation per assignment,
- exam reservation/consume separation,
- payment event dedup,
- no cascade delete formal history.

### ADR
Dodano zaakceptowane decyzje:
- modular monolith,
- course-first formal model,
- training hour ledger,
- exam consume-on-start,
- permission-based RBAC,
- archive/correction over hard-delete.

### OpenAPI
Dodano pierwszy machine-readable kontrakt:
- `specs/api/openapi-v1.yaml`.

Obejmuje główne endpointy dla:
- students,
- course enrollments,
- training,
- PKK,
- calendar,
- locations,
- student finance,
- licenses,
- internal exams,
- purchase history,
- dashboard.

## Pozostałe realne luki developerskie

### 1. Legal category dictionary
Wymaga osobnej weryfikacji aktualnego prawa przed zmianą `LEGAL_VERIFIED`, w szczególności:
- `PT` / pozwolenie na kierowanie tramwajem,
- pełna lista kategorii/variantów,
- wersjonowanie słownika i reguł.

### 2. Privacy retention schedule
Mamy lifecycle/no-hard-delete, ale brak dokładnego harmonogramu retencji per typ danych.

### 3. Final ID/encryption ADR
Do decyzji:
- UUID vs ULID physical type,
- mechanizm application-level encryption / KMS,
- HMAC lookup rotation strategy.

### 4. Calendar overlap implementation
Do decyzji technicznej:
- PostgreSQL exclusion constraints,
- czy transaction query + lock.

### 5. OpenAPI completeness
Pierwszy blueprint istnieje, ale przed kodowaniem konkretnego modułu należy dopisać brakujące request/response schemas dla wszystkich endpointów modułu i uruchomić validator w CI.

### 6. Retention / data subject flows
Potrzebna osobna polityka:
- access requests,
- correction,
- account closure,
- anonymization where lawful,
- formal retention exceptions.

## Stan po skanie

Najważniejsze ryzyka, które wcześniej mogły spowodować błędną implementację przez agenta, są już usunięte z aktywnej dokumentacji:
- niejednoznaczny owner PKK,
- dwa momenty zużycia egzaminu,
- dwa źródła prawdy godzin,
- role pomieszane ze staff type,
- destrukcyjne delete formalnej historii,
- brak wspólnego API contract,
- brak physical schema.

Core jest teraz znacznie bliżej implementation-ready. Następny batch powinien objąć finalne decyzje infrastrukturalne/retencyjne oraz weryfikację legal dictionary.
