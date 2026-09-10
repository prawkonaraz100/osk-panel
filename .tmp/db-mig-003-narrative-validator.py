from pathlib import Path

p = Path('docs/116-stage-4-final-migration-order-invariant-matrix-audit.md')
machine = Path('specs/database/final-migration-order-invariant-matrix.yml').read_text()
payload = Path('.tmp/db-mig-003-narrative-section.md').read_text().rstrip() + '\n\n'
text = p.read_text()
original = text


def replace_once(src, old, new, label):
    count = src.count(old)
    assert count == 1, f'{label}: expected 1 match, got {count}'
    return src.replace(old, new, 1)

text = replace_once(text, '**Aktualny krok:** `DB-MIG-002`', '**Aktualny krok:** `DB-MIG-003`', 'current step')
text = replace_once(text, '**Status:** `FAIL_WITH_4_P1_BLOCKERS / 0 P0 / 4 P1 OPEN`', '**Status:** `FAIL_WITH_3_P1_BLOCKERS / 0 P0 / 3 P1 OPEN`', 'top status')

text = replace_once(
    text,
    'DB-MIG-001 zamknął wykonawczy graf zależności i kanoniczną kolejność topologiczną obiektów migracyjnych. DB-MIG-002 jest wyłącznie warstwą kompozycji fazowej nad tym DAG: przypisuje każdy node do jednej lub wielu faz `expand/preflight/write_fence/backfill/reconcile/validate/contract`, ale nie może zmieniać `requires` ani `topological_order` z DB-MIG-001.\n',
    'DB-MIG-001 zamknął wykonawczy graf zależności i kanoniczną kolejność topologiczną obiektów migracyjnych. DB-MIG-002 dodał nad tym DAG siedmiofazową kompozycję `expand/preflight/write_fence/backfill/reconcile/validate/contract`. DB-MIG-003 domyka warstwę wykonawczą: definiuje entry/exit/abort, restart/failure handling i bezpieczny rollback, nie zmieniając `requires`, `topological_order` ani `phase_order`.\n',
    'intro authority')

text = replace_once(
    text,
    'W tym kroku nie definiujemy jeszcze cutover/restart/failure/rollback, nie tworzymy macierzy testów, nie generujemy migracji Laravel, nie rozpoczynamy Stage 5 i nie zmieniamy UI/API. Cutover/restart/failure/rollback pozostaje wyłącznie DB-MIG-003.\n',
    'W tym kroku domykamy wyłącznie cutover/restart/failure/rollback. Nie tworzymy jeszcze macierzy testów DB-TST-001/002, nie synchronizujemy finalnych agregatów DB-FINAL-001, nie generujemy migracji Laravel, nie rozpoczynamy Stage 5 i nie zmieniamy UI/API.\n',
    'step boundary')

text = replace_once(
    text,
    'Po DB4_10 obowiązuje 71 wcześniej zamkniętych blockerów Stage 4. DB-MIG-001 i DB-MIG-002 są kolejnymi dwoma zamkniętymi blockerami; żaden wcześniejszy kontrakt nie został otwarty ponownie. Zamrożone pozostają:\n',
    'Po DB4_10 obowiązuje 71 wcześniej zamkniętych blockerów Stage 4. DB-MIG-001, DB-MIG-002 i DB-MIG-003 są kolejnymi trzema zamkniętymi blockerami; żaden wcześniejszy kontrakt nie został otwarty ponownie. Zamrożone pozostają:\n',
    'resolved count intro')

text = replace_once(text, '## 2. Wynik po DB-MIG-002\n\nWynik po DB-MIG-002: **0 P0, 4 P1 OPEN**.\n', '## 2. Wynik po DB-MIG-003\n\nWynik po DB-MIG-003: **0 P0, 3 P1 OPEN**.\n', 'result header')
text = replace_once(text, '`DB-MIG-003 → DB-TST-001 → DB-TST-002 → DB-FINAL-001`', '`DB-TST-001 → DB-TST-002 → DB-FINAL-001`', 'remaining order')

text = replace_once(
    text,
    'Dokładny wybór operacyjny `CONCURRENTLY` pozostaje wdrożeniem i częścią cutover contract DB-MIG-003, a nie decyzją DB-MIG-002.\n',
    'Dokładny wybór operacyjny `CONCURRENTLY` pozostaje decyzją implementacyjną. DB-MIG-003 nie wymusza jego użycia, ale zamyka sposób obsługi przerwanego lub częściowego nontransactional/concurrent index build: exact inspection i `manual_review`, bez blind drop/recreate.\n',
    'concurrently clarification')

text = replace_once(
    text,
    'DB-MIG-002 nie definiuje entry/exit/abort conditions cutover, restart safety, failure handling ani rollback strategy. Wszystkie te decyzje pozostają jawnie odroczone do DB-MIG-003. Nie definiuje również konkretnych operacyjnych wartości produkcyjnych ani nie generuje fizycznych migracji Laravel.\n',
    'DB-MIG-002 sam nie definiuje entry/exit/abort conditions cutover, restart safety, failure handling ani rollback strategy. Te decyzje zostały domknięte dopiero przez DB-MIG-003 opisany poniżej. DB-MIG-002 nie definiuje również konkretnych operacyjnych wartości produkcyjnych ani nie generuje fizycznych migracji Laravel.\n',
    'DB-MIG-002 boundary clarification')

sec5_start = text.index('## 5. DB-MIG-003 — cutover, restart, failure i rollback safety\n')
sec6_start = text.index('## 6. DB-TST-001 — machine-readable invariant test matrix\n', sec5_start)
text = text[:sec5_start] + payload + text[sec6_start:]

text = replace_once(
    text,
    'Stage 4 zamknął przed DB4_11 71 blockerów; DB-MIG-001 i DB-MIG-002 są już kolejnymi PASS, ale nadal nie istnieje finalna machine-readable relacja `resolved blocker -> final test IDs` dla całego Etapu 4.\n',
    'Stage 4 zamknął przed DB4_11 71 blockerów; DB-MIG-001, DB-MIG-002 i DB-MIG-003 są już kolejnymi PASS, ale nadal nie istnieje finalna machine-readable relacja `resolved blocker -> final test IDs` dla całego Etapu 4.\n',
    'test coverage intro')

pres_start = text.index('## 10. Preservation gate\n')
next_start = text.index('## 11. Następny pojedynczy krok\n', pres_start)
preservation = '''## 10. Preservation gate

DB-MIG-003 zachowuje:
- DB4_1–DB4_10 bez zmian,
- 71 wcześniejszych blockerów bez reopen oraz DB-MIG-001, DB-MIG-002 i DB-MIG-003 jako kolejne PASS,
- executable DAG i `topological_order` DB-MIG-001 bez zmian,
- siedmiofazowy `migration_phase_composition` DB-MIG-002 bez zmian,
- `core-schema.yml` bez zmian — blob `39958721c99550cfc27c3774e8dfa1af0d0637e6`,
- `docs/87...` bez zmian — blob `6c090b082604b6b42c283667fcf08e9033a5b523`,
- wszystkie bounded-context specs jako read-only,
- DB-TST-001, DB-TST-002 i DB-FINAL-001 jako nierozwiązane w tym kroku,
- brak stabilnych `test_id` lub test matrix w DB-MIG-003,
- brak wymyślonych produkcyjnych RPO/RTO lub retention duration,
- brak migracji Laravel,
- brak Stage 5,
- brak UI/feature implementation.

'''
text = text[:pres_start] + preservation + text[next_start:]

next_start = text.index('## 11. Następny pojedynczy krok\n')
next_section = '''## 11. Następny pojedynczy krok

Po domknięciu machine + narrative + central gate DB-MIG-003 następny krok może dotyczyć wyłącznie:

**`DB-TST-001 — machine_readable_invariant_test_matrix`**

I dopiero po kolejnym jawnym poleceniu użytkownika.

`core-schema.yml` i `docs/87...` pozostają zamrożone do DB-FINAL-001. DB-TST-002 i DB-FINAL-001 pozostają OPEN.

**STOP przed fixerem DB-TST-001.**
'''
text = text[:next_start] + next_section

# Machine/narrative semantic preservation checks.
assert 'status: PASS_DB_MIG_003' in machine
assert 'DAG_node_count_at_contract_close: 170' in machine
assert 'values: [restart_safe, manual_review]' in machine
assert 'blind_retry: forbidden' in machine
assert 'allowed_modes:\n      - application_rollback\n      - schema_forward_fix\n      - explicit_safe_down' in machine
assert 'destructive_contract_requires_successful_restore_evidence: true' in machine
assert 'PASS_does_not_mean_DB_TST_001_or_DB_TST_002_are_closed: true' in machine
assert 'exact_RPO_RTO_values: external_production_business_policy_not_set_in_DB_MIG_003' in machine

assert '**Aktualny krok:** `DB-MIG-003`' in text
assert '**Status:** `FAIL_WITH_3_P1_BLOCKERS / 0 P0 / 3 P1 OPEN`' in text
assert '## 5. DB-MIG-003 — cutover, restart, failure i rollback safety\n\n**Status: PASS / P1 RESOLVED**' in text
assert 'wszystkie 170 node\'ów DAG' in text
assert '**Status: OPEN / P1**' in text and text.count('**Status: OPEN / P1**') == 3
assert 'DB-MIG-003, DB-TST-001, DB-TST-002 i DB-FINAL-001 jako nierozwiązane' not in text
assert '**STOP przed fixerem DB-TST-001.**' in text
assert 'DB-TST-001 → DB-TST-002 → DB-FINAL-001' in text
assert text != original
p.write_text(text)
print('NARRATIVE_ASSERTIONS_PASS')
