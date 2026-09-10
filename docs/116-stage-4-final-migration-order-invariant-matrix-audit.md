# 116. Stage 4 — final migration order and invariant matrix audit

Data: 2026-09-10

**Etap:** `DB4_11_FINAL_MIGRATION_ORDER_AND_INVARIANT_TEST_MATRIX`
**Aktualny krok:** `DB-MIG-002`
**Status:** `FAIL_WITH_4_P1_BLOCKERS / 0 P0 / 4 P1 OPEN`

Machine-readable diagnoza: `specs/database/final-migration-order-invariant-matrix.yml`.

---

## 1. Cel i twarda granica tego kroku

DB4_11 nie projektuje nowej domeny biznesowej. To ostatni slice Etapu 4, którego zadaniem jest udowodnić, że wszystkie zamknięte kontrakty DB4_1–DB4_10 można bez zgadywania przełożyć na bezpieczną kolejność migracji oraz kompletną macierz testów inwariantów.

DB-MIG-001 pozostaje zamkniętym authority dla wykonawczego grafu zależności i kanonicznej kolejności topologicznej. DB-MIG-002 zamyka wyłącznie drugą warstwę: deterministyczne przypisanie node’ów DAG do faz `expand/preflight/write_fence/backfill/reconcile/validate/contract` oraz poprawną kolejność aktywacji PostgreSQL guards. Nie definiujemy jeszcze cutover/restart/rollback, nie tworzymy macierzy testów, nie generujemy migracji Laravel, nie rozpoczynamy Stage 5 i nie zmieniamy UI/API.

Po DB4_10 obowiązuje 71 wcześniej zamkniętych blockerów Stage 4; DB-MIG-001 jest 72., a DB-MIG-002 staje się 73. zamkniętym blockerem. Żaden wcześniejszy kontrakt nie został otwarty ponownie. Zamrożone pozostają:
- `specs/database/core-schema.yml` — blob `39958721c99550cfc27c3774e8dfa1af0d0637e6`,
- `docs/87-physical-database-schema.md` — blob `6c090b082604b6b42c283667fcf08e9033a5b523`.

## 2. Wynik diagnozy

Wynik po DB-MIG-002: **0 P0, 4 P1 OPEN**.

Pozostała wymagana kolejność fixerów:

`DB-MIG-003 → DB-TST-001 → DB-TST-002 → DB-FINAL-001`

Nie wolno scalać tych fixerów w jeden krok. Po każdym blockerze obowiązuje osobny machine + narrative + central gate.

## 3. DB-MIG-001 — executable migration dependency DAG

**Status: PASS / P1 RESOLVED**

Machine authority `specs/database/final-migration-order-invariant-matrix.yml` zawiera teraz `migration_dependency_dag` z trwałymi semantic node IDs, jawnym `requires` oraz jedną kanoniczną `topological_order`. Każda tabela z `core-schema.yml -> core_tables` ma dokładnie jeden node `table`; nie ma już jednego kroku grupującego wiele parent/child tabel i pozostawiającego numerację migracji implementatorowi.

DAG rozróżnia dokładnie osiem klas node'ów wymaganych przez ten blocker:
- `extension`,
- `table`,
- `candidate_key`,
- `index`,
- `foreign_key`,
- `trigger`,
- `projection`,
- `constraint`.

`btree_gist` jest osobnym node'em i wyprzedza indeksy/exclusion constraints Calendar. Candidate-key bundles wyprzedzają zależne same-tenant/composite FK bundles. Commerce ma osobny cross-domain node dla purchase provenance do License/Internal Exam/Service, więc wcześniejsze utworzenie downstream inventory nie wymusza błędnego FK przed `order_items`. DB4_10 ma kolejność Audit Policy → Audit → DomainEvent → Outbox → activity/notification projection, a projection nodes zależą od gotowych source/guard nodes.

Gate machine sprawdza automatycznie:
- unikalność node ID i `order`,
- dozwolony katalog typów,
- istnienie każdego `requires`,
- brak cyklu niezależnie od zadeklarowanej kolejności,
- że każdy dependency występuje wcześniej w `topological_order`,
- że zbiór node'ów `table` jest dokładnie równy zbiorowi `core_tables`, bez braków i dodatków,
- że żadna migracja Laravel nie powstaje w DB-MIG-001.

DAG DB-MIG-001 pozostaje niezmienionym dependency authority. DB-MIG-002 nie przestawia `topological_order`; nakłada na istniejące node’y niezależną, maszynowo walidowaną ścieżkę faz. Cutover/restart/rollback nadal pozostaje DB-MIG-003, a machine-readable test matrix DB-TST-001/002.

## 4. DB-MIG-002 — expand / write fence / backfill / validate / contract

**Status: PASS / P1 RESOLVED**

Machine authority zawiera teraz `migration_phase_composition` z jedną kanoniczną kolejnością siedmiu faz:

`expand → preflight → write_fence → backfill → reconcile → validate → contract`.

Każdy node istniejącego DAG DB-MIG-001 musi rozwiązać się do niepustej ścieżki faz przez regułę `exact override → node-type default`; nie wolno wyprowadzać faz ad hoc w migracjach Laravel. Machine gate sprawdza, że wszystkie typy DAG są pokryte, override wskazuje istniejący node, użyte fazy należą do katalogu i występują w poprawnej kolejności. `topological_order` z DB-MIG-001 pozostaje nadrzędnym dependency order i nie może zostać przepisany przez phase binding.

Semantyka PostgreSQL jest jawna. `FOREIGN KEY` i kwalifikujące się `CHECK` mogą wejść jako `NOT VALID` w `write_fence`, gdy trzeba chronić nowe write’y przed zakończeniem migracji starej populacji; `VALIDATE CONSTRAINT` następuje dopiero po exact backfill/reconciliation i zerze unresolved wymaganych przypadków. Nie składamy fałszywej obietnicy dla mechanizmów, które PostgreSQL tego nie wspiera: `UNIQUE`/candidate keys oraz exclusion constraints **nie** dostają fikcyjnego `NOT VALID`. Dla nich najpierw wykonuje się preflight konfliktów, potem wymaga zera unresolved, a dopiero następnie instaluje unique/exclusion boundary jako write fence. `btree_gist` pozostaje wcześniejszym dependency z DB-MIG-001.

Trigger/final-state guards wchodzą w `write_fence`, aby nowe zapisy nie tworzyły kolejnych wyjątków; spójność legacy jest osobno dowodzona przed/na `validate`. Projection nodes mają sekwencję `write_fence → exact backfill → reconcile → validate → contract`: najpierw nowy source/dedupe writer, potem tylko evidence-based history, a stary projection path można wycofać dopiero po walidacji.

Kontrakt grupuje legacy safety w sześć kohort: Identity/Tenant, Students/Training/Calendar, PKK, License/Internal Exam, Student Finance/Commerce oraz Audit/Events/Activity/Notifications. We wszystkich obowiązuje fail-closed: lokalny bounded-context contract decyduje, co jest wystarczającym dowodem; timestamp, nazwa, kwota, UUID albo samo `same tenant` nie tworzą lineage. DB4_9 nie może regrantować inventory, DB4_10 nie może zgadywać eventu/recipienta/read-state, a Calendar nie może automatycznie przesuwać ani wybierać zwycięzcy kolizji.

`validate` jest twardą bramką: wymagane unresolved P0/P1 migration/reconciliation cases muszą wynosić **0**. Niepowodzenie preflightu lub reconciliation oznacza STOP, nie force-pass. `contract` może deautoryzować stary path dopiero po walidacji i nie może niszczyć formalnej, finansowej ani audytowej historii.

DB-MIG-002 nie rozstrzyga operacyjnego cutoveru, restartu po częściowym failure ani rollback strategy — to pozostaje dokładnie DB-MIG-003. Nie powstał żaden plik migracji Laravel.

## 5. DB-MIG-003 — cutover, restart, failure i rollback safety

**Status: OPEN / P1**

Lokalne bounded contracts mają już wiele zasad fail-closed i retry/reconciliation, a `docs/85-production-operations.md` wymaga controlled migration, backupu, znanej strategii rollbacku oraz expand/contract. Brakuje jednak jednego globalnego Stage-4 kontraktu, który składa te zasady w wykonawczy cutover.

Plan musi określić dla faz migracji:
- entry conditions,
- exit/postconditions,
- abort conditions,
- czy node/faza jest automatycznie restart-safe czy wymaga manual review,
- co oznacza bezpieczny rollback.

Rollback nie może oznaczać automatycznego destructive `down` dla formalnej, finansowej lub audytowej historii. W zależności od fazy właściwą strategią może być rollback aplikacji przy forward-compatible schema, schema forward-fix albo tylko jawnie udowodniona bezpieczna migracja wstecz.

Destructive contract phase musi zostać związany z backup/restore evidence i zerem nierozwiązanych required reconciliation cases. DB4_11 nie ma wymyślać konkretnych produkcyjnych RPO/RTO.

## 6. DB-TST-001 — machine-readable invariant test matrix

**Status: OPEN / P1**

`core-schema.yml` posiada dużą listę `migration_tests_required`, ale są to wolne teksty bez stabilnych ID. `docs/87` opisuje je w sekcji 23 jako prose bullets.

To nie pozwala jednoznacznie powiedzieć, czy dany test jest:
- schema-constraint integration testem,
- transactional testem,
- prawdziwym concurrency/race testem,
- migration preflight testem,
- migration postcheckiem,
- projection testem,
- security-storage testem.

Finalna macierz musi mieć stabilne `test_id`, source invariant, klasę wykonania, expected outcome, fazę wykonania i wymagany kształt fixture/concurrency na poziomie kontraktu. Nie tworzymy tu jeszcze PHPUnit/Pest/Laravel test files.

## 7. DB-TST-002 — coverage i completeness proof

**Status: OPEN / P1**

Stage 4 zamknął dotąd 71 blockerów, ale nie istnieje machine-readable relacja `resolved blocker -> final test IDs`.

Nie ma również kompletnego joinu:
- `critical_constraints -> tests`,
- cross-table transaction/final-state guards -> tests,
- local bounded `required_tests` -> final aggregate tests.

W obecnym stanie można przez przypadek zgubić test podczas aggregate sync i nie wykryć tego maszynowo. Można też pozostawić orphan/stale test text, który daje fałszywe poczucie pokrycia.

Finalny gate musi wymagać:
- każdy resolved Stage-4 blocker ma co najmniej jeden final test,
- każdy krytyczny constraint i wymagany cross-table transaction guard ma test,
- lokalne `required_tests` są zachowane albo jawnie pokryte przez równoważny/silniejszy final test,
- zero unknown source refs,
- zero nieuzasadnionych orphan tests,
- zero coverage gaps.

## 8. DB-FINAL-001 — final machine/narrative semantic sync

**Status: OPEN / P1**

Diagnoza znalazła konkretny drift pomiędzy finalnym machine aggregate a `docs/87`.

W sekcji 24 narrative krok Commerce nadal streszcza tylko `orders/payments/service entitlements/activations`, podczas gdy machine aggregate po DB4_9 obejmuje również m.in. catalog items, OrderItems, payment events, settlements, fulfillments i purchase-grant lineage.

Krok DB4_10 w narrative nadal używa skrótu `audit/activity/outbox/notifications`, podczas gdy finalny machine contract ma zależności obejmujące audit policy revisions, AuditLog, DomainEvent jako canonical projection identity, Outbox, activity projection policies, Activity, Notifications, migration review oraz retention execution evidence.

DB-FINAL-001 ma zostać wykonany **na końcu**, po ustaleniu DAG i macierzy testów, aby oba agregaty otrzymały już finalną reprezentację. Wtedy trzeba ponownie uruchomić semantic-loss gate przez DB4_1–DB4_10 i usunąć wszystkie stare provisional shortcuts.

## 9. Co nie jest blockerem DB4_11

DB4_11 nie ma ponownie rozstrzygać domenowych decyzji, które już przeszły wcześniejsze slice'y. Nie zmieniamy m.in.:
- tenant isolation,
- Course/Training/Ledger authority,
- Calendar resource conflict model,
- License Inventory/Assignment/Activation,
- Internal Exam reserve/start/consume,
- PKK execution/reconciliation/crypto lineage,
- Student Finance vs Platform Commerce boundary,
- paid/fulfilled/grant lineage,
- DomainEvent/Outbox/Activity/Notification authority.

Nie rozwiązujemy też w tym slice zewnętrznych production blockers takich jak dokładny retention duration, final provider schemas czy legal re-verification kategorii. DB4_11 ma jedynie uwzględnić ich status jako zewnętrzne gate'y, nie wymyślać wartości.

## 10. Preservation gate

DB-MIG-002 zachowuje:
- DB4_1–DB4_10 bez zmian,
- 71 wcześniejszych blockerów bez reopen oraz DB-MIG-001 i DB-MIG-002 jako PASS,
- `core-schema.yml` bez zmian,
- `docs/87...` bez zmian,
- wszystkie bounded-context specs jako read-only,
- brak migracji Laravel,
- brak Stage 5,
- brak UI/feature implementation.

## 11. Następny pojedynczy krok

Po domknięciu machine + narrative + central gate DB-MIG-002 następny krok może dotyczyć wyłącznie:

**`DB-MIG-003 — global_cutover_restart_failure_and_safe_rollback_gate`**

I dopiero po kolejnym jawnym poleceniu użytkownika.

DB-TST-001, DB-TST-002 i DB-FINAL-001 pozostają OPEN.

**STOP przed fixerem DB-MIG-003.**
