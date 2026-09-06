# 108. Stage 4 — Students / Courses / Training Ledger physical invariant audit

Data: 2026-09-06

**Status:** `DB4_4 IN PROGRESS / DB-TRN-001 PASS / DB-TRN-002 PASS / DB-TRN-003 PASS / 5 P1 BLOCKERS OPEN`

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

# DB-TRN-002 — PASS: formal Student identity branch + duplicate lifecycle

## Problem z diagnozy

Zweryfikowana reguła formalna wymaga, aby osoba przyjmowana na szkolenie była ewidencjonowana przez PESEL albo — gdy PESEL nie został nadany — przez datę urodzenia. Jednocześnie potwierdzony ekran tworzenia kursanta pozwala utworzyć sam profil kursanta bez kursu, a na ekranie edycji istnieje jawny branch `no_pesel` / `no_pesel_declared` i data urodzenia jest wymagana właśnie dla tego branchu.

Nie można więc bezrefleksyjnie zrobić `students.pesel NOT NULL`, bo zepsułoby to potwierdzoną możliwość przygotowania profilu przed formalnym zapisem na kurs. Z drugiej strony nie można dopuścić, aby `CourseEnrollment` istniał dla kursanta z niekompletną formalną tożsamością.

## Decyzja canonical — rozdzielenie profilu przygotowawczego od formalnej gotowości

`students` może istnieć jako trwały, pre-course profil z samym imieniem i nazwiskiem. Formalna kompletność tożsamości jest natomiast warunkiem utworzenia i utrzymania `CourseEnrollment`.

Dodajemy do `students`:

`no_pesel_declared boolean NOT NULL DEFAULT false`.

To pole nie jest automatycznie wyliczane z `pesel IS NULL`. Ma rozróżniać dwa różne fakty:
- `no_pesel_declared=false + brak PESEL` = profil jeszcze niekompletny / PESEL nie został wprowadzony,
- `no_pesel_declared=true` = jawnie zadeklarowano, że PESEL nie został nadany; wtedy data urodzenia jest wymagana.

Input API `no_pesel` mapuje się na canonical DB field `no_pesel_declared`. Nie zmieniamy w tym kroku Stage-3 OpenAPI.

## Row-level consistency PESEL

`pesel_ciphertext` i `pesel_lookup_hash` są jednym logical value zapisanym w dwóch bezpiecznych reprezentacjach. DB wymusza, że:
- oba są `NULL`, albo
- oba są non-NULL.

Nie można zapisać tylko ciphertextu albo tylko lookup hash.

Branch `no_pesel_declared=true` wymaga jednocześnie:
- `pesel_ciphertext IS NULL`,
- `pesel_lookup_hash IS NULL`,
- `birth_date IS NOT NULL`.

Jeżeli PESEL jest obecny, `no_pesel_declared` musi być `false`.

Dopuszczamy nadal pre-formalny stan:

`no_pesel_declared=false + brak PESEL pair`

bo inaczej zredukowalibyśmy potwierdzony create flow. Taki rekord nie może jednak zostać użyty jako formalny kursant CourseEnrollment.

## Formal identity predicate

Student spełnia warunek formalnej tożsamości tylko wtedy, gdy finalny stan spełnia jedną z dwóch gałęzi:

1. `no_pesel_declared=false` oraz oba pola PESEL są non-NULL,
2. `no_pesel_declared=true`, oba pola PESEL są NULL i `birth_date IS NOT NULL`.

Nie wymagamy ręcznego `birth_date` dla branchu z PESEL. Źródło daty urodzenia przy PESEL pozostawało w screen spec jako `TO_VERIFY`, więc nie wymyślamy automatycznego dekodowania lub dodatkowego obowiązku.

## Database boundary dla formalnego kursu

Sama walidacja formularza nie jest wystarczająca.

Projekt przewiduje deferrable constraint trigger albo równoważny transactional database guard:
- przy `INSERT` / zmianie Student relation na `course_enrollments` sprawdź `student_has_formal_identity`,
- przy zmianie pól tożsamości Student, który ma jakikolwiek historyczny CourseEnrollment, sprawdź finalny stan po transakcji,
- nie pozwól zdegradować formalnego kursanta do stanu „brak PESEL, no_pesel nie zadeklarowane”.

Constraint jest deferrable, aby legalna korekta branchu mogła w jednej transakcji wyczyścić stary PESEL, ustawić `no_pesel_declared=true` i uzupełnić datę urodzenia bez chwilowego zerwania finalnego inwariantu.

Wymóg tożsamości nie znika po zakończeniu, anulowaniu, przerwaniu ani archiwizacji rekordu, jeżeli istnieje formalna historia kursu. Formalna dokumentacja nadal musi odnosić się do kompletnej tożsamości kursanta.

## PESEL — bezpieczny write contract

Plaintext PESEL nie jest przechowywany. Authorized command/import:
1. przyjmuje plaintext tylko na boundary requestu,
2. wykonuje canonical normalization,
3. z tego samego znormalizowanego inputu tworzy ciphertext oraz keyed lookup hash,
4. zapisuje oba atomowo,
5. usuwa plaintext z dalszego obiegu.

Lookup pozostaje HMAC-SHA-256 albo równoważnym secret-keyed hashem. Zwykły globalny SHA-256 jest zabroniony.

PostgreSQL nie dostaje HMAC secretu tylko po to, aby kryptograficznie porównać ciphertext z hashem. DB wymusza ich pair-nullity i uniqueness, natomiast integration test warstwy domenowej wymusza, że obie reprezentacje zostały policzone z tego samego canonical inputu. Niezależny PATCH jednego z tych dwóch pól jest zabroniony.

## Duplicate PESEL lifecycle

Canonical hard boundary:

`UNIQUE (organization_id, pesel_lookup_hash) WHERE pesel_lookup_hash IS NOT NULL`.

Index obejmuje również zarchiwizowane rekordy. Archive nie zwalnia PESEL.

Efekt:
- drugi Student z tym samym PESEL w tym samym OSK nie może powstać,
- zarchiwizowanie pierwszego Studenta nie pozwala stworzyć jego duplikatu,
- ten sam PESEL może wystąpić w innym OSK, bo identity jest tenant-scoped w tym modelu,
- normalny lifecycle nie używa hard-delete do zwalniania PESEL.

Nie tworzymy hard unique na `first_name + last_name + birth_date` dla osoby bez PESEL. Taki zestaw nie jest unikalnym identyfikatorem osoby i mógłby blokować dwie rzeczywiście różne osoby. Aplikacja może później pokazywać ostrzeżenie o potencjalnym duplikacie, ale nie udajemy, że to bezpieczny DB identity key.

## Zmiany branchu

PESEL -> brak PESEL:
- `no_pesel_declared=true`,
- oba pola PESEL wyczyszczone,
- `birth_date` wymagane,
- całość w jednej transakcji,
- identity change audytowany.

Brak PESEL -> PESEL:
- `no_pesel_declared=false`,
- ciphertext + lookup hash zapisane jako jedna para,
- znana `birth_date` nie jest automatycznie kasowana,
- identity change audytowany.

PESEL -> inny PESEL:
- obie reprezentacje zastępowane atomowo,
- partial unique jest końcową granicą duplicate race,
- identity change audytowany.

Optimistic concurrency edycji Student jest teraz zamknięte w DB-TRN-003.

## Archive / restore w zakresie identity

DB-TRN-002 zamyka identity lifecycle:
- archive nie czyści PESEL/birth-date identity,
- archive nie zwalnia PESEL unique claim,
- restore używa tego samego trwałego Student row,
- restore nie tworzy drugiego identity record.

Wpływ archive na aktywne kursy i concurrency jest teraz doprecyzowany w DB-TRN-003.

## Migration design

Przy przyszłych migracjach:
1. dodać `no_pesel_declared` z bezpiecznym default `false`,
2. sprawdzić spójność istniejących par ciphertext/hash,
3. **nie** ustawiać automatycznie `no_pesel_declared=true` tylko dlatego, że PESEL jest pusty,
4. wykryć duplikaty non-NULL PESEL hash per OSK, również archived,
5. nie kasować ani nie merge'ować ich po cichu,
6. dodać row checks i partial unique,
7. sprawdzić każdy Student z istniejącym CourseEnrollment,
8. formalny Student bez poprawnej identity branch -> migration FAIL / jawna data remediation,
9. dodać deferrable formal-identity guards.

Pre-course incomplete Student może legalnie pozostać po migracji, o ile nie ma formalnego CourseEnrollment.

## Quality gate DB-TRN-002

Sprawdzono:
- prawna reguła PESEL albo data urodzenia dla osoby bez PESEL jest chroniona przed formalnym kursem — **PASS**,
- potwierdzona możliwość utworzenia profilu bez kursu nie została zepsuta — **PASS**,
- `no_pesel_declared` odróżnia jawny brak PESEL od brakujących danych — **PASS**,
- ciphertext/hash muszą być zapisywane i czyszczone jako para — **PASS**,
- formalny CourseEnrollment nie może wskazywać incomplete identity — **PASS DESIGN**,
- Student z formalną historią nie może później zostać zdegradowany do incomplete identity — **PASS DESIGN**,
- PESEL jest unikalny per OSK również przez archive — **PASS**,
- ten sam PESEL w dwóch różnych OSK nie jest błędnie blokowany globalnie — **PASS**,
- name + birth date nie zostały użyte jako fałszywy hard unique — **PASS**,
- migracja nie zgaduje `no_pesel` na podstawie NULL — **PASS**,
- plaintext PESEL nadal nie jest persistence field — **PASS**,
- DB-TRN-003..008 nie zostały naprawione przy okazji — **PASS**,
- `core-schema.yml` i `docs/87` nie zostały zmienione — **PASS**,
- migracje Laravel nie zostały utworzone — **PASS**,
- DB4_5 ani późniejsze slice'y nie zostały rozpoczęte — **PASS**.

**GATE DB-TRN-002: PASS.**

---

# DB-TRN-003 — PASS: Student concurrency + archive/restore lifecycle

## Problem z diagnozy

Publiczny kontrakt `Student` zwraca `version`, a `PATCH /students/{studentId}` używa `If-Match`, natomiast physical `students` nie miał concurrency root. Archive i restore były osobnymi komendami, ale brakowało wspólnego lock/version contract oraz jednoznacznej odpowiedzi, co dzieje się z aktywnym kursem przy archive.

Najgroźniejszy race był następujący:
1. proces A sprawdza, że Student jest aktywny i zaczyna tworzyć CourseEnrollment,
2. proces B archiwizuje Studenta,
3. oba procesy commitują,
4. powstaje archived Student z nowym aktywnym formalnym kursem.

Drugie ryzyko to zwykły lost update przy dwóch równoległych PATCH-ach albo PATCH vs archive.

## Decyzja canonical — jeden concurrency root

Do `students` dodajemy:

`version bigint NOT NULL DEFAULT 1 CHECK (version >= 1)`.

`students.version` jest concurrency root dla:
- edycji profilu,
- edycji identity branch,
- archive,
- restore.

Materialna zmiana zwiększa version dokładnie raz. State-idempotent no-op nie zwiększa version.

`archived_at` i `archived_by_user_id` nie mogą być ustawiane ani czyszczone zwykłym profile PATCH. Do lifecycle służą wyłącznie dedykowane archive/restore commands.

## PATCH / Student update

Mutujący profile command:
1. pobiera expected version z `If-Match`,
2. lockuje Student `FOR UPDATE`,
3. dopiero po locku porównuje expected version,
4. stale version -> conflict/precondition failed bez partial write,
5. stosuje zmianę,
6. ponownie przechodzi DB-TRN-001/002 guards,
7. zwiększa `version` dokładnie raz,
8. sensitive/material change zapisuje audit/outbox w tej samej transakcji.

Dwa PATCH-e z tym samym expected version nie mogą oba zacommitować.

Sam fakt, że Student jest archived, nie jest fizycznym powodem do utraty możliwości korekty danych historycznych. Database contract dopuszcza korektę business fields na archived row, jeżeli warstwa autoryzacji/UI na to zezwala, ale taka korekta nadal nie może zmieniać archive state i musi przejść version + identity guards.

Stage-3 OpenAPI już referencjonuje `If-Match` na Student PATCH. Machine contract DB4_4 traktuje expected version jako wymagany dla mutacji. Dokładne oznaczenie HTTP `required`/`428` pozostaje do synchronizacji acceptance/API w Stage 5; nie osłabiamy z tego powodu fizycznego concurrency contract.

## Archive Student — bez ukrytej zmiany kursu

Archive nie jest aliasem „anuluj wszystkie kursy”. Nie może:
- ustawiać `cancelled_at`,
- ustawiać `interrupted_at`,
- ustawiać `completed_at`,
- zmieniać `training_stage`,
- usuwać CourseEnrollment,
- usuwać Sessions/Attendance/Ledger.

W DB-TRN-003 przyjmujemy konserwatywny minimalny predicate kursu otwartego:

`completed_at IS NULL AND interrupted_at IS NULL AND cancelled_at IS NULL`.

Jeżeli Student ma choć jeden taki CourseEnrollment, archive kończy się conflict i niczego nie zmienia.

Pełna macierz Course lifecycle należy do DB-TRN-004. Tam predicate może zostać doprecyzowany, ale nie wolno osłabić zasady: **archive Studenta nie ma hidden side effect na stan formalnego kursu**.

Historyczne terminalne kursy pozostają przypięte do archived Student.

## Course create vs archive — jedna granica serializacji

CourseEnrollment create i Student archive lockują ten sam `students` row `FOR UPDATE`.

Course create po locku wymaga:
- `archived_at IS NULL`,
- formal identity z DB-TRN-002,
- same-tenant integrity z DB-TRN-001.

Deferrable constraint trigger albo równoważny transactional DB guard sprawia również, że import/direct SQL nie może stworzyć nowego formalnego enrollmentu dla archived Student.

Race ma tylko dwa legalne wyniki:
- Course create commitował pierwszy -> archive po locku widzi otwarty kurs i jest odrzucony,
- archive commitował pierwszy -> Course create po locku widzi archived Student i jest odrzucony.

Stan `archived Student + newly open course` nie może zostać zacommitowany.

## Archive transaction

Dla aktywnego Studenta:
1. claim Idempotency-Key,
2. lock Student `FOR UPDATE`,
3. tenant/permission check,
4. sprawdzenie braku otwartego CourseEnrollment po locku,
5. ustawienie `archived_at` i `archived_by_user_id`,
6. `version + 1`,
7. audit + outbox,
8. commit.

Jeżeli Student już jest archived, ponowny archive jest state-idempotent no-op i nie zwiększa version.

PESEL/birth-date identity, kursy, sesje, attendance i ledger nie są modyfikowane.

## Restore transaction

Restore używa tego samego durable Student row.

Dla archived Student:
1. claim Idempotency-Key,
2. lock Student `FOR UPDATE`,
3. tenant/permission check,
4. clear `archived_at` i `archived_by_user_id`,
5. `version + 1`,
6. audit + outbox,
7. commit.

Jeżeli Student już jest aktywny, restore jest state-idempotent no-op bez version bump.

Restore:
- nie tworzy nowego Student ID,
- nie otwiera cancelled/completed/interrupted CourseEnrollment,
- nie tworzy kursu,
- nie zmienia PESEL unique claim,
- nie rebinduje historycznych formalnych rekordów.

## Races edit/archive/restore

Wszystkie trzy rodziny mutacji lockują ten sam Student row.

Jeżeli PATCH commitnie przed archive, archive widzi najnowszy stan i może następnie zarchiwizować go, zwiększając version kolejny raz. Nie ma lost update.

Jeżeli archive commitnie pierwszy, wcześniejszy PATCH z old expected version po uzyskaniu locka dostaje stale-version conflict i nie może nadpisać archived state.

Archive i restore również serializują się na Student row i każdy command ocenia stan dopiero po locku.

## Learning access — świadomie poza tym krokiem

DB-TRN-003 **nie** definiuje jeszcze, czy archive Student ma suspendować learning account/licencję. To należy do DB4_6.

W tym slice:
- nie kasujemy learning account,
- nie revoke'ujemy licencji jako ukrytego efektu,
- restore nie reaktywuje dostępu jako ukrytego efektu.

DB4_6 musi później zamknąć ten lifecycle jawnie.

## Migration design

Przy późniejszych migracjach:
1. dodać `students.version bigint NOT NULL DEFAULT 1 CHECK >= 1`,
2. legacy rows inicjalizować na 1,
3. wykryć archived Student z kursem spełniającym minimalny open predicate,
4. taki konflikt -> migration FAIL / jawna reviewed remediation; nie auto-cancel,
5. zainstalować DB guard wymagający nonarchived Student przy course insert/rebind,
6. zainstalować archive guard przeciw otwartemu kursowi,
7. podpiąć profile PATCH/archive/restore do jednego version root,
8. uruchomić race/invariant tests.

Nie generujemy jeszcze migracji Laravel.

## Quality gate DB-TRN-003

Sprawdzono:
- `students.version` jest jednoznacznym concurrency root — **PASS DESIGN**,
- stale PATCH nie może nadpisać nowszego Student state — **PASS**,
- dwa PATCH-e z tym samym expected version nie mogą oba commitować — **PASS**,
- edit/archive/restore serializują się na tym samym Student row — **PASS**,
- archive nie zmienia kursu w tle — **PASS**,
- Student z otwartym kursem nie może zostać zarchiwizowany — **PASS DESIGN**,
- archived Student nie może dostać nowego CourseEnrollment — **PASS DESIGN**,
- race course-create vs archive nie może pozostawić archived Student + open course — **PASS DESIGN**,
- terminalna historia kursów/sesji/ledger pozostaje nietknięta — **PASS**,
- restore używa tego samego row i nie otwiera historycznych kursów — **PASS**,
- DB-TRN-002 PESEL/identity archive rules pozostają zachowane — **PASS**,
- pełna Course lifecycle matrix nie została rozwiązana przy okazji — **PASS SCOPE**, pozostaje DB-TRN-004,
- learning access/license lifecycle nie został rozwiązany — **PASS SCOPE**, pozostaje DB4_6,
- `core-schema.yml` i `docs/87` nie zostały zmienione — **PASS**,
- migracje Laravel nie zostały utworzone — **PASS**,
- DB4_5 ani późniejsze slice'y nie zostały rozpoczęte — **PASS**.

**GATE DB-TRN-003: PASS.**

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

Wykonano wyłącznie same-tenant integrity. Bounded-context spec nie naprawił pozostałych siedmiu blockerów.

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

---

# Quality gate DB4_4_STEP_3 — DB-TRN-002

Wykonano wyłącznie formal Student identity branch + PESEL duplicate/archive lifecycle.

Self-audit:
- bounded-context machine source zaktualizowany bez zmian aggregate — **PASS**,
- pre-course Student bez PESEL nadal może istnieć jako incomplete profile — **PASS PRESERVATION**,
- formal CourseEnrollment wymaga jednej z dwóch legalnych identity branches — **PASS DESIGN**,
- `no_pesel_declared` rozróżnia jawny brak PESEL od niekompletnych danych — **PASS**,
- PESEL ciphertext/hash pair jest atomowa i nie może być połowicznie NULL — **PASS**,
- PESEL partial unique działa per OSK i obejmuje archived rows — **PASS**,
- archive nie zwalnia PESEL i restore nie tworzy nowej identity — **PASS**,
- nie wprowadzono hard unique na name+birth_date — **PASS**,
- migracja nie zgaduje `no_pesel` i nie naprawia duplicate przez silent merge/delete — **PASS**,
- Student version/edit/archive concurrency nie zostały rozwiązane — **PASS SCOPE** w tamtym kroku,
- course lifecycle nie został zmieniony — **PASS SCOPE**, pozostaje DB-TRN-004,
- `specs/database/core-schema.yml` bez zmian — **PASS**,
- `docs/87-physical-database-schema.md` bez zmian — **PASS**,
- DB4_5+ bez zmian — **PASS**,
- migracje Laravel/UI/feature implementation — **NIE ROZPOCZĘTO**.

**FINAL GATE DB-TRN-002: PASS.**

---

# Quality gate DB4_4_STEP_4 — DB-TRN-003

Wykonano wyłącznie Student concurrency + archive/restore lifecycle.

Self-audit:
- bounded-context machine source zaktualizowany bez zmian aggregate — **PASS**,
- Student ma jeden version root dla profile edit/archive/restore — **PASS**,
- stale expected version nie może spowodować partial/lost update — **PASS**,
- dedicated archive/restore pozostają idempotent i serializowane — **PASS**,
- archive nie mutuje CourseEnrollment ani formalnej historii — **PASS**,
- otwarty kurs blokuje archive — **PASS DESIGN**,
- Course create i archive serializują się na Student row — **PASS DESIGN**,
- archived Student nie może dostać nowego formalnego kursu — **PASS DESIGN**,
- restore nie otwiera ani nie tworzy kursu — **PASS**,
- DB-TRN-002 identity/PESEL semantics nie zostały osłabione — **PASS**,
- pełna macierz Course lifecycle nie została zaprojektowana — **PASS SCOPE**, pozostaje DB-TRN-004,
- learning access/license archive effect nie został zaprojektowany — **PASS SCOPE**, pozostaje DB4_6,
- `specs/database/core-schema.yml` bez zmian — **PASS**,
- `docs/87-physical-database-schema.md` bez zmian — **PASS**,
- DB4_5+ bez zmian — **PASS**,
- migracje Laravel/UI/feature implementation — **NIE ROZPOCZĘTO**.

**FINAL GATE DB-TRN-003: PASS.**

## Aktualna kolejność napraw

1. `DB-TRN-001` — **PASS**.
2. `DB-TRN-002` — **PASS**.
3. `DB-TRN-003` — **PASS**.
4. `DB-TRN-004` — Course lifecycle/stage/cancel/restore history — **NEXT**.
5. `DB-TRN-005` — requirement context/profile reproducibility.
6. `DB-TRN-006` — attendance -> ledger exactly-once.
7. `DB-TRN-007` — external training projection/history.
8. `DB-TRN-008` — course PKK persistence boundary.

**Następny pojedynczy krok: tylko `DB-TRN-004` -> self-audit -> gate -> STOP przed `DB-TRN-005`.**