# 116. Stage 4 — final migration order and invariant matrix audit

Data: 2026-09-10

**Etap:** `DB4_11_FINAL_MIGRATION_ORDER_AND_INVARIANT_TEST_MATRIX`
**Aktualny krok:** `DB-MIG-001`
**Status:** `FAIL_WITH_5_P1_BLOCKERS / 0 P0 / 5 P1 OPEN`

Machine-readable diagnoza: `specs/database/final-migration-order-invariant-matrix.yml`.

---

## 1. Cel i twarda granica tego kroku

DB4_11 nie projektuje nowej domeny biznesowej. To ostatni slice Etapu 4, którego zadaniem jest udowodnić, że wszystkie zamknięte kontrakty DB4_1–DB4_10 można bez zgadywania przełożyć na bezpieczną kolejność migracji oraz kompletną macierz testów inwariantów.

DB-MIG-001 jest pierwszym fixerem DB4_11. Zamykamy wyłącznie wykonawczy graf zależności i kanoniczną kolejność topologiczną obiektów migracyjnych. Nie przypisujemy jeszcze faz `expand/preflight/write_fence/backfill/reconcile/validate/contract`, nie definiujemy cutover/rollback, nie tworzymy macierzy testów, nie generujemy migracji Laravel, nie rozpoczynamy Stage 5 i nie zmieniamy UI/API.

Po DB4_10 obowiązuje 71 wcześniej zamkniętych blockerów Stage 4; DB-MIG-001 staje się 72. zamkniętym blockerem. Żaden wcześniejszy kontrakt nie został otwarty ponownie. Zamrożone pozostają:
- `specs/database/core-schema.yml` — blob `39958721c99550cfc27c3774e8dfa1af0d0637e6`,
- `docs/87-physical-database-schema.md` — blob `6c090b082604b6b42c283667fcf08e9033a5b523`.

## 2. Wynik diagnozy

Wynik po DB-MIG-001: **0 P0, 5 P1 OPEN**.

Pozostała wymagana kolejność fixerów:

`DB-MIG-002 → DB-MIG-003 → DB-TST-001 → DB-TST-002 → DB-FINAL-001`

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

Istotna granica: node'y nie mają jeszcze `phase` ani `migration_phase`. Składanie ich do `expand/preflight/write_fence/backfill/reconcile/validate/contract` pozostaje wyłącznie DB-MIG-002. Cutover/restart/rollback pozostaje DB-MIG-003, a machine-readable test matrix DB-TST-001/002.

## 4. DB-MIG-002 — expand / write fence / backfill / validate / contract

**Status: OPEN / P1**

Obecny aggregate kończy migration order jednym wspólnym krokiem typu „final partial indexes and cross-table constraints”. To jest zbyt szerokie i koliduje z już zamkniętymi local slice contracts.

Najbardziej widoczny przykład to DB4_10: current writers i write fence dla nowych rekordów muszą wejść **przed** legacy backfill, a `NOT VALID` FK/CHECK lub równoważna technika może chronić nowe write'y zanim stara populacja zostanie w pełni zwalidowana. DB4_9 i wcześniejsze slice'y również wymagają preflightu, exact-evidence backfillu, reconciliation i dopiero później final validation.

Dlatego finalny plan musi rozróżnić fazy:
- expand,
- preflight,
- new-runtime write fence,
- exact backfill,
- reconciliation,
- validate,
- contract.

Nie każdy constraint może zostać bezpiecznie przesunięty do jednego końcowego bucketu.

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

DB-MIG-001 zachowuje:
- DB4_1–DB4_10 bez zmian,
- 71 wcześniejszych blockerów bez reopen oraz DB-MIG-001 jako nowy PASS,
- `core-schema.yml` bez zmian,
- `docs/87...` bez zmian,
- wszystkie bounded-context specs jako read-only,
- brak migracji Laravel,
- brak Stage 5,
- brak UI/feature implementation.

## 11. Następny pojedynczy krok

Po domknięciu machine + narrative + central gate DB-MIG-001 następny krok może dotyczyć wyłącznie:

**`DB-MIG-002 — expand_write_fence_backfill_validate_contract_phase_composition`**

I dopiero po kolejnym jawnym poleceniu użytkownika.

DB-MIG-003, DB-TST-001, DB-TST-002 i DB-FINAL-001 pozostają OPEN.

**STOP przed fixerem DB-MIG-002.**
