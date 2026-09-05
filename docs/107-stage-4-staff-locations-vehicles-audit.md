# 107. Stage 4 — Staff / Locations / Vehicles physical invariant audit

Data: 2026-09-06

**Status:** `DB4_3 IN PROGRESS / DB-RES-001 PASS / DB-RES-002 PASS / 4 P1 BLOCKERS OPEN`

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
- `docs/87-physical-database-schema.md`.

Podczas zamykania DB4_3 `specs/database/staff-locations-vehicles.yml` jest autorytatywnym bounded-context source dla nowych decyzji tego slice'u. Aggregate `core-schema.yml` i `docs/87` zostaną zsynchronizowane przed finalnym PASS całego DB4_3 i przed generowaniem migracji.

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

## Problem

Przy samych prostych FK `staff_membership_links` mógł połączyć StaffProfile z OSK A z OrganizationMembership z OSK B. To podważałoby bazę resolverów `own`, `assigned_locations` i `assigned_students`.

## Decyzja

`staff_membership_links.organization_id` jest obowiązkowym tenant key.

Candidate keys:
- `staff_profiles UNIQUE(organization_id,id)`,
- `organization_memberships UNIQUE(organization_id,id)`.

Composite FK:
- `(organization_id,staff_profile_id) -> staff_profiles(organization_id,id)`,
- `(organization_id,organization_membership_id) -> organization_memberships(organization_id,id)`.

`ON UPDATE RESTRICT / ON DELETE RESTRICT`.

Active-link uniqueness pozostaje:
- najwyżej jeden `unlinked_at IS NULL` na StaffProfile,
- najwyżej jeden `unlinked_at IS NULL` na OrganizationMembership.

Normalny unlink zachowuje historyczny link. Tenant identity istniejącej historii nie jest przepisywana.

Wpływ archive Staff na membership pozostaje `DB-RES-006`.

## Gate

- cross-tenant StaffMembershipLink niemożliwy po constraintach — PASS,
- active-link uniqueness zachowane — PASS,
- history unlink zachowana — PASS,
- migration precheck bez silent reassignment — PASS,
- nie ruszono pozostałych blockerów — PASS.

**Gate DB-RES-001: PASS.**

---

# DB-RES-002 — PASS: Staff/Vehicle ↔ Location tenant integrity

## Problem

Dotychczas:

`staff_location_assignments(staff_profile_id,location_id)`

oraz

`vehicle_location_assignments(vehicle_id,location_id)`

miały tylko niezależne FK. Błąd aplikacji/importu mógł więc połączyć zasób OSK A z Location OSK B.

Dla Staff było to dodatkowo krytyczne, ponieważ `assigned_locations` z DB-IAM-002 opiera się właśnie na StaffLocationAssignments.

## Decyzja canonical

Obie join tables dostają jawny `organization_id` i stają się tenant-aware.

### StaffLocationAssignment

Canonical key:

`PRIMARY KEY (organization_id,staff_profile_id,location_id)`

Composite FK:
- `(organization_id,staff_profile_id) -> staff_profiles(organization_id,id)`,
- `(organization_id,location_id) -> locations(organization_id,id)`.

### VehicleLocationAssignment

Canonical key:

`PRIMARY KEY (organization_id,vehicle_id,location_id)`

Composite FK:
- `(organization_id,vehicle_id) -> vehicles(organization_id,id)`,
- `(organization_id,location_id) -> locations(organization_id,id)`.

Wymagane candidate keys:
- `staff_profiles(organization_id,id)` — już wymagane przez DB-RES-001,
- `vehicles UNIQUE(organization_id,id)`,
- `locations UNIQUE(organization_id,id)`.

Wszystkie composite FK używają `ON UPDATE RESTRICT / ON DELETE RESTRICT`.

## Tenant ownership

`organization_id` w assignment jest immutable po utworzeniu. StaffProfile, Vehicle i Location z historycznymi relacjami nie są „przenoszone” do innego OSK przez przepisanie tenant key.

To nie zmienia assignmentów kategorii, ponieważ `driving_categories` jest globalnym słownikiem, a nie tenant resource.

## Skutek dla RBAC

Po DB-RES-001 + DB-RES-002 cały bazowy resolver:

`OrganizationMembership -> active StaffMembershipLink -> StaffProfile -> StaffLocationAssignment -> Location`

ma fizycznie zamknięte tenant boundaries.

`assigned_locations` nie może zostać rozszerzone przez przypadkowy cross-tenant join. Frontend filtering nadal nie jest security boundary.

## Skutek dla Vehicle/Calendar

VehicleLocationAssignment nie może tworzyć fałszywej relacji Vehicle OSK A ↔ Location OSK B, która później zanieczyściłaby resource filters lub conflict checks kalendarza.

Dokładne zachowanie kalendarza pozostaje DB4_5; tutaj zamykamy wyłącznie integralność relacji.

## Migration precheck

Przed constraintami należy:
- wykryć każdą Staff↔Location parę, której parenty mają różne `organization_id`,
- wykryć każdą Vehicle↔Location parę z różnymi tenantami,
- wykryć duplikaty par,
- dodać/backfillować `organization_id` tylko z wcześniej zweryfikowanego parenta,
- ponownie udowodnić zgodność drugiej strony przed utworzeniem composite FK.

Cross-tenant mismatch nie jest automatycznie przepinany do innej lokalizacji.

## Test obligations DB-RES-002

- same-tenant Staff↔Location accepted,
- Staff OSK A ↔ Location OSK B rejected by DB,
- assignment tenant key mismatch rejected,
- duplicate Staff↔Location rejected,
- same-tenant Vehicle↔Location accepted,
- Vehicle OSK A ↔ Location OSK B rejected by DB,
- Vehicle assignment tenant key mismatch rejected,
- duplicate Vehicle↔Location rejected,
- `assigned_locations` nie zwraca lokalizacji innego OSK,
- vehicle/location calendar filter nie przekracza tenant boundary,
- migration precheck wykrywa istniejące cross-tenant pary.

Machine source: `specs/database/staff-locations-vehicles.yml`.

## Quality gate DB-RES-002

Sprawdzono:
- czy obie join tables są tenant-aware — **PASS**,
- czy parent/location są spinane composite FK — **PASS**,
- czy wymagane candidate keys są jawne — **PASS DESIGN**,
- czy duplicate assignment ma jednoznaczny constraint — **PASS**,
- czy `assigned_locations` nie może użyć cross-tenant relation — **PASS**,
- czy Vehicle↔Location jest równie chronione — **PASS**,
- czy category assignments pozostały poza zmianą — **PASS**,
- czy migration precheck nie robi silent reassignment — **PASS**,
- czy nie rozwiązano DB-RES-003..006 — **PASS**,
- czy nie rozpoczęto DB4_4 ani migracji Laravel — **PASS**.

Nie znaleziono nowego P0/P1 wynikającego z decyzji DB-RES-002.

**Gate DB-RES-002: PASS.**

---

# DB-RES-003 — OPEN P1 SECURITY: Staff/Vehicle FileAsset attachment

Staff/Vehicle posiadają `photo_asset_id`, a dokumenty `asset_id`. Sam FK do `file_assets.id` nie zabrania przypięcia assetu z innego OSK albo platformowego assetu jako prywatnego dokumentu.

Do zamknięcia pozostaje same-tenant + purpose + ready attachment contract dla:
- StaffProfile.photo,
- StaffDocument.asset,
- Vehicle.photo,
- VehicleDocument.asset.

**To jest następny i jedyny blocker do naprawy.**

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

Nie naprawiamy jeszcze.

---

# DB-RES-006 — OPEN P1 SECURITY: archiwizacja StaffProfile vs panel access

Jeżeli StaffProfile zostanie zarchiwizowany bez zmiany aktywnego StaffMembershipLink/OrganizationMembership, były pracownik może zachować panel access. Bezwarunkowy revoke membership może natomiast zepsuć restore i last-owner guard.

Potrzebna jawna atomowa policy respektująca DB-IAM-004/005. Nie naprawiamy jeszcze.

---

# Nie są blockerami DB4_3 na tym etapie

- próg `expiring_soon`,
- future Calendar behavior po archive — DB4_5,
- active Course behavior po archive — DB4_4,
- szczegółowe walidatory numeru rejestracyjnego/VIN/telefonu,
- provider katalogu miejscowości,
- tenant FK do globalnego słownika driving category,
- nieobserwowalne backend semantics konkurencyjnego delete.

---

# Wynik po DB4_3_STEP_3

- `DB-RES-001` — **PASS**,
- `DB-RES-002` — **PASS**,
- `DB-RES-003` — **OPEN P1**,
- `DB-RES-004` — **OPEN P1**,
- `DB-RES-005` — **OPEN P1**,
- `DB-RES-006` — **OPEN P1**.

DB4_3 jako całość nadal ma **FAIL / IN_PROGRESS** z 4 blockerami P1. DB4_4 pozostaje zablokowany.

Aggregate `core-schema.yml` i `docs/87` zostaną zsynchronizowane przed finalnym DB4_3 PASS, po zamknięciu blockerów bounded-contextu.

**Następny pojedynczy krok: tylko `DB-RES-003` — Staff/Vehicle FileAsset same-tenant + purpose + ready attachment integrity.**
