# AGENTS.md — instrukcja dla Codex / agentów implementacyjnych

## Cel

Zbudować własny system OSK o **parytecie funkcjonalnym** opisanym w dokumentacji tego repozytorium, bez kopiowania chronionej implementacji konkurencyjnego serwisu.

## Najpierw przeczytaj

Obowiązkowo przed implementacją:
1. `README.md`
2. `docs/01-feature-map.md`
3. `docs/03-user-flows.md`
4. `docs/10-gap-register.md`
5. `docs/12-action-matrix.md`
6. `docs/13-acceptance-criteria.md`
7. `docs/14-reverification-audit-2026-09-05.md`
8. `specs/functional-requirements.yml`

## Poziomy pewności

- `CURRENT_CONFIRMED` — aktualne publiczne potwierdzenie.
- `RULES_CONFIRMED` — aktualny regulamin potwierdza zachowanie.
- `HISTORICAL_INDEX` — historyczny ślad; **nie zakładaj, że nadal istnieje**.
- `INFERRED` — nasza decyzja/projekt potrzebny do solidnego systemu.
- `TO_VERIFY_AUTH` — wymagane legalne wejście do aktualnego panelu przed twierdzeniem, że tak działa 360.
- `SOURCE_CONFLICT` — nie hardkoduj wartości; użyj config/CMS/capability model.

## Zasady bezwzględne

1. Nie implementuj `TO_VERIFY_AUTH` jako „identycznego zachowania 360”. Możesz stworzyć neutralny własny odpowiednik, ale oznacz decyzję jako projektową.
2. Nie promuj `HISTORICAL_INDEX` do bieżącego wymagania bez nowego dowodu.
3. Nie kopiuj kodu, treści, assetów, logo ani layoutu konkurencyjnego serwisu.
4. Wszystkie operacje tenantowe muszą sprawdzać `organization_id` i polityki uprawnień.
5. Wszystkie operacje PKK, finansowe, egzaminacyjne, licencyjne, aukcyjne, role i impersonacja muszą generować odpowiedni audit log.
6. Nie wiąż logiki biznesowej z komponentami Vue.
7. Płatności, jawna aktywacja usługi i PKK muszą być idempotentne.
8. Cofnięcie nieaktywowanej licencji musi atomowo przywrócić dokładnie jedną sztukę inventory.
9. Oferta reklamowa po `Licytuj` jest modelowana jako wiążący bid; klient nie dostaje zwykłego `DELETE bid`.
10. Nie usuwaj historii formalnych operacji; stosuj status/archiwizację oraz immutable/audit records.
11. Języki, kategorie, liczba lekcji/działów, parametry placementów i czasy emisji są config/data — nie hard-code.
12. Dla każdej funkcji dodaj testy polityk i krytyczny test integracyjny.
13. Każda zmiana zakresu powinna aktualizować dokumentację oraz `functional-requirements.yml`.

## Szczególne pułapki po drugim audycie

- `Faktury`: `HISTORICAL_INDEX / TO_VERIFY_AUTH`; nie zakładaj aktualnego modułu tylko dlatego, że był w starym menu.
- `export_progress`: `INFERRED`, nie potwierdzone.
- `pause_campaign`: brak publicznego potwierdzenia klientowej akcji.
- `refund`: proces własny; regulamin reklamacji nie potwierdza przycisku refund.
- egzaminy: TTL linku jest decyzją security naszego produktu, nie obecnie potwierdzonym detalem 360.
- session limit: dokładny zakres dla pracowników OSK jest `TO_VERIFY_AUTH`.
- języki: publiczne źródła są niespójne; capability matrix per product/module.

## Kolejność pracy

Postępuj zgodnie z `docs/09-roadmap.md`.

## Format PR

Każdy PR powinien zawierać:
- zakres modułu,
- spełnione wymagania i ich confidence level,
- decyzje projektowe dla `INFERRED/TO_VERIFY_AUTH`,
- migracje,
- API,
- uprawnienia,
- testy,
- wpływ na audyt,
- screenshoty własnego UI,
- aktualizację docs i YAML.
