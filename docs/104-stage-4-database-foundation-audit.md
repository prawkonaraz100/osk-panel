# 104. Stage 4 — database foundation audit

Data: 2026-09-05

**Status:** `IN_PROGRESS / DB-FOUND-001 PASS / FOUNDATION_GATE_FAIL`

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

## Wynik pierwszego skanu

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

Dlaczego taki wybór:
- natywne 16-bajtowe storage/index/FK PostgreSQL,
- lepsza lokalność indeksu niż przy losowym UUIDv4,
- brak centralnej sekwencji i brak przewidywalnych liczbowych ID,
- brak `char(26)`/collation/string semantics charakterystycznych dla ULID,
- brak zależności od konkretnej wersji PostgreSQL lub rozszerzenia z DB-side UUIDv7 generator.

Stage-3 API nie wymaga ponownego otwarcia: publiczne resource IDs są już kompatybilne z UUID string.

Do migration/invariant tests dodano obowiązek potwierdzenia, że syntetyczne ID generowane przez system są UUIDv7 i są przechowywane jako native `uuid`.

**Gate DB-FOUND-001: PASS.**

## DB-FOUND-002 — OPEN: nullable scope łamie zamierzoną unikalność

Dwa miejsca są niebezpieczne przy zwykłym PostgreSQL `UNIQUE`:

1. `idempotency_records` — `organization_id` może być `NULL`, ale unikalność jest opisana jako `(organization_id, operation_key, idempotency_key)`.
2. `account_closure_requests` — `organization_id` może być `NULL`, a pending uniqueness jest opisana jako `(user_id, organization_id) WHERE status='pending'`.

W zwykłej semantyce PostgreSQL wiele `NULL` nie koliduje ze sobą. Bez dodatkowej strategii system mógłby dopuścić kilka globalnych rekordów, mimo że dokumentacja mówi „jeden”.

To jest **następny i jedyny** problem do rozwiązania.

## DB-FOUND-003 — OPEN: `organization_contact_addresses` nie jest w core inventory

Etap 2 prawidłowo ustalił, że adres firmy:
- nie jest `Location`,
- ma osobny canonical owner,
- powinien być zapisany w `organization_contact_addresses`.

Jednak `core-schema.yml` nie ma tej tabeli w `core_tables`, a `docs/87-physical-database-schema.md` nie definiuje jej fizycznie. Migracje wygenerowane wyłącznie ze starego core blueprintu zgubiłyby potwierdzony ekran Ustawień.

Nie naprawiono tego jeszcze — zgodnie z zasadą jednego problemu na krok.

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
- nullable-scope uniqueness — poza samym pozostawieniem blockera do kolejnego kroku,
- Stage-2 settings merge,
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

## Gate po Stage 4.2

Foundation gate jako całość pozostaje `FAIL`, ponieważ trzy P1 są nadal otwarte. To jest oczekiwane.

Aktualny wynik:
- `DB-FOUND-001` — **PASS**,
- `DB-FOUND-002` — **FAIL / OPEN**,
- `DB-FOUND-003` — **FAIL / OPEN**,
- `DB-FOUND-004` — **FAIL / OPEN**.

Następny pojedynczy krok:

**DB-FOUND-002 — zaprojektować NULL-safe uniqueness dla globalnego i tenantowego scope.**

Dopiero po jego PASS przechodzimy do `organization_contact_addresses`.
