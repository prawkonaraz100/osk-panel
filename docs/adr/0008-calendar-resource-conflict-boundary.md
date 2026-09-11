# ADR-0008 — Calendar resource conflict boundary

Status: Accepted  
Data: 2026-09-11

## Context

Stage-4 Calendar authority zamknęło semantykę konfliktów zasobów, ale starszy
rejestr ADR nadal przedstawiał wybór mechanizmu jako nierozstrzygnięty.
Jednocześnie Stage-5 implementuje moduły w dependency-closed podzbiorach fazy
`expand`, a fizyczny node `MIG-IDX-CALENDAR_GIST` należy do późniejszych faz
`preflight` i `write_fence`.

Potrzebna jest więc jedna operacyjna decyzja, która nie przepisuje Stage-4:
co jest finalną granicą produkcyjną oraz jak zachowuje się implementacja przed
globalnym phase barrier.

## Decision

### 1. Finalna granica produkcyjna

Jedyną finalną race-safe boundary dla konfliktów Calendar/Training jest
PostgreSQL:

- `calendar_resource_claims` jako technical current projection,
- tenant-scoped resource identity,
- half-open interval `[starts_at, ends_at)`,
- generated `tstzrange`,
- `btree_gist`,
- partial GiST exclusion constraints dla Student, Instructor, Vehicle i
  managed Location,
- exact claim-set/final-state guards zgodne z Stage-4 Calendar authority.

Application-level precheck nie jest finalną boundary.

### 2. Jeden wspólny claim model

Do tej samej projekcji konfliktowej wchodzą wyłącznie faktycznie zajmujące
zasób rezerwacje:

- scheduled manual `general_event`,
- booked availability reservation,
- scheduled formal `TrainingSession`.

Opublikowany, ale niezarezerwowany `AvailabilitySlot` nie zajmuje zasobu.
`important_date` również nie tworzy claimu.

Formalna jazda nie jest drugim mutable `CalendarEvent`. Canonical ownerem
formalnego terminu jest `TrainingSession`; kalendarz pokazuje jego projection.

### 3. Stan przejściowy Stage-5 przed GiST

Dopóki globalna kolejność faz migracyjnych nie pozwala zmaterializować
`MIG-IDX-CALENDAR_GIST`, runtime może wykonywać konfliktowy use case tylko
w jednej transakcji PostgreSQL z:

1. tenant/resource validation,
2. deterministycznymi transaction-scoped advisory locks dla wszystkich
   zajmowanych zasobów w kolejności Student -> Instructor -> Vehicle -> Location,
3. overlap query na `calendar_resource_claims`,
4. atomowym replacement exact claim setu i business ownera.

Ta warstwa jest przejściowym mechanizmem serializacji development/core-v1,
nie zamiennikiem GiST. Jej istnienie nie pozwala:

- oznaczyć fizycznych GiST/constraint DBT jako wykonanych,
- zadeklarować production booking concurrency jako zamknięte,
- pominąć późniejszych migration phases,
- zmienić Stage-4 authority.

### 4. Production gate

Production activation konfliktowych booking/scheduling writes wymaga
zmaterializowanej i zwalidowanej finalnej GiST boundary. Jeżeli odpowiednie
fazy migracji nie są jeszcze zamknięte, release/readiness gate ma traktować
production booking concurrency jako niegotowe.

### 5. Semantyka konfliktu

- przedział: `[start, end)`,
- `10:00-11:00` i `11:00-12:00` nie konfliktują,
- konflikt jest same-tenant i same-resource-kind,
- custom meeting place nie jest managed Location,
- nie ma auto-shift, auto-reassign ani auto-cancel,
- conflicting mutation rollbackuje cały business effect.

## Consequences

- Stage-5 może budować działający Calendar/Training runtime bez łamania
  globalnej kolejności migracji,
- finalna ochrona produkcyjna nadal pozostaje fizyczna i PostgreSQL-native,
- DBT wymagające GiST/FK/constraint phases pozostają `pending_domain_materialization`
  aż do faktycznej materializacji,
- aplikacyjne locki i precheck można później zachować jako UX/deadlock-risk
  helper, ale bezpieczeństwo produkcyjne nie może na nich polegać.

## Authority preserved

Ten ADR nie zmienia:

- `specs/database/calendar.yml`,
- Stage-4 170-node migration DAG,
- seven-phase order,
- final migration matrix,
- zasady `TrainingSession -> attendance -> TrainingHourLedgerEntry` z ADR-0003.

Jest operacyjnym doprecyzowaniem Stage-5 dla już zaakceptowanego Stage-4
Calendar design.
