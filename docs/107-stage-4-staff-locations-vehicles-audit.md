# 107. Stage 4 — Staff / Locations / Vehicles physical invariant audit

Data: 2026-09-06

**Status:** `DB4_3 IN PROGRESS / DB-RES-001..005 PASS / 1 P1 BLOCKER OPEN`

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

## Problem

Zweryfikowane ekrany pokazują po jednej bieżącej wartości ważności dla każdego rodzaju dokumentu, natomiast dotychczasowy physical blueprint dopuszczał wiele `staff_documents` / `vehicle_documents` tego samego typu bez definicji rekordu bieżącego.

Nie można było jednoznacznie odpowiedzieć, czy backend ma:
- nadpisywać rekord,
- wybierać `MAX(created_at)`,
- przechowywać historię i arbitralnie wybierać jedną wersję,
- czy blokować dwa równoległe current records.

To było niezgodne z wymaganiem zachowania historii oraz z jednoznaczną projekcją detail/expiry.

## Decyzja canonical

Wybrany został **versioned/superseded history model**.

Current row:

`superseded_at IS NULL`

Dla jednego parenta i `document_type` może istnieć **zero albo dokładnie jeden** bieżący rekord.

Staff:

`UNIQUE (organization_id, staff_profile_id, document_type) WHERE superseded_at IS NULL`

Vehicle:

`UNIQUE (organization_id, vehicle_id, document_type) WHERE superseded_at IS NULL`

Nie stosujemy zasady „najnowszy created_at wygrywa”.

## Pola wersji

Do `staff_documents` dochodzą semantycznie:
- `created_by_user_id nullable`,
- `superseded_at nullable`,
- `superseded_by_user_id nullable`,
- `supersession_reason nullable`.

Analogicznie dla `vehicle_documents`.

`valid_until` pozostaje nullable. W Staff nadal istnieje `document_number`; asset nadal podlega DB-RES-003.

## Edycja dokumentu

Zwykła edycja ważności/numeru/assetu **nie mutuje business fields starej wersji w miejscu**.

Transakcja replacement:
1. lock parent resource,
2. pobierz current row danego typu `FOR UPDATE`, jeśli istnieje,
3. zwaliduj tenant i nowy asset,
4. oznacz stary current jako superseded,
5. wstaw nową current version,
6. zapisz audit/outbox,
7. commit.

Partial unique index jest finalnym zabezpieczeniem przed dwoma current rows przy concurrency.

Jeżeli payload nie zmienia business state, nowa wersja nie jest wymagana.

## Clear semantics

Usunięcie dokumentu z bieżącej projekcji oznacza:
- supersede current row,
- brak replacement,
- zero current rows,
- historia pozostaje.

Jeżeli użytkownik usuwa tylko datę ważności, ale dokument jako taki pozostaje, powstaje nowa wersja z `valid_until = NULL`.

Hard-delete nie jest używany do zwykłej edycji ani czyszczenia.

## Immutability historii

Po supersede business fields historycznego dokumentu są immutable.

Na istniejącym current row dozwolona jest jedynie kontrolowana jednorazowa zmiana pól supersession. Canonical DB design wymaga triggera albo równoważnego constraint policy, aby późniejsza poprawka nie przepisała historii.

## Projection rules

Detail Staff/Vehicle czyta wyłącznie `superseded_at IS NULL`.

- brak current row → brak bieżącej wartości dokumentu,
- current row z `valid_until IS NULL` → dokument bez daty ważności,
- `expired` liczymy wyłącznie z bieżącego `valid_until`, względem lokalnej daty organizacji,
- próg `expiring_soon` pozostaje konfiguracją/projekcją, nie częścią DB-RES-004.

Dla późniejszego DB4_5 Calendar wejściem do important-date projection są wyłącznie current rows z `valid_until IS NOT NULL`. Superseded rows nie mogą emitować bieżących alertów terminowych.

## Relacja z DB-RES-003

Każda nowa wersja dokumentu z `asset_id` ponownie przechodzi same-tenant + purpose + ready attachment gate z DB-RES-003.

Supersede dokumentu nie kasuje starego FileAsset. Historyczna referencja pozostaje zgodnie z polityką retencji i security delivery assetu.

## Migration precheck

Przed utworzeniem partial unique indexes należy pogrupować istniejące dokumenty per parent/type i wykryć grupy z więcej niż jednym kandydatem na current.

**Nie wolno automatycznie wybrać `MAX(created_at)` ani `MAX(id)`.** Ambiguous history wymaga jawnego one-time mapping/remediation opartego na źródłowych danych albo audytowalnej decyzji migracyjnej.

## Quality gate DB-RES-004

Sprawdzono:
- current predicate jest jednoznaczny — **PASS**,
- zero-or-one current row per parent/type jest chronione partial unique — **PASS**,
- normalna edycja zachowuje poprzednią wersję — **PASS**,
- concurrency nie może pozostawić dwóch current rows — **PASS DESIGN**,
- clear nie hard-delete'uje historii — **PASS**,
- historyczne business fields są immutable — **PASS DESIGN**,
- detail/expiry czytają wyłącznie current row — **PASS**,
- superseded row nie emituje current important-date input — **PASS**,
- DB-RES-003 pozostaje obowiązujący dla każdego nowego attachmentu — **PASS**,
- migration precheck nie stosuje arbitralnego `latest wins` — **PASS**,
- DB-RES-005 i DB-RES-006 nie zostały rozwiązane w tym kroku — **PASS**,
- nie rozpoczęto DB4_4 ani migracji Laravel — **PASS**.

Nie znaleziono nowego P0/P1 wynikającego z decyzji DB-RES-004.

Machine source: `specs/database/staff-locations-vehicles.yml`.

**Gate DB-RES-004: PASS.**

---

# DB-RES-005 — PASS: Staff/Vehicle identity uniqueness lifecycle

## Problem

Physical blueprint nie rozstrzygał, czy identyfikatory zasobów mają pozostawać unikalne po archiwizacji, czy archive ma zwalniać ich wartość. To groziło dwoma przeciwnymi błędami:
- powstaniem drugiego trwałego profilu tej samej osoby/pojazdu,
- albo zablokowaniem legalnego późniejszego użycia numeru rejestracyjnego.

Osobno trzeba było rozstrzygnąć PESEL pracownika, VIN pojazdu i numer rejestracyjny.

## Staff PESEL

PESEL pozostaje opcjonalny, szyfrowany w `pesel_ciphertext`, a equality/uniqueness używa `pesel_lookup_hash` zgodnego z keyed-HMAC policy.

Canonical constraint:

`UNIQUE (organization_id, pesel_lookup_hash) WHERE pesel_lookup_hash IS NOT NULL`

Constraint obejmuje **także zarchiwizowane StaffProfile**.

Skutki:
- archive pracownika nie zwalnia PESEL,
- próba utworzenia drugiego StaffProfile z tym samym PESEL w tym samym OSK jest konfliktem również wtedy, gdy pierwszy profil jest archived,
- właściwą ścieżką jest restore istniejącego profilu albo jawna korekta danych,
- ten sam PESEL może istnieć w innym OSK, bo StaffProfile jest tenant-owned,
- korekta PESEL na wartość zajętą przez inny profil w tym samym OSK jest odrzucana,
- jawna, audytowana korekta błędnego PESEL na tym samym profilu może zwolnić poprzednią błędną wartość; nie tworzymy osobnego dożywotniego rejestru wszystkich historycznych pomyłek PESEL.

## Vehicle VIN

VIN jest nullable, ale gdy istnieje, identyfikuje fizyczny pojazd na tyle silnie, że archive nie może zwolnić go dla drugiego Vehicle row.

Canonical constraint:

`UNIQUE (organization_id, vin_normalized) WHERE vin_normalized IS NOT NULL`

Constraint obejmuje także archived vehicles.

Skutki:
- ten sam VIN w drugim trwałym Vehicle row tego samego OSK jest zabroniony,
- jeżeli pojazd został zarchiwizowany, należy przywrócić jego rekord zamiast tworzyć drugi z tym samym VIN,
- ten sam VIN może wystąpić w innym OSK,
- jawna korekta błędnego VIN jest dozwolona i audytowana, ale nie może wejść na VIN zajęty przez inny Vehicle tego OSK.

## Vehicle registration number

Numer rejestracyjny ma inną semantykę niż VIN: jest bieżącym identyfikatorem operacyjnym i może być legalnie użyty później dla innego aktywnego pojazdu po wyjściu poprzedniego pojazdu z floty.

Dlatego canonical constraint jest **current-only**:

`UNIQUE (organization_id, registration_number_normalized) WHERE archived_at IS NULL`

Skutki:
- dwa niearchiwalne/aktywne Vehicle rows w tym samym OSK nie mogą mieć tego samego numeru,
- archive zwalnia numer dla przyszłego aktywnego pojazdu,
- archived row nadal zachowuje historyczny numer,
- wiele historycznych archived rows może mieć ten sam numer,
- restore starego Vehicle wymaga, aby numer był w tej chwili wolny wśród niearchiwalnych pojazdów,
- jeżeli numer został przejęty przez inny aktywny pojazd, restore zwraca conflict; system nie robi auto-swap, auto-archive ani silent renumber,
- po rozwiązaniu konfliktu przez zmianę numeru albo archive aktualnego posiadacza restore może się udać.

## Tenant scope i normalizacja

Wszystkie trzy reguły są per `organization_id`, nie globalne dla całej platformy.

Uniqueness działa na kanonicznych przechowywanych wartościach:
- PESEL → `pesel_lookup_hash`,
- VIN → `vin_normalized`,
- rejestracja → `registration_number_normalized`.

Dokładne regexy/format-validation nie są częścią DB-RES-005. Jeżeli przyszła zmiana normalizacji mogłaby zlać dwie istniejące wartości, wymaga osobnego migration precheck przed wdrożeniem.

## Archive / restore

- Staff archive zachowuje PESEL hash i nadal uczestniczy w uniqueness,
- Vehicle archive zachowuje i rezerwuje VIN,
- Vehicle archive zwalnia wyłącznie registration number dla current fleet,
- hard-delete nie jest normalną metodą „zwolnienia” PESEL/VIN/rejestracji,
- wpływ Staff archive na panel access pozostaje wyłącznie zakresem DB-RES-006.

## Migration precheck

Przed utworzeniem constraintów należy wykryć:
- duplikaty nie-null `pesel_lookup_hash` w jednym OSK, także w archived StaffProfile,
- duplikaty nie-null `vin_normalized` w jednym OSK, także w archived Vehicle,
- duplikaty `registration_number_normalized` wśród `archived_at IS NULL`,
- kolizje, które ujawnią się po canonical normalization.

Dozwolone są:
- te same wartości w różnych OSK,
- historyczny reuse numeru rejestracyjnego w archived rows, jeśli najwyżej jeden bieżący row go posiada.

Migracja **nie może** po cichu usuwać, scalać, archiwizować, zmieniać PESEL/VIN ani przenumerowywać pojazdu tylko po to, aby constraint przeszedł. Konflikt wymaga jawnej, audytowalnej remediacji.

## Concurrency

DB unique index jest finalną race boundary. Create/edit/restore może wykonać precheck dla UX, ale nie może zakładać, że precheck wystarcza.

Szczególnie race:

`restore archived vehicle ↔ create/rename another vehicle to same registration`

musi dać jednego zwycięzcę i domain conflict po drugiej stronie.

## Quality gate DB-RES-005

Sprawdzono:
- PESEL jest unikalny per OSK także przez archive/restore — **PASS**,
- PESEL nie jest platform-global unique — **PASS**,
- VIN jest unikalny per OSK także przez archive/restore — **PASS**,
- VIN nie jest zwalniany przez archive — **PASS**,
- registration number jest unikalny tylko w current/nonarchived fleet — **PASS**,
- historyczny registration reuse po archive jest dozwolony bez utraty historii — **PASS**,
- restore ma jednoznaczny conflict, gdy registration jest zajęty — **PASS**,
- jawna identity correction nie omija uniqueness i jest audytowana — **PASS DESIGN**,
- migracja nie robi silent identity repair — **PASS**,
- concurrency opiera finalne rozstrzygnięcie o DB constraint — **PASS DESIGN**,
- DB-RES-006 nie został rozwiązany w tym kroku — **PASS**,
- DB4_4 i migracje Laravel nie zostały rozpoczęte — **PASS**.

Nie znaleziono nowego P0/P1 wynikającego z decyzji DB-RES-005.

Machine source: `specs/database/staff-locations-vehicles.yml`.

**Gate DB-RES-005: PASS.**

---

# DB-RES-006 — OPEN P1 SECURITY: archiwizacja StaffProfile vs panel access

Jeżeli StaffProfile zostanie zarchiwizowany bez zmiany aktywnego StaffMembershipLink/OrganizationMembership, były pracownik może zachować panel access. Bezwarunkowy revoke membership może natomiast zepsuć restore i last-owner guard.

Potrzebna jawna atomowa policy respektująca DB-IAM-004/005. **To jest następny i jedyny blocker do naprawy.**

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

# Wynik po DB4_3_STEP_6

- `DB-RES-001` — **PASS**,
- `DB-RES-002` — **PASS**,
- `DB-RES-003` — **PASS**,
- `DB-RES-004` — **PASS**,
- `DB-RES-005` — **PASS**,
- `DB-RES-006` — **OPEN P1**.

DB4_3 jako całość nadal ma **FAIL / IN_PROGRESS** z 1 blockerem P1. DB4_4 pozostaje zablokowany.

Aggregate `core-schema.yml` i `docs/87` zostaną zsynchronizowane dopiero przed finalnym DB4_3 PASS, po zamknięciu ostatniego blockera bounded-contextu.

**Następny pojedynczy krok: tylko `DB-RES-006` — Staff archive vs panel-access membership lifecycle.**
