# AGENTS.md — instrukcja dla Codex / agentów implementacyjnych

## Cel

Zbudować własny system OSK o **parytecie funkcjonalnym** opisanym w dokumentacji tego repozytorium.

## Zasady bezwzględne

1. Przed implementacją przeczytaj `README.md` oraz `docs/01-feature-map.md`.
2. Nie implementuj pozycji `TO_VERIFY` jako faktu — najpierw dodaj ją do backlogu lub użyj neutralnego, własnego rozwiązania.
3. Nie kopiuj kodu, treści, assetów, logo ani layoutu konkurencyjnego serwisu.
4. Wszystkie operacje tenantowe muszą sprawdzać `organization_id` i polityki uprawnień.
5. Wszystkie operacje PKK, finansowe, egzaminacyjne, role i impersonacja muszą generować audit log.
6. Nie wiąż logiki biznesowej z komponentami Vue.
7. Płatności i PKK muszą być idempotentne.
8. Nie usuwaj historii formalnych operacji; stosuj status/archiwizację.
9. Dla każdej funkcji dodaj testy polityk i krytyczny test integracyjny.
10. Każda zmiana zakresu powinna aktualizować dokumentację.

## Kolejność pracy

Postępuj zgodnie z `docs/09-roadmap.md`.

## Format PR

Każdy PR powinien zawierać:
- zakres modułu,
- spełnione wymagania,
- migracje,
- API,
- uprawnienia,
- testy,
- wpływ na audyt,
- screenshoty własnego UI,
- aktualizację docs.
