# 107. Stage 4 — Staff / Locations / Vehicles physical invariant audit

Data: 2026-09-06

**Status:** `DB4_3 FINAL PASS / 0 OPEN P0-P1 / AGGREGATE SYNC PASS`

## Cel

`DB4_3_STAFF_LOCATIONS_VEHICLES` został przeprowadzony ściśle blocker po blockerze, a po zamknięciu wszystkich sześciu blockerów wykonano osobną finalną synchronizację aggregate.

Nie rozpoczęto Student/Course/Calendar, migracji Laravel ani implementacji UI w ramach tej bramki.

Zasada:

`confirmed resource capability -> physical owner -> same-tenant relation -> lifecycle -> current projection -> invariant test`

## Źródła

- screen specs Staff/Locations/Vehicles,
- `specs/database/core-schema.yml`,
- `specs/database/identity-rbac.yml`,
- `specs/database/staff-locations-vehicles.yml`,
- `specs/security/permissions.yml`,
- `specs/traceability/core-v1.yml`,
- `docs/08-security-compliance.md`,
- `docs/87-physical-database-schema.md`.

`specs/database/staff-locations-vehicles.yml` pozostaje źródłem decyzji bounded-context DB4_3. Po finalnym sync jego sześć rozstrzygnięć zostało przeniesionych bez redukcji capability do aggregate `specs/database/core-schema.yml` oraz narrative `docs/87-physical-database-schema.md`.

---

# Co było poprawne przed naprawami

- StaffProfile jest oddzielony od User/OrganizationMembership.
- Staff może istnieć bez panel account.
- `locations` i `organization_contact_addresses` są różnymi konceptami.
- Staff/Vehicle mają relacyjne multi-selecty kategorii/lokalizacji, a nie JSON.
- trzy niezależne ważności dokumentów Staff i Vehicle są zachowane.
- archive zachowuje historyczne referencje.
- demo sentinel `01-01-0001` nie jest wymaganiem biznesowym.

---

# DB-RES-001 — PASS: StaffMembershipLink same-organization integrity

`staff_membership_links.organization_id` jest obowiązkowym tenant key. Composite FK spinają link z `staff_profiles(organization_id,id)` oraz `organization_memberships(organization_id,id)`. Active-link uniqueness i historyczny unlink pozostają zachowane.

**Gate DB-RES-001: PASS.**

---

# DB-RES-002 — PASS: Staff/Vehicle ↔ Location tenant integrity

`staff_location_assignments` i `vehicle_location_assignments` są tenant-aware i posiadają `organization_id`. Composite FK wymuszają zgodność Staff/Vehicle z Location w tym samym OSK. `assigned_locations` nie może rozszerzyć RBAC przez cross-tenant join.

**Gate DB-RES-002: PASS.**

---

# DB-RES-003 — PASS: Staff/Vehicle FileAsset same-tenant + purpose + ready attachment

Cztery prywatne ścieżki attachmentu używają same-tenant composite FK do `file_assets(organization_id,id)`. Purpose jest jawny (`staff_photo`, `staff_document`, `vehicle_photo`, `vehicle_document`), a DB trigger wymaga `status='ready'` i serializuje race asset-state ↔ attachment. Platformowy asset nie może być użyty jako prywatny Staff/Vehicle attachment.

**Gate DB-RES-003: PASS.**

---

# DB-RES-004 — PASS: history-safe current document validity projection

Wybrany został versioned/superseded history model. Current row to `superseded_at IS NULL`, a partial unique index gwarantuje zero albo jeden current row na parent + document type. Edycja tworzy nową wersję zamiast przepisywać historię, clear zachowuje stare rekordy, a projekcje detail/expiry/important-date czytają tylko current row. Nie stosujemy heurystyki `MAX(created_at)`.

**Gate DB-RES-004: PASS.**

---

# DB-RES-005 — PASS: Staff/Vehicle identity uniqueness lifecycle

PESEL pracownika i VIN pojazdu są unikalne per OSK także przez archive/restore, gdy wartość istnieje. Numer rejestracyjny jest unikalny per OSK tylko wśród niearchiwalnych pojazdów, dzięki czemu historyczny numer może zostać legalnie użyty ponownie po archive, bez przepisywania historii. Restore starego pojazdu przy zajętym numerze daje conflict. Constrainty są finalną race boundary.

**Gate DB-RES-005: PASS.**

---

# DB-RES-006 — PASS: Staff archive vs panel-access membership lifecycle

## Problem

StaffProfile i panel account są oddzielnymi konceptami. Samo `archived_at` na StaffProfile nie usuwało jednak StaffMembershipLink ani nie określało, co zrobić z OrganizationMembership.

Dwa skrajne zachowania były błędne:
- pozostawienie aktywnego linku i aktywnego membership mogło utrzymać staff-derived dostęp po archive,
- bezwarunkowy revoke membership niszczyłby bezpieczny restore, permission snapshot i mógłby wejść w konflikt z Owner/last-owner governance.

## Decyzja canonical — rozdzielenie Staff od membership

- `StaffProfile` reprezentuje zasób kadrowy,
- `OrganizationMembership` reprezentuje principal autoryzacyjny OSK,
- `StaffMembershipLink` wiąże te dwa konteksty.

Archive Staff **zawsze kończy aktywny StaffMembershipLink**. Nie otwieramy historycznego linku ponownie przy restore; ewentualny późniejszy panel access tworzy nowy link row.

Committed invariant:

`archived StaffProfile -> zero StaffMembershipLink z unlinked_at IS NULL`

oraz odwrotnie:

`aktywny StaffMembershipLink -> StaffProfile.archived_at IS NULL`.

DB design wymaga guardu/constraint triggera dla tworzenia linku do niearchiwalnego Staff oraz końcowego guardu, że archive nie może zacommitować z aktywnym linkiem. Archive i link creation serializują się na StaffProfile, więc race nie może zostawić stanu sprzecznego.

## Archive bez panel account

Jeżeli StaffProfile nie ma aktywnego StaffMembershipLink:
- ustawiamy `archived_at` i aktora,
- nie zmieniamy żadnego OrganizationMembership,
- audit/outbox powstają w tej samej transakcji.

## Archive zwykłego pracownika z panelem

Jeżeli aktywny link wskazuje membership z `is_owner=false`:
1. lock StaffProfile,
2. lock aktywnego linku,
3. lock membership,
4. oznacz StaffProfile jako archived,
5. zakończ StaffMembershipLink przez `unlinked_at` + actor,
6. jeżeli membership jest `active`, przejdź do `suspended` zgodnie z DB-IAM-005,
7. wyczyść bound session tenant contexts w tej samej transakcji,
8. zwiększ `version` i `authorization_version` zgodnie z DB-IAM-005,
9. audit/outbox,
10. commit.

Jeżeli membership już był `suspended`, pozostaje suspended. Jeżeli był `revoked`, pozostaje revoked i niczego nie odtwarzamy.

## Archive StaffProfile powiązanego z Ownerem

Standardowy Staff archive:
- kończy StaffMembershipLink,
- **nie zmienia `is_owner`**,
- **nie suspenduje ani nie revoke'uje Owner membership jako ukrytego efektu**.

Owner governance jest osobnym high-risk lifecycle i może istnieć bez StaffProfile. Odebranie Ownerowi panel access wymaga jawnego Owner transfer/demotion/membership flow z DB-IAM-004/005. Dzięki temu last-owner guard nie jest obchodzony przez akcję kadrową.

Archive StaffProfile jedynego Ownera jest dozwolone jako archive profilu kadrowego, ponieważ Owner membership pozostaje aktywne i nie powstaje stan `0 active owners`.

## Skutek dla resolverów RBAC

Po archive nie istnieje aktywny StaffMembershipLink, więc:
- `own` przez StaffProfile → empty/deny,
- `assigned_locations` → empty/deny,
- `assigned_students` → empty/deny.

Jeżeli linked membership był Ownerem i pozostał aktywny, jego jawne membership permissions/scopes nadal są oceniane normalnie. To nie jest dostęp wynikający z archived StaffProfile.

## Restore StaffProfile

Domyślny restore przywraca tylko rekord kadrowy:
- czyści `archived_at`,
- nie reaktywuje membership,
- nie tworzy StaffMembershipLink,
- nie wiąże starych sesji.

Jawne przywrócenie panel access wymaga niearchiwalnego Staff, membership z tego samego OSK oraz braku conflicting active links. Powstaje nowy StaffMembershipLink row.

Status membership:
- `active` → nowy link,
- `suspended` → jawne `suspended -> active` według DB-IAM-005 + nowy link; stare sesje nie rebindują się automatycznie,
- `revoked` → brak automatycznej reaktywacji; wymagane świeże provisioning zgodnie z DB-IAM-005,
- aktywny Owner → nowy link bez zmiany Owner governance.

## Atomicity i historia

Archive jest jedną transakcją. Jeżeli którekolwiek przejście Staff/link/membership/session nie powiedzie się, wszystko się wycofuje. Historyczne StaffMembershipLink rows i Membership nie są hard-delete.

## Quality gate DB-RES-006

- archived Staff nie może mieć aktywnego StaffMembershipLink — **PASS DESIGN**,
- active link do archived Staff jest blokowany — **PASS DESIGN**,
- non-owner active membership jest suspended przy archive — **PASS**,
- session tenant contexts są czyszczone przez DB-IAM-005 — **PASS**,
- revoked membership nie jest odtwarzany — **PASS**,
- Owner nie jest po cichu demoted/suspended/revoked — **PASS**,
- last-owner guard nie może zostać ominięty — **PASS**,
- staff-derived resolvers po archive dają empty/deny — **PASS**,
- restore Staff nie przywraca automatycznie panel access — **PASS**,
- panel restore tworzy nowy link i zachowuje historię — **PASS**,
- transaction failure nie zostawia częściowego stanu — **PASS DESIGN**.

**Gate DB-RES-006: PASS.**

---

# DB4_3_STEP_8 — finalna synchronizacja aggregate — PASS

## Zakres

W tym kroku nie dodawano nowych reguł biznesowych. Wykonano wyłącznie synchronizację sześciu zamkniętych decyzji z bounded-contextu do:
- `specs/database/core-schema.yml`,
- `docs/87-physical-database-schema.md`.

DB4_4 nie został rozpoczęty. Nie utworzono migracji Laravel.

## Self-audit synchronizacji

Sprawdzono sześć ścieżek `bounded context -> aggregate machine spec -> narrative`:

1. **DB-RES-001 StaffMembershipLink same-tenant** — candidate keys + composite FK + active-link history są w core i docs87 — **PASS**.
2. **DB-RES-002 Staff/Vehicle ↔ Location** — join tables mają `organization_id`, tenant-aware PK i composite FK — **PASS**.
3. **DB-RES-003 FileAsset** — candidate key, same-tenant attachment, cztery purpose, `ready`, DB trigger/lock i brak platform asset dla private attachment są zachowane — **PASS**.
4. **DB-RES-004 documents** — `superseded_at`, zero-or-one current row, partial uniques, immutable history, replacement/clear semantics i current-only projection są zachowane — **PASS**.
5. **DB-RES-005 identity uniqueness** — PESEL/VIN durable per-OSK uniqueness i current-only registration uniqueness z restore conflict są zachowane — **PASS**.
6. **DB-RES-006 Staff archive/panel access** — unlink, non-owner suspend, Owner exception, resolver deny, explicit restore/new link i brak auto-reactivation revoked membership są zachowane — **PASS**.

Dodatkowo:
- stare proste `staff_location_assignment_unique` / `vehicle_location_assignment_unique` zostały zastąpione tenant-aware kluczami — **PASS**,
- stara niejednoznaczna polityka `optional PESEL/VIN` / full registration unique została usunięta z narrative — **PASS**,
- `Backend sprawdza organization` nie jest już jedyną granicą StaffMembershipLink — **PASS**,
- nie znaleziono konfliktu DB4_3 z DB4_2 Owner/membership lifecycle — **PASS**,
- migration/invariant test matrix w aggregate zawiera obowiązki DB4_3 — **PASS**,
- nie zredukowano potwierdzonego reverse-engineered capability — **PASS**.

Nie znaleziono nowego P0/P1 podczas synchronizacji.

## Finalny wynik DB4_3

- `DB-RES-001` — **PASS**,
- `DB-RES-002` — **PASS**,
- `DB-RES-003` — **PASS**,
- `DB-RES-004` — **PASS**,
- `DB-RES-005` — **PASS**,
- `DB-RES-006` — **PASS**,
- aggregate machine sync — **PASS**,
- aggregate narrative sync — **PASS**,
- otwarte P0/P1 — **0**.

**FINAL GATE DB4_3: PASS.**

DB4_4 może zostać otwarty dopiero jako osobny następny etap, zaczynając od diagnozy `Students / Courses / Training Ledger`, bez wykonywania migracji Laravel w tym samym kroku.
