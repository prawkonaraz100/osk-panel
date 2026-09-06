# 107. Stage 4 — Staff / Locations / Vehicles physical invariant audit

Data: 2026-09-06

**Status:** `DB4_3 ALL 6 BLOCKERS PASS / FINAL AGGREGATE SYNC PENDING`

## Cel

Pracujemy nad `DB4_3_STAFF_LOCATIONS_VEHICLES` ściśle blocker po blockerze.

Nie wchodzimy w Student/Course/Calendar i nie generujemy migracji Laravel. Każdy blocker ma osobną decyzję, machine-readable spec, self-audit i gate.

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

Podczas zamykania DB4_3 `specs/database/staff-locations-vehicles.yml` jest autorytatywnym bounded-context source dla nowych decyzji tego slice'u. Aggregate `core-schema.yml` i `docs/87` zostaną zsynchronizowane w osobnym, następnym kroku przed finalnym PASS całego DB4_3 i przed generowaniem migracji.

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

To oznacza, że archive pracownika usuwa staff-derived path i wyłącza zwykłe konto pracownika bez destrukcyjnego revoke permission history.

## Archive StaffProfile powiązanego z Ownerem

Owner jest wyjątkiem świadomym i bezpiecznym.

Standardowy Staff archive:
- kończy StaffMembershipLink,
- **nie zmienia `is_owner`**,
- **nie suspenduje ani nie revoke'uje Owner membership jako ukrytego efektu**.

Powód: Owner governance jest osobnym high-risk lifecycle i może legalnie istnieć bez StaffProfile. Po unlink owner zachowuje wyłącznie prawa wynikające bezpośrednio z OrganizationMembership; nie ma już staff-derived `own`, `assigned_locations` ani `assigned_students` przez zarchiwizowany profil.

Jeżeli administrator chce równocześnie odebrać Ownerowi dostęp do panelu, nie robi tego przez „Archiwizuj pracownika”. Musi użyć jawnego Owner transfer/demotion/membership flow z DB-IAM-004/005. Dzięki temu last-owner guard nie jest obchodzony przez akcję kadrową.

W szczególności archive StaffProfile jedynego Ownera jest dozwolone jako archive profilu kadrowego, bo Owner membership pozostaje aktywne. Nie powstaje stan `0 active owners`.

## Skutek dla resolverów RBAC

Po archive nie istnieje aktywny StaffMembershipLink, więc:
- `own` przez StaffProfile → empty/deny,
- `assigned_locations` → empty/deny,
- `assigned_students` → empty/deny.

Jeżeli linked membership był Ownerem i pozostał aktywny, jego jawne membership permissions/scopes nadal są oceniane normalnie. To nie jest dostęp wynikający z archived StaffProfile.

## Restore StaffProfile

Domyślny restore przywraca **tylko rekord kadrowy**:
- czyści `archived_at`,
- nie reaktywuje membership,
- nie tworzy StaffMembershipLink,
- nie wiąże starych sesji.

Przywrócenie panel access wymaga osobnej, jawnej decyzji.

Jeżeli operator jawnie przywraca panel access:
- Staff musi być już niearchiwalny,
- membership musi należeć do tego samego OSK,
- nie może istnieć conflicting active link po stronie Staff ani membership,
- powstaje **nowy** StaffMembershipLink row.

Status membership:
- `active` → tworzymy nowy link,
- `suspended` → jawne `suspended -> active` według DB-IAM-005 + nowy link; stare sesje nie rebindują się automatycznie,
- `revoked` → brak automatycznej reaktywacji; wymagane świeże permission/template provisioning zgodnie z DB-IAM-005,
- Owner active po archive → jawny restore panel link tworzy nowy link, bez zmiany Owner governance.

## Atomicity i historia

Archive jest jedną transakcją. Jeżeli którekolwiek przejście Staff/link/membership/session nie powiedzie się, wszystko się wycofuje. Nie może istnieć częściowy stan „Staff archived, ale stary link nadal aktywny”.

Historyczne StaffMembershipLink rows nie są hard-delete. Membership również nie jest hard-delete.

## Migration precheck

Przed guardami należy wykryć:
- archived StaffProfile z aktywnym StaffMembershipLink,
- aktywny StaffMembershipLink wskazujący archived StaffProfile,
- aktywne non-owner membership pozostawione jako staff-panel account dla archived Staff.

Cross-tenant mismatch pozostaje zakresem już zamkniętego DB-RES-001.

Migracja nie może po cichu:
- reaktywować Staff,
- przenosić Ownera,
- revoke'ować membership,
- usuwać historycznych linków.

Wymagana jest jawna i audytowalna remediation.

## Quality gate DB-RES-006

Sprawdzono:
- archived Staff nie może mieć aktywnego StaffMembershipLink — **PASS DESIGN**,
- nowy active link do archived Staff jest blokowany w DB boundary — **PASS DESIGN**,
- zwykły non-owner panel account jest suspended przy archive — **PASS**,
- session tenant contexts są czyszczone przez istniejący DB-IAM-005 lifecycle — **PASS**,
- revoked membership nie jest przypadkiem odtwarzany — **PASS**,
- Owner nie jest po cichu demoted/suspended/revoked przez akcję Staff archive — **PASS**,
- last-owner guard nie może zostać ominięty Staff archive — **PASS**,
- staff-derived resolvers po archive dają empty/deny — **PASS**,
- restore Staff nie przywraca automatycznie panel access — **PASS**,
- panel restore tworzy nowy historyczny link zamiast otwierać stary — **PASS**,
- suspended/revoked restore respektuje DB-IAM-005 — **PASS**,
- archive/link creation race jest serializowany — **PASS DESIGN**,
- transaction failure nie może zostawić częściowego stanu — **PASS DESIGN**,
- DB4_4 ani migracje Laravel nie zostały rozpoczęte — **PASS**.

Nie znaleziono nowego P0/P1 wynikającego z decyzji DB-RES-006.

Machine source: `specs/database/staff-locations-vehicles.yml`.

**Gate DB-RES-006: PASS.**

---

# Stan DB4_3 po DB4_3_STEP_7

- `DB-RES-001` — **PASS**,
- `DB-RES-002` — **PASS**,
- `DB-RES-003` — **PASS**,
- `DB-RES-004` — **PASS**,
- `DB-RES-005` — **PASS**,
- `DB-RES-006` — **PASS**.

W zdiagnozowanym bounded-context DB4_3 pozostało **0 otwartych P0/P1**.

To **nie oznacza jeszcze finalnego PASS całego DB4_3**, ponieważ zgodnie z planem pozostała osobna bramka synchronizacji:

`specs/database/staff-locations-vehicles.yml -> specs/database/core-schema.yml + docs/87-physical-database-schema.md`

Dopiero po tej synchronizacji i jej własnym gate można oznaczyć DB4_3 jako PASS i otworzyć DB4_4.

**Następny pojedynczy krok: tylko finalna synchronizacja aggregate DB4_3 + gate.**
