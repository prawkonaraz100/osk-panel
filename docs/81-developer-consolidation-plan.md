# 81. Developer consolidation pass — plan naprawczy dokumentacji

Data: 2026-09-05

**Status:** `HISTORICAL_SUPERSEDED`

## Current authority

Plan konsolidacji został wykonany i nie jest bieżącym backlogiem. Aktualny stan implementacji, luki i freeze boundaries są w `docs/227-current-project-status-authority.md` oraz `specs/current-project-status.yml`.

## Cel historyczny

Przed rozpoczęciem szerokiej implementacji porządkujemy dokumentację tak, aby agent implementacyjny nie musiał sam rozstrzygać sprzeczności między starszym audytem publicznym a nowszym audytem ekranowym.

## Zasada nadrzędna

Dla implementacji obowiązuje kolejność źródeł:

1. `specs/legal/*.yml` — dla reguł prawnych/formalnych,
2. `specs/design/*.yml` — dla jawnych decyzji własnego produktu,
3. `specs/screens/*.yml` — dla aktualnie zweryfikowanych ekranów,
4. najnowsze odpowiadające im `docs/17-...` i późniejsze,
5. `docs/71-admin-osk-module-mapping-status.md` — status gotowości modułu,
6. `specs/admin-osk-services.yml`,
7. `specs/functional-requirements.yml`,
8. starsze dokumenty ogólne `docs/01-16`.

Starszy dokument nie może obniżyć confidence ani cofnąć potwierdzonego zachowania z nowszego screen-level spec.

## Partie konsolidacji

### Batch 1 — statusy i źródła prawdy

- synchronizacja `docs/02-screen-inventory.md`,
- synchronizacja `docs/10-gap-register.md`,
- synchronizacja agregujących YAML-i,
- usunięcie zamkniętych luk z list aktywnych gapów,
- aktualizacja Lokalizacji po zweryfikowaniu create/edit.

### Batch 2 — model domenowy i źródła prawdy danych

- `course_enrollment` jako właściciel PKK,
- godziny bieżącego OSK jako projekcja z ledgeru,
- godziny uznane z innego OSK jako audytowalne rekordy,
- canonical domain glossary,
- jednoznaczne relacje student / learning account / access / license.

### Batch 3 — state machines i lifecycle

- egzamin: jedna polityka zużycia inventory,
- licencja: assignment/activation/revoke,
- delete/archive/cancel/correction dla danych formalnych,
- payment/entitlement lifecycle.

### Batch 4 — API contract

- API course-first dla PKK,
- API kursów, lokalizacji, student finance i dashboardu,
- standard błędów, paginacji, filtrowania, money, timezone,
- UUID/public IDs,
- idempotency i optimistic locking,
- przygotowanie kontraktu OpenAPI 3.1.

### Batch 5 — RBAC, acceptance criteria i test strategy

- pełna permission matrix,
- AC dla świeżo zmapowanych ekranów,
- tenant isolation tests,
- state-machine tests,
- race-condition tests,
- E2E krytycznych flow.

### Batch 6 — production engineering

- ADR,
- CI/CD,
- observability,
- queue retry/DLQ,
- backup/restore,
- RPO/RTO,
- secrets/configuration management,
- deployment environments.

## Reguła pracy

Każdy batch kończy się:
- ponownym skanem repo,
- sprawdzeniem, czy stare statusy nie przeczą nowym,
- aktualizacją plików agregujących,
- osobnym commitem/PR-em możliwym do przeglądu.
