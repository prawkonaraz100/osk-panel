# 104. Stage 4 — database foundation audit

Data: 2026-09-05

**Status:** `IN_PROGRESS / FOUNDATION_GATE_FAIL`

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

Foundation gate poprawnie kończy się wynikiem `FAIL`. To nie jest regresja — wykryto cztery konkretne P1, które trzeba rozwiązać przed uznaniem physical schema za bazę do migracji.

### DB-FOUND-001 — brak finalnego typu primary key

`core-schema.yml` nadal ma `uuid_pending_final_ADR`, a dokument fizycznej bazy odsyła decyzję `UUIDv7 / ULID` na później.

Tego nie wolno zostawić agentowi implementującemu migracje, ponieważ decyzja wpływa na:
- typ wszystkich PK/FK,
- sposób generowania ID,
- kolejność indeksów,
- Laravel casts/model traits,
- dane testowe i importy.

Następny krok ma rozstrzygnąć wyłącznie ten problem.

### DB-FOUND-002 — nullable scope łamie zamierzoną unikalność

Dwa miejsca są niebezpieczne przy zwykłym PostgreSQL `UNIQUE`:

1. `idempotency_records` — `organization_id` może być `NULL`, ale unikalność jest opisana jako `(organization_id, operation_key, idempotency_key)`.
2. `account_closure_requests` — `organization_id` może być `NULL`, a pending uniqueness jest opisana jako `(user_id, organization_id) WHERE status='pending'`.

W zwykłej semantyce PostgreSQL wiele `NULL` nie koliduje ze sobą. Bez dodatkowej strategii system mógłby dopuścić kilka globalnych rekordów, mimo że dokumentacja mówi „jeden”.

Ten problem zostanie naprawiony dopiero po zamknięciu DB-FOUND-001.

### DB-FOUND-003 — `organization_contact_addresses` nie jest w core inventory

Etap 2 prawidłowo ustalił, że adres firmy:
- nie jest `Location`,
- ma osobny canonical owner,
- powinien być zapisany w `organization_contact_addresses`.

Jednak `core-schema.yml` nie ma tej tabeli w `core_tables`, a `docs/87-physical-database-schema.md` nie definiuje jej fizycznie. Migracje wygenerowane wyłącznie ze starego core blueprintu zgubiłyby potwierdzony ekran Ustawień.

### DB-FOUND-004 — Stage-2 settings nie zostały jeszcze scalone do physical core

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

## Czego celowo nie naprawiono w tym kroku

Nie dotykaliśmy jeszcze:
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

Każdy z tych obszarów dostanie osobny slice i gate.

## Gate po Stage 4.1

`FAIL` — cztery otwarte P1 są jawnie zapisane w `specs/gates/stage-4-database-contract-gate.yml`.

Następny pojedynczy krok:

**DB-FOUND-001 — finalny physical primary-key strategy.**

Dopiero po jego PASS przechodzimy do nullable uniqueness.
