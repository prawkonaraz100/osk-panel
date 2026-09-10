from pathlib import Path
p=Path('docs/116-stage-4-final-migration-order-invariant-matrix-audit.md')
s=p.read_text()
def rep(a,b):
 global s
 n=s.count(a)
 if n!=1: raise SystemExit(f'expected 1 match got {n}: {a[:80]!r}')
 s=s.replace(a,b,1)
rep('**Aktualny krok:** `DB-MIG-001`\n**Status:** `FAIL_WITH_5_P1_BLOCKERS / 0 P0 / 5 P1 OPEN`','**Aktualny krok:** `DB-MIG-002`\n**Status:** `FAIL_WITH_4_P1_BLOCKERS / 0 P0 / 4 P1 OPEN`')
rep('DB-MIG-001 jest pierwszym fixerem DB4_11. Zamykamy wyłącznie wykonawczy graf zależności i kanoniczną kolejność topologiczną obiektów migracyjnych. Nie przypisujemy jeszcze faz `expand/preflight/write_fence/backfill/reconcile/validate/contract`, nie definiujemy cutover/rollback, nie tworzymy macierzy testów, nie generujemy migracji Laravel, nie rozpoczynamy Stage 5 i nie zmieniamy UI/API.','DB-MIG-001 pozostaje zamkniętym authority dla wykonawczego grafu zależności i kanonicznej kolejności topologicznej. DB-MIG-002 zamyka wyłącznie drugą warstwę: deterministyczne przypisanie node’ów DAG do faz `expand/preflight/write_fence/backfill/reconcile/validate/contract` oraz poprawną kolejność aktywacji PostgreSQL guards. Nie definiujemy jeszcze cutover/restart/rollback, nie tworzymy macierzy testów, nie generujemy migracji Laravel, nie rozpoczynamy Stage 5 i nie zmieniamy UI/API.')
rep('Po DB4_10 obowiązuje 71 wcześniej zamkniętych blockerów Stage 4; DB-MIG-001 staje się 72. zamkniętym blockerem. Żaden wcześniejszy kontrakt nie został otwarty ponownie. Zamrożone pozostają:','Po DB4_10 obowiązuje 71 wcześniej zamkniętych blockerów Stage 4; DB-MIG-001 jest 72., a DB-MIG-002 staje się 73. zamkniętym blockerem. Żaden wcześniejszy kontrakt nie został otwarty ponownie. Zamrożone pozostają:')
rep('Wynik po DB-MIG-001: **0 P0, 5 P1 OPEN**.','Wynik po DB-MIG-002: **0 P0, 4 P1 OPEN**.')
rep('`DB-MIG-002 → DB-MIG-003 → DB-TST-001 → DB-TST-002 → DB-FINAL-001`','`DB-MIG-003 → DB-TST-001 → DB-TST-002 → DB-FINAL-001`')
rep('Istotna granica: node\'y nie mają jeszcze `phase` ani `migration_phase`. Składanie ich do `expand/preflight/write_fence/backfill/reconcile/validate/contract` pozostaje wyłącznie DB-MIG-002. Cutover/restart/rollback pozostaje DB-MIG-003, a machine-readable test matrix DB-TST-001/002.','DAG DB-MIG-001 pozostaje niezmienionym dependency authority. DB-MIG-002 nie przestawia `topological_order`; nakłada na istniejące node’y niezależną, maszynowo walidowaną ścieżkę faz. Cutover/restart/rollback nadal pozostaje DB-MIG-003, a machine-readable test matrix DB-TST-001/002.')
old='''## 4. DB-MIG-002 — expand / write fence / backfill / validate / contract

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
'''
new='''## 4. DB-MIG-002 — expand / write fence / backfill / validate / contract

**Status: PASS / P1 RESOLVED**

Machine authority zawiera teraz `migration_phase_composition` z jedną kanoniczną kolejnością siedmiu faz:

`expand → preflight → write_fence → backfill → reconcile → validate → contract`.

Każdy node istniejącego DAG DB-MIG-001 musi rozwiązać się do niepustej ścieżki faz przez regułę `exact override → node-type default`; nie wolno wyprowadzać faz ad hoc w migracjach Laravel. Machine gate sprawdza, że wszystkie typy DAG są pokryte, override wskazuje istniejący node, użyte fazy należą do katalogu i występują w poprawnej kolejności. `topological_order` z DB-MIG-001 pozostaje nadrzędnym dependency order i nie może zostać przepisany przez phase binding.

Semantyka PostgreSQL jest jawna. `FOREIGN KEY` i kwalifikujące się `CHECK` mogą wejść jako `NOT VALID` w `write_fence`, gdy trzeba chronić nowe write’y przed zakończeniem migracji starej populacji; `VALIDATE CONSTRAINT` następuje dopiero po exact backfill/reconciliation i zerze unresolved wymaganych przypadków. Nie składamy fałszywej obietnicy dla mechanizmów, które PostgreSQL tego nie wspiera: `UNIQUE`/candidate keys oraz exclusion constraints **nie** dostają fikcyjnego `NOT VALID`. Dla nich najpierw wykonuje się preflight konfliktów, potem wymaga zera unresolved, a dopiero następnie instaluje unique/exclusion boundary jako write fence. `btree_gist` pozostaje wcześniejszym dependency z DB-MIG-001.

Trigger/final-state guards wchodzą w `write_fence`, aby nowe zapisy nie tworzyły kolejnych wyjątków; spójność legacy jest osobno dowodzona przed/na `validate`. Projection nodes mają sekwencję `write_fence → exact backfill → reconcile → validate → contract`: najpierw nowy source/dedupe writer, potem tylko evidence-based history, a stary projection path można wycofać dopiero po walidacji.

Kontrakt grupuje legacy safety w sześć kohort: Identity/Tenant, Students/Training/Calendar, PKK, License/Internal Exam, Student Finance/Commerce oraz Audit/Events/Activity/Notifications. We wszystkich obowiązuje fail-closed: lokalny bounded-context contract decyduje, co jest wystarczającym dowodem; timestamp, nazwa, kwota, UUID albo samo `same tenant` nie tworzą lineage. DB4_9 nie może regrantować inventory, DB4_10 nie może zgadywać eventu/recipienta/read-state, a Calendar nie może automatycznie przesuwać ani wybierać zwycięzcy kolizji.

`validate` jest twardą bramką: wymagane unresolved P0/P1 migration/reconciliation cases muszą wynosić **0**. Niepowodzenie preflightu lub reconciliation oznacza STOP, nie force-pass. `contract` może deautoryzować stary path dopiero po walidacji i nie może niszczyć formalnej, finansowej ani audytowej historii.

DB-MIG-002 nie rozstrzyga operacyjnego cutoveru, restartu po częściowym failure ani rollback strategy — to pozostaje dokładnie DB-MIG-003. Nie powstał żaden plik migracji Laravel.
'''
rep(old,new)
rep('DB-MIG-001 zachowuje:\n- DB4_1–DB4_10 bez zmian,\n- 71 wcześniejszych blockerów bez reopen oraz DB-MIG-001 jako nowy PASS,','DB-MIG-002 zachowuje:\n- DB4_1–DB4_10 bez zmian,\n- 71 wcześniejszych blockerów bez reopen oraz DB-MIG-001 i DB-MIG-002 jako PASS,')
rep('Po domknięciu machine + narrative + central gate DB-MIG-001 następny krok może dotyczyć wyłącznie:\n\n**`DB-MIG-002 — expand_write_fence_backfill_validate_contract_phase_composition`**\n\nI dopiero po kolejnym jawnym poleceniu użytkownika.\n\nDB-MIG-003, DB-TST-001, DB-TST-002 i DB-FINAL-001 pozostają OPEN.\n\n**STOP przed fixerem DB-MIG-002.**','Po domknięciu machine + narrative + central gate DB-MIG-002 następny krok może dotyczyć wyłącznie:\n\n**`DB-MIG-003 — global_cutover_restart_failure_and_safe_rollback_gate`**\n\nI dopiero po kolejnym jawnym poleceniu użytkownika.\n\nDB-TST-001, DB-TST-002 i DB-FINAL-001 pozostają OPEN.\n\n**STOP przed fixerem DB-MIG-003.**')
p.write_text(s)
