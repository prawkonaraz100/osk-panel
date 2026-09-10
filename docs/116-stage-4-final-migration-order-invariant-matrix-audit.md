# 116. Stage 4 — final migration order and invariant matrix audit

Data: 2026-09-10

**Etap:** `DB4_11_FINAL_MIGRATION_ORDER_AND_INVARIANT_TEST_MATRIX`
**Aktualny krok:** `DB-TST-001`
**Status:** `FAIL_WITH_2_P1_BLOCKERS / 0 P0 / 2 P1 OPEN`

Machine-readable authority: `specs/database/final-migration-order-invariant-matrix.yml`.

---

## 1. Cel i twarda granica tego kroku

DB4_11 nie projektuje nowej domeny biznesowej. To ostatni slice Etapu 4, którego zadaniem jest udowodnić, że wszystkie zamknięte kontrakty DB4_1–DB4_10 można bez zgadywania przełożyć na bezpieczną kolejność migracji oraz kompletną macierz testów inwariantów.

DB-MIG-001 zamknął wykonawczy graf zależności i kanoniczną kolejność topologiczną obiektów migracyjnych. DB-MIG-002 dodał nad tym DAG siedmiofazową kompozycję `expand/preflight/write_fence/backfill/reconcile/validate/contract`. DB-MIG-003 domknął entry/exit/abort, restart/failure handling i bezpieczny rollback. DB-TST-001 dodaje nad tymi authority stabilną machine-readable macierz testów inwariantów bez zmiany DAG, faz ani cutover contract.

W tym kroku domykamy wyłącznie strukturę finalnego katalogu testów: stabilne `test_id`, klasę wykonania, source invariant, expected outcome, execution phase, fixture profile, concurrency profile i blocking severity. Nie wykonujemy jeszcze completeness/coverage joinu DB-TST-002, nie synchronizujemy finalnych agregatów DB-FINAL-001, nie generujemy testów frameworkowych ani migracji Laravel, nie rozpoczynamy Stage 5 i nie zmieniamy UI/API.

Po DB4_10 obowiązuje 71 wcześniej zamkniętych blockerów Stage 4. DB-MIG-001, DB-MIG-002, DB-MIG-003 i DB-TST-001 są kolejnymi czterema zamkniętymi blockerami; żaden wcześniejszy kontrakt nie został otwarty ponownie. Zamrożone pozostają:
- `specs/database/core-schema.yml` — blob `39958721c99550cfc27c3774e8dfa1af0d0637e6`,
- `docs/87-physical-database-schema.md` — blob `6c090b082604b6b42c283667fcf08e9033a5b523`.

## 2. Wynik po DB-TST-001

Wynik po DB-TST-001: **0 P0, 2 P1 OPEN**.

Pozostała wymagana kolejność fixerów:

`DB-TST-002 → DB-FINAL-001`

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

Dla candidate key / `UNIQUE` kontrakt ma `NOT_VALID_supported: false`. Wymagana sekwencja to: preflight duplicate/nullability conflicts → zero unresolved conflicts → unique index lub constraint jako new-write fence → attach constraint using index tylko wtedy, gdy pozwalają na to kontrakt i PostgreSQL. Dokładny wybór operacyjny `CONCURRENTLY` pozostaje decyzją implementacyjną. DB-MIG-003 nie wymusza jego użycia, ale zamyka sposób obsługi przerwanego lub częściowego nontransactional/concurrent index build: exact inspection i `manual_review`, bez blind drop/recreate.

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

DB-MIG-002 sam nie definiuje entry/exit/abort conditions cutover, restart safety, failure handling ani rollback strategy. Te decyzje zostały domknięte dopiero przez DB-MIG-003 opisany poniżej. DB-MIG-002 nie definiuje również konkretnych operacyjnych wartości produkcyjnych ani nie generuje fizycznych migracji Laravel.

## 5. DB-MIG-003 — cutover, restart, failure i rollback safety

**Status: PASS / P1 RESOLVED**

DB-MIG-003 domyka globalny execution contract dla migracji Stage 4. Nie zmienia kolejności zależności z DB-MIG-001 ani siedmiofazowego modelu z DB-MIG-002. Dodaje trzeci wymiar: warunki wejścia i wyjścia z fazy, warunki przerwania, sposób wznowienia po awarii oraz dozwolone strategie rollbacku.

Machine authority to `migration_cutover_execution_contract` w `specs/database/final-migration-order-invariant-matrix.yml`. Kontrakt obejmuje wszystkie 170 node'ów DAG i rozstrzyga ich restart posture bez tworzenia migracji Laravel.

### 5.1. Execution control i restart safety

Migracja wymaga jednego aktywnego executora dla danego planu. Dokładny mechanizm lock/lease pozostaje decyzją implementacyjną, ale równoległe niezależne wykonanie tego samego planu jest zabronione. Po utracie executora takeover wymaga dowodu, że poprzedni executor nie jest już aktywny, oraz odnotowanej decyzji operatora.

Najważniejsza zasada restartu brzmi: **postcondition first, nigdy blind retry**. Po przerwaniu najpierw sprawdza się dokładny stan obiektu i częściowe efekty. Jeżeli oczekiwany postcondition już istnieje, krok można uznać za wykonany bez ponownego nakładania efektu. Jeżeli nie ma efektu i preconditions nadal obowiązują, automatyczne ponowienie jest dopuszczalne tylko dla node'a sklasyfikowanego `restart_safe`. Efekt częściowy, niejednoznaczny albo obiekt o innej definicji oznacza `manual_review` lub `STOP`.

Machine gate używa dwóch wartości: `restart_safe` i `manual_review`. Domyślnie `extension`, `table`, `foreign_key` i `trigger` są restart-safe po sprawdzeniu postcondition; `candidate_key`, `index`, `projection` i `constraint` wymagają manual review. Jawne override do manual review obejmuje także Calendar GiST, purchase downstream lineage, event lineage i event trigger, ponieważ ich fazy mogą obejmować konflikt, legacy backfill albo reconciliation.

`restart_safe` nie oznacza bezwarunkowej idempotencji. Każdy retry nadal wymaga sprawdzenia dokładnej definicji lub efektu wierszowego.

### 5.2. Entry / exit / abort dla siedmiu faz

Każda z siedmiu faz DB-MIG-002 ma teraz własny execution gate:

- `expand` — wejście wymaga zgodnego plan identity, jednego executora i kompatybilności aplikacji z forward-compatible schema; wyjście wymaga dokładnych postconditions i braku destructive change; niezgodny lub częściowy DDL zatrzymuje wykonanie,
- `preflight` — wejście wymaga gotowego expand i obowiązujących exact-evidence rules; wyjście wymaga pełnej inwentaryzacji i klasyfikacji naruszeń oraz zera konfliktów blokujących fence'y, których nie da się aktywować jako `NOT VALID`; brak lub konflikt evidence oznacza STOP,
- `write_fence` — writer musi być kompatybilny z nowym guardem; po wyjściu nowe write'y nie mogą tworzyć kolejnych legacy exceptions; nie wolno wyłączać guardu tylko po to, aby backfill przeszedł,
- `backfill` — działa tylko na exact durable evidence, z checkpoint/resume semantics tam, gdzie przerwanie może zostawić częściowy postęp; unproven rows trafiają do reconciliation, a retry nie może tworzyć podwójnego grant/event/payment effect,
- `reconcile` — wymagane przypadki muszą mieć trwały issue reason i bezpieczny fingerprint; przed validation liczba wymaganych nierozwiązanych P0/P1 musi wynosić zero; nie wolno fabrykować lineage, recipient, read state, payment ani inventory history,
- `validate` — write fence pozostaje aktywny, backfill i reconciliation muszą mieć PASS, a wszystkie final invariants/constraints i projection postconditions muszą przejść; failure blokuje przejście do `contract`,
- `contract` — wejście wymaga PASS validation, zera unresolved, decyzji o kompatybilności fallback application, zatrzymania starych writer paths oraz — dla produkcji i destructive/deauthorization scope — zdrowego backupu i udokumentowanego restore evidence. Przerwanie tej fazy zawsze wymaga manual review.

DB-MIG-003 nie ustala konkretnych limitów lock timeout, statement timeout ani wielkości batchy. To decyzje operacyjne wdrożenia, ale ich przekroczenie musi prowadzić do bezpiecznego abortu, a nie do omijania inwariantów.

### 5.3. Failure i resume matrix

Kontrakt rozróżnia rodzaje awarii zamiast stosować jedno ogólne „spróbuj ponownie”. Transactional DDL może być ponowiony dopiero po potwierdzeniu rollbacku transakcji i postcondition. Nieudany nontransactional/concurrent index build wymaga inspekcji partial/invalid artifact i manual review — blind `drop and recreate by name` jest zabroniony.

Przerwany backfill może być wznowiony tylko wtedy, gdy checkpoint i resume predicate są udowodnione jako idempotentne względem exact evidence. Przerwana privileged reconciliation nie jest automatycznie replayowana. Validation failure utrzymuje write fence, blokuje `contract` i prowadzi do forward-fix/reconciliation, po czym validation jest uruchamiana ponownie od własnego entry gate.

Jeżeli po zmianie DB psuje się health aplikacji, rollback aplikacji jest dopuszczalny tylko wtedy, gdy poprzednia/fallback wersja nadal jest kompatybilna z aktualnym schema i aktywnymi write fences. W przeciwnym razie wymagany jest schema forward-fix.

### 5.4. Rollback nie oznacza automatycznego `down`

DB-MIG-003 dopuszcza dokładnie trzy tryby rollback posture:

1. `application_rollback` — powrót do kompatybilnej wersji aplikacji bez cofania authoritative historii w bazie,
2. `schema_forward_fix` — preferowany po powstaniu trwałych efektów; zachowuje zatwierdzoną historię i naprawia kontrakt do przodu,
3. `explicit_safe_down` — wyłącznie jawnie opisany i zreviewowany reverse operation, gdy można udowodnić, że jest niedestrukcyjny i żaden authoritative write od niego nie zależy.

Generic/automatic destructive `down` nie jest strategią rollbacku dla formalnej, finansowej, audytowej ani innej wymaganej historii. Nie wolno także traktować restore starego backupu nad nowszymi prawidłowymi write'ami produkcyjnymi jako zwykłego rollbacku release.

Destructive `contract` wymaga zdrowego backupu i skutecznego restore evidence. Sam fakt istnienia backupu nie wystarcza. PITR/full restore pozostaje mechanizmem incident recovery, a nie domyślnym sposobem cofania schematu.

DB-MIG-003 nie wymyśla produkcyjnych RPO/RTO ani exact retention duration; pozostają one zewnętrzną polityką biznesową/legal/privacy zgodnie z `docs/85-production-operations.md`.

### 5.5. Final cutover smoke and integrity gate

Przed uznaniem cutoveru za zakończony wymagane są m.in. oczekiwane postconditions wszystkich node'ów wymaganych przez release, zero wymaganych unresolved migration cases, skuteczna final validation, brak niezaklasyfikowanych partial DDL artifacts, zgodne active write fences, health/readiness aplikacji oraz bounded-context migration/projection integrity. Destructive contract actions wymagają dodatkowo backup/restore evidence.

Ten smoke/integrity gate jest **release-level execution evidence**, a nie macierzą testów DB-TST-001. DB-MIG-003 świadomie nie nadaje stabilnych `test_id` i jego PASS nie zamyka DB-TST-001 ani DB-TST-002.

## 6. DB-TST-001 — machine-readable invariant test matrix

**Status: PASS / P1 RESOLVED**

Machine authority `invariant_test_matrix` przekształca istniejące `migration_tests_required` z `core-schema.yml` z listy wolnych tekstów w stabilny kontrakt wykonawczy. Parser znalazł **261 source occurrences i 261 unikalnych source invariants**, czyli w aktualnym aggregate nie ma duplikatów wymagających scalenia semantycznego.

Każdy finalny wpis ma:
- trwały `test_id` w formacie `DBT-<DOMAIN>-NNN`,
- dokładny `source_invariant`,
- co najmniej jeden `source_ref` do konkretnego `migration_tests_required[index]`,
- `test_class`,
- `execution_phase`,
- `blocking_severity: P1`,
- `expected_outcome`,
- `fixture_profile`,
- `concurrency_profile`,
- jawny status `contract_only_no_framework_test_generated`.

ID są przydzielone w kolejności pierwszego wystąpienia source invariant w zaakceptowanym aggregate. Po zamknięciu DB-TST-001 istniejących ID nie wolno renumerować bez jawnej migracji authority; nowe testy dopisują następny wolny numer w swojej domenie. Dzięki temu późniejsze implementacje testów i coverage joins mogą wskazywać stabilny identyfikator zamiast fragmentu prose.

### 6.1. Siedem klas wykonania

Macierz deklaruje dokładnie siedem wartości `test_class`:
- `schema_constraint`,
- `transaction`,
- `concurrency`,
- `migration_preflight`,
- `migration_postcheck`,
- `projection`,
- `security_storage`.

Aktualny rozkład 261 testów po quality review wynosi: **142 transaction, 46 security/storage, 23 schema-constraint, 21 projection, 15 concurrency, 14 migration-preflight i 0 migration-postcheck**. Zerowy count `migration_postcheck` jest jawny i prawidłowy: klasa pozostaje częścią stabilnego enumu, ale żaden obecny source invariant nie wymaga jej jako klasy podstawowej. Post-schema constraint checks pozostają klasyfikowane jako `schema_constraint`, a coverage/completeness zostanie udowodnione osobno w DB-TST-002.

Quality review skorygował oczywiste przypadki, których nie wolno redukować do zwykłego integration transaction testu: m.in. duplicate-worker grant race ma klasę `concurrency`, audit append-only/current-row uniqueness/DB-enforced immutability mają `schema_constraint`, a activity retry z historycznym snapshotem ma `projection`.

### 6.2. Fixture i concurrency contract

DB-TST-001 nie tworzy jeszcze PHPUnit/Pest/Laravel test files. Zamiast tego definiuje wymagany kształt testu na poziomie kontraktu. Profile obejmują minimalny valid+invalid tuple dla constraintów, success+forced-failure dla transakcji, dwa niezależne połączenia DB z deterministyczną barierą dla race tests, legacy population z exact/ambiguous cases dla migration preflight, canonical source + replay dla projection oraz authorized control + wrong-tenant/forbidden/sensitive negative context dla security/storage.

Każdy test klasy `concurrency` musi używać niepustego concurrency profile. Retry/replay projection ma odrębny profil od prawdziwego two-transaction race, dzięki czemu implementator nie może zastąpić concurrency testu sekwencyjnym unit testem.

`blocking_severity: P1` oznacza tutaj, że failure blokuje akceptację kontraktu bazy Stage 4; nie jest to produkcyjna klasyfikacja incydentu.

### 6.3. Twarda granica DB-TST-001

DB-TST-001 zapewnia stabilny katalog i execution contract testów, ale **nie twierdzi jeszcze, że katalog jest kompletny względem wszystkich local bounded specs i 75 zamkniętych blockerów**. Następujące dowody pozostają wyłącznie DB-TST-002:
- `resolved blocker -> final test IDs`,
- `critical constraint -> test IDs`,
- required cross-table/final-state transaction guard -> test IDs,
- local bounded `required_tests` -> final test lub jawny stronger-equivalent mapping,
- zero unknown source refs, orphan tests i coverage gaps.

DB-FINAL-001 nadal pozostaje jedynym krokiem uprawnionym do synchronizacji `core-schema.yml` i `docs/87`.

## 7. DB-TST-002 — coverage i completeness proof

**Status: OPEN / P1**

Stage 4 zamknął przed DB4_11 71 blockerów; DB-MIG-001, DB-MIG-002, DB-MIG-003 i DB-TST-001 są już kolejnymi PASS. Mamy teraz 261 stabilnych final test IDs, ale nadal nie istnieje finalna machine-readable relacja `resolved blocker -> final test IDs` dla całego Etapu 4.

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

DB-TST-001 zachowuje:
- DB4_1–DB4_10 bez zmian,
- 71 wcześniejszych blockerów bez reopen oraz DB-MIG-001, DB-MIG-002, DB-MIG-003 i DB-TST-001 jako kolejne PASS,
- executable DAG i `topological_order` DB-MIG-001 bez zmian,
- siedmiofazowy `migration_phase_composition` DB-MIG-002 bez zmian,
- `migration_cutover_execution_contract` DB-MIG-003 bez zmian,
- `core-schema.yml` bez zmian — blob `39958721c99550cfc27c3774e8dfa1af0d0637e6`,
- `docs/87...` bez zmian — blob `6c090b082604b6b42c283667fcf08e9033a5b523`,
- wszystkie bounded-context specs jako read-only,
- DB-TST-002 i DB-FINAL-001 jako nierozwiązane w tym kroku,
- brak framework test files, migracji Laravel, Stage 5 i UI/feature implementation.

## 11. Następny pojedynczy krok

Po domknięciu machine + narrative + central gate DB-TST-001 następny krok może dotyczyć wyłącznie:

**`DB-TST-002 — invariant_test_coverage_traceability_and_completeness_gate`**

I dopiero po kolejnym jawnym poleceniu użytkownika.

`core-schema.yml` i `docs/87...` pozostają zamrożone do DB-FINAL-001. DB-FINAL-001 pozostaje OPEN.

**STOP przed fixerem DB-TST-002.**
