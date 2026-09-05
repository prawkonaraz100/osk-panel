# 104. Stage 4 — database foundation audit

Data: 2026-09-05

**Status:** `FOUNDATION_PASS / READY_FOR_DB4_2_IDENTITY_TENANT_RBAC`

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

Foundation scan wykrył cztery P1. Zostały naprawione **pojedynczo**, z osobnym gate po każdym kroku. Foundation slice nie zawiera już otwartego P0/P1.

## DB-FOUND-001 — PASS: finalny physical primary-key strategy

- synthetic domain PK/FK: native PostgreSQL `uuid`,
- nasze generowane domain IDs: UUIDv7,
- generation owner: application przed `INSERT`,
- brak silent fallback do UUIDv4,
- provider/import IDs nie są naszym PK.

**Gate DB-FOUND-001: PASS.**

## DB-FOUND-002 — PASS: NULL-safe uniqueness

`idempotency_records` i `account_closure_requests` mają jawnie rozdzielone partial unique indexes dla scope tenantowego i globalnego. Nie polegamy na zwykłym UNIQUE z nullable `organization_id`.

**Gate DB-FOUND-002: PASS.**

## DB-FOUND-003 — PASS: canonical company contact address

`organization_contact_addresses` jest obecne w core inventory i physical blueprint jako one-to-one `organization_id PK/FK`. Adres firmy pozostaje odrębnym konceptem od szkoleniowego `Location`.

**Gate DB-FOUND-003: PASS.**

## DB-FOUND-004 — PASS: Stage-2 Settings scalone do physical core

Pozostałe ownership i physical fields z Etapu 2 zostały scalone bez tworzenia shadow JSONB/duplicate columns.

### `users`

Physical core zawiera:
- `first_name varchar(120)`,
- `last_name varchar(120)`.

Są to globalne pola human identity. System może wspierać przejściowy stan techniczny/pre-onboarding, ale human user po onboardingu musi mieć oba pola. Imię/nazwisko nie są duplikowane per OSK ani w PKK settings.

### `organizations`

Dodano:
- `phone varchar(40) null`.

`organizations.name` pozostaje canonical company name. `nip` nie został dodany do potwierdzonego formularza Ustawień tylko dlatego, że istnieje w domenie.

### `auth_login_identifiers`

Dodano:
- `is_primary_for_type boolean default false`,
- partial unique `(user_id, identifier_type)` dla bieżącego `is_primary_for_type=true` i `revoked_at IS NULL`.

Settings email jest projekcją primary current email identifier, a nie osobną kolumną `users.email`.

### `organization_settings`

Dodano:
- `version integer not null default 1 check >= 1`.

Wersja jest źródłem optimistic concurrency dla settings i PKK configuration gate. Jeden udany atomowy zapis zwiększa ją dokładnie raz.

Krytyczne dane nie trafiają do `preferences jsonb`.

### `pkk_integration_settings`

Canonical physical shape jest zgodny ze Stage 2:
- `organization_id PK/FK`,
- `school_name`,
- `osk_registry_number`,
- `external_osk_login_ciphertext`,
- opcjonalny `external_osk_login_lookup_hash`,
- `readiness_status`,
- `updated_at`.

`external_osk_login`:
- nie jest application login,
- plaintext nie jest utrwalany po zapisie,
- operator first/last name nie jest duplikowany w tej tabeli.

Provider-specific sekrety, jeżeli finalny provider ich wymaga, dostają osobny secret/adapter contract i nie zmieniają ownership obserwowanego formularza.

### Atomic settings use case

Physical blueprint zachowuje Stage-2 transakcję:
- lock `organization_settings`,
- validate `If-Match/version`,
- update właściwych owner tables,
- recalculate PKK readiness,
- increment version dokładnie raz,
- redacted audit,
- commit.

Do migration/invariant test matrix dodano testy dla names, primary email, version i PKK external login isolation.

**Gate DB-FOUND-004: PASS.**

## Foundation gate — PASS

Aktualny wynik:
- `DB-FOUND-001` — **PASS**,
- `DB-FOUND-002` — **PASS**,
- `DB-FOUND-003` — **PASS**,
- `DB-FOUND-004` — **PASS**.

Foundation nie generuje jeszcze migracji aplikacyjnych dla całego systemu. Oznacza tylko, że wspólne decyzje fizyczne są wystarczająco spójne, aby przejść do następnego **jednego** slice'u DB.

## Następny etap

**DB4_2_IDENTITY_TENANT_RBAC**.

Najpierw wykonujemy wyłącznie diagnozę:
- tenant isolation na poziomie physical FK/constraints,
- membership lifecycle,
- staff membership link semantics,
- permission grant/deny model,
- role template vs explicit permissions,
- session-to-membership scoping,
- owner/last-owner invariants,
- cross-organization write prevention.

Dopiero po zapisaniu blockerów DB4_2 naprawiamy je jeden po drugim. Nie rozpoczynamy równolegle staff/resources ani kolejnych bounded contexts.
