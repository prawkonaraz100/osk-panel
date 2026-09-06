# 109. Stage 4 — Calendar database diagnosis

Data: 2026-09-06

**Etap:** `DB4_5_CALENDAR`  
**Krok:** `DB4_5_STEP_1_DIAGNOSIS`  
**Status:** `DIAGNOSIS_COMPLETE / 7 P1 OPEN / 0 FIXES APPLIED`

## 1. Zasada tego kroku

Ten dokument jest wyłącznie diagnozą. Nie zmienia fizycznego aggregate schema, nie tworzy migracji Laravel i nie rozwiązuje żadnego blockera. Po bramce jakości następny dozwolony krok to pojedynczy `DB-CAL-001`.

Źródła:
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
- `specs/reverse-engineering-manifest.yml`,
- `specs/traceability/core-v1.yml`,
- `specs/database/core-schema.yml`,
- `docs/87-physical-database-schema.md`,
- `specs/database/students-courses-training.yml`,
- `docs/84-test-strategy.md`.

Machine-readable diagnoza: `specs/database/calendar.yml`.

## 2. Co jest już potwierdzone i nie może zniknąć

Calendar jest jednym centralnym plannerem OSK. Musi zachować:
- widoki miesiąc / tydzień / dzień,
- filtry `Wydarzenie`, `Jazda`, `Ważne daty`,
- wielowymiarowe filtry Staff / Vehicle / Location,
- ręczne tworzenie `general_event` i `driving_lesson`,
- opcjonalnego Studenta, Instruktora, Pojazdu i miejsca spotkania,
- alternatywę `Location` albo własny tekst `custom_meeting_place`,
- server-side conflict detection,
- history-preserving edit/cancel/complete lifecycle,
- `important_date` jako systemową projekcję źródłowych terminów, nie ręczny event,
- availability/self-booking slots,
- tenant isolation,
- timezone organizacji,
- audyt zmian.

Nie rozwiązujemy braków przez usunięcie żadnej z tych funkcji.

## 3. Stan obecnego physical blueprintu

`calendar_events` ma obecnie m.in. `organization_id`, czas, optional Student/Instructor/Vehicle/Location, custom meeting place, `status`, `version` i `created_by_user_id`. Jest tylko `ends_at > starts_at` oraz zapis, że backend sprawdza konflikty, podczas gdy strategia concurrency pozostaje `pending_ADR`.

`availability_slots` ma `organization_id`, optional Instructor/Vehicle/Location, czas, `status`, `booked_student_id`, `booked_at` i `version`, lecz bez zamkniętego lifecycle i transakcyjnego modelu book/cancel.

Ten szkielet wystarcza do rozpoczęcia DB4_5, ale nie jest jeszcze bezpiecznym production blueprintem.

## 4. Wynik diagnozy

- P0: **0**
- P1: **7**
- poprawki zastosowane w tym kroku: **0**

### DB-CAL-001 — P1 — same-tenant resource integrity

`calendar_events` i `availability_slots` są tenant-owned, ale aggregate nie definiuje composite same-organization FK dla wszystkich resource IDs. Dotyczy Student/Instructor/Vehicle/Location oraz `booked_student_id`.

Ryzyko: backend bug/import może stworzyć event OSK A wskazujący zasób OSK B mimo poprawnego `organization_id` na samym event row.

**Nie naprawiono w diagnozie.**

### DB-CAL-002 — P1 — manual event vs system projection + row invariants

Ręczny formularz obsługuje tylko `general_event` i `driving_lesson`. `important_date` jest własną systemową projekcją i jego source-of-truth pozostaje w Staff/Vehicle document domains. Obecne `event_type varchar` nie zamyka tej granicy.

Dodatkowo formularz jednoznacznie reprezentuje miejsce jako `saved location XOR custom text`, ale obecny row pozwala technicznie ustawić oba.

Ryzyko: drugi source-of-truth dla ważnych dat i sprzeczny meeting-place state.

**Nie naprawiono w diagnozie.**

### DB-CAL-003 — P1 — overlap/conflict concurrency

API i wymagania produktu mówią o server-side conflict detection, ale `core-schema.yml` ma nadal `calendar_conflict.enforcement_strategy: pending_ADR`.

Samo `SELECT czy wolne -> INSERT` nie jest race-safe. Dwa równoległe requesty mogą oba zobaczyć wolny zasób i oba commitować.

Zakres konfliktów musi objąć co najmniej Student / Instructor / Vehicle / zarządzaną Location oraz rezerwację powstałą z self-bookingu.

**Nie naprawiono w diagnozie.**

### DB-CAL-004 — P1 — event lifecycle + optimistic concurrency

Own policy definiuje `scheduled|completed|cancelled`, zachowanie historii oraz audyt. Physical model ma wolny `status` i `version`, ale nie ma state matrix, terminal metadata ani zamkniętego PATCH-vs-cancel-vs-complete concurrency contract.

Ryzyko: równoległa edycja i cancel/complete mogą wzajemnie nadpisać stan albo pozostawić niespójne historyczne dane.

**Nie naprawiono w diagnozie.**

### DB-CAL-005 — P1 — `calendar.manage.own` bez canonical owner relation

DB4_2 ustalił, że scope `own` ma działać wyłącznie przez canonical owner relation i przy jej braku fail-closed. Calendar ma realne permission `calendar.manage.own`, lecz model nie wskazuje czy ownerem eventu jest Instructor, creator czy inna relacja. To samo dotyczy own-scope publikowania slotów.

Nie wolno tutaj po cichu redefiniować globalnej semantyki `own` z DB4_2.

**Nie naprawiono w diagnozie.**

### DB-CAL-006 — P1 — AvailabilitySlot exactly-once booking lifecycle

Stage-3 API obiecuje atomowy booking jednego slotu dla jednego Studenta, a strategia testów wymaga double-booking race test. Obecny row nie definiuje state matrix, lock order, stale version behavior ani materialnego skutku rezerwacji w Calendar.

Ryzyko: dwa bookingi, book-vs-cancel race albo slot oznaczony jako booked bez realnej rezerwacji zasobu w kalendarzu.

**Nie naprawiono w diagnozie.**

### DB-CAL-007 — P1 — `driving_lesson` vs formal `TrainingSession`

DB4_4 uczynił `TrainingSession` formalnym źródłem sesji szkoleniowej, Attendance i Ledger creditu. Calendar równocześnie ma `driving_lesson` z tymi samymi osiami: Student, Instructor, Vehicle, Location i czas. Brak relation/projection contract pomiędzy tymi modelami.

Ryzyko: dwie niezależne mutowalne kopie tej samej jazdy mogą różnić się terminem/zasobami; Calendar może zostać cancelled/completed niezależnie od formalnej TrainingSession.

Calendar `complete` nie może stać się drugim sposobem naliczania godzin — credited time musi nadal przechodzić przez DB-TRN-006.

**Nie naprawiono w diagnozie.**

## 5. Celowo niezakwalifikowane jako P1 w tym kroku

Nie blokują physical Calendar diagnosis:
- exact min/max duration,
- czy `Jazda` ma backendowo wymagane zasoby mimo optional labels w UI,
- warning vs hard-block dla wygasłych dokumentów — wymaga policy/legal decision,
- recurrence,
- notifications,
- drag & drop,
- persistence zaznaczeń filtrów,
- dokładny rendering `Ważnych dat` u konkurenta,
- dokładne znaczenie `!`,
- employee work-time UI,
- dokładne Stage-5 HTTP `If-Match` required markers/error codes.

Te elementy pozostają jawnie zachowane jako późniejsze decyzje; nie są usunięte ze scope produktu.

## 6. Kolejność napraw po diagnozie

Naprawiamy po jednym blockerze, każdorazowo z osobną bramką:

1. `DB-CAL-001` — same-tenant integrity,
2. `DB-CAL-002` — manual/system storage boundary + single-row invariants,
3. `DB-CAL-003` — race-safe overlap/conflict,
4. `DB-CAL-004` — event lifecycle/concurrency,
5. `DB-CAL-005` — own-scope resolver,
6. `DB-CAL-006` — availability booking lifecycle,
7. `DB-CAL-007` — formal TrainingSession integration,
8. final DB4_5 aggregate sync.

Ta kolejność jest celowa: conflict/lifecycle/authorization logic nie powinna być projektowana na relacjach, które wcześniej nie mają gwarancji same-tenant.

## 7. Bramka jakości diagnozy

**PASS — DIAGNOSIS COMPLETE.**

Spełnione warunki:
- komplet Calendar reverse-engineering został zachowany,
- availability/self-booking nie zostało pominięte,
- important-date source boundary został rozpoznany,
- zależność z formalnym TrainingSession została rozpoznana,
- RBAC `own` został potraktowany jako istniejący kontrakt, nie redefiniowany,
- wykryto 7 P1 i 0 P0,
- nie zastosowano żadnej naprawy,
- `core-schema.yml` i `docs/87` nie zostały zmienione w diagnozie,
- nie utworzono migracji Laravel,
- nie rozpoczęto DB4_6 ani późniejszych slice,
- nie rozpoczęto UI/feature implementation.

Następny pojedynczy krok po zapisaniu centralnego gate: **`DB-CAL-001` only**.
