# 109. Stage 4 — Calendar database audit

Data: 2026-09-06

**Etap:** `DB4_5_CALENDAR`  
**Aktualny krok:** `DB-CAL-004`  
**Status:** `DB-CAL-001..004 PASS / 3 P1 OPEN`

## 1. Zasada pracy

DB4_5 jest prowadzony pojedynczymi blockerami. Diagnoza wykazała 7 P1. Po wcześniejszym zamknięciu `DB-CAL-001`, `DB-CAL-002` i `DB-CAL-003` w tym kroku rozwiązano **wyłącznie `DB-CAL-004` — lifecycle CalendarEvent i optimistic concurrency dla PATCH/cancel/complete**.

Nie zmieniono:
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

Potwierdzone capability CalendarEvent:
- create,
- update/reschedule,
- cancel z zachowaniem historii,
- complete,
- `version` w projekcji,
- `If-Match` już obecny na PATCH,
- `Idempotency-Key` na create/cancel/complete.

Własna polityka produktu definiuje statusy `scheduled|completed|cancelled`. DB-CAL-004 zamyka ich fizyczną semantykę i race behavior bez wchodzenia w DB-CAL-005..007.

## 3. DB-CAL-001, DB-CAL-002 i DB-CAL-003 — zachowany PASS

DB-CAL-001 nadal gwarantuje same-tenant composite FK dla Calendar/Availability resources.

DB-CAL-002 nadal gwarantuje:
- manualne `calendar_events` tylko `general_event|driving_lesson`,
- `important_date` jako systemową projekcję,
- zero-or-one source miejsca spotkania.

DB-CAL-003 nadal gwarantuje:
- half-open interval `[starts_at,ends_at)`,
- `calendar_resource_claims`,
- finalną GiST exclusion boundary dla Student/Instructor/Vehicle/Location,
- exact claim set dla scheduled CalendarEvent,
- zero claims dla non-scheduled eventu,
- `DEFERRABLE INITIALLY DEFERRED` final-state guard.

DB-CAL-004 korzysta z tych inwariantów i ich nie osłabia.

## 4. Problem DB-CAL-003 — historyczny zapis diagnozy

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

## 5. Semantyka czasu DB-CAL-003

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

`scheduled` zajmuje zasoby.

`completed` i `cancelled` nie mają aktywnych claims.

DB-CAL-004 domyka teraz pełną state matrix i transitions, ale nie zmienia tej wcześniej ustalonej semantyki zajętości.

## 8. `calendar_resource_claims` — techniczna projekcja konfliktowa

Blueprint zakłada osobną techniczną tabelę `calendar_resource_claims`.

Nie jest ona drugim business source-of-truth. Jej rolą jest bieżąca, transakcyjna projekcja zajętości zasobów, na której PostgreSQL może fizycznie wymusić brak overlapów.

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

W DB-CAL-003 jedynym aktywnym owner kind jest `calendar_event`; DB wymaga `CHECK (claim_owner_kind = 'calendar_event')` do czasu osobnej późniejszej bramki rozszerzającej owner kinds.

## 9. Same-tenant integrity claims

Każdy niepusty resource ID ma composite FK `(organization_id, resource_id)` do odpowiedniego tenant-owned parenta.

Dla `claim_owner_kind=calendar_event` owner ID musi rozwiązać się do CalendarEvent z tym samym `organization_id`.

## 10. Exact claim set dla CalendarEvent

Dla `scheduled` eventu finalny stan transakcji musi mieć:
- dokładnie 1 Student claim iff `student_id != NULL`,
- dokładnie 1 Instructor claim iff `instructor_id != NULL`,
- dokładnie 1 Vehicle claim iff `vehicle_id != NULL`,
- dokładnie 1 Location claim iff `location_id != NULL`,
- dokładnie ten sam przedział czasu co event,
- dokładnie to samo `organization_id`.

Non-scheduled event ma zero aktywnych claims.

Final-state DB guard jest `DEFERRABLE INITIALLY DEFERRED` albo równoważną transactional DB boundary.

## 11. Finalna granica PostgreSQL — GiST exclusion constraints

Wymagany jest `btree_gist` i cztery częściowe exclusion constraints na `calendar_resource_claims` dla Student/Instructor/Vehicle/Location.

To jest finalna granica współbieżności. Application precheck może istnieć dla UX, ale nie jest boundary.

## 12. Create/update i rollback DB-CAL-003

Create tworzy Event + exact claims i dopiero po przejściu GiST/final-state guard commit.

Update lockuje Event `FOR UPDATE`, zastępuje claim projection i przy konflikcie rollbackuje całą zmianę, zachowując poprzedni event i claims.

Stała kolejność claim processing:
`Student -> Instructor -> Vehicle -> Location`.

DB-CAL-004 dopina teraz expected-version i lifecycle history do tego samego transaction path.

## 13. AvailabilitySlot — granica z DB-CAL-006

Samo opublikowanie dostępności nie tworzy conflict claimu.

Przyszły successful booking musi wejść do tego samego conflict boundary, ale state machine/book/cancel/reavailability pozostają DB-CAL-006.

## 14. Formal `TrainingSession` — granica z DB-CAL-007

DB-CAL-003/004 nie wybierają jeszcze canonical schedule ownera formalnej jazdy.

Calendar complete:
- nie tworzy Attendance,
- nie tworzy TrainingHourLedger credit,
- nie może omijać DB-TRN-006.

To pozostaje krytyczną granicą do DB-CAL-007.

## 15. Conflict result i retry

GiST exclusion violation mapuje się na domenowy calendar resource conflict.

Konflikt nie commituję częściowej zmiany i nie powoduje automatycznego przesunięcia/reassignmentu. Exact HTTP error pozostaje Stage 5 contract sync.

## 16. Migration design DB-CAL-003 — zachowany

Migracji Laravel nadal nie tworzymy. Legacy overlaps wymagają jawnej remediation; migracja nie wybiera zwycięzcy, nie przesuwa eventów, nie anuluje i nie zeruje resource IDs.

## 17. Testy wymagane przez DB-CAL-003 — zachowane

Pozostają obowiązkowe concurrency, overlap, exact-claim i migration-precheck tests ustalone w machine spec.

## 18. Self-audit DB-CAL-003

Wynik pozostaje **PASS**.

## 19. Historyczna bramka DB-CAL-003

DB-CAL-003 został zamknięty jako PASS z `3/7 resolved` i `4 P1 open`. Następnym blockerem był DB-CAL-004, który jest przedmiotem bieżącej sekcji poniżej.

---

# DB-CAL-004 — CalendarEvent lifecycle + optimistic concurrency

## 20. Problem

Przed DB-CAL-004 fizyczny model miał tylko wolne `status` i `version`, mimo że produkt i API posiadają osobne operacje:
- PATCH eventu,
- cancel,
- complete.

Bez zamkniętej state matrix i wspólnej serializacji możliwe byłyby m.in.:
- PATCH nadpisujący równoległe cancel,
- complete i cancel oba uznane za skuteczne,
- terminalny event dalej trzymający resource claims,
- direct SQL zmieniający status bez historii,
- dwa materialne update'y z tym samym `version` bez jednoznacznego zwycięzcy.

## 21. Canonical status i terminal metadata

Dozwolone statusy CalendarEvent:
- `scheduled`,
- `completed`,
- `cancelled`.

DB ma zamknięty CHECK dla tego katalogu.

### `scheduled`
- `completed_at = NULL`,
- `completed_by_user_id = NULL`,
- `cancelled_at = NULL`,
- `cancelled_by_user_id = NULL`,
- `cancellation_reason = NULL`,
- exact DB-CAL-003 claim set dla niepustych zasobów.

### `completed`
- `completed_at != NULL`,
- `completed_by_user_id != NULL`,
- wszystkie cancellation fields `NULL`,
- zero aktywnych claims.

### `cancelled`
- `cancelled_at != NULL`,
- `cancelled_by_user_id != NULL`,
- `cancellation_reason` może być `NULL`,
- completion fields `NULL`,
- zero aktywnych claims.

`completed_by_user_id` i `cancelled_by_user_id` mają zwykłe globalne FK do `users(id)` z `RESTRICT`; nie inventujemy fake tenant key na User.

## 22. Transition matrix

Normalny lifecycle:
- create -> `scheduled`, version `1`,
- material PATCH: `scheduled -> scheduled`, version +1,
- complete: `scheduled -> completed`, version +1,
- cancel: `scheduled -> cancelled`, version +1.

Normalnie zabronione:
- completed -> scheduled,
- cancelled -> scheduled,
- completed -> cancelled,
- cancelled -> completed,
- generic PATCH statusu lub terminal metadata.

Nie wymyślamy teraz normalnego restore/reopen. Jeżeli produkt później będzie potrzebował exceptional correction, musi mieć własny audytowany kontrakt; zwykły PATCH nie może symulować korekty historii.

## 23. Jeden concurrency root: `calendar_events.version`

`calendar_events.version`:
- `bigint NOT NULL DEFAULT 1`,
- `CHECK version >= 1`,
- jest jedynym concurrency rootem CalendarEvent.

Materialny PATCH, cancel i complete wymagają expected version na granicy domenowej.

PATCH już ma `If-Match` w Stage-3 API. Cancel/complete obecnie mają `Idempotency-Key`, ale nie mają jeszcze `If-Match`. DB4_5 nie zmienia OpenAPI w tym kroku — dokładny required-marker dla cancel/complete jest jawnie zapisany do **Stage 5 acceptance contract sync**.

`Idempotency-Key` nie zastępuje expected version dla nowego commandu.

Każda materialna mutacja:
1. lockuje `calendar_events` `FOR UPDATE`,
2. po locku porównuje expected version,
3. dopiero potem waliduje/zmienia stan.

Stale version = conflict bez partial write.

Semantic no-op PATCH:
- nie zwiększa version,
- nie dopisuje history eventu,
- nie zmienia `updated_at`.

## 24. Append-only `calendar_event_lifecycle_events`

DB-CAL-004 wprowadza dedykowaną historię materialnych wersji CalendarEvent.

Minimalne pola:
- `id`,
- `organization_id`,
- `calendar_event_id`,
- `event_type`,
- `from_status`,
- `to_status`,
- `event_version_before`,
- `event_version_after`,
- `actor_user_id`,
- `reason`,
- `changed_fields_redacted`,
- `occurred_at`.

Runtime event types:
- `created`,
- `updated`,
- `completed`,
- `cancelled`.

Dla legacy migration dopuszczony jest wyłącznie techniczny `migration_baseline`.

Same-tenant FK:
`(organization_id,calendar_event_id) -> calendar_events(organization_id,id)`.

`actor_user_id` ma globalny FK do `users(id)` i jest nullable wyłącznie dla `migration_baseline`.

Partial/history shortcut nie wystarcza. Wymagamy dwóch warstw:
1. `UNIQUE(organization_id,calendar_event_id,event_version_after)` — at most one history row per version,
2. **DEFERRABLE INITIALLY DEFERRED history/version final-state guard** — finalny CalendarEvent musi mieć dokładnie jeden history row z `event_version_after = calendar_events.version`, a `to_status` tego row musi odpowiadać bieżącemu statusowi.

Runtime successor ma `after = before + 1`. Migration baseline może zakotwiczyć nieznaną wcześniejszą historię bez jej fabrykowania.

Direct zmiana `version` lub `status` bez odpowiadającego lifecycle eventu ma zostać odrzucona przy commit.

## 25. Jeden `command_effective_at`

Self-audit poprawił początkowe sformułowanie „commit time”. PostgreSQL nie daje nam magicznego dokładnego timestampu commit jako zwykłej kolumny commandu.

Canonical rozwiązanie:
- po locku dla nowego materialnego commandu uchwycić jeden `command_effective_at` (`timestamptz`),
- użyć go dla `cancelled_at` albo `completed_at`,
- użyć tego samego instant jako `occurred_at` odpowiadającego lifecycle eventu.

Dzięki temu row i historia nie rozjeżdżają się czasowo.

## 26. PATCH transaction

Dla scheduled event:
1. `FOR UPDATE`,
2. expected-version check,
3. status musi być `scheduled`,
4. generic PATCH nie może zawierać terminal fields/status,
5. DB-CAL-001/002 validations,
6. semantic no-op detection,
7. dla materialnej zmiany uchwycenie `command_effective_at`,
8. jeżeli zmieniono czas/resource — replacement claim set zgodnie z DB-CAL-003,
9. zapis material fields,
10. version +1 dokładnie raz,
11. append `updated` lifecycle event dla tej samej wersji,
12. commit tylko po GiST + deferred claim/history guards.

Terminalny CalendarEvent nie jest normalnie patchowalny.

## 27. Cancel transaction

API zachowuje `Idempotency-Key`; domain boundary wymaga także expected version.

Nowy command:
1. idempotency claim/replay,
2. lock Event,
3. expected-version check,
4. status `scheduled`,
5. capture `command_effective_at`,
6. usunięcie wszystkich current CalendarEvent claims,
7. `status=cancelled`,
8. `cancelled_at=command_effective_at`,
9. actor,
10. optional reason,
11. version +1,
12. append `cancelled` history event z tym samym instant,
13. deferred claim/history guards,
14. commit.

Retry tego samego Idempotency-Key + same payload zwraca pierwotny rezultat bez drugiego version increment. Inny key przeciw już cancelled Event = conflict, nie druga cancellation.

## 28. Complete transaction

Analogicznie:
1. idempotency claim/replay,
2. lock Event,
3. expected-version check,
4. status `scheduled`,
5. capture `command_effective_at`,
6. usunięcie claims,
7. `status=completed`,
8. `completed_at=command_effective_at`,
9. actor,
10. version +1,
11. append `completed` history event z tym samym instant,
12. deferred guards,
13. commit.

**Calendar complete nie nalicza godzin szkolenia.** Nie tworzy Attendance ani TrainingHourLedger credit. Formalne efekty pozostają DB-CAL-007 + DB-TRN-006.

## 29. Race matrix

### PATCH vs PATCH, ten sam expected version
Maksymalnie jeden materialny mutation commit. Drugi dostaje stale conflict.

### PATCH vs cancel
Ten, kto pierwszy lockuje i commituję zgodnie z expected version, wygrywa. Drugi po locku dostaje stale version albo terminal-state conflict. Brak partial write.

### PATCH vs complete
Identycznie.

### cancel vs complete
Dokładnie jedna terminal transition może commitować.

### dwa cancel z różnymi Idempotency-Key, ten sam expected version
Jeden sukces, drugi conflict.

### dwa complete z różnymi Idempotency-Key
Jeden sukces, drugi conflict.

### retry tego samego Idempotency-Key
Zwraca ten sam rezultat bez nowego efektu.

Lock order dla event + claims pozostaje:
1. CalendarEvent,
2. Student claim,
3. Instructor claim,
4. Vehicle claim,
5. Location claim.

## 30. Terminal event policy

Po `completed` lub `cancelled`:
- normalny PATCH zabroniony,
- normalny hard delete zabroniony,
- zero current resource claims,
- event pozostaje odczytywalny jako historia,
- lifecycle history pozostaje append-only,
- przyszła korekta nie może nadpisywać starych history rows.

Nie wprowadzamy temporalnej zasady „nie można complete przed ends_at”, bo taki wymóg nie jest jeszcze potwierdzoną regułą produktu. To nie jest potrzebne do zamknięcia concurrency P1.

## 31. Migration design DB-CAL-004

Migracji Laravel nadal nie tworzymy.

Przyszła kolejność:
1. precheck existing status values,
2. unknown status -> explicit review, bez inferencji z czasu/nazwy,
3. `version` non-null bigint >=1,
4. terminal metadata columns początkowo nullable,
5. zebrać wiarygodne audit/domain evidence dla terminal actor/time,
6. nie fabrykować terminal timestamps z `updated_at`,
7. nie fabrykować aktora z `created_by_user_id`,
8. dodać globalne actor FKs,
9. dodać status/terminal metadata checks,
10. utworzyć append-only lifecycle history,
11. dodać lifecycle actor FK,
12. dla legacy utworzyć po jednym `migration_baseline` na bieżący event version,
13. dodać unique per event/version,
14. dodać history immutability,
15. dodać `DEFERRABLE INITIALLY DEFERRED` current-version/history guard,
16. zsynchronizować DB-CAL-003 claim backfill tylko dla finalnie `scheduled`,
17. uruchomić PATCH/cancel/complete race tests.

Jeżeli wymaganych terminal metadata nie można odtworzyć z wiarygodnego evidence, migracja ma się zatrzymać albo wymagać jawnej remediation — nie zgadujemy.

## 32. Testy DB-CAL-004

Obowiązkowo m.in.:
- create -> scheduled/version1/claims/created history,
- unknown status rejected,
- scheduled z terminal metadata rejected,
- completed wymaga completed_at+actor i nie może mieć cancellation metadata,
- cancelled wymaga cancelled_at+actor i nie może mieć completion metadata,
- actor IDs muszą istnieć w global users,
- generic PATCH nie ustawia terminal state,
- material PATCH + matching version -> +1,
- semantic no-op -> bez +1 i bez history,
- stale PATCH -> zero partial effects,
- terminal PATCH rejected,
- cancel i complete usuwają claims atomowo,
- terminal row i history mają ten sam `command_effective_at`,
- idempotency replay nie robi drugiego version bump,
- PATCH/cancel/complete races mają maksymalnie jednego zwycięzcę,
- current Event version ma dokładnie jeden matching history row,
- direct version/status update bez matching history rejected at commit,
- lifecycle history immutable,
- Calendar complete nie nalicza godzin szkolenia,
- migration nie zgaduje statusu/actora/timestampu.

## 33. Self-audit DB-CAL-004

Wynik: **PASS**.

Self-audit wykrył i poprawił przed finalnym gate:
1. początkowe nieprecyzyjne `commit time` -> jeden `command_effective_at`,
2. brak at-least-one boundary dla history -> deferrable current-version/history final-state guard,
3. brak jawnych globalnych FK terminal/history actorów -> `users(id)` + `RESTRICT`.

Po hardeningu potwierdzono:
- state catalog i terminal metadata matrix są zamknięte,
- version jest jednym concurrency rootem,
- expected version jest porównywany po row locku,
- PATCH/cancel/complete serializują się na tym samym Event row,
- one current version -> exactly one matching history row przy commit,
- matching history status odpowiada current Event status,
- semantic no-op nie tworzy sztucznej wersji,
- terminal Event nie jest zwyczajnie patchowany ani hard-delete'owany,
- claims są zwalniane atomowo z terminal transition,
- history i terminal metadata używają jednego command effective instant,
- Calendar complete nie tworzy formalnego training credit,
- DB-CAL-005..007 pozostają nietknięte,
- agregaty zamrożone,
- brak migracji Laravel,
- brak DB4_6/UI.

## 34. Pozostałe P1 po DB-CAL-004

Pozostają dokładnie **3 P1**:

### DB-CAL-005 — `calendar.manage.own`
Brak canonical own resolver zgodnego z DB4_2 dla CalendarEvent i AvailabilitySlot.

### DB-CAL-006 — AvailabilitySlot booking lifecycle
Same-tenant i shared GiST boundary są gotowe, ale state matrix, exactly-once booking, reservation link i cancel/reavailability nadal otwarte.

### DB-CAL-007 — `driving_lesson` vs formal `TrainingSession`
Nadal trzeba ustalić jeden canonical schedule owner i relację/projekcję bez drugiej mutable kopii formalnej jazdy.

## 35. Bramka jakości DB-CAL-004

**PASS — po self-audit hardeningu.**

Stan artefaktu przed centralnym gate:
- P0: `0`,
- P1 rozwiązane w DB4_5: `4`,
- P1 otwarte: `3`,
- `DB-CAL-001`: `PASS`,
- `DB-CAL-002`: `PASS`,
- `DB-CAL-003`: `PASS`,
- `DB-CAL-004`: `PASS`,
- final DB4_5 aggregate sync: nadal `PENDING`,
- DB4_6: zablokowane,
- Stage 5: zablokowany,
- UI/feature implementation: zablokowane.

Następny pojedynczy krok dopiero po centralnym gate: **`DB-CAL-005` only**.
