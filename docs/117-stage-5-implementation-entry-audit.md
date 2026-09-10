# 117. Stage 5 — implementation entry audit

Data: 2026-09-10

**Etap:** `STAGE_5_IMPLEMENTATION`
**Aktualny krok:** `S5-CI-001`
**Status:** `S5-CI-001 PASS / 0 P0 / 1 P1 OPEN`

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

### 10.1. Korekty finalnego bootstrapu

Przed transferem do accepted history wykryto dwie pozostałości scaffoldingu. Laravel 13 scala frameworkowe defaulty auth, więc samo usunięcie `App\\Models\\User` nie wystarczało: `config/auth.php` neutralizuje teraz domyślny guard/provider/model przez jawne wartości `null`, a test potwierdza, że guard `web` nie może zostać utworzony przed `S5-FOUND-001`. Dodatkowo przywrócono marker files wymaganych katalogów `bootstrap/cache` i `storage/*`, dzięki czemu świeży checkout przechodzi `composer install`. Nie utworzono domenowych modeli, providerów, tabel ani migracji.

---

## 11. S5-ENV-001 — closure

**PASS.** Dodano deterministyczny local/test runtime bez wejścia w warstwę migracji domenowych. `compose.yml` uruchamia przypięte PostgreSQL 17.11, Redis 8.10.1 i Moto Server 5.2.2 jako hermetyczny endpoint S3-compatible. Moto zastępuje pierwotnie sprawdzony LocalStack: aktualny LocalStack wymaga zewnętrznego Auth Token już do uruchomienia kontenera, więc nie spełniał naszego wymogu token-free reproducibility dla local/test.

`phpunit.xml` nie korzysta już z array/sync/local fallbacków dla infrastruktury objętej tym gate: testy wskazują PostgreSQL, Redis i S3-compatible endpoint. `RuntimeServicesTest` wykonuje realny SQL connect, Redis cache/queue/lock oraz S3 create-bucket/write/read/delete. Dodano wymagany adapter Flysystem S3 i PHP Redis extension do manifestu Composer; flaga path-style jest normalizowana do boolean przed przekazaniem do AWS SDK.

Konfiguracja zawiera wyłącznie jawne local/test credentials (`osk_panel_local_only`, `test/test`), oznaczone jako nieprodukcyjne. Nie dodano żadnych danych osobowych, sekretów produkcyjnych, migracji domenowych, modeli, providerów auth ani feature/UI. Pełne CI pozostaje `S5-CI-001`; ten krok jedynie udowadnia, że ten sam kontrakt środowiska jest wykonywalny na świeżym runnerze.

Pozostałe blockery: `S5-MIG-001`, `S5-TST-001`, `S5-CI-001`, `S5-FOUND-001`.

Następny pojedynczy krok: **S5-MIG-001**.

**STOP przed S5-MIG-001.**

---

## 12. S5-MIG-001 — closure

**PASS.** Zamknięto brak warstwy wykonawczej migracji bez wygenerowania 170 domenowych DDL jako jednej nieprzeglądalnej zmiany. `database/migration-plan/plan.json` pozostaje byte-for-byte związany z finalnym Stage-4 matrix blob `ad5f2aa2…`, zawiera dokładnie 170 node'ów, canonical topological order, 17 review batches, siedem faz oraz restart classification z DB-MIG-003.

Po końcowym review pierwotny wrapper został dodatkowo utwardzony. Stage-4 migracje są izolowane pod `database/migrations/stage4/<phase>/<node>/...`, więc zwykły `php artisan migrate` ich nie odkrywa. Każda phase-step jest jawnie wpisana do `implementations.json` z node ID, fazą, restart classification i SHA-256 pliku. Registry musi zachowywać canonical topological order dla materializowanego podzbioru, być dependency-closed względem wszystkich `requires`, a zarejestrowane fazy każdego node'a muszą być prefiksem authoritative phase path. Materializacja nie wymaga niezależnych wcześniejszych node'ów wyłącznie z powodu niższego `order`.

Controlled executor wymaga teraz jednocześnie exact `plan_identity`, exact `execution_identity` związanej z bytes registry/migracji oraz jawnego `--phase`. Bezpośrednie `migrate --path=...` nie omija tego kontraktu: sam plik migracji wymaga aktywnego `ControlledMigrationContext` z właściwą fazą i node ID. Późniejsza faza pozostaje fail-closed, dopóki wszystkie authoritative stepy wcześniejszych faz nie są w pełni zmaterializowane i zastosowane.

Execution journal zapisuje run-level oraz node-level evidence. W production pusty lub brakujący `MIGRATION_EVIDENCE_PATH` zatrzymuje wykonanie przed DDL. Dla przyszłych `manual_review` stepów przerwane albo failed evidence blokuje automatyczny retry; wznowienie wymaga jawnego `--reviewed-resume=<node-id>` i zostaje zapisane w evidence. PostgreSQL advisory lock `(519662, 5001)`, zakaz blind retry i generic destructive down pozostają bez zmian.

Pierwszą i jedyną zmaterializowaną stepą nadal jest `MIG-EXT-BTREE-GIST / expand`. Restart-safe migracja sprawdza exact postcondition przed efektem, tworzy `btree_gist` tylko gdy extension nie istnieje i odmawia automatycznego `down()`. Mechanizm nie jest jednak hardcodowany do jednego node'a: kolejne implementacje mogą rozszerzać tylko canonical dependency-closed prefix oraz authoritative phase prefixes.

Machine gate na świeżym PostgreSQL potwierdził plan 170/170, 17 review batches, hash/execution identity tamper rejection, rogue-file rejection, brak odkrycia przez default `migrate`, odmowę direct `--path`, zły plan i execution identity, brak jawnej fazy, przedwczesny `preflight`, production bez durable evidence, konkurencyjny lock, poprawne pierwsze wykonanie i brak ponownej aplikacji po sukcesie. PHPUnit `--fail-on-warning`, PHP syntax checks oraz preservation wszystkich zamkniętych Stage-4 authority również przeszły.

Pozostałe blockery: `S5-TST-001`, `S5-CI-001`, `S5-FOUND-001`. Następny pojedynczy krok: **S5-TST-001**.

**STOP przed S5-TST-001.**


### 12.1. Korekta reguły materializacji podzbioru

Przy wejściu do `S5-FOUND-001` machine diagnosis wykazał, że lokalna implementacja `S5-MIG-001` była bardziej restrykcyjna niż zamknięty Stage-4 DAG: wymóg pełnego canonical prefixu zmuszałby foundation audit/outbox do materializacji 166/170 node'ów, w tym 121 niezwiązanych z pierwszym modułem. Stage-4 authority wymaga kolejności topologicznej i ukończenia wszystkich jawnych `requires`, ale nie nakazuje materializacji niezależnych node'ów o niższym `order`.

Regułę wykonawczą skorygowano preservation-safe: materializowany podzbiór musi zachować canonical order i być dependency-closed, bez przeskakiwania zależności. Siedem faz, plan identity, 170-node DAG, restart classification, phase-entry i cutover/rollback contract pozostają bez zmian. Machine repair: `6e4947c6c0b1b1237a0973523897ceccb03d0d41`.

---

## 13. S5-TST-001 — closure

**PASS.** Utworzono wykonywalny framework test harness działający na rzeczywistym PostgreSQL. Harness ma syntetyczne, tenant-separated fixtures oraz niezależne połączenia PDO; testy potwierdzają różne backend PID, niewidoczność niezatwierdzonej transakcji w drugiej sesji i zachowanie advisory lock między połączeniami.

Finalny katalog Stage 4 jest parsowany bezpośrednio z immutable matrix blob `ad5f2aa2…` i zawiera dokładnie 491 unikalnych `DBT-*`. Traceability nie udaje implementacji domenowej: tylko `DBT-CORE-099` i `DBT-CORE-100` mają status `implemented_executable_assertion`, ponieważ wskazują istniejącą publiczną metodę PHPUnit z realnymi assertions. Pozostałe 489 mają `pending_domain_materialization`.

W katalogu jest 14 `migration_preflight` oraz 2 `migration_postcheck`. Wszystkie 14 domenowych preflightów pozostaje pending do materializacji ich DDL/slices; jednocześnie sam fail-closed preflight harness jest wykonany na syntetycznym przypadku unresolved legacy row. Oba postchecki Stage 4 są realnie wykonywane: `DBT-CORE-099` sprawdza `PASS_ZERO_GAPS`, a `DBT-CORE-100` immutable final aggregate authority.

Pełny przebieg na usługach S5-ENV-001 zakończył się **19 testów / 1096 assertions — PASS**, przy `--fail-on-warning`. Composer validation, PHP syntax oraz preservation Stage-4 authority również przeszły.

Nie utworzono domenowych modeli, endpointów, UI ani kolejnych migracji Stage 4. Pełny implementation CI pozostaje `S5-CI-001`, a core foundation `S5-FOUND-001`.

Następny pojedynczy krok: **S5-CI-001**.

**STOP przed S5-CI-001.**

---

## 14. S5-CI-001 — closure

**PASS.** Dodano stały `Implementation CI`, który działa dla pull requestów oraz pushy na accepted branch. Machine layer został przyjęty jako clean commit `55b6d0af82ee018cb3c0a201e4d4e6a6358c6a06`; helper history nie weszła do accepted.

Backend gate wykonuje `composer validate --strict`, Pint oraz Larastan/PHPStan na poziomie 8 dla `app` i `routes`. Machine validation ujawnił 18 rzeczywistych problemów statycznego typowania w istniejącej warstwie migracji i command wrapperze; zostały poprawione jawnie, bez baseline'u, ignored errors ani suppressions. Finalny backend gate przechodzi z zerem błędów.

Frontend gate wykonuje `npm ci`, ESLint z `--max-warnings=0`, Vue/TypeScript typecheck, production build oraz `npm audit --audit-level=high`. Przypięte narzędzia obejmują ESLint 10.10.0, `@eslint/js` 10.0.1, `eslint-plugin-vue` 10.11.0 i `typescript-eslint` 8.70.0; TypeScript pozostaje 6.0.3.

Runtime gate uruchamia deterministyczne usługi `S5-ENV-001`, waliduje migration plan/registry przez `php artisan migration:plan:validate --json` i wykonuje framework suite na rzeczywistym PostgreSQL z `--fail-on-warning`. OpenAPI validator pozostaje aktywny w osobnym jobie contracts-and-traceability. Ten sam job wykonuje self-test oraz fail-closed `validate_changed_module_traceability.py`, aby przyszła zmiana pod `app/Modules/<Module>/` lub `resources/js/modules/<Module>/` nie mogła wejść bez odpowiadającego traceability w tym samym diffie. Osobny job Gitleaks skanuje committed changes pod kątem sekretów.

Kluczowym closure evidence jest permanentny accepted-branch run `34505180921`: **5/5 jobów SUCCESS** — `backend-quality`, `frontend-quality`, `runtime-tests-and-migrations`, `contracts-and-traceability` i `secret-scan`. To potwierdza działanie docelowego workflow, a nie wyłącznie tymczasowego helpera. Szczegółowy kontrakt i provenance zapisano w `docs/121-stage-5-implementation-ci.md`.

Stage-4 authority pozostały byte-for-byte bez zmian. Nie rozpoczęto domenowych modeli, endpointów, ekranów ani `S5-FOUND-001`; statyczny hardening migration executora nie zmienia 170-node plan/order/restart/cutover authority.

Po centralnym gate jedynym pozostałym blockerem entry/foundation tranche ma być `S5-FOUND-001`.

Następny pojedynczy krok: **S5-FOUND-001 — first core foundation slice**.

**STOP przed S5-FOUND-001.**

---

## 15. S5-FOUND-001 — foundation closure

**Machine: PASS. Narrative: PASS. Central: PENDING.**

Pierwszy core foundation slice został zaimplementowany w clean commit `f32d5c77bb844aac6e2ea0770b70b415fd28354e`, bez helper history. Zakres pozostał ograniczony do `IdentityTenant`, `OrganizationSettings` oraz minimalnego `AuditNotification`.

Migration registry materializuje 18/170 node'ów: wcześniejszy `MIG-EXT-BTREE-GIST` oraz 17 dependency-closed foundation table nodes. Stage-4 DAG, siedem faz i immutable authority pozostały bez zmian. Finalny DBT catalog nadal zawiera 491 IDs; 13 jest już związanych z wykonywalnymi foundation assertions, a 478 pozostaje jawnie pending do kolejnych domenowych slices.

Foundation ma realny Laravel auth provider, active tenant membership context, explicit permission authority, per-permission scopes, same-tenant validation, authorization versioning oraz Owner governance. Końcowy review zatrzymał pierwszy zielony kandydat i wymusił hardening dwóch ryzyk: protected Owner baseline nie może zostać osłabiony zwykłym permission mutation, a krytyczne authorization/settings writes serializują tenant/membership rows przed finalnym permission check, eliminując wykryte TOCTOU.

`OrganizationSettingsService` używa optimistic concurrency i atomowo zapisuje audit/domain-event/outbox. Minimalny audit layer wymaga current audit policy i allowlistuje payload. `FoundationReferenceCatalogSeeder` czyta permission/scope authority bezpośrednio z `specs/security/permissions.yml`; nie istnieje niezależna ręczna lista runtime.

Helper hardening run `34532023466` zakończył się SUCCESS. Po clean promotion accepted-branch `Implementation CI` run `34532350584` przeszedł 5/5 jobów, osobny API Contract Gate `34532350492` również przeszedł, a PostgreSQL suite zakończył się wynikiem **35 tests / 1181 assertions PASS**.

Szczegółowy zakres, hardening, testy i provenance: `docs/122-stage-5-foundation.md`.

Centralny gate może teraz zamknąć `S5-FOUND-001` i ustawić brak otwartych P0/P1, ale dopiero po własnej machine consistency/preservation validation.

**STOP przed następnym core slice.**
