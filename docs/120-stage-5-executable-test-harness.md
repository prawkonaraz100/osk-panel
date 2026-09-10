# 120. Stage 5 executable test harness

Status: `S5-TST-001 PASS`

## Scope

Ten krok ustanawia wykonywalny framework test harness dla późniejszych modułów. Nie oznacza, że wszystkie 491 kontraktów Stage 4 mają już implementację domenową. Harness rozróżnia jawnie `implemented_executable_assertion` od `pending_domain_materialization` i nie pozwala uznać testu za wdrożony bez istniejącej publicznej metody PHPUnit.

## PostgreSQL-backed runner

Testy integracyjne działają na rzeczywistym PostgreSQL z kontraktu S5-ENV-001. `IndependentPostgres` tworzy osobne połączenia PDO do tej samej bazy, dzięki czemu testy mogą obserwować oddzielne backend PID, izolację niezatwierdzonej transakcji i zachowanie blokad między dwiema sesjami. Nie zastępujemy race-condition assertions mockiem ani SQLite.

## Synthetic tenant-separated fixtures

`SyntheticTenantFixtureFactory` tworzy wyłącznie sztuczne UUID i testową tabelę `s5_test_tenant_resources`. Fixture zawiera dwa rozdzielone tenanty oraz dwa zasoby; żadne dane rzeczywiste ani production identifiers nie są używane. Jest to narzędzie harnessu, a nie implementacja docelowego modelu Organization ani dowód domenowego tenant isolation — te assertions powstaną w S5-FOUND-001 wraz z właściwym DDL.

## Stage-4 DBT traceability

`Stage4TestCatalog` parsuje bezpośrednio immutable matrix `specs/database/final-migration-order-invariant-matrix.yml` blob `ad5f2aa2e14ef248b95dd3dda0d1cbcb2e69d441`. Parser YAML jest zależnością dev i jest zamrożony w Composer lockfile.

Finalny katalog zawiera dokładnie 491 unikalnych ID w klasach:

- schema_constraint: 180,
- transaction: 178,
- security_storage: 57,
- projection: 31,
- concurrency: 29,
- migration_preflight: 14,
- migration_postcheck: 2.

Każdy ID ma dokładnie jeden runtime state. W S5-TST-001 tylko `DBT-CORE-099` i `DBT-CORE-100` mają status `implemented_executable_assertion`, ponieważ ich Stage-4 authority-level postcheck można wykonać już teraz. Pozostałe 489 ID mają `pending_domain_materialization`.

Nie jest to brak traceability: wszystkie 491 ID są obecne w runtime catalogu, ale stan implementacji pozostaje zgodny z rzeczywistością. Późniejszy moduł może zmienić ID na `implemented_executable_assertion` wyłącznie dodając konkretną klasę i publiczną metodę PHPUnit do registry.

## Migration preflight and postcheck

14 finalnych `migration_preflight` kontraktów dotyczy legacy/domain migration semantics, których tabele i bounded slices nie są jeszcze zmaterializowane. Dlatego wszystkie 14 pozostają pending. Sam mechanizm preflight jest jednak wykonywalny: testowa klasa `MigrationPreflight` wymaga zera nierozwiązanych wierszy i fail-closed odrzuca syntetyczny przypadek legacy ambiguity przed przejściem po jego naprawie.

Oba finalne `migration_postcheck` są wykonywane naprawdę:

- `DBT-CORE-099` sprawdza 491 unikalnych ID, class counts i dokładny Stage-4 `PASS_ZERO_GAPS`: 77 blockerów, 183 critical constraints, 45 transactional invariants, 1581 lokalnych wymaganych wystąpień, zero unknown/orphan/collision/gap;
- `DBT-CORE-100` sprawdza immutable bloby final matrix, core schema, Stage-4 central gate, docs/87 i docs/116 oraz finalny 491-test catalog.

## Executable evidence

Pełny przebieg na świeżym runnerze uruchomił PostgreSQL, Redis i testowy S3 adapter z S5-ENV-001, a następnie `php artisan test --fail-on-warning`. Wynik: **19 testów / 1096 assertions — PASS**. `composer validate --strict`, parser availability, PHP syntax checks i preservation gate również przeszły.

## Boundary

S5-TST-001 nie implementuje 489 oczekujących domenowych kontraktów, nie materializuje kolejnych Stage-4 DDL, nie tworzy modeli, endpointów ani UI. Pełny implementation CI pozostaje osobnym `S5-CI-001`, a pierwszy core slice pozostaje `S5-FOUND-001`.

Następny krok po osobnej instrukcji: **S5-CI-001**.

**STOP przed S5-CI-001.**
