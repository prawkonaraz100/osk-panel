# 112. Stage 4 — Internal Exams database audit

Data: 2026-09-07

**Etap:** `DB4_7_INTERNAL_EXAMS`  
**Aktualny krok:** `DB_EXAM_002_FORMAL_COURSE_REQUIREMENT_CAPABILITY_BASIS`  
**Status:** `FAIL_WITH_6_P1_BLOCKERS / 0 P0 / 6 P1 OPEN`

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