# AGENTS.md — instrukcja dla Codex / agentów implementacyjnych

## Główny cel

Zbudować własny **panel administracyjny OSK** o parytecie funkcjonalnym opisanym w dokumentacji tego repozytorium, bez kopiowania chronionej implementacji konkurencyjnego serwisu.

**Panel administratora OSK jest obecnie nadrzędnym zakresem projektu.**

Nie buduj samodzielnie pełnego produktu kursanta, jeżeli dana funkcja nie jest potrzebna administratorowi OSK. Funkcje kursanta implementujemy tylko jako zależności zarządzane z panelu, np.:
- utworzenie dostępu,
- licencja,
- egzamin wewnętrzny,
- postęp nauki,
- zapis na jazdę,
- przypisanie materiałów/szkolenia,
- dokumentacja przebiegu szkolenia.

## Najpierw przeczytaj

Obowiązkowo przed implementacją:
1. `README.md`
2. `docs/15-current-authenticated-menu-map.md`
3. `docs/16-admin-osk-service-catalog.md`
4. `specs/admin-osk-services.yml`
5. `docs/01-feature-map.md`
6. `docs/03-user-flows.md`
7. `docs/10-gap-register.md`
8. `docs/12-action-matrix.md`
9. `docs/13-acceptance-criteria.md`
10. `docs/14-reverification-audit-2026-09-05.md`
11. `specs/functional-requirements.yml`

## Definicja kompletnego mapowania admina

Nie uznawaj modułu za w pełni zmapowany tylko dlatego, że znamy jego nazwę lub route.

Pełne mapowanie wymaga co najmniej:
- ekranów i podwidoków,
- pól formularzy,
- kolumn tabel,
- filtrów/sortowania/wyszukiwania,
- wszystkich przycisków i menu kontekstowych,
- akcji pojedynczych i zbiorczych,
- walidacji,
- modali,
- statusów i state transitions,
- ról/permissions,
- zależności między modułami,
- efektów finansowych,
- powiadomień,
- dokumentów/druków,
- integracji,
- błędów/retry,
- audit trail,
- acceptance criteria.

## Poziomy pewności

- `CURRENT_CONFIRMED` — aktualne publiczne potwierdzenie.
- `RULES_CONFIRMED` — aktualny regulamin potwierdza zachowanie.
- `HISTORICAL_INDEX` — historyczny ślad; **nie zakładaj, że nadal istnieje**.
- `INFERRED` — nasza decyzja/projekt potrzebny do solidnego systemu.
- `TO_VERIFY_AUTH` — wymagane legalne wejście do aktualnego panelu przed twierdzeniem, że tak działa 360.
- `SOURCE_CONFLICT` — nie hardkoduj wartości; użyj config/CMS/capability model.

## Zasady bezwzględne

1. Priorytetem są moduły administratora OSK: dashboard, PKK, kalendarz, kursanci, lokalizacje, pojazdy, pracownicy, licencje, egzaminy, wizytówki, reklamy, wykłady, szkolenie z instruktorem, ustawienia i historia zakupów.
2. Nie implementuj `TO_VERIFY_AUTH` jako „identycznego zachowania 360”. Możesz stworzyć neutralny własny odpowiednik, ale oznacz decyzję jako projektową.
3. Nie promuj `HISTORICAL_INDEX` do bieżącego wymagania bez nowego dowodu.
4. Nie kopiuj kodu, treści, assetów, logo ani layoutu konkurencyjnego serwisu.
5. Wszystkie operacje tenantowe muszą sprawdzać `organization_id` i polityki uprawnień.
6. Wszystkie operacje PKK, finansowe, egzaminacyjne, licencyjne, aukcyjne i zmiany ról muszą generować odpowiedni audit log.
7. Nie wiąż logiki biznesowej z komponentami Vue.
8. Płatności, jawna aktywacja usługi i PKK muszą być idempotentne.
9. Cofnięcie nieaktywowanej licencji musi atomowo przywrócić dokładnie jedną sztukę inventory.
10. Oferta reklamowa po `Licytuj` jest modelowana jako wiążący bid; klient nie dostaje zwykłego `DELETE bid`.
11. Nie usuwaj historii formalnych operacji; stosuj status/archiwizację oraz immutable/audit records.
12. Języki, kategorie, liczba lekcji/działów, parametry placementów i czasy emisji są config/data — nie hard-code.
13. Dla każdej funkcji dodaj testy polityk i krytyczny test integracyjny.
14. Każda zmiana zakresu powinna aktualizować dokumentację oraz oba pliki YAML w `specs/`.

## Szczególne pułapki

- `Faktury`: `HISTORICAL_INDEX / TO_VERIFY_AUTH`; nie zakładaj aktualnego modułu tylko dlatego, że był w starym menu.
- `Historia zakupów`: aktualna osobna pozycja panelu; traktuj ją jako wspólny ledger zakupów OSK, nie alias faktur.
- `Lokalizacje`: osobny zasób panelu; nie redukuj go do jednego adresu organizacji.
- `Moje wizytówki`: model kolekcji, nie zakładaj jednej wizytówki na tenant.
- `export_progress`: `INFERRED`, nie potwierdzone.
- `pause_campaign`: brak publicznego potwierdzenia klientowej akcji.
- `refund`: proces własny; regulamin reklamacji nie potwierdza przycisku refund.
- egzaminy: TTL linku jest decyzją security naszego produktu, nie obecnie potwierdzonym detalem 360.
- session limit: dokładny zakres dla pracowników OSK jest `TO_VERIFY_AUTH`.
- języki: publiczne źródła są niespójne; capability matrix per product/module.

## Kolejność pracy

Najpierw zamykaj `remaining_button_level_gaps` z `specs/admin-osk-services.yml`. Dopiero po ich zweryfikowaniu przechodź do implementacji modułu jako funkcjonalnie kompletnego.

## Format PR

Każdy PR powinien zawierać:
- zakres modułu admin OSK,
- spełnione wymagania i ich confidence level,
- decyzje projektowe dla `INFERRED/TO_VERIFY_AUTH`,
- migracje,
- API,
- uprawnienia,
- testy,
- wpływ na audyt,
- screenshoty własnego UI,
- aktualizację docs i YAML.
