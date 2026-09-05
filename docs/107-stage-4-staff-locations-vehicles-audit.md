# 107. Stage 4 — Staff / Locations / Vehicles physical invariant audit

Data: 2026-09-05

**Status:** `DB4_3 DIAGNOSIS COMPLETE / FAIL WITH 6 P1 BLOCKERS`

## Cel

Ten krok jest wyłącznie diagnozą `DB4_3_STAFF_LOCATIONS_VEHICLES`.

Nie naprawiamy wykrytych problemów w tym samym kroku, nie wchodzimy w Student/Course/Calendar i nie generujemy migracji Laravel.

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
- `specs/security/permissions.yml`,
- `specs/traceability/core-v1.yml`,
- `docs/87-physical-database-schema.md`.

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

# Blockery DB4_3

## DB-RES-001 — P1 SECURITY: `StaffMembershipLink` nie gwarantuje same-organization integrity

### Stan

`staff_membership_links` posiada:
- `organization_id`,
- `staff_profile_id`,
- `organization_membership_id`,

ale physical narrative mówi tylko, że backend sprawdza zgodność organizacji.

DB4_2 zamroził resolver:

`OrganizationMembership -> active StaffMembershipLink -> StaffProfile`

jako podstawę `own`, `assigned_locations` i `assigned_students`.

### Problem

Proste niezależne FK pozwalają fizycznie utworzyć link:
- `organization_id = OSK A`,
- `staff_profile_id` z OSK A,
- `organization_membership_id` należący do OSK B,

albo analogiczną inną kombinację.

Taki rekord może podważyć cały resolver scope.

### Wymagane rozstrzygnięcie

Potrzebny composite same-organization contract albo równie mocny physical invariant dla:
- link.organization_id + staff_profile_id,
- link.organization_id + organization_membership_id.

Active-link uniqueness per StaffProfile i per OrganizationMembership ma zostać zachowane.

**Nie naprawiamy w diagnozie.**

---

## DB-RES-002 — P1 SECURITY: Staff/Vehicle ↔ Location join tables mogą tworzyć cross-tenant assignment

### Stan

`staff_location_assignments(staff_profile_id,location_id)` i `vehicle_location_assignments(vehicle_id,location_id)` nie mają jawnego tenant key.

Jednocześnie `assigned_locations` z DB-IAM-002 opiera authorization na `StaffLocationAssignments`.

### Problem

Dwa proste FK nie udowadniają, że parent i location należą do tego samego OSK.

Błąd importu/bug backendu mógłby przypisać pracownikowi OSK A lokalizację OSK B, a następnie resolver RBAC potraktowałby to jako legalny assigned-location scope.

Vehicle-location cross-tenant relation analogicznie łamie zasady resource isolation i calendar filters.

### Wymagane rozstrzygnięcie

Potrzebny tenant-aware join shape i same-organization FK/invariant dla obu join tables.

**Nie naprawiamy w diagnozie.**

---

## DB-RES-003 — P1 SECURITY: Staff/Vehicle document i photo `FileAsset` nie mają zamkniętego same-tenant ownership contract

### Stan

Staff/Vehicle posiadają `photo_asset_id`, a dokumenty mogą posiadać `asset_id`. `FileAsset.organization_id` może być nullable dla platformowych/global assets.

### Problem

Samo FK do `file_assets.id` nie zabrania:
- przypięcia zdjęcia należącego do innego OSK,
- przypięcia dokumentu z innego OSK,
- przypadkowego użycia platformowego assetu jako prywatnego dokumentu pracownika/pojazdu.

Upload security mówi, że business entity może przypiąć asset dopiero po `ready`, ale physical resource contract nie definiuje jeszcze wymaganej zgodności tenant + purpose dla tych czterech ścieżek.

### Wymagane rozstrzygnięcie

Należy ustalić same-tenant/purpose-ready attach invariant dla:
- StaffProfile.photo,
- StaffDocument.asset,
- Vehicle.photo,
- VehicleDocument.asset.

**Nie naprawiamy w diagnozie.**

---

## DB-RES-004 — P1: current document validity projection jest niejednoznaczny

### Stan

UI ma dokładnie po jednym bieżącym polu daty dla każdego typu:

Staff:
- card/license,
- medical exam,
- psychological exam.

Vehicle:
- technical inspection,
- OC,
- AC.

Physical tables `staff_documents` i `vehicle_documents` pozwalają jednak tworzyć wiele rekordów tego samego `document_type` dla jednego parenta i nie definiują current/superseded semantics.

### Problem

Bez rozstrzygnięcia dwóch agentów mogą wdrożyć różne zachowania:
- update jednego current row,
- append history bez current marker,
- wybór najnowszego `created_at`,
- przypadkowe dwa równoległe current records.

To wpływa na ekran detalu, expired/attention projection i calendar important-date projection.

### Wymagane rozstrzygnięcie

Wybrać jedną physical semantics:
- jedna current row per `(parent,document_type)` + audit history,
- albo versioned/superseded history z partial unique current row.

Historyczne wymagania nie mogą być realizowane przez nieokreślone „najświeższy rekord wygrywa”.

**Nie naprawiamy w diagnozie.**

---

## DB-RES-005 — P1: identity uniqueness lifecycle Staff/Vehicle nie jest finalny

### Staff

`pesel_lookup_hash` ma tylko rekomendowaną/optional partial uniqueness. Nie jest rozstrzygnięte, czy jeden PESEL może tworzyć dwa trwałe StaffProfile w tym samym OSK zamiast restore istniejącego profilu.

### Vehicle

Blueprint wymienia unique registration number, ale jednocześnie pozostawia „finalną politykę reuse po archiwizacji” do decyzji. VIN uniqueness jest również optional.

### Problem

Migracja musi wiedzieć, czy uniqueness jest:
- across full durable history,
- only current/non-archived,
- albo nie jest constraintem.

W przeciwnym razie archive/restore może powodować niemożliwy restore albo duplikaty tożsamości zasobu.

### Wymagane rozstrzygnięcie

Zamknąć history-safe identity rules osobno dla:
- Staff PESEL gdy podany,
- Vehicle registration number,
- Vehicle VIN gdy podany.

**Nie naprawiamy w diagnozie.**

---

## DB-RES-006 — P1 SECURITY: archiwizacja StaffProfile nie ma zamkniętego skutku dla panel access

### Stan

Staff może mieć osobny aktywny `StaffMembershipLink -> OrganizationMembership`. Screen potwierdza archive/delete Staff i osobny panel-access context.

DB4_2 mówi, że tylko active OrganizationMembership może autoryzować.

### Problem

Jeżeli `StaffProfile.archived_at` zostanie ustawione bez zmiany linked membership, osoba usunięta z aktywnej kadry może nadal mieć aktywny dostęp do panelu.

Z drugiej strony automatyczny hard revoke membership przy archive utrudni bezpieczne restore i może naruszyć last-owner invariant.

### Wymagane rozstrzygnięcie

Potrzebna jawna, atomowa own-product policy dla:
- archive StaffProfile z aktywnym panel account,
- restore StaffProfile,
- Owner being archived,
- relacji do `suspended|revoked` membership,
- zachowania historycznego StaffMembershipLink.

Policy musi respektować last-owner guard z DB-IAM-004 i nie może usuwać membership history.

**Nie naprawiamy w diagnozie.**

---

# Nie są blockerami DB4_3 na tym etapie

- dokładny próg `expiring_soon` — projection/config, nie physical integrity blocker,
- exact future-event behavior po archive Location/Vehicle — będzie zamykane z Calendar, przy zachowaniu historycznych refs,
- exact course behavior po archive Location/Staff — będzie zamykane z Course/Training, bez utraty historii,
- exact validator polskiego numeru rejestracyjnego/VIN/telefonu — application validation, chyba że późniejszy gate wymaga DB check,
- source katalogu miejscowości — może pozostać adapter/dictionary decision, o ile `city_reference + snapshot` zachowuje dane,
- category assignment tenant FK — driving category jest globalnym dictionary, nie tenant resource,
- competitor archive/delete backend semantics — nieobserwowalne; own product stosuje bezpieczny lifecycle z equivalent capability.

---

# Wynik diagnozy

`DB4_3_STAFF_LOCATIONS_VEHICLES` ma obecnie **6 otwartych blockerów P1**:

1. `DB-RES-001` — StaffMembershipLink same-organization integrity,
2. `DB-RES-002` — Staff/Vehicle location assignment tenant integrity,
3. `DB-RES-003` — Staff/Vehicle FileAsset same-tenant/purpose attachment,
4. `DB-RES-004` — current document validity cardinality/history semantics,
5. `DB-RES-005` — Staff/Vehicle identity uniqueness lifecycle,
6. `DB-RES-006` — Staff archive vs panel-access membership lifecycle.

Nie rozpoczęto żadnej z tych napraw.

**Następny pojedynczy krok: tylko `DB-RES-001`.**
