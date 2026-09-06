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

Każdy composite FK używa `ON UPDATE RESTRICT / ON DELETE RESTRICT`. Opcjonalne relacje zachowują `MATCH SIMPLE`.

`training_session_attendance` dostaje własne `organization_id NOT NULL`, aby oba composite FK do Session i Student miały fizyczną same-tenant boundary. Migracja nie może cicho przepinać istniejących cross-tenant danych.

**GATE DB-TRN-001: PASS.**

---

# DB-TRN-002 — PASS: formal Student identity branch + duplicate lifecycle

`students` może istnieć jako pre-course profil bez PESEL, ale formalny `CourseEnrollment` wymaga jednej z dwóch kompletnych gałęzi: PESEL albo jawny brak PESEL + data urodzenia. `no_pesel_declared` odróżnia brak danych od formalnej deklaracji. PESEL ciphertext/hash są jedną atomową parą, a partial unique `(organization_id,pesel_lookup_hash)` obejmuje także archived rows.

Archive nie zwalnia PESEL; restore używa tego samego trwałego Student row. Nie używamy fałszywego hard unique na imię+nazwisko+data urodzenia.

**GATE DB-TRN-002: PASS.**

---

# DB-TRN-003 — PASS: Student concurrency + archive/restore lifecycle

`students.version bigint` jest jednym concurrency root edycji profilu, archive i restore. Mutacje lockują Student `FOR UPDATE`, stale expected version nie może wykonać partial write.

Course create i Student archive serializują się na tym samym Student row. Archive nie anuluje ani nie przerywa kursu w tle; aktywny CourseEnrollment blokuje archive. Restore nie otwiera historycznych courses.

**GATE DB-TRN-003: PASS.**

---

# DB-TRN-004 — PASS: Course lifecycle, stage history, cancel/restore

Canonical lifecycle to `active|completed|interrupted|cancelled`, wyliczany z terminal timestamps. `training_completed` jest atomowo związane z `completed_at`; terminal timestamps są mutually exclusive.

Course ma jeden `version` root, a każda materialna mutacja ma dokładnie jeden version bump i append-only `course_enrollment_lifecycle_event`. Normalny restore działa tylko `cancelled -> active`; completed/interrupted wymagają wyjątkowej correction, nie restore. Closed course generic PATCH jest zabroniony.

Nie wymyślono niepotwierdzonego strict stage graph; zachowano dokładnie potwierdzone siedem stage values.

**GATE DB-TRN-004: PASS.**

---

# DB-TRN-005 — PASS: requirement context/profile reproducibility and correction history

## Problem z diagnozy

Mieliśmy `training_requirement_profiles.input_snapshot` oraz `course_exemption_decisions`, ale brakowało odpowiedzi na cztery podstawowe pytania:
1. gdzie dokładnie żyją bieżące source facts,
2. jak stwierdzić, że current requirement profile nie jest stale,
3. jak dokładnie odtworzyć kalkulację po konkretnej wersji reguł,
4. jak poprawić wymagania na zamkniętym kursie bez przepisania historii.

Szczególnie niebezpieczne byłoby przechowywanie `target_category` równocześnie w CourseEnrollment i requirement context, bo dwa mutable current values mogłyby się rozjechać.

## Jednoznaczny owner source facts

Nie duplikujemy kategorii kursu. Canonical:
- `target category` -> `course_enrollments.driving_category_id`,
- `training_type` -> `course_enrollments.training_type`,
- `started_at` -> CourseEnrollment, gdy wpływa na wybór reguł,
- `state_theory_passed` + evidence -> nowy current `course_requirement_contexts`,
- `held_categories` -> znormalizowany current set `course_requirement_context_held_categories`,
- explicit exemption basis/evidence -> aktualny non-revoked `course_exemption_decisions`,
- manual override -> osobny audytowalny `course_requirement_override_decisions`.

Output starego requirement profile nigdy nie jest używany do „odgadywania” source facts.

## `requirements_revision` — freshness epoch, nie drugi concurrency root

Do CourseEnrollment dochodzi:

`requirements_revision bigint NOT NULL CHECK >= 1`.

To **nie jest drugi optimistic concurrency root**. Concurrency nadal należy wyłącznie do `course_enrollments.version`.

`requirements_revision` rośnie tylko wtedy, gdy zmienia się wejście wpływające na wymagania albo jawnie przeliczamy pod innym immutable rule set. Current profile musi mieć dokładnie tę samą rewizję.

Dzięki temu stage transition może zmienić Course version bez fałszywego oznaczania requirement profile jako stale, ale zmiana kategorii, held categories, teorii państwowej, exemption, override lub rule set nie może zacommitować bez nowego profilu.

## Immutable identity reguł

Dodajemy globalny katalog `training_requirement_rule_sets`:
- `version` jako trwały identyfikator,
- jurisdiction,
- `content_hash` canonical rule artifact,
- source reference,
- effective/published timestamps.

Wersja użyta przez profile musi wskazywać istniejący immutable row. Nie można później pod tym samym `rule_set_version` podmienić treści reguł.

DB-TRN-005 nie wymyśla polityki, która przyszła wersja prawa ma być zastosowana do którego kursu. Zamyka tylko reprodukowalność: jeżeli engine wybrał wersję, musi być ona jednoznacznie utrwalona i hash-identifiable.

## Durable current requirement context

`course_requirement_contexts` ma dokładnie jeden current row per CourseEnrollment i nie dostaje osobnego version. Wszystkie mutacje serializują się na Course row.

Current held categories są normalizowane do osobnej tabeli join. Nie przechowujemy ich jako przypadkowego JSON array. Historyczne zestawy są zachowywane w immutable input snapshots każdej kalkulacji.

## Exemption decisions

`course_exemption_decisions` stają się jawnie wersjonowaną historią wyboru podstawy zwolnienia:
- business fields immutable po insert,
- maksimum jeden current non-revoked decision per Course,
- replacement = revoke starego + insert nowego pod Course lockiem,
- revoke ma actor + reason,
- current basis nie jest duplikowane do requirement context.

Nie ma nieaudytowanego checkboxa „zwolnij z teorii”.

## Manual override

Ponieważ istniejąca specyfikacja produktu dopuszcza manual override pod warunkami bezpieczeństwa, physical model dostaje `course_requirement_override_decisions`.

Override:
- jest osobną decyzją, nie ukrytym booleanem,
- wymaga permission, actor, reason i timestamp,
- ma allowlist wyłącznie sześciu requirement outputs,
- minute values nie mogą być ujemne,
- maksimum jeden current override na Course,
- replacement/revoke zachowuje stare decyzje,
- base rule-engine output nadal jest zapisywany osobno od effective output po override.

Dzięki temu po czasie widać, co powiedział rule engine i co dokładnie zostało ręcznie zmienione.

## `training_requirement_profiles` jako immutable decision trail

Każdy profile staje się nie tylko current projection, ale pełnym immutable calculation decision.

Dopisujemy/utrwalamy m.in.:
- `requirements_revision`,
- Course version po kalkulacji,
- `rule_set_version`,
- trigger code,
- calculation reason,
- actor/system origin,
- pełny normalized `input_snapshot`,
- `base_output_snapshot`,
- `effective_output_snapshot`,
- optional manual override decision id,
- relational effective output columns,
- calculated/superseded timestamps.

Business contents starego profilu nie są aktualizowane. Zwykłą history mutation może być tylko `superseded_at`.

Partial unique utrzymuje maksimum jeden current profile. Dodatkowo unique `(organization_id, course_enrollment_id, requirements_revision)` zabrania dwóch decyzji dla tej samej rewizji.

## Input snapshot

Snapshot każdej kalkulacji zawiera dokładnie użyte:
- category ID/code,
- training type,
- course start,
- `state_theory_passed`,
- context evidence,
- posortowane held categories,
- current exemption decision/basis/evidence,
- current override decision/payload,
- rule-set version i content hash.

Nie kopiujemy PESEL ani PKK, bo nie są potrzebne do kalkulacji wymagań.

## Current projection guard

Finalny committed CourseEnrollment musi posiadać dokładnie jeden current profile oraz:

`current_profile.requirements_revision = course_enrollments.requirements_revision`.

At-most-one daje partial unique; at-least-one + revision match wymusza deferrable constraint trigger albo równoważny transactional DB guard.

Direct SQL, który zmieni context albo `requirements_revision` bez nowej kalkulacji, nie może przejść finalnego constraintu.

## Course create

API już obiecuje „course created and legal requirements calculated”. Dlatego nowy Course w tej samej transakcji dostaje:
- `requirements_revision=1`,
- current requirement context,
- explicit source facts, jeżeli zostały podane,
- wybraną immutable rule-set version,
- current profile dla revision 1.

Nie rozwiązujemy tu PKK persistence; to nadal DB-TRN-008.

## Edycja contextu

`POST .../requirement-context`:
1. claimuje Idempotency-Key,
2. lockuje CourseEnrollment `FOR UPDATE`,
3. sprawdza expected Course version,
4. aktualizuje current facts/held-category set,
5. `requirements_revision + 1`,
6. supersede starego current profile,
7. kalkuluje i insertuje nowy profile,
8. Course `version + 1` dokładnie raz,
9. dopisuje właściwy DB-TRN-004 `updated` albo `closed_course_corrected` event,
10. audit + outbox w tej samej transakcji.

Semantic no-op nie tworzy sztucznej nowej rewizji.

## Category/training type/start change

Course PATCH już ma Course lock + expected version. Jeżeli zmienia rule-relevant field, requirement recalculation jest częścią tej samej transakcji. Nie robimy drugiego Course version bump za profile — cały logical command zwiększa Course version tylko raz.

Internal-exam compatibility pozostaje DB4_7, external-training compatibility pozostaje DB-TRN-007.

## Exemption / override / rule-set refresh

Każda z tych zmian:
- lockuje ten sam Course,
- używa expected Course version,
- zwiększa `requirements_revision` raz,
- tworzy nowy immutable profile,
- zwiększa Course version raz,
- zapisuje DB-TRN-004 history + audit/outbox.

Dwa równoległe commandy z tą samą Course version nie mogą oba wygrać.

## Closed course correction

Po terminalnym zamknięciu requirements nie są edytowane inline. Obowiązuje DB-TRN-004 correction mode z reason, actor i expected version.

Korekta:
- dopisuje nowy profile,
- nie usuwa starych profili,
- nie usuwa Sessions, Attendance, Ledger ani ExamAttempt,
- oznacza potrzebę regeneracji pochodnych dokumentów, jeżeli zostały dotknięte.

Dokładny pipeline dokumentów pozostaje późniejszym slice, więc nie rozszerzamy DB-TRN-005.

## Completion boundary

DB-TRN-005 zamyka tylko requirement-projection część completion:
- current profile musi istnieć,
- revision musi być świeża,
- rule-set identity musi być poprawna.

Dopiero DB-TRN-006 sprawdzi wymagane minuty, a DB4_7 wymagane egzaminy wewnętrzne.

## Migration design

Migracja nie może odtwarzać source facts z outputów na zasadzie „teoria=false, więc zapewne miał kategorię X”.

Kolejność:
1. register known immutable rule artifact + hash,
2. dodać `requirements_revision` tymczasowo nullable dla legacy precheck,
3. utworzyć context / held categories / override decisions,
4. wzmocnić exemption decisions,
5. rozszerzyć profiles o revision/course-version/snapshots/actor/trigger/rule-set FK,
6. próbować materializować source facts wyłącznie z kompletnego, zweryfikowanego starego `input_snapshot` lub innych jawnych źródeł,
7. nie zgadywać held categories, theory-passed ani exemption basis z output flags,
8. brak reconstructible facts -> migration FAIL / explicit reviewed remediation,
9. dopiero po weryfikacji przypisać revision i current profile,
10. włączyć partial unique + revision freshness guard.

Analogicznie nie można przypisać staremu profilowi fikcyjnej rule-set version, jeżeli nie wiadomo, jaki dokładnie artefakt go wyliczył.

## Self-audit incydentu jakości w tym kroku

Pierwszy machine write DB-TRN-005 zachował nowe decyzje, ale skrócił wcześniejsze sekcje DB-TRN-001..004. To zostało wykryte **przed zapisaniem gate PASS**.

Nie zaakceptowano tego jako finalnego machine source. Kolejny commit przywrócił pełny wcześniejszy contract i dopisał DB-TRN-005 bez semantycznej utraty. Porównanie z HEAD sprzed DB-TRN-005 pokazuje dla machine spec tylko `514 additions / 3 deletions`, gdzie trzy usunięcia odpowiadają celowej zmianie statusu/current-step/open-blocker listy.

Czyli bramka została wykonana na skorygowanym źródle, a nie na wersji skróconej.

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
- closed-course correction zachowuje starą historię — **PASS**,
- recalculation nie usuwa training/attendance/exam history — **PASS**,
- completion freshness jest zamknięte bez przedwczesnego rozwiązania godzin i egzaminów — **PASS SCOPE**,
- DB-TRN-006..008 pozostają nierozwiązane — **PASS SCOPE**,
- nie utracono wcześniejszego DB-TRN-001..004 machine contract po self-audit correction — **PASS**,
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

Obecna tabela nie definiuje jeszcze jednoznacznie current form projection vs additive documented transfer oraz concurrency revoke/replace.

**Status:** OPEN P1.

---

# DB-TRN-008 — OPEN P1: required course PKK persistence boundary before provider lifecycle

## Problem

Zweryfikowany dedykowany formularz Course create/edit wymaga PKK, a PKK jest course-scoped. Minimalna atomic persistence boundary między CourseEnrollment i PKK identity nadal nie jest zamknięta; provider lifecycle pozostaje DB4_8.

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

---

# Quality gate DB4_4_STEP_1 — DIAGNOSIS

Historyczny wynik diagnozy: **FAIL_WITH_8_P1_BLOCKERS** — prawidłowy wynik otwierający blocker-by-blocker fixes.

---

# Quality gate DB4_4_STEP_2 — DB-TRN-001

**FINAL GATE DB-TRN-001: PASS.**

---

# Quality gate DB4_4_STEP_3 — DB-TRN-002

**FINAL GATE DB-TRN-002: PASS.**

---

# Quality gate DB4_4_STEP_4 — DB-TRN-003

**FINAL GATE DB-TRN-003: PASS.**

---

# Quality gate DB4_4_STEP_5 — DB-TRN-004

**FINAL GATE DB-TRN-004: PASS.**

---

# Quality gate DB4_4_STEP_6 — DB-TRN-005

Wykonano wyłącznie requirement context/profile reproducibility.

Self-audit:
- machine source zawiera durable source-fact owners + immutable profile history — **PASS**,
- `requirements_revision` jest freshness epoch, nie konkurencyjnym version root — **PASS**,
- source-fact change nie może commitnąć ze stale profile — **PASS DESIGN**,
- target category nie została zduplikowana — **PASS**,
- exemption/override są jawnie audytowalne — **PASS**,
- rule-set identity jest immutable + hashed — **PASS**,
- snapshots pozwalają odtworzyć inputs, base output i effective output — **PASS**,
- active i closed-course correction używają Course lock/version contract — **PASS**,
- wcześniejsze machine obligations DB-TRN-001..004 zostały zachowane po wykrytej i skorygowanej regresji dokumentacyjnej — **PASS**,
- `core-schema.yml` i `docs/87` pozostają bez zmian — **PASS**,
- DB-TRN-006..008 nadal open — **PASS SCOPE**,
- DB4_5+ nie rozpoczęto — **PASS**,
- Laravel migrations / UI implementation nie rozpoczęto — **PASS**.

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