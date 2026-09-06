# 109. Stage 4 — Calendar database audit

Data: 2026-09-06

**Etap:** `DB4_5_CALENDAR`  
**Aktualny krok:** `DB-CAL-003`  
**Status:** `DB-CAL-001..003 PASS / 4 P1 OPEN`

## 1. Zasada pracy

DB4_5 jest prowadzony pojedynczymi blockerami. Diagnoza wykazała 7 P1. Po wcześniejszym zamknięciu `DB-CAL-001` i `DB-CAL-002` w tym kroku rozwiązano **wyłącznie `DB-CAL-003` — race-safe resource overlap/conflict boundary**.

Nie zmieniono:
- `DB-CAL-004` lifecycle i optimistic concurrency CalendarEvent,
- `DB-CAL-005` `calendar.manage.own`,
- `DB-CAL-006` AvailabilitySlot booking lifecycle,
- `DB-CAL-007` Calendar ↔ formal `TrainingSession`.

Nie zmieniono też aggregate `specs/database/core-schema.yml` ani `docs/87-physical-database-schema.md`, nie utworzono migracji Laravel i nie rozpoczęto DB4_6/UI.

Machine-readable kontrakt: `specs/database/calendar.yml`.

## 2. Źródła i zachowane wymagania

Źródła funkcjonalne i kontraktowe:
- `docs/48-calendar-main-screen.md`,
- `docs/49-calendar-add-event-form.md`,
- `docs/50-own-calendar-event-lifecycle.md`,
- `docs/51-calendar-important-dates.md`,
- `docs/84-test-strategy.md`,
- `specs/screens/calendar.yml`,
- `specs/screens/calendar-add-event.yml`,
- `specs/api/paths/pkk-calendar.yaml`,
- `specs/api/openapi-components-v1.yaml`,
- `specs/api/required-operations-v1.yml`,
- `specs/security/permissions.yml`,
- `specs/database/staff-locations-vehicles.yml`,
- `specs/database/students-courses-training.yml`.

Potwierdzony produkt wymaga server-side conflict detection dla co najmniej:
- kursanta,
- instruktora,
- pojazdu,
- zarządzanej lokalizacji.

API create/update CalendarEvent deklaruje conflict validation, a strategia testów wymaga race testów kalendarza i bookingu.

## 3. DB-CAL-001 i DB-CAL-002 — zachowany PASS

DB-CAL-001 nadal gwarantuje, że Student/Instructor/Vehicle/Location wskazane przez CalendarEvent i AvailabilitySlot należą do tego samego OSK.

DB-CAL-002 nadal gwarantuje:
- `calendar_events` przechowuje wyłącznie ręczne `general_event|driving_lesson`,
- `important_date` jest systemową projekcją źródłowych terminów, nie mutable CalendarEvent,
- `location_id` i `custom_meeting_place` są zero-or-one source,
- custom text nie tworzy automatycznie `Location`.

DB-CAL-003 buduje conflict boundary na tych już zamkniętych inwariantach i ich nie osłabia.

## 4. Problem DB-CAL-003

Samo sprawdzenie:

`SELECT czy zasób jest wolny -> INSERT/UPDATE event`

nie jest bezpieczne przy współbieżności.

Dwa requesty mogą równolegle:
1. zobaczyć brak konfliktu,
2. oba przejść application validation,
3. oba spróbować zapisać nakładający się termin.

Bez finalnej granicy PostgreSQL oba mogłyby commitować.

Ryzyko dotyczy m.in.:
- create vs create,
- create vs reschedule/update,
- update dwóch różnych eventów do tego samego okna,
- przyszłego booking reservation vs CalendarEvent,
- dwóch bookingów konkurujących o te same zasoby.

## 5. Semantyka czasu

Canonical przedział konfliktu:

`[starts_at, ends_at)`

czyli start inclusive, end exclusive.

W PostgreSQL:

`tstzrange(starts_at, ends_at, '[)')`.

Konsekwencja:
- event A `10:00–11:00`,
- event B `11:00–12:00`

**nie są konfliktem**.

Natomiast każdy dodatni wspólny odcinek czasu jest overlapem.

`starts_at` i `ends_at` pozostają `timestamptz`/UTC; timezone organizacji wpływa na interpretację i prezentację lokalną, a nie na fizyczny instant zapisany w DB.

Nadal obowiązuje `ends_at > starts_at`.

## 6. Które zasoby konfliktują

Conflict key jest zawsze tenant-scoped i resource-specific.

Blokujemy overlap tego samego:
- `Student`,
- `StaffProfile` w roli Instruktora,
- `Vehicle`,
- zarządzanej `Location`.

UUID z dwóch różnych resource kinds nie konfliktują tylko dlatego, że ich tekstowa wartość UUID jest identyczna.

`custom_meeting_place`:
- nie jest zarządzanym zasobem OSK,
- nie tworzy Location claim,
- dwa eventy z takim samym tekstem nie są automatycznie konfliktem zasobowym.

`important_date`:
- jest projekcją informacyjną,
- nie zajmuje zasobu,
- nie tworzy conflict claim.

## 7. Który stan zajmuje zasób

Na potrzeby samej granicy konfliktowej bieżący known operational state `scheduled` zajmuje zasoby.

`completed` i `cancelled` nie mają aktywnych claims.

To **nie zamyka DB-CAL-004**. DB-CAL-003 nie projektuje jeszcze:
- pełnej state matrix,
- legalnych transitionów,
- cancellation/completion actor metadata,
- `If-Match` i increment rules,
- terminal edit policy.

DB-CAL-004 nadal jest osobnym P1. Tutaj określamy tylko, który już znany stan ma być traktowany jako aktywna rezerwacja przez conflict engine.

## 8. `calendar_resource_claims` — techniczna projekcja konfliktowa

Wprowadzony blueprint zakłada osobną techniczną tabelę `calendar_resource_claims`.

Nie jest ona drugim business source-of-truth. Jej rolą jest **bieżąca, transakcyjna projekcja zajętości zasobów**, na której PostgreSQL może fizycznie wymusić brak overlapów.

Każdy claim przechowuje co najmniej:
- własne ID,
- `organization_id`,
- owner kind + owner ID,
- dokładnie jeden z resource IDs: Student / Instructor / Vehicle / Location,
- `starts_at`,
- `ends_at`,
- generated `occupied_during tstzrange`,
- `created_at`.

DB check:

`num_nonnulls(student_id,instructor_id,vehicle_id,location_id) = 1`.

Claim jest technical current projection, więc jego usunięcie/replacement w tej samej transakcji co zmiana ownera nie jest utratą business history. Business history pozostaje w CalendarEvent/audicie i późniejszych lifecycle artifacts.

W DB-CAL-003 jedynym aktywnym owner kind jest `calendar_event`. Dodanie innych origin kinds wymaga osobnej późniejszej bramki.

## 9. Same-tenant integrity claims

Claim nadal podlega DB-CAL-001.

Każdy niepusty resource ID ma composite FK `(organization_id, resource_id)` do odpowiedniego tenant-owned parenta:
- Student,
- StaffProfile,
- Vehicle,
- Location.

Dzięki temu technical conflict projection również nie może połączyć OSK A z zasobem OSK B.

Dla `claim_owner_kind=calendar_event` owner ID musi rozwiązać się do CalendarEvent z tym samym `organization_id`; finalny transactional/deferrable DB guard nie pozwala na orphan/cross-tenant owner claim.

## 10. Exact claim set dla CalendarEvent

Dla `scheduled` eventu finalny stan transakcji musi mieć:
- dokładnie 1 Student claim iff `student_id != NULL`,
- dokładnie 1 Instructor claim iff `instructor_id != NULL`,
- dokładnie 1 Vehicle claim iff `vehicle_id != NULL`,
- dokładnie 1 Location claim iff `location_id != NULL`,
- dokładnie ten sam przedział czasu co event,
- dokładnie to samo `organization_id`.

Nie wolno pozostawić:
- brakującego claimu,
- dodatkowego claimu,
- claimu ze starym terminem po reschedule,
- claimu do zasobu, którego event już nie wskazuje.

Non-scheduled event ma zero aktywnych claims.

Final-state DB guard jest deferrable/transactional, aby event i jego claim set mogły zostać zmienione atomowo w jednej transakcji bez wymagania poprawnego stanu po każdym pojedynczym SQL statement.

## 11. Finalna granica PostgreSQL — GiST exclusion constraints

Wymagany jest `btree_gist` i cztery częściowe exclusion constraints na `calendar_resource_claims`.

Logicznie:

### Student
`organization_id WITH =`, `student_id WITH =`, `occupied_during WITH &&`

### Instructor
`organization_id WITH =`, `instructor_id WITH =`, `occupied_during WITH &&`

### Vehicle
`organization_id WITH =`, `vehicle_id WITH =`, `occupied_during WITH &&`

### Location
`organization_id WITH =`, `location_id WITH =`, `occupied_during WITH &&`

Każdy constraint jest aktywny wyłącznie dla niepustej kolumny odpowiedniego resource type.

To jest **finalna granica współbieżności**. Application-level precheck nadal może istnieć dla szybkiego i czytelnego UX, ale nie jest security/concurrency boundary.

Dwa równoległe requesty nie mogą więc oba commitować nakładającego się claimu tego samego zasobu w jednym OSK.

## 12. Create/update i rollback

### Create

Transakcja:
1. waliduje tenant/basic time,
2. tworzy CalendarEvent,
3. tworzy dokładny claim set,
4. claims są wkładane w deterministycznej kolejności,
5. GiST exclusion constraints rozstrzygają konflikt,
6. final-state guard sprawdza exact set,
7. dopiero wtedy commit.

### Update terminu/zasobów

Transakcja:
1. lockuje target CalendarEvent `FOR UPDATE`,
2. odczytuje bieżące claims,
3. usuwa technical current claim projection,
4. zmienia event,
5. tworzy replacement claim set,
6. GiST rozstrzyga overlap,
7. final-state guard sprawdza zgodność,
8. commit.

Jeżeli nowy termin konfliktuje, **cała transakcja rollbackuje**. Poprzedni event i poprzedni claim set pozostają nietknięte.

DB-CAL-003 nie ustala jeszcze optimistic-version semantics tego update — pozostaje to DB-CAL-004.

Dla ograniczenia deadlock risk claim processing ma stałą kolejność:
`Student -> Instructor -> Vehicle -> Location`.

Finalne bezpieczeństwo nie zależy jednak od advisory locka ani kolejności samej aplikacyjnej walidacji; zapewniają je exclusion constraints.

## 13. AvailabilitySlot — granica z DB-CAL-006

Samo opublikowanie dostępności **nie tworzy conflict claimu**.

Availability slot opisuje czas, w którym zasób ma być dostępny do rezerwacji. Gdyby sam slot zajmował zasób, model przeczyłby znaczeniu „dostępności”.

Jednocześnie przyszły successful booking **nie może** zostać committed bez wejścia do tego samego conflict boundary.

DB-CAL-006 nadal musi osobno ustalić:
- state machine slotu,
- dokładny business reservation effect,
- owner/link do claim setu,
- idempotency/book/cancel,
- reavailability po anulowaniu.

Ale niezależnie od wybranej reprezentacji booking reservation musi atomowo materializować claims przed stanem `booked/success`, dzięki czemu booking vs CalendarEvent i booking vs booking korzystają z tego samego GiST enforcement.

To zamyka mechanizm konfliktu, nie lifecycle bookingu.

## 14. Formal `TrainingSession` — granica z DB-CAL-007

DB-CAL-003 nie wybiera, czy canonical schedule ownerem jazdy będzie CalendarEvent czy formal TrainingSession.

DB-CAL-007 nadal musi rozstrzygnąć tę relację.

Wymóg narzucony przez DB-CAL-003 jest tylko jeden: po DB-CAL-007 jedna formalna jazda ma wystawiać **jeden effective reservation fact** do tego samego conflict boundary.

Claim insert:
- nie zalicza godzin,
- nie tworzy Attendance,
- nie tworzy Ledger credit,
- nie może omijać DB-TRN-006.

## 15. Conflict result i retry

GiST exclusion violation jest mapowane na domenowy calendar resource conflict.

Dokładny kod HTTP/error envelope pozostaje Stage 5 contract sync.

Zasady:
- konflikt nie commituję częściowej zmiany,
- system nie przesuwa automatycznie eventu,
- system nie zmienia automatycznie zasobu,
- system nie traktuje blind retry jako sukcesu,
- operator/klient może ponowić po refreshu lub zmianie danych.

Nieoczekiwany deadlock/serialization failure jest transient DB failure; ewentualny retry musi powtórzyć **całą transakcję wraz z constraintami**, a nie ominąć validation path.

## 16. Migration design DB-CAL-003

Migracji Laravel nadal nie tworzymy.

Przyszła kolejność:
1. zapewnić dostępność `btree_gist`,
2. potwierdzić dodatnie zakresy czasu,
3. zidentyfikować bieżące `scheduled` manual events,
4. wykonać precheck overlapów osobno dla Student/Instructor/Vehicle/Location w obrębie OSK,
5. przy istniejącym konflikcie zatrzymać migrację,
6. **nie** wybierać automatycznie zwycięzcy,
7. **nie** przesuwać czasu,
8. **nie** anulować eventu,
9. **nie** zerować resource ID,
10. utworzyć `calendar_resource_claims`,
11. dodać exactly-one-resource i same-tenant FKs,
12. dodać generated half-open `tstzrange`,
13. dodać partial uniques i cztery GiST exclusion constraints,
14. backfillować exact claims z niepustych zasobów `scheduled` eventów,
15. dodać owner/exact-set final-state guards,
16. uruchomić concurrency tests.

Nieznanego legacy statusu nie klasyfikujemy na ślepo jako claiming/nonclaiming. Full lifecycle constraints pozostają DB-CAL-004.

## 17. Testy wymagane przez DB-CAL-003

Obowiązkowo:
- `[10:00,11:00)` oraz `[11:00,12:00)` dla tego samego resource przechodzą,
- overlap tego samego Studenta jest odrzucany,
- overlap tego samego Instruktora jest odrzucany,
- overlap tego samego Vehicle jest odrzucany,
- overlap tej samej managed Location jest odrzucany,
- inne zasoby w tym samym czasie mogą commitować,
- ten sam UUID w innych resource kinds nie daje fałszywego konfliktu,
- ten sam resource identity w innym OSK nie konfliktuje,
- custom meeting place nie tworzy managed Location claim,
- `important_date` nie tworzy claimu,
- scheduled event ma dokładny claim set,
- bezpośrednia zmiana czasu/resource bez claim sync jest odrzucana,
- non-scheduled event nie ma aktywnych claims,
- dwa concurrent creates do tego samego zasobu/okna: maksymalnie jeden commit,
- create vs update do tego samego okna: maksymalnie jeden conflicting commit,
- dwa różne eventy concurrent-update do jednego okna: maksymalnie jeden conflicting commit,
- failed conflicting update zachowuje poprzedni event i jego claims,
- application precheck race nie omija GiST,
- publishing AvailabilitySlot nie zajmuje zasobu,
- przyszły booking nie może ominąć shared claim boundary,
- migration precheck wykrywa legacy overlaps,
- migracja nie naprawia overlapów przez auto-cancel/shift/reassign.

## 18. Self-audit DB-CAL-003

Wynik: **PASS**.

Sprawdzone:
- interval semantics są jednoznaczne i half-open,
- adjacent events nie dają false conflict,
- wszystkie 4 wymagane resource kinds mają finalną GiST boundary,
- claims zachowują same-tenant integrity DB-CAL-001,
- `important_date` i meeting-place semantics DB-CAL-002 są zachowane,
- finalną granicą nie jest check-then-insert,
- scheduled CalendarEvent ma exact transactional claim set,
- konflikt nie może zostawić częściowej zmiany eventu,
- Availability publication nie została pomylona z rezerwacją,
- DB-CAL-006 jest zobowiązany do shared boundary, ale jego booking lifecycle nie został rozwiązany,
- DB-CAL-007 jest zobowiązany do single effective reservation fact, ale canonical schedule owner nie został wybrany,
- nie rozwiązano DB-CAL-004..007,
- agregaty pozostały zamrożone,
- migracji Laravel nie utworzono,
- DB4_6 i UI nie rozpoczęto.

## 19. Pozostałe P1 po DB-CAL-003

Pozostają dokładnie **4 P1**:

### DB-CAL-004 — event lifecycle + optimistic concurrency

Nadal brak finalnej state matrix, terminal metadata oraz PATCH/cancel/complete expected-version/concurrency contract.

### DB-CAL-005 — `calendar.manage.own`

Nadal brak canonical owner resolver zgodnego z DB4_2.

### DB-CAL-006 — AvailabilitySlot booking lifecycle

Shared overlap boundary jest już określony, ale nadal otwarte są slot state matrix, exactly-once booking, book/cancel races, reservation owner/link i reavailability.

### DB-CAL-007 — `driving_lesson` vs formal `TrainingSession`

Shared conflict boundary jest już określony, ale nadal trzeba ustalić jeden canonical schedule owner i relację/projekcję bez drugiej mutable kopii formalnej jazdy.

## 20. Bramka jakości DB-CAL-003

**PASS.**

Stan po bramce tego artefaktu:
- P0: `0`,
- P1 rozwiązane w DB4_5: `3`,
- P1 otwarte: `4`,
- `DB-CAL-001`: `PASS`,
- `DB-CAL-002`: `PASS`,
- `DB-CAL-003`: `PASS`,
- final DB4_5 aggregate sync: nadal `PENDING`,
- DB4_6: zablokowane,
- Stage 5: zablokowany,
- UI/feature implementation: zablokowane.

Następny pojedynczy krok **dopiero po aktualizacji centralnego gate**: `DB-CAL-004` only.
