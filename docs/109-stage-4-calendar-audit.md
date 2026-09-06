# 109. Stage 4 — Calendar database audit

Data: 2026-09-06

**Etap:** `DB4_5_CALENDAR`  
**Aktualny krok:** `DB-CAL-006`  
**Status:** `DB-CAL-001..006 PASS / 1 P1 OPEN`

## 1. Zasada pracy

DB4_5 jest prowadzony pojedynczymi blockerami. Diagnoza wykazała 7 P1. Pełny zapis DB-CAL-003, DB-CAL-004 i DB-CAL-005 pozostaje poniżej bez kondensowania. Bieżący DB-CAL-006 został dopisany jako kolejny osobny appendix, bez przepisywania potwierdzonych wcześniejszych decyzji.

W bieżącym kroku nie zmieniono:
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

To **nie zamyka DB-CAL-004** w historycznym kroku DB-CAL-003. DB-CAL-003 nie projektował wtedy jeszcze:
- pełnej state matrix,
- legalnych transitionów,
- cancellation/completion actor metadata,
- `If-Match` i increment rules,
- terminal edit policy.

DB-CAL-004 jest domknięty dopiero w appendixie poniżej. Tutaj historycznie określono tylko, który już znany stan ma być traktowany jako aktywna rezerwacja przez conflict engine.

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

W DB-CAL-003 jedynym aktywnym owner kind jest `calendar_event`. Self-audit doprecyzował, że nie może to być wyłącznie konwencja aplikacyjna: wymagany jest DB `CHECK (claim_owner_kind = 'calendar_event')`. Dzięki temu nieznany owner kind nie może utworzyć orphan claimu i zablokować zasobu bez odpowiadającego owner guard. Rozszerzenie katalogu owner kinds będzie możliwe dopiero w późniejszej, jawnej bramce DB-CAL-006/007.

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

Final-state DB guard jest **`DEFERRABLE INITIALLY DEFERRED`** (albo równoważną transactional DB boundary), aby event i jego claim set mogły zostać zmienione atomowo w jednej transakcji bez wymagania poprawnego stanu po każdym pojedynczym SQL statement, ale z obowiązkowo poprawnym stanem przy commit.

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

DB-CAL-003 historycznie nie ustalał optimistic-version semantics tego update — domyka je appendix DB-CAL-004 poniżej.

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

DB-CAL-004 dodatkowo potwierdza, że samo Calendar `complete` również nie może tworzyć formalnego training credit.

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
13. dodać `claim_owner_kind = 'calendar_event'` CHECK,
14. dodać partial uniques i cztery GiST exclusion constraints,
15. backfillować exact claims z niepustych zasobów `scheduled` eventów,
16. dodać owner/exact-set `DEFERRABLE INITIALLY DEFERRED` guards,
17. uruchomić concurrency tests.

Nieznanego legacy statusu nie klasyfikujemy na ślepo jako claiming/nonclaiming. Full lifecycle constraints są domknięte w DB-CAL-004, ale migracja nadal wymaga jawnej klasyfikacji nieznanych legacy values.

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
- nieznany `claim_owner_kind` jest odrzucany przez DB,
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
- nieznany `claim_owner_kind` nie może utworzyć niekontrolowanego orphan claimu,
- exact-set guard jest odroczony do finalnego stanu transakcji, a nie pominięty,
- finalną granicą nie jest check-then-insert,
- scheduled CalendarEvent ma exact transactional claim set,
- konflikt nie może zostawić częściowej zmiany eventu,
- Availability publication nie została pomylona z rezerwacją,
- DB-CAL-006 jest zobowiązany do shared boundary, ale jego booking lifecycle nie został rozwiązany,
- DB-CAL-007 jest zobowiązany do single effective reservation fact, ale canonical schedule owner nie został wybrany,
- w historycznym kroku nie rozwiązano DB-CAL-004..007,
- agregaty pozostały zamrożone,
- migracji Laravel nie utworzono,
- DB4_6 i UI nie rozpoczęto.

## 19. Pozostałe P1 po DB-CAL-003 — historyczny stan

Po DB-CAL-003 pozostawały dokładnie **4 P1**:

### DB-CAL-004 — event lifecycle + optimistic concurrency

Wtedy brakowało finalnej state matrix, terminal metadata oraz PATCH/cancel/complete expected-version/concurrency contract. **Ten blocker jest obecnie zamknięty w appendixie DB-CAL-004 poniżej.**

### DB-CAL-005 — `calendar.manage.own`

Nadal brak canonical owner resolver zgodnego z DB4_2.

### DB-CAL-006 — AvailabilitySlot booking lifecycle

Shared overlap boundary jest określony, ale nadal otwarte są slot state matrix, exactly-once booking, book/cancel races, reservation owner/link i reavailability.

### DB-CAL-007 — `driving_lesson` vs formal `TrainingSession`

Shared conflict boundary jest określony, ale nadal trzeba ustalić jeden canonical schedule owner i relację/projekcję bez drugiej mutable kopii formalnej jazdy.

## 20. Bramka jakości DB-CAL-003 — historyczna

**PASS.**

Stan po tej bramce:
- P0: `0`,
- P1 rozwiązane w DB4_5: `3`,
- P1 otwarte: `4`,
- `DB-CAL-001`: `PASS`,
- `DB-CAL-002`: `PASS`,
- `DB-CAL-003`: `PASS`,
- final DB4_5 aggregate sync: `PENDING`,
- DB4_6: zablokowane,
- Stage 5: zablokowany,
- UI/feature implementation: zablokowane.

Historycznie następnym pojedynczym krokiem był `DB-CAL-004`.

---

# DB-CAL-004 — CalendarEvent lifecycle + optimistic concurrency

## 21. Źródła DB-CAL-004

DB-CAL-004 opiera się dodatkowo na:
- `docs/50-own-calendar-event-lifecycle.md`,
- Stage-3 Calendar API w `specs/api/paths/pkk-calendar.yaml`,
- `CalendarEvent`, `UpdateCalendarEventRequest` i `IfMatch` z `specs/api/openapi-components-v1.yaml`.

Potwierdzone capability:
- create CalendarEvent,
- PATCH z `If-Match`,
- cancel z `Idempotency-Key`,
- complete z `Idempotency-Key`,
- history-preserving cancellation,
- `version` w CalendarEvent projection.

## 22. Problem DB-CAL-004

Przed tym krokiem fizyczny model nie określał wystarczająco:
- zamkniętego katalogu statusów,
- terminal metadata consistency,
- jednego concurrency root,
- required expected-version semantics dla wszystkich materialnych mutacji,
- PATCH-vs-cancel-vs-complete race behavior,
- trwałej historii każdej materialnej wersji,
- finalnej DB boundary wiążącej current `version/status` z historią.

Samo `status varchar + version` nie wystarczało.

## 23. Canonical status i terminal metadata

Dozwolone statusy:
- `scheduled`,
- `completed`,
- `cancelled`.

### `scheduled`
Wszystkie completion/cancellation metadata muszą być `NULL`. Event ma exact DB-CAL-003 claims dla swoich niepustych zasobów.

### `completed`
Wymagane:
- `completed_at`,
- `completed_by_user_id`.

Zabronione:
- `cancelled_at`,
- `cancelled_by_user_id`,
- `cancellation_reason`.

Claims = zero.

### `cancelled`
Wymagane:
- `cancelled_at`,
- `cancelled_by_user_id`.

`cancellation_reason` jest nullable, zgodnie z API/body semantics.

Completion metadata = `NULL`. Claims = zero.

`completed_by_user_id` i `cancelled_by_user_id` są globalnymi FK do `users(id)` z `RESTRICT`. Nie dodajemy sztucznego tenant key do globalnego User.

## 24. Transition matrix

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

Nie inventujemy normalnego restore/reopen. Future exceptional correction, jeśli będzie wymagany, musi mieć osobny audytowany kontrakt i nie może nadpisywać istniejącej historii.

## 25. `calendar_events.version` jako jedyny concurrency root

`version`:
- `bigint NOT NULL DEFAULT 1`,
- `CHECK version >= 1`.

Materialny PATCH, cancel i complete wymagają expected version na granicy domenowej.

PATCH już ma `If-Match` w Stage 3. Cancel/complete obecnie mają Idempotency-Key, ale nie mają `If-Match`; exact HTTP required-marker zostaje jawnie przeniesiony do **Stage 5 acceptance contract sync**.

`Idempotency-Key` nie zastępuje expected version dla nowego commandu.

Każda materialna mutacja:
1. lock `calendar_events FOR UPDATE`,
2. compare expected version **po locku**,
3. dopiero potem state validation/mutation.

Stale version -> conflict, zero partial write.

Semantic no-op PATCH:
- bez version bump,
- bez history eventu,
- bez zmiany `updated_at`.

## 26. `calendar_event_lifecycle_events`

Wprowadzamy append-only material-version history:
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

Migration-only:
- `migration_baseline`.

Same-tenant event FK:
`(organization_id,calendar_event_id) -> calendar_events(organization_id,id)`.

Actor FK:
`actor_user_id -> users(id)`; nullable wyłącznie dla migration baseline.

`UNIQUE(organization_id,calendar_event_id,event_version_after)` daje at-most-one history row per version.

Self-audit wykazał, że to jeszcze nie daje at-least-one. Dlatego dodany został **`DEFERRABLE INITIALLY DEFERRED` current-version/history final-state guard**:
- finalny CalendarEvent ma dokładnie jeden history row z `event_version_after = calendar_events.version`,
- jego `to_status` musi odpowiadać current `calendar_events.status`,
- runtime successor ma `after = before + 1`,
- direct status/version change bez odpowiadającej historii jest odrzucany przy commit,
- migration baseline może zakotwiczyć nieznaną wcześniejszą historię bez jej fabrykowania.

Business history row po insert jest immutable.

## 27. Jeden `command_effective_at`

Pierwszy machine draft używał zbyt mocnego sformułowania „commit time”. Self-audit poprawił je przed gate.

Dla nowego materialnego commandu po locku uchwycony jest jeden:

`command_effective_at timestamptz`

Ten sam instant jest używany dla:
- `cancelled_at` / `completed_at`,
- `occurred_at` odpowiadającego lifecycle eventu.

Nie twierdzimy, że jest to magiczny dokładny PostgreSQL commit timestamp.

## 28. Create transaction

Create korzysta z API Idempotency-Key i atomowo:
1. claim/replay idempotency,
2. DB-CAL-001 tenant validation,
3. DB-CAL-002 type/meeting-place validation,
4. insert `scheduled`, version `1`,
5. exact DB-CAL-003 claims,
6. GiST conflict boundary,
7. append `created` lifecycle event version `1`,
8. deferred claim/history guards,
9. commit.

Błąd rollbackuje Event, claims i history.

## 29. Scheduled PATCH transaction

1. Event `FOR UPDATE`,
2. compare expected version,
3. wymóg `scheduled`,
4. status/terminal metadata forbidden w generic PATCH,
5. same-tenant + row invariants,
6. semantic no-op detection,
7. dla materialnej zmiany capture `command_effective_at`,
8. jeżeli time/resources się zmieniają: replacement claims w stałej kolejności,
9. apply business fields,
10. version +1 dokładnie raz,
11. append `updated` lifecycle event tej samej wersji,
12. GiST + deferred claim/history guards,
13. commit.

Terminal Event -> normal PATCH conflict.

## 30. Cancel transaction

Nowy cancel command:
1. claim/replay Idempotency-Key,
2. Event `FOR UPDATE`,
3. expected-version check,
4. status musi być `scheduled`,
5. capture `command_effective_at`,
6. remove all Event claims,
7. status `cancelled`,
8. `cancelled_at = command_effective_at`,
9. `cancelled_by_user_id = actor`,
10. nullable reason,
11. version +1,
12. append `cancelled` history event z tym samym instant,
13. deferred guards,
14. commit.

Same key + same payload retry -> ten sam rezultat, bez drugiego version bump.

Inny key na już cancelled Event -> conflict, nie drugi cancel effect.

## 31. Complete transaction

Analogicznie:
1. idempotency replay/claim,
2. Event `FOR UPDATE`,
3. expected version,
4. `scheduled`,
5. capture `command_effective_at`,
6. remove claims,
7. `completed`,
8. `completed_at = command_effective_at`,
9. actor,
10. version +1,
11. append `completed` history event z tym samym instant,
12. deferred guards,
13. commit.

**Calendar complete nie tworzy Attendance i nie nalicza TrainingHourLedger credit.** Formalny pipeline pozostaje DB-CAL-007 + DB-TRN-006.

## 32. Race matrix

### PATCH vs PATCH, ten sam expected version
Maksymalnie jeden materialny mutation commit; drugi stale conflict.

### PATCH vs cancel
Pierwszy poprawny lock+commit wygrywa; drugi stale-version albo terminal-state conflict. Zero partial write.

### PATCH vs complete
Analogicznie.

### cancel vs complete
Dokładnie jedna terminal transition może commitować.

### dwa cancel / dwa complete z różnymi keys i tym samym expected version
Jeden sukces, jeden conflict.

### ten sam Idempotency-Key replay
Pierwotny rezultat, zero drugiego efektu.

Lock order:
1. CalendarEvent,
2. Student claim,
3. Instructor claim,
4. Vehicle claim,
5. Location claim.

## 33. Terminal event policy

Po completed/cancelled:
- normal business PATCH forbidden,
- normal hard delete forbidden,
- zero current claims,
- Event/history pozostają odczytywalne,
- przyszła correction nie może rewrite'ować history rows.

Nie hardkodujemy niepotwierdzonej reguły „complete dopiero po ends_at”. To jest osobna product policy, nie warunek zamknięcia tego P1.

## 34. Migration design DB-CAL-004

Migracji Laravel nadal nie tworzymy.

Przyszła kolejność:
1. precheck status values,
2. unknown status -> explicit review,
3. `version` -> nonnull bigint >=1,
4. terminal metadata columns nullable initially,
5. reliable audit/domain evidence dla actor/time,
6. brak fabrykowania terminal timestamp z `updated_at`,
7. brak fabrykowania actor z `created_by_user_id`,
8. global actor FKs,
9. status/metadata checks,
10. lifecycle history table,
11. lifecycle actor FK,
12. one migration baseline per existing Event current version,
13. unique per Event/version,
14. history immutability,
15. deferred current-version/history final-state guard,
16. DB-CAL-003 claim backfill tylko dla final `scheduled`,
17. race tests.

Jeśli terminal metadata nie da się odtworzyć wiarygodnie, migracja ma FAIL/review, nie guess.

## 35. Testy DB-CAL-004

Obowiązkowo:
- create -> scheduled/version1/claims/history,
- unknown status rejected,
- scheduled z terminal metadata rejected,
- completed wymaga completed_at+actor i forbids cancel metadata,
- cancelled wymaga cancelled_at+actor i forbids completion metadata,
- terminal actor FK musi wskazywać User,
- generic PATCH nie zmienia statusu/terminal metadata,
- material PATCH + matching version -> +1,
- semantic no-op -> bez fake version/history,
- stale PATCH -> zero partial effects,
- terminal PATCH rejected,
- cancel/complete remove claims atomowo,
- row/history dzielą ten sam command_effective_at,
- idempotency replay -> zero drugiego efektu,
- PATCH/cancel/complete races -> jeden materialny winner,
- current Event version ma dokładnie jeden matching history row,
- history `to_status` odpowiada current Event status,
- direct status/version update bez history rejected at commit,
- history immutable,
- Calendar complete nie nalicza training hours,
- migration nie zgaduje statusu/actora/timestampu.

## 36. Self-audit DB-CAL-004

Wynik: **PASS**.

Self-audit wykrył przed formalnym gate i naprawił:
1. `commit time` -> pojedynczy `command_effective_at`,
2. at-most-one history bez at-least-one -> deferred current-version/history guard,
3. brak jawnych terminal/history actor FK -> global `users(id)` + `RESTRICT`.

Po poprawce potwierdzono:
- status/terminal matrix closed,
- one Event concurrency root,
- expected version after row lock,
- PATCH/cancel/complete share same serialization root,
- current version requires exactly one matching history row,
- history status matches Event status,
- no-op nie tworzy fake version,
- terminal Event nie jest normalnie patchowany/hard-deleted,
- terminal transition atomowo zwalnia claims,
- row/history mają ten sam command effective instant,
- stale/conflict nie zostawia partial state,
- idempotency nie zastępuje expected version dla nowego commandu,
- Calendar complete nie nalicza formalnego training credit,
- DB-CAL-005..007 pozostają otwarte,
- aggregate frozen,
- zero Laravel migrations,
- zero DB4_6/UI.

## 37. Pozostałe P1 po DB-CAL-004

Pozostają dokładnie **3 P1**:

### DB-CAL-005 — `calendar.manage.own`
Canonical own resolver dla CalendarEvent/AvailabilitySlot nadal nie jest zamknięty.

### DB-CAL-006 — AvailabilitySlot booking lifecycle
Shared GiST boundary istnieje, ale slot state matrix, exactly-once booking, reservation owner/link i cancel/reavailability pozostają otwarte.

### DB-CAL-007 — `driving_lesson` vs formal `TrainingSession`
Nadal trzeba wybrać jeden canonical schedule owner i uniknąć dwóch niezależnych mutable schedule facts.

## 38. Bramka jakości DB-CAL-004

**PASS — po self-audit hardeningu.**

Stan przed centralnym gate:
- P0: `0`,
- P1 rozwiązane w DB4_5: `4`,
- P1 otwarte: `3`,
- DB-CAL-001..004: `PASS`,
- final DB4_5 aggregate sync: `PENDING`,
- DB4_6: zablokowane,
- Stage 5: zablokowany,
- UI/feature implementation: zablokowane.

Historycznie następnym pojedynczym krokiem był **DB-CAL-005 only**.

---

# DB-CAL-005 — canonical `calendar.manage.own` owner resolution

## 39. Źródła DB-CAL-005

Ten krok korzysta przede wszystkim z już zamkniętego RBAC:
- `specs/security/permissions.yml`,
- `specs/database/identity-rbac.yml`,
- `specs/database/staff-locations-vehicles.yml`,
- `docs/04-roles-permissions.md`,
- Stage-3 Calendar API w `specs/api/paths/pkk-calendar.yaml`.

Nie redefiniujemy `own`. DB4_2 mówi już:
- tenant validation jest przed scope resolution,
- `own` wymaga canonical owner relation do bieżącego User albo StaffProfile połączonego z aktywnym membership,
- brak owner relation = DENY,
- frontend filtering nie jest security boundary,
- `calendar.manage.own` ma profil `own_only`,
- `calendar.publish_student_slots` dopuszcza `organization|own`,
- `calendar.view` może OR-ować `organization|assigned_students|assigned_locations|own`.

## 40. Problem DB-CAL-005

CalendarEvent miał jednocześnie:
- `created_by_user_id`,
- opcjonalny `instructor_id`.

AvailabilitySlot miał opcjonalny `instructor_id`.

Bez jawnej decyzji backend mógłby różnie interpretować „mój event”:
- raz jako „utworzony przeze mnie”,
- innym razem jako „prowadzony przeze mnie”,
- albo tylko filtrować to w UI.

To byłoby niebezpieczne. Sekretariat może stworzyć jazdę dla instruktora B — autor rekordu nie powinien przez to stać się domenowym właścicielem jazdy.

## 41. Canonical owner — istniejący `instructor_id`

Nie dodajemy drugiej kolumny ownera.

Canonical owner dla `own`:
- `CalendarEvent` -> `calendar_events.instructor_id`,
- `AvailabilitySlot` -> `availability_slots.instructor_id`.

Owner identity jest `StaffProfile`.

DB-CAL-001 już fizycznie gwarantuje, że `(organization_id,instructor_id)` wskazuje StaffProfile z tego samego OSK.

`created_by_user_id`, `completed_by_user_id`, `cancelled_by_user_id` i późniejsze lifecycle actor fields są provenance/auditem, **nie ownership relation**.

## 42. Runtime resolver `own`

Canonical ścieżka:

`authenticated User -> active OrganizationMembership -> active StaffMembershipLink -> StaffProfile -> target.instructor_id`

Allow dopiero, gdy:
- membership jest aktywny,
- target jest w tym samym tenant,
- istnieje aktywny StaffMembershipLink (`unlinked_at IS NULL`),
- `target.instructor_id == linked_staff_profile.id`,
- konkretne permission jest granted i ma scope `own`,
- domain state również pozwala na akcję.

Fail-closed:
- brak staff linku,
- `instructor_id = NULL`,
- tylko zgodność `created_by_user_id`,
- ten sam globalny User bez aktywnego staff linku,
- cross-tenant target.

## 43. CalendarEvent — general vs driving lesson

### `general_event` z instruktorem
Ownerem jest wskazany `instructor_id`. Instruktor może zarządzać eventem przez `own`, jeśli jego aktywny staff link wskazuje ten sam StaffProfile.

### `general_event` bez instruktora
Nie ma canonical owner relation. `own` = DENY.

Taki event nadal może być zarządzany przez osobę z `calendar.manage.organization` + organization scope.

Nie wolno uzupełnić ownera przez `created_by_user_id`.

### `driving_lesson` z instruktorem
Ownerem own-scope jest `instructor_id`.

### `driving_lesson` bez instruktora
`own` = DENY. Nie wymuszamy tu jednak jeszcze formalnej reguły, że driving lesson zawsze musi mieć instruktora — to należy do DB-CAL-007 i integracji z TrainingSession.

## 44. AvailabilitySlot

Analogicznie:
- slot z `instructor_id` ma ownera StaffProfile,
- slot bez `instructor_id` jest ownerless dla `own`,
- osoba z `calendar.publish_student_slots` + own może publikować tylko slot dla własnego linked StaffProfile,
- organization scope może publikować slot ownerless albo dla innego same-tenant StaffProfile, o ile pozostałe reguły domenowe na to pozwalają.

`calendar.book_for_student` pozostaje permission typu student-scoped. DB-CAL-005 nie zmienia autoryzacji bookingu w slot-own i nie rozwiązuje jego lifecycle — to DB-CAL-006.

## 45. Create pod `own`

### CalendarEvent
Jeżeli command korzysta z `calendar.manage.own`:
- aktywny staff link jest wymagany,
- proposed `instructor_id` musi być non-null,
- musi równać się linked StaffProfile,
- nie wolno wskazać innego instruktora,
- nie wolno pominąć instruktora i „odziedziczyć” ownera z autora requestu.

Przy `calendar.manage.organization` own match nie jest wymagany; nadal obowiązuje tenant/domain validation.

### AvailabilitySlot
Ta sama reguła dla own branch `calendar.publish_student_slots`.

## 46. PATCH i transfer ownership

Own-scope nie może służyć do eskalacji przez zmianę właściciela.

Dla istniejącego targetu pod `own`:
1. current `instructor_id` musi odpowiadać linked StaffProfile,
2. po locku targetu ownership jest ponownie sprawdzany,
3. proposed final `instructor_id` również musi pozostać tym samym StaffProfile.

Zatem pod `own` zabronione jest:
- przypisanie eventu/slotu innemu instruktorowi,
- wyczyszczenie `instructor_id`.

Reassignment jest możliwy tylko przez odpowiedni organization scope. Po takim commicie nowy `instructor_id` staje się nowym canonical ownerem dla przyszłych own checks.

## 47. Cancel / complete i stan terminalny

Dla CalendarEvent cancel/complete pod `own` current owner jest sprawdzany **po Event row locku**.

Terminal command nie zmienia `instructor_id`.

Pozostałe reguły DB-CAL-004 pozostają bez zmian:
- expected version,
- lifecycle state,
- history event,
- claim release,
- idempotency.

## 48. Unlink / archive / restore

Własność jest rozwiązywana przez **aktywną** relację membership↔StaffProfile, a nie przez historycznego autora.

Po `StaffMembershipLink.unlinked_at != NULL`:
- historyczny `instructor_id` eventu/slotu pozostaje,
- ten membership przestaje spełniać own scope,
- organization-scope management może nadal działać.

Po archive StaffProfile:
- DB4_3 nie pozwala pozostawić active StaffMembershipLink,
- own scope fail-closed,
- historyczny `instructor_id` nie jest zerowany.

Po restore StaffProfile:
- stary link nie jest automatycznie otwierany,
- own access nie wraca automatycznie,
- potrzebny jest jawny nowy aktywny link.

Jeżeli później inny membership zostanie jawnie aktywnie połączony z tym samym StaffProfile i ma odpowiednie permission/scope, może rozwiązać istniejące targety jako own. Ownership identity jest StaffProfile, nie „pierwszy User, który kiedyś utworzył event”.

## 49. Authorization concurrency / TOCTOU

Nie wystarcza sprawdzić StaffMembershipLink przed długą mutacją i później ignorować jego zmianę.

Own mutation ma:
1. zwalidować active membership i tenant,
2. rozwiązać matching active StaffMembershipLink,
3. utrzymać stabilność owner proof do commitu — preferowane `FOR SHARE` na matching link albo równoważna serializowana rewalidacja,
4. dla istniejącego targetu lock `FOR UPDATE`,
5. po locku ponownie sprawdzić current owner,
6. sprawdzić expected version, jeśli command tego wymaga,
7. dla PATCH sprawdzić post-state owner,
8. dopiero wykonać mutation i istniejące DB-CAL-003/004 guards.

Unlink/archive nie może „zniknąć” w środku requestu bez zdefiniowanej serializacji owner proof.

Permission/membership revocation lifecycle pozostaje zgodny z DB4_2 `authorization_version` i nie jest tutaj redefiniowany.

## 50. Query enforcement

Ten sam owner adapter obowiązuje także tam, gdzie `calendar.view` ma scope `own` dla manual CalendarEvent.

Filtrowanie musi odbywać się w backend query / authorization relation dla:
- list,
- get-by-id,
- search,
- count,
- export.

Vue post-filter nie jest security boundary.

Pozostałe istniejące scope'y `assigned_students`, `assigned_locations`, `organization` nadal są OR-owane zgodnie z DB4_2 i **nie zmieniamy ich definicji**.

DB-CAL-005 nie zmienia source-specific own adaptera dla `important_date`; nie jest on wymagany do zamknięcia blockera `calendar.manage.own`.

## 51. Migration design DB-CAL-005

Migracji Laravel nadal nie tworzymy.

Nie potrzebujemy:
- nowej kolumny owner,
- backfillu ownera,
- konwersji `created_by_user_id` -> ownership.

Legacy row z `instructor_id != NULL` używa istniejącego instruktora jako canonical ownera own-scope.

Legacy row z `instructor_id = NULL` pozostaje ownerless dla `own`. **Nie wolno** inferować ownera z:
- `created_by_user_id`,
- lifecycle actorów,
- nazwy eventu,
- innych heurystyk.

Przy przyszłej migracji warto mieć indeksy do scope filtering:
- `(organization_id,instructor_id)` na `calendar_events`,
- `(organization_id,instructor_id)` na `availability_slots`.

To indeksy wydajnościowe, nie nowy source-of-truth.

## 52. Testy DB-CAL-005

Obowiązkowo:
- own match wymaga active membership + active StaffMembershipLink + matching `instructor_id`,
- `instructor_id=NULL` deny nawet gdy creator=current user,
- creator=current user nie daje own, jeśli instructor wskazuje inną osobę,
- general event z self instructor jest own-manageable,
- general event bez instructor wymaga non-own management scope,
- driving lesson z self instructor jest own-manageable,
- driving lesson z innym/null instructor jest deny pod own,
- own create wymaga proposed self instructor,
- own PATCH nie może transferować ani zerować instructor,
- organization manage może reassign w granicach same-tenant/domain rules,
- cancel/complete pod own rewalidują current owner po Event locku,
- slot own resolver używa `availability_slots.instructor_id`,
- own slot create/update nie może wskazać innego/NULL ownera,
- organization branch slot management nie wymaga own match,
- `calendar.book_for_student` nie zostaje przedefiniowane jako slot own,
- unlink usuwa own access bez rewrite historii,
- archive usuwa own access bez zerowania instructor,
- restore bez nowego linku nie przywraca own,
- jawny nowy link do tego samego StaffProfile może ponownie spełnić own,
- brak aktywnego linku = deny nawet dla tego samego globalnego User,
- cross-tenant deny zachodzi przed owner resolverem,
- `calendar.view` own filtruje manual events backendowo przez instructor owner,
- pozostałe scope semantics pozostają bez zmian,
- legacy NULL instructor nie jest backfillowany z created_by,
- unlink-vs-own-mutation race nie może użyć znikającego owner proof bez serializacji.

## 53. Self-audit DB-CAL-005

Wynik: **PASS**.

Potwierdzono:
- `own` z DB4_2 nie został przedefiniowany,
- CalendarEvent owner = istniejący `instructor_id`,
- AvailabilitySlot owner = istniejący `instructor_id`,
- nie dodano drugiej kolumny owner,
- null owner fail-closed,
- `created_by_user_id` nie jest authorization shortcut,
- own create nie może utworzyć ownerless/foreign-owner targetu,
- own PATCH nie może transferować/zerować ownera,
- organization scope może zarządzać ownerless/reassign zgodnie z istniejącymi regułami,
- unlink/archive/restore zachowują semantykę DB4_3,
- list/get/search/count/export są backend-scope enforced,
- `assigned_students`, `assigned_locations`, `organization` nie zostały przedefiniowane,
- booking lifecycle DB-CAL-006 nie został rozwiązany,
- formal schedule owner DB-CAL-007 nie został rozwiązany,
- agregaty są nadal zamrożone,
- brak migracji Laravel,
- brak DB4_6/UI implementation.

## 54. Pozostałe P1 po DB-CAL-005

Pozostają dokładnie **2 P1**:

### DB-CAL-006 — AvailabilitySlot booking lifecycle
Do zamknięcia pozostają slot status/field matrix, exactly-once booking, reservation effect w shared conflict boundary, book/cancel races oraz reavailability.

### DB-CAL-007 — `driving_lesson` vs formal `TrainingSession`
Do zamknięcia pozostaje jeden canonical schedule owner i jednoznaczna relacja/projekcja bez dwóch niezależnych mutable schedule facts.

## 55. Bramka jakości DB-CAL-005

**PASS — machine contract + security-scope self-audit.**

Stan przed centralnym gate:
- P0: `0`,
- P1 rozwiązane w DB4_5: `5`,
- P1 otwarte: `2`,
- DB-CAL-001..005: `PASS`,
- final DB4_5 aggregate sync: `PENDING`,
- DB4_6: zablokowane,
- Stage 5: zablokowany,
- UI/feature implementation: zablokowane.

Następny pojedynczy krok dopiero po centralnym gate: **DB-CAL-006 only**.

---

# DB-CAL-006 — AvailabilitySlot booking lifecycle + exactly-once

## 56. Źródła i granica DB-CAL-006

Ten krok opiera się na:
- Stage-3 `/availability-slots`, `/book` i `/cancel` w `specs/api/paths/pkk-calendar.yaml`,
- `AvailabilitySlot`, `CreateAvailabilitySlotRequest`, `UpdateAvailabilitySlotRequest` i `BookAvailabilitySlotRequest` w `specs/api/openapi-components-v1.yaml`,
- race/idempotency wymaganiach z `docs/84-test-strategy.md`,
- DB-CAL-001 same-tenant integrity,
- DB-CAL-003 shared GiST conflict boundary,
- DB-CAL-005 slot owner/scope semantics.

API potwierdza, że `/book` ma **atomowo zarezerwować slot dla jednego Studenta**, a test strategy wymaga, by dwa równoległe bookingi jednego slotu/zasobu dały maksymalnie jeden poprawny booking.

DB-CAL-006 nie rozstrzyga jeszcze relacji formalnej jazdy do `TrainingSession`. To pozostaje wyłącznie DB-CAL-007.

## 57. Zamknięty lifecycle AvailabilitySlot

Canonical statusy:
- `available`,
- `booked`,
- `cancelled`.

### `available`
- `booked_student_id = NULL`,
- `booked_at = NULL`,
- zero booking claims,
- PATCH dozwolony,
- booking dozwolony,
- cancel dozwolony.

### `booked`
- `booked_student_id != NULL`,
- `booked_at != NULL`,
- exact booking claim set,
- normalny PATCH zabroniony,
- drugi booking zabroniony,
- cancel dozwolony.

### `cancelled`
- current `booked_student_id = NULL`,
- current `booked_at = NULL`,
- zero booking claims,
- PATCH zabroniony,
- booking zabroniony,
- kolejny nowy cancel command zabroniony.

DB wymusza zgodność statusu z booking fields. Generic PATCH nie może ustawiać statusu ani pól bookingu bez dedykowanego commandu.

Normalny hard delete nie jest lifecycle slotu.

## 58. Reavailability jest jawna, nie ukryta

Anulowany slot jest terminalny.

Nie stosujemy automatycznego:
- `booked -> available`,
- `cancelled -> available`,
- tworzenia nowego available slotu jako side-effect cancel.

Jeżeli OSK chce ponownie wystawić ten sam termin, publikuje **nowy AvailabilitySlot**. Future explicit reopen, jeśli kiedyś będzie potrzebny, dostanie osobną bramkę.

Ta decyzja zapobiega sytuacji, w której cancel po czasie albo świadome wycofanie terminu przypadkiem ponownie publikuje dostępność.

## 59. `availability_slots.version` — jeden concurrency root

`version` jest `bigint NOT NULL DEFAULT 1 CHECK >= 1`.

PATCH, book i cancel serializują się na tym samym `availability_slots` row:
1. `SELECT ... FOR UPDATE`,
2. expected version sprawdzany po locku,
3. dopiero potem state transition/mutation.

PATCH już ma `If-Match` w Stage 3. Book/cancel obecnie mają Idempotency-Key, ale nie `If-Match`; wymaganie expected version istnieje na granicy domenowej, a exact HTTP marker zostaje do Stage 5 acceptance contract sync.

`Idempotency-Key` nie zastępuje expected version dla nowego commandu.

Semantic no-op PATCH nie podnosi version, nie zmienia `updated_at` i nie tworzy historii.

## 60. Append-only `availability_slot_lifecycle_events`

Każda materialna wersja slotu ma historię:
- `id`,
- `organization_id`,
- `availability_slot_id`,
- `event_type`,
- `from_status`,
- `to_status`,
- `slot_version_before`,
- `slot_version_after`,
- `actor_user_id`,
- `booking_student_id_snapshot`,
- `reason`,
- `changed_fields_redacted`,
- `occurred_at`.

Runtime eventy:
- `created`,
- `updated`,
- `booked`,
- `cancelled`.

Migration-only:
- `migration_baseline`.

Historia jest append-only. `UNIQUE(organization_id,availability_slot_id,slot_version_after)` daje at-most-one per material version.

Dodatkowy **`DEFERRABLE INITIALLY DEFERRED` current-version/history guard** wymaga przy commit:
- dokładnie jednego history row dla current `availability_slots.version`,
- `to_status` zgodnego z current slot status,
- runtime successor `after = before + 1`.

Bez matching history nie można bezpośrednio przepisać statusu/version/booking fields.

`booking_student_id_snapshot` zachowuje Studenta z bookingu również wtedy, gdy późniejszy cancel czyści current booking fields.

## 61. Shared GiST boundary — owner kind `availability_slot_booking`

DB-CAL-003 zostaje rozszerzony o drugi jawny owner kind:
- `calendar_event`,
- `availability_slot_booking`.

DB check:

`claim_owner_kind IN ('calendar_event','availability_slot_booking')`.

Nieznany owner kind nadal jest odrzucany.

Dla `availability_slot_booking` claim owner musi rozwiązać się do **tego samego tenant i tego samego AvailabilitySlot**. Finalny owner guard jest deferrable.

Booked slot przy commit musi mieć dokładnie:
- 1 Student claim równy `booked_student_id`,
- 1 Instructor claim iff `instructor_id != NULL`,
- 1 Vehicle claim iff `vehicle_id != NULL`,
- 1 Location claim iff `location_id != NULL`,
- każdy claim z dokładnie tym samym `[starts_at,ends_at)`,
- to samo `organization_id`.

`available` i `cancelled` muszą mieć zero booking claims.

Exact-set guard jest `DEFERRABLE INITIALLY DEFERRED` albo równoważną transactional DB boundary.

Te same cztery GiST exclusion constraints z DB-CAL-003 zabezpieczają:
- booking vs CalendarEvent,
- booking vs booking,
- Student,
- Instructor,
- Vehicle,
- Location.

Samo opublikowanie `available` slotu nadal **nie zajmuje zasobów**.

## 62. Booking nie tworzy drugiego CalendarEvent

Successful booking nie tworzy niezależnego mutable `CalendarEvent`.

Canonical current reservation owner w DB-CAL-006 to:

`AvailabilitySlot(status='booked')`.

Conflict projection to jego claims z `claim_owner_kind='availability_slot_booking'`.

Calendar może później wyświetlać booked slot jako read-model projection, ale taki projection:
- nie jest drugim source-of-truth,
- nie jest bezpośrednio mutowany jak manual CalendarEvent,
- może być odbudowany ze slotu.

To celowo nie przesądza DB-CAL-007. Jeżeli booked slot później stanie się formalną jazdą, DB-CAL-007 musi zdefiniować atomowy handoff/ownership tak, by pozostał **jeden effective reservation fact**, a nie dwa nakładające się sources.

## 63. Book transaction

Book używa `calendar.book_for_student` i istniejącego student-scoped RBAC wobec żądanego Studenta. Nie przedefiniowujemy bookingu jako `slot own`.

Transakcja:
1. claim/replay Idempotency-Key,
2. active membership + same-tenant slot,
3. DB-CAL-001 same-tenant Student validation,
4. autoryzacja `calendar.book_for_student` dla requested Student,
5. slot `FOR UPDATE`,
6. expected-version check,
7. wymagany status `available`,
8. revalidation obecnych zasobów i czasu slotu,
9. capture jednego `command_effective_at`,
10. status -> `booked`,
11. `booked_student_id` -> requested Student,
12. `booked_at = command_effective_at`,
13. exact claims w kolejności Student -> Instructor -> Vehicle -> Location,
14. istniejące GiST constraints przyjmują albo odrzucają,
15. version +1 dokładnie raz,
16. append `booked` lifecycle event z Student snapshot i tym samym command instant,
17. deferred exact-claim + history guards,
18. audit/outbox w tej samej transakcji, gdy calendar domain events są materializowane,
19. commit.

GiST conflict rollbackuje **wszystko**: status, booking fields, claims, version i history. Slot pozostaje wcześniejszym `available`.

Same Idempotency-Key + ten sam payload zwraca ten sam rezultat bez drugiego efektu. Ten sam key + inny Student/payload = idempotency conflict. Inny key przeciw booked/cancelled = conflict, nie drugi booking.

## 64. PATCH i cancel

### PATCH
Normalny PATCH działa tylko dla `available`.

Po locku:
- expected version,
- state = available,
- DB-CAL-001 resource validation,
- DB-CAL-005 owner pre/post check dla own scope,
- status i booking fields nie są generic-patchowalne,
- material change -> version +1 + `updated` history,
- zero booking claims pozostaje final invariant.

`booked` i `cancelled` są niepatchowalne w normalnym flow.

### Cancel available
- `available -> cancelled`,
- version +1,
- historia `cancelled`,
- booking fields pozostają NULL,
- zero claims.

### Cancel booked
- zapamiętujemy pre-cancel Student,
- usuwamy wszystkie booking claims,
- czyścimy current `booked_student_id` i `booked_at`,
- `booked -> cancelled`,
- version +1,
- append history z poprzednim Student snapshot,
- finalnie zero claims.

Cancel korzysta z `calendar.publish_student_slots` i DB-CAL-005 organization|own scope. Current owner jest rewalidowany po slot locku.

Ten sam cancel Idempotency-Key replay nie tworzy drugiego efektu. Nowy command przeciw już cancelled slotowi jest conflict.

## 65. Race matrix

### book vs book — ten sam slot/version
Maksymalnie jeden commit. Drugi dostaje stale-version/state conflict.

### book vs cancel
Pierwszy poprawny lock+commit wygrywa. Drugi nie pozostawia partial state.

### PATCH vs book
Pierwszy poprawny lock+commit wygrywa. Drugi dostaje stale-version albo non-available conflict.

### PATCH vs cancel
Analogicznie.

### dwa różne sloty, ten sam zasób i overlap
Oba mogą być opublikowane jako `available`, ponieważ publikacja nie jest rezerwacją. Przy bookingach wspólne GiST constraints pozwalają maksymalnie jednemu konfliktującemu reservation commit.

### booking vs istniejący CalendarEvent
GiST odrzuca booking. Cały booking rollbackuje, slot pozostaje `available` na poprzedniej wersji.

Lock order:
1. AvailabilitySlot,
2. Student claim,
3. Instructor claim,
4. Vehicle claim,
5. Location claim.

## 66. Migration design DB-CAL-006

Migracji Laravel nadal nie tworzymy.

Przyszła kolejność:
1. precheck statusów i booking field pairs,
2. unknown/inconsistent status -> explicit review,
3. `version` -> bigint nonnull >=1,
4. status/field checks,
5. `availability_slot_lifecycle_events`,
6. same-tenant history relations i actor FK,
7. migration baseline tylko z wiarygodnego current state,
8. unique one history per version + immutability,
9. deferred current-version/history guard,
10. precheck wiarygodnie booked slotów vs CalendarEvent claims i inne booked slots,
11. legacy overlap/ambiguous booking -> FAIL/review,
12. dodać owner guard dla `availability_slot_booking`, podczas gdy stary claim-kind check nadal blokuje jego użycie,
13. dopiero wtedy rozszerzyć claim-kind check do dwóch wartości,
14. backfill exact claims tylko dla reliably booked slots,
15. deferred exact claim-set guard,
16. potwierdzić zero claims dla available/cancelled,
17. race tests.

Nie wolno:
- zgadywać statusu z samego `booked_at`, Student ID, czasu lub `updated_at`,
- inventować Studenta/timestampu dla rzekomego bookingu,
- automatycznie wybierać zwycięzcy overlapu,
- anulować/modyfikować slotu tylko po to, by constraint przeszedł,
- zmieniać booked na available,
- tworzyć CalendarEvent z legacy booked slotu,
- fabrykować actora migration baseline.

## 67. Testy DB-CAL-006

Obowiązkowo:
- create -> available/version1/no booking fields/no claims/history,
- unknown status rejected,
- available wymaga NULL booking fields i zero claims,
- booked wymaga Student+booked_at+exact claims,
- cancelled wymaga NULL current booking fields i zero claims,
- cross-tenant booked Student rejected,
- material available PATCH -> version +1 + history,
- semantic no-op -> bez fake version/history,
- booked/cancelled normal PATCH rejected,
- own PATCH nadal nie transferuje instructor owner,
- book wymaga student-scoped `calendar.book_for_student`,
- book atomowo ustawia status/Student/time/version/history/claims,
- slot bez optional resources nadal tworzy Student claim,
- claim interval = slot interval,
- dwa concurrent book same slot -> maksymalnie jeden commit,
- idempotency replay -> zero drugiego book effect,
- ten sam key + inny Student -> conflict,
- booking vs CalendarEvent conflict -> pełny rollback do available,
- dwa różne overlapujące sloty tego samego Instructor/Vehicle/Location/Student -> maksymalnie jeden booking,
- PATCH vs book i book vs cancel -> jeden materialny winner,
- cancel available -> cancelled/history,
- cancel booked -> remove claims + clear current booking + preserve Student snapshot in history,
- cancel replay -> zero drugiego efektu,
- cancelled nie jest auto-republished i nie można go rebookować,
- current version ma dokładnie jeden matching history row,
- direct status/version/booking-field rewrite bez history rejected,
- history immutable,
- unknown claim owner kind nadal rejected,
- fake claims dla available/cancelled rejected,
- booked claim removal without state transition rejected,
- booking nie tworzy niezależnego CalendarEvent,
- booking/claim insert nie tworzy Attendance ani TrainingHourLedger credit,
- migration nie zgaduje statusu/Studenta/timestampu/actora/overlap winnera.

## 68. Self-audit DB-CAL-006

Wynik: **PASS**.

Potwierdzono:
- status/booking-field matrix jest zamknięty,
- slot ma jeden concurrency root `version`,
- book/cancel expected version sprawdzany po slot locku,
- booked/cancelled nie są generic PATCH mutable,
- successful booking ma jeden canonical reservation owner bez drugiego CalendarEvent,
- `availability_slot_booking` jest DB-constrained owner kind,
- booked exact claim set jest sprawdzany na finalnym stanie transakcji,
- booking używa istniejących Student/Instructor/Vehicle/Location GiST constraints,
- booking-vs-event i booking-vs-booking są race-safe w DB,
- failed booking nie zostawia partial version/history/claims,
- cancel zachowuje historię i zwalnia claims,
- cancel nie republishuje automatycznie dostępności,
- lifecycle history jest append-only i version-paired,
- same-tenant Student boundary DB-CAL-001 została zachowana,
- student-scoped booking permission DB4_2 nie został przedefiniowany,
- DB-CAL-005 slot-owner semantics nie zostały przedefiniowane,
- booking nie nalicza formalnych godzin,
- **DB-CAL-007 pozostaje otwarte i nie zostało rozwiązane w tym kroku**,
- aggregate pozostają zamrożone,
- brak migracji Laravel,
- brak DB4_6/UI implementation.

## 69. Pozostały P1 po DB-CAL-006

Pozostaje dokładnie **1 P1**:

### DB-CAL-007 — `driving_lesson` vs formal `TrainingSession`

Do zamknięcia pozostaje wybór jednego canonical schedule ownera oraz relacji/projekcji, która nie pozostawi dwóch niezależnych mutable schedule facts. DB-CAL-007 musi również ustalić atomic handoff z booked availability reservation, jeśli taka rezerwacja staje się formalną jazdą, oraz zapewnić jeden effective reservation fact w shared conflict boundary.

## 70. Bramka jakości DB-CAL-006

**PASS — machine contract + lifecycle/concurrency/claim self-audit.**

Stan przed centralnym gate:
- P0: `0`,
- P1 rozwiązane w DB4_5: `6`,
- P1 otwarte: `1`,
- DB-CAL-001..006: `PASS`,
- DB-CAL-007: `OPEN`,
- final DB4_5 aggregate sync: `PENDING`,
- DB4_6: zablokowane,
- Stage 5: zablokowany,
- UI/feature implementation: zablokowane.

Następny pojedynczy krok dopiero po centralnym gate: **DB-CAL-007 only**.
