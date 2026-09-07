# 112. Stage 4 — Internal Exams database audit

Data: 2026-09-07

**Etap:** `DB4_7_INTERNAL_EXAMS`  
**Aktualny krok:** `DB_EXAM_005_EXAM_ACCESS_TOKEN_AND_FINISHED_RESULT_TOKEN_SECURITY`  
**Status:** `FAIL_WITH_3_P1_BLOCKERS / 0 P0 / 3 P1 OPEN`

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

---

## 15. DB-EXAM-001 — wynik fixera: PASS

DB-EXAM-001 zamyka wyłącznie fizyczną integralność tenantową i exact-target. Nie definiuje lifecycle Attempt/Access/Reservation, tokenów, zużycia inventory, scoringu, failover ani projekcji panelu.

### 15.1 Exact Course + Student

Formalny `internal_exam_attempt` nadal zawiera wymagane `organization_id`, `student_id` i `course_enrollment_id`, ale samo sprawdzenie dwóch osobnych same-tenant FK nie wystarcza. Potrzebny jest candidate key:

`course_enrollments(organization_id,id,student_id)`.

Attempt ma composite FK:

`(organization_id,course_enrollment_id,student_id)`
`-> course_enrollments(organization_id,id,student_id)`.

Dzięki temu niemożliwy staje się przypadek „Course kursanta A + Student B” nawet wtedy, gdy obie osoby są w tym samym OSK. Zachowujemy także bezpośredni same-tenant FK Attempt -> Student.

### 15.2 Tenant keys dla całego graphu Internal Exams

`organization_id NOT NULL` jest wymagany na tenant-owned tabelach Internal Exams, w tym na dwóch tabelach, które w dotychczasowym agregacie nie miały własnego tenant key:
- `internal_exam_attempt_questions`,
- `internal_exam_results`.

Dla tych tabel tenant można w migracji deterministycznie backfillować wyłącznie z obowiązkowego parent Attempt. Nie jest to heurystyka ani reassignment.

Wymagane candidate keys `(organization_id,id)` obejmują co najmniej:
- InventoryEntry,
- Attempt,
- Reservation,
- Access,
- ExamStation,
- StationSession.

### 15.3 Reservation i Access

Reservation ma composite same-tenant FK zarówno do dokładnej InventoryEntry, jak i Attempt.

Access ma composite same-tenant FK do Attempt. Jeśli `station_id` jest non-NULL, wskazana Station musi należeć do tego samego Organization. Czy Station ma być wymagana dla określonych `mode` pozostaje DB-EXAM-004/006.

### 15.4 StationSession exact Access + Attempt

Sama para osobnych FK do Access i Attempt nie wystarcza, bo można byłoby teoretycznie stworzyć:

`Session.attempt_id = A`, `Session.access_id = access_of_B`.

Dlatego Access posiada candidate key:

`(organization_id,id,internal_exam_attempt_id)`.

StationSession wskazuje go przez:

`(organization_id,internal_exam_access_id,internal_exam_attempt_id)`.

Dodatkowo StationSession ma same-tenant FK do ExamStation.

Transfer reference jest nullable, ale gdy istnieje, musi wskazywać StationSession tego samego tenantu i tego samego Attempt przez candidate key:

`internal_exam_station_sessions(organization_id,id,internal_exam_attempt_id)`.

To nie definiuje jeszcze dozwolonego failover transition — jedynie uniemożliwia transfer do innej próby.

### 15.5 Questions, Result i Documents

AttemptQuestion dostaje `organization_id` i composite FK do exact Attempt. Unique ordinal ma scope `(organization_id,internal_exam_attempt_id,ordinal)`.

Result również dostaje `organization_id`, composite FK do Attempt i nadal dokładnie jeden Result per Attempt przez unique `(organization_id,internal_exam_attempt_id)`.

Document wskazuje same-tenant Attempt oraz same-tenant FileAsset przez `(organization_id,asset_id) -> file_assets(organization_id,id)`.

Purpose/readiness assetu, wersja dokumentu, snapshot hash i immutability pozostają DB-EXAM-007.

### 15.6 Globalne relacje

Nie tworzymy sztucznych tenant composite FK dla:
- `users`,
- `driving_categories`,
- `languages`.

Są to globalne identity/dictionaries i wcześniejsza architektura pozostaje bez zmian.

### 15.7 Commerce boundary

`internal_exam_inventory_entries.source_order_item_id` nie dostaje teraz pozornego same-tenant constraintu. Poprawny tenant boundary `OrderItem` zależy od DB4_9, ponieważ obecny commerce model nie ma jeszcze finalnego organization candidate key dla tej relacji.

DB-EXAM-001 nie projektuje więc payment/order lifecycle ani momentu grantowania paid inventory.

### 15.8 Delete policy

Formalne relacje Internal Exams używają `ON UPDATE RESTRICT / ON DELETE RESTRICT`. Normalny lifecycle nie może hard-delete'ować formalnej historii przez cascade tylko dlatego, że zmienił się Student, Course, Attempt czy dokument.

### 15.9 Migration safety

Przed włączeniem constraintów migracja musi sprawdzić:
- exact Attempt Course+Student,
- tenant parentów Reservation/Access/StationSession,
- exact StationSession Access+Attempt,
- transfer same Attempt,
- Document Asset same tenant.

Dozwolony jest deterministyczny backfill `organization_id` Question/Result z ich obowiązkowego Attempt.

Zabronione są:
- przepięcie Attempt do innego Studenta/Course,
- przepięcie StationSession do innego Access/Attempt/Station,
- reassignment Inventory Reservation,
- wyzerowanie cross-tenant Document Asset tylko po to, aby migracja przeszła,
- zgadywanie brakującego parent ID.

Niejednoznaczność = migration FAIL + reviewed remediation.

### 15.10 Self-audit fixera

Pierwszy machine write ujawnił dwie drobne regresje tekstu w historycznej diagnozie DB-EXAM-005/006 oraz zastąpił część checklisty diagnozy. Nie zostały zaakceptowane jako PASS. W kolejnych commitach przywrócono oryginalne brzmienie i pełną checklistę diagnostyczną.

Po korekcie:
- DB-EXAM-001 jest rozwiązany,
- DB-EXAM-002..008 pozostają otwarte i nie zostały semantycznie zmienione,
- `core-schema.yml` i `docs/87` pozostają zamrożone,
- DB4_8+, Stage 5, Laravel migrations i UI nie zostały rozpoczęte.

Aktualny stan DB4_7 po DB-EXAM-001:
- P0: **0**,
- P1 open: **7**,
- resolved: **1/8**,
- result: **FAIL_WITH_7_P1_BLOCKERS**.

Następny dozwolony krok po centralnym gate: **DB-EXAM-002 only**.

**STOP przed DB-EXAM-002.**

---

## 16. DB-EXAM-002 — wynik fixera: PASS

DB-EXAM-002 zamyka wyłącznie formalną i historycznie odtwarzalną podstawę utworzenia egzaminu. Nie projektuje inventory/reservation exactly-once, Attempt/Access lifecycle, tokenów, Station failover, scoringu ani statystyk.

### 16.1 Jedno źródło decyzji, czy dana część egzaminu jest wymagana

`InternalExamAttempt` nie staje się drugim rule engine.

Canonical authority pozostaje w DB4_4:
- bieżący formalny wynik wymagań: `training_requirement_profiles`,
- freshness epoch: `course_enrollments.requirements_revision`,
- użyty artefakt reguł: `training_requirement_rule_sets`,
- kategoria kursu: `course_enrollments.driving_category_id`.

Pole tekstowe typu `requirement_basis` nie może być źródłem prawdy o tym, czy teoria lub praktyka jest wymagana.

Request Stage-3 nadal podaje wyłącznie:
- `exam_part`,
- `language_code`.

Nie przyjmujemy `driving_category_id` jako niezależnej decyzji klienta. Kategoria Attemptu jest wyprowadzana z zablokowanego `CourseEnrollment`.

### 16.2 Exact RequirementProfile + revision

Attempt otrzymuje obowiązkowe pola:
- `training_requirement_profile_id`,
- `requirements_revision`,
- `internal_exam_capability_id`,
- `requirement_basis_snapshot`.

Wymagany candidate key profilu:

`training_requirement_profiles(organization_id,id,course_enrollment_id,requirements_revision)`.

Attempt wskazuje dokładny profil przez composite FK:

`(organization_id,training_requirement_profile_id,course_enrollment_id,requirements_revision)`
`-> training_requirement_profiles(organization_id,id,course_enrollment_id,requirements_revision)`.

Dzięki temu nie wystarcza wskazanie profilu istniejącego w tym samym OSK. Musi to być profil dokładnie tego Course i dokładnie tej revision.

Currentness profilu jest warunkiem **utworzenia** Attemptu, nie permanentnym warunkiem FK. Po późniejszej legalnej recalculation profil może zostać superseded, a stary Attempt nadal wskazuje historyczną decyzję, która obowiązywała przy jego utworzeniu.

### 16.3 Exact Course + Student + Category

DB-EXAM-001 zamknął Course+Student. DB-EXAM-002 wzmacnia tę granicę o kategorię:

`course_enrollments(organization_id,id,student_id,driving_category_id)`

jest candidate key dla Attemptu.

Attempt ma composite FK:

`(organization_id,course_enrollment_id,student_id,driving_category_id)`
`-> course_enrollments(organization_id,id,student_id,driving_category_id)`.

Nie można więc utworzyć egzaminu dla Course kat. C, ale zapisać na Attempt kat. B. Request nie może „nadpisać” kategorii wyprowadzonej z Course.

### 16.4 Która część egzaminu jest dozwolona

Mapowanie jest jawne:
- `exam_part=theory` wymaga `TrainingRequirementProfile.internal_theory_exam_required = true`,
- `exam_part=practical` wymaga `TrainingRequirementProfile.internal_practical_exam_required = true`.

Jeżeli rule engine zwalnia kursanta z teorii, nowy teoretyczny Attempt jest odrzucany.

Dotyczy to m.in. wcześniej potwierdzonych scenariuszy, w których teoria nie jest wymagana. Brak teorii nie oznacza braku Course lub Student — oznacza tylko brak prawa do wygenerowania niepotrzebnej części egzaminu.

`exam_part` oraz wskazanie profilu/revision są historycznym faktem i nie są normalnie przepinane po utworzeniu Attemptu.

### 16.5 `internal_exam_capabilities` — wersjonowana dostępność category + part + language

Sam globalny słownik `languages` nie oznacza, że każda wersja egzaminu istnieje w każdym języku.

Wprowadzamy globalny, nietenantowy katalog historycznych capability:

`internal_exam_capabilities`.

Każdy row reprezentuje dokładnie:
- `driving_category_id`,
- `exam_part`,
- `language_code`,
- `enabled_at`,
- opcjonalne `disabled_at`,
- opcjonalne `source_reference`.

Current capability to `disabled_at IS NULL`.

Partial unique:

`(driving_category_id,exam_part,language_code) WHERE disabled_at IS NULL`.

Attempt przechowuje `internal_exam_capability_id` i ma exact composite FK:

`(internal_exam_capability_id,driving_category_id,exam_part,language_code)`
`-> internal_exam_capabilities(id,driving_category_id,exam_part,language_code)`.

Dzięki temu pointer nie może wskazywać capability dla innej kategorii, części albo języka.

Wyłączenie capability zachowuje row historyczny. Nie hard-delete'ujemy go po użyciu. Ponowne włączenie tej samej kombinacji tworzy nowy historyczny row, zamiast „odmładzać” stary przez wyzerowanie `disabled_at`.

Endpoint `/internal-exam/capabilities` jest projekcją bieżących rows, grupowaną po kategorii i części. Lista języków zaobserwowana na jednym ekranie konkurenta nie staje się globalnym source of truth.

### 16.6 Immutable `requirement_basis_snapshot`

Attempt zapisuje minimalny, niezbędny snapshot podstawy utworzenia. Obejmuje co najmniej:
- ID profilu,
- requirements revision,
- rule-set version,
- rule-set content hash,
- exam part,
- nazwę i wartość użytej flagi `required=true`,
- exemption basis, jeśli występuje,
- kategorię ID/code,
- training type,
- capability ID,
- language code,
- `basis_evaluated_at`.

Snapshot jest dowodem historycznym, a nie drugim rule engine.

Nie kopiujemy do niego PESEL ani PKK. Dane tożsamości potrzebne do dokumentowania próby pozostają w osobnym `candidate_snapshot` i będą dalej chronione zgodnie z właściwymi boundary.

### 16.7 Transakcja utworzenia i races

Tworzenie Attemptu rozpoczyna wspólny lock prefix:

`CourseEnrollment FOR UPDATE`.

To celowo ten sam root, na którym DB-TRN-005 serializuje zmianę requirement contextu.

Po locku system musi ponownie sprawdzić:
1. istnieje dokładnie current TrainingRequirementProfile,
2. jego `requirements_revision` jest równe bieżącemu Course,
3. właściwa flaga `internal_*_exam_required` jest `true`,
4. kategoria pochodzi z Course,
5. istnieje dokładnie current capability dla `category + exam_part + language`.

Wybrany capability row jest blokowany `FOR SHARE`; jego retirement wymaga konfliktującego update locku.

Daje to deterministyczne wyniki race:
- Attempt create wygrał przed requirement recalculation -> zapisuje ówczesny profil; późniejsza zmiana go nie przepisuje,
- recalculation wygrała pierwsza -> Attempt musi użyć nowego profilu albo zostaje odrzucony,
- create wygrał przed capability retirement -> zapisuje capability ważne w tej chwili,
- retirement wygrał pierwszy -> nowy Attempt nie może commitować z już disabled capability.

DB-EXAM-003 może później rozszerzyć tę transakcję o Inventory/Reservation, ale nie może odwrócić ustalonego Course lock prefix.

### 16.8 Późniejsza zmiana wymagań lub capability

Zmiana requirementów po utworzeniu Attemptu nie:
- przepina profilu,
- zmienia requirements revision historycznego Attemptu,
- zmienia kategorii/języka,
- przelicza `requirement_basis_snapshot`,
- automatycznie usuwa ani invaliduje próby.

Analogicznie retirement capability nie przepisuje historycznych Attemptów.

Nowy Attempt nie może jednak korzystać z superseded RequirementProfile ani disabled capability.

Osobne pytanie: co zrobić z **już utworzonym, ale jeszcze nierozpoczętym** Attemptem, gdy jego basis później stanie się nieaktualny. Tego celowo nie rozstrzygamy tutaj — jest to lifecycle policy DB-EXAM-004. DB-EXAM-002 zakazuje jedynie cichego przepisywania historii.

### 16.9 Migration safety

Legacy `requirement_basis` jako zwykły tekst nie jest wystarczającym dowodem do automatycznego ustalenia historycznego profilu.

Migracja nie może:
- podpiąć bieżącego profilu tylko dlatego, że jest current,
- wybrać „najbliższego” profilu po `calculated_at`, UUID albo kolejności,
- ponownie uruchomić dzisiejszego rule engine i udawać, że wynik był historyczną decyzją,
- założyć, że dzisiejsze current capability było dostępne w chwili starego Attemptu,
- wywnioskować capability z globalnego słownika języków albo listy na jednym ekranie,
- wymyślić rule-set version/content hash,
- usunąć lub automatycznie invalidować nierozstrzygalnej próby.

Exact legacy mapping jest dopuszczalny tylko wtedy, gdy istnieją wiarygodne dane dowodzące konkretnego profilu i capability. W przeciwnym razie: migration FAIL + reviewed remediation.

### 16.10 Self-audit fixera

Pierwszy machine write DB-EXAM-002 poprawnie zamknął architekturę, ale preservation gate wykrył:
- zmianę jednej historycznej linii DB-EXAM-001 z `deferred_to_DB_EXAM_002` na `closed_by_DB_EXAM_002`,
- zastąpienie części szczegółowej checklisty DB-EXAM-001 nowymi checkami,
- w korekcie pojawiła się jeszcze jedna czysto tekstowa regresja w otwartym DB-EXAM-003 (`but_final...` -> `but final...`).

Żadna z tych wersji nie została zaakceptowana jako PASS. Historyczny DB-EXAM-001 i diagnoza DB-EXAM-003..008 zostały przywrócone przed zamknięciem machine gate.

Po korekcie:
- DB-EXAM-001 pozostaje PASS,
- DB-EXAM-002 jest PASS,
- DB-EXAM-003..008 pozostają OPEN,
- agregaty `core-schema.yml` i `docs/87` pozostają zamrożone,
- OpenAPI nie został zmieniony,
- DB4_8+, Stage 5, Laravel migrations i UI nie zostały rozpoczęte.

Aktualny stan DB4_7 po DB-EXAM-002:
- P0: **0**,
- P1 open: **6**,
- resolved: **2/8**,
- result: **FAIL_WITH_6_P1_BLOCKERS**.

Następny dozwolony krok po centralnym gate: **DB-EXAM-003 only**.

**STOP przed DB-EXAM-003.**

---

## 17. DB-EXAM-003 — wynik fixera: PASS

DB-EXAM-003 zamyka wyłącznie authority i exactly-once dla puli egzaminów, rezerwacji, konsumpcji przy starcie oraz audytowanych korekt. Nie zamyka jeszcze Attempt/Access lifecycle, tokenów, StationSession/failover, scoringu ani statystyk.

### 17.1 Ledger i jednostkowe Inventory nie są konkurencyjnymi źródłami prawdy

Zachowujemy oba modele, ale rozdzielamy ich role:

- `internal_exam_inventory_entries` — stabilna tożsamość jednej konkretnej sztuki egzaminu, jej provenance i guarded current operational state,
- `internal_exam_inventory_ledger_entries` — **canonical append-only accounting history**,
- `internal_exam_reservations` — historia przypisania jednej konkretnej sztuki do formalnego Attemptu.

Nie istnieje mutable `available_exam_count` jako authority. Stan jednostki oraz status Reservation są projekcjami operacyjnymi, które muszą być zgodne z ledgerem po commit. Finalna granica jest `DEFERRABLE INITIALLY DEFERRED` albo równoważnym transactional DB guardem.

### 17.2 Current state konkretnej sztuki

Jeden InventoryEntry reprezentuje dokładnie jedną jednostkę. Runtime current states:

`available | reserved | consumed | adjusted_out`.

`released` nie jest current state jednostki. Release jest zdarzeniem `reserved -> available` i pozostaje widoczny w ledgerze/Reservation history.

Analogicznie dawne niejednoznaczne `adjusted` rozbijamy na dwa pojęcia:
- `source_type=adjustment` — jednostka została przyznana przez korektę,
- `current_state=adjusted_out` — dostępna jednostka została jawnie wycofana przez korektę ujemną.

Consumed unit nie wraca normalnym UPDATE-em do `available`; `adjusted_out` również nie jest ponownie otwierany w miejscu.

### 17.3 Reservation — dokładnie jedna aktywna alokacja

Reservation ma katalog:

`reserved | released | consumed`.

Ma własne `version >= 1` oraz immutable linkage do Organization, InventoryEntry, Attempt i `reserved_at`.

State-field matrix:
- `reserved` -> `released_at=NULL`, `consumed_at=NULL`,
- `released` -> `released_at!=NULL`, `consumed_at=NULL`,
- `consumed` -> `consumed_at!=NULL`, `released_at=NULL`.

Partial unique wymusza maksymalnie:
- jedną aktywną Reservation na InventoryEntry,
- jedną aktywną Reservation na Attempt,
- jedną consumed Reservation na Attempt.

Historyczne released rows pozostają. Nie „od-rezerwujemy” starego row przez zmianę z powrotem na `reserved`; ewentualna przyszła ponowna alokacja tworzy nową Reservation. Dokładna lifecycle eligibility po release pozostaje DB-EXAM-004.

### 17.4 `internal_exam_inventory_ledger_entries`

Ledger jest append-only. Każdy event należy do jednej konkretnej jednostki i ma bezlukowy `event_sequence`, alokowany pod `InventoryEntry FOR UPDATE`.

Runtime event types:
- `unit_granted`,
- `unit_adjustment_granted`,
- `unit_reserved`,
- `unit_released`,
- `unit_consumed`,
- `unit_adjusted_out`.

Migration-only event to `migration_baseline`, którego runtime nie może wstawiać po cutover.

`available_delta` jest deterministyczny:
- grant `+1`,
- adjustment grant `+1`,
- reserve `-1`,
- release `+1`,
- consume `0`,
- adjusted-out `-1`.

Reservation-related ledger event musi wskazywać exact same-tenant Inventory + Reservation + Attempt. Business ledger rows są immutable.

### 17.5 Kiedy rezerwujemy sztukę

Stage-3 API jednoznacznie mówi, że:

`POST /course-enrollments/{courseEnrollmentId}/internal-exam-attempts`

**tworzy Attempt i rezerwuje dokładnie jedną jednostkę**.

To jest canonical flow core v1. Starsze sformułowanie „create access -> reserve” zostaje superseded dla formalnego Stage-3 flow; utworzenie lub resend Accessu nie może pobrać drugiej sztuki.

Transakcja zachowuje lock prefix DB-EXAM-002:

`CourseEnrollment FOR UPDATE -> ... -> InventoryEntry FOR UPDATE`.

Wybrana jednostka musi być same-tenant, `available` i mieć ledger final state `available`. `SKIP LOCKED` może służyć równoległym allocatorom. Nie hardcodujemy kolejności `free before paid` ani odwrotnej, bo evidence jej nie potwierdza.

Atomowo powstają:
- Attempt,
- Reservation `reserved`,
- ledger `unit_reserved`,
- Inventory current state `reserved`.

Brak jednostki rollbackuje cały Attempt. Idempotency retry nie przydziela kolejnej sztuki.

### 17.6 Consume-on-start exactly once

ADR-0004 pozostaje nadrzędny dla core v1: **consume-on-start**.

Po właściwych Attempt/Access locks przyszły DB-EXAM-004 musi zachować suffix:

`Reservation FOR UPDATE -> InventoryEntry FOR UPDATE`.

Start wymaga:
- Reservation `reserved`,
- Inventory `reserved`,
- exact Attempt binding,
- ostatniego ledger event `unit_reserved` dla tej samej Reservation.

Jedna transakcja:
- dopisuje `unit_consumed`,
- zmienia Reservation na `consumed`, zapisuje `consumed_at` i version +1,
- zmienia Inventory na `consumed`,
- przełącza Attempt do `in_progress` w tej samej outer transaction.

Dokładny Attempt/Access state matrix i `started_at` pozostają DB-EXAM-004, a station effects DB-EXAM-006.

Submit/finish nie konsumuje ponownie. Retry startu nie dopisuje drugiej konsumpcji. Technical abort po starcie nie dopisuje release.

### 17.7 Pre-start release i race ze startem

Revoke/expire/cancel przed startem może wywołać release, ale dokładne lifecycle źródło i eligibility zamknie DB-EXAM-004.

Inventory effect jest już określony:
- lock Reservation,
- lock InventoryEntry,
- wymagaj obu w stanie `reserved`,
- append `unit_released`,
- Reservation -> `released`, `released_at`, version +1,
- Inventory -> `available`.

Start i release używają tego samego lock suffixu. Pierwszy commit wygrywa:
- start pierwszy -> `consumed`; release jest później odrzucony,
- release pierwszy -> `released/available`; stary start jest odrzucony.

Dla jednej Reservation nie mogą commitować oba terminalne efekty.

### 17.8 Audytowane korekty inventory

`POST /internal-exam/inventory-adjustments` pozostaje elevated command z `exams.inventory.adjust` i Idempotency-Key.

Wprowadzamy append-only `internal_exam_inventory_adjustments` zawierający co najmniej:
- Organization,
- non-zero `delta`,
- reason,
- optional same-tenant related Attempt,
- real actor User,
- timestamp.

`delta > 0` tworzy dokładnie N nowych jednostek `source_type=adjustment`, każda `available`, każda z initial `unit_adjustment_granted`.

`delta < 0` może wycofać tylko aktualnie `available` jednostki. Dla każdej dopisuje `unit_adjusted_out`; Reservation/consumed unit nie może być zabrana. Jeżeli brakuje wolnych jednostek, cała korekta rollbackuje się.

Exactly-once dotyczy jednego idempotentnego commandu. Kolejna świadoma korekta jest nowym immutable Adjustment z własnym powodem i aktorem — nie ukrytym ponownym wykonaniem starej operacji.

### 17.9 Zwrot po awarii technicznej

Technical abort po starcie **nie przywraca starej sztuki**.

Jeżeli uprawniony operator uzna refund za zasadny, dodatni Adjustment tworzy nową kompensacyjną jednostkę. Oryginalna jednostka pozostaje `consumed`, jej Reservation pozostaje `consumed`, a ledger historii próby nie jest przepisywany.

To rozdziela dwa fakty:
- egzamin faktycznie wystartował i zużył konkretną sztukę,
- później OSK dostało audytowaną kompensację.

### 17.10 Projekcja puli

Panelowe:
- `Darmowe`,
- `Opłacone`,
- `Adjustment`,
- `Wszystkie`

są projekcjami canonical historii i provenance jednostek.

Available total można liczyć jako sumę `available_delta` ledgeru; guarded operational count `Inventory.current_state='available'` musi dawać ten sam wynik. Żaden licznik nie jest samodzielnym mutable source of truth.

Nie definiujemy jeszcze kolejności zużywania free vs paid, ponieważ istniejące źródła tego nie potwierdzają. To product allocation policy, nie invariant DB-EXAM-003.

### 17.11 Commerce boundary

DB-EXAM-003 nie projektuje:
- momentu grantowania paid inventory po payment,
- order/payment lifecycle,
- webhooków,
- exact same-tenant OrderItem boundary.

Pozostaje to DB4_9. DB-EXAM-003 definiuje jedynie, jak wygląda już istniejąca jednostka i jej `unit_granted` history.

### 17.12 Migration safety

Migracja może mapować legacy `available|reserved|consumed` tylko przy dowodliwej zgodności Reservation, parent relations i timestampów.

Legacy `released` może stać się current `available` wyłącznie, gdy istnieje exact released Reservation i brak późniejszego active/consumed efektu.

Legacy `adjusted` jest niejednoznaczne — nie zgadujemy, czy oznacza grant czy withdrawal.

Zabronione jest:
- rekonstruowanie ledger order z remisów timestampów lub UUID,
- fabrykowanie payment provenance,
- wymyślanie aktora/reason,
- automatyczne od-konsumowanie technicznie przerwanej próby.

Niejednoznaczność = migration FAIL + reviewed remediation.

### 17.13 Self-audit fixera

Machine preservation gate przeszedł bez regresji historycznych. Otwarta diagnoza DB-EXAM-004..008 została porównana z bazą `4103cfe6…` i pozostała bez zmian.

Sprawdzono dodatkowo:
- ledger + unit rows nie tworzą dwóch niezależnych authority,
- technical refund nie zmienia historii consumed unit,
- generic adjustment endpoint nie dostał sztucznego „one correction per Attempt”,
- free-vs-paid priority nie został wymyślony,
- DB4_9 payment/order grant nie został rozwiązany przedwcześnie,
- agregaty pozostają zamrożone.

Aktualny stan DB4_7 po DB-EXAM-003:
- P0: **0**,
- P1 open: **5**,
- resolved: **3/8**,
- result: **FAIL_WITH_5_P1_BLOCKERS**.

Następny dozwolony krok po centralnym gate: **DB-EXAM-004 only**.

**STOP przed DB-EXAM-004.**

---

## 18. DB-EXAM-004 — wynik fixera: PASS

DB-EXAM-004 zamyka wyłącznie Attempt/Access lifecycle, optimistic concurrency, wspólną kolejność blokad i final-state equivalence z Reservation. Nie projektuje purpose-scoped tokenów, device binding/failover stanowisk, scoringu/dokumentów ani projekcji panelu.

### 18.1 Attempt jest głównym concurrency root

Canonical lifecycle authority próby to `internal_exam_attempts.status`, a lokalny lifecycle dostępu to `internal_exam_accesses.status`. Status Accessu nie jest niezależnym światem — wszystkie komendy zmieniające stan konkretnej próby najpierw serializują się na:

`InternalExamAttempt FOR UPDATE`.

Attempt i Access mają `version >= 1`. Materialna zmiana zwiększa właściwą wersję dokładnie raz; semantic no-op nie zwiększa jej.

Normalny hard-delete Attemptu ani Accessu jest zabroniony.

### 18.2 Zamknięty katalog Attempt

Runtime Attempt states:

`created | in_progress | passed | failed | technical_abort | invalidated`.

Dozwolone przejścia:
- `created -> in_progress`,
- `in_progress -> passed|failed|technical_abort|invalidated`,
- `passed|failed|technical_abort -> invalidated`,
- `invalidated` jest terminalny.

Nie dodajemy sztucznego `cancelled` dla Attemptu przed startem. Pre-start cancel/revoke/expire dotyczy Accessu i zwolnienia rezerwacji, a sam Attempt pozostaje `created`, dzięki czemu można utworzyć nowy Access i — jeżeli wcześniejsza rezerwacja została zwolniona — nową rezerwację.

Terminalnego Attemptu nie otwieramy ponownie do `in_progress`; powtórka egzaminu tworzy nowy Attempt.

### 18.3 Attempt state-field matrix

`created` wymaga braku `started_at`, `finished_at`, `technical_aborted_at`, `invalidated_at`.

`in_progress` wymaga `started_at` i braku terminalnych timestampów.

`passed|failed` wymagają `started_at`, `finished_at`, `finished_at >= started_at`.

`technical_abort` wymaga `started_at`, `finished_at`, `technical_aborted_at`, przy czym abort timestamp jest momentem pierwszego zakończenia wykonania.

`invalidated` wymaga, aby próba była już rozpoczęta. Invalidation nie przepisuje wcześniejszego `finished_at` dla passed/failed/technical-abort; jeżeli źródłem było `in_progress`, `finished_at` zostaje ustawione razem z invalidation.

`started_at` jest ustawiane dokładnie raz.

### 18.4 Zamknięty katalog Access

Access states:

`draft | ready | delivered_or_assigned | opened | started | completed | cancelled | expired | revoked | technical_abort | invalidated`.

Pre-start nonterminal:
`draft|ready|delivered_or_assigned|opened`.

Startable:
`ready|delivered_or_assigned|opened`.

Po starcie aktywny Access to `started`.

Pre-start terminal:
`cancelled|expired|revoked`.

Post-start terminal:
`completed|technical_abort|invalidated`.

Terminalnego Accessu nie reaktywujemy w miejscu.

Każdy status ma odpowiadającą state-field matrix. Terminalne timestampy są write-once.

### 18.5 Mode + Station matrix bez wchodzenia w DB-EXAM-006

DB-EXAM-004 zamyka wyłącznie nullability/shape:
- `remote_link` -> `station_id IS NULL`, `expires_at NOT NULL`, może wejść w `opened`,
- `local_current_workstation` -> Station jest wymagana i rozwiązywana po stronie serwera, `expires_at IS NULL`,
- `assigned_exam_station` -> Station wymagana, `expires_at IS NULL`.

`mode` oraz `station_id` są immutable dla jednego Accessu. Jeżeli po pre-start terminal state trzeba zmienić sposób uruchomienia lub Station, tworzymy nowy Access.

To nie rozstrzyga jeszcze station identity, device binding, availability ani failover — te pozostają DB-EXAM-006.

### 18.6 Kardynalność Accessów

Partial unique wymusza maksymalnie jeden nonterminal Access per Attempt.

Dodatkowo jeden Attempt może mieć maksymalnie jeden Access, który kiedykolwiek wystartował (`started_at IS NOT NULL`). To chroni przed ponownym startem tej samej formalnej próby przez nowy Access.

Historyczne pre-start terminal Access rows mogą istnieć wielokrotnie.

Resend remote linku nie tworzy kolejnego Accessu ani kolejnej Reservation.

### 18.7 Versioned lifecycle history

Wprowadzamy append-only:
- `internal_exam_attempt_lifecycle_events`,
- `internal_exam_access_lifecycle_events`.

Każdy materialny version step ma dokładnie jeden lifecycle event. Normalny step to `version_after = version_before + 1`; creation może mieć `version_before=NULL`, `version_after=1`.

Po commit bieżący row version/status musi odpowiadać najnowszemu eventowi przez deferrable guard albo równoważną transactional DB boundary.

Lifecycle event nie przechowuje raw access tokenu ani kopii PESEL/PKK. Finalny shape audit/outbox nadal należy do DB4_10.

### 18.8 Candidate snapshot PATCH

`PATCH /internal-exam-attempts/{attemptId}` jest dozwolony tylko przed startem:
- Attempt `created`,
- `started_at IS NULL`,
- wymagany aktualny `If-Match`/expected version,
- dozwolona jest wyłącznie materialna zmiana `candidate_snapshot`.

Nie wolno przez ten PATCH przepinać:
- Organization,
- Student,
- Course,
- exam part,
- category,
- language,
- RequirementProfile/revision,
- capability,
- `requirement_basis_snapshot`.

Dwa PATCH-e z tym samym expected version: maksymalnie jeden commit.

### 18.9 Późniejsza zmiana requirements/capability

Historyczny Attempt zachowuje creation-time basis z DB-EXAM-002.

Sama późniejsza supersession RequirementProfile albo retirement capability:
- nie przepisuje Attemptu,
- nie uruchamia ponownie rule engine przy Access create/start,
- nie blokuje automatycznie istniejącego Attemptu tylko dlatego, że jego historyczny basis nie jest już current.

Jeżeli biznesowo próba ma zostać wycofana, służy do tego jawny lifecycle command, a nie cicha reinterpretacja historii.

### 18.10 Access create, replacement i re-reservation

`POST /internal-exam-attempts/{attemptId}/accesses` lockuje Attempt i wymaga `status=created` oraz braku nonterminal Accessu.

Jeżeli Attempt nadal ma aktywną Reservation `reserved`, nowy pierwszy Access używa tej samej rezerwacji bez nowego ledger effect.

Jeżeli wcześniejszy pre-start Access został terminalnie zakończony i jego Reservation zwolniona, nowy Access może powstać wyłącznie atomowo z **nową** InventoryEntry/Reservation alokowaną według DB-EXAM-003.

Stary released Reservation row nie jest otwierany ponownie. Brak inventory rollbackuje zarówno nowy Access, jak i próbę re-reservation.

Consumed Reservation oznacza, że nie wolno tworzyć replacement Accessu do ponownego startu tej samej próby.

### 18.11 Send/resend i remote open

Remote send wymaga mode `remote_link`, Attempt `created` i właściwego pre-start Access state.

Pierwsze wysłanie może przejść `ready -> delivered_or_assigned`. Resend z `delivered_or_assigned` lub `opened` nie cofa statusu i nie tworzy drugiego Accessu ani Reservation.

Dokładna rotacja/purpose tokenu pozostaje DB-EXAM-005; outbox delivery — DB4_10.

`opened` jest dozwolone wyłącznie dla remote linku i nie ma inventory effect.

### 18.12 Wspólna kolejność locków i start vs release

Canonical prefix dla stateful commands:

`Attempt FOR UPDATE -> Access FOR UPDATE`.

Jeżeli zmieniana jest Reservation/Inventory, zachowujemy suffix zamknięty w DB-EXAM-003:

`Reservation FOR UPDATE -> InventoryEntry FOR UPDATE`.

Pełna kolejność dla start/revoke/expire/cancel:

`Attempt -> Access -> Reservation -> InventoryEntry`.

Po lockach wszystkie preconditions są sprawdzane ponownie.

Start wymaga:
- Attempt `created`,
- Access w `ready|delivered_or_assigned|opened`,
- exact Reservation `reserved`,
- dla remote: `command_effective_at < expires_at`.

Na dokładnej granicy `effective_at == expires_at` start jest odrzucony, a expiry jest dozwolone.

Start atomowo:
- konsumuje inventory przez DB-EXAM-003,
- zmienia Attempt na `in_progress`,
- ustawia Attempt `started_at`,
- zmienia Access na `started`,
- ustawia ten sam moment `started_at`,
- zwiększa obie wersje raz,
- dopisuje oba lifecycle events.

Revoke/expire/cancel przed startem używa tego samego lock order i atomowo terminalizuje Access oraz zwalnia Reservation przez DB-EXAM-003. Attempt pozostaje `created`.

W race start vs revoke/expire tylko pierwszy prawidłowy stan może commitować.

### 18.13 Submit, technical abort i invalidation

Submit wymaga Attempt `in_progress`, exact Access `started` i consumed Reservation. Atomowo tworzy wynik/evidence (szczegóły consistency nadal DB-EXAM-007), przełącza Attempt na `passed|failed`, Access na `completed` i ustawia matching finish timestamp.

Submit **nie wykonuje żadnej drugiej konsumpcji inventory**.

Technical abort wymaga tych samych post-start podstaw, przełącza Attempt i Access na `technical_abort`, zapisuje reason/timestamp i nie przywraca consumed inventory.

Submit vs technical abort serializują się na Attempt row; tylko jeden może zostać pierwszym execution-terminal effect.

`exams.attempt.invalidate` jest jawną elevated lifecycle akcją. Invalidation:
- wymaga reason,
- wymaga expected Attempt version,
- jest dozwolone dopiero po starcie,
- nie usuwa ani nie przepisuje Result/Question/Document evidence,
- nie zwraca consumed inventory.

Jeżeli invalidate konkuruje z submit/abort na tej samej obserwowanej wersji, stale command odpada. Świadoma post-finish invalidation wymaga ponownego odczytu current version.

Stage-3 API nie ma jeszcze publicznego endpointu invalidation mimo istniejącego permission contract; gap zapisujemy do późniejszego API contract sync, bez wchodzenia teraz w Stage 5.

### 18.14 Final-state equivalence

Po commit cross-row guard wymaga m.in.:
- `created` -> zero consumed Reservation, zero ever-started Access, zero lub jedna active Reservation/Access,
- nonterminal pre-start Access -> dokładnie odpowiadająca active Reservation,
- `in_progress` -> dokładnie jedna consumed Reservation i jeden `started` Access,
- `passed|failed` -> dokładnie jedna consumed Reservation, jeden `completed` Access i dokładnie jeden Result,
- `technical_abort` -> consumed Reservation + matching technical-abort Access,
- `invalidated` -> consumed Reservation + dokładnie jeden ever-started Access w `invalidated`.

Pre-start terminal Access może pozostawić Attempt `created` bez aktywnej Reservation; historia released Reservation zostaje zachowana.

Bezpośredni ręczny UPDATE jednego statusu bez matching cross-row effects ma zostać odrzucony przy commit.

### 18.15 Migration safety

Legacy lifecycle można mapować tylko przy dokładnym dowodzie zgodności statusów, timestamps, Accessów i Reservation.

Zabronione jest:
- wybieranie „aktualnego” Accessu po timestamp/UUID,
- automatyczne wybieranie zwycięzcy spośród wielu nonterminal lub started Accessów,
- fabrykowanie brakującego start/finish timestampu dla terminalnej próby,
- przepisywanie revoked/expired Accessu z consumed Reservation tylko po to, by constraint przeszedł,
- rekonstruowanie pełnej transition history z niejednoznacznych timestampów.

Migration baseline może opisać wiarygodnie udowodniony current state bez wymyślania wcześniejszych eventów. Runtime nie może używać migration baseline po cutover.

Niejednoznaczność = migration FAIL + reviewed remediation.

### 18.16 Self-audit fixera

Machine write DB-EXAM-004 został wykonany wyłącznie w `specs/database/internal-exams.yml`. Preservation gate wykrył trzy czysto tekstowe regresje w historycznych otwartych blockerach; nie zaakceptowano ich. Korekty `d91731f…` i `7143bae…` przywróciły oryginalne brzmienie DB-EXAM-004/006/008.

Po korekcie porównano DB-EXAM-005..008 z bazą sprzed fixera i pozostały literalnie OPEN bez zmiany diagnoz. Zamrożone agregaty zachowały identyczne SHA:
- `specs/database/core-schema.yml` -> `5d8f4d661f56115740d3c3a59d8424c85ec1cf24`,
- `docs/87-physical-database-schema.md` -> `191afe107e830baa928b18f67e6cb75b2d4a73b8`.

Sprawdzono dodatkowo:
- DB-EXAM-001..003 pozostają PASS,
- lock suffix DB-EXAM-003 nie został odwrócony,
- resend nie alokuje drugiej sztuki,
- replacement po release używa nowej Reservation, nie otwiera starej,
- submit nie konsumuje ponownie,
- technical abort nie przywraca inventory,
- późniejszy requirement/capability change nie przepisuje Attempt history,
- token security pozostaje DB-EXAM-005,
- station device/failover pozostaje DB-EXAM-006,
- scoring/document evidence pozostaje DB-EXAM-007,
- management projection pozostaje DB-EXAM-008,
- DB4_8+, Stage 5, Laravel migrations i UI nie zostały rozpoczęte.

Aktualny stan DB4_7 po DB-EXAM-004:
- P0: **0**,
- P1 open: **4**,
- resolved: **4/8**,
- result: **FAIL_WITH_4_P1_BLOCKERS**.

Następny dozwolony krok po centralnym gate: **DB-EXAM-005 only**.

**STOP przed DB-EXAM-005.**

---

## 19. DB-EXAM-005 — wynik fixera: PASS

DB-EXAM-005 zamyka wyłącznie security boundary dla bearer tokenów zdalnego egzaminu i rozdziela uprawnienie do wykonania egzaminu od późniejszego odczytu wyniku. Nie projektuje StationSession/device binding/failover, scoringu/dokumentów ani projekcji statystyk.

### 19.1 Jeden `Access.token_hash` nie może być finalnym authority

Dotychczasowy pojedynczy `internal_exam_accesses.token_hash` mieszał dwa różne privilege lifecycle:
- token wykonawczy przed i podczas egzaminu,
- token tylko do odczytu zakończonego wyniku i question review.

Canonical token authority przenosimy do append-history table `internal_exam_access_tokens`. Stary `Access.token_hash` nie jest już source of truth; jego dokładne usunięcie lub migracyjne wygaszenie nastąpi przy finalnym aggregate/cutover po reviewed migration.

Bearer tokens są dozwolone wyłącznie dla `remote_link`. Lokalne i przypisane stanowiska nie dostają remote bearer tokenu; ich authentication/device binding pozostaje DB-EXAM-006.

### 19.2 Zamknięty katalog purpose

Dozwolone są dokładnie dwa purpose:
- `exam_execution`,
- `finished_result_read`.

Nie używamy arbitralnego JSON scope jako authority.

`exam_execution` może tylko:
- uruchomić exact Access,
- obsługiwać/submitować exact rozpoczęty Attempt.

Nie może czytać zakończonego wyniku ani question review.

`finished_result_read` może tylko:
- `GET` exact Result,
- `GET` exact Questions zakończonego Attemptu.

Nie może startować, submitować, revoke'ować Accessu, robić station transfer ani pobierać staff-only dokumentów.

### 19.3 Exact Access + Attempt + tenant binding

Każdy token row przechowuje:
- `organization_id`,
- `internal_exam_access_id`,
- `internal_exam_attempt_id`,
- purpose,
- monotoniczny `token_sequence`,
- losowy `lookup_id`,
- one-way verifier + key version,
- issue/expiry/revocation metadata.

Composite FK:

`(organization_id,internal_exam_access_id,internal_exam_attempt_id)`
`-> internal_exam_accesses(organization_id,id,internal_exam_attempt_id)`

uniemożliwia podpięcie tokenu Accessu A do Attemptu B albo innego OSK.

`lookup_id` jest tylko losowym locator, nie sekretem i nie authority.

### 19.4 Raw token nigdy nie jest recoverable

Token powstaje z CSPRNG i ma co najmniej równoważnik 256 bitów entropii.

W DB zapisujemy wyłącznie keyed one-way verifier, np. HMAC-SHA-256 lub równoważny mechanizm, wraz z `verifier_key_version`. Pepper/key pozostaje poza bazą w security key management.

Raw secret nie może być zapisany:
- w DB,
- w reversible ciphertext,
- w FileAsset/object storage,
- audit logach,
- outbox payloadach,
- application logs/traces,
- lifecycle events,
- idempotency response snapshot.

Po przekroczeniu one-time response/delivery boundary serwer nie może odzyskać starego secretu.

### 19.5 Weryfikacja tokenu

Weryfikacja wymaga kolejno:
1. parsowania opaque tokenu,
2. odszukania row po losowym locatorze,
3. wyliczenia verifiera z właściwym key version,
4. constant-time compare,
5. zgodności purpose,
6. exact Access/Attempt request binding,
7. same tenant,
8. `revoked_at IS NULL`,
9. `effective_at < expires_at`,
10. następnie locków DB-EXAM-004 i ponownego sprawdzenia lifecycle.

`attempt_id`, `access_id` ani sam `lookup_id` nigdy nie uwierzytelniają.

Na dokładnej granicy expiry token jest nieważny.

### 19.6 Execution token lifecycle

Execution token istnieje tylko dla remote Accessu.

Przed startem wymaga startable Accessu i Attempt `created`. Po starcie może autoryzować submit wyłącznie exact Access/Attempt w `started/in_progress`, dopóki token nie wygasł i nie został revoked.

Parallel replay startu nadal jest finalnie zatrzymywany przez DB-EXAM-004 Attempt/Access locks oraz DB-EXAM-003 consume-on-start exactly once.

Execution token jest revoke'owany atomowo przy:
- pre-start revoke/expire/cancel,
- successful submit/finish,
- technical abort,
- invalidation.

Exact TTL pozostaje konfigurowalną security/product policy; DB nie hardcoduje arbitralnej liczby minut, ale konfiguracja musi obejmować przewidziany czas egzaminu.

### 19.7 Finished-result token lifecycle

Result token nie istnieje przed zakończeniem egzaminu.

Powstaje wyłącznie, gdy:
- Attempt jest `passed|failed`,
- Access jest `completed`,
- exact Result istnieje.

W tej samej finish transaction:
- execution token jest revoke'owany,
- powstaje jeden current `finished_result_read` verifier.

Technical abort nie tworzy result tokenu.

Invalidation revoke'uje current result token, ale nie usuwa historycznego Result/Question/Document evidence. Staff nadal może czytać wynik przez istniejący RBAC, jeśli ma odpowiednie permission.

Ewentualny przyszły reissue result tokenu musi być jawnym commandem z nowym Idempotency-Key i rotacją secretu; exact HTTP shape pozostaje do Stage-5 API sync.

### 19.8 Dlaczego resend musi rotować token

Potwierdzony produkt wymaga resend tego samego Accessu bez nowej Reservation. Jednocześnie raw secret jest nieodtwarzalny.

Dlatego resend **nie może wysłać starego tokenu ponownie**.

`POST /internal-exam-accesses/{accessId}/send`:
- zachowuje ten sam Access,
- zachowuje tę samą Reservation,
- nie alokuje kolejnego InventoryEntry,
- revoke'uje current execution token z powodem rotation,
- zwiększa `token_sequence`,
- generuje nowy secret w pamięci,
- zapisuje tylko nowy verifier,
- wysyła nowy raw secret raz,
- odrzuca buffer secretu.

Stary token przestaje działać natychmiast po commit rotacji.

Pierwszy send może zmienić Access `ready -> delivered_or_assigned`; resend nie cofa lifecycle statusu.

### 19.9 Idempotency bez secret replay

One-time secret i Idempotency-Key nie oznaczają, że serwer ma zachować plaintext do ponownego zwrócenia.

Ten sam completed Idempotency-Key:
- nie wykonuje drugiej rotacji,
- nie tworzy kolejnego tokenu,
- nie odtwarza starego raw secretu,
- może zwrócić sanitized nonsecret receipt albo jawny `secret not replayable` rezultat.

Jeżeli klient nie odebrał secretu po commit, stary secret nadal nie staje się recoverable. Potrzebny jest nowy jawny send/resend z nowym Idempotency-Key, który rotuje secret ponownie.

Delivery failure nie rollbackuje Accessu, Reservation ani token history.

### 19.10 Token sequence i current uniqueness

Per `(Organization,Access,purpose)` tokeny mają bezlukowy `token_sequence` od 1, alokowany pod Access lock.

Partial unique gwarantuje maksymalnie jeden row `revoked_at IS NULL` na Access+purpose.

Historyczne rotated/revoked/expired rows pozostają i nie są hard-delete'owane.

Verifier, binding, purpose, sequence, locator, issue time i expiry są immutable. Revocation jest write-once i wymaga reason code.

### 19.11 Final-state equivalence

Deferrable cross-row guard wymaga:
- local/assigned-station Access -> zero bearer tokens,
- remote `ready|delivered|opened` -> dokładnie 1 current execution token, 0 result token,
- remote `started` -> dokładnie 1 current execution token, 0 result token,
- remote `completed` z passed/failed Result -> 0 execution, dokładnie 1 current result token,
- remote `technical_abort|invalidated` -> zero current bearer tokens,
- remote pre-start `cancelled|expired|revoked` -> zero current bearer tokens.

Expired token nie uwierzytelnia nawet wtedy, gdy cleanup nie ustawił jeszcze `revoked_at`.

### 19.12 Integracja z DB-EXAM-003 i DB-EXAM-004

DB-EXAM-005 nie zmienia inventory semantics:
- create Attempt nadal rezerwuje dokładnie jedną sztukę,
- start nadal konsumuje dokładnie raz,
- submit nie konsumuje drugi raz,
- pre-start revoke/expire/cancel nadal release'uje Reservation,
- technical abort nie przywraca consumed unit.

Token commands zachowują lock prefix DB-EXAM-004 `Attempt -> Access`; gdy lifecycle dotyka Reservation/Inventory, suffix DB-EXAM-003 pozostaje bez zmian.

Rotation tokenu sama w sobie nie zwiększa business `Access.version`, jeśli nie zmienia się business field; token history ma własny sequence.

### 19.13 Outbox i delivery boundary

DB-EXAM-005 zamyka zakaz durable raw-secret storage, ale nie projektuje finalnej infrastruktury wiadomości.

Outbox może zachować wyłącznie nonsecret delivery metadata/intention. Raw token nie może znaleźć się w durable outbox payload.

Exact transport i sposób bezpiecznej jednorazowej dostawy zostaną zsynchronizowane w Stage 5 oraz DB4_10, bez rozwiązywania ich teraz.

### 19.14 Migration safety

Legacy `Access.token_hash` jest niejednoznaczny: nie wiadomo automatycznie, czy reprezentował execution privilege, result privilege czy dawny multipurpose token.

Migracja nie może:
- klasyfikować finished legacy hash jako result token tylko po statusie,
- promować pre-start hash do active execution tokenu bez dowodu verifier algorithm/key context,
- kopiować starego hasha jako current token tylko po to, by link nadal działał,
- zastępować tokenu Attempt/Access ID,
- fabrykować raw secretu,
- tworzyć result tokenu bez jawnej delivery flow.

Najbezpieczniejszym defaultem dla niejednoznacznego ephemeral tokenu jest security invalidation i jawny reissue/rotation. Formalna historia Attempt/Result/Questions/Documents nie jest przez to kasowana.

Niejednoznaczny purpose/verifier = fail security activation + reviewed remediation.

### 19.15 Self-audit fixera

Pierwszy machine write DB-EXAM-005 wprowadził jedną niedozwoloną czysto tekstową zmianę w zamkniętym DB-EXAM-004: `set_access_started_at_equal_attempt_started_at` zostało zapisane jako `set_access_started_at_equal_attempt.started_at`. Bramka jakości nie została wtedy uznana za PASS.

Commit `cf551565…` przywrócił dokładnie tę jedną linię. Porównanie correction commit wykazało 1 addition / 1 deletion, a pełny diff od finalnego DB-EXAM-004 obejmuje wyłącznie `specs/database/internal-exams.yml`.

Zamrożone agregaty nadal mają dokładnie:
- `specs/database/core-schema.yml` -> `5d8f4d661f56115740d3c3a59d8424c85ec1cf24`,
- `docs/87-physical-database-schema.md` -> `191afe107e830baa928b18f67e6cb75b2d4a73b8`.

Sprawdzono dodatkowo:
- DB-EXAM-001..004 pozostają PASS,
- resend nie tworzy nowego Accessu, Reservation ani Inventory,
- raw token nie jest nigdzie durable/recoverable,
- execution privilege i result-read privilege są rozdzielone,
- token nie zastępuje lifecycle/tenant/exact-target checks,
- DB-EXAM-006 Station/device/failover pozostaje OPEN,
- DB-EXAM-007 scoring/document evidence pozostaje OPEN,
- DB-EXAM-008 management/statistics projection pozostaje OPEN,
- DB4_8+, Stage 5, Laravel migrations i UI nie zostały rozpoczęte.

Aktualny stan DB4_7 po DB-EXAM-005:
- P0: **0**,
- P1 open: **3**,
- resolved: **5/8**,
- result: **FAIL_WITH_3_P1_BLOCKERS**.

Następny dozwolony krok po centralnym gate: **DB-EXAM-006 only**.

**STOP przed DB-EXAM-006.**
