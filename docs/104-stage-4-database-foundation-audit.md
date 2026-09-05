# 104. Stage 4 — database foundation audit

Data: 2026-09-05

**Status:** `IN_PROGRESS / DB-FOUND-001 PASS / DB-FOUND-002 PASS / FOUNDATION_GATE_FAIL`

## Cel

Etap 4 nie zaczyna się od generowania migracji Laravel. Najpierw zamykamy fizyczne decyzje, które wpływają na wszystkie późniejsze tabele i constrainty.

Zasada pozostaje taka sama jak w reverse engineeringu i OpenAPI:

`evidence -> requirement -> domain/data -> physical constraint -> migration -> invariant test`

Nie upraszczamy modelu tylko dlatego, że łatwiej byłoby wygenerować CRUD.

## Sprawdzone źródła

- `specs/database/core-schema.yml`,
- `docs/87-physical-database-schema.md`,
- `specs/database/organization-settings.yml`,
- `docs/100-osk-settings-domain-model.md`,
- zamknięty Gate Etapu 3,
- `specs/traceability/core-v1.yml`.

## Wynik foundation scan

Foundation scan wykrył cztery konkretne P1 przed uznaniem physical schema za bazę do migracji. Naprawiamy je pojedynczo i po każdym uruchamiamy bramkę ponownie.

## DB-FOUND-001 — PASS: finalny physical primary-key strategy

Decyzja została zamknięta:

- synthetic domain PK/FK używa natywnego PostgreSQL `uuid`,
- nasze generowane identyfikatory domenowe są **UUIDv7**,
- UUIDv7 jest generowany application-side przed `INSERT`,
- nie wymagamy DB-default ani wersjozależnej funkcji PostgreSQL do generowania UUIDv7,
- backend nie może po cichu przełączyć się na UUIDv4,
- zewnętrzne/provider/import identifiers pozostają osobnymi polami i nie stają się naszym PK,
- czyste join/dictionary tables mogą zachować jawnie zaprojektowany composite/natural key, jeśli nie potrzebują własnej tożsamości historycznej.

Stage-3 API nie wymaga ponownego otwarcia: publiczne resource IDs są już kompatybilne z UUID string.

**Gate DB-FOUND-001: PASS.**

## DB-FOUND-002 — PASS: NULL-safe uniqueness dla tenant/global scope

Problem polegał na tym, że zwykły PostgreSQL `UNIQUE` nie traktuje wielu wartości `NULL` jak tej samej wartości. Dwa logiczne scope'y mogły więc zostać błędnie zapisane:

1. `idempotency_records.organization_id` jest nullable,
2. `account_closure_requests.organization_id` jest nullable.

Nie używamy tutaj pojedynczego `UNIQUE` obejmującego nullable `organization_id`. Przyjęto jedną wspólną zasadę:

**tenant scope i globalny scope mają osobne partial unique indexes.**

### `idempotency_records`

Tenant scope:
- unique `(organization_id, operation_key, idempotency_key)`
- where `organization_id IS NOT NULL`.

Global/non-tenant scope:
- unique `(operation_key, idempotency_key)`
- where `organization_id IS NULL`.

Skutek:
- retry w tym samym OSK nie może podwójnie wykonać tego samego commandu,
- globalny command nie może zostać claimed dwa razy tylko dlatego, że `organization_id=NULL`,
- ten sam client-generated key może poprawnie istnieć w dwóch różnych OSK,
- globalny namespace jest oddzielony od tenantowych namespace'ów.

### `account_closure_requests`

Organization-specific pending request:
- unique `(user_id, organization_id)`
- where `status='pending' AND organization_id IS NOT NULL`.

Global pending request:
- unique `(user_id)`
- where `status='pending' AND organization_id IS NULL`.

Skutek:
- jeden user może mieć najwyżej jeden globalny pending closure request,
- jeden user może mieć najwyżej jeden pending request dla danego OSK,
- requesty tego samego usera dotyczące dwóch różnych OSK nie kolidują.

Nie wymagamy PostgreSQL `NULLS NOT DISTINCT`; jawne partial indexes są bardziej przenośne w ramach naszego założonego schematu i wyraźnie dokumentują dwa różne namespace'y.

Do obowiązkowych migration/invariant tests dodano osobne przypadki dla globalnego scope, tenantowego scope i rozdzielenia scope'ów.

**Gate DB-FOUND-002: PASS.**

## DB-FOUND-003 — OPEN: `organization_contact_addresses` nie jest w core inventory

Etap 2 prawidłowo ustalił, że adres firmy:
- nie jest `Location`,
- ma osobny canonical owner,
- powinien być zapisany w `organization_contact_addresses`.

Jednak `core-schema.yml` nadal nie ma tej tabeli w `core_tables`, a `docs/87-physical-database-schema.md` nadal nie definiuje jej fizycznie. Migracje wygenerowane wyłącznie ze starego core blueprintu zgubiłyby potwierdzony ekran Ustawień.

To jest **następny i jedyny** problem do rozwiązania.

## DB-FOUND-004 — OPEN: Stage-2 settings nie zostały jeszcze scalone do physical core

Do physical blueprintu trzeba jawnie przenieść m.in.:
- `users.first_name`,
- `users.last_name`,
- `organizations.phone`,
- `auth_login_identifiers.is_primary_for_type`,
- partial unique dla bieżącego primary email,
- `organization_settings.version`,
- pełny `organization_contact_addresses`,
- finalny physical shape `pkk_integration_settings` wynikający z Etapu 2.

Nie tworzymy dla tych danych alternatywnych JSONB ani duplicate shadow columns.

Nie naprawiono tego jeszcze.

## Czego celowo nie naprawiono w tym kroku

Nie dotykaliśmy:
- `organization_contact_addresses`,
- Stage-2 settings merge poza nullable uniqueness,
- tenant-safe FK dla staff/location/vehicle/course,
- constraintów kalendarza,
- ledgerów czasu,
- licencji,
- egzaminów,
- PKK operations,
- finansów,
- audit/outbox,
- szyfrowania/key rotation,
- legalnego mapowania `PT`.

Każdy z tych obszarów dostaje osobny slice i gate.

## Gate po Stage 4.3

Foundation gate jako całość pozostaje `FAIL`, ponieważ dwa P1 są nadal otwarte. To jest oczekiwane i nie blokuje uznania DB-FOUND-002 za zamknięty.

Aktualny wynik:
- `DB-FOUND-001` — **PASS**,
- `DB-FOUND-002` — **PASS**,
- `DB-FOUND-003` — **FAIL / OPEN**,
- `DB-FOUND-004` — **FAIL / OPEN**.

Następny pojedynczy krok:

**DB-FOUND-003 — dodać `organization_contact_addresses` do canonical core inventory i physical blueprint, bez scalania pozostałych pól settings z DB-FOUND-004.**

Dopiero po jego PASS przechodzimy do pełnego Stage-2 settings merge.
