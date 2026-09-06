# 108. Stage 4 — Students / Courses / Training Ledger physical invariant audit

Data: 2026-09-06

**Status:** `DB4_4 IN PROGRESS / DB-TRN-001 PASS / DB-TRN-002 PASS / DB-TRN-003 PASS / DB-TRN-004 PASS / DB-TRN-005 PASS / 3 P1 BLOCKERS OPEN`

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

# DB-TRN-004 — PASS: Course lifecycle, stage history, cancel/restore

## Problem z diagnozy

`course_enrollments` miał równolegle `training_stage`, `completed_at`, `interrupted_at`, `cancelled_at` i `version`, ale bez zamkniętej fizycznej macierzy. Możliwy był więc stan sprzeczny, np. `training_stage=training_completed` bez `completed_at`, jednoczesne completion + cancellation albo utrata informacji, przez jakie etapy kurs przechodził.

Potwierdzone źródła dają siedem wartości `training_stage`, osobne operacje API `cancel`, `restore`, `stage-transitions`, optimistic concurrency dla zwykłej edycji oraz wymóg historii. Jednocześnie ekran mówi `transition_rules: TO_VERIFY`, więc nie wolno nam wymyślić sztucznej sekwencji `theory -> practice -> ...` jako twardego constraintu.

## Decyzja canonical — stage i lifecycle są osobnymi wymiarami

`training_stage` opisuje etap workflow, natomiast lifecycle kursu wynika z terminalnych timestampów. Nie dokładamy drugiej mutowalnej kolumny `status`, która mogłaby rozjechać się z timestampami.

Canonical lifecycle:
- `active` — `completed_at`, `interrupted_at`, `cancelled_at` są wszystkie `NULL`,
- `completed` — tylko `completed_at` jest non-NULL i `training_stage='training_completed'`,
- `interrupted` — tylko `interrupted_at` jest non-NULL i stage nie jest `training_completed`,
- `cancelled` — tylko `cancelled_at` jest non-NULL, `cancelled_by_user_id` jest non-NULL i stage nie jest `training_completed`.

DB wymusza maksymalnie jeden terminalny timestamp. Dodatkowo `training_stage='training_completed'` jest równoważne obecności `completed_at`. Dzięki temu stage i zakończenie nie mogą się rozjechać.

Predicate `active` staje się finalnym doprecyzowaniem minimalnego `open course predicate` z DB-TRN-003.

## Stage catalog bez wymyślania niepotwierdzonego grafu

Dozwolone wartości pozostają dokładnie zgodne z potwierdzonym ekranem:
- `unassigned`,
- `theory`,
- `practice`,
- `documentation`,
- `word_exam`,
- `supplementary_training`,
- `training_completed`.

Dla aktywnego kursu jawna komenda stage transition może przechodzić pomiędzy potwierdzonymi **nieterminalnymi** wartościami. Nie kodujemy na sztywno kolejności, której nie zweryfikowaliśmy.

Target `training_completed` nie jest zwykłą zmianą stringa. Kieruje do atomowej semantyki completion: stage + `completed_at` zmieniają się razem.

## Jeden concurrency root dla CourseEnrollment

`course_enrollments.version` jest canonical concurrency root wszystkich materialnych mutacji kursu i ma finalnie typ `bigint NOT NULL DEFAULT 1 CHECK >= 1`.

Update, stage transition, complete, interrupt, cancel, restore i correction:
- lockują CourseEnrollment `FOR UPDATE`,
- porównują expected version po locku,
- stale version kończy się conflict/precondition failed bez częściowego zapisu,
- udana materialna mutacja zwiększa version dokładnie raz.

Transport retry dla commandów z Idempotency-Key zwraca wcześniejszy wynik i nie nakłada efektu drugi raz. Idempotency nie zastępuje optimistic concurrency przy świeżej, ale już nieaktualnej intencji użytkownika.

Dokładny HTTP surface expected-version dla lifecycle commandów wymaga późniejszej synchronizacji Stage 5; fizycznego concurrency contractu nie osłabiamy.

## Append-only historia Course lifecycle

Dodajemy projekt tabeli `course_enrollment_lifecycle_events` jako formalny append-only trail. Każdy event przechowuje co najmniej:
- tenant i CourseEnrollment,
- `event_type`,
- from/to lifecycle state,
- from/to training stage,
- `course_version_before` / `course_version_after`,
- actor, czas i reason tam, gdzie wymagany,
- opcjonalny redacted payload korekty,
- opcjonalne `correction_of_event_id` dla jawnej korekty faktu lifecycle.

Każda materialna zmiana CourseEnrollment ma dokładnie jeden odpowiadający event w tej samej transakcji. Unique `(organization_id, course_enrollment_id, course_version_after)` oraz transactional/deferrable guard wiążą row-version z historią.

Historia jest append-only. Normalna aplikacja nie aktualizuje i nie usuwa dawnych eventów.

## Completion

Completion jest dozwolone tylko z `active` i atomowo:
- ustawia `training_stage='training_completed'`,
- ustawia `completed_at`,
- pozostawia `interrupted_at`, `cancelled_at`, `cancelled_by_user_id` puste,
- zwiększa version raz,
- zapisuje event `completed`, audit i outbox.

DB-TRN-004 **nie** rozstrzyga jeszcze, czy kurs spełnił wymagane godziny, wymagania rule engine i egzaminy. To pozostaje odpowiednio DB-TRN-005, DB-TRN-006 i DB4_7.

## Interruption

Przerwanie jest formalnym zakończeniem bieżącego szkolenia bez twierdzenia, że zostało ukończone. Z aktywnego kursu:
- zachowuje ostatni nieterminalny `training_stage`,
- ustawia `interrupted_at`,
- wymaga reason,
- zwiększa version i zapisuje append-only event `interrupted`.

Normalne `restore` nie czyści `interrupted_at`. Jeżeli przerwanie było błędem, powrót do aktywnego kursu jest wyjątkową jawnie audytowaną `lifecycle correction`, a nie zwykłym restore.

## Cancel

`POST .../cancel` jest lifecycle commandem, nie hard-delete. Z aktywnego kursu:
- zachowuje ostatni nieterminalny stage,
- ustawia `cancelled_at` i `cancelled_by_user_id`,
- wymaga reason,
- zwiększa version raz,
- zapisuje `cancelled` event.

Nie usuwa ani nie przepisuje Sessions, Attendance, Ledger, Exam, Finance ani PKK history. Fresh cancel już cancelled course jest konfliktem; retry z tym samym Idempotency-Key nie powtarza efektu.

Completed albo interrupted course nie może być normalnie anulowany po fakcie. Ewentualne sprostowanie terminalnego faktu należy do jawnego correction flow.

## Restore

Normalny restore jest jednoznacznie ograniczony do `cancelled -> active`.

Nie otwiera:
- completed,
- interrupted.

Restore cancelled course:
1. lockuje najpierw Student, potem CourseEnrollment,
2. wymaga nonarchived Student oraz nadal poprawnej formal identity,
3. czyści `cancelled_at` i `cancelled_by_user_id`,
4. zachowuje poprzedni stage,
5. zwiększa version raz,
6. dopisuje event `restored`.

Nie tworzy nowego kursu ani Studenta i nie zmienia historycznych sessions/ledger/external training.

Stały lock order `Student -> Course` zamyka race restore vs Student archive. Nie może zostać zacommitowany stan `archived Student + restored active Course`.

## Closed course correction

Zwykły PATCH terminalnego kursu jest odrzucany. Ekran już wymaga correction mode, więc fizyczny kontrakt to respektuje.

Business-field correction bez zmiany lifecycle:
- wymaga expected version, actor i reason,
- zachowuje terminal state/timestamps,
- zwiększa version,
- dopisuje `closed_course_corrected` z redacted before/after projection,
- nie usuwa wcześniejszej historii.

Wyjątkowa korekta samego lifecycle:
- nie jest aliasem normalnego restore,
- wymaga reference do korygowanego eventu,
- reason + before/after projection,
- finalny row musi przejść pełną lifecycle matrix,
- jeżeli finalnie wraca do `active`, Student musi być nonarchived i nadal spełniać DB-TRN-002.

## Formal activity po zamknięciu

Nowej `TrainingSession` nie można tworzyć dla `completed`, `interrupted` ani `cancelled` CourseEnrollment. Istniejąca formalna historia nie jest usuwana.

Dokładne session completion / attendance / ledger credit i korekty pozostają DB-TRN-006.

## Migration design

Przy późniejszym generowaniu migracji:
1. ujednolicić `course_enrollments.version` do bigint >= 1,
2. zweryfikować katalog istniejących stage,
3. wykryć sprzeczne kombinacje terminal timestamps,
4. wykryć rozjazd `training_completed <-> completed_at`,
5. wykryć niespójne `cancelled_at/cancelled_by`,
6. sprzeczny legacy row -> FAIL / jawna reviewed remediation, bez zgadywania historii,
7. utworzyć append-only `course_enrollment_lifecycle_events`,
8. dla każdego istniejącego kursu utworzyć tylko `migration_baseline` z aktualnym stanem — bez wymyślania dawnych actorów i chronologii,
9. dodać row checks i guard `version <-> exactly one lifecycle event`,
10. podpiąć update/stage/complete/interrupt/cancel/restore/correction do wspólnego Course version root,
11. dodać guard, że nowa TrainingSession wymaga aktywnego kursu,
12. uruchomić race/history/negative tests.

Nie generujemy jeszcze migracji Laravel.

## Quality gate DB-TRN-004

Sprawdzono:
- istnieje jedna sprzecznościowo zamknięta macierz `active/completed/interrupted/cancelled` — **PASS DESIGN**,
- `training_completed` i `completed_at` nie mogą się rozjechać — **PASS DESIGN**,
- terminalne timestamps są mutually exclusive — **PASS DESIGN**,
- siedem potwierdzonych stage zostało zachowanych, bez wymyślenia niepotwierdzonej kolejności — **PASS PRESERVATION**,
- stage/lifecycle history jest append-only i same-tenant — **PASS DESIGN**,
- każda materialna mutacja ma jeden Course version root + jeden event — **PASS DESIGN**,
- update/stage/cancel/restore races są serializowane; stale intent nie może cicho wygrać — **PASS DESIGN**,
- normalny restore otwiera tylko cancelled, nie completed/interrupted — **PASS**,
- restore nie może aktywować kursu z archived Student — **PASS DESIGN**,
- closed-course correction zachowuje dawną historię — **PASS**,
- Student archive nadal nie zmienia Course w tle — **PASS**,
- DB-TRN-005..008 nie zostały rozwiązane przy okazji — **PASS SCOPE**,
- `specs/database/core-schema.yml` bez zmian — **PASS**,
- `docs/87-physical-database-schema.md` bez zmian — **PASS**,
- DB4_5+ bez zmian — **PASS**,
- migracje Laravel/UI/feature implementation — **NIE ROZPOCZĘTO**.

**GATE DB-TRN-004: PASS.**

---

# DB-TRN-005 — PASS: requirement context/profile reproducibility and correction history

## Problem z diagnozy

Legal/product spec wymaga, aby wymagania kursu były przeliczalne na podstawie trwałych faktów, m.in. target category, held categories, `state_theory_passed`, exemption basis i evidence reference. Dotychczasowy model posiadał `training_requirement_profiles.input_snapshot` oraz `course_exemption_decisions`, ale nie miał kompletnego canonical ownera current source facts, gwarancji świeżości current projection ani trwałej identyfikacji dokładnej wersji artefaktu rule engine.

Bez tego po czasie nie dałoby się deterministycznie odpowiedzieć: „dlaczego ta wersja kursu miała takie wymagania?” ani bezpiecznie skorygować zamkniętego kursu bez retroaktywnego przepisywania historii.

## Jednoznaczny owner source facts

Nie duplikujemy `target_category` w requirement context. Canonical owner pozostaje jeden:
- `target category` -> `course_enrollments.driving_category_id`,
- `training_type` -> `course_enrollments.training_type`,
- `started_at` -> CourseEnrollment, gdy wpływa na wybór reguł,
- `state_theory_passed` i context evidence -> `course_requirement_contexts`,
- `held_categories` -> `course_requirement_context_held_categories`,
- explicit exemption basis/evidence -> current non-revoked `course_exemption_decisions`,
- manual override -> current non-revoked `course_requirement_override_decisions`.

Computed requirement outputs nigdy nie są używane jako source facts i nie wolno z nich odgadywać brakujących danych wejściowych.

## `requirements_revision` — freshness epoch, nie concurrency root

Do CourseEnrollment dochodzi `requirements_revision bigint NOT NULL CHECK >= 1`.

To nie jest drugi optimistic concurrency root. Wszystkie requirement-affecting commandy nadal serializują się na `course_enrollments.version` i Course row `FOR UPDATE`.

`requirements_revision` rośnie dokładnie raz, gdy zmienia się rule-relevant source fact, exemption, override albo jawnie wybrany rule set. Nie rośnie przy zwykłym stage transition, cancel/restore, zmianie instruktora/lokalizacji ani innym polu bez wpływu na requirements.

Current profile musi mieć dokładnie tę samą rewizję co CourseEnrollment.

## Immutable rule-set identity

Dodajemy globalny katalog `training_requirement_rule_sets` z:
- `version` jako trwałym identyfikatorem,
- jurisdiction,
- `content_hash` canonical rule artifact,
- source reference,
- effective/published timestamps.

Profile wskazuje konkretną wersję przez FK. Raz użytej wersji nie można później podmienić na inną treść. DB-TRN-005 nie wymyśla polityki wyboru przyszłej wersji prawa; zamyka reprodukowalność: wybrana wersja musi być jawna, trwała i hash-identifiable.

## Durable current context i held categories

`course_requirement_contexts` ma dokładnie jeden current row na CourseEnrollment i nie posiada niezależnego version. Current held categories są relacyjne i znormalizowane w `course_requirement_context_held_categories`, a nie ukryte jako dowolny JSON array.

Historyczny zestaw inputs jest zachowany w immutable snapshot każdej kalkulacji.

## Exemption decisions

`course_exemption_decisions` zostaje wzmocnione jako audytowalna historia:
- business fields immutable po insert,
- maksimum jeden current non-revoked row na Course,
- replacement = revoke starego current + insert nowego pod tym samym Course lockiem,
- revoke wymaga actor + reason,
- current exemption basis nie jest duplikowany do context row.

Nie ma nieaudytowanego booleanu „zwolnij z teorii”.

## Manual override

Istniejąca product spec dopuszcza manual override tylko przy uprawnieniu i pełnym audycie. Dlatego dostaje osobny `course_requirement_override_decisions` zamiast ukrytego checkboxa.

Override:
- wymaga permission, actor, reason, timestamp,
- ma allowlist dokładnie sześciu requirement outputs,
- minute values muszą być nieujemne,
- maksimum jeden current non-revoked override per Course,
- replacement/revoke zachowuje historyczne decyzje,
- base rule-engine output jest zachowany osobno od effective output po override.

## TrainingRequirementProfile jako immutable decision trail

Każdy `training_requirement_profiles` przechowuje:
- `requirements_revision`,
- Course version po kalkulacji,
- `rule_set_version`,
- trigger code,
- calculation reason,
- actor/system origin,
- normalized `input_snapshot`,
- `base_output_snapshot`,
- `effective_output_snapshot`,
- optional manual override decision id,
- effective relational output columns,
- calculated/superseded timestamps.

Business contents starego profile nie są aktualizowane. Normalną mutacją historyczną może być tylko `superseded_at`.

Partial unique utrzymuje maksimum jeden current profile, a unique `(organization_id, course_enrollment_id, requirements_revision)` zabrania dwóch decyzji dla tej samej rewizji.

## Snapshot contract

Input snapshot zawiera dokładnie użyte normalized inputs: category id/code, training type, course start, `state_theory_passed`, context evidence, posortowany held-category set, current exemption decision/basis/evidence, current override decision/payload oraz rule-set version + content hash.

PESEL i PKK są zabronione w snapshot, bo nie są potrzebne do requirement calculation.

`base_output_snapshot` zachowuje wynik rule engine przed override. `effective_output_snapshot` zachowuje rezultat po opcjonalnym override. Relacyjne effective columns są bieżącą projekcją API i integration test ma wykazać ich zgodność z effective snapshot.

## Current projection freshness guard

Finalny committed CourseEnrollment musi mieć dokładnie jeden current TrainingRequirementProfile i:

`current_profile.requirements_revision = course_enrollments.requirements_revision`.

At-most-one zapewnia partial unique. At-least-one + revision match wymusza deferrable constraint trigger albo równoważny transactional DB guard.

Direct SQL lub backend bug, który zmieni context albo revision bez nowego profile, nie może przejść finalnego inwariantu.

## Course create

API już deklaruje „Course enrollment created and legal requirements calculated”. Dlatego nowy Course w tej samej transakcji dostaje:
- `requirements_revision=1`,
- requirement context,
- explicit held categories, jeżeli zostały podane,
- jawnie wybraną immutable rule-set version,
- current profile dla revision 1.

DB-TRN-008 nadal jest właścicielem required PKK atomic persistence boundary.

## Requirement context update

`POST .../requirement-context`:
1. claim Idempotency-Key,
2. lock CourseEnrollment `FOR UPDATE`,
3. expected Course version check,
4. update context/held set,
5. `requirements_revision + 1`,
6. supersede current profile,
7. calculate + insert new current profile,
8. Course `version + 1` dokładnie raz,
9. DB-TRN-004 `updated` albo `closed_course_corrected` event,
10. audit + outbox w tej samej transakcji.

Semantic no-op nie tworzy sztucznej rewizji ani nowego profile.

## Course fields wpływające na rules

Zmiana category/training type/start używa istniejącego DB-TRN-004 Course lock/version. Jeśli field wpływa na rules, recalculation jest częścią tej samej logical transaction i Course version zwiększa się tylko raz.

Internal-exam compatibility pozostaje DB4_7, external-training compatibility pozostaje DB-TRN-007.

## Exemption / override / rule-set refresh

Każda z tych zmian:
- lockuje ten sam Course,
- wymaga expected Course version,
- zwiększa `requirements_revision` raz,
- tworzy nowy immutable profile,
- zwiększa Course version raz,
- zapisuje DB-TRN-004 history + audit/outbox.

Dwa równoległe commandy z tą samą expected Course version nie mogą oba wygrać.

## Closed course correction

Po terminalnym zamknięciu requirements nie mogą być edytowane inline. Obowiązuje DB-TRN-004 correction mode z actor, reason i expected version.

Korekta dopisuje nowy profile, nie usuwa starych profili i nie usuwa Sessions, Attendance, Ledger ani ExamAttempt. Jeżeli wpływa na dokumenty pochodne, oznacza potrzebę ich regeneracji; dokładny pipeline dokumentowy jest poza DB-TRN-005.

## Completion boundary

DB-TRN-005 zamyka tylko freshness wymagań przed completion:
- current profile istnieje,
- jego requirements revision jest aktualna,
- rule-set identity jest poprawna.

Wymagane minuty pozostają DB-TRN-006, a spełnienie internal exam requirements pozostaje DB4_7.

## Migration design

Migracja:
1. rejestruje znany immutable rule artifact + content hash,
2. dodaje `requirements_revision` tymczasowo nullable na czas legacy precheck,
3. tworzy context, held-category i override structures,
4. wzmacnia exemption history,
5. rozszerza profiles o revision/course version/snapshots/actor/trigger/rule-set FK,
6. rekonstruuje source facts tylko z kompletnego, zweryfikowanego starego `input_snapshot` albo innych jawnych źródeł,
7. nigdy nie odgaduje held categories, theory-passed ani exemption basis z output flags,
8. unreconstructible source facts -> migration FAIL / explicit reviewed remediation,
9. nie przypisuje fikcyjnej rule-set version, jeżeli nie wiadomo, jaki artefakt wyliczył historyczny profile,
10. dopiero po weryfikacji włącza current-profile partial unique i revision freshness guard.

Nie generujemy jeszcze migracji Laravel.

## Self-audit jakości wykonania kroku

W tym kroku self-audit dwukrotnie zadziałał jako realna bramka, a nie formalność:
- pierwszy machine write DB-TRN-005 skrócił wcześniejsze sekcje DB-TRN-001..004; regresję wykryto przed gate PASS i kolejny commit przywrócił pełny machine contract,
- pierwsza aktualizacja tego audit doc skróciła wcześniejszą narrację; końcowa kontrola diffu wykryła to przed zamknięciem etapu i dokument został przywrócony w pełnej wersji z dopisanym DB-TRN-005.

Żadna z tych wersji pośrednich nie jest traktowana jako finalny canonical rezultat kroku.

## Quality gate DB-TRN-005

Sprawdzono:
- target category ma jednego canonical ownera — **PASS**,
- durable current source facts są jednoznaczne — **PASS DESIGN**,
- held categories są znormalizowane — **PASS DESIGN**,
- explicit exemption ma current/history ownera — **PASS DESIGN**,
- manual override jest jawny i audytowalny — **PASS DESIGN**,
- rule-set version wskazuje immutable hashed artifact — **PASS DESIGN**,
- `requirements_revision` dowodzi freshness bez tworzenia drugiego concurrency root — **PASS DESIGN**,
- każda source-fact/rule-set zmiana tworzy dokładnie jeden nowy immutable profile — **PASS DESIGN**,
- input/base-output/effective-output/actor/reason/evidence trail jest kompletny — **PASS DESIGN**,
- requirement commandy i Course PATCH serializują się na tym samym Course version root — **PASS DESIGN**,
- stale source-fact change nie może zacommitować ze starym current profile — **PASS DESIGN**,
- closed-course correction zachowuje wcześniejszą requirement i formal history — **PASS**,
- recalculation nie usuwa training/attendance/exam history — **PASS**,
- completion freshness jest zamknięte bez przedwczesnego rozwiązania godzin i egzaminów — **PASS SCOPE**,
- DB-TRN-006..008 pozostają nierozwiązane — **PASS SCOPE**,
- pełny machine contract DB-TRN-001..004 zachowany — **PASS PRESERVATION**,
- pełna wcześniejsza narracja audit DB-TRN-001..004 zachowana — **PASS PRESERVATION**,
- `specs/database/core-schema.yml` bez zmian — **PASS**,
- `docs/87-physical-database-schema.md` bez zmian — **PASS**,
- DB4_5+ bez zmian — **PASS**,
- migracje Laravel/UI/feature implementation — **NIE ROZPOCZĘTO**.

**GATE DB-TRN-005: PASS.**

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

---

# Quality gate DB4_4_STEP_5 — DB-TRN-004

Wykonano wyłącznie Course lifecycle/stage/cancel/restore history.

Self-audit:
- bounded-context machine source został zaktualizowany bez aggregate sync — **PASS**,
- jedna lifecycle matrix usuwa sprzeczne kombinacje completion/interruption/cancel — **PASS DESIGN**,
- `training_completed` jest atomowo związane z `completed_at` — **PASS DESIGN**,
- stage catalog zachowuje dokładnie potwierdzone wartości — **PASS PRESERVATION**,
- nie wymyślono strict transition graph mimo `TO_VERIFY` w screen spec — **PASS SCOPE**,
- append-only lifecycle history zachowuje from/to stage/state + course versions — **PASS DESIGN**,
- każda materialna mutacja ma dokładnie jeden version bump i jeden history event — **PASS DESIGN**,
- normalny restore dotyczy tylko cancelled course — **PASS**,
- completed/interrupted nie są normalnie reopenowane — **PASS**,
- Student archive vs course restore race jest zamknięty stałym lock order — **PASS DESIGN**,
- generic PATCH terminalnego kursu wymaga explicit correction mode — **PASS**,
- correction nie niszczy wcześniejszych eventów ani formalnej historii — **PASS**,
- nowa TrainingSession wymaga active Course, ale dokładne session/ledger semantics pozostają DB-TRN-006 — **PASS SCOPE**,
- completion eligibility nie została rozwiązana przed DB-TRN-005/006/DB4_7 — **PASS SCOPE**,
- DB-TRN-005..008 nadal pozostają otwarte — **PASS SCOPE**,
- `specs/database/core-schema.yml` bez zmian — **PASS**,
- `docs/87-physical-database-schema.md` bez zmian — **PASS**,
- DB4_5+ bez zmian — **PASS**,
- migracje Laravel/UI/feature implementation — **NIE ROZPOCZĘTO**.

**FINAL GATE DB-TRN-004: PASS.**

---

# Quality gate DB4_4_STEP_6 — DB-TRN-005

Wykonano wyłącznie requirement context/profile reproducibility.

Self-audit:
- target category ma jednego canonical ownera — **PASS**,
- trwałe current source facts i znormalizowane held categories są jednoznaczne — **PASS DESIGN**,
- `requirements_revision` jest freshness epoch, a nie drugim concurrency root — **PASS DESIGN**,
- immutable hashed `training_requirement_rule_sets` zamyka rule-version reproducibility — **PASS DESIGN**,
- exemption i manual override mają jawny current/history lifecycle — **PASS DESIGN**,
- każdy requirements revision ma jeden immutable profile z input/base/effective snapshots — **PASS DESIGN**,
- current profile revision musi odpowiadać Course requirements revision — **PASS DESIGN**,
- wszystkie requirement-affecting commandy serializują się na `course_enrollments.version` — **PASS DESIGN**,
- stale requirement mutation nie zostawia partial context/profile write — **PASS DESIGN**,
- closed-course correction używa DB-TRN-004 correction mode i nie kasuje formal history — **PASS**,
- recalculation nie usuwa Sessions/Attendance/Ledger/Exam history — **PASS**,
- DB-TRN-006 hour satisfaction i DB4_7 exam satisfaction nie zostały rozwiązane przy okazji — **PASS SCOPE**,
- DB-TRN-006..008 nadal pozostają open — **PASS SCOPE**,
- pełne wcześniejsze machine i narrative obligations DB-TRN-001..004 zostały zachowane po self-audit corrections — **PASS PRESERVATION**,
- `specs/database/core-schema.yml` bez zmian — **PASS**,
- `docs/87-physical-database-schema.md` bez zmian — **PASS**,
- DB4_5+ bez zmian — **PASS**,
- migracje Laravel/UI/feature implementation — **NIE ROZPOCZĘTO**.

**FINAL GATE DB-TRN-005: PASS.**

## Aktualna kolejność napraw

1. `DB-TRN-001` — **PASS**.
2. `DB-TRN-002` — **PASS**.
3. `DB-TRN-003` — **PASS**.
4. `DB-TRN-004` — **PASS**.
5. `DB-TRN-005` — **PASS**.
6. `DB-TRN-006` — attendance -> ledger exactly-once — **NEXT**.
7. `DB-TRN-007` — external training projection/history.
8. `DB-TRN-008` — course PKK persistence boundary.

**Następny pojedynczy krok: tylko `DB-TRN-006` -> self-audit -> gate -> STOP przed `DB-TRN-007`.**