# 104. Stage 4 — database foundation audit

Data: 2026-09-05

**Status:** `IN_PROGRESS / DB-FOUND-001 PASS / DB-FOUND-002 PASS / DB-FOUND-003 PASS / FOUNDATION_GATE_FAIL`

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

### `account_closure_requests`

Organization-specific pending request:
- unique `(user_id, organization_id)`
- where `status='pending' AND organization_id IS NOT NULL`.

Global pending request:
- unique `(user_id)`
- where `status='pending' AND organization_id IS NULL`.

Do obowiązkowych migration/invariant tests dodano osobne przypadki dla globalnego scope, tenantowego scope i rozdzielenia scope'ów.

**Gate DB-FOUND-002: PASS.**

## DB-FOUND-003 — PASS: `organization_contact_addresses` ma canonical physical target

Problem został zamknięty bez scalania pozostałych pól Ustawień.

`organization_contact_addresses` jest teraz:
- jawnie wpisane do `core_tables.identity` w `specs/database/core-schema.yml`,
- jawnie opisane w `organization_model`,
- fizycznie zdefiniowane w `docs/87-physical-database-schema.md`,
- one-to-one z `organizations` przez `organization_id uuid PK/FK`,
- odseparowane od `locations`, które pozostaje zasobem szkoleniowym.

Physical shape zachowuje ustalenia z Etapu 2:
- `street`,
- `house_number`,
- `unit_number`,
- `postal_code`,
- `city_name`,
- `city_reference`,
- `voivodeship_name`,
- `country_code`,
- timestampy.

Dodano też migration/invariant obligations:
- najwyżej jeden structured company/contact address na Organization,
- zapis/edycja adresu firmy nie tworzy rekordu `locations`.

W tym kroku **celowo nie przenoszono** jeszcze `users.first_name/last_name`, `organizations.phone`, primary-email flag, settings version ani finalnego PKK settings shape. To należy wyłącznie do DB-FOUND-004.

**Gate DB-FOUND-003: PASS.**

## DB-FOUND-004 — OPEN: Stage-2 settings nie zostały jeszcze w pełni scalone do physical core

Do physical blueprintu trzeba teraz jawnie przenieść pozostałe elementy z Etapu 2:
- `users.first_name`,
- `users.last_name`,
- `organizations.phone`,
- `auth_login_identifiers.is_primary_for_type`,
- partial unique dla bieżącego primary email,
- `organization_settings.version`,
- finalny physical shape `pkk_integration_settings` wynikający z `specs/database/organization-settings.yml`.

`organization_contact_addresses` jest już zamknięte przez DB-FOUND-003 i nie jest ponownie projektowane w tym kroku.

Nie tworzymy dla tych danych alternatywnych JSONB ani duplicate shadow columns.

## Czego celowo nie naprawiono w DB-FOUND-003

Nie dotykaliśmy:
- pozostałego Stage-2 settings merge,
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

## Gate po Stage 4.4

Foundation gate jako całość pozostaje `FAIL`, ponieważ jeden P1 jest nadal otwarty. To jest oczekiwane i nie blokuje uznania DB-FOUND-003 za zamknięty.

Aktualny wynik:
- `DB-FOUND-001` — **PASS**,
- `DB-FOUND-002` — **PASS**,
- `DB-FOUND-003` — **PASS**,
- `DB-FOUND-004` — **FAIL / OPEN**.

Następny pojedynczy krok:

**DB-FOUND-004 — scalić pozostałe canonical pola i constrainty Ustawień OSK z Etapu 2 do physical core.**

Dopiero po jego PASS kończymy foundation slice i przechodzimy do `DB4_2_IDENTITY_TENANT_RBAC`.
