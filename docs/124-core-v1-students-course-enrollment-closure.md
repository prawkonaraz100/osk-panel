# 124. Core v1 — Students + Course Enrollment closure

Data: 2026-09-11

**Slice:** `CORE-V1-STUDENTS-COURSE-ENROLLMENT-001`  
**Implementation machine:** PASS  
**Narrative payload:** READY  
**Central closure:** PENDING

## 1. Zakres

Slice obejmuje wyłącznie:

- Students,
- Course Enrollment,
- wersjonowany training requirement engine,
- recognized external training,
- course-scoped PKK identity,
- dependency-closed migracje fazy `expand` potrzebne temu zakresowi,
- backend/API/permissions,
- wykonywalne testy, DBT runtime traceability i implementation traceability,
- własny UI dla potwierdzonych ekranów `/kursanci` oraz `/kursanci/{student_id}`.

Nie rozpoczęto Calendar engine, Training Session/Hour Ledger, Student Finance, Learning Access/Licenses ani Internal Exams.

## 2. Clean implementation provenance

Clean accepted implementation commit:

`4715db135738bc9a002b87d44bb11511c4a83452`

Po centralnych bramkach wykonano wyłącznie jawne hardening/correctness fixes: traceability key, PHPStan row typing, pojedynczy static-analysis cleanup oraz truthful DBT runtime registration.

Finalny implementation head przed narrative closure:

`e97caebfe355dafc0d92a077b15135b358173aa7`

Tymczasowy `.github/workflows/helper-student-course-slice.yml` nie wszedł do accepted history.

## 3. Migracje

Materializacja wzrosła z **34/170** do **45/170** node'ów/phase steps. Slice dodał 11 restart-safe `expand` nodes:

- `MIG-TBL-STUDENTS`,
- `MIG-TBL-COURSE_ENROLLMENTS`,
- `MIG-TBL-COURSE_ENROLLMENT_LIFECYCLE_EVENTS`,
- `MIG-TBL-TRAINING_REQUIREMENT_RULE_SETS`,
- `MIG-TBL-COURSE_REQUIREMENT_CONTEXTS`,
- `MIG-TBL-COURSE_REQUIREMENT_CONTEXT_HELD_CATEGORIES`,
- `MIG-TBL-COURSE_REQUIREMENT_OVERRIDE_DECISIONS`,
- `MIG-TBL-TRAINING_REQUIREMENT_PROFILES`,
- `MIG-TBL-COURSE_EXEMPTION_DECISIONS`,
- `MIG-TBL-RECOGNIZED_EXTERNAL_TRAINING`,
- `MIG-TBL-PKK_PROFILES`.

Execution identity:

`59ed166d900d2701df2b8ca6e534b6b59d0de78ecf8fa0f04204f255020e5bcc`

Nie materializowano późniejszych candidate-key/FK/index/constraint phases. Część z nich zależy także od Training Session, Hour Ledger, Student Learning Account albo dalszych tabel. Stage-4 170-node DAG, canonical order, dependency edges i siedmiofazowa kolejność nie zostały przepisane.

## 4. Student

Zaimplementowano tenant-scoped Student lifecycle z rozdzieleniem profilu od Learning Account:

- kursant może istnieć przed kursem bez kompletnej formalnej tożsamości,
- formalny CourseEnrollment wymaga PESEL albo jawnego `no_pesel` z datą urodzenia,
- PESEL jest szyfrowany at rest i ma HMAC lookup; plaintext nie trafia do audit payload,
- duplicate PESEL w tym samym OSK jest blokowany także względem archived history na aktualnej warstwie runtime,
- optimistic version/ETag/`If-Match`,
- archive/restore bez kasowania profilu,
- aktywny kurs blokuje archive,
- `assigned_students` scope rozwiązuje tylko faktycznie przypisanych kursantów.

Learning login, hasło i licencja nie zostały spłaszczone do Student.

## 5. Course Enrollment i PKK

CourseEnrollment pozostaje odrębną encją formalnego kursu. Zaimplementowano:

- wiele kursów dla jednego Student,
- create/list/get/update,
- cancel/restore,
- versioned lifecycle events,
- nonterminal training-stage transitions,
- fail-closed `training_completed` dopóki nie ma wykonywalnego Hour Ledger i Internal Exam evidence,
- prowadzącego instruktora i lokalizację w tym samym OSK,
- course-scoped PKK profile z szyfrowanym numerem,
- zmianę kategorii/rodzaju szkolenia tylko z jawną rewalidacją PKK context.

Pełny provider-backed PKK fetch/return/retry flow pozostaje osobnym późniejszym adapterem; slice nie symuluje zewnętrznego providera.

## 6. Requirement engine i godziny z innego OSK

Training requirement profile jest wersjonowany i związany z hash-em zweryfikowanego artefaktu reguł.

Zaimplementowano m.in.:

- aktualny profil wymagań dla kursu,
- source-fact recalculation,
- verified held-category reductions,
- jawne decyzje o zwolnieniu teorii `art_23a` z historią,
- recognized external training jako append/revoke history,
- rewalidację external projection przy zmianie kategorii lub rodzaju szkolenia.

Pola bieżących godzin OSK są deklaracją/planningiem, nie formalnym kredytem czasu. Formalne credited hours pozostają wyłącznie odpowiedzialnością następnego Training Session/Hour Ledger slice.

## 7. Student Finance, Learning Access i Internal Exams

Potwierdzone pola/entry points zachowano, ale nie utworzono fałszywego źródła prawdy:

- `initial_cost` pozostaje późniejszym atomowym side effectem Student Finance; CourseEnrollment nie przechowuje równoległego salda,
- import bieżących formalnych godzin nie jest wykonywany bez Hour Ledger,
- Student Learning Account i license assignment są odrębnymi przyszłymi encjami,
- Internal Exam nie jest symulowany,
- zakończenie szkolenia pozostaje zamknięte bez evidence z godzin i egzaminu.

## 8. API i UI

OpenAPI zachowuje istniejący contract i został zsynchronizowany z runtime concurrency:

- mutacje Course mają `If-Match`,
- komendy create/cancel/restore/stage/external wymagają idempotency tam, gdzie przewiduje kontrakt,
- create Course replay z tym samym kluczem zwraca ten sam efekt,
- PESEL i PKK nie są zwracane jako pełny plaintext.

UI obejmuje:

- listę kursantów, count/search/filter/sort/pagination,
- read-only preview,
- profil kursanta,
- create/edit/archive/restore Student,
- listę kursów,
- create/edit/cancel/restore Course,
- training stage,
- requirement summary i decyzję `art_23a`,
- recognized external training add/revoke.

Calendar, Learning Access, Finance i Exam są oznaczone jako zależne moduły, bez sztucznego wypełniania stanu.

## 9. Executable DBT

Przed końcowym audytem runtime catalog miał **36/491** implemented assertions. Audyt closure wykrył, że rzeczywiste Students/Course tests nie były jeszcze przypięte do Stage-4 DBT catalogu.

Zarejestrowano wyłącznie 11 kontraktów mających faktycznie wykonywalne assertions:

- `DBT-TRN-003`,
- `DBT-IAM-032`,
- `DBT-CORE-021`,
- `DBT-TRN-006`,
- `DBT-TRN-007`,
- `DBT-TRN-008`,
- `DBT-CORE-023`,
- `DBT-TRN-013`,
- `DBT-TRN-035`,
- `DBT-TRN-037`,
- `DBT-TRN-038`.

Runtime catalog wynosi teraz **47/491 implemented executable assertions**, a **444/491** pozostają `pending_domain_materialization`.

Nie oznaczono jako wykonanych DBT wymagających fizycznych późniejszych constraints, Training Session, Hour Ledger ani niewykonanych race/concurrency scenarios.

## 10. Machine evidence

Accepted implementation run `34547755876` na head `e97caebf...`:

- backend-quality — PASS,
- frontend-quality — PASS,
- runtime-tests-and-migrations — PASS,
- contracts-and-traceability — PASS,
- secret-scan — PASS.

PostgreSQL suite:

**77 tests / 1417 assertions — PASS**

Dodatkowo:

- Composer strict validation — PASS,
- Pint — PASS,
- PHPStan — PASS, zero błędów,
- ESLint — PASS, zero warnings,
- Vue/TypeScript typecheck — PASS,
- production build — PASS,
- npm audit high — PASS,
- OpenAPI validator — PASS,
- changed-module traceability — PASS,
- migration plan/registry validation — PASS,
- Gitleaks accepted-push scan — PASS.

## 11. Jawnie odroczone zależności

Nie są częścią PASS tego slice:

- Calendar event/availability/conflict engine,
- Training Session,
- formal Training Hour Ledger,
- Student Finance i course-cost charge side effect,
- Learning Access i Licenses,
- Internal Exams,
- provider-backed PKK operations,
- późniejsze candidate-key/FK/index/constraint phases blokowane canonical phase/dependency boundary.

## 12. Narrative result

Implementation machine = **PASS**.

Ten dokument jest narrative closure candidate. Po jego accepted-branch central validation można zamknąć:

`CORE-V1-STUDENTS-COURSE-ENROLLMENT-001 = PASS`.

Następny pojedynczy krok zgodnie z `AGENTS.md` i Stage-5 order:

`CORE-V1-CALENDAR-TRAINING-SESSION-HOUR-LEDGER-001`

Nie został rozpoczęty.

**STOP przed Calendar + Training Session + formal Hour Ledger do następnej jawnej instrukcji.**
