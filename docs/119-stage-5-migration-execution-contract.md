# 119. Stage 5 migration execution contract

Status: `S5-MIG-001 PASS candidate`

## Scope
Ten krok ustanawia wykonywalną warstwę migracji dla późniejszych modułów. Nie udaje, że wszystkie 170 node'ów Stage 4 mają już gotowe DDL. Pełny DAG jest natomiast materializowany jako deterministyczny plan wykonawczy, a każda przyszła migracja domenowa musi wskazać istniejący node i wejść w małą partię zachowującą canonical topological order.

## Immutable authority binding
`database/migration-plan/plan.json` jest generowany wyłącznie z `specs/database/final-migration-order-invariant-matrix.yml` blob `ad5f2aa2e14ef248b95dd3dda0d1cbcb2e69d441`. Runtime validator sam oblicza Git blob SHA źródła i odrzuca plan po zmianie authority. Plan zawiera dokładnie 170 node'ów, siedem faz `expand -> preflight -> write_fence -> backfill -> reconcile -> validate -> contract` oraz restart classification `restart_safe|manual_review` rozwiązane według exact override, a w pozostałych przypadkach według node-type default.

## Review batches
Canonical topological order jest dzielony deterministycznie na 17 partii po maksymalnie 10 node'ów. Partia nie zmienia zależności ani faz; jest wyłącznie granicą review/materializacji. `implementations.json` nie może zawierać nieznanego node ID ani innej restart classification niż authority.

## Controlled executor
Produkcyjne uruchomienie ma używać `php artisan migration:controlled --plan=<exact-plan-identity> --force`, nie bezpośredniego `migrate`. Wrapper:
1. waliduje authority i plan identity,
2. bierze PostgreSQL advisory lock `(519662, 5001)`,
3. zapisuje execution evidence poza core schema,
4. uruchamia Laravel migrator,
5. zapisuje success/failure i zawsze zwalnia lock.

W production `MIGRATION_EVIDENCE_PATH` jest wymagany i musi wskazywać trwały wolumen lub ścieżkę zbieraną przez deployment operations system. Nie powstaje nowa core table na potrzeby journalu.

## Restart and rollback
Blind retry pozostaje zabroniony. Plan zachowuje restart classification każdego node'a. Późniejsze migracje `manual_review` muszą mieć precondition/postcondition i procedurę resume zgodną z DB-MIG-003 przed materializacją. Automatyczny destrukcyjny `down()` nie jest strategią rollbacku; dozwolone pozostają wyłącznie `application_rollback`, `schema_forward_fix` i jawnie udowodniony `explicit_safe_down`.

## First materialized node
S5-MIG-001 materializuje tylko `MIG-EXT-BTREE-GIST`, pierwszy node DAG i `restart_safe` expand step. Migracja sprawdza PostgreSQL, wykonuje `CREATE EXTENSION IF NOT EXISTS btree_gist` i potwierdza postcondition. `down()` celowo odmawia automatycznego usuwania extension.

## Boundary
169 pozostałych DDL node'ów nie jest oznaczonych jako zaimplementowane. Będą materializowane partiami wraz z właściwymi core slices po przejściu entry gates. S5-MIG-001 nie implementuje modeli, endpointów, UI ani 491 framework tests. Następny krok to wyłącznie `S5-TST-001` po osobnej instrukcji.
