# 112. Stage 4 — Internal Exams database audit

Data: 2026-09-07

**Etap:** `DB4_7_INTERNAL_EXAMS`  
**Aktualny krok:** `DIAGNOSIS_ONLY`  
**Status:** `FAIL_WITH_8_P1_BLOCKERS / 0 P0 / 8 P1 OPEN`

Machine-readable diagnoza: `specs/database/internal-exams.yml`.

---

## 1. Zasada pracy

DB4_7 rozpoczynamy zgodnie z tym samym kontraktem jakości, który zamknął DB4_2–DB4_6:

1. najpierw diagnoza całego bounded contextu,
2. w diagnozie nie naprawiamy żadnego blockera,
3. potem dokładnie jeden P0/P1 na krok,
4. po każdym fixerze machine self-audit, narrative audit i centralny gate,
5. `specs/database/core-schema.yml` oraz `docs/87-physical-database-schema.md` pozostają zamrożone do finalnego DB4_7 aggregate sync,
6. DB4_8+, Stage 5, migracje Laravel i UI są poza zakresem bieżącego kroku.

W repozytorium nie istniał wcześniej bounded-context DB contract dla Internal Exams. Po potwierdzeniu drzewa repo utworzono `specs/database/internal-exams.yml` jako dokument **diagnozy**, nie jako gotowy model fizyczny.

---

## 2. Źródła

### Własne decyzje produktowe i lifecycle

- `specs/design/internal-exam-lifecycle.yml`,
- `docs/34-own-internal-exam-lifecycle-policy.md`,
- `docs/adr/0004-internal-exam-consume-on-start.md`.

Najważniejsza zamknięta decyzja istniejąca przed DB4_7:

`create/reserve -> start/consume exactly once -> finish without second consume`.

Revoke/expire/cancel przed startem zwalnia niewykorzystaną rezerwację. Technical abort po starcie nie przywraca automatycznie puli; ewentualny zwrot jest osobnym audytowanym adjustmentem.

### Formalny kurs i requirements

- `docs/66-formal-student-record-and-theory-exemptions.md`,
- `docs/67-editable-training-requirements-and-theory-exemption.md`,
- `specs/database/students-courses-training.yml`.

Formalny egzamin jest **course-first**:

`organization -> student -> course_enrollment -> requirement profile -> required exam part -> internal_exam_attempt`.

Nie obsługujemy formalnego `ad_hoc_candidate` poza ewidencją kursanta i kursu.

### Reverse engineering / ekrany / dokumenty

- `docs/32-student-internal-exam-screen.md`,
- `docs/33-student-internal-exam-access-flow.md`,
- `docs/59-internal-exam-purchase-screen.md`,
- `docs/60-internal-exam-management-panel.md`,
- `docs/61-internal-exam-expanded-attempt-history.md`,
- `docs/62-internal-exam-answer-sheet-pdf.md`,
- `docs/63-internal-exam-result-details-screen.md`,
- `docs/64-internal-exam-question-review.md`,
- `docs/65-internal-exam-generation-access-flow.md`,
- `docs/68-internal-exam-edit-candidate-data.md`,
- `docs/69-internal-exam-filtering.md`,
- `docs/70-internal-exam-sorting.md`.

Potwierdzone capability obejmują m.in.:
- wielokrotne próby,
- historię i statystyki,
- remote link,
- start lokalny,
- przygotowanie domeny pod przypisane stanowisko,
- pulę darmową/opłaconą i korekty,
- tokenizowany frontend egzaminu/wyniku,
- review pytanie po pytaniu,
- PDF arkusza odpowiedzi,
- papierowy workflow podpisów.

### API / RBAC

- `specs/api/paths/internal-exams.yaml`,
- `specs/api/openapi-components-v1.yaml`,
- `specs/security/permissions.yml`.

Stage-3 API już rozdziela:
- Attempt,
- Access,
- Reservation/Inventory,
- start,
- submit,
- technical abort,
- result/questions,
- documents,
- stations.

RBAC rozdziela m.in. `exams.generate`, `exams.access.send`, `exams.start.local`, `exams.results.view`, `exams.documents.download`, `exams.inventory.adjust`.

---

## 3. Potwierdzona architektura biznesowa, której nie wolno uprościć

Egzamin nie może być:
- booleanem na Student,
- jednym licznikiem `exam_count--`,
- jedną tabelą mieszającą dostęp, próbę, wynik i rozliczenie.

Potwierdzony model rozdziela co najmniej:

`inventory -> reservation -> access -> attempt -> question/result/document evidence`.

Jednocześnie formalny attempt należy do dokładnego `CourseEnrollment`, a jego dopuszczalna część (`theory|practical`) wynika z aktualnego rule engine w chwili utworzenia.

Po ukończeniu historia próby musi pozostać reprodukowalna nawet po zmianie:
- danych kursanta,
- PKK,
- wymagań kursu,
- bazy pytań,
- mediów,
- punktacji,
- szablonu dokumentu.

---

# 4. DB-EXAM-001 — same-tenant i exact-target integrity

**Severity: P1 — OPEN.**

Aktualny aggregate posiada wiele równoległych identyfikatorów:
- Organization,
- Student,
- CourseEnrollment,
- Attempt,
- InventoryEntry,
- Reservation,
- Access,
- Station,
- StationSession,
- Result,
- Question,
- Document.

Nie ma jeszcze pełnego dowodu DB, że wszystkie relacje wskazują ten sam tenant oraz dokładnie ten sam formalny target.

Przykładowe nielegalne stany, które muszą być fizycznie niemożliwe:
- Attempt Course A + Student B w tym samym OSK,
- Reservation Inventory z innego OSK,
- Access z innego OSK niż Attempt,
- StationSession z `attempt_id=A`, ale Access należy do B,
- Result/Document podpięty do Attempt innego tenantu.

**Ryzyko:** cross-tenant disclosure, błędna formalna historia i rozliczenie nie tej próby.

---

# 5. DB-EXAM-002 — formal Course/Requirement/Capability basis

**Severity: P1 — OPEN.**

API już wymaga:

`exam_part_must_be_required_by_rule_engine`.

DB4_4 posiada versioned `TrainingRequirementProfile` i `requirements_revision`, natomiast obecny Attempt ma tylko ogólny `requirement_basis`.

To nie dowodzi jeszcze:
- który dokładnie profile/revision dopuścił część egzaminu,
- że Student Attemptu jest Studentem tego Course,
- że category snapshot odpowiada Course,
- że language jest obsługiwany przez właściwą capability,
- że teoria nie została wygenerowana dla Course, w którym rule engine wyłączył teorię.

Późniejsza zmiana requirements nie może cicho przepisać historii już utworzonej próby.

**Ryzyko:** formalnie niewymagana/niedopuszczalna część egzaminu oraz brak reprodukowalnej podstawy historycznej.

---

# 6. DB-EXAM-003 — inventory/reservation authority i consume-on-start exactly-once

**Severity: P1 — OPEN.**

W istniejących dokumentach występują dwa obrazy:
- `exam credit ledger` jako source of truth,
- konkretne jednostkowe `internal_exam_inventory_entries` z mutable status.

To może być poprawny model łączony, ale obecnie canonical authority nie jest jednoznacznie zamknięte.

Musimy przed implementacją rozstrzygnąć, jak fizycznie udowodnić:
- dostępna jednostka -> dokładnie jedna rezerwacja,
- pre-start release -> dokładnie jeden zwrot,
- start -> dokładnie jedna konsumpcja,
- submit -> zero kolejnej konsumpcji,
- technical abort po starcie -> brak auto-restore,
- manual/elevated adjustment -> osobny audytowany efekt,
- free/paid/adjustment provenance zostaje zachowane,
- licznik widoczny w UI jest projekcją, nie drugim source of truth.

DB4_7 nie rozstrzyga payment/order grant — to pozostaje DB4_9.

**Ryzyko:** double consume, double release, orphan reservation albo ręcznie rozjechany licznik.

---

# 7. DB-EXAM-004 — Attempt/Access lifecycle i concurrency

**Severity: P1 — OPEN.**

Design posiada listy stanów Attempt i Access, a API ma:
- PATCH before start + `If-Match`,
- Access create/send/revoke/start,
- submit,
- technical abort,
- invalidate.

Current physical blueprint nie ma jeszcze kompletnej state-field matrix i wspólnej concurrency contract dla wyścigów:
- revoke vs start,
- expire vs start,
- start vs start,
- submit vs technical abort,
- submit vs invalidate,
- dwa materialne PATCH-e.

Nie jest również fizycznie zamknięta relacja między:
- Attempt status,
- Access status,
- Reservation status,
- lifecycle timestamps.

**Ryzyko:** sprzeczne terminalne stany, podwójny start lub submit oraz lost update.

---

# 8. DB-EXAM-005 — token security i rozdzielenie start/result privilege

**Severity: P1 — OPEN.**

`examAccessToken` jest alternatywnym principalem dla niektórych endpointów.

Jednocześnie istnieją co najmniej dwa różne cele security:
1. uruchomienie/obsługa aktywnej próby,
2. późniejszy odczyt zakończonego wyniku i review.

Obecny Access posiada `token_hash`, ale nie dowodzi jeszcze:
- purpose/scope tokenu,
- exact Attempt binding,
- lifecycle privilege po zakończeniu,
- replay boundary,
- expiry/revoke/rotation,
- czy jeden token może bezpiecznie pełnić oba cele.

Raw token nie może być przechowywany recoverably, a `attempt_id` nie może być sekretem autoryzacyjnym.

**Ryzyko:** start token zachowuje zbyt szerokie prawa po egzaminie, result token może uruchomić/mutować próbę albo dojść do cross-attempt disclosure.

---

# 9. DB-EXAM-006 — StationSession concurrency, device binding i failover

**Severity: P1 — OPEN.**

Własna polityka wymaga:
- wiele stanowisk na OSK,
- maksymalnie jedna aktywna próba per stanowisko,
- brak globalnego limitu jednego kursanta dla całego OSK,
- failover na inne stanowisko po awarii bez ponownej konsumpcji egzaminu.

Current partial unique na aktywny StationSession jest dobrym początkiem, ale nie zamyka jeszcze:
- same-tenant Station↔Access↔Attempt,
- exact Attempt↔Access pairing,
- station identity/device binding,
- start na disabled/offline station,
- atomowego transferu old session -> new station,
- konfliktów dwóch równoległych failover/start requestów.

**Ryzyko:** jedna próba na dwóch stanowiskach, dwa egzaminy na jednym stanowisku albo drugi credit zużyty przy failover.

---

# 10. DB-EXAM-007 — immutable exam evidence

**Severity: P1 — OPEN.**

Rzeczywisty PDF, ekran wyniku i question review potwierdzają, że zakończony Attempt musi odtworzyć dokładnie ten sam historyczny egzamin.

Current `question_snapshot` i `result_snapshot` są zbyt ogólne, aby blueprint udowodnił:
- exam-definition version,
- question/content revision,
- media revision,
- odpowiedzi i correct-answer snapshot,
- max points,
- awarded points,
- pass-threshold/scoring snapshot,
- spójność sumy z Result,
- document template version,
- content hash/artefakt historycznego PDF,
- politykę jawnej correction/invalidation zamiast nadpisania historii.

Nie wolno przeliczać starej próby aktualną bazą pytań.

**Ryzyko:** wynik lub dokument historyczny zmienia się po aktualizacji treści, a formalnego przebiegu nie da się odtworzyć.

---

# 11. DB-EXAM-008 — deterministic management/history/statistics projection

**Severity: P1 — OPEN.**

Panel wymaga:
- najnowszej próby,
- liczby prób,
- zdawalności,
- passed/failed counts,
- filtrów statusu,
- sortowania,
- `hide finished`.

Evidence pokazuje, że dwie próby mogą mieć tę samą wyświetlaną minutę. Timestamp nie może więc być samodzielnym `latest` resolverem.

Dodatkowo statusy UI takie jak:
- `Brak przypisanego`,
- `Nie przeprowadzony`,
- `Niezaliczony`,
- `Zaliczony`

są projekcjami i nie powinny tworzyć drugiego mutable status authority obok Attempt lifecycle.

Przed implementacją trzeba zdefiniować deterministyczny ordering oraz denominator/statistics inclusion dla `created`, `in_progress`, `technical_abort`, `invalidated`, `passed`, `failed`.

**Ryzyko:** niestabilny latest row, różne wyniki pass-rate/count/filter dla tej samej historii.

---

## 12. Granice z późniejszymi slice'ami

### DB4_8 PKK

DB4_7 przechowuje i sprawdza formalny Course/PKK/requirement context potrzebny Attemptowi, ale nie projektuje provider fetch/update/return/retry/reconciliation.

### DB4_9 Commerce

DB4_7 rozstrzyga lifecycle już istniejącej jednostki egzaminu i adjustment. Nie projektuje:
- płatności,
- order lifecycle,
- VAT,
- webhooków,
- momentu komercyjnego grantowania paid inventory.

### DB4_10 Audit/Outbox

DB4_7 wymaga audytu lifecycle, adjustmentów, invalidation i security events, ale finalny fizyczny shape infrastruktury audit/outbox pozostaje DB4_10.

---

## 13. Self-audit diagnozy

Wynik: **PASS_DIAGNOSIS_COMPLETE**.

Potwierdzono:
- 8 P1 zostało wyłącznie zdiagnozowanych,
- żaden blocker nie został naprawiony,
- aggregate `core-schema.yml` nie został zmieniony,
- aggregate `docs/87` nie został zmieniony,
- course-first model pozostaje,
- nie wprowadzono formalnego ad-hoc candidate,
- theory exemption/rule engine pozostaje,
- remote link i local workstation pozostają,
- assigned exam station pozostaje przygotowanym capability,
- consume-on-start ADR pozostaje nadrzędny,
- release przed startem pozostaje,
- technical abort po starcie nie zwraca automatycznie creditu,
- wiele prób i historia pozostają,
- tokenizowany frontend pozostaje,
- result review i answer-sheet PDF pozostają,
- papierowe miejsca na podpisy pozostają,
- nie wprowadzono globalnego limitu `1 student na całe OSK`,
- DB4_8, DB4_9 i DB4_10 nie zostały rozwiązane przedwcześnie,
- brak migracji Laravel,
- Stage 5 i UI nierozpoczęte.

---

## 14. Wynik bramki diagnostycznej

- P0: **0**,
- P1: **8**,
- resolved: **0/8**,
- open P0/P1: **8**,
- DB4_7: **FAIL_WITH_8_P1_BLOCKERS**,
- DB4_7 final aggregate sync: **BLOCKED**,
- DB4_8: **BLOCKED**,
- Stage 5: **BLOCKED**,
- Laravel migrations: **BLOCKED**,
- UI/feature implementation: **BLOCKED**.

Kolejność fixerów:
1. `DB-EXAM-001` — same-tenant + exact-target integrity,
2. `DB-EXAM-002` — formal Course/Requirement/Capability basis,
3. `DB-EXAM-003` — inventory/reservation + consume-on-start exactly-once,
4. `DB-EXAM-004` — Attempt/Access lifecycle + concurrency,
5. `DB-EXAM-005` — token security,
6. `DB-EXAM-006` — StationSession/failover,
7. `DB-EXAM-007` — immutable exam evidence,
8. `DB-EXAM-008` — deterministic management/statistics projection.

Po centralnym gate następny dozwolony krok to **wyłącznie DB-EXAM-001**.

**STOP przed DB-EXAM-001.**
