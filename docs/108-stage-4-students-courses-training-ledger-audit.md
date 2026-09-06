# 108. Stage 4 — Students / Courses / Training Ledger physical invariant audit

Data: 2026-09-06

**Status:** `DB4_4 IN PROGRESS / DB-TRN-001 PASS / 7 P1 BLOCKERS OPEN`

## Cel i zasada pracy

`DB4_4_STUDENTS_COURSES_TRAINING_LEDGER` został otwarty od osobnej diagnozy, bez napraw w tym samym kroku. Następnie blocker jest zamykany pojedynczo: jedna decyzja fizyczna -> self-audit -> gate -> STOP.

Nie wchodzimy do Calendar ani późniejszych slice'ów i nie generujemy migracji Laravel przed zamknięciem właściwych bramek projektu.

Zasada:

`confirmed capability -> legal/formal requirement -> physical owner -> tenant boundary -> lifecycle/history -> concurrency -> invariant test`

## Źródła audytu

Przejrzano w szczególności:
- `specs/screens/student-create.yml`, `student-detail.yml`, `student-edit.yml`,
- `specs/screens/student-add-course.yml`, `course-create.yml`, `course-edit.yml`, `course-delete.yml`,
- `specs/legal/training-theory-exemptions.yml`,
- `specs/legal/editable-training-requirements.yml`,
- `docs/66-formal-student-record-and-theory-exemptions.md`,
- `docs/67-editable-training-requirements-and-theory-exemption.md`,
- `specs/traceability/core-v1.yml`,
- `specs/api/openapi-components-v1.yaml`,
- `specs/api/paths/students-courses.yaml`,
- `specs/database/core-schema.yml`,
- `specs/database/students-courses-training.yml`,
- `docs/87-physical-database-schema.md`,
- `docs/84-test-strategy.md`.

`specs/database/students-courses-training.yml` jest bounded-context machine spec dla DB4_4. Aggregate `core-schema.yml` i `docs/87` pozostają nietknięte aż do osobnego finalnego sync DB4_4.

## Co jest już poprawnie zaprojektowane

- formalny przebieg jest course-first: trwały `Student` + `CourseEnrollment` przed formalnymi zajęciami i egzaminem,
- kurs zachowuje wszystkie potwierdzone pola: rodzaj szkolenia, kategorię, PKK, start, koszt, cztery pola godzinowe, instruktora i lokalizację,
- bieżące godziny OSK z formularza nie są źródłem formalnego zaliczenia; credited time ma pochodzić z `training_hour_ledger_entries`,
- godziny z poprzedniego OSK są oddzielone do `recognized_external_training`,
- wymagania szkolenia są wersjonowaną projekcją rule engine, nie stałym zestawem flag,
- teoria jest liczona w jednostkach 45 minut, praktyka w jednostkach 60 minut zgodnie z aktualnym zweryfikowanym specem prawnym,
- formalna i finansowa historia nie może być hard-delete,
- API posiada osobne operacje create/update/cancel/restore/stage transition, sesje szkoleniowe, korekty godzin oraz external training.

To są dobre fundamenty. Diagnoza wykryła osiem P1 wymagających zamknięcia fizycznymi inwariantami.

---

# DB-TRN-001 — PASS: same-tenant integrity Student / Course / Training

## Problem z diagnozy

W wielu formalnych relacjach obie strony mają `organization_id`, ale aggregate blueprint opierał się głównie na zwykłych FK do samego `id`. To nie dawało fizycznej gwarancji, że relacja nie połączy danych dwóch OSK.

Dotyczyło co najmniej:
- `students.default_location_id`,
- `student_learning_accounts -> students` w zakresie same-tenant ownership,
- `course_enrollments -> students`, `staff_profiles`, `locations`,
- `training_requirement_profiles -> course_enrollments`,
- `course_exemption_decisions -> course_enrollments`,
- `recognized_external_training -> course_enrollments`,
- `training_sessions -> course_enrollments`, `staff_profiles`, `vehicles`, `locations`,
- `training_session_attendance -> training_sessions + students`,
- `training_hour_ledger_entries -> course_enrollments/training_sessions`.

## Decyzja canonical

W `specs/database/students-courses-training.yml` wprowadzono tenant-aware candidate keys oraz composite foreign keys. Fizyczny wzorzec jest taki sam jak zamknięty wcześniej w DB4_3:

`child(organization_id, relation_id) -> parent(organization_id, id)`.

Parent candidate keys wymagane w tym slice:
- `students(organization_id,id)`,
- `course_enrollments(organization_id,id)`,
- `training_sessions(organization_id,id)`.

Wykorzystujemy też już zamknięte w DB4_3:
- `staff_profiles(organization_id,id)`,
- `locations(organization_id,id)`,
- `vehicles(organization_id,id)`.

Każdy composite FK używa `ON UPDATE RESTRICT / ON DELETE RESTRICT`. Opcjonalne relacje (`default_location`, course location, session vehicle/location, ledger session) zachowują `MATCH SIMPLE`, więc `NULL` pozostaje legalnym brakiem relacji, ale nie może ukryć nie-NULL cross-tenant ID.

## Attendance — brakujący tenant key

`training_session_attendance` nie miał własnego `organization_id`, więc nie dało się fizycznie spiąć jednocześnie Session i Student z tym samym tenantem.

Decyzja:
- dodać `organization_id NOT NULL FK organizations`,
- `(organization_id,training_session_id)` -> `training_sessions(organization_id,id)`,
- `(organization_id,student_id)` -> `students(organization_id,id)`.

Migracja będzie później wykonywana bez automatycznego „naprawiania” danych:
1. kolumna tymczasowo nullable,
2. sprawdzenie, że Session i Student istnieją i należą do tego samego OSK,
3. mismatch -> migration FAIL / jawna security remediation,
4. backfill tenantu z uprzednio zweryfikowanej TrainingSession,
5. `NOT NULL`, FK do Organization i oba composite FK.

Nie zmieniamy w tym blockerze semantyki unique `(training_session_id,student_id)` ani reguł formalnego creditu.

## Relacje zamknięte fizycznie

Po decyzji DB-TRN-001 same-tenant boundary istnieje dla:
- Student -> default Location,
- StudentLearningAccount -> Student,
- CourseEnrollment -> Student,
- CourseEnrollment -> lead StaffProfile,
- CourseEnrollment -> optional Location,
- TrainingRequirementProfile -> CourseEnrollment,
- CourseExemptionDecision -> CourseEnrollment,
- RecognizedExternalTraining -> CourseEnrollment,
- TrainingSession -> CourseEnrollment,
- TrainingSession -> StaffProfile,
- TrainingSession -> optional Vehicle,
- TrainingSession -> optional Location,
- TrainingSessionAttendance -> TrainingSession,
- TrainingSessionAttendance -> Student,
- TrainingHourLedgerEntry -> CourseEnrollment,
- TrainingHourLedgerEntry -> optional TrainingSession.

Globalny `User` i globalne słowniki DrivingCategory nie dostają sztucznego tenant key. Ten sam globalny User może legalnie występować w wielu organizacjach; tenant ownership learning account wynika z powiązania z tenant-owned Studentem.

## Tenant ownership

`organization_id` rekordów formalnych nie jest client authority. Backend wyprowadza tenant z aktywnego membership albo zweryfikowanego parent resource. Zmiana organizacji istniejącego formalnego Student/Course/Session/Ledger przez zwykłe przepisanie `organization_id` jest zabroniona.

Composite FK pozostają końcową granicą bezpieczeństwa, jeżeli backend/import popełni błąd.

## Świadomie NIE rozwiązano tutaj

Aby nie naruszyć staged process, DB-TRN-001 nie rozwiązuje:
- czy Attendance Student jest dokładnie Studentem z CourseEnrollment sesji — DB-TRN-006,
- czy Ledger Session należy dokładnie do tego samego CourseEnrollment co ledger row — DB-TRN-006,
- exactly-once complete/credit, cancelled-session guard, correction/reversal source entry — DB-TRN-006,
- PESEL/birth-date branch i duplicate Student — DB-TRN-002,
- Student version/archive/restore — DB-TRN-003,
- Course stage/cancel/restore history — DB-TRN-004,
- requirement source facts/reproducibility — DB-TRN-005,
- external-hours current projection — DB-TRN-007,
- required PKK persistence boundary — DB-TRN-008,
- learning-account/license credentials lifecycle — DB4_6,
- PKK provider lifecycle — DB4_8.

## Quality gate DB-TRN-001

Sprawdzono:
- wszystkie relacje wymienione w diagnozie DB-TRN-001 mają physical same-tenant boundary — **PASS**,
- parent candidate keys są jawne — **PASS**,
- brakujący tenant key Attendance został domknięty — **PASS DESIGN**,
- migration backfill Attendance nie może cicho przepisać cross-tenant danych — **PASS**,
- optional FK zachowują poprawną nullability przez `MATCH SIMPLE` — **PASS**,
- formalne FK używają `RESTRICT`, bez destrukcyjnego cascade — **PASS**,
- global User/dictionary nie zostały błędnie tenant-scoped — **PASS**,
- DB-TRN-002..008 nie zostały naprawione przy okazji — **PASS**,
- `core-schema.yml` i `docs/87` nie zostały zmienione — **PASS**,
- migracje Laravel nie zostały utworzone — **PASS**,
- DB4_5 ani późniejsze slice'y nie zostały rozpoczęte — **PASS**.

**GATE DB-TRN-001: PASS.**

---

# DB-TRN-002 — OPEN P1: formal identity branch Student + duplicate lifecycle

## Problem

Zweryfikowana reguła formalna wymaga identyfikacji osoby przez PESEL albo — gdy PESEL nie został nadany — przez datę urodzenia. UI posiada jawny branch `no_pesel` / `no_pesel_declared`.

Obecny physical blueprint dopuszcza jednak stan, w którym jednocześnie:
- PESEL jest `NULL`,
- birth date jest `NULL`.

Nie jest też ostatecznie zamknięte:
- spójne przejście PESEL <-> brak PESEL,
- spójność `pesel_ciphertext` z `pesel_lookup_hash`,
- duplicate PESEL per OSK,
- zachowanie duplicate detection przez archive/restore.

## Ryzyko

Możliwy jest formalny rekord kursanta niespełniający minimalnej identyfikacji ewidencyjnej albo dwa trwałe rekordy tej samej osoby w jednym OSK.

**Status:** OPEN P1.

---

# DB-TRN-003 — OPEN P1: Student concurrency + archive/restore lifecycle

## Problem

Publiczny kontrakt `Student` posiada `version`, a `PATCH /students/{studentId}` korzysta z `If-Match`. Physical `students` nie posiada obecnie jawnego `version` jako concurrency root.

Jednocześnie istnieją osobne operacje archive/restore i wymaganie zachowania formalnej historii, ale fizyczna polityka nie rozstrzyga jeszcze w pełni:
- jak serializować profile edit vs archive/restore,
- co archive oznacza dla aktywnych CourseEnrollment,
- kiedy restore jest dozwolony,
- jak uniknąć silent lost update,
- które zależności pozostają tylko historyczne bez destrukcyjnego cascade.

Szczegółowy lifecycle learning access/licencji pozostaje zakresem DB4_6 i nie jest tutaj projektowany.

## Ryzyko

Równoległa edycja i archive/restore może nadpisać dane lub pozostawić student/course state sprzeczny z formalną historią.

**Status:** OPEN P1.

---

# DB-TRN-004 — OPEN P1: Course lifecycle, stage history, cancel/restore

## Problem

`course_enrollments` posiada jednocześnie `training_stage`, `completed_at`, `interrupted_at`, `cancelled_at`, ale nie ma jeszcze zamkniętej fizycznej macierzy stanów ani historii przejść stage.

Potwierdzone API posiada osobną operację `stage-transitions`, ekran wymaga historii, a polityka produktu mapuje „usuń kurs” na bezpieczne cancel/correction z zachowaniem formalnych zależności. Closed course wymaga trybu korekty.

Nie jest fizycznie rozstrzygnięte:
- które kombinacje stage/timestamp są legalne,
- jak zachować pełną historię stage transitions,
- jak serializować update/cancel/restore/complete/interruption,
- czy i kiedy cancelled/closed enrollment można przywrócić,
- jak korekta closed course nie przepisuje historycznych faktów.

## Ryzyko

Jeden kurs może mieć wzajemnie sprzeczne lifecycle flags albo utracić historię zmian etapu potrzebną do audytu i dokumentacji.

**Status:** OPEN P1.

---

# DB-TRN-005 — OPEN P1: requirement context/profile reproducibility and correction history

## Problem

Legal/product spec mówi, że wymagania mają być przeliczalne na podstawie trwałych faktów:
- target category,
- held categories,
- `state_theory_passed`,
- exemption basis,
- evidence reference.

Rekomendowany model dokumentacyjny rozdziela durable requirement context od immutable decyzji kalkulacyjnych. Obecny DB posiada `training_requirement_profiles` z `input_snapshot` oraz `course_exemption_decisions`, lecz nie ma jeszcze jednoznacznego canonical ownera bieżących source facts ani kompletnego immutable decision trail każdej rekalkulacji z actor/reason/output snapshot.

Dodatkowo korekta zamkniętego kursu musi być audytowana i nie może niszczyć wcześniejszych zajęć, attendance ani exam history.

## Ryzyko

Po czasie nie będzie można deterministycznie odpowiedzieć: „dlaczego w tej wersji kurs miał takie wymagania?” ani bezpiecznie odtworzyć skutku późniejszej korekty przy konkretnym `rule_set_version`.

**Status:** OPEN P1.

---

# DB-TRN-006 — OPEN P1: verified attendance -> ledger exactly-once

## Problem

Formalny pipeline jest zdefiniowany jako:

`training_session -> duration -> verified attendance -> training_hour_ledger -> course totals`.

API `complete` deklaruje atomowe utworzenie eligible ledger entries, ale physical blueprint nie posiada jeszcze pełnych guardów exactly-once.

Brakuje jednoznacznego rozstrzygnięcia co najmniej dla:
- attendance Student musi odpowiadać Studentowi szkolonemu w CourseEnrollment sesji,
- session/course/student/tenant muszą być spójne,
- cancelled session nie może kredytować czasu,
- ponowne/retry `complete` nie może tworzyć drugiego base credit,
- jedna sesja nie może zostać formalnie zaliczona dwa razy temu samemu kursowi/part,
- `source_entry_id` correction/reversal musi mieć jawny FK i lifecycle,
- reversal/correction nie może umożliwić przypadkowego double reversal/double credit,
- duration/timestamps i credited minutes muszą mieć jeden autorytatywny kontrakt.

## Ryzyko

Formalny czas szkolenia może zostać naliczony podwójnie, dla niewłaściwego kursanta albo mimo anulowanej sesji.

**Status:** OPEN P1.

---

# DB-TRN-007 — OPEN P1: RecognizedExternalTraining current projection vs additive history

## Problem

Potwierdzony formularz course create/edit pokazuje po jednej wartości „teoria w innej szkole” i „praktyka w innej szkole”. Jednocześnie domena musi wspierać wiele udokumentowanych zewnętrznych wpisów oraz korekty/reversal bez utraty historii.

Obecna tabela może przechowywać wiele aktywnych rekordów tego samego `course_enrollment + training_part + source_kind`, ale nie definiuje jednoznacznie:
- który rekord reprezentuje bieżącą wartość formularza `course_form_initial`,
- czy kolejne edycje zastępują/revoke'ują wcześniejszą wersję,
- które rekordy są additive documented transfer,
- jak projekcja sumy unika podwójnego naliczenia po kolejnych edycjach,
- jak concurrency create/revoke zachowuje deterministyczny wynik.

## Ryzyko

Godziny uznane z poprzedniego OSK mogą zostać naliczone podwójnie albo projekcja formularza stanie się zależna od nieokreślonego „latest row”.

**Status:** OPEN P1.

---

# DB-TRN-008 — OPEN P1: required course PKK persistence boundary before provider lifecycle

## Problem

Zweryfikowany dedykowany formularz Course create/edit wymaga PKK, a PKK jest course-scoped. Jednocześnie `course_enrollments` nie posiada własnego PKK field, natomiast `pkk_profiles` należy do późniejszego DB4_8 i `pkk_number` może być nullable.

DB4_4 musi później określić minimalną bezpieczną granicę persistence/orchestration, dzięki której zapis kursu z wymaganym PKK nie może zakończyć się stanem „Course istnieje, ale required PKK identity nie została utrwalona”. Nie wolno przy tym w tym slice projektować provider request/response, reconciliation ani retry — to pozostaje DB4_8.

## Ryzyko

Potwierdzony course-create capability może powstać jako częściowy zapis albo PKK może zostać przypisane do innego course/tenant niż enrollment, zanim DB4_8 dołoży provider lifecycle.

**Status:** OPEN P1.

---

# Świadomie poza blockerami DB4_4 diagnozy

Nie rozwiązujemy w tym slice:
- legalnej finalnej mapy `PT/tram` — znany production/legal verification gate,
- szczegółowego provider contract/retry PKK — DB4_8,
- licencji, learning access i credentials — DB4_6,
- internal exam compatibility/lifecycle — DB4_7,
- pełnej Student Finance i powiązania kosztu kursu z płatnościami — DB4_9,
- calendar overlap i resource booking — DB4_5,
- dokładnych regexów telefonu/e-mail/PKK/VIN jako application validation,
- nieobserwowalnego backend behavior konkurencyjnego hard delete.

Te zależności mogą być walidowane na boundary późniejszych slice'ów, ale nie są powodem do rozszerzenia zakresu bieżącego blockera.

---

# Quality gate DB4_4_STEP_1 — DIAGNOSIS

Historyczny wynik kroku diagnozy:
- DB4_3 był PASS przed otwarciem DB4_4 — **PASS**,
- przejrzano screen/API/legal/DB/test sources dla Students/Courses/Training Ledger — **PASS**,
- wszystkie potwierdzone cztery pola godzinowe kursu pozostają w scope — **PASS**,
- formalny source of truth czasu pozostaje ledger, nie ręcznie nadpisywane declared hours — **PASS**,
- reguły 45 min teoria / 60 min praktyka zostały uwzględnione jako wymaganie, nie zostały reinterpretowane — **PASS**,
- wykryto i zapisano wszystkie znane P0/P1 z tego audytu — **PASS: 8 P1 / 0 P0**,
- nie naprawiono żadnego DB-TRN-* w kroku diagnozy — **PASS**,
- `core-schema.yml` i `docs/87` nie były modyfikowane w diagnozie — **PASS**,
- nie rozpoczęto DB4_5 ani późniejszych slice'ów — **PASS**,
- nie utworzono migracji Laravel ani feature/UI implementation — **PASS**.

**DIAGNOSIS GATE DB4_4: FAIL_WITH_8_P1_BLOCKERS.**

Był to prawidłowy wynik diagnozy i otworzył naprawy blocker-by-blocker.

---

# Quality gate DB4_4_STEP_2 — DB-TRN-001

Wykonano wyłącznie same-tenant integrity. Bounded-context spec nie naprawia pozostałych siedmiu blockerów.

Self-audit:
- nowy machine source: `specs/database/students-courses-training.yml` — **PASS**,
- komplet relacji z diagnozy DB-TRN-001 -> composite same-tenant boundary — **PASS**,
- Attendance otrzymał projekt `organization_id` + bezpieczny migration backfill — **PASS**,
- Staff/Location/Vehicle wykorzystują candidate keys już zamknięte w DB4_3 — **PASS**,
- Student/Course/TrainingSession candidate keys zadeklarowane — **PASS**,
- nie wprowadzono nowego hard-delete/cascade formal history — **PASS**,
- nie zmieniono semantyki formalnych godzin — **PASS**,
- nie rozwiązano dokładnej relacji Attendance Student = Course Student — **PASS SCOPE**, pozostaje DB-TRN-006,
- nie rozwiązano ledger exactly-once — **PASS SCOPE**, pozostaje DB-TRN-006,
- `specs/database/core-schema.yml` bez zmian — **PASS**,
- `docs/87-physical-database-schema.md` bez zmian — **PASS**,
- DB4_5+ bez zmian — **PASS**,
- migracje Laravel/UI/feature implementation — **NIE ROZPOCZĘTO**.

**FINAL GATE DB-TRN-001: PASS.**

## Aktualna kolejność napraw

1. `DB-TRN-001` — **PASS**.
2. `DB-TRN-002` — formal Student identity branch + duplicate lifecycle — **NEXT**.
3. `DB-TRN-003` — Student concurrency + archive/restore lifecycle.
4. `DB-TRN-004` — Course lifecycle/stage/cancel/restore history.
5. `DB-TRN-005` — requirement context/profile reproducibility.
6. `DB-TRN-006` — attendance -> ledger exactly-once.
7. `DB-TRN-007` — external training projection/history.
8. `DB-TRN-008` — course PKK persistence boundary.

**Następny pojedynczy krok: tylko `DB-TRN-002` -> self-audit -> gate -> STOP przed `DB-TRN-003`.**