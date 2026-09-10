from pathlib import Path

P = Path('docs/116-stage-4-final-migration-order-invariant-matrix-audit.md')
text = P.read_text()
original = text

def repl(old, new, label):
    global text
    n = text.count(old)
    if n != 1:
        raise AssertionError(f'{label}: expected 1 match got {n}')
    text = text.replace(old, new, 1)

repl('**Aktualny krok:** `DB-MIG-003`\n**Status:** `FAIL_WITH_3_P1_BLOCKERS / 0 P0 / 3 P1 OPEN`',
     '**Aktualny krok:** `DB-TST-001`\n**Status:** `FAIL_WITH_2_P1_BLOCKERS / 0 P0 / 2 P1 OPEN`', 'header')

repl('DB-MIG-001 zamknął wykonawczy graf zależności i kanoniczną kolejność topologiczną obiektów migracyjnych. DB-MIG-002 dodał nad tym DAG siedmiofazową kompozycję `expand/preflight/write_fence/backfill/reconcile/validate/contract`. DB-MIG-003 domyka warstwę wykonawczą: definiuje entry/exit/abort, restart/failure handling i bezpieczny rollback, nie zmieniając `requires`, `topological_order` ani `phase_order`.\n\nW tym kroku domykamy wyłącznie cutover/restart/failure/rollback. Nie tworzymy jeszcze macierzy testów DB-TST-001/002, nie synchronizujemy finalnych agregatów DB-FINAL-001, nie generujemy migracji Laravel, nie rozpoczynamy Stage 5 i nie zmieniamy UI/API.\n\nPo DB4_10 obowiązuje 71 wcześniej zamkniętych blockerów Stage 4. DB-MIG-001, DB-MIG-002 i DB-MIG-003 są kolejnymi trzema zamkniętymi blockerami; żaden wcześniejszy kontrakt nie został otwarty ponownie.',
     'DB-MIG-001 zamknął wykonawczy graf zależności i kanoniczną kolejność topologiczną obiektów migracyjnych. DB-MIG-002 dodał nad tym DAG siedmiofazową kompozycję `expand/preflight/write_fence/backfill/reconcile/validate/contract`. DB-MIG-003 domknął entry/exit/abort, restart/failure handling i bezpieczny rollback. DB-TST-001 dodaje nad tymi authority stabilną machine-readable macierz testów inwariantów bez zmiany DAG, faz ani cutover contract.\n\nW tym kroku domykamy wyłącznie strukturę finalnego katalogu testów: stabilne `test_id`, klasę wykonania, source invariant, expected outcome, execution phase, fixture profile, concurrency profile i blocking severity. Nie wykonujemy jeszcze completeness/coverage joinu DB-TST-002, nie synchronizujemy finalnych agregatów DB-FINAL-001, nie generujemy testów frameworkowych ani migracji Laravel, nie rozpoczynamy Stage 5 i nie zmieniamy UI/API.\n\nPo DB4_10 obowiązuje 71 wcześniej zamkniętych blockerów Stage 4. DB-MIG-001, DB-MIG-002, DB-MIG-003 i DB-TST-001 są kolejnymi czterema zamkniętymi blockerami; żaden wcześniejszy kontrakt nie został otwarty ponownie.', 'scope')

repl('## 2. Wynik po DB-MIG-003\n\nWynik po DB-MIG-003: **0 P0, 3 P1 OPEN**.\n\nPozostała wymagana kolejność fixerów:\n\n`DB-TST-001 → DB-TST-002 → DB-FINAL-001`',
     '## 2. Wynik po DB-TST-001\n\nWynik po DB-TST-001: **0 P0, 2 P1 OPEN**.\n\nPozostała wymagana kolejność fixerów:\n\n`DB-TST-002 → DB-FINAL-001`', 'result')

s = text.index('## 6. DB-TST-001 — machine-readable invariant test matrix\n')
e = text.index('\n## 7. DB-TST-002 — coverage i completeness proof\n', s)
new6 = '''## 6. DB-TST-001 — machine-readable invariant test matrix

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
'''
text = text[:s] + new6 + text[e:]

repl('Stage 4 zamknął przed DB4_11 71 blockerów; DB-MIG-001, DB-MIG-002 i DB-MIG-003 są już kolejnymi PASS, ale nadal nie istnieje finalna machine-readable relacja `resolved blocker -> final test IDs` dla całego Etapu 4.',
     'Stage 4 zamknął przed DB4_11 71 blockerów; DB-MIG-001, DB-MIG-002, DB-MIG-003 i DB-TST-001 są już kolejnymi PASS. Mamy teraz 261 stabilnych final test IDs, ale nadal nie istnieje finalna machine-readable relacja `resolved blocker -> final test IDs` dla całego Etapu 4.', 'section7')

s = text.index('## 10. Preservation gate\n')
e = text.index('\n## 11. Następny pojedynczy krok\n', s)
new10 = '''## 10. Preservation gate

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
'''
text = text[:s] + new10 + text[e:]

s = text.index('## 11. Następny pojedynczy krok\n')
new11 = '''## 11. Następny pojedynczy krok

Po domknięciu machine + narrative + central gate DB-TST-001 następny krok może dotyczyć wyłącznie:

**`DB-TST-002 — invariant_test_coverage_traceability_and_completeness_gate`**

I dopiero po kolejnym jawnym poleceniu użytkownika.

`core-schema.yml` i `docs/87...` pozostają zamrożone do DB-FINAL-001. DB-FINAL-001 pozostaje OPEN.

**STOP przed fixerem DB-TST-002.**
'''
text = text[:s] + new11

if text == original:
    raise AssertionError('no-op narrative')
for marker in [
    '**Aktualny krok:** `DB-TST-001`',
    '**Status:** `FAIL_WITH_2_P1_BLOCKERS / 0 P0 / 2 P1 OPEN`',
    '**Status: PASS / P1 RESOLVED**',
    '261 source occurrences i 261 unikalnych source invariants',
    '142 transaction, 46 security/storage, 23 schema-constraint, 21 projection, 15 concurrency, 14 migration-preflight i 0 migration-postcheck',
    '**STOP przed fixerem DB-TST-002.**'
]:
    if marker not in text:
        raise AssertionError(f'missing marker {marker}')
if '**STOP przed fixerem DB-TST-001.**' in text:
    raise AssertionError('stale STOP remains')
P.write_text(text)
print('DB_TST_001_NARRATIVE_PASS')
