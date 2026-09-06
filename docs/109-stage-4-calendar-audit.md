# 109. Stage 4 — Calendar database audit

Data: 2026-09-06

**Etap:** `DB4_5_CALENDAR`  
**Aktualny krok:** `DB-CAL-002`  
**Status:** `DB-CAL-001..002 PASS / 5 P1 OPEN`

## 1. Zasada pracy

DB4_5 jest prowadzony pojedynczymi blockerami. Diagnoza wykazała 7 P1. Po wcześniejszym zamknięciu `DB-CAL-001` w tym kroku rozwiązano **wyłącznie `DB-CAL-002` — granicę ręcznych CalendarEvent vs systemowej projekcji `important_date` oraz invariant miejsca spotkania**.

Nie zmieniono:
- `DB-CAL-003` overlap/conflict concurrency,
- `DB-CAL-004` lifecycle eventu,
- `DB-CAL-005` `calendar.manage.own`,
- `DB-CAL-006` availability booking lifecycle,
- `DB-CAL-007` Calendar ↔ formal `TrainingSession`.

Nie zmieniono też aggregate `specs/database/core-schema.yml` ani `docs/87-physical-database-schema.md`, nie utworzono migracji Laravel i nie rozpoczęto DB4_6/UI.

Machine-readable kontrakt: `specs/database/calendar.yml`.

## 2. Źródła i zachowane wymagania

Źródła funkcjonalne i kontraktowe:
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

Potwierdzony ręczny formularz ma dwa typy:
- `general_event` / „Wydarzenie”,
- `driving_lesson` / „Jazda”.

`Ważne daty` są filtrem systemowym i nie występują w selektorze ręcznego tworzenia. Dla naszego produktu zostały wcześniej jawnie ustalone jako systemowa projekcja źródłowych terminów, nie drugi ręczny rekord kalendarza.

Formularz miejsca spotkania ma dwa wzajemnie wykluczające się tryby:
- zapisana `Location`,
- dowolny `custom_meeting_place`.

Miejsce jest opcjonalne, więc poprawny jest także stan bez obu wartości.

## 3. DB-CAL-001 — zachowany PASS

DB-CAL-001 pozostaje bez zmian i nadal wymusza same-tenant composite FK dla:
- `calendar_events.student_id`,
- `calendar_events.instructor_id`,
- `calendar_events.vehicle_id`,
- `calendar_events.location_id`,
- `availability_slots.instructor_id`,
- `availability_slots.vehicle_id`,
- `availability_slots.location_id`,
- `availability_slots.booked_student_id`.

Relacje opcjonalne używają `MATCH SIMPLE`, historyczne odwołania `RESTRICT`, a globalny `created_by_user_id` nie jest sztucznie tenant-scoped.

DB-CAL-002 nie osłabia żadnej z tych granic.

## 4. DB-CAL-002 — problem A: ręczny event vs `important_date`

Przed tym krokiem fizyczny blueprint miał wolne `event_type varchar`. Sam fakt, że UI ręcznie pokazuje tylko dwa typy, nie chronił bazy przed zapisaniem np.:
- `important_date`,
- innego systemowego typu,
- nieznanej wartości omijającej kontrakt formularza.

Byłoby to szczególnie niebezpieczne dla `important_date`, ponieważ termin dokumentu już ma canonical ownera w domenie Staff/Vehicle. Skopiowanie daty do niezależnego mutable `calendar_events` tworzyłoby dwa źródła prawdy i ryzyko rozjazdu.

## 5. DB-CAL-002 — decyzja: `calendar_events` tylko dla ręcznych wpisów

`calendar_events` jest fizycznym magazynem **manual operational events**.

Dozwolone wartości `event_type` w tej tabeli:
- `general_event`,
- `driving_lesson`.

Wymagany jest DB `CHECK` zamykający ten katalog dla bieżącego modelu.

`important_date`:
- nie może zostać zapisany jako `calendar_events` row,
- nie może zostać utworzony ani zmieniony przez manualne create/update/cancel/complete CalendarEvent,
- jest elementem read-modelu kalendarza wyprowadzanym ze swojej domeny źródłowej.

DB-CAL-002 celowo **nie** rozstrzyga jeszcze, jak `driving_lesson` ma być związany z formalnym `TrainingSession`; to pozostaje DB-CAL-007. Zachowanie dyskryminatora ręcznego formularza nie jest teraz usuwane.

## 6. `important_date` — canonical source i projekcja

### 6.1 Źródła bieżące

Dla obecnego zakresu systemowa projekcja może powstawać z bieżących rekordów dokumentów z non-null `valid_until`:

Staff:
- `card_or_authorization`,
- `medical_exam`,
- `psychological_exam`.

Vehicle:
- `technical_inspection`,
- `oc_insurance`,
- `ac_insurance`.

Current source row pozostaje definiowany przez model wersjonowanych dokumentów (`superseded_at IS NULL`). Stara superseded wersja nie jest bieżącą `important_date`.

### 6.2 Tożsamość projekcji

Canonical identity systemowego wpisu nie jest `calendar_events.id`.

Jest nią source tuple:
- `organization_id`,
- `source_domain`,
- `source_row_id`,
- `date_kind`.

Warstwa API może z tego wyprowadzić stabilny opaque identifier do UI, ale taki identifier nie może zostać później potraktowany jak PK w `calendar_events`.

Projection item musi zachować source reference, aby kliknięcie mogło prowadzić do obiektu źródłowego, a mutacja terminu odbywała się w domenie Staff/Vehicle.

### 6.3 Brak drugiego źródła prawdy

Zmiana `valid_until` w source domain automatycznie zmienia systemową projekcję bez osobnego PATCH do CalendarEvent.

Supersede/clear source row usuwa poprzedni current projection zgodnie z read modelem.

Dopuszczalny jest później materialized/cache read model, ale:
- nie jest authoritative,
- jest odbudowywalny ze źródeł,
- nie może być ręcznie edytowany,
- nie staje się drugim ownerem daty.

To pozwala w przyszłości dodawać inne systemowe terminy, pod warunkiem jawnego mapowania `source_domain + date_kind`.

## 7. DB-CAL-002 — problem B: miejsce spotkania

Obserwowany UI ma jeden przełączany slot miejsca spotkania. Select zapisanej lokalizacji i custom text nie są jednocześnie aktywne.

Poprzedni physical row pozwalał jednak technicznie zapisać oba:
- `location_id != NULL`,
- `custom_meeting_place != NULL`.

To tworzyłoby dwa konkurujące źródła miejsca dla jednego eventu.

## 8. DB-CAL-002 — invariant miejsca spotkania

Canonical semantyka:

**zero albo jedno z:**
- `location_id`,
- `custom_meeting_place`.

Oba `NULL` są dozwolone, ponieważ miejsce spotkania jest w formularzu opcjonalne.

DB musi wymuszać logicznie:

`num_nonnulls(location_id, custom_meeting_place) <= 1`

oraz:

`custom_meeting_place IS NULL OR btrim(custom_meeting_place) <> ''`.

Na write boundary:
- custom text jest trimowany,
- empty/whitespace-only normalizuje się do `NULL`,
- przełączenie na saved Location czyści custom text w command model,
- przełączenie na custom text czyści `location_id`,
- custom text nigdy nie tworzy automatycznie trwałego rekordu `locations`.

## 9. Migration design DB-CAL-002

Migracji Laravel nadal nie tworzymy.

Przyszły migration design ma kolejno:
1. przeskanować istniejące `calendar_events.event_type`,
2. wykryć `important_date`, nieznane systemowe typy i inne wartości poza `general_event|driving_lesson`,
3. nie klasyfikować ich automatycznie na podstawie nazwy/zasobów,
4. wykryć eventy mające równocześnie saved Location i custom text,
5. wykryć whitespace-only custom text,
6. dla niejednoznacznych rekordów wymagać explicit reviewed remediation,
7. whitespace-only może być znormalizowane do `NULL` dopiero po potwierdzeniu, że nie odrzucamy semantycznej treści,
8. dodać `event_type` CHECK,
9. dodać meeting-place exclusion/nonblank CHECK,
10. uruchomić projection/manual-write oraz meeting-place negative tests.

Nie wolno „naprawić” obu aktywnych miejsc przez arbitralny wybór `location_id` albo tekstu.

## 10. Testy wymagane przez DB-CAL-002

Muszą pokryć co najmniej:
- `general_event` jest dozwolony,
- `driving_lesson` jest dozwolony i nie zmienia potwierdzonego parytetu widocznego formularza,
- `important_date` jako row `calendar_events` jest odrzucany,
- nieznany systemowy/manualny event type poza zamkniętym katalogiem jest odrzucany,
- manual create nie może zapisać `important_date`,
- current Staff document expiry pojawia się jako systemowa projekcja,
- current Vehicle document expiry pojawia się jako systemowa projekcja,
- superseded source document nie emituje current `important_date`,
- zmiana source `valid_until` zmienia projekcję bez modyfikacji `calendar_events`,
- projekcji nie można mutować endpointami manual CalendarEvent,
- tożsamość projekcji rozwiązuje się do source tuple, nie do `calendar_events.id`,
- event tylko z `location_id` przechodzi,
- event tylko z niepustym custom text przechodzi,
- event bez miejsca przechodzi,
- event z Location i custom text jednocześnie jest odrzucany przez DB,
- whitespace-only custom text nie jest trwałym stanem,
- custom text nie tworzy stałej Location,
- migration precheck wykrywa unknown event type i dual meeting place.

## 11. Self-audit DB-CAL-002

Wynik: **PASS**.

Sprawdzone:
- manual storage ma zamknięty katalog typów,
- `important_date` nie staje się drugim mutable źródłem daty,
- canonical `valid_until` pozostaje w Staff/Vehicle source domain,
- projection identity jest source-based,
- manualne CalendarEvent commands nie mutują system projection,
- meeting place ma DB-enforced zero-or-one source,
- opcjonalny stan bez miejsca jest zachowany,
- whitespace-only custom text nie jest persystowany,
- custom text nie tworzy `Location`,
- DB-CAL-001 pozostaje zachowany,
- nie rozwiązano DB-CAL-003..007,
- agregaty pozostały zamrożone,
- migracji Laravel nie utworzono,
- DB4_6 i UI nie rozpoczęto.

## 12. Pozostałe P1 po DB-CAL-002

Pozostaje dokładnie **5 P1**:

### DB-CAL-003 — overlap/conflict concurrency

Server-side conflict detection nadal nie ma race-safe final DB/transaction boundary. Nie ustalono jeszcze interval semantics, resource-claiming statuses ani finalnej strategii PostgreSQL/serialization.

### DB-CAL-004 — event lifecycle + optimistic concurrency

Nadal brak finalnej state matrix i PATCH/cancel/complete concurrency contract.

### DB-CAL-005 — `calendar.manage.own`

Nadal brak canonical owner resolver zgodnego z DB4_2.

### DB-CAL-006 — AvailabilitySlot booking lifecycle

Same-tenant `booked_student_id` jest zamknięte przez DB-CAL-001, ale exactly-once booking, state matrix, book/cancel races i efekt rezerwacji nadal są otwarte.

### DB-CAL-007 — `driving_lesson` vs formal `TrainingSession`

Nadal trzeba ustalić jeden canonical schedule owner i relację/projekcję tak, aby Calendar nie stał się drugim formalnym źródłem godzin.

## 13. Bramka jakości DB-CAL-002

**PASS.**

Stan po bramce tego artefaktu:
- P0: `0`,
- P1 rozwiązane w DB4_5: `2`,
- P1 otwarte: `5`,
- `DB-CAL-001`: `PASS`,
- `DB-CAL-002`: `PASS`,
- final DB4_5 aggregate sync: nadal `PENDING`,
- DB4_6: zablokowane,
- Stage 5: zablokowany,
- UI/feature implementation: zablokowane.

Następny pojedynczy krok **dopiero po aktualizacji centralnego gate**: `DB-CAL-003` only.
