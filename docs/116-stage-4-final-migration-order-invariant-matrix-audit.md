# 116. Stage 4 — final migration order and invariant matrix audit

Data: 2026-09-10

**Etap:** `DB4_11_FINAL_MIGRATION_ORDER_AND_INVARIANT_TEST_MATRIX`
**Aktualny krok:** `DB-MIG-002`
**Status:** `FAIL_WITH_4_P1_BLOCKERS / 0 P0 / 4 P1 OPEN`

Machine-readable authority: `specs/database/final-migration-order-invariant-matrix.yml`.

---

## 1. Cel i twarda granica tego kroku

DB4_11 nie projektuje nowej domeny biznesowej. To ostatni slice Etapu 4, którego zadaniem jest udowodnić, że wszystkie zamknięte kontrakty DB4_1–DB4_10 można bez zgadywania przełożyć na bezpieczną kolejność migracji oraz kompletną macierz testów inwariantów.

DB-MIG-001 zamknął wykonawczy graf zależności i kanoniczną kolejność topologiczną obiektów migracyjnych. DB-MIG-002 jest wyłącznie warstwą kompozycji fazowej nad tym DAG: przypisuje każdy node do jednej lub wielu faz `expand/preflight/write_fence/backfill/reconcile/validate/contract`, ale nie może zmieniać `requires` ani `topological_order` z DB-MIG-001.

W tym kroku nie definiujemy jeszcze cutover/restart/failure/rollback, nie tworzymy macierzy testów, nie generujemy migracji Laravel, nie rozpoczynamy Stage 5 i nie zmieniamy UI/API. Cutover/restart/failure/rollback pozostaje wyłącznie DB-MIG-003.

Po DB4_10 obowiązuje 71 wcześniej zamkniętych blockerów Stage 4. DB-MIG-001 i DB-MIG-002 są kolejnymi dwoma zamkniętymi blockerami; żaden wcześniejszy kontrakt nie został otwarty ponownie. Zamrożone pozostają:
- `specs/database/core-schema.yml` — blob `39958721c99550cfc27c3774e8dfa1af0d0637e6`,
- `docs/87-physical-database-schema.md` — blob `6c090b082604b6b42c283667fcf08e9033a5b523`.

## 2. Wynik po DB-MIG-002

Wynik po DB-MIG-002: **0 P0, 4 P1 OPEN**.

Pozostała wymagana kolejność fixerów:

`DB-MIG-003 → DB-TST-001 → DB-TST-002 → DB-FINAL-001`

Nie wolno scalać tych fixerów w jeden krok. Po każdym blockerze obowiązuje osobny machine + narrative + central gate.

## 3. DB-MIG-001 — executable migration dependency DAG

**Status: PASS / P1 RESOLVED**

Machine authority `specs/database/final-migration-order-invariant-matrix.yml` zawiera `migration_dependency_dag` z trwałymi semantic node IDs, jawnym `requires` oraz jedną kanoniczną `topological_order`. Każda tabela z `core-schema.yml -> core_tables` ma dokładnie jeden node `table`; nie ma jednego kroku grupującego wiele parent/child tabel i pozostawiającego numerację migracji implementatorowi.

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

DB-MIG-002 nie zastępuje ani nie przepisuje tego porządku. `migration_dependency_dag` pozostaje jedynym authority dla dependency order, a phase binding jest dodatkowym wymiarem wykonania. Machine gate wymaga, aby DB-MIG-001 `topological_order` pozostał niezmieniony przez kompozycję fazową.

## 4. DB-MIG-002 — expand / preflight / write fence / backfill / reconcile / validate / contract

**Status: PASS / P1 RESOLVED**

DB-MIG-002 zamyka wcześniejszą lukę, w której finalny aggregate odkładał „final partial indexes and cross-table constraints” do jednego wspólnego końcowego bucketu. Taki model był zbyt szeroki: część nowych write guards musi chronić nowe rekordy jeszcze zanim legacy population przejdzie backfill i pełną walidację.

Machine authority definiuje dokładnie siedem faz, w tej kolejności:

1. `expand` — dodanie forward-compatible tabel, kolumn, extensions i struktur wspierających bez odbierania starej authority; destructive change jest zabroniony.
2. `preflight` — inwentaryzacja istniejących danych i udowodnienie prerequisites zanim zostaną zainstalowane guardy, których legacy violations nie mogą przejść; guessing i auto-reparenting są zabronione.
3. `write_fence` — wymuszenie nowego kontraktu zapisu przed legacy backfill, tak aby nowe write'y nie tworzyły ani nie utrwalały kolejnych wyjątków.
4. `backfill` — uzupełnianie wyłącznie wartości udowodnionych przez exact durable evidence. Podobieństwo timestamp/name/amount/UUID lub samo same-tenant nie jest dowodem lineage.
5. `reconcile` — klasyfikacja nierozstrzygniętych lub konfliktowych legacy rows do review bez fabrykowania business truth; unresolved rows nie mogą być force-pass.
6. `validate` — dowód, że stara populacja i nowe write'y spełniają finalny invariant. Wymagana liczba nierozwiązanych przypadków P0/P1 przed validation wynosi zero.
7. `contract` — deautoryzacja albo usunięcie superseded paths dopiero po skutecznej validation i wymaganym evidence; destructive history rewrite/delete pozostaje zabroniony.

### 4.1. Binding node'ów do faz

Fazy nie tworzą alternatywnej kolejności migracji. Każdy node z DB-MIG-001 dostaje niepusty phase path według zasady `exact_override_else_node_type_default`, a wszystkie fazy tego path muszą zachować globalny `phase_order`.

Domyślne mapowanie klas node'ów jest następujące:
- `extension` → `expand`,
- `table` → `expand`,
- `candidate_key` → `preflight, write_fence`,
- `index` → `preflight, write_fence`,
- `foreign_key` → `preflight, write_fence, validate`,
- `constraint` → `preflight, write_fence, validate`,
- `trigger` → `write_fence, validate`,
- `projection` → `write_fence, backfill, reconcile, validate, contract`.

Machine authority posiada także jawne wyjątki tam, gdzie local bounded contract wymaga dodatkowych faz:
- `MIG-EXT-BTREE-GIST` → `expand`, zanim powstaną zależne Calendar GiST/exclusion objects,
- `MIG-IDX-CALENDAR_GIST` → `preflight, write_fence`, ponieważ exclusion conflicts muszą być wyzerowane przed instalacją, a exclusion constraint nie obsługuje `NOT VALID`,
- `MIG-FK-PURCHASE_DOWNSTREAM` → `preflight, write_fence, backfill, reconcile, validate`, ponieważ DB4_9 wymaga exact purchase lineage,
- `MIG-FK-EVENTS` → `preflight, write_fence, backfill, reconcile, validate`, ponieważ DB4_10 zachowuje fail-closed legacy source/actor/recipient lineage,
- `MIG-TRG-EVENTS` → `write_fence, backfill, reconcile, validate`, aby nowe Audit/DomainEvent/Outbox/projection rows były chronione przed legacy backfill.

Gate wymaga kompletnego rozwiązania faz dla wszystkich DAG node'ów: `unmapped_DAG_nodes = 0`, `unknown_phase_names = 0`, `phase_order_violations = 0`, a każdy exact override musi wskazywać istniejący node. Phase binding nie może przepisać topological order DB-MIG-001.

### 4.2. PostgreSQL activation strategy — bez fałszywego `NOT VALID`

DB-MIG-002 jawnie rozdziela mechanizmy PostgreSQL, których nie wolno traktować jednakowo.

Dla `FOREIGN KEY` i `CHECK`, gdy legacy population może być niezgodna, write fence może użyć `ADD CONSTRAINT ... NOT VALID` albo równoważnej techniki tylko wtedy, gdy PostgreSQL faktycznie ją wspiera dla danego mechanizmu. Backfill i reconciliation pozostają exact-evidence-only, a `VALIDATE CONSTRAINT` może nastąpić dopiero po osiągnięciu zera wymaganych unresolved cases.

Dla candidate key / `UNIQUE` kontrakt ma `NOT_VALID_supported: false`. Wymagana sekwencja to: preflight duplicate/nullability conflicts → zero unresolved conflicts → unique index lub constraint jako new-write fence → attach constraint using index tylko wtedy, gdy pozwalają na to kontrakt i PostgreSQL. Dokładny wybór operacyjny `CONCURRENTLY` pozostaje wdrożeniem i częścią cutover contract DB-MIG-003, a nie decyzją DB-MIG-002.

Dla exclusion constraint kontrakt również ma `NOT_VALID_supported: false`. Najpierw trzeba wykryć istniejące conflicting intervals, następnie przeprowadzić reconciliation bez auto-shift, cancel, reassign ani winner guessing, doprowadzić unresolved conflicts do zera i dopiero wtedy zainstalować exclusion constraint jako write fence.

Trigger/final-state guard jest instalowany w `write_fence`, a consistency proof dla legacy population następuje w `validate`; wyłączenie guardu tylko po to, aby backfill przeszedł, jest zabronione.

Projection przechodzi kolejno przez: instalację nowego source/dedupe write fence → exact-evidence backfill → reconciliation unresolved rows → validation source/dedupe/safe-snapshot invariants → retirement starej projection path dopiero w `contract`.

### 4.3. Legacy safety cohorts

Siedmiofazowy model zachowuje local bounded-context evidence policy i nie pozwala finalnemu aggregate osłabić wcześniejszych kontraktów. Machine authority grupuje wymagania bezpieczeństwa w sześć kohort:
- identity and tenant lineage — exact evidence only, write fence przed backfill i zero unresolved przed validate,
- students/training/calendar — bez zgadywania właściciela lub zwycięzcy konfliktu kalendarza,
- PKK execution/crypto — wymagane exact provider/operation/configuration/crypto lineage,
- license/exam/inventory — bez przepisywania historii assignment/activation/reservation/consumption,
- student finance/commerce — wymagane exact payment/settlement/fulfillment/purchase lineage i zakaz regrant tylko po to, aby zgadzała się ilość,
- audit/events/activity/notifications — wymagane exact event/actor/recipient/policy/read-state evidence i zakaz zgadywania eventu, odbiorcy lub read state.

We wszystkich tych kohortach write fence musi wejść przed backfill, a wymagane unresolved cases muszą wynosić zero przed final validation. Failed preflight albo reconciliation oznacza `STOP`, nie force-pass. Old path retirement przed validate jest zabroniony.

### 4.4. Granica DB-MIG-002

DB-MIG-002 nie definiuje entry/exit/abort conditions cutover, restart safety, failure handling ani rollback strategy. Wszystkie te decyzje pozostają jawnie odroczone do DB-MIG-003. Nie definiuje również konkretnych operacyjnych wartości produkcyjnych ani nie generuje fizycznych migracji Laravel.

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

Stage 4 zamknął przed DB4_11 71 blockerów; DB-MIG-001 i DB-MIG-002 są już kolejnymi PASS, ale nadal nie istnieje finalna machine-readable relacja `resolved blocker -> final test IDs` dla całego Etapu 4.

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
- 71 wcześniejszych blockerów bez reopen oraz DB-MIG-001 i DB-MIG-002 jako kolejne PASS,
- executable DAG i `topological_order` DB-MIG-001 bez zmian,
- `core-schema.yml` bez zmian,
- `docs/87...` bez zmian,
- wszystkie bounded-context specs jako read-only,
- DB-MIG-003, DB-TST-001, DB-TST-002 i DB-FINAL-001 jako nierozwiązane w tym kroku,
- brak migracji Laravel,
- brak Stage 5,
- brak UI/feature implementation.

## 11. Następny pojedynczy krok

Po domknięciu machine + narrative + central gate DB-MIG-002 następny krok może dotyczyć wyłącznie:

**`DB-MIG-003 — cutover_restart_failure_and_rollback_safety`**

I dopiero po kolejnym jawnym poleceniu użytkownika.

DB-TST-001, DB-TST-002 i DB-FINAL-001 pozostają OPEN.

**STOP przed fixerem DB-MIG-003.**
