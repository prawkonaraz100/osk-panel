# 107. Stage 4 — Staff / Locations / Vehicles physical invariant audit

Data: 2026-09-06

**Status:** `DB4_3 IN PROGRESS / DB-RES-001 PASS / DB-RES-002 PASS / DB-RES-003 PASS / 3 P1 BLOCKERS OPEN`

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

Dotychczas `staff_location_assignments(staff_profile_id,location_id)` oraz `vehicle_location_assignments(vehicle_id,location_id)` miały tylko niezależne FK. Błąd aplikacji/importu mógł więc połączyć zasób OSK A z Location OSK B.

Dla Staff było to dodatkowo krytyczne, ponieważ `assigned_locations` z DB-IAM-002 opiera się właśnie na StaffLocationAssignments.

## Decyzja canonical

Obie join tables dostają jawny `organization_id` i stają się tenant-aware.

### StaffLocationAssignment

`PRIMARY KEY (organization_id,staff_profile_id,location_id)`

Composite FK:
- `(organization_id,staff_profile_id) -> staff_profiles(organization_id,id)`,
- `(organization_id,location_id) -> locations(organization_id,id)`.

### VehicleLocationAssignment

`PRIMARY KEY (organization_id,vehicle_id,location_id)`

Composite FK:
- `(organization_id,vehicle_id) -> vehicles(organization_id,id)`,
- `(organization_id,location_id) -> locations(organization_id,id)`.

Wymagane candidate keys:
- `staff_profiles(organization_id,id)`,
- `vehicles UNIQUE(organization_id,id)`,
- `locations UNIQUE(organization_id,id)`.

Wszystkie composite FK używają `ON UPDATE RESTRICT / ON DELETE RESTRICT`.

## Tenant ownership

`organization_id` w assignment jest immutable po utworzeniu. StaffProfile, Vehicle i Location z historycznymi relacjami nie są przenoszone do innego OSK przez przepisanie tenant key.

To nie zmienia assignmentów kategorii, ponieważ `driving_categories` jest globalnym słownikiem.

## Skutek dla RBAC

Po DB-RES-001 + DB-RES-002 resolver:

`OrganizationMembership -> active StaffMembershipLink -> StaffProfile -> StaffLocationAssignment -> Location`

ma zamknięte tenant boundaries.

## Migration precheck

Przed constraintami należy wykryć cross-tenant pary i duplikaty, backfillować `organization_id` wyłącznie z wcześniej zweryfikowanego parenta i ponownie udowodnić zgodność drugiej strony. Nie ma silent reassignment.

## Quality gate DB-RES-002

- tenant-aware join tables — **PASS**,
- composite same-tenant FK — **PASS**,
- candidate keys — **PASS DESIGN**,
- duplicate assignment jednoznacznie blokowany — **PASS**,
- `assigned_locations` nie może użyć cross-tenant relation — **PASS**,
- Vehicle↔Location równie chronione — **PASS**,
- category assignments poza zakresem zmiany — **PASS**,
- migration precheck bez silent reassignment — **PASS**,
- nie rozpoczęto kolejnych blockerów — **PASS**.

**Gate DB-RES-002: PASS.**

---

# DB-RES-003 — PASS: Staff/Vehicle FileAsset same-tenant + purpose + ready attachment

## Problem

Staff/Vehicle mają `photo_asset_id`, a `staff_documents` i `vehicle_documents` mogą wskazywać `asset_id`.

Prosty FK do `file_assets.id` nie chronił przed:
- assetem z innego OSK,
- platformowym/global assetem (`organization_id IS NULL`) użytym jako prywatny dokument lub zdjęcie,
- assetem o złym `purpose`,
- assetem, który nie osiągnął stanu `ready`.

To było sprzeczne z upload security, według którego business entity może przypiąć plik dopiero po bezpiecznym zakończeniu upload/scan.

## Decyzja canonical — tenant boundary

`file_assets` dostaje wymagany candidate key:

`UNIQUE(organization_id,id)`.

Cztery ścieżki używają composite FK:
- `staff_profiles(organization_id,photo_asset_id) -> file_assets(organization_id,id)`,
- `staff_documents(organization_id,asset_id) -> file_assets(organization_id,id)`,
- `vehicles(organization_id,photo_asset_id) -> file_assets(organization_id,id)`,
- `vehicle_documents(organization_id,asset_id) -> file_assets(organization_id,id)`.

`MATCH SIMPLE / ON UPDATE RESTRICT / ON DELETE RESTRICT`.

Asset reference pozostaje nullable tam, gdzie UI dopuszcza brak pliku. Gdy reference jest nie-null, tenant-owned source ma nie-null `organization_id`, więc platformowy asset z `file_assets.organization_id IS NULL` nie może spełnić FK.

## Purpose mapping

Dokładne klasy attachment:
- StaffProfile.photo → `staff_photo`,
- StaffDocument.asset → `staff_document`,
- Vehicle.photo → `vehicle_photo`,
- VehicleDocument.asset → `vehicle_document`.

Business `document_type` nadal należy do `staff_documents` / `vehicle_documents`. `FileAsset.purpose` opisuje klasę attachmentu, a nie np. `medical_exam` czy `oc_insurance`.

## Ready + purpose jako DB boundary

Same-tenant integralność jest wymuszana composite FK. Dynamicznego `status='ready'` i właściwego `purpose` nie próbujemy modelować przez kopiowanie mutable statusu do czterech tabel biznesowych.

Canonical physical design używa wspólnego `BEFORE INSERT OR UPDATE` constraint triggera (lub równoważnego DB triggera) dla zmian asset reference/tenant key. Trigger:
1. ładuje wskazany same-tenant `FileAsset`,
2. blokuje rekord `FOR SHARE` do końca transakcji attachmentu,
3. sprawdza dokładny expected purpose dla danej ścieżki,
4. wymaga `status='ready'`,
5. odrzuca missing/foreign/platform/wrong-purpose/non-ready asset.

Laravel nadal wykonuje wcześniejszą walidację dla dobrego UX, ale **DB trigger jest finalną granicą attachmentu**. Dzięki lockowi status nie może zmienić się między walidacją a commitem attachmentu.

## Lifecycle po attachment

`FileAsset.purpose` jest immutable po zakończeniu uploadu.

Późniejszy kontrolowany security transition assetu do stanu non-ready może zachować historyczny business reference, ale taki asset nie może być serwowany/downloadowany tylko dlatego, że referencja nadal istnieje. Download ponownie sprawdza aktualną politykę/stage assetu.

Zmiana zdjęcia lub dokumentu nie kasuje automatycznie poprzedniego FileAsset. Retencja i bezpieczne usuwanie assetu są osobnym lifecycle.

## Migration precheck

Przed constraintami/triggerem trzeba wykryć:
- foreign-tenant attachments,
- platform asset użyty jako prywatny attachment,
- wrong-purpose attachments,
- non-ready attachments wymagające jawnej remediacji.

Nie wolno automatycznie przepinać assetu do innego OSK ani po cichu przepisywać purpose tylko po to, żeby migracja przeszła.

## Quality gate DB-RES-003

Sprawdzono:
- same-tenant na wszystkich czterech ścieżkach — **PASS**,
- platform/global asset nie może wejść do prywatnego Staff/Vehicle attachmentu — **PASS**,
- purpose mapping jest jawny i oddzielony od document type — **PASS**,
- `ready` jest wymagane przy attachment commit — **PASS**,
- race `status change ↔ attach` jest serializowany lockiem — **PASS DESIGN**,
- późniejszy non-ready stan nie daje prawa do downloadu — **PASS**,
- replacement nie usuwa automatycznie starego assetu — **PASS**,
- migration precheck nie robi silent tenant/purpose repair — **PASS**,
- DB-RES-004..006 nie zostały naprawione w tym kroku — **PASS**,
- nie rozpoczęto DB4_4 ani migracji Laravel — **PASS**.

Nie znaleziono nowego P0/P1 wynikającego z decyzji DB-RES-003.

Machine source: `specs/database/staff-locations-vehicles.yml`.

**Gate DB-RES-003: PASS.**

---

# DB-RES-004 — OPEN P1: current document validity projection

UI ma po jednej bieżącej dacie dla każdego typu dokumentu, ale `staff_documents` i `vehicle_documents` dopuszczają wiele rekordów tego samego typu bez current/superseded semantics.

Trzeba wybrać jednoznaczny history-safe model current row/versioning. **To jest następny i jedyny blocker do naprawy.**

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

# Wynik po DB4_3_STEP_4

- `DB-RES-001` — **PASS**,
- `DB-RES-002` — **PASS**,
- `DB-RES-003` — **PASS**,
- `DB-RES-004` — **OPEN P1**,
- `DB-RES-005` — **OPEN P1**,
- `DB-RES-006` — **OPEN P1**.

DB4_3 jako całość nadal ma **FAIL / IN_PROGRESS** z 3 blockerami P1. DB4_4 pozostaje zablokowany.

Aggregate `core-schema.yml` i `docs/87` zostaną zsynchronizowane przed finalnym DB4_3 PASS, po zamknięciu blockerów bounded-contextu.

**Następny pojedynczy krok: tylko `DB-RES-004` — history-safe current document validity projection.**
