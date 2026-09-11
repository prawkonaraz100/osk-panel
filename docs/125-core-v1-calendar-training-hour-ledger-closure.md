# 125. Core v1 — Calendar + Training Session + formal Hour Ledger closure

Data: 2026-09-11

**Slice:** `CORE-V1-CALENDAR-TRAINING-SESSION-HOUR-LEDGER-001`  
**Implementation machine:** PASS  
**Narrative payload:** READY  
**Central closure:** PENDING

## 1. Zakres

Slice obejmuje wyłącznie:

- Calendar,
- Training Session,
- verified Attendance,
- formalny Training Hour Ledger,
- AvailabilitySlot booking lifecycle,
- formalizację booked Availability do praktycznego TrainingSession,
- calendar read-model projections,
- ważne daty jako read-only projections,
- dependency-closed migracje fazy `expand` potrzebne temu zakresowi,
- backend/API/permissions,
- wykonywalne testy, DBT runtime traceability i implementation traceability,
- własny UI dla potwierdzonego głównego ekranu `/kalendarz` oraz wspólnego formularza `Wydarzenie/Jazda`.

Nie rozpoczęto Student Finance, Learning Access/Licenses, Internal Exams ani PKK provider adaptera.

## 2. Clean implementation provenance

Clean accepted implementation commit:

`0bf3e1c484f21c3b94f079e1202d196d7375fcff`

Finalny accepted runtime hardening head przed narrative closure:

`802c673521adb1e883a1c6ab4494d2c387374eb5`

Accepted implementation CI na clean implementation commit:

`34598964042` — 5/5 SUCCESS.

Accepted API Contract Gate:

`34598964044` — PASS.

Finalny accepted Implementation CI po truthful DBT hardening:

`34599765192` — 5/5 SUCCESS.

Tymczasowy `.github/workflows/helper-calendar-training-slice.yml` nie wszedł do accepted history.

## 3. Migracje

Materializacja wzrosła z **45/170** do **54/170** Stage-4 DAG node'ów / phase steps. Slice dodał 9 restart-safe `expand` nodes:

- `MIG-TBL-TRAINING_SESSIONS`,
- `MIG-TBL-TRAINING_SESSION_ATTENDANCE`,
- `MIG-TBL-TRAINING_HOUR_LEDGER_ENTRIES`,
- `MIG-TBL-CALENDAR_EVENTS`,
- `MIG-TBL-CALENDAR_EVENT_LIFECYCLE_EVENTS`,
- `MIG-TBL-AVAILABILITY_SLOTS`,
- `MIG-TBL-AVAILABILITY_SLOT_LIFECYCLE_EVENTS`,
- `MIG-TBL-CALENDAR_RESOURCE_CLAIMS`,
- `MIG-TBL-TRAINING_SESSION_CALENDAR_DETAILS`.

Execution identity:

`1495b3bbccb44db2bbc6f466391a6fa8f70dc6ad5f3154dbe84de68e0d2c4422`

Plan identity pozostał:

`d2fd6bc999dc2a5ee024e9b91bf00f5a5dad29575554a0ba42eba67107c23f10`

Stage-4 authority blob pozostał:

`ad5f2aa2e14ef248b95dd3dda0d1cbcb2e69d441`

Nie materializowano późniejszych candidate-key/FK/index/GiST/constraint phases. `specs/database/core-schema.yml`, `docs/87-physical-database-schema.md` i finalny Stage-4 matrix nie zostały przepisane.

## 4. Training Session, Attendance i formal Hour Ledger

Zaimplementowano planowany TrainingSession jako wersjonowany schedule aggregate:

- create/list/get/update,
- optimistic concurrency przez `version` i `If-Match`,
- `session_type` nie jest mutowalny zwykłym PATCH,
- verified Attendance jest zapisywane przed formalnym completion,
- completion i cancellation są terminalne,
- status `present` przy completion tworzy dokładnie jeden bazowy `credit`,
- bazowy credit ma liczbę minut wyliczoną z rzeczywistego `starts_at -> ends_at`,
- status `absent` nie tworzy formalnego kredytu,
- cancellation nie tworzy formalnego kredytu,
- complete/cancel zwalnia aktualne schedule claims,
- fresh drugi terminal command jest odrzucany, a ten sam idempotency key może odtworzyć poprzedni wynik.

Formalny czas bieżącego OSK pozostaje append-only ledgerem. Manualne zmiany czasu:

- tworzą `correction` lub `reversal`,
- nie aktualizują ani nie kasują historycznych wpisów,
- wymagają świeżego CourseEnrollment version,
- nie mogą sprowadzić subtotalu poniżej zera,
- zwiększają CourseEnrollment version i zapisują lifecycle/audit evidence.

## 5. Wspólna granica konfliktów Calendar

Wprowadzono jeden współdzielony `calendar_resource_claims` runtime boundary dla:

- manual CalendarEvent,
- booked AvailabilitySlot,
- planned TrainingSession.

Aktualny runtime przed finalną fazą GiST:

- działa w jednej transakcji,
- bierze deterministic transaction-scoped PostgreSQL advisory locks,
- porządek zasobów to Student -> Instructor -> Vehicle -> Location,
- po lockach ponownie wykonuje overlap query,
- używa half-open interval `[start, end)`,
- pozwala na bezpośrednio sąsiadujące rezerwacje,
- wykonuje exact claim replacement i terminal release atomowo.

Finalna produkcyjna granica pozostaje PostgreSQL GiST exclusion zgodnie z ADR-0008. Transitional advisory-lock boundary nie jest deklarowany jako substytut finalnego constraintu.

## 6. Manual CalendarEvent i formalna „Jazda”

`calendar_events` przechowuje wyłącznie manualny `general_event`.

Zaimplementowano:

- create/list/get/update,
- complete/cancel,
- optimistic version / `If-Match`,
- idempotent command replay tam, gdzie wymaga kontrakt,
- append-only lifecycle history,
- managed `location_id` XOR `custom_meeting_place`,
- terminal claim release,
- brak wpływu manualnego CalendarEvent na formalny Training Hour Ledger.

Formalna `driving_lesson` nie jest drugim mutable CalendarEventem. Jej schedule ownerem jest praktyczny TrainingSession.

Calendar projection dla formalnej jazdy:

- ma `source_kind = training_session`,
- Student pochodzi z CourseEnrollment,
- opcjonalna nazwa i custom meeting place są w `training_session_calendar_details`,
- nie tworzy kopii w `calendar_events`,
- calendar.manage nie daje prawa do mutacji formalnego szkolenia,
- formalne mutacje wymagają odpowiednich `training_sessions.*` permissions.

Student-only create wybiera CourseEnrollment tylko wtedy, gdy istnieje dokładnie jeden kwalifikujący się aktywny kurs; system nie wybiera „pierwszego”, „ostatniego” ani najnowszego przy niejednoznaczności.

## 7. Availability booking i formalization handoff

Availability publication jest nierezerwującą deklaracją dostępności.

Booking:

- wymaga aktualnego slot version / `If-Match`,
- zapisuje Student snapshot i lifecycle history,
- atomowo tworzy dokładny Student/Instructor/Vehicle/Location claim set,
- przy konflikcie cofa cały booking,
- nie tworzy automatycznie TrainingSession,
- nie tworzy Training Hour Ledger credit.

Cancellation:

- może zakończyć available/booked slot zgodnie z lifecycle,
- zwalnia current booking claims,
- zachowuje booking Student snapshot w historii,
- jest terminalna,
- nie auto-republikuje slotu.

Formalization booked slotu:

- jest osobnym idempotent commandem,
- wymaga `training_sessions.create`,
- wymaga explicit same-Student CourseEnrollment albo dokładnie jednego jednoznacznego aktywnego kursu,
- atomowo przenosi claim ownership z `availability_slot_booking` do `training_session`,
- linkuje slot z jednym praktycznym TrainingSession,
- zachowuje historyczny snapshot slotu,
- przy failure cofa session/link/claims i pozostawia oryginalny booking,
- nie tworzy attendance ani formalnego kredytu czasu,
- po formalizacji slot nie może być niezależnie anulowany.

## 8. Calendar read model

Główny read model łączy źródła bez tworzenia równoległych source-of-truth:

- manual `general_event` z `calendar_events`,
- formalną praktyczną jazdę z TrainingSession,
- booked i jeszcze niesformalizowany AvailabilitySlot jako `source_kind = availability_slot_booking`,
- important dates z aktualnych dokumentów Staff/Vehicle.

Po formalizacji Availability projection jest tłumiona, a kalendarz pokazuje jeden TrainingSession projection — bez duplikatu.

Important dates:

- są read-only,
- pochodzą z current non-superseded `valid_until`,
- nie tworzą CalendarEvent rows,
- nie tworzą resource claims,
- automatycznie zmieniają datę lub znikają po zmianie źródłowego dokumentu,
- nie pokazują wpisów dla archived source owner ani dla null expiry,
- respektują calendar visibility/resource scopes.

## 9. Permissions, API i UI

Runtime scope nie korzysta z role shortcutów.

Kluczowe granice:

- Calendar own scope wiąże się z aktywnym StaffProfile i `instructor_id`, nie z creator user,
- own mutation nie może przenieść ownership na innego instruktora,
- formal TrainingSession own scope również korzysta z canonical StaffProfile identity,
- calendar.view może czytać dozwolone projections,
- calendar.manage nie eskaluje do formalnego training mutation,
- availability publication/book/formalization korzystają z odpowiednich jawnych permissions i scopes.

OpenAPI został zsynchronizowany z runtime:

- wymagane `If-Match` dla versioned terminal/mutation commands,
- idempotency dla commands objętych contractem,
- formal `driving_lesson` ma source-specific command/projection zamiast zapisu do generic CalendarEvent.

UI `/kalendarz` obejmuje potwierdzony zakres:

- Miesiąc / Tydzień / Dzień,
- Dzisiaj / poprzedni / następny okres,
- filtry Wydarzenia / Jazdy / Ważne daty,
- filtry pracowników, pojazdów i lokalizacji,
- centralny read model,
- wspólny drawer `Dodaj wydarzenie`,
- typ `Wydarzenie` lub `Jazda`,
- nazwa,
- data/godzina,
- czas trwania,
- Kursant,
- Instruktor,
- Pojazd,
- zapisana lokalizacja albo custom meeting place przez `Inne? / Wróć`.

Wspólny formularz jest wizualnie jeden, ale backend route zależy od typu: general event trafia do CalendarEvent, a Jazda do canonical TrainingSession.

Osadzone kalendarze Staff/Vehicle/Location korzystają z tego samego live read modelu zamiast placeholdera.

## 10. Executable DBT

Przed audytem closure runtime catalog miał **47/491** implemented executable assertions.

Audyt wykrył, że realne Calendar/Training tests nie były jeszcze przypięte do Stage-4 DBT catalogu. Po truthful hardening zarejestrowano wyłącznie 24 kontrakty posiadające już rzeczywiste assertions:

- `DBT-CAL-002`,
- `DBT-CAL-003`,
- `DBT-CAL-004`,
- `DBT-CAL-011`,
- `DBT-CAL-012`,
- `DBT-CAL-013`,
- `DBT-CAL-014`,
- `DBT-CAL-016`,
- `DBT-CAL-017`,
- `DBT-TRN-015`,
- `DBT-TRN-017`,
- `DBT-TRN-018`,
- `DBT-CAL-018`,
- `DBT-CAL-019`,
- `DBT-CAL-020`,
- `DBT-CAL-021`,
- `DBT-CAL-022`,
- `DBT-CAL-024`,
- `DBT-CAL-025`,
- `DBT-CAL-051`,
- `DBT-CAL-052`,
- `DBT-CAL-053`,
- `DBT-CAL-054`,
- `DBT-CAL-055`.

Runtime catalog wynosi teraz:

**71/491 implemented executable assertions**  
**420/491 pending_domain_materialization**

Świadomie nie oznaczono jako wykonanych:

- `DBT-CAL-023` — Stage-4 `migration_preflight`, który musi pozostać pending do właściwej fazy DDL mimo istniejącego business-runtime testu ambiguity,
- realnych two-writer concurrency DBT, których osobnych race testów jeszcze nie ma,
- fizycznych GiST/FK/index/check/candidate-key DBT z późniejszych faz.

Wszystkie 14 Stage-4 migration preflight contracts nadal mają stan `pending_domain_materialization`.

## 11. Machine evidence

Finalny accepted Implementation CI run `34599765192` na head `802c6735...` zakończył się **5/5 SUCCESS**:

- backend-quality — PASS,
- frontend-quality — PASS,
- runtime-tests-and-migrations — PASS,
- contracts-and-traceability — PASS,
- secret-scan — PASS.

PostgreSQL suite:

**110 tests / 1772 assertions — PASS**

Dodatkowo:

- Composer strict validation — PASS,
- Pint — PASS,
- PHPStan — PASS, zero błędów,
- ESLint — PASS, zero warnings,
- Vue/TypeScript typecheck — PASS,
- production build — PASS,
- npm audit high — PASS,
- migration plan/registry validation — PASS,
- changed-module traceability — PASS,
- Gitleaks accepted-push scan — PASS.

API Contract Gate run `34598964044` na clean implementation commit `0bf3e1c4...` — PASS. Późniejsze hardening commits zmieniały wyłącznie test/DBT runtime coverage i nie zmieniały kontraktu HTTP.

## 12. Jawnie odroczone zależności

Nie są częścią PASS tego slice:

- finalne fizyczne GiST/FK/index/check/candidate-key phases zgodnie z globalnym migration phase barrier,
- realne two-writer race tests dla niewykonanych concurrency DBT,
- osobny self-booking/Availability UI poza potwierdzonym głównym Calendar screen,
- Work Time UI,
- notifications,
- recurrence,
- drag & drop,
- późniejsza polityka warning vs hard-block dla wygasłych dokumentów,
- Student Finance,
- Learning Access / Licenses,
- Internal Exams,
- provider-backed PKK operations.

## 13. Narrative result

Implementation machine = **PASS**.

Ten dokument jest narrative closure candidate. Po jego accepted-branch central validation można zamknąć:

`CORE-V1-CALENDAR-TRAINING-SESSION-HOUR-LEDGER-001 = PASS`.

Następny pojedynczy krok zgodnie z `AGENTS.md` i Stage-5 order:

`CORE-V1-STUDENT-FINANCE-001`

Nie został rozpoczęty.

**STOP przed Student Finance do następnej jawnej instrukcji.**
