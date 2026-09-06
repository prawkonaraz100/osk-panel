# 109. Stage 4 — Calendar database audit

Data: 2026-09-06

**Etap:** `DB4_5_CALENDAR`  
**Aktualny krok:** `DB-CAL-001`  
**Status:** `DB-CAL-001 PASS / 6 P1 OPEN`

## 1. Zasada pracy

DB4_5 jest prowadzony pojedynczymi blockerami. Diagnoza wykazała 7 P1. W tym kroku rozwiązano **wyłącznie `DB-CAL-001` — same-tenant resource integrity**.

Nie zmieniono:
- `DB-CAL-002` manual event vs system projection,
- `DB-CAL-003` overlap/conflict concurrency,
- `DB-CAL-004` lifecycle eventu,
- `DB-CAL-005` `calendar.manage.own`,
- `DB-CAL-006` availability booking lifecycle,
- `DB-CAL-007` Calendar ↔ formal `TrainingSession`.

Nie zmieniono też aggregate `specs/database/core-schema.yml` ani `docs/87-physical-database-schema.md`, nie utworzono migracji Laravel i nie rozpoczęto DB4_6/UI.

Machine-readable kontrakt: `specs/database/calendar.yml`.

## 2. Źródła i istniejące zależności

DB-CAL-001 korzysta z już zamkniętych granic wcześniejszych slice:
- `students (organization_id,id)` — candidate key z DB-TRN-001,
- `staff_profiles (organization_id,id)` — candidate key z DB-RES-001,
- `vehicles (organization_id,id)` — candidate key z DB-RES-002,
- `locations (organization_id,id)` — candidate key z DB-RES-002.

Źródła funkcjonalne i kontraktowe pozostają:
- `docs/48-calendar-main-screen.md`,
- `docs/49-calendar-add-event-form.md`,
- `docs/50-own-calendar-event-lifecycle.md`,
- `docs/51-calendar-important-dates.md`,
- `specs/screens/calendar.yml`,
- `specs/screens/calendar-add-event.yml`,
- `specs/api/paths/pkk-calendar.yaml`,
- `specs/api/openapi-components-v1.yaml`,
- `specs/api/required-operations-v1.yml`,
- `specs/security/permissions.yml`,
- `specs/database/staff-locations-vehicles.yml`,
- `specs/database/students-courses-training.yml`.

## 3. Zachowane wymagania Calendar

Naprawa same-tenant integrity nie redukuje produktu. Nadal zachowujemy:
- jeden centralny kalendarz,
- widoki miesiąc / tydzień / dzień,
- `general_event`, `driving_lesson` i systemową projekcję `important_date`,
- filtry Staff / Vehicle / Location,
- opcjonalnego Studenta, Instruktora, Pojazd i zarządzaną lokalizację,
- custom meeting place,
- availability/self-booking,
- server-side conflict detection,
- history-preserving lifecycle,
- timezone organizacji,
- audyt.

DB-CAL-001 nie rozstrzyga jeszcze, które zasoby są biznesowo aktywne/kwalifikowane ani czy wygasły dokument jest warningiem czy hard-blockiem. Ten krok odpowiada tylko na pytanie: **czy wskazany tenant-owned resource należy do tej samej organizacji co event/slot**.

## 4. DB-CAL-001 — problem

Przed tym krokiem `calendar_events` i `availability_slots` miały `organization_id`, ale relacje do tenant-owned zasobów były opisane jedynie przez zwykłe resource IDs.

Dotyczyło to:
- `calendar_events.student_id`,
- `calendar_events.instructor_id`,
- `calendar_events.vehicle_id`,
- `calendar_events.location_id`,
- `availability_slots.instructor_id`,
- `availability_slots.vehicle_id`,
- `availability_slots.location_id`,
- `availability_slots.booked_student_id`.

Sama aplikacyjna walidacja `resource.organization_id == event.organization_id` nie jest wystarczającą finalną granicą. Błąd backendu, import albo późniejszy kod mógłby zapisać event OSK A wskazujący zasób OSK B.

## 5. DB-CAL-001 — decyzja fizyczna

### 5.1 `calendar_events`

Każda opcjonalna relacja tenant-owned otrzymuje composite FK:

- `(organization_id, student_id) -> students(organization_id,id)`,
- `(organization_id, instructor_id) -> staff_profiles(organization_id,id)`,
- `(organization_id, vehicle_id) -> vehicles(organization_id,id)`,
- `(organization_id, location_id) -> locations(organization_id,id)`.

Wszystkie są nullable i używają `MATCH SIMPLE`, więc brak zasobu nadal jest legalny. Jeśli resource ID jest podany, musi wskazywać rekord dokładnie z tego samego OSK.

`ON UPDATE RESTRICT`, `ON DELETE RESTRICT` chronią historyczne odwołania.

### 5.2 `availability_slots`

Analogicznie:
- `(organization_id, instructor_id) -> staff_profiles(organization_id,id)`,
- `(organization_id, vehicle_id) -> vehicles(organization_id,id)`,
- `(organization_id, location_id) -> locations(organization_id,id)`,
- `(organization_id, booked_student_id) -> students(organization_id,id)`.

`booked_student_id` otrzymuje tutaj wyłącznie same-tenant boundary. Jego state matrix, exactly-once booking i relacja do realnego efektu rezerwacji pozostają w `DB-CAL-006`.

### 5.3 Tenant key

`calendar_events.organization_id` i `availability_slots.organization_id` są obowiązkowymi tenant keys.

Zasady:
- organization scope pochodzi z aktywnego membership/zwalidowanego parent context,
- client-supplied `organization_id` nie jest authority,
- zwykła zmiana `organization_id` nie służy do przenoszenia eventu/slotu między OSK,
- cross-tenant relation jest odrzucana przez DB nawet przy pominiętej walidacji aplikacji.

### 5.4 `created_by_user_id`

Nie dodano sztucznego composite tenant FK dla `created_by_user_id`.

`User` jest globalną tożsamością i może mieć membership w wielu OSK. To, czy dany User miał prawo utworzyć/zarządzać eventem, wynika z membership + permission/scope. Canonical semantyka `calendar.manage.own` pozostaje celowo w `DB-CAL-005`.

## 6. Archive i historia

Composite FK nie powinien usuwać ani zerować relacji historycznej po archiwizacji Staff/Vehicle/Location/Student.

Parent domains używają lifecycle/archive zamiast normalnego hard delete. Dlatego stary event może nadal wskazywać historyczny zasób i pozostawać audytowalny.

DB-CAL-001 nie ustala, czy **nowy** event może użyć archived/inactive resource. To oddzielna business/compliance eligibility policy.

## 7. Migration design dla DB-CAL-001

Migracji Laravel nadal nie tworzymy. Gdy finalny DB4_5 migration design zostanie wygenerowany, kolejność dla tego blockera jest następująca:

1. potwierdzić istniejące candidate keys Student/Staff/Vehicle/Location,
2. sprawdzić non-null i poprawność `organization_id` eventów/slotów,
3. wykonać precheck każdej niepustej relacji CalendarEvent,
4. wykonać precheck każdej niepustej relacji AvailabilitySlot,
5. jeśli istnieje cross-tenant mismatch — zatrzymać migrację albo wykonać jawnie zatwierdzoną security/data remediation,
6. **nie** naprawiać automatycznie przez przepięcie resource ID, zmianę organizacji ani wyzerowanie relacji,
7. dopiero po czystych precheckach dodać composite FKs,
8. uruchomić negatywne constraint tests.

## 8. Testy wymagane przez tę bramkę

Muszą istnieć testy, że:
- same-tenant Student/Instructor/Vehicle/Location na CalendarEvent przechodzą,
- wszystkie opcjonalne resource IDs mogą być `NULL`,
- każdy z czterech resource IDs z innego OSK jest odrzucany przez DB,
- same-tenant Instructor/Vehicle/Location na AvailabilitySlot przechodzą,
- każdy z tych zasobów z innego OSK jest odrzucany,
- `booked_student_id` z innego OSK jest odrzucany,
- migracyjny precheck wykrywa legacy cross-tenant rows,
- nie można przenieść eventu/slotu do innego OSK przez zwykłe przepisanie `organization_id`,
- archive parent resource nie niszczy historycznej relacji,
- globalny User jako actor nie jest fałszywie traktowany jak tenant-owned resource.

## 9. Self-audit DB-CAL-001

Wynik: **PASS**.

Sprawdzone:
- wszystkie 4 tenant-owned relacje `calendar_events` mają composite same-tenant boundary,
- wszystkie 4 tenant-owned relacje `availability_slots` mają composite same-tenant boundary,
- nullable semantics zachowane przez `MATCH SIMPLE`,
- użyto istniejących candidate keys zamiast duplikować model,
- historia jest chroniona przez `RESTRICT`,
- User nie został błędnie tenant-scoped,
- migration policy nie maskuje legacy naruszeń automatycznym przepinaniem danych,
- nie rozwiązano DB-CAL-002..007,
- aggregate schema pozostało zamrożone,
- migracji Laravel nie utworzono,
- DB4_6 i UI nie rozpoczęto.

## 10. Pozostałe P1 po DB-CAL-001

Pozostaje dokładnie **6 P1**:

### DB-CAL-002 — manual event vs system projection + row invariants

Ręczny formularz obsługuje tylko `general_event` i `driving_lesson`; `important_date` jest systemową projekcją. Nadal trzeba zamknąć storage boundary oraz `location_id XOR custom_meeting_place`.

### DB-CAL-003 — overlap/conflict concurrency

Server-side conflict detection nadal nie ma race-safe final DB/transaction boundary.

### DB-CAL-004 — event lifecycle + optimistic concurrency

Nadal brak finalnej state matrix i PATCH/cancel/complete concurrency contract.

### DB-CAL-005 — `calendar.manage.own`

Nadal brak canonical owner resolver zgodnego z DB4_2.

### DB-CAL-006 — AvailabilitySlot booking lifecycle

Same-tenant `booked_student_id` jest już zamknięte, ale exactly-once booking, state matrix, book/cancel races i efekt rezerwacji nadal są otwarte.

### DB-CAL-007 — `driving_lesson` vs formal `TrainingSession`

Nadal trzeba ustalić jeden canonical schedule owner i relację/projekcję tak, aby Calendar nie stał się drugim formalnym źródłem godzin.

## 11. Bramka jakości DB-CAL-001

**PASS.**

Stan po bramce:
- P0: `0`,
- P1 rozwiązane w DB4_5: `1`,
- P1 otwarte: `6`,
- `DB-CAL-001`: `PASS`,
- final DB4_5 aggregate sync: nadal `PENDING`,
- DB4_6: zablokowane,
- Stage 5: zablokowany,
- UI/feature implementation: zablokowane.

Następny pojedynczy dozwolony krok po aktualizacji centralnego gate: **`DB-CAL-002` only**.
