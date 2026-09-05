# 107. Stage 4 — Staff / Locations / Vehicles physical invariant audit

Data: 2026-09-06

**Status:** `DB4_3 IN PROGRESS / DB-RES-001 PASS / 5 P1 BLOCKERS OPEN`

## Cel

Pracujemy nad `DB4_3_STAFF_LOCATIONS_VEHICLES` ściśle blocker po blockerze.

Nie wchodzimy w Student/Course/Calendar i nie generujemy migracji Laravel. Każdy blocker ma osobną decyzję, machine-readable spec, self-audit i gate.

Zasada:

`confirmed resource capability -> physical owner -> same-tenant relation -> lifecycle -> current projection -> invariant test`

## Sprawdzone źródła

- `specs/screens/staff.yml`,
- `specs/screens/staff-create.yml`,
- `specs/screens/staff-detail.yml`,
- `specs/screens/staff-edit.yml`,
- `specs/screens/locations.yml`,
- `specs/screens/school-locations-create.yml`,
- `specs/screens/school-locations-edit.yml`,
- `specs/screens/vehicles.yml`,
- `specs/screens/vehicle-create.yml`,
- `specs/screens/vehicle-detail.yml`,
- `specs/screens/vehicle-edit.yml`,
- `specs/screens/vehicle-delete.yml`,
- `specs/database/core-schema.yml`,
- `specs/database/identity-rbac.yml`,
- `specs/database/staff-locations-vehicles.yml`,
- `specs/security/permissions.yml`,
- `specs/traceability/core-v1.yml`,
- `docs/87-physical-database-schema.md`.

Podczas zamykania DB4_3 bounded-context source `specs/database/staff-locations-vehicles.yml` jest autorytatywny dla nowych decyzji tego slice'u. Aggregate `core-schema.yml` i `docs/87` zostaną zsynchronizowane przed finalnym PASS całego DB4_3 i przed generowaniem migracji.

---

# Co jest już poprawne

## Model zasobów odpowiada ekranom

Physical blueprint potrafi reprezentować potwierdzone pola i multi-selecty bez wrzucania relacji do JSON:
- StaffProfile,
- StaffTypeAssignment,
- StaffCategoryAssignment,
- StaffLocationAssignment,
- StaffDocument,
- StaffMembershipLink,
- Location,
- Vehicle,
- VehicleDocument,
- VehicleCategoryAssignment,
- VehicleLocationAssignment.

Potwierdzone trzy niezależne daty ważności dokumentów pracownika i pojazdu nie zostały scalone w jedno pole.

## StaffProfile jest oddzielony od loginu

Staff może istnieć bez `User`/membership. Dostęp panelowy jest osobnym lifecycle przez `StaffMembershipLink -> OrganizationMembership`, zgodnym z DB4_2.

## Lokalizacja szkoleniowa jest oddzielona od adresu firmy

`locations` i `organization_contact_addresses` pozostają odrębnymi konceptami.

## Kategorie są globalnym słownikiem

`driving_categories` nie jest tenant-owned. Dlatego assignment `staff/vehicle -> driving_category` nie wymaga sztucznego `organization_id` po stronie kategorii; nadal musi poprawnie wskazywać parent resource.

## Archive zamiast destrukcyjnego usuwania

Staff/Vehicle/Location posiadają archiwizację i wymaganie zachowania historycznych referencji. Szczegółowe skutki dla przyszłych Calendar/Course records mogą być dopięte w zależnych slice'ach, ale schema nie może ich usuwać cascade.

## Demo sentinel date nie jest wymaganiem

`01-01-0001` dla pojazdu pozostaje sklasyfikowane jako demo/sentinel anomaly. Canonical model używa realnego timestamp albo `NULL`.

---

# DB-RES-001 — PASS: `StaffMembershipLink` same-organization integrity

## Problem

`staff_membership_links` posiada:
- `organization_id`,
- `staff_profile_id`,
- `organization_membership_id`.

Przy samych niezależnych FK możliwy byłby fizycznie rekord łączący `StaffProfile` z OSK A z `OrganizationMembership` z OSK B. To jest krytyczne, ponieważ DB4_2 zamroził ścieżkę:

`OrganizationMembership -> active StaffMembershipLink -> StaffProfile`

jako podstawę resolverów `own`, `assigned_locations` i `assigned_students`.

## Decyzja canonical

`StaffMembershipLink.organization_id` pozostaje obowiązkowym tenant key i jest fizycznie związany z obiema stronami relacji przez dwa composite FK.

Wymagane candidate keys:
- `staff_profiles UNIQUE(organization_id,id)`,
- `organization_memberships UNIQUE(organization_id,id)`.

Wymagane FK:

`staff_membership_links(organization_id,staff_profile_id)`
`-> staff_profiles(organization_id,id)`

oraz:

`staff_membership_links(organization_id,organization_membership_id)`
`-> organization_memberships(organization_id,id)`.

Oba używają `ON UPDATE RESTRICT / ON DELETE RESTRICT`. Dzięki temu DB, a nie tylko backend, blokuje cross-tenant link.

## Tenant ownership

Dla tej relacji tenant ownership jest historyczną częścią tożsamości:
- `StaffMembershipLink.organization_id` nie jest zmieniany po utworzeniu,
- `OrganizationMembership.organization_id` nie jest przepisywany do innego OSK,
- istniejącego `StaffProfile` z historycznymi relacjami nie „przenosimy” do innego OSK przez zmianę `organization_id`.

Jeżeli biznesowo potrzebna będzie relacja w innym OSK, powstaje właściwy tenant resource/membership/link lifecycle zamiast przepisywania historii.

## Active-link uniqueness pozostaje bez zmian

Nadal obowiązuje:
- najwyżej jeden aktywny link na `staff_profile_id`,
- najwyżej jeden aktywny link na `organization_membership_id`,
- aktywny = `unlinked_at IS NULL`.

Composite FK jest dodatkową granicą bezpieczeństwa; nie zastępuje tych partial unique indexes.

## Historia

Normalne odłączenie nie usuwa `StaffMembershipLink`. Ustawia `unlinked_at` i actor, zachowując tenant identity rekordu. Suspend/revoke membership nie przepisuje historycznego tenant linku.

Wpływ archiwizacji StaffProfile na aktywny link i membership pozostaje świadomie poza tym krokiem — to `DB-RES-006`.

## Migration precheck

Przed utworzeniem constraintów migracja musi wykryć:
- link, którego `organization_id` nie pasuje do `StaffProfile.organization_id`,
- link, którego `organization_id` nie pasuje do `OrganizationMembership.organization_id`,
- naruszenia istniejącej active-link uniqueness.

Nie wolno automatycznie „naprawić” security mismatch przez przepięcie rekordu do innego użytkownika/OSK. Migracja failuje albo wymaga jawnej remediacji.

## Test obligations DB-RES-001

- same-organization StaffProfile + membership -> accepted,
- StaffProfile OSK A + Membership OSK B -> rejected by DB,
- link.organization A + StaffProfile B -> rejected by DB,
- link.organization A + Membership B -> rejected by DB,
- drugi aktywny link dla tego samego StaffProfile -> rejected,
- drugi aktywny link dla tego samego OrganizationMembership -> rejected,
- unlinked history pozostaje zachowana,
- suspend/revoke membership nie zmienia historycznego tenant identity linku,
- RBAC traversal przez StaffMembershipLink nie może przekroczyć organizacji.

Machine source: `specs/database/staff-locations-vehicles.yml`.

**Gate DB-RES-001: PASS.**

---

# DB-RES-002 — OPEN P1 SECURITY: Staff/Vehicle ↔ Location join tables mogą tworzyć cross-tenant assignment

`staff_location_assignments(staff_profile_id,location_id)` i `vehicle_location_assignments(vehicle_id,location_id)` nie mają jeszcze jawnego tenant key/composite same-organization constraint.

To jest szczególnie istotne dla `StaffLocationAssignments`, ponieważ `assigned_locations` z DB-IAM-002 używa tej relacji jako security resolvera.

Potrzebny tenant-aware join shape i same-organization FK/invariant dla obu join tables.

**To jest następny i jedyny blocker do naprawy.**

---

# DB-RES-003 — OPEN P1 SECURITY: Staff/Vehicle document i photo `FileAsset`

Staff/Vehicle posiadają `photo_asset_id`, a dokumenty `asset_id`. Sam FK do `file_assets.id` nie zabrania przypięcia assetu z innego OSK albo platformowego assetu jako prywatnego dokumentu.

Do zamknięcia pozostaje same-tenant + purpose + ready attachment contract dla:
- StaffProfile.photo,
- StaffDocument.asset,
- Vehicle.photo,
- VehicleDocument.asset.

Nie naprawiamy jeszcze.

---

# DB-RES-004 — OPEN P1: current document validity projection

UI ma po jednej bieżącej dacie dla każdego typu dokumentu, ale `staff_documents` i `vehicle_documents` dopuszczają wiele rekordów tego samego typu bez current/superseded semantics.

Trzeba wybrać jednoznaczny history-safe model current row/versioning. Nie naprawiamy jeszcze.

---

# DB-RES-005 — OPEN P1: identity uniqueness lifecycle Staff/Vehicle

Do finalnego rozstrzygnięcia pozostają:
- Staff PESEL uniqueness, gdy podany,
- Vehicle registration number uniqueness przez archive/restore,
- Vehicle VIN uniqueness, gdy podany.

Migracje muszą wiedzieć, czy constraint obejmuje całą trwałą historię czy tylko current rows. Nie naprawiamy jeszcze.

---

# DB-RES-006 — OPEN P1 SECURITY: archiwizacja StaffProfile vs panel access

Jeżeli StaffProfile zostanie zarchiwizowany bez zmiany aktywnego StaffMembershipLink/OrganizationMembership, były pracownik może zachować panel access. Z kolei bezwarunkowy revoke membership może zepsuć restore i last-owner guard.

Potrzebna jawna atomowa policy respektująca DB-IAM-004/005. Nie naprawiamy jeszcze.

---

# Nie są blockerami DB4_3 na tym etapie

- dokładny próg `expiring_soon` — projection/config, nie physical integrity blocker,
- exact future-event behavior po archive Location/Vehicle — zamyka DB4_5,
- exact course behavior po archive Location/Staff — zamyka DB4_4,
- exact validator polskiego numeru rejestracyjnego/VIN/telefonu — application validation, chyba że późniejszy gate wymaga DB check,
- source katalogu miejscowości — adapter/dictionary decision,
- category assignment tenant FK — driving category jest globalnym dictionary,
- competitor archive/delete backend semantics — nieobserwowalne; own product stosuje bezpieczny lifecycle z equivalent capability.

---

# Quality gate po DB-RES-001

Sprawdzono:
- czy obie strony StaffMembershipLink muszą należeć do `link.organization_id` — **PASS**,
- czy DB ma fizyczne composite FK, a nie tylko backend check — **PASS**,
- czy istnieją wymagane candidate keys parentów — **PASS DESIGN**,
- czy active-link uniqueness pozostało zachowane — **PASS**,
- czy historia unlink pozostaje zachowana — **PASS**,
- czy resolver RBAC nie może dostać cross-tenant bridge — **PASS**,
- czy migration precheck zabrania silent reassignment — **PASS**,
- czy nie rozwiązano przy okazji DB-RES-002..006 — **PASS**,
- czy nie rozpoczęto DB4_4 ani migracji Laravel — **PASS**.

Nie znaleziono nowego P0/P1 wynikającego z decyzji DB-RES-001.

Aggregate `core-schema.yml` i `docs/87` nie są jeszcze finalnie zsynchronizowane z nowym composite-link contract. To jest jawnie kontrolowane przez bounded-context authority rule i zostanie wykonane przed **finalnym DB4_3 PASS**, nie między pojedynczymi blockerami.

---

# Wynik po DB4_3_STEP_2

- `DB-RES-001` — **PASS**,
- `DB-RES-002` — **OPEN P1**,
- `DB-RES-003` — **OPEN P1**,
- `DB-RES-004` — **OPEN P1**,
- `DB-RES-005` — **OPEN P1**,
- `DB-RES-006` — **OPEN P1**.

DB4_3 jako całość nadal ma **FAIL / IN_PROGRESS**. DB4_4 pozostaje zablokowany.

**Następny pojedynczy krok: tylko `DB-RES-002` — tenant integrity dla Staff/Vehicle ↔ Location assignments.**
