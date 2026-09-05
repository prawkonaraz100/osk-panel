# 86. Consolidation scan 1 — wynik po batchach 1-5

Data: 2026-09-05

## Zakres skanu

Po pierwszych partiach napraw sprawdzono spójność pomiędzy:
- `AGENTS.md`,
- `README.md`,
- `docs/02`, `03`, `04`, `05`, `06`, `07`, `08`, `09`, `10`, `12`, `13`,
- `docs/71`,
- `docs/81-85`,
- `specs/implementation-baseline-v1.yml`,
- `specs/design/*`,
- `specs/legal/*`,
- kluczowymi `specs/screens/*`.

## Naprawione konflikty

### 1. Stary `TO_VERIFY_AUTH` vs nowe screen specs
Naprawione przez:
- aktywny implementation baseline,
- nową hierarchię źródeł w `AGENTS.md`,
- skonsolidowany `docs/02`,
- skonsolidowany `docs/10`.

### 2. PKK student-first vs course-first
Naprawione w:
- canonical glossary,
- domain model,
- API contract,
- user flows,
- action matrix,
- acceptance criteria.

Canonical relacja:
`Student -> CourseEnrollment -> PkkProfile`.

### 3. Egzamin consume-on-finish vs consume-on-start
Naprawione w dokumentach nadrzędnych.

Canonical policy:
- reserve przy access creation,
- consume przy start,
- release przed start,
- technical abort po start nie oddaje automatycznie sztuki.

### 4. Ręczny agregat godzin vs formalny ledger
Naprawione.

Canonical source of truth:
- bieżący OSK -> sessions + ledger,
- inne OSK -> recognized external training.

### 5. Niejednoznaczne nazwy domenowe
Naprawione przez `docs/82-canonical-domain-glossary.md`.

### 6. Delete semantics
Naprawione przez `docs/83-core-lifecycle-policy.md` i `specs/design/core-lifecycle.yml`.

### 7. RBAC
Naprawione przez permission-based model i `specs/security/permissions.yml`.

### 8. API common contract
Naprawione na poziomie dokumentacyjnym przez `docs/06-api-contract.md` i `specs/api/common-contract.yml`.

## Nadal wymagają kolejnych partii

### A. Stare agregaty machine-readable
- `specs/functional-requirements.yml`,
- `specs/admin-osk-services.yml`.

Mają niższy priorytet dzięki baseline, ale nadal zawierają historyczne statusy. Należy je zredukować do compatibility aggregate albo zsynchronizować.

### B. Starsze agregaty Markdown
- `docs/01-feature-map.md`,
- `docs/15-current-authenticated-menu-map.md`,
- `docs/16-admin-osk-service-catalog.md`.

Nowsze dokumenty mają pierwszeństwo, ale agregaty powinny zostać odświeżone, aby nie wprowadzać człowieka w błąd.

### C. OpenAPI 3.1
API jest już rozstrzygnięte koncepcyjnie, ale brakuje machine-readable OpenAPI.

### D. Physical DB schema
Brakuje:
- tabel/kolumn/typów,
- indeksów,
- unique/check constraints,
- migration order.

### E. ADR
Brakuje formalnych ADR dla:
- modular monolith,
- course-first formal model,
- exam consume-on-start,
- training hour ledger,
- archive/correction policy,
- permission-based RBAC.

### F. Legal dictionary / category codes
Do osobnej weryfikacji prawnej:
- canonical code dla `PT` / tramwaju,
- pełna wersjonowana tabela kategorii i wymagań.

Nie zmieniać `LEGAL_VERIFIED` bez weryfikacji aktualnego źródła prawa.

## Wniosek skanu

Najgroźniejsze sprzeczności architektoniczne zostały usunięte z aktywnego baseline i głównych dokumentów developerskich.

Kolejna partia powinna naprawić **stare agregaty**, a następnie przejść do physical DB schema + ADR + OpenAPI.
