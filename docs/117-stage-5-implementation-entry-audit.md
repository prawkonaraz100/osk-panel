# 117. Stage 5 — implementation entry audit

Data: 2026-09-10

**Etap:** `STAGE_5_IMPLEMENTATION`
**Aktualny krok:** `S5-BOOT-001`
**Status:** `S5-BOOT-001 PASS / 0 P0 / 5 P1 OPEN`

Machine-readable gate: `specs/gates/stage-5-implementation-gate.yml`.

---

## 1. Co oznacza Stage 5

Stage 4 zakończył projekt kontraktu bazy danych i finalny migration/test authority. Stage 5 jest etapem przejścia od dokumentacji/specyfikacji do wykonywalnej implementacji.

To nazewnictwo jest niezależne od `docs/09-roadmap.md`, gdzie „Faza 5” oznacza moduł Student Finance. W tym audycie `Stage 5` oznacza piąty etap delivery po Stage 3 OpenAPI i Stage 4 Database Contract. Modułowa kolejność implementacji nadal pochodzi z `AGENTS.md` i zaczyna się od Identity + Tenant + RBAC + Audit.

## 2. Prerequisite Stage 4

Stage 4 jest zamknięty i stanowi read-only authority dla wejścia do implementacji:

- accepted closure commit: `2e4204d53f37a009a7bafa99bf727c8d1ab23836`,
- Stage-4 central gate blob: `306dabaa58223abe2f3b2a34b888b075c88e9ec4`,
- `core-schema.yml` blob: `b0629cc48e26798d945356eaa1d0c9b1187aa542`,
- final migration/test matrix blob: `ad5f2aa2e14ef248b95dd3dda0d1cbcb2e69d441`,
- `docs/87` blob: `a48eacd92d4a9c8e989f5d70c3bec95b9231a77c`,
- `docs/116` blob: `6f76255742d4902546f45c28d54c1da6ee3d3fd7`.

Finalny kontrakt Stage 4 obejmuje 170-node executable migration DAG, siedem faz `expand -> preflight -> write_fence -> backfill -> reconcile -> validate -> contract`, 491 finalnych test IDs oraz 77/77 zamkniętych blockerów przy zero coverage gaps.

Stage 5 nie może uprościć tych authority do zwykłej kolejności tabel, jednego końcowego worka constraintów ani application-only validation.

## 3. Stan repo na wejściu

Repo jest nadal repozytorium dokumentacji/specyfikacji. Na accepted tree nie ma:

- `composer.json`,
- `artisan`,
- `package.json`,
- backendowego scaffoldingu Laravel,
- frontendowego scaffoldingu Vue,
- wykonywalnych migracji Laravel,
- wykonywalnego framework test suite,
- CI dla kodu aplikacji.

Istniejący `.github/workflows/api-contract-gate.yml` jest contract CI i pozostaje potrzebny, ale sam nie stanowi implementation CI.

Wniosek: rozpoczęcie od konkretnego ekranu, Student Finance albo domenowego CRUD byłoby złamaniem kolejności. Najpierw musi powstać foundation pozwalający bezpiecznie wykonać późniejsze migracje, testy i moduły.

## 4. Zasady wykonania Stage 5

Obowiązuje dotychczasowy tryb pracy:

- jeden blocker na raz,
- diagnoza przed fixerem,
- osobna bramka jakości po każdym fixerze,
- helper/workflow commits nie wchodzą do accepted history,
- accepted branch tylko fast-forward i po ponownym sprawdzeniu jego tipa,
- Stage-4 authorities są read-only, chyba że nowy, udowodniony defect wymusi osobny prerequisite repair,
- nie generujemy 170 migracji jedną nieprzeglądalną zmianą,
- nie oznaczamy 491 testów jako wdrożone bez wykonywalnych assertions,
- UI/features pozostają zablokowane do przejścia foundation/runtime gates.

## 5. Diagnoza blockerów

### S5-BOOT-001 — application scaffold and toolchain

**P1 / OPEN**

Brakuje aplikacyjnego scaffoldingu i przypiętego toolchainu. Fixer ma utworzyć minimalny modular-monolith bootstrap Laravel + Vue zgodny z `docs/07-architecture.md`, bez implementacji domenowych ekranów i bez arbitralnego instalowania integracji, których jeszcze nie potrzebujemy.

Wersje runtime/frameworków muszą zostać sprawdzone przy wykonywaniu fixera w aktualnych authoritative sources; diagnoza nie hardcoduje dziś numerów wersji.

### S5-ENV-001 — local/test/CI runtime services

**P1 / OPEN**

Po scaffoldzie potrzebny jest deterministyczny kontrakt PostgreSQL/Redis oraz bezpieczna konfiguracja adaptera storage dla local/test/CI. Fixtures nie mogą zawierać danych realnych ani production secrets.

### S5-MIG-001 — executable migration layer

**P1 / OPEN**

Nie istnieją jeszcze Laravel migrations. Ich implementacja musi zachować 170-node dependency authority, siedmiofazowy model oraz cutover/restart/rollback contract z DB-MIG-001/002/003. Nie wolno sprowadzić tego do „create tables, potem final constraints”.

Migracje będą dzielone na małe, przeglądalne partie z machine validation zamiast jednego dużego generatora.

### S5-TST-001 — executable framework test harness

**P1 / OPEN**

Stage 4 ma kontrakt 491 finalnych test IDs, ale nie ma jeszcze PHPUnit/Pest/Laravel test files. Stage 5 musi zbudować PostgreSQL-backed harness, synthetic tenant-separated factories, deterministic concurrency primitives oraz traceability `DBT-* -> executable test`.

### S5-CI-001 — implementation CI

**P1 / OPEN**

Istniejący OpenAPI gate ma pozostać aktywny. Do niego trzeba dołożyć backend lint/static analysis, frontend lint/typecheck/build, unit/integration tests na PostgreSQL, migration validation, secret scan i changed-module traceability.

### S5-FOUND-001 — first core foundation slice

**P1 / OPEN**

Dopiero po zamknięciu bootstrap/runtime/migration/test/CI można rozpocząć pierwszy właściwy moduł z `AGENTS.md`: Identity + Tenant + RBAC + Audit. Foundation musi od początku implementować tenant isolation, memberships, runtime permission authority, scopes, session tenant context, Owner/last-owner governance, authorization versioning i atomic audit/outbox dla krytycznych zmian.

## 6. Kolejność fixerów

Kolejność jest zamknięta dla tej diagnozy:

`S5-BOOT-001 -> S5-ENV-001 -> S5-MIG-001 -> S5-TST-001 -> S5-CI-001 -> S5-FOUND-001`

Nie oznacza to, że cały Stage 5 kończy się po S5-FOUND-001. To entry/foundation tranche. Kolejne moduły core będą wchodziły zgodnie z `AGENTS.md` dopiero po przejściu tego foundation gate.

## 7. Co nie blokuje wejścia do bootstrapu

Nie przenosimy do S5-BOOT-001 decyzji, które są potrzebne dopiero przed odpowiednim sensitive domain albo go-live, np. finalnego retention schedule, approved RPO/RTO, rzeczywistego PKK providera czy legal re-verification pełnego słownika kategorii.

Public ID strategy nie jest ponownie otwierana: późniejszy Stage-4 machine authority przyjął application-side UUIDv7 zapisany jako PostgreSQL native `uuid`, więc starszy baseline nie może cofnąć tej decyzji.

## 8. Wynik gate

Diagnoza: **0 P0 / 6 P1**.

Żaden fixer nie został wykonany w tym kroku. Nie utworzono Laravel migrations, feature code ani UI.

## 9. Następny pojedynczy krok

**`S5-BOOT-001 — application scaffold and toolchain`**.

Zakres następnego fixera jest ograniczony do scaffoldingu aplikacji, manifestów/lockfiles, bezsekretowych environment templates oraz podstawowych komend build/test. Nie wolno jeszcze implementować domenowych feature'ów ani materializować finalnych migracji poza ewentualnymi technicznymi defaultami scaffoldingu, które muszą zostać ocenione przed zachowaniem.

**STOP przed S5-BOOT-001.**


---

## 10. S5-BOOT-001 — closure

**PASS.** Utworzono minimalny, preservation-safe bootstrap aplikacji bez implementacji domenowych feature'ów i ekranów.

Przypięty toolchain: PHP 8.5, Laravel 13, Node 24.20.0 LTS, npm 11 oraz Vue 3 + Vite + TypeScript 6.0.3. Dokładne wersje zależności są zamrożone w `composer.lock` i `package-lock.json`. Manifest Composer ma własny opis projektu i `license: proprietary`; usunięto niepotrzebne bootstrapowi `tinker`, `sail`, `pail`, `pao` oraz frontendowy `@laravel/multiplex`.

Domyślne migracje Laravel, SQLite bootstrap database, przykładowy `User`/`UserFactory`, `welcome.blade.php`, przykładowe JS-y, favicon oraz skeletonowe instrukcje `CLAUDE.md` nie zostały zachowane. Istniejący projektowy `AGENTS.md` i wszystkie zamknięte authority Stage 4 pozostały byte-for-byte bez zmian.

Bootstrap ustawia PostgreSQL jako domyślny driver DB, ale nie konfiguruje jeszcze deterministycznych usług PostgreSQL/Redis dla local/test/CI — to jest wyłączny zakres następnego `S5-ENV-001`.

Bramka wykonała `composer validate --strict`, `php artisan --version`, `php artisan test --fail-on-warning`, `npm run typecheck` oraz `npm run build`. Na czas PHPUnit tworzony jest pusty `.env` wyłącznie efemerycznie na runnerze i usuwany przed diffem; testowy `APP_KEY` jest jawny w `phpunit.xml`. Żaden local/production `.env` ani sekret nie trafia do repo.

Pozostałe blockery: `S5-ENV-001`, `S5-MIG-001`, `S5-TST-001`, `S5-CI-001`, `S5-FOUND-001`.

Następny pojedynczy krok: **S5-ENV-001**.

**STOP przed S5-ENV-001.**
