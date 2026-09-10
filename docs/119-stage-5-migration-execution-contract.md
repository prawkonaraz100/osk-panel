# 119. Stage 5 migration execution contract

Status: `S5-MIG-001 PASS candidate — phase-aware hardened`

## Scope
Ten krok ustanawia wykonywalną warstwę migracji dla późniejszych modułów. Nie udaje, że wszystkie 170 node'ów Stage 4 mają już gotowe DDL. Pełny DAG jest materializowany jako deterministyczny plan wykonawczy, a każda przyszła phase-step musi wskazać istniejący node, należeć do jego authoritative phase path i wejść do registry w canonical topological prefix bez pomijania zależności.

## Immutable authority binding
`database/migration-plan/plan.json` jest generowany wyłącznie z `specs/database/final-migration-order-invariant-matrix.yml` blob `ad5f2aa2e14ef248b95dd3dda0d1cbcb2e69d441`. Runtime validator sam oblicza Git blob SHA źródła i odrzuca plan po zmianie authority. Plan zawiera dokładnie 170 node'ów, siedem faz `expand -> preflight -> write_fence -> backfill -> reconcile -> validate -> contract` oraz restart classification `restart_safe|manual_review` rozwiązane zgodnie z DB-MIG-003.

## Registry and execution identity
`database/migration-plan/implementations.json` jest osobnym, machine-validated registry wykonywalnych phase-stepów. Registry nie może wskazać nieznanego node'a, fazy spoza authoritative phase path, zmienionej restart classification ani niezarejestrowanego pliku. Zaimplementowane node'y muszą tworzyć canonical topological prefix pełnego DAG i być dependency-closed; zarejestrowane fazy danego node'a muszą być prefiksem jego authoritative phase path.

Każda phase-step zawiera SHA-256 pliku migracji. Z `plan_identity`, registry schema/root oraz ordered step metadata wyliczany jest osobny `execution_identity`. Produkcyjne wykonanie wymaga zgodności obu identity, więc zmiana registry lub bytes migracji bez aktualizacji i ponownej walidacji kończy się fail-closed.

## Phase isolation and bypass resistance
Migracje Stage 4 nie leżą bezpośrednio w `database/migrations`, lecz w izolowanym drzewie `database/migrations/stage4/<phase>/<node>/...`. Zwykły `php artisan migrate` nie odkrywa ich automatycznie. Validator porównuje całe drzewo PHP pod `database/migrations/stage4` z registry i odrzuca każdy brakujący albo niezarejestrowany plik.

Dodatkowo każda Stage-4 migracja wymaga aktywnego `ControlledMigrationContext` z dokładną fazą i node ID. Bezpośrednie `php artisan migrate --path=...` omijające `migration:controlled` jest więc odrzucane przez sam plik migracji przed DDL.

## Controlled executor
Wykonanie używa jawnie jednej fazy:

`php artisan migration:controlled --plan=<plan-identity> --execution=<execution-identity> --phase=<phase> --force`

Wrapper:
1. waliduje immutable authority, pełny 170-node plan, registry, hashes i execution identity,
2. wymaga jawnej fazy,
3. przed fazą późniejszą niż `expand` wymaga pełnej materializacji i zastosowania każdej authoritative step poprzednich faz,
4. bierze PostgreSQL advisory lock `(519662, 5001)`,
5. zapisuje run-level i node-level execution evidence poza core schema,
6. wykonuje osobno każdą zarejestrowaną step w canonical order,
7. potwierdza zapis migracji w Laravel migration repository,
8. zawsze zwalnia controlled context i advisory lock.

To uniemożliwia spłaszczenie przyszłego `write_fence -> backfill -> reconcile -> validate -> contract` do jednego niekontrolowanego `migrate`.

## Durable evidence, interruption and manual review
W production `MIGRATION_EVIDENCE_PATH` jest wymagany i nie może być pusty; brak trwałej ścieżki zatrzymuje wykonanie przed DDL. Nie powstaje nowa core table na potrzeby journalu.

Journal zapisuje `migration_node_started|succeeded|failed` z `execution_identity`, fazą i node ID. Dla przyszłych stepów sklasyfikowanych jako `manual_review`, wcześniejsze przerwane albo failed evidence blokuje automatyczny retry. Wznowienie wymaga jawnego `--reviewed-resume=<node-id>` w danym invocation i jest również zapisywane jako evidence. Blind retry pozostaje zabroniony.

## Restart and rollback
`restart_safe` nadal nie oznacza bezwarunkowego ponowienia. Migracja musi najpierw sprawdzić exact postcondition. Pierwszy node `MIG-EXT-BTREE-GIST` sprawdza, czy extension już istnieje; jeśli tak, uznaje oczekiwany postcondition bez ponownego efektu. Automatyczny destrukcyjny `down()` nie jest strategią rollbacku; dozwolone pozostają wyłącznie `application_rollback`, `schema_forward_fix` i jawnie udowodniony `explicit_safe_down`.

## First materialized node
S5-MIG-001 materializuje tylko `MIG-EXT-BTREE-GIST`, pierwszy node DAG i `restart_safe` expand step. Registry deklaruje 1 node / 1 phase-step, ale sam mechanizm nie jest zaszyty na tej liczbie: kolejne kroki mogą rozszerzać wyłącznie canonical dependency-closed prefix i authoritative phase prefixes.

## Executable proof
Machine gate na świeżym PostgreSQL potwierdza: 170/170 plan, 17 review batches, registry/file hash identity, tamper rejection, rogue-file rejection, brak odkrycia Stage-4 migration przez default `migrate`, odmowę bezpośredniego `migrate --path`, zły plan identity, zły execution identity, brak jawnej fazy, odmowę przedwczesnego `preflight`, production fail-closed bez evidence path, concurrent executor rejection, poprawne pierwsze wykonanie, brak ponownej aplikacji po sukcesie, extension postcondition, PHPUnit `--fail-on-warning` oraz preservation Stage-4 authorities.

## Boundary
169 pozostałych node'ów nie jest oznaczonych jako zaimplementowane. Będą materializowane małymi review batches w kolejnych właściwych slice'ach; każda nowa step musi przejść ten sam registry/hash/phase gate. S5-MIG-001 nie implementuje modeli, endpointów, UI ani pełnego framework test harnessu finalnych DBT contracts. Następny krok to wyłącznie `S5-TST-001` po osobnej instrukcji.
