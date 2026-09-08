# 87. Physical database schema — PostgreSQL core v1

Data: 2026-09-07

**Status:** `IMPLEMENTATION_BLUEPRINT / DB4_7_INTERNAL_EXAMS_AGGREGATE_SYNC_PASS`

> To nie są jeszcze migracje Laravel. To fizyczny blueprint tabel, indeksów, constraintów i najważniejszych transakcji zgodny z canonical domain model. Machine-readable odpowiednik: `specs/database/core-schema.yml`. Przy konflikcie machine spec + późniejszy ADR wygrywa. Reverse-engineered scope chronią `docs/96-reverse-engineering-preservation-contract.md` i `specs/reverse-engineering-manifest.yml`. Cross-layer kompletność kontroluje `specs/traceability/core-v1.yml`.

DB4_4 Students / Courses / Training Ledger został zsynchronizowany z `specs/database/students-courses-training.yml` po zamknięciu DB-TRN-001..008. Sekcje 11–14 oraz odpowiadające im constrainty, transakcje, kolejność migracji i testy poniżej są agregatową projekcją tych rozstrzygnięć.

DB4_5 Calendar został zsynchronizowany z `specs/database/calendar.yml` po zamknięciu DB-CAL-001..007. Sekcja 15, rozszerzenie formalnego `TrainingSession` w sekcji 13 oraz odpowiadające constrainty, transakcje, migration order i invariant tests są agregatową projekcją zamkniętego kontraktu Calendar.

DB4_6 Licenses / Learning Access został zsynchronizowany z `specs/database/licenses-learning-access.yml` po zamknięciu DB-LIC-001..007. Rozszerzenia Identity i Student Learning Access, sekcja 17 oraz odpowiadające constrainty, transakcje, security boundary, migration order i invariant tests są agregatową projekcją tego kontraktu.

DB4_7 Internal Exams został zsynchronizowany z `specs/database/internal-exams.yml` po zamknięciu DB-EXAM-001..008. Sekcja 18 oraz odpowiadające jej same-tenant boundaries, inventory ledger, Attempt/Access lifecycle, token i Station security, immutable exam evidence, deterministyczna management projection, lock orders, migration safety i invariant tests są agregatową projekcją zamkniętego kontraktu.

---

# 1. Konwencje DB

- syntetyczny primary key i odpowiadające mu foreign keys: natywny PostgreSQL `uuid`,
- identyfikatory domenowe generowane przez naszą aplikację: **UUIDv7**, generowany przed `INSERT`; nie wolno po cichu używać UUIDv4,
- nie wymagamy DB-default do generowania UUIDv7,
- zewnętrzne identyfikatory providerów/importów pozostają osobnymi polami i nie są używane jako nasze primary keys,
- czyste tabele join/dictionary mogą zachować jawnie zaprojektowany klucz naturalny/composite,
- wszystkie timestampy: `timestamptz`,
- storage czasu: UTC,
- timezone prezentacji: IANA timezone organizacji, domyślnie `Europe/Warsaw`,
- money: `bigint amount_minor` + `char(3) currency`, nigdy float,
- business state: `varchar` + application enum/check; unikamy PostgreSQL enum dla często ewoluujących lifecycle,
- JSONB: immutable snapshot, redacted safe payload albo niekrytyczne metadata — nie zamiennik modelu relacyjnego,
- mutable row: `created_at`, `updated_at`,
- immutable ledger/event: brak zwykłego `updated_at`,
- tenant-owned table ma `organization_id` tam, gdzie upraszcza autoryzację i indeksy,
- constraint dla stanu bieżącego nie może niszczyć historii; używamy partial unique tam, gdzie rekord może być revoked/released i ponownie użyty.

Decyzja UUIDv7 dotyczy naszego synthetic domain ID. Publiczne API nadal przekazuje UUID jako string.

---

# 2. Dane wrażliwe

## 2.1 PESEL

Preferowany wzorzec:
- `pesel_ciphertext text null`,
- `pesel_lookup_hash char(64) null`,
- lookup hash = HMAC-SHA-256 lub równoważny keyed hash,
- zwykły globalny SHA-256 do lookupu jest zabroniony.

## 2.2 PKK

Analogicznie:
- `pkk_number_ciphertext text`,
- `pkk_lookup_hash char(64)`,
- dla bieżącej formalnej identity PKK oba pola są wymagane,
- plaintext PKK istnieje tylko na autoryzowanym command boundary, a po zapisie jest odrzucany.

## 2.3 Credentials/provider secrets

- sekrety integracyjne przez secret manager albo szyfrowane reference,
- canonical local password hash pozostaje wyłącznie w `users.password_hash`,
- plaintext hasła nigdy nie jest persistowany ani odtwarzalny z bazy,
- secret-bearing credential PDF nie jest zapisywany jako `FileAsset` ani do object storage; jest renderowany in-memory i streamowany jednokrotnie,
- QR nie zawiera hasła ani reset secretu,
- audit/log/outbox/idempotency snapshot nie zawiera hasła, password hash, tokenu ani pełnego secretu.

---

# 3. Identity / organization

## `organizations`

- `id uuid PK`
- `name varchar(255) not null`
- `nip varchar(16) null`
- `phone varchar(40) null`
- `timezone varchar(64) not null default 'Europe/Warsaw'`
- `status varchar(32) not null`
- `created_at timestamptz`
- `updated_at timestamptz`

`name` jest canonical company name. `nip` może pozostać polem domenowym, ale nie jest częścią potwierdzonego formularza Ustawień.

## `organization_settings`

One-to-one z `organizations`.

- `organization_id uuid PK/FK`
- `default_language_code varchar(16) null`
- `preferences jsonb null` — tylko niekrytyczne UI/preferences,
- `version integer not null default 1 check (version >= 1)`,
- `created_at`
- `updated_at`

`version` jest aggregate version dla `ETag/If-Match` i optimistic concurrency ekranu Ustawień oraz PKK configuration gate. Udany atomowy zapis zwiększa wersję dokładnie raz.

Krytyczne ustawienia domenowe nie trafiają do `preferences`.

## `organization_contact_addresses`

Canonical structured company/contact address z ekranu Ustawień OSK. To **nie jest** zasób szkoleniowy i nie może być zapisywany jako rekord w `locations`.

- `organization_id uuid PK/FK organizations`
- `street varchar(255) not null`
- `house_number varchar(32) not null`
- `unit_number varchar(32) null`
- `postal_code varchar(20) not null`
- `city_name varchar(160) not null`
- `city_reference varchar(128) null`
- `voivodeship_name varchar(160) null`
- `country_code char(2) not null default 'PL'`
- `created_at timestamptz`
- `updated_at timestamptz`

`organization_contact_addresses` i `locations` są odrębnymi konceptami.

## `users`

Globalna tożsamość auth.

- `id uuid PK`
- `first_name varchar(120) null` podczas technicznego/pre-onboarding stanu; wymagane dla human user po onboardingu,
- `last_name varchar(120) null` analogicznie,
- `password_hash varchar(255) null`
- `status varchar(32) not null`
- `last_login_at timestamptz null`
- `created_at`
- `updated_at`

Imię i nazwisko są globalnymi polami tożsamości użytkownika. E-mail/login nie jest kanoniczną kolumną w `users`. `users.password_hash` jest jedynym canonical ownerem lokalnego hash hasła; DB4_6 nie tworzy tenantowej kopii hasła.

## `user_password_management`

One-to-one globalny security authority/credential epoch dla lokalnego hasła Usera:

- `user_id uuid PK/FK users`
- `management_mode varchar(32) not null` — `unclassified|self_service|organization_managed`
- `managing_organization_id uuid null FK organizations`
- `credential_version bigint not null default 0 check (credential_version >= 0)`
- `password_changed_at timestamptz null`
- `created_at`
- `updated_at`.

State matrix:
- `organization_managed` wymaga `managing_organization_id IS NOT NULL`,
- `self_service|unclassified` wymagają `managing_organization_id IS NULL`,
- `users.password_hash IS NOT NULL` wymaga `credential_version >= 1`.

OSK może wykonać reset hasła tylko, gdy target User ma `organization_managed` i `managing_organization_id` równe temu OSK oraz actor ma wymagane permission/scope. Taki principal musi być ekskluzywnym learner principalem: brak OrganizationMembership, brak LearningAccount w innym OSK i brak current SocialAuthAccount. Przejście authority jest osobnym jawnie audytowanym identity-security commandem, a nie side-effectem zwykłego resetu.

Każda udana materialna mutacja lokalnego hasła zwiększa dokładnie jeden globalny `credential_version`. Plaintext istnieje wyłącznie w pamięci procesu w granicy bieżącego commandu.

## `auth_login_identifiers`

- `id uuid PK`
- `user_id uuid FK users`
- `identifier_type varchar(32)` — `email|username`
- `identifier_normalized varchar(320) not null`
- `is_primary_for_type boolean not null default false`
- `verified_at timestamptz null`
- `created_at`
- `revoked_at timestamptz null`

Partial unique:
- `(identifier_normalized)` where `revoked_at is null`,
- `(user_id, identifier_type)` where `revoked_at is null AND is_primary_for_type=true`.

Candidate key `UNIQUE(id,user_id)` jest targetem same-global-user composite FK z LearningAccount.

## `auth_social_accounts`

- `id uuid PK`
- `user_id uuid FK`
- `provider varchar(64)`
- `provider_subject varchar(255)`
- `created_at`
- `revoked_at timestamptz null`

Unique `(provider, provider_subject)` dla aktywnej identity.

## `organization_memberships`

To trwały tenant authorization aggregate root. Jeden row na `(organization_id,user_id)`; nie hard-delete.

- `id uuid PK`
- `organization_id uuid FK organizations`
- `user_id uuid FK users` — immutable po utworzeniu membership,
- `status varchar(32) not null` — `active|suspended|revoked`,
- `is_owner boolean not null default false`,
- `role_template_code varchar(64) null` — provenance/UI only,
- `role_template_catalog_version varchar(64) null` — provenance/UI only,
- `version bigint not null default 1 check (version >= 1)`,
- `authorization_version bigint not null default 1 check (authorization_version >= 1)`,
- `created_at`,
- `updated_at`.

Unique/candidate keys:
- `UNIQUE(organization_id,user_id)`,
- `UNIQUE(id,user_id)` — target composite FK sesji,
- `UNIQUE(organization_id,id)` — target composite FK `staff_membership_links` dla same-tenant integrity.

Stare `data_scope varchar(64)` jest **usunięte z canonical physical model**. Scope jest per permission.

`version` = optimistic-concurrency version całego membership authorization aggregate.

`authorization_version` = security epoch. Wzrasta przy zmianie permission/scope/Owner lub statusu wpływającego na authorization eligibility.

Status:
- `active` — może autoryzować po permission + scope,
- `suspended` — nie autoryzuje, ale zachowuje current permission/scope config do jawnego wznowienia,
- `revoked` — nie autoryzuje, `is_owner=false`, current permission rows są `granted=false`, scope rows są usunięte.

Reactivation:
- `suspended -> active` zachowuje config, ale nie wiąże starych sesji automatycznie,
- `revoked -> active` wymaga świeżego template/permission+scope provisioning; stary Owner i stare prawa nie wracają automatycznie.

## `permissions`

- `code varchar(128) PK`
- `description varchar(255)`.

Katalog synchronizowany ze `specs/security/permissions.yml`.

## `membership_permissions`

Current runtime source of truth dla capability:
- `membership_id uuid FK organization_memberships`
- `permission_code varchar(128) FK permissions`
- `granted boolean not null`
- `created_at`.

Unique `(membership_id,permission_code)`.

Brak row = DENY. `role_template_code` nie jest konsultowany runtime. Permission rows nie mają niezależnego version — concurrency root to `organization_memberships.version`.

## `data_scopes`

Katalog:
- `code varchar(64) PK`
- `description varchar(255)`.

Canonical codes:
- `organization`,
- `own`,
- `assigned_students`,
- `assigned_locations`.

## `permission_scope_options`

Whitelist legalnych permission/scope + resolver:
- `permission_code varchar(128) FK permissions`,
- `scope_code varchar(64) FK data_scopes`,
- `resolver_code varchar(96) not null`.

PK `(permission_code,scope_code)`.

## `membership_permission_scopes`

Current scope predicates dla granted permission:
- `membership_id uuid`,
- `permission_code varchar(128)`,
- `scope_code varchar(64)`,
- `created_at timestamptz`.

PK `(membership_id,permission_code,scope_code)`.

Composite FK:
- `(membership_id,permission_code)` -> `membership_permissions`,
- `(permission_code,scope_code)` -> `permission_scope_options`.

Reguły:
- granted permission wymaga co najmniej jednego legalnego scope,
- denied permission ma zero scope rows,
- brak scope przy `granted=true` = fail-closed DENY,
- wiele scope rows dla jednego permission = logiczne OR po wcześniejszej tenant validation.

## Owner governance

`is_owner` jest governance markerem, nie skrótem autoryzacyjnym.

Owner musi mieć jawnie materializowany protected baseline z `organization` scope:
- `organization.view`,
- `organization.members.manage`,
- `staff.permissions.manage`,
- `sessions.manage.organization`.

Zmiana Ownera jest serializowana per organizacja. Post-transaction active Owner count musi być `>= 1`. Transfer Ownera = promotion successor + demotion predecessor w jednej transakcji.

Normal admin flow blokuje self-promotion, self-grant i self-scope-broadening. Administrator nie może delegować permission/scope ponad własny effective grant ceiling. Elevated grant/broadening wymaga aktywnego Ownera.

## `auth_sessions`

- `id uuid PK`
- `user_id uuid FK users`
- `organization_membership_id uuid null`
- `token_or_framework_session_hash varchar(255) unique not null`
- `created_at timestamptz`
- `last_seen_at timestamptz null`
- `revoked_at timestamptz null`
- `revoke_reason varchar(255) null`
- `ip_hash varchar(128) null`
- `user_agent varchar(512) null`.

Same-user tenant integrity:

`FOREIGN KEY (organization_membership_id,user_id)`
`REFERENCES organization_memberships(id,user_id)`
`MATCH SIMPLE ON UPDATE RESTRICT ON DELETE RESTRICT`.

Gdy `organization_membership_id IS NULL`, sesja może być global/pre-tenant. Tenant-owned request bez aktywnego membership context = DENY.

Selected organization wynika z membership, nigdy z client-supplied `organization_id` jako authority.

Przed dodaniem composite FK migracja skanuje istniejące session rows i nie naprawia cross-user mismatch automatycznie przez przepięcie do innej osoby.

## Membership mutation / session effects

Każdy admin mutation przyjmuje expected membership version, lockuje target row i po locku porównuje version. Stale version -> conflict bez partial write.

Permission/scope mutation jest atomowa, stosuje self-escalation/grant-ceiling/Owner rules i zwiększa `version` raz oraz `authorization_version` raz, jeżeli effective authorization się zmieniła. Audit + outbox powstają w tej samej transakcji.

Przy `active -> suspended` lub `active|suspended -> revoked` wszystkie `auth_sessions.organization_membership_id` wskazujące membership są ustawiane na `NULL` w tej samej transakcji. Globalna session identity może pozostać zalogowana, więc inne OSK tego samego Usera nie są automatycznie odbierane.

Permission revoke przy nadal `active` membership nie wymaga logoutu; `authorization_version` unieważnia stare cache/elevation proof najpóźniej przed następną biznesową autoryzacją.

## `account_closure_requests`

- `id uuid PK`
- `user_id uuid FK users`
- `organization_id uuid null FK organizations`
- `requested_at timestamptz`
- `reason text null`
- `status varchar(32)` — `pending|approved|rejected|completed|cancelled`
- `resolved_at timestamptz null`
- `resolved_by_user_id uuid null`
- `resolution_note text null`
- `request_id varchar(64)`.

Partial unique:
- `(user_id,organization_id)` where `status='pending' AND organization_id IS NOT NULL`,
- `(user_id)` where `status='pending' AND organization_id IS NULL`.

---

# 4. Legal documents / acceptance

## `legal_documents`

Immutable versions.

- `id uuid PK`
- `document_type varchar(64)`
- `version varchar(64)`
- `content_hash char(64)`
- `storage_asset_id uuid null FK file_assets`
- `published_at timestamptz`
- `effective_from timestamptz null`
- `created_at`

Unique `(document_type,version)`.

## `terms_acceptances`

Append-only.

- `id uuid PK`
- `organization_id uuid FK`
- `user_id uuid FK`
- `legal_document_id uuid FK`
- `accepted_at timestamptz`
- `ip_hash varchar(128) null`
- `user_agent varchar(512) null`
- `request_id varchar(64)`

Unique `(organization_id,user_id,legal_document_id)`.

---

# 5. File assets

## `file_assets`

- `id uuid PK`
- `organization_id uuid null FK`
- `storage_disk varchar(64)`
- `storage_key varchar(512) not null`
- `original_filename varchar(255) null`
- `mime_type_declared varchar(128) null`
- `mime_type_detected varchar(128) null`
- `size_bytes bigint not null`
- `sha256 char(64) null`
- `purpose varchar(64)`
- `status varchar(32)` — `pending|uploaded|scanning|ready|rejected|deleted`
- `created_by_user_id uuid null`
- `created_at`
- `ready_at timestamptz null`
- `deleted_at timestamptz null`

Constraints/candidate keys:
- unique `storage_key`,
- `UNIQUE(organization_id,id)` jako target tenant-aware composite FK dla prywatnych Staff/Vehicle attachments i non-secret learning-access document references.

Dla Staff/Vehicle attachment:
- prywatny asset musi należeć do tego samego `organization_id`,
- platformowy asset z `organization_id IS NULL` nie może być przypięty jako prywatne zdjęcie/dokument,
- purpose jest związany ze ścieżką attachmentu (`staff_photo`, `staff_document`, `vehicle_photo`, `vehicle_document`),
- business attachment może commitować wyłącznie dla `status='ready'`,
- reusable DB trigger/constraint trigger blokuje asset `FOR SHARE` i jest finalną granicą purpose + ready; Laravel robi wcześniejszy precheck dla UX,
- `purpose` po zakończeniu uploadu nie jest przepisywany,
- późniejszy security transition assetu do non-ready nie usuwa historycznej referencji, ale download musi ponownie sprawdzić bieżący stan assetu,
- replacement attachmentu nie kasuje automatycznie poprzedniego FileAsset.

Credential PDF zawierający świeże hasło jest wyjątkiem od zwykłego asset pipeline: **nie wolno go tworzyć jako FileAsset**. Jest renderowany i streamowany tylko w ramach bieżącego reset/export commandu.

---

# 6. Idempotency storage

## `idempotency_records`

- `id uuid PK`
- `organization_id uuid null`
- `operation_key varchar(128)`
- `idempotency_key uuid`
- `request_hash char(64)`
- `status varchar(32)` — `processing|completed|failed`
- `result_resource_type varchar(128) null`
- `result_resource_id varchar(128) null`
- `response_status smallint null`
- `safe_response_snapshot jsonb null`
- `created_at`
- `completed_at timestamptz null`
- `expires_at timestamptz null`

Partial unique:
- tenant `(organization_id,operation_key,idempotency_key)` where `organization_id IS NOT NULL`,
- global `(operation_key,idempotency_key)` where `organization_id IS NULL`.

Same key + different request hash w tym samym scope = conflict. One-time plaintext password/token ani secret-bearing PDF bytes nie trafiają do snapshotu. Retry secret-bearing reset/export nie może ponownie mutować hasła ani odtworzyć starego plaintextu.

---

# 7. Dictionaries / capabilities

## `languages`

- `code varchar(16) PK`
- `label_key varchar(128)`
- `active boolean`
- `valid_from date null`
- `valid_to date null`.

Globalny słownik `languages` nie jest dowodem wsparcia języka przez konkretny produkt licencyjny. Product support ma własną wersjonowaną capability history w sekcji 17.

## `driving_categories`

- `id uuid PK`
- `code varchar(32) unique not null`
- `label varchar(64)`
- `active boolean`
- `valid_from date null`
- `valid_to date null`
- `metadata jsonb null`.

Zaobserwowany słownik kursów: `A, B, C, D, T, A1, B1, C1, D1, AM, A2, B+E, C1+E, C+E, D1+E, D+E, PT`. Legal mapping `PT` wymaga końcowej re-weryfikacji przed produkcją.

## `location_types`

- `code varchar(64) PK`
- `label_key varchar(128)`
- `active boolean`.

Start: `branch`, `lecture_room`, `maneuvering_area`.

## `staff_types`

- `code varchar(64) PK`
- `label_key varchar(128)`
- `active boolean`.

`staff_type != permission`.

## `internal_exam_capabilities`

- `id uuid PK`
- `driving_category_id uuid FK`
- `exam_part varchar(16)`
- `active boolean`
- `valid_from date null`
- `valid_to date null`
- `metadata jsonb null`.

## `internal_exam_capability_languages`

- `internal_exam_capability_id uuid FK`
- `language_code varchar(16) FK languages`

Unique `(internal_exam_capability_id,language_code)`.

---

# 8. Staff

## `staff_profiles`

- `id uuid PK`
- `organization_id uuid FK`
- `first_name varchar(120)`
- `last_name varchar(120)`
- `email_normalized varchar(320)`
- `phone varchar(40) null`
- `pesel_ciphertext text null`
- `pesel_lookup_hash char(64) null`
- `authorization_number varchar(128) null`
- `photo_asset_id uuid null`
- `archived_at timestamptz null`
- `archived_by_user_id uuid null`
- `created_at`
- `updated_at`.

Candidate/unique/index rules:
- `UNIQUE(organization_id,id)` jako target composite tenant FK,
- `(organization_id,archived_at)`,
- `(organization_id,last_name,first_name)`,
- `UNIQUE(organization_id,pesel_lookup_hash) WHERE pesel_lookup_hash IS NOT NULL` — obejmuje także archived StaffProfile; archive nie zwalnia PESEL.

Photo FK:

`FOREIGN KEY (organization_id,photo_asset_id) REFERENCES file_assets(organization_id,id) MATCH SIMPLE ON UPDATE RESTRICT ON DELETE RESTRICT`.

Przy non-null photo dodatkowo obowiązuje DB attachment trigger: purpose `staff_photo`, status `ready`.

## `staff_type_assignments`

- `staff_profile_id uuid FK`
- `staff_type_code varchar(64) FK`

Unique `(staff_profile_id,staff_type_code)`.

## `staff_category_assignments`

- `staff_profile_id uuid FK`
- `driving_category_id uuid FK`

Unique `(staff_profile_id,driving_category_id)`.

`driving_categories` jest globalnym słownikiem, więc nie dokładamy sztucznego tenant key po stronie kategorii.

## `staff_location_assignments`

Tenant-aware join:
- `organization_id uuid FK organizations`
- `staff_profile_id uuid`
- `location_id uuid`

`PRIMARY KEY (organization_id,staff_profile_id,location_id)`.

Composite FK:
- `(organization_id,staff_profile_id) -> staff_profiles(organization_id,id)`,
- `(organization_id,location_id) -> locations(organization_id,id)`,
- `ON UPDATE RESTRICT / ON DELETE RESTRICT`.

Cross-tenant Staff↔Location nie może przejść constraintów i nie może rozszerzyć `assigned_locations` RBAC.

## `staff_membership_links`

Historyczne powiązanie profilu pracownika z membership w konkretnym OSK.

- `id uuid PK`
- `organization_id uuid FK`
- `staff_profile_id uuid`
- `organization_membership_id uuid`
- `linked_at timestamptz`
- `linked_by_user_id uuid null`
- `unlinked_at timestamptz null`
- `unlinked_by_user_id uuid null`.

Composite same-tenant FK:
- `(organization_id,staff_profile_id) -> staff_profiles(organization_id,id)`,
- `(organization_id,organization_membership_id) -> organization_memberships(organization_id,id)`,
- `ON UPDATE RESTRICT / ON DELETE RESTRICT`.

Partial unique:
- `(staff_profile_id)` where `unlinked_at is null`,
- `(organization_membership_id)` where `unlinked_at is null`.

DB guard zabrania aktywnego linku do `StaffProfile.archived_at IS NOT NULL`. Archive vs link creation serializujemy na StaffProfile row.

## Staff archive / panel access lifecycle

Archive StaffProfile zawsze kończy aktywny `StaffMembershipLink` i zachowuje historyczny link row.

Dla linked non-owner membership:
- `active -> suspended` zgodnie z DB-IAM-005,
- permission/scope config pozostaje do jawnego wznowienia,
- bound session tenant contexts są czyszczone w tej samej transakcji,
- membership już `suspended` pozostaje suspended,
- membership `revoked` pozostaje revoked i nie odzyskuje permission snapshotu.

Dla linked Owner membership:
- Staff archive kończy StaffMembershipLink,
- **nie zmienia `is_owner` i nie suspenduje/revoke'uje Ownera jako ukrytego side-effectu**,
- odebranie Ownerowi panel access wymaga jawnego DB-IAM-004/005 governance flow,
- dzięki temu archive profilu kadrowego jedynego Ownera nie tworzy stanu `0 active owners`.

Po archive brak aktywnego StaffMembershipLink oznacza empty/deny dla staff-derived `own`, `assigned_locations`, `assigned_students`. Owner może nadal autoryzować wyłącznie z własnych jawnych membership permissions/scopes.

Restore StaffProfile domyślnie przywraca tylko rekord kadrowy. Nie tworzy linku, nie aktywuje membership i nie rebinduje starych sesji. Jawny restore panel access tworzy **nowy** StaffMembershipLink; starego historycznego row nie otwieramy ponownie. `suspended -> active` używa DB-IAM-005, a revoked membership nie jest automatycznie reaktywowany.

## `staff_documents`

Versioned/superseded history model:
- `id uuid PK`
- `organization_id uuid FK`
- `staff_profile_id uuid FK`
- `document_type varchar(64)`
- `valid_until date null`
- `document_number varchar(128) null`
- `asset_id uuid null`
- `created_at timestamptz`
- `created_by_user_id uuid null`
- `superseded_at timestamptz null`
- `superseded_by_user_id uuid null`
- `supersession_reason text null`.

Startowe typy: `card_or_authorization`, `medical_exam`, `psychological_exam`.

Current predicate: `superseded_at IS NULL`.

Partial unique:

`UNIQUE(organization_id,staff_profile_id,document_type) WHERE superseded_at IS NULL`.

Normalna edycja nie nadpisuje business fields starej wersji. Parent jest serializowany, current row superseded, a następnie powstaje nowa wersja. Clear = supersede bez replacement; zero current rows. Historyczne business fields są immutable. Nie używamy `MAX(created_at)` jako current resolvera.

Asset FK:

`FOREIGN KEY (organization_id,asset_id) REFERENCES file_assets(organization_id,id) MATCH SIMPLE ON UPDATE RESTRICT ON DELETE RESTRICT`, plus purpose `staff_document` i `status='ready'` przy attachment commit.

---

# 9. Locations

## `locations`

- `id uuid PK`
- `organization_id uuid FK`
- `type_code varchar(64) FK location_types`
- `name varchar(255) not null`
- `street_and_number varchar(255) not null`
- `postal_code varchar(20) not null`
- `city_reference varchar(128) null`
- `city_name varchar(160) not null`
- `voivodeship_name varchar(160) null`
- `archived_at timestamptz null`
- `archived_by_user_id uuid null`
- `created_at`
- `updated_at`.

Candidate key `UNIQUE(organization_id,id)` jest wymagany dla tenant-aware Staff/Vehicle location assignments.

---

# 10. Vehicles

## `vehicles`

- `id uuid PK`
- `organization_id uuid FK`
- `registration_number_normalized varchar(32) not null`
- `side_number varchar(64) null`
- `make varchar(120) not null`
- `model varchar(120) not null`
- `production_year smallint null`
- `engine_capacity_cm3 integer null`
- `vin_normalized varchar(32) null`
- `photo_asset_id uuid null`
- `archived_at timestamptz null`
- `archived_by_user_id uuid null`
- `created_at`
- `updated_at`.

Candidate/identity constraints:
- `UNIQUE(organization_id,id)` jako composite tenant FK target,
- `UNIQUE(organization_id,vin_normalized) WHERE vin_normalized IS NOT NULL` — obejmuje także archived rows; archive nie zwalnia VIN,
- `UNIQUE(organization_id,registration_number_normalized) WHERE archived_at IS NULL` — current fleet only.

Numer rejestracyjny może zostać legalnie użyty przez inny bieżący Vehicle po archive poprzedniego; archived history zachowuje dawną wartość. Restore starego Vehicle wymaga wolnego numeru wśród nonarchived rows; konflikt nie powoduje auto-swap/auto-archive/renumber.

Photo FK:

`FOREIGN KEY (organization_id,photo_asset_id) REFERENCES file_assets(organization_id,id) MATCH SIMPLE ON UPDATE RESTRICT ON DELETE RESTRICT`, plus purpose `vehicle_photo` i `status='ready'` przy attachment commit.

## `vehicle_documents`

Versioned/superseded history model:
- `id uuid PK`
- `organization_id uuid FK`
- `vehicle_id uuid FK`
- `document_type varchar(64)`
- `valid_until date null`
- `asset_id uuid null`
- `created_at timestamptz`
- `created_by_user_id uuid null`
- `superseded_at timestamptz null`
- `superseded_by_user_id uuid null`
- `supersession_reason text null`.

Typy: `technical_inspection`, `oc_insurance`, `ac_insurance`.

Current predicate: `superseded_at IS NULL`.

Partial unique:

`UNIQUE(organization_id,vehicle_id,document_type) WHERE superseded_at IS NULL`.

Replacement/clear/history immutability działają identycznie jak dla StaffDocument. Current detail/expiry czyta tylko non-superseded row; current `valid_until IS NULL` oznacza brak daty ważności. Dla późniejszego Calendar important-date input bierzemy wyłącznie current rows z non-null `valid_until`.

Asset FK:

`FOREIGN KEY (organization_id,asset_id) REFERENCES file_assets(organization_id,id) MATCH SIMPLE ON UPDATE RESTRICT ON DELETE RESTRICT`, plus purpose `vehicle_document` i `status='ready'` przy attachment commit.

## `vehicle_category_assignments`

- `vehicle_id uuid FK`
- `driving_category_id uuid FK`

Unique `(vehicle_id,driving_category_id)`.

## `vehicle_location_assignments`

Tenant-aware join:
- `organization_id uuid FK organizations`
- `vehicle_id uuid`
- `location_id uuid`

`PRIMARY KEY (organization_id,vehicle_id,location_id)`.

Composite FK:
- `(organization_id,vehicle_id) -> vehicles(organization_id,id)`,
- `(organization_id,location_id) -> locations(organization_id,id)`,
- `ON UPDATE RESTRICT / ON DELETE RESTRICT`.

Cross-tenant Vehicle↔Location nie może przejść constraintów ani zanieczyścić późniejszych calendar resource filters.

---

# 11. Students / learning access — DB4_4 + DB4_6 aggregate

Canonical Student identity/lifecycle: `specs/database/students-courses-training.yml`, DB-TRN-001..003. Canonical learning-account/credentials/license boundary: `specs/database/licenses-learning-access.yml`, DB-LIC-001..007.

## `students`

- `id uuid PK`
- `organization_id uuid FK organizations not null`
- `first_name varchar(120) not null`
- `last_name varchar(120) not null`
- `birth_date date null`
- `no_pesel_declared boolean not null default false`
- `pesel_ciphertext text null`
- `pesel_lookup_hash char(64) null`
- `contact_email_normalized varchar(320) null`
- `phone varchar(40) null`
- `default_location_id uuid null`
- `archived_at timestamptz null`
- `archived_by_user_id uuid null`
- `version bigint not null default 1 check (version >= 1)`
- `created_at`
- `updated_at`.

Candidate key:

`UNIQUE(organization_id,id)`.

`default_location_id`, jeśli nie jest `NULL`, jest chronione przez composite FK `(organization_id,default_location_id) -> locations(organization_id,id)` z `ON UPDATE/DELETE RESTRICT`.

PESEL jest jednym logicznym identyfikatorem zapisanym jako ciphertext + keyed lookup hash. Row check wymaga obu pól jednocześnie `NULL` albo jednocześnie non-NULL. `no_pesel_declared=true` wymaga jednocześnie braku PESEL pair i `birth_date IS NOT NULL`; PESEL obecny zabrania `no_pesel_declared=true`.

Pre-course Student może istnieć z niekompletną tożsamością. Formalny `CourseEnrollment` może jednak wskazywać tylko Studenta spełniającego jeden z branchy:
1. `no_pesel_declared=false` + kompletna para PESEL,
2. `no_pesel_declared=true` + brak PESEL + data urodzenia.

Formal-identity guard jest constraint triggerem deferrable albo równoważną transactional DB boundary. Student mający historyczny formalny kurs nie może później zostać zdegradowany do stanu incomplete.

Partial unique:

`UNIQUE(organization_id,pesel_lookup_hash) WHERE pesel_lookup_hash IS NOT NULL`.

Obejmuje archived rows; archive nie zwalnia PESEL. Nie tworzymy fałszywego hard unique na `imię+nazwisko+data_urodzenia` dla osób bez PESEL.

`students.version` jest jednym concurrency root dla profile edit, identity edit, archive i restore. Course create i Student archive serializują się na Student row. Archive przy aktywnym kursie jest conflict i nie anuluje, nie przerywa ani nie usuwa kursu. Restore używa tego samego durable Student row i nie otwiera historycznych kursów.

DB4_6 doprecyzowuje wpływ na learning access: archive nie przepisuje LearningAccount, Assignment ani Activation history, nie pauzuje i nie przedłuża entitlement. Archived Student jest natomiast operacyjnie niekwalifikujący do nowych learning-access effects. Restore sam nie wznawia zawieszonych kont ani nie reaktywuje wygasłych/revoked praw.

## `student_learning_accounts`

Durable tenant learning-access identity:

- `id uuid PK`
- `organization_id uuid not null`
- `student_id uuid not null`
- `user_id uuid not null FK users`
- `auth_login_identifier_id uuid not null`
- `language_code varchar(16) not null FK languages`
- `status varchar(32) not null` — `active|suspended`
- `version bigint not null default 1 check (version >= 1)`
- `created_at`
- `updated_at`.

Candidate keys:
- `UNIQUE(organization_id,id)`,
- `UNIQUE(organization_id,id,student_id)` dla exact Assignment target.

Same-tenant Student FK:
`(organization_id,student_id) -> students(organization_id,id) ON UPDATE/DELETE RESTRICT`.

Same-global-user identifier FK:
`(auth_login_identifier_id,user_id) -> auth_login_identifiers(id,user_id) ON UPDATE/DELETE RESTRICT`.

Referencja auth identifier musi po commit wskazywać current/non-revoked identifier. Final-state guard jest `DEFERRABLE INITIALLY DEFERRED` albo równoważną DB boundary. Nie utrzymujemy już niezależnego `login_identifier_projection`; login/search/list projection czyta `auth_login_identifiers.identifier_normalized` przez join. Nie dokładamy tenantowego namespace/unique loginu, bo generic login jest globalny.

`user_id` nie jest przepinany zwykłym PATCH. `version` jest concurrency root konfiguracji konta. Generic PATCH nie zmienia lifecycle `status`; suspend/resume jest jawny. Normalny hard-delete jest zabroniony.

Operational eligibility do nowych assignment/activation/reset effects wymaga jednocześnie:
- `status='active'`,
- Student nie jest archived,
- auth identifier nadal current,
- global User jest auth-eligible.

State-dependent mutation lockuje co najmniej `Student -> StudentLearningAccount`, żeby archive i nowy learning-access effect nie mogły przejść obok siebie.

## `student_access_handoffs`

Non-secret metadata wydania danych dostępowych:

- `id uuid PK`
- `organization_id uuid not null`
- `student_learning_account_id uuid not null`
- `handoff_type varchar(32) not null` — np. `initial|reset|nonsecret_reprint`
- `generated_by_user_id uuid FK users`
- `document_asset_id uuid null`
- `credential_version_snapshot bigint null`
- `contains_fresh_secret boolean not null default false`
- `fresh_secret_issued_at timestamptz null`
- `batch_id uuid null`
- `batch_ordinal integer null`
- `created_at`.

Same-tenant composite FK chroni LearningAccount i optional FileAsset. Handoff nie przechowuje plaintext password ani password hash.

Jeżeli `contains_fresh_secret=true`, handoff musi odnosić się do initial/reset, mieć dodatni `credential_version_snapshot`, `fresh_secret_issued_at` i **`document_asset_id IS NULL`**. Secret-bearing PDF jest memory-only; metadata handoff może potwierdzać jego wydanie, ale nie zachowuje pliku ani sekretu.

Późniejszy „druk hasła” nie może odzyskać dawnego plaintextu. Jeżeli ma ponownie pokazać hasło, jest to nowy reset z nowym credential version.

## `student_access_export_batches`

Auditowalna metadata operacji zbiorczej, bez sekretów:

- `id uuid PK`
- `organization_id uuid not null`
- `export_mode varchar(32) not null` — `nonsecret_combined_pdf|reset_and_secret_combined_pdf`
- `requested_by_user_id uuid not null`
- `item_count integer not null check > 0`
- `created_at`.

Po commit liczba handoff items w batch musi odpowiadać `item_count`; `(batch_id,batch_ordinal)` i `(batch_id,student_learning_account_id)` są unique.

W trybie `reset_and_secret_combined_pdf` wszystkie targety są prewalidowane przed pierwszą mutacją. Resety i wynikowy combined PDF są logicznie all-or-none. Ten sam global User wskazany przez kilka LearningAccount w batch dostaje jeden reset, a wszystkie jego handoff rows snapshotują ten sam nowy credential version. Failed render przed durable password write pozostawia stare credentials bez zmian.

---

# 12. CourseEnrollment / lifecycle / requirements — DB4_4 aggregate

Canonical szczegóły: DB-TRN-004, DB-TRN-005 i DB-TRN-007.

## `course_enrollments`

- `id uuid PK`
- `organization_id uuid FK not null`
- `student_id uuid not null`
- `training_type varchar(32) not null`
- `driving_category_id uuid FK not null`
- `started_at timestamptz not null`
- `lead_instructor_id uuid not null`
- `location_id uuid null`
- `training_stage varchar(64) not null`
- `declared_theory_minutes integer null check >= 0`
- `declared_practical_minutes integer null check >= 0`
- `completed_at timestamptz null`
- `interrupted_at timestamptz null`
- `cancelled_at timestamptz null`
- `cancelled_by_user_id uuid null`
- `created_by_user_id uuid`
- `version bigint not null default 1 check (version >= 1)`
- `requirements_revision bigint not null default 1 check (requirements_revision >= 1)`
- `created_at`
- `updated_at`.

Candidate keys:
- `UNIQUE(organization_id,id)`,
- `UNIQUE(organization_id,id,student_id)` dla exact-Course-Student FK Attendance.

Composite same-tenant FK chronią Student, lead instructor oraz optional Location. `organization_id` nie jest client authority.

`declared_*` zachowują zaobserwowane pola jako plan/deklarację i **nie są zaliczonym formalnym czasem**.

Canonical `training_stage` values:
`unassigned|theory|practice|documentation|word_exam|supplementary_training|training_completed`.

Lifecycle nie ma drugiej mutowalnej kolumny status. Jest wyprowadzany z terminal timestamps:
- active: wszystkie terminal timestamps `NULL`, stage != `training_completed`,
- completed: tylko `completed_at` non-NULL i stage `training_completed`,
- interrupted: tylko `interrupted_at` non-NULL i stage != `training_completed`,
- cancelled: tylko `cancelled_at` non-NULL, `cancelled_by_user_id` non-NULL i stage != `training_completed`.

DB wymusza maksymalnie jeden terminal timestamp oraz równoważność `training_completed <-> completed_at non-NULL`. Normalny restore otwiera tylko cancelled Course; completed/interrupted wymagają jawnej exceptional lifecycle correction. Generic PATCH terminalnego Course jest zabroniony bez correction mode.

## `course_enrollment_lifecycle_events`

Append-only historia każdej materialnej wersji Course:
- `id uuid PK`
- `organization_id uuid not null`
- `course_enrollment_id uuid not null`
- `event_type varchar(48) not null`
- `from_lifecycle_state varchar(24) null`
- `to_lifecycle_state varchar(24) not null`
- `from_training_stage varchar(64) null`
- `to_training_stage varchar(64) not null`
- `course_version_before bigint null`
- `course_version_after bigint not null`
- `reason text null`
- `actor_user_id uuid null only for migration baseline`
- `correction_of_event_id uuid null`
- `event_payload_redacted jsonb null`
- `occurred_at timestamptz not null`.

Same-tenant composite FK do Course oraz self-FK dla `correction_of_event_id`. Unique `(organization_id,course_enrollment_id,course_version_after)` gwarantuje jeden event per material Course version. Normalne eventy mają `after=before+1`; migration baseline może mieć `before=NULL`. Historia nie jest aktualizowana ani usuwana przez normalny lifecycle.

## `training_requirement_rule_sets`

Globalny, immutable katalog dokładnych artefaktów rule engine:
- `version varchar(64) PK`
- `jurisdiction varchar(16) not null`
- `content_hash char(64) not null`
- `source_reference varchar(255) null`
- `effective_from timestamptz null`
- `published_at timestamptz not null`
- `created_at timestamptz not null`.

Raz użytej `version` nie wolno przypisać innej treści.

## `course_requirement_contexts`

Jeden current non-course source-fact row na Course:
- `organization_id uuid not null`
- `course_enrollment_id uuid not null`
- `state_theory_passed boolean not null default false`
- `evidence_reference varchar(255) null`
- `effective_from timestamptz not null`
- `updated_by_user_id uuid not null`
- `updated_at timestamptz not null`.

PK `(organization_id,course_enrollment_id)`. Nie ma własnego concurrency root; używa Course version.

## `course_requirement_context_held_categories`

Normalized current held-category set:
- `organization_id uuid not null`
- `course_enrollment_id uuid not null`
- `driving_category_id uuid not null`.

PK `(organization_id,course_enrollment_id,driving_category_id)`.

## `course_requirement_override_decisions`

Audytowalna, opcjonalna manual override decision history:
- `id uuid PK`
- `organization_id uuid not null`
- `course_enrollment_id uuid not null`
- `override_payload jsonb not null`
- `reason text not null`
- `evidence_reference varchar(255) null`
- `approved_by_user_id uuid not null`
- `created_at timestamptz not null`
- `revoked_at timestamptz null`
- `revoked_by_user_id uuid null`
- `revocation_reason text null`.

Dozwolone override keys są ograniczone do requirement outputs: theory/practical required, minimum minutes oraz internal theory/practical exam required. Maximum one current row per Course: partial unique `(organization_id,course_enrollment_id) WHERE revoked_at IS NULL`.

## `training_requirement_profiles`

Immutable calculation-decision history + jeden current projection:
- `id uuid PK`
- `organization_id uuid not null`
- `course_enrollment_id uuid not null`
- `requirements_revision bigint not null`
- `course_version_after bigint not null`
- `rule_set_version varchar(64) not null FK training_requirement_rule_sets`
- `trigger_code varchar(64) not null`
- `calculation_reason text null`
- `calculated_by_user_id uuid null for system`
- `input_snapshot jsonb not null`
- `base_output_snapshot jsonb not null`
- `effective_output_snapshot jsonb not null`
- `manual_override_decision_id uuid null`
- `theory_training_required boolean`
- `minimum_theory_minutes integer`
- `internal_theory_exam_required boolean`
- `practical_training_required boolean`
- `minimum_practical_minutes integer`
- `internal_practical_exam_required boolean`
- `exemption_basis_code varchar(128) null`
- `calculated_at timestamptz not null`
- `superseded_at timestamptz null`.

Partial unique `(organization_id,course_enrollment_id) WHERE superseded_at IS NULL` daje at-most-one current. Unique `(organization_id,course_enrollment_id,requirements_revision)` daje jeden decision per revision. Deferrable final-state guard wymaga **dokładnie jednego** current profile i `profile.requirements_revision = course_enrollments.requirements_revision` dla każdego committed formal Course.

Snapshot przechowuje dokładne normalized inputs, rule-set version + hash i decyzje exemption/override. PESEL i PKK nie trafiają do requirement snapshotów.

## `course_exemption_decisions`

- `id uuid PK`
- `organization_id uuid not null`
- `course_enrollment_id uuid not null`
- `basis_code varchar(128)`
- `evidence_reference varchar(255) null`
- `reason text null`
- `approved_by_user_id uuid`
- `rule_set_version varchar(64)`
- `created_at`
- `revoked_at timestamptz null`
- `revoked_by_user_id uuid null`
- `revocation_reason text null`.

Business fields są immutable po insert. Partial unique `(organization_id,course_enrollment_id) WHERE revoked_at IS NULL`. Replacement/revoke zachowuje dawną decyzję i w tej samej Course-serialized transakcji zwiększa `requirements_revision`, tworzy nowy current RequirementProfile i lifecycle history event.

## `recognized_external_training`

Wersjonowana historia godzin uznanych z poprzedniego OSK:
- `id uuid PK`
- `organization_id uuid not null`
- `course_enrollment_id uuid not null`
- `training_part varchar(32)` — `theory|practical`
- `recognized_minutes integer not null` — current runtime row > 0; zero reprezentujemy brakiem current effect,
- `record_role varchar(32)` — `course_form_projection|documented_transfer`,
- `source_kind varchar(32)` — `course_form_initial|course_form_revision|documented_transfer|correction|context_revalidation`,
- `source_school_reference varchar(255) null`
- `evidence_reference varchar(255) null`
- `reason text null`
- `approved_by_user_id uuid`
- `recognized_for_driving_category_id uuid null only for noncurrent legacy unknown`
- `recognized_for_training_type varchar(32) null only for noncurrent legacy unknown`
- `supersedes_record_id uuid null`
- `created_at`
- `superseded_at timestamptz null`
- `revoked_at timestamptz null`
- `revoked_by_user_id uuid null`
- `revocation_reason text null`.

Current predicate: `superseded_at IS NULL AND revoked_at IS NULL` oraz context snapshot zgodny z bieżącą kategorią i training type Course.

`course_form_projection` ma partial unique `(organization_id,course_enrollment_id,training_part)` dla current rows — formularz ma zatem jedną bieżącą scalar wartość per część. `documented_transfer` jest niezależnym additive recordem; wiele current transferów jest sumowanych.

Korekta external to **pełne replacement value**, nie signed delta jak Ledger correction. Source jest superseded, successor zachowuje tenant/Course/part/role. Composite self-FK oraz partial unique na `supersedes_record_id` zabraniają cross-course lineage i branchowania. Nie używamy `MAX(created_at)` / „latest row wins”.

Zmiana kategorii lub training type Course wymaga revalidation current external rows w tej samej transakcji. DB nie koduje niezweryfikowanej macierzy legalnej kompatybilności; wymusza jedynie, że po domain decision nie pozostanie stale-context current credit.

Formalny total per part:

`current_OSK_ledger_minutes + current_recognized_external_minutes`.

Teoria i praktyka nie zastępują się wzajemnie.

---

# 13. Training sessions / formal hour ledger — DB4_4 aggregate + DB4_5 schedule-conflict extension

Canonical szczegóły: DB-TRN-001, DB-TRN-006 oraz DB-CAL-003/007.

## `training_sessions`

- `id uuid PK`
- `organization_id uuid not null`
- `course_enrollment_id uuid not null`
- `session_type varchar(32)` — formal creditable `theory|practical`
- `starts_at timestamptz not null`
- `ends_at timestamptz not null`
- `duration_minutes integer not null check > 0`
- `instructor_id uuid not null`
- `vehicle_id uuid null`
- `location_id uuid null`
- `status varchar(32) not null` — `planned|completed|cancelled`
- `completed_at timestamptz null`
- `completed_by_user_id uuid null`
- `cancelled_at timestamptz null`
- `cancelled_by_user_id uuid null`
- `cancellation_reason text null`
- `created_by_user_id uuid`
- `version bigint not null default 1 check (version >= 1)`
- `created_at`
- `updated_at`.

Candidate keys:
- `UNIQUE(organization_id,id)`,
- `UNIQUE(organization_id,id,course_enrollment_id)`.

Composite same-tenant FKs do Course, Instructor oraz optional Vehicle/Location. `duration_minutes` jest dokładną dodatnią liczbą pełnych minut pomiędzy `starts_at` i `ends_at`; nie zaokrąglamy sesji do bloków 45/60.

State checks wiążą `planned/completed/cancelled` z odpowiednią terminal metadata. Completed/cancelled Session jest normalnie immutable i nie hard-delete.

Po DB4_5 `TrainingSession` jest również jedynym canonical schedule ownerem formalnego szkolenia. Każdy `planned` Session — zarówno `theory`, jak i `practical` — materializuje dokładny set `calendar_resource_claims`: Student wynikający z Course, Instructor oraz opcjonalne Vehicle i managed Location dla dokładnego przedziału `[starts_at,ends_at)`. `completed|cancelled` ma zero aktywnych claims. Create/reschedule, complete i cancel synchronizują te claims w tej samej transakcji co Session lifecycle; claim insert sam nie tworzy Attendance ani Ledger credit.

Praktyczny Session jest w read modelu Calendar projekcją `driving_lesson`; nie ma drugiej mutable kopii w `calendar_events`. Opcjonalną nazwę/custom meeting text zachowuje 1:1 `training_session_calendar_details`, opisane w sekcji 15. Materialna zmiana companion metadata korzysta z tego samego `training_sessions.version`, nie z osobnego concurrency root.

## `training_session_attendance`

- `organization_id uuid not null`
- `training_session_id uuid not null`
- `course_enrollment_id uuid not null`
- `student_id uuid not null`
- `status varchar(32)` — `present|absent`
- `confirmed_by_user_id uuid null`
- `confirmed_at timestamptz null`.

Canonical cardinality: `UNIQUE(organization_id,training_session_id)` — jeden Attendance row na single-student formal Session.

Exact integrity:
- `(organization_id,training_session_id,course_enrollment_id) -> training_sessions(organization_id,id,course_enrollment_id)`,
- `(organization_id,course_enrollment_id,student_id) -> course_enrollments(organization_id,id,student_id)`.

Confirmation actor i time są oba NULL albo oba non-NULL. Formalny base credit może powstać wyłącznie dla verified `present`.

## `training_hour_ledger_entries`

Immutable / append-only:
- `id uuid PK`
- `organization_id uuid not null`
- `course_enrollment_id uuid not null`
- `training_session_id uuid null`
- `entry_type varchar(32)` — `credit|opening_balance|correction|reversal`
- `training_part varchar(32)` — `theory|practical`
- `minutes integer not null`
- `source_entry_id uuid null`
- `reason text null`
- `actor_user_id uuid not null for new runtime entries`
- `created_at`.

Session relation jest exact-course composite FK:
`(organization_id,training_session_id,course_enrollment_id) -> training_sessions(organization_id,id,course_enrollment_id)`.

Source relation dla correction/reversal obejmuje tenant + source ID + Course + training part, więc nie można korygować wpisu z innego Course albo part.

Partial unique:
- base credit: `(organization_id,training_session_id) WHERE entry_type='credit'`,
- opening balance: `(organization_id,course_enrollment_id,training_part) WHERE entry_type='opening_balance'`,
- reversal source: `(organization_id,source_entry_id) WHERE entry_type='reversal'`.

Entry matrix:
- `credit`: dodatni, Session required, source NULL, minutes = exact Session duration, part wynika z Session type,
- `opening_balance`: dodatni, no Session/source, reason required, tylko explicit current-OSK import,
- `correction`: non-zero signed delta, reason required, source optional,
- `reversal`: dokładne `-source.minutes`, source required, source typu reversal niedozwolony.

Deferrable final-state guard:
- planned Session -> 0 credits,
- cancelled Session -> 0 credits,
- completed + verified present -> dokładnie 1 matching credit,
- completed + verified absent -> 0 credits,
- completed Session -> dokładnie 1 verified Attendance.

Complete transaction lock order: `Course -> Session -> Attendance`. Cancel: `Course -> Session`. Complete/cancel race nie może pozostawić cancelled Session z creditem ani present completed Session bez creditu. Po DB4_5 complete/cancel atomowo usuwa również Session resource claims; finalny terminal Session ma zero claims.

Current OSK formal time = signed sum Ledger per `Course + training_part`. Course-part total oraz source-linked Session subtotal nie mogą być ujemne. Godziny zadeklarowane w Course form nie zwiększają Ledger; godziny z poprzedniego OSK pozostają w `recognized_external_training`.

---

# 14. PKK — local course identity + provider boundary

## `pkk_integration_settings`

Canonical one-to-one konfiguracja PKK dla organizacji, wspólna dla `/ustawienia` i configuration gate.

- `organization_id uuid PK/FK organizations`
- `school_name varchar(255) null`
- `osk_registry_number varchar(128) null`
- `external_osk_login_ciphertext text null`
- `external_osk_login_lookup_hash char(64) null`
- `readiness_status varchar(32) not null default 'not_configured'`
- `updated_at timestamptz`

`readiness_status`: `not_configured|configured_unverified|verified|requires_attention`.

Konfiguracja providera jest osobnym konceptem. Brak zweryfikowanej konfiguracji nie blokuje lokalnego atomowego zapisu Course + wymaganej identity PKK; blokuje/warunkuje późniejsze provider operations w DB4_8.

## `pkk_profiles`

Canonical owner course-scoped identity PKK oraz jej wersjonowanej historii. Nie ma drugiej kopii PKK na `course_enrollments`.

- `id uuid PK`
- `organization_id uuid not null`
- `course_enrollment_id uuid not null`
- `pkk_number_ciphertext text not null`
- `pkk_lookup_hash char(64) not null`
- `identity_revision bigint not null check >= 1`
- `bound_driving_category_id uuid not null`
- `bound_training_type varchar(32) not null`
- `record_origin varchar(32) not null` — `course_create|course_edit|context_revalidation|migration_baseline`
- `recorded_at timestamptz not null`
- `recorded_by_user_id uuid null only for migration baseline`
- `supersedes_pkk_profile_id uuid null`
- `superseded_at timestamptz null`
- `superseded_by_user_id uuid null`
- `status varchar(64) null` — provider lifecycle ownership DB4_8
- `profile_snapshot_ciphertext text null`
- `profile_snapshot_redacted jsonb null`
- `fetched_at timestamptz null`
- `updated_at`.

Current predicate: `superseded_at IS NULL`.

Composite same-tenant Course FK:
`(organization_id,course_enrollment_id) -> course_enrollments(organization_id,id)`.

Candidate key `(organization_id,id,course_enrollment_id)` pozwala lineage self-FK:
`(organization_id,supersedes_pkk_profile_id,course_enrollment_id) -> pkk_profiles(organization_id,id,course_enrollment_id)`.

Constraints:
- unique `(organization_id,course_enrollment_id,identity_revision)`,
- partial unique `(organization_id,course_enrollment_id) WHERE superseded_at IS NULL`,
- partial unique `(organization_id,supersedes_pkk_profile_id) WHERE supersedes_pkk_profile_id IS NOT NULL`,
- source musi być current przed replacement; self-reference/cycle/branching zabronione.

Deferrable required-current-profile guard wymaga po commit **dokładnie jednego current `pkk_profile` dla każdego CourseEnrollment** oraz zgodności `bound_driving_category_id` i `bound_training_type` z finalnym Course.

PKK write contract:
1. plaintext tylko na autoryzowanym command boundary,
2. jedna canonical normalization,
3. z tego samego normalized inputu powstają ciphertext + HMAC lookup hash,
4. zapis obu atomowo,
5. plaintext nie trafia do Course, historii, audit ani outbox.

DB4_4 świadomie **nie** wprowadza hard unique na `pkk_lookup_hash`, bo duplicate/collision policy nie została potwierdzona. Lookup hash służy do bezpiecznego authorized collision detection; exact provider/legal collision policy pozostaje DB4_8 + legal/product verification.

Course create atomowo zapisuje:
- CourseEnrollment,
- current PkkProfile `identity_revision=1`, `record_origin=course_create`,
- requirement context/current RequirementProfile,
- initial external rows, jeśli są dodatnie,
- Course lifecycle `created` event,
- audit/outbox.

Błąd PKK encryption/hash/profile insert rollbackuje cały Course create. Provider fetch nie jest wymagany przed lokalnym commit.

Zmiana PKK nie nadpisuje current profile in-place. Stary row jest superseded, a successor dostaje `identity_revision+1`, finalny Course context i `record_origin=course_edit`. Ten sam normalized PKK = identity-history no-op.

Zmiana category/training type wymaga revalidation PKK context w tej samej Course-serialized transakcji. Kompatybilny ten sam PKK może dostać `context_revalidation` successor bez ponownego plaintextu; incompatibility/unknown bez replacement PKK blokuje Course context change. Nie kodujemy niezweryfikowanej macierzy provider/legal compatibility.

Cancel/restore Course nie kasuje ani nie supersede'uje PKK i nie wykonuje ukrytego provider return/fetch.

## `pkk_operations`

- `id uuid PK`
- `organization_id uuid FK`
- `course_enrollment_id uuid FK`
- `pkk_profile_id uuid null FK`
- `operation_type varchar(64)`
- `business_status varchar(32)`
- `idempotency_key uuid null`
- `request_id varchar(64)`
- `request_snapshot_redacted jsonb null`
- `request_payload_ciphertext text null`
- `actor_user_id uuid`
- `created_at`
- `completed_at timestamptz null`.

## `pkk_operation_attempts`

- `id uuid PK`
- `pkk_operation_id uuid FK`
- `attempt_no integer`
- `provider_request_id varchar(128) null`
- `transport_status varchar(32)`
- `provider_status_code varchar(64) null`
- `error_class varchar(64) null`
- `retryable boolean`
- `normalized_response_redacted jsonb null`
- `provider_response_ciphertext text null`
- `started_at`
- `finished_at timestamptz null`.

Unique `(pkk_operation_id,attempt_no)`.

**DB4_4 synchronizuje tylko local required course PKK identity.** Exact provider configuration verification, fetch/update/return/XML-signature/status/retry/idempotency/reconciliation i operation-attempt constraints pozostają do osobnego DB4_8.

---

# 15. Calendar — DB4_5 aggregate

Canonical szczegóły: `specs/database/calendar.yml`, audyt `docs/109-stage-4-calendar-audit.md`, DB-CAL-001..007.

## 15.1 Storage i read-model boundary

Po DB4_5 nie utrzymujemy dwóch mutable schedule facts dla formalnej jazdy.

Źródła bieżących elementów kalendarza:
- manual `general_event` -> `calendar_events`,
- formal `driving_lesson` -> praktyczny `training_sessions` + opcjonalny `training_session_calendar_details`,
- `important_date` -> source-domain projection z bieżących Staff/Vehicle documents z `valid_until IS NOT NULL`,
- booked availability reservation -> `availability_slots` tylko dopóki `training_session_id IS NULL`.

Sformalizowany slot nie emituje drugiego elementu, bo linked TrainingSession jest już jedyną formalną rezerwacją.

Historyczne `calendar_events(event_type='driving_lesson')` nie są automatycznie dopasowywane do TrainingSession po czasie, nazwie ani zasobach. Migracja może konwertować wyłącznie dowodliwe 1:1 przypadki; niejednoznaczne wymagają jawnej reviewed remediation.

## 15.2 `calendar_events` — manual `general_event`

Po DB4_5 runtime `calendar_events` przechowuje ręczne `general_event`, nie formalną jazdę.

- `id uuid PK`
- `organization_id uuid not null`
- `event_type varchar(32) not null` — runtime `general_event`,
- `name varchar(255) null`
- `starts_at timestamptz not null`
- `ends_at timestamptz not null`
- `student_id uuid null`
- `instructor_id uuid null`
- `vehicle_id uuid null`
- `location_id uuid null`
- `custom_meeting_place varchar(255) null`
- `status varchar(32) not null` — `scheduled|completed|cancelled`,
- `created_by_user_id uuid not null`
- `version bigint not null default 1 check (version >= 1)`
- `completed_at timestamptz null`
- `completed_by_user_id uuid null`
- `cancelled_at timestamptz null`
- `cancelled_by_user_id uuid null`
- `cancellation_reason text null`
- `created_at`
- `updated_at`.

Candidate key: `UNIQUE(organization_id,id)`.

Composite same-tenant FKs `(organization_id,resource_id)` chronią optional Student/Instructor/Vehicle/Location i używają `MATCH SIMPLE`, `ON UPDATE/DELETE RESTRICT`. `organization_id` nie jest client authority.

Meeting place ma zero lub jedno persisted source:
- saved `location_id`, albo
- trimmed nonblank `custom_meeting_place`.

Oba mogą być NULL; oba non-NULL są zabronione. Custom text nie tworzy `Location`.

Lifecycle:
- create -> `scheduled`, version 1,
- material PATCH: `scheduled -> scheduled`, version +1,
- complete: `scheduled -> completed`, version +1,
- cancel: `scheduled -> cancelled`, version +1.

Generic PATCH status/terminal metadata, normal restore i hard delete terminal history są zabronione. Completed/cancelled mają spójną terminal metadata i zero current claims.

`created_by_user_id` jest provenance, nie ownerem `own` scope. Canonical owner manual eventu to `instructor_id` wskazujący StaffProfile. `instructor_id=NULL` daje `own` DENY; organization scope może nadal zarządzać, jeśli permission na to pozwala.

## 15.3 `calendar_event_lifecycle_events`

Append-only material-version history:
- `id uuid PK`
- `organization_id uuid not null`
- `calendar_event_id uuid not null`
- `event_type varchar(32) not null` — runtime `created|updated|completed|cancelled`, migration-only `migration_baseline`,
- `from_status varchar(32) null`
- `to_status varchar(32) not null`
- `event_version_before bigint null`
- `event_version_after bigint not null`
- `actor_user_id uuid null only for migration baseline`
- `reason text null`
- `changed_fields_redacted jsonb null`
- `occurred_at timestamptz not null`.

Same-tenant FK do CalendarEvent; actor to global `users(id)`. Unique `(organization_id,calendar_event_id,event_version_after)`.

`DEFERRABLE INITIALLY DEFERRED` current-version/history guard wymaga po commit dokładnie jednego history row dla current Event version i `to_status` zgodnego z Event status. Runtime successor ma `after=before+1`. Business history jest immutable.

## 15.4 `availability_slots`

- `id uuid PK`
- `organization_id uuid not null`
- `instructor_id uuid null`
- `vehicle_id uuid null`
- `location_id uuid null`
- `starts_at timestamptz not null`
- `ends_at timestamptz not null`
- `status varchar(32) not null` — `available|booked|cancelled`,
- `booked_student_id uuid null`
- `booked_at timestamptz null`
- `training_session_id uuid null`
- `version bigint not null default 1 check (version >= 1)`
- `created_at`
- `updated_at`.

Candidate key `UNIQUE(organization_id,id)`. Same-tenant optional FKs do Instructor/Vehicle/Location/Student. `training_session_id`, gdy non-NULL, jest same-tenant relacją do TrainingSession i ma partial unique `(organization_id,training_session_id)`.

State matrix:
- `available`: booking fields NULL, `training_session_id=NULL`, zero claims, normal PATCH/book/cancel dozwolone,
- `booked` unformalized: Student+booked_at non-NULL, `training_session_id=NULL`, exact `availability_slot_booking` claims,
- `booked` formalized: Student+booked_at zachowane jako booking snapshot, `training_session_id!=NULL`, **zero slot-booking claims**; current reservation owner = TrainingSession,
- `cancelled`: current booking fields NULL, link NULL w normalnym flow, zero claims, terminal.

Cancellation nie republishuje dostępności. Chcąc ponownie wystawić czas, tworzymy nowy slot. Successful booking nie tworzy CalendarEvent.

Canonical own owner slotu = `instructor_id` StaffProfile; creator/Student nie daje own ownership.

## 15.5 `availability_slot_lifecycle_events`

Append-only history każdej materialnej wersji slotu:
- `id uuid PK`
- `organization_id uuid not null`
- `availability_slot_id uuid not null`
- `event_type varchar(32)` — runtime `created|updated|booked|formalized|cancelled`, migration-only `migration_baseline`,
- `from_status varchar(32) null`
- `to_status varchar(32) not null`
- `slot_version_before bigint null`
- `slot_version_after bigint not null`
- `actor_user_id uuid null only for migration baseline`
- `booking_student_id_snapshot uuid null`
- `reason text null`
- `changed_fields_redacted jsonb null`
- `occurred_at timestamptz not null`.

Unique `(organization_id,availability_slot_id,slot_version_after)`. Current version/history final-state guard jest `DEFERRABLE INITIALLY DEFERRED`.

`formalized` jest materialnym `booked -> booked` eventem version +1 i zachowuje Student snapshot. Późniejsze lifecycle linked Session nie rewrite'uje tej historii.

## 15.6 `calendar_resource_claims` — finalna race-safe conflict boundary

To techniczna current projection zajętości, nie business history.

- `id uuid PK`
- `organization_id uuid not null`
- `claim_owner_kind varchar(32) not null` — `calendar_event|availability_slot_booking|training_session`,
- `claim_owner_id uuid not null`
- `student_id uuid null`
- `instructor_id uuid null`
- `vehicle_id uuid null`
- `location_id uuid null`
- `starts_at timestamptz not null`
- `ends_at timestamptz not null`
- `occupied_during tstzrange GENERATED ALWAYS AS (tstzrange(starts_at,ends_at,'[)')) STORED`
- `created_at timestamptz not null`.

Checks:
- `ends_at > starts_at`,
- `num_nonnulls(student_id,instructor_id,vehicle_id,location_id)=1`,
- owner kind jest dokładnie jedną z 3 dozwolonych wartości.

Każdy resource relation ma same-tenant composite FK. Owner resolution do właściwego CalendarEvent/AvailabilitySlot/TrainingSession oraz exact claim set są chronione `DEFERRABLE INITIALLY DEFERRED` constraint triggerami lub równoważną transactional DB boundary.

Canonical przedział konfliktu to half-open `[starts_at,ends_at)`. Zatem `10:00–11:00` i `11:00–12:00` nie konfliktują.

Wymagane `btree_gist` oraz cztery partial exclusion constraints:
- Student: `organization_id WITH =`, `student_id WITH =`, `occupied_during WITH &&` gdzie Student non-NULL,
- Instructor analogicznie,
- Vehicle analogicznie,
- Location analogicznie.

To jest finalna concurrency boundary. Application precheck służy UX, ale race nie może ominąć GiST.

Custom meeting text i `important_date` nie tworzą resource claims.

## 15.7 Exact claim sets

### Scheduled manual general event
Dokładnie po jednym claimie dla każdego niepustego Student/Instructor/Vehicle/Location, z exact event interval. Completed/cancelled = zero claims.

### Booked AvailabilitySlot przed formalizacją
Dokładnie:
- Student równy `booked_student_id`,
- Instructor iff non-NULL,
- Vehicle iff non-NULL,
- Location iff non-NULL,
- exact slot interval.

Available/cancelled = zero claims. Formalized booked slot = zero slot claims, bo reservation owner został przeniesiony do TrainingSession.

### Planned TrainingSession
Dokładnie:
- Student wynikający z `course_enrollments.student_id`,
- Instructor z Session,
- Vehicle iff non-NULL,
- Location iff non-NULL,
- exact Session interval.

Dotyczy zarówno theory, jak i practical Session. Completed/cancelled = zero claims.

Claim insert nigdy nie nalicza godzin i nie tworzy Attendance.

## 15.8 `training_session_calendar_details`

Calendar-only 0..1 companion formalnego practical TrainingSession:
- `organization_id uuid not null`
- `training_session_id uuid not null`
- `display_name text null`
- `custom_meeting_place text null`
- `created_at timestamptz not null`
- `updated_at timestamptz not null`.

Unique/PK `(organization_id,training_session_id)`, same-tenant FK do Session. DB guard wymaga `session_type='practical'`.

Companion nie przechowuje czasu, Studenta, Instruktora, Vehicle ani saved Location. `training_sessions.location_id` oraz companion custom text są zero-or-one source; oba NULL dozwolone, oba non-NULL zabronione. Custom text po trimie musi być niepusty.

Materialna zmiana companion metadata zwiększa **ten sam `training_sessions.version`**. Terminal Session nie jest normalnie patchowany przez companion.

## 15.9 Formal `driving_lesson` routing i permissions

Kalendarz wyświetla practical TrainingSession jako `driving_lesson`, ale mutacja jest command adapterem do Training domain:
- create: `training_sessions.create`,
- reschedule/update: `training_sessions.edit`,
- cancel: `training_sessions.cancel`,
- complete: `training_sessions.edit` + DB-TRN-006.

`calendar.manage.*` sam nie daje prawa do mutacji formalnego Session. Generic CalendarEvent PATCH/cancel/complete nie targetuje source-based TrainingSession projection.

`calendar.view` może projektować formalne Session z istniejącymi scope adapters:
- own -> Session Instructor,
- assigned Student -> Course Student,
- assigned Location -> Session Location,
- organization -> Organization.

Formalne complete nadal wymaga verified Attendance i tworzy eligible Ledger credit wyłącznie ścieżką DB-TRN-006. Calendar complete/claim insert nie jest skrótem do formalnego zaliczenia czasu.

Przy tworzeniu jazdy z Calendar explicit `course_enrollment_id` jest preferowany. Implicit Course resolution jest dozwolone tylko, gdy dokładnie jeden aktywny, kwalifikujący się Course jest dowodliwy. Zero/wiele -> wymagany jawny Course; nie wybieramy „latest/first”.

## 15.10 Booked AvailabilitySlot -> formal TrainingSession handoff

Booking nie tworzy Session automatycznie, bo sam Student nie gwarantuje jednoznacznego Course context.

Dedykowany formalization command:
1. wymaga `training_sessions.create`, Idempotency-Key i expected slot version,
2. rozwiązuje explicit lub dokładnie jeden kwalifikujący Course bez heurystyki,
3. lock order `Course -> AvailabilitySlot`,
4. wymaga `booked`, `training_session_id IS NULL`, same Student Course↔booking,
5. tworzy planned practical TrainingSession z booking snapshot czasu/zasobów,
6. opcjonalnie companion metadata,
7. usuwa `availability_slot_booking` claims,
8. wstawia exact `training_session` claims dla tej samej rezerwacji,
9. zapisuje `slot.training_session_id`,
10. zwiększa slot version raz,
11. appenduje `formalized` history event,
12. audit/outbox + deferred link/claims/history/GiST guards,
13. commit.

Całość jest jedną transakcją: nie ma committed stanu z dwoma reservation owners ani bez reservation fact. Failure rollbackuje nowy Session/link/history i zachowuje pierwotne booking claims.

Po handoff slot nie jest samodzielnie anulowany; używamy Session cancel. Session cancel/complete nie republishuje slotu. Późniejszy Session reschedule nie przepisuje historycznego booking snapshotu slotu.

## 15.11 Important dates

`important_date` nie jest `calendar_events` row.

Bieżące źródła:
- StaffDocument current (`superseded_at IS NULL`) z non-NULL `valid_until`: card/authorization, medical exam, psychological exam,
- VehicleDocument current z non-NULL `valid_until`: technical inspection, OC, AC.

Canonical identity projection opiera się na `(organization_id,source_domain,source_row_id,date_kind)`. Zmiana `valid_until` w source domain aktualizuje widok bez drugiego calendar write. Projection nie można mutować manual CalendarEvent endpoints i nie tworzy resource claims.

## 15.12 Calendar migration safety

DB4_5 migration nie może:
- heurystycznie klasyfikować nieznanych event/slot statusów,
- fabrykować terminal actor/timestamps z `updated_at`/creatora,
- zgadywać booked Studenta lub booked_at,
- wybierać zwycięzcy legacy overlapu,
- przesuwać czasu, anulować event/slot ani zerować zasobów tylko po to, by GiST przeszedł,
- heurystycznie mapować legacy driving_lesson do TrainingSession,
- tworzyć duplicate TrainingSession dla niejednoznacznego legacy row.

Najpierw prechecks/reviewed remediation, potem candidate keys/composite FKs/history baselines, `btree_gist`, claim projection/backfill, exact-set guards i GiST constraints.

---

# 16. Student finance

## `student_charges`

- `id uuid PK`
- `organization_id uuid FK`
- `student_id uuid FK`
- `course_enrollment_id uuid null FK`
- `title varchar(255)`
- `amount_minor bigint check >= 0`
- `currency char(3) default 'PLN'`
- `due_at date null`
- `status varchar(32)`
- `created_by_user_id uuid`
- `cancelled_at timestamptz null`
- `cancelled_by_user_id uuid null`
- `cancellation_reason text null`
- `created_at`.

## `student_payments`

- `id uuid PK`
- `organization_id uuid FK`
- `student_id uuid FK`
- `charge_id uuid FK`
- `amount_minor bigint check > 0`
- `currency char(3)`
- `paid_at timestamptz`
- `payment_method varchar(32) null`
- `note text null`
- `received_by_user_id uuid`
- `idempotency_key uuid null`
- `reversed_at timestamptz null`
- `reversed_by_user_id uuid null`
- `reversal_reason text null`
- `created_at`.

Balance = charge - nieodwrócone payments.

---

# 17. Licenses / entitlement — DB4_6 aggregate

Canonical szczegóły: `specs/database/licenses-learning-access.yml`, audyt `docs/110-stage-4-licenses-learning-access-audit.md`, DB-LIC-001..007.

## 17.1 `license_products`

- `id uuid PK`
- `code varchar(64) unique not null`
- `duration_days integer not null check (duration_days > 0)`
- `active boolean not null`
- `activation_mode varchar(32) not null`
- `metadata jsonb null`.

`duration_days` jest definicją produktu dla **przyszłych** aktywacji. Gdy produkt ma już InventoryEntry, duration nie może być nadpisana w sposób zmieniający historyczne znaczenie. Każda Activation snapshotuje użyty czas trwania.

## 17.2 `license_product_language_capabilities`

Wersjonowana historia wsparcia językowego produktu, zamiast bezhistorycznego `license_product_languages`:

- `id uuid PK`
- `license_product_id uuid not null FK license_products`
- `language_code varchar(16) not null FK languages`
- `enabled_at timestamptz not null`
- `disabled_at timestamptz null`
- `created_at timestamptz not null`.

Partial unique:
`UNIQUE(license_product_id,language_code) WHERE disabled_at IS NULL`.

Candidate key `UNIQUE(id,language_code)` wspiera exact Assignment snapshot FK.

`enabled_at` jest immutable; `disabled_at` może przejść tylko `NULL -> non-NULL`. Normalny hard-delete i re-enable starego row in-place są zabronione; ponowne wsparcie tworzy nowy capability row. Globalny `languages` ani pojedyncza lista z UI nie jest dowodem product support.

Retirement capability:
- blokuje nowe Assignment po `disabled_at`,
- nie revoke'uje retroaktywnie już przypisanych praw,
- nie blokuje późniejszej Activation unit przypisanego, gdy capability było ważne w `assigned_at`.

## 17.3 `license_inventory_entries`

Jeden row = jedna jednostka licencji:

- `id uuid PK`
- `organization_id uuid not null`
- `license_product_id uuid not null FK license_products`
- `source_order_item_id uuid null` — exact commerce tenant boundary domykamy w DB4_9,
- `status varchar(32) not null` — `available|assigned|consumed|expired|adjusted`
- `granted_at timestamptz not null`
- `created_at timestamptz not null`.

Candidate key `UNIQUE(organization_id,id)`.

`license_product_id` jest immutable po utworzeniu InventoryEntry. Normalna ścieżka jednostki:
`available -> assigned -> consumed`, albo `assigned -> available` wyłącznie przez jawny revoke unactivated Assignment. `expired|adjusted` są stanami bez current assignment.

## 17.4 `license_assignments`

Historyczna alokacja konkretnej jednostki Inventory do dokładnego LearningAccount/Studenta:

- `id uuid PK`
- `organization_id uuid not null`
- `license_inventory_entry_id uuid not null`
- `student_id uuid not null`
- `student_learning_account_id uuid not null`
- `license_product_language_capability_id uuid not null`
- `language_code varchar(16) not null`
- `assignment_sequence bigint not null check >= 1`
- `status varchar(32) not null` — `assigned|activated|revoked_before_activation`
- `assigned_by_user_id uuid not null`
- `assigned_at timestamptz not null`
- `revoked_at timestamptz null`
- `revoked_by_user_id uuid null`
- `revoke_reason text null`
- `version bigint not null default 1 check (version >= 1)`
- `created_at timestamptz not null`.

Candidate keys:
- `UNIQUE(organization_id,id)`,
- `UNIQUE(organization_id,id,student_learning_account_id)` dla exact Activation target.

Composite same-tenant / exact-target FKs:
- `(organization_id,license_inventory_entry_id) -> license_inventory_entries(organization_id,id)`,
- `(organization_id,student_id) -> students(organization_id,id)`,
- `(organization_id,student_learning_account_id,student_id) -> student_learning_accounts(organization_id,id,student_id)`,
- `(license_product_language_capability_id,language_code) -> license_product_language_capabilities(id,language_code)`.

Assignment musi dodatkowo wskazywać capability tego samego produktu co InventoryEntry, dla tego samego języka, i capability musi obejmować `assigned_at`. To jest cross-row final-state guard.

Current assignment predicate:
`status IN ('assigned','activated') AND revoked_at IS NULL`.

Partial unique:
`UNIQUE(organization_id,license_inventory_entry_id)` dla current predicate. Historyczne revoked rows pozostają i nie blokują późniejszego reassignment tej samej jednostki.

`assignment_sequence` jest monotoniczną, bezlukową kolejnością per LearningAccount, alokowaną pod lockiem LearningAccount; unique `(organization_id,student_learning_account_id,assignment_sequence)`. „Najnowsza licencja” = `MAX(assignment_sequence)`, nie timestamp/UUID heuristic.

Existing LearningAccount assignment dziedziczy jego bieżący `language_code`; Assignment nie może po cichu zmienić języka konta. Dla nowego konta language i Assignment snapshot powstają atomowo i muszą być zgodne.

`version` jest concurrency root Assignment. `assigned_at`, actor, Student, LearningAccount, Inventory, language snapshot, capability pointer i assignment sequence są historyczne/immutable.

## 17.5 Inventory ↔ Assignment ↔ Activation final-state equivalence

Po commit:
- Inventory `available` -> zero current Assignment,
- Inventory `assigned` -> dokładnie jeden current Assignment `status='assigned'`, zero Activation,
- Inventory `consumed` -> dokładnie jeden historyczny/current Assignment `status='activated'` i dokładnie jedna Activation,
- Inventory `expired|adjusted` -> zero current Assignment.

Activated Assignment jest faktem historycznym konsumpcji jednostki, a nie odpowiedzią na pytanie „czy dostęp jest dziś aktywny”. Bieżący dostęp wynika z Activation entitlement periods.

Guard jest `DEFERRABLE INITIALLY DEFERRED` lub równoważną finalną DB boundary. Allocation serializuje się na InventoryEntry `FOR UPDATE`, więc dwa concurrent assignments tej samej jednostki dają maksymalnie jeden commit.

Create Assignment jest idempotentny. Gdy trzeba jednocześnie utworzyć LearningAccount/identity/credentials, cały flow jest **jedną outer transaction**; failure Assignment rollbackuje także nowe konto/identity/credential mutation.

Revoke przed aktywacją:
- wymaga expected Assignment version + Idempotency-Key,
- lock order `Student -> LearningAccount -> InventoryEntry -> Assignment`,
- wymaga `Assignment='assigned'`, `Inventory='assigned'`, zero Activation,
- atomowo `Assignment -> revoked_before_activation`, Inventory tej samej jednostki -> `available`, version +1, audit/outbox,
- retry nie zwalnia jednostki drugi raz,
- revoke jest dozwolony także po archive Student lub suspend LearningAccount, bo jest operacją porządkowania prawa, nie nowym access effectem.

Activate i revoke korzystają z tego samego lock order/prefiksu, więc race ma dokładnie jednego zwycięzcę.

## 17.6 `license_activations` — immutable entitlement ledger

Append-only efekt aktywacji:

- `id uuid PK`
- `organization_id uuid not null`
- `license_assignment_id uuid not null unique`
- `student_learning_account_id uuid not null`
- `entitlement_sequence bigint not null check >= 1`
- `activation_origin varchar(32) not null` — runtime `learner_self|organization_user`, migration-only `legacy_unknown`
- `activated_by_user_id uuid null`
- `activated_at timestamptz not null`
- `duration_snapshot_source varchar(32) not null` — runtime `product_at_activation`, migration-only `legacy_effect_reconstructed`
- `duration_days_snapshot integer not null check > 0`
- `expiry_before timestamptz null`
- `effective_from timestamptz not null`
- `effective_to timestamptz not null`
- `created_at timestamptz not null`.

Normalny UPDATE/DELETE Activation jest zabroniony. Exact composite FK:
`(organization_id,license_assignment_id,student_learning_account_id) -> license_assignments(organization_id,id,student_learning_account_id)`.

Unique:
- `license_assignment_id` — jedna Activation per Assignment,
- `(organization_id,student_learning_account_id,entitlement_sequence)`.

`entitlement_sequence` jest bezlukowe od 1 i alokowane pod `StudentLearningAccount FOR UPDATE`.

Canonical chaining:
- pierwsza Activation: `expiry_before=NULL`, `effective_from=activated_at`,
- jeżeli poprzedni entitlement jeszcze trwa: `expiry_before=previous.effective_to`, `effective_from=previous.effective_to`,
- jeżeli poprzedni już wygasł: `expiry_before=previous.effective_to`, `effective_from=activated_at`; nie backfillujemy przerwy,
- zawsze `effective_to = effective_from + duration_days_snapshot * 86400 seconds`.

Current entitlement end jest wyłącznie pochodne:
`MAX(license_activations.effective_to)` per LearningAccount. Nie ma niezależnej mutable kolumny expiry.

Activation command:
- Idempotency-Key + expected Assignment version,
- lock order `Student -> LearningAccount -> InventoryEntry -> Assignment -> LicenseProduct`,
- operational eligibility required,
- Assignment/Inventory muszą być `assigned` i Activation nie może istnieć,
- current LearningAccount language musi równać się Assignment snapshot language,
- capability nie musi być current teraz, ale musi być dowodliwie ważna w `assigned_at`,
- snapshotuje dodatni product duration,
- oblicza sequence/expiry/effective period,
- atomowo tworzy Activation, ustawia Assignment `activated`, Inventory `consumed`, zwiększa Assignment version raz, zapisuje audit/outbox.

Concurrent extensions tego samego LearningAccount nie tracą czasu dzięki serializacji na LearningAccount. Archive/suspend nie pauzuje ani nie przedłuża już biegnącego entitlement.

Runtime nie może wstawiać `legacy_unknown` ani `legacy_effect_reconstructed` po migration cutover.

## 17.7 Language change i management projection

Canonical current language accessu = `student_learning_accounts.language_code`. `license_assignments.language_code` jest immutable assignment-time snapshot.

Zmiana language LearningAccount wymaga:
- expected LearningAccount version,
- lock `Student -> LearningAccount`,
- operational eligibility,
- **zero pending Assignment** (`status='assigned'`),
- **zero live/future entitlement** (`Activation.effective_to > command_effective_at`).

Historyczne Assignment/Activation nie są przepisywane. Udana zmiana zwiększa LearningAccount version raz.

Management/read model jest czystą projekcją. `read_effective_at` jest pobrane raz na zapytanie. Pochodne są m.in.:
- status accessu,
- expiry,
- remaining time,
- hide-finished,
- available count = Inventory `status='available'`,
- active count = activated Assignment z Activation `effective_to > read_effective_at`,
- latest assignment = `MAX(assignment_sequence)`.

Nie utrzymujemy mutable „latest_status/count/remaining/finished” jako authority.

Bulk credential PDF lokalizuje każdy access według **bieżącego LearningAccount language**, nie historycznego Assignment snapshotu.

## 17.8 DB4_6 migration safety

Migracja nie może heurystycznie:
- przepinać cross-tenant lub wrong-Student LearningAccount/Assignment,
- merge'ować/rebindować Usera/identifiera na podstawie imienia, emaila Studenta lub wpisanego loginu,
- mapować nieznanego LearningAccount statusu,
- wywnioskować `managing_organization_id` z LearningAccount albo creatora,
- zachować legacy secret-bearing PDF jako zwykły FileAsset ani udawać, że był non-secret,
- fabrykować Inventory/Assignment/Activation state,
- zgadywać activation order, duration, origin lub effective time,
- wyznaczać assignment order z timestamp tie lub UUID,
- wywnioskować product-language capability z globalnego słownika lub jednego ekranu.

Niejednoznaczny legacy state = FAIL + reviewed remediation. Najpierw prechecks/remediation, potem candidate keys/composite FKs, credential authority, capability history, sequences i final-state guards; dopiero po dowodliwym backfillu włączamy runtime constraints.

---

# 18. Internal exams

Canonical bounded-context source: `specs/database/internal-exams.yml` (`DB-EXAM-001..008 = PASS`). Ta sekcja zastępuje wcześniejszy provisional model. Nie wolno wdrażać równolegle starego `token_hash`, `station_key_hash`, stanu Inventory `released|adjusted`, timestamp-only latest ani generic mutable evidence jako drugiego authority.

## 18.1 Formal requirement i capability

Formalny `internal_exam_attempt` zawsze należy do trwałego `student_id + course_enrollment_id`. Exact tenant/course/student/category jest chroniony composite FK, a Attempt zapisuje niezmienny basis decyzji formalnej:
- `training_requirement_profile_id`,
- `requirements_revision`,
- `internal_exam_capability_id`,
- `requirement_basis_snapshot jsonb`.

Tworzenie Attemptu rozpoczyna się od `CourseEnrollment FOR UPDATE`. Po locku system wymaga current RequirementProfile o tej samej revision oraz odpowiedniej flagi `internal_theory_exam_required` albo `internal_practical_exam_required`. Kategoria pochodzi z CourseEnrollment; request nie może jej nadpisać.

Globalny versioned `internal_exam_capabilities` ma co najmniej: category, exam part, language, `enabled_at`, nullable `disabled_at`, source reference i timestamps. Current support = `disabled_at IS NULL`; partial unique dotyczy `(driving_category_id, exam_part, language_code)`. Sama obecność języka w globalnym słowniku nie oznacza wsparcia egzaminu.

Późniejsza zmiana RequirementProfile lub retirement capability nie przepisuje historycznego Attemptu. Kurs zwolniony z teorii nie może dostać nowego formalnego theory Attemptu.

## 18.2 Inventory, Reservation i append-only ledger

### `internal_exam_inventory_entries`

Jedna tabela = jedna konkretna jednostka egzaminowa i jej provenance. Current states:
- `available`,
- `reserved`,
- `consumed`,
- `adjusted_out`.

Source types:
- `free`,
- `paid`,
- `adjustment`.

`released` jest przejściem Reservation/Ledger, nie bieżącym stanem Inventory. Mutable licznik dostępnych egzaminów nie jest authority.

### `internal_exam_reservations`

Statusy:
- `reserved`,
- `released`,
- `consumed`.

Partial unique zapewnia co najwyżej jedną aktywną rezerwację per InventoryEntry i per Attempt oraz co najwyżej jedną consumed Reservation per Attempt. Released history pozostaje immutable; późniejsze użycie jednostki tworzy nową Reservation zamiast otwierania starej.

### `internal_exam_inventory_ledger_entries`

Canonical accounting history jest append-only. Każdy event ma `event_sequence` unikalny i ciągły per `(organization_id, internal_exam_inventory_entry_id)` przydzielany pod lockiem jednostki.

Runtime events:
- `unit_granted`,
- `unit_adjustment_granted`,
- `unit_reserved`,
- `unit_released`,
- `unit_consumed`,
- `unit_adjusted_out`.

`migration_baseline` jest dozwolony wyłącznie przy kontrolowanym cutover i nie może być emitowany runtime po migracji.

Formalny Attempt rezerwuje dokładnie jedną jednostkę w transakcji create. Start konsumuje ją dokładnie raz. Revoke/expire/cancel przed startem zwalnia rezerwację dokładnie raz. Technical abort po starcie nie przywraca jednostki; refund to osobny dodatni adjustment zachowujący pierwotną consumed history. Kolejność free-vs-paid nie jest hardcodowanym constraintem DB. Payment/grant trigger pozostaje DB4_9.

## 18.3 Attempt lifecycle i concurrency

### `internal_exam_attempts`

Canonical statuses:
- `created`,
- `in_progress`,
- `passed`,
- `failed`,
- `technical_abort`,
- `invalidated`.

Attempt ma `version bigint >= 1` i jest głównym concurrency rootem dla commandów wpływających na próbę. State/timestamp matrix wymusza zgodność `started_at`, `finished_at`, `technical_aborted_at`, `invalidated_at`. Normalny hard-delete oraz reopen terminalnego Attemptu są zabronione; repeat exam = nowy Attempt.

`internal_exam_attempt_lifecycle_events` jest append-only i wiąże materialną wersję Attemptu z transition eventem. Candidate snapshot może być edytowany tylko w `created`, przed startem, z `If-Match`/expected version.

### `internal_exam_accesses`

Canonical statuses:
- `draft`,
- `ready`,
- `delivered_or_assigned`,
- `opened`,
- `started`,
- `completed`,
- `cancelled`,
- `expired`,
- `revoked`,
- `technical_abort`,
- `invalidated`.

Launch modes:
- `remote_link`: `station_id IS NULL`, `expires_at IS NOT NULL`,
- `local_current_workstation`: Station server-resolved, bez expiry,
- `assigned_exam_station`: exact Station required, bez expiry.

Mode i Station binding są immutable per Access. Co najwyżej jeden nonterminal Access i co najwyżej jeden Access ever-started na Attempt; terminalna historia prestart może zawierać wiele Access rows. `internal_exam_access_lifecycle_events` jest append-only.

Canonical shared lock prefix to `Attempt FOR UPDATE -> Access FOR UPDATE`. Gdy zmienia się Reservation/Inventory, suffix pozostaje `Reservation FOR UPDATE -> InventoryEntry FOR UPDATE`.

Start, revoke/expire/cancel oraz submit/abort/invalidate re-checkują preconditions po lockach. Races mają jednego zwycięzcę. Submit nie konsumuje Inventory drugi raz; technical abort i invalidation nie przywracają consumed unit.

## 18.4 Purpose-scoped remote tokens

`internal_exam_access_tokens` zastępuje pojedynczy `Access.token_hash` jako canonical bearer-token authority.

Purposes:
- `exam_execution`,
- `finished_result_read`.

Tokeny są tylko dla `remote_link`. Raw secret ma co najmniej 256 bitów entropy i nigdy nie jest przechowywany odwracalnie. DB przechowuje keyed one-way verifier (HMAC-SHA-256 albo równoważny), a key/pepper pozostaje poza DB. Token jest exact-bound do organization + Access + Attempt + purpose; ID ani lookup locator sam nie autoryzuje.

Resend używa tego samego Access/Reservation, rotuje execution secret i odwołuje poprzedni. Idempotency retry nie mintuje ponownie i nie odtwarza raw secret. Po submit execution token jest revokowany, a osobny finished-result token może czytać wyłącznie historyczny Result/Questions; nie może startować, submitować ani pobierać staff-only PDF. Technical abort nie mintuje result tokenu. Invalidation revokuje bieżące bearer tokens bez kasowania evidence.

Raw token nie trafia do audit/outbox/log/idempotency snapshot.

## 18.5 Station identity, credentials i Session failover

### `exam_stations`

Station jest logiczną tożsamością stanowiska. Persisted administrative state to tylko:
- `enabled`,
- `disabled`.

Online/offline jest derived z current authenticated credential + heartbeat freshness. Free/occupied jest derived z aktywnej `internal_exam_station_session`. Efektywnie available = enabled + online + current credential + free.

### `exam_station_credentials`

Rotating credential history z nieodwracalnym keyed verifierem; raw Station secret nie jest recoverable ani logowany. Rotacja revokuje stare credential, ale nie kończy aktywnej Session. Disable revokuje current credential i blokuje nowe działania, lecz nie wykonuje ukrytego auto-end, transfer ani refund.

### `internal_exam_station_sessions`

Session przechowuje exact organization/Attempt/Access/Station, `session_sequence`, optional predecessor, `started_at`, nullable `ended_at` i end reason:
- `exam_completed`,
- `technical_abort`,
- `invalidated`,
- `transferred`.

Partial unique: najwyżej jedna aktywna Session per Station i per Attempt. Sequence jest ciągły od 1 dla Attemptu; transfer chain jest liniowy, bez branch/cycle.

`Access.station_id` pozostaje immutable prestart launch/assignment binding. Po starcie bieżąca runtime Station pochodzi z aktywnej StationSession.

Failover używa tego samego Attemptu i Accessu, nie zmienia `Access.station_id`, nie tworzy Reservation ani Inventory effect i nie konsumuje ponownie. Reconnect tej samej Station używa istniejącej aktywnej Session. Offline/disabled Station nie kończy ani nie transferuje automatycznie egzaminu. Submit/technical-abort/invalidation domykają matching active Session atomowo.

## 18.6 Immutable exam definition, questions i Result

### `internal_exam_definitions`

Globalny immutable katalog versioned definicji dla category + exam part + language + engine kind (`question_test|non_question_assessment`). Definicja zawiera `composition_snapshot`, `scoring_policy_snapshot`, schema/version, content hash, publish/retire timestamps. Current row ma partial unique dla swojego scope.

Definicja jest wybierana i zamrażana **przy faktycznym Start**, przed możliwością odpowiedzi. Attempt zapisuje exact definition ID/version/hash/evidence schema i, dla testu pytaniowego, `question_set_hash`.

Zaobserwowane 32 pytania i 74 pkt nie są globalnymi stałymi DB; liczebność i scoring wynikają z frozen definition.

### `internal_exam_attempt_questions`

Dla `question_test` Start materializuje exact ordered evidence: ordinal/group, source provenance, schema-versioned `question_snapshot`, `question_snapshot_hash`, immutable media evidence/hash, `max_points_snapshot`. Treść/media/max-points są immutable od Start. Submit zapisuje finalne `candidate_answer`, `is_correct`, `points_awarded`, `answered_at` jako write-once fields.

Finished review nie używa current QuestionBank ani mutable current media jako history authority.

### `internal_exam_results`

Dokładnie jeden immutable Result dla `passed|failed`. Scoring jest wykonywany server-side według frozen policy. Dla question-test:
- `score = SUM(points_awarded)`,
- `max_score = SUM(max_points_snapshot)`,
- `passed` odpowiada frozen scoring policy.

Result zachowuje scoring policy snapshot, optional threshold, question-set hash, result snapshot/hash oraz `evidence_bundle_hash`. Invalidation po finish nie przelicza i nie nadpisuje Question/Result evidence.

## 18.7 Versioned document template i answer-sheet evidence

### `internal_exam_document_templates`

Globalny immutable katalog zawiera document type, optional exam part, template version, renderer version, template content hash oraz half-open effective interval `[effective_from,effective_to)`. Zakresy obowiązywania dla tego samego scope nie mogą się nakładać, a użytej wersji nie wolno retroaktywnie przepisywać.

### `internal_exam_documents`

Canonical document wiąże exact Attempt, template ID/version/renderer/hash, `evidence_bundle_hash`, same-tenant ready FileAsset, `content_hash`, generation metadata. Jeden canonical document per `(organization_id, attempt_id, document_type)`.

Answer sheet jest deterministycznie renderowany z frozen Attempt/Result/Question evidence + frozen template. Powtórne pobranie używa istniejącego assetu albo zweryfikowanej byte-identical regeneracji. Aktualny profil Studenta, current QuestionBank i current template nie mogą zmienić historycznego dokumentu. In-place edit finished evidence jest zabroniony; formalna korekta wymaga jawnej invalidation + nowego Attemptu albo przyszłego append-only correction artifact.

## 18.8 Deterministic management projection

Panel zarządzania nie ma mutable summary authority. Canonical grain projekcji:

`(organization_id, course_enrollment_id, exam_part)`.

Kontekst istnieje, gdy current RequirementProfile wymaga tej części albo istnieje historyczny Attempt. Niewymagana część bez historii nie projektuje fałszywego `not_assigned`.

Każdy Attempt dostaje immutable `course_attempt_sequence bigint >= 1`, unikalny per organization+CourseEnrollment, wspólny dla wszystkich exam parts tego kursu. Sequence jest przydzielany w create transaction pod istniejącym `CourseEnrollment FOR UPDATE`, ciągły dla committed runtime attempts i rollbackuje razem z failed create/reservation.

Latest dla exact course+part = Attempt z największym `course_attempt_sequence`. Timestamp, UUID ani wyświetlana minuta nie są latest authority.

Publiczne statusy panelu są derived:
- brak Attemptu w wymaganym kontekście -> `not_assigned`,
- latest `created|in_progress|technical_abort|invalidated` -> `not_conducted`,
- latest `failed` -> `failed`,
- latest `passed` -> `passed`.

`exam_count` obejmuje wszystkie Attempty. Pass-rate = `passed / (passed + failed)`; pozostałe statusy nie wchodzą do denominatora, a brak valid conducted attempts daje `NULL`, nie sztuczne 0%. Aggregate pass-rate jest ratio of sums, nie średnią procentów.

`hide_finished=true` ukrywa tylko projected `passed|failed`; nie ukrywa expanded history. Search używa current imienia, nazwiska, emaila i loginu, nie PESEL ani finished candidate snapshot. Wiele kategorii i statusów łączy się OR we własnym wymiarze, różne wymiary AND. Każde sortowanie ma stabilny tie-breaker `(course_enrollment_id ASC, exam_part ASC)`; nullable latest fields są `NULLS LAST`. Expanded history ma `course_attempt_sequence DESC`.

## 18.9 Canonical transaction lock orders

- create Attempt: `CourseEnrollment FOR UPDATE`, potem current requirement/capability validation, sequence allocation i exact Inventory reservation w jednej outer transaction,
- remote Start: `Attempt -> Access -> Definition FOR SHARE -> Reservation -> InventoryEntry`,
- local/assigned Start: `Attempt -> Access -> Definition FOR SHARE -> target Station -> current StationCredential FOR SHARE -> Reservation -> InventoryEntry`,
- failover: `Attempt -> exact started Access -> active StationSession -> old+target Stations sorted by UUID -> target current credential FOR SHARE`,
- submit/abort: `Attempt -> exact started Access`; final Question/Result/Attempt/Access i optional StationSession close commitują atomowo,
- invalidate: `Attempt -> exact ever-started Access`, expected version + reason, bez kasowania evidence i bez Inventory restore.

Każdy command re-checkuje preconditions po lockach. Start materializuje definition/question evidence oraz konsumuje dokładnie raz w tej samej outer transaction; failure evidence/session powoduje rollback całego Start.

## 18.10 Same-tenant constraints i history preservation

Tenant-owned FK muszą być composite tam, gdzie child powtarza `organization_id`. Kluczowe exact-target boundaries obejmują Attempt->Course/Student/Category/RequirementProfile, Reservation->Attempt/Inventory, Access->Attempt/optional Station, StationSession->Attempt/Access/Station, Question/Result/Document->Attempt i Document->FileAsset.

Formalnych Attemptów, wyników, ledger events, lifecycle events, sessions i dokumentów nie hard-delete. `ON UPDATE/DELETE RESTRICT` lub odpowiedni history-preserving guard jest domyślną granicą dla formalnych relacji.

## 18.11 Migration safety

DB4_7 migruje fail-closed. Zabronione jest automatyczne zgadywanie:
- cross-tenant/wrong-target przypisań,
- historycznego RequirementProfile/capability z current stanu,
- Inventory/Reservation/Ledger kolejności z UUID lub niejednoznacznych timestampów,
- lifecycle winner/order,
- purpose starego token hash lub raw secret,
- Station credential/session/transfer winner,
- question/media revision, scoring threshold i template history,
- punktów/odpowiedzi tak, aby sztucznie wyrównać Result,
- `course_attempt_sequence` przez UUID, display minute, status albo Result.

Dozwolone są wyłącznie deterministyczne transformacje z kompletnego, udowodnionego legacy evidence. Ambiguity = migration FAIL + reviewed remediation.

DB4_8 provider lifecycle, DB4_9 payment/grant trigger oraz DB4_10 final audit/outbox physical shape nie są rozwiązywane w tym sync.

---

# 19. Platform commerce

## `orders`

- `id uuid PK`
- `organization_id uuid FK`
- `status varchar(32)`
- `total_amount_minor bigint`
- `currency char(3)`
- `created_by_user_id uuid`
- `created_at`
- `updated_at`.

## `order_items`

- `id uuid PK`
- `order_id uuid FK`
- `product_type varchar(64)`
- `product_reference uuid/null`
- `quantity integer`
- `unit_amount_minor bigint`
- `vat_rate numeric(5,2)`
- `total_amount_minor bigint`
- `snapshot jsonb`.

## `payments`

- `id uuid PK`
- `organization_id uuid FK`
- `order_id uuid FK`
- `provider varchar(32)`
- `provider_payment_id varchar(128) null`
- `status varchar(32)`
- `amount_minor bigint`
- `currency char(3)`
- `created_at`
- `confirmed_at timestamptz null`.

Unique `(provider,provider_payment_id)` where not null.

## `payment_events`

Immutable:
- `id uuid PK`
- `payment_id uuid FK`
- `provider varchar(32)`
- `provider_event_id varchar(128)`
- `event_type varchar(64)`
- `payload_hash char(64)`
- `received_at timestamptz`
- `processed_at timestamptz null`.

Unique `(provider,provider_event_id)`.

## `service_entitlements`

- `id uuid PK`
- `organization_id uuid FK`
- `service_type varchar(64)`
- `source_order_item_id uuid null FK order_items`
- `source_grant_reference varchar(128) null`
- `activation_mode varchar(32)` — `immediate|explicit`
- `status varchar(32)` — `granted|available|activated|expired|revoked`
- `granted_at timestamptz`
- `expires_at timestamptz null`
- `created_at`.

## `service_activations`

- `id uuid PK`
- `organization_id uuid FK`
- `service_entitlement_id uuid unique FK service_entitlements`
- `activated_by_user_id uuid null`
- `activated_at timestamptz`
- `effective_from timestamptz`
- `effective_to timestamptz null`
- `created_at`.

Dla `activation_mode=explicit` payment success nie jest aktywacją.

---

# 20. Audit / activity / outbox / notifications

## `audit_logs`

Append-only:
- `id uuid PK`
- `organization_id uuid null`
- `actor_user_id uuid null`
- `action varchar(128)`
- `entity_type varchar(128)`
- `entity_id varchar(128) null`
- `before_redacted_json jsonb null`
- `after_redacted_json jsonb null`
- `reason text null`
- `request_id varchar(64)`
- `ip_hash varchar(128) null`
- `user_agent varchar(512) null`
- `created_at`.

Dla membership authorization mutation audit musi dodatkowo zawierać logicznie `membership_version before/after` i `authorization_version before/after` w bezpiecznym domain diff/snapshot.

Learning credential/license audit jest również redacted: może przechowywać identyfikatory row, statusy, credential version i effective periods, ale nigdy plaintext password, password hash ani secret-bearing PDF bytes.

## `organization_activity_events`

- `id uuid PK`
- `organization_id uuid FK`
- `event_type varchar(128)`
- `actor_user_id uuid null`
- `subject_type varchar(128) null`
- `subject_id varchar(128) null`
- `related_student_id uuid null`
- `safe_payload jsonb not null`
- `occurred_at timestamptz`
- `source_event_id varchar(128) null`
- `created_at`.

## `outbox_messages`

- `id uuid PK`
- `aggregate_type varchar(128)`
- `aggregate_id varchar(128)`
- `event_type varchar(128)`
- `payload jsonb`
- `request_id varchar(64)`
- `created_at`
- `published_at timestamptz null`
- `attempts integer default 0`
- `last_error text null`.

Membership permission/scope/status/Owner mutation zapisuje outbox w tej samej transakcji co current state + audit. Learning-access/license mutation również zapisuje tylko bezpieczne metadata i commituję audit/outbox atomowo z biznesowym stanem.

## `notifications`

- `id uuid PK`
- `organization_id uuid FK`
- `user_id uuid null`
- `type varchar(64)`
- `payload jsonb`
- `read_at timestamptz null`
- `created_at`.

---

# 21. Foreign-key delete policy

Preferowane:
- `RESTRICT` dla danych formalnych/finansowych i membership identity,
- `SET NULL` tylko dla optional relation, gdy historia nadal ma sens,
- `CASCADE` tylko dla technicznych child rows bez samodzielnej wartości historycznej.

`organization_memberships` nie jest hard-delete. Suspend/revoke są lifecycle state changes.

Nigdy cascade-delete z `Student` do CourseEnrollment, ExamAttempt, payment, PKK operation history, StudentLearningAccount ani LicenseAssignment. Nigdy cascade-delete z `CourseEnrollment` do `pkk_profiles`, PKK operations, training hour ledger, internal exam attempts ani student charges. License Assignment/Activation oraz access-handoff audit pozostają historyczne i nie są usuwane normalnym lifecycle.

---

# 22. Indeksy tenantowe i query-driven indexing

Minimum pod obserwowane query:
- organization_memberships: `(organization_id,status)`, `(user_id,status)`,
- membership_permissions: `(membership_id,permission_code)`,
- membership_permission_scopes: `(membership_id,permission_code)`,
- auth_sessions: `(user_id,revoked_at,last_seen_at)` i lookup po `organization_membership_id`,
- user_password_management: lookup po `(management_mode,managing_organization_id)` oraz PK `user_id`,
- students: archived + created/name/search projection,
- student_learning_accounts: `(organization_id,student_id)`, `(organization_id,status)`, `user_id`, `auth_login_identifier_id`, language,
- student_access_handoffs: `(organization_id,student_learning_account_id,created_at)`, batch+ordinal/account,
- student_access_export_batches: organization + created_at/requester,
- staff: archived + name + document expiry,
- vehicles: archived + registration + document expiry,
- locations: archived + type,
- course_enrollments: student + stage + start + version/requirements revision,
- course lifecycle events: Course + version/time,
- requirement profiles: Course + current/revision,
- recognized external: Course + part + current role,
- training sessions: Course + status/time oraz Instructor/Vehicle/Location + time pod calendar projection,
- training ledger: Course + part + Session/source,
- pkk profiles: Course + current/revision,
- calendar_events: `(organization_id,starts_at,ends_at)`, `(organization_id,instructor_id)`, pozostałe resource filters,
- calendar_event_lifecycle_events: `(organization_id,calendar_event_id,event_version_after)` + occurred_at,
- availability_slots: `(organization_id,status,starts_at,ends_at)`, `(organization_id,instructor_id)`, booked Student/link lookups,
- availability_slot_lifecycle_events: `(organization_id,availability_slot_id,slot_version_after)` + occurred_at,
- calendar_resource_claims: owner lookup `(organization_id,claim_owner_kind,claim_owner_id)` plus indeksy wspierające cztery GiST exclusion constraints,
- training_session_calendar_details: `(organization_id,training_session_id)` unique lookup,
- student_charges/payments: student + date/status,
- license products/capabilities: product code oraz `(license_product_id,language_code,disabled_at)`,
- license inventory: `(organization_id,license_product_id,status)`,
- license assignments: Inventory current lookup, LearningAccount + `assignment_sequence`, Student/status, capability pointer,
- license activations: Assignment unique, LearningAccount + `entitlement_sequence`, LearningAccount + `effective_to`,
- exam attempts: course/student/date/status/language/category,
- activity events: organization + occurred_at,
- orders: organization + status/date.

---

# 23. Obowiązkowe migration/invariant tests

Identity / Tenant / RBAC:
- role template nie jest runtime authorization source,
- future permission dla istniejącego membership domyślnie DENY,
- granted permission bez legalnego scope = DENY,
- unsupported permission/scope pair jest odrzucone,
- `organization` scope nie omija cross-tenant validation,
- `own` bez canonical owner relation = DENY,
- assigned scope bez aktywnego staff linku = pusty zbiór,
- session User A + membership User B jest odrzucone przez composite FK,
- null membership jest dozwolone dla global/pre-tenant session, ale tenant request = DENY,
- membership `user_id` jest immutable,
- Owner marker sam nie daje permissions,
- Owner bez protected baseline jest consistency failure,
- self-promotion/self-grant/self-scope-broadening są odrzucone,
- actor nie deleguje permission/scope ponad własny grant ceiling,
- transakcja zostawiająca zero active Ownerów jest odrzucona,
- concurrent Owner demotions nie mogą oba przejść, jeśli wynik byłby zero Ownerów,
- Owner transfer jest atomowy,
- stale membership `version` nie może nadpisać nowej konfiguracji,
- membership mutation zwiększa `version` dokładnie raz,
- authorization-affecting mutation zwiększa `authorization_version` dokładnie raz,
- suspend nie autoryzuje mimo zachowanych permission rows,
- revoke pozostawia zero granted permissions/scope rows,
- revoked reactivation wymaga fresh provisioning i nie przywraca Ownera,
- suspend/revoke czyści wszystkie session tenant contexts dla membership w tej samej transakcji,
- suspend/revoke jednego OSK nie usuwa membershipów tego samego Usera w innych OSK,
- permission revoke przy aktywnym membership jest skuteczny najpóźniej przy następnym autoryzowanym request bez obowiązkowego logoutu,
- audit + outbox commitują atomowo z membership mutation.

Staff / Locations / Vehicles DB4_3:
- StaffMembershipLink cross-tenant Staff↔Membership jest odrzucony przez composite FK,
- active StaffMembershipLink do archived Staff jest zabroniony,
- race Staff archive ↔ create link nie może commitować sprzecznego stanu,
- Staff↔Location oraz Vehicle↔Location cross-tenant assignment jest odrzucony,
- private Staff/Vehicle attachment wymaga same tenant + dokładnego purpose + `ready`,
- race asset-status ↔ attachment nie może ominąć ready validation,
- StaffDocument/VehicleDocument ma najwyżej jeden current row per parent/type,
- document replacement zachowuje historię, clear nie hard-delete'uje, superseded business fields są immutable,
- superseded dokument nie emituje bieżącego expiry/important-date input,
- archived Staff nadal blokuje duplikat PESEL w tym samym OSK,
- archived Vehicle nadal blokuje duplikat VIN w tym samym OSK,
- dwa nonarchived Vehicle z tym samym numerem rejestracyjnym w jednym OSK są odrzucone,
- archive Vehicle zwalnia numer tylko dla current fleet i zachowuje historyczną wartość,
- restore Vehicle z zajętym aktualnie numerem daje conflict,
- archive zwykłego non-owner Staff atomowo kończy link, suspenduje active membership i czyści tenant session contexts,
- archive Staff powiązanego z Ownerem kończy staff link, ale nie demotuje/suspenduje/revoke'uje Ownera ukrytym side-effectem,
- staff-derived `own/assigned_locations/assigned_students` po archive = empty/deny,
- restore Staff nie przywraca automatycznie panel access ani starych sesji,
- jawny panel restore tworzy nowy StaffMembershipLink i respektuje suspended/revoked membership lifecycle.

Students / Courses / Training DB4_4:
- pre-course Student może istnieć bez kompletnej formal identity, ale Course create jest odrzucony do czasu spełnienia PESEL lub explicit no-PESEL + birth date,
- `no_pesel_declared=true` wymaga birth date i braku PESEL pair,
- Student PESEL ciphertext/hash pair jest atomowa i duplicate same-organization jest odrzucony również przy archived row,
- dwa Student mutations z tym samym expected version nie mogą oba commitować,
- Course create vs Student archive nie może pozostawić archived Student + nowy active Course,
- wszystkie same-tenant Student/Course/Training relacje odrzucają cross-tenant reference w DB,
- Course terminal timestamps są mutually exclusive, a `training_completed` nie może rozjechać się z `completed_at`,
- każdy material Course version ma dokładnie jeden append-only lifecycle event,
- normalny restore otwiera tylko cancelled Course i nie może aktywować go pod archived Student,
- current RequirementProfile jest dokładnie jeden i ma revision równą Course `requirements_revision`,
- rule-set version wskazuje immutable hashed artifact,
- requirement source fact change nie może commitować bez nowego current profile,
- exemption/override replacement zachowuje wcześniejszą historię,
- Attendance jest exact Session Course + exact Course Student i jest maksymalnie jeden per Session,
- completed verified present Session ma dokładnie jeden base credit,
- completed absent, planned i cancelled Session mają zero base credit,
- duplicate base credit per Session jest odrzucony,
- correction/reversal source jest same tenant + same Course + same training part,
- ten sam source nie może być reversed dwa razy; reversal-of-reversal jest zabroniony,
- declared Course hours nie zwiększają formalnego Ledger bez wpisu ledgerowego,
- course-form external projection ma najwyżej jeden current row per Course+part,
- documented external transfers są addytywne, a replacement correction nie double-countuje source i successor,
- current external row nie przeżywa category/training-type change bez revalidation,
- combined current-OSK + external minutes są deterministyczne per training part,
- Course create nie może commitować bez dokładnie jednego current PKK identity profile,
- current PKK profile ma ciphertext + HMAC lookup hash; plaintext nie jest persistowany,
- PKK replacement zachowuje prior profile i finalnie pozostawia dokładnie jeden current,
- category/training-type change nie może commitować ze stale PKK context,
- DB4_4 nie hard-blockuje identycznego PKK hash na dwóch Course bez zweryfikowanej provider/legal duplicate policy,
- DB4_4 migration nie zgaduje no-PESEL branch, requirement context, external lineage ani PKK identity/history z niepełnego legacy evidence.

Calendar DB4_5:
- manual CalendarEvent z cross-tenant Student/Instructor/Vehicle/Location jest odrzucony przez DB,
- saved Location i custom meeting place nie mogą być równocześnie aktywne; custom text jest trimmed/nonblank,
- `important_date` pozostaje source projection i nie może być manual CalendarEvent row,
- half-open interval pozwala temu samemu zasobowi zakończyć o 11:00 i zacząć nową rezerwację o 11:00,
- overlap tego samego Student/Instructor/Vehicle/managed Location w tym samym OSK jest odrzucony przez GiST,
- ten sam resource w innym OSK nie konfliktuje,
- nieznany claim owner kind jest odrzucony,
- scheduled general event ma exact claim set, terminal general event ma zero claims,
- current CalendarEvent version ma dokładnie jeden matching lifecycle history row,
- dwa CalendarEvent mutations z tym samym expected version nie mogą oba commitować,
- `own` wymaga active StaffMembershipLink do target instructor; creator nie jest owner shortcut,
- AvailabilitySlot state/booking fields/version/history są spójne,
- dwa concurrent bookingi tego samego slotu dają maksymalnie jeden commit,
- booking konfliktujący z general event rollbackuje cały booking,
- booked slot ma exact booking claims dopóki nie zostanie sformalizowany,
- cancelled slot ma zero claims i nie jest automatycznie republished,
- planned TrainingSession ma exact Course Student + Instructor + optional Vehicle/Location claims,
- TrainingSession konfliktuje z general event, booking i innym Session dla tego samego zasobu/overlap,
- TrainingSession claim insert nie tworzy Attendance ani training credit,
- completed/cancelled TrainingSession ma zero resource claims,
- practical TrainingSession projektuje się jako `driving_lesson` bez CalendarEvent copy,
- companion zachowuje optional name/custom place bez duplikowania schedule fields,
- TrainingSession saved Location i companion custom place nie mogą być równocześnie aktywne,
- formal lesson calendar mutation wymaga training permission, nie samego calendar.manage,
- Calendar complete nigdy nie omija DB-TRN-006,
- formalization wymaga explicit lub jednoznacznego aktywnego Course i nigdy nie wybiera latest/first,
- formalization atomowo przenosi claim owner Slot -> TrainingSession bez duplicate reservation,
- formalized slot ma TrainingSession link, zero booking claims i zero drugiego calendar item,
- formalized slot nie jest samodzielnie anulowany, a linked Session terminal state nie republishuje go,
- legacy driving_lesson CalendarEvent nie jest heurystycznie mapowany do TrainingSession,
- DB4_5 migration nie auto-shiftuje, nie auto-canceluje, nie reassignuje i nie wybiera overlap winnera.

Licenses / Learning Access DB4_6:
- LearningAccount identifier należy do tego samego global Usera i jest current przy commit,
- login projection jest joinem z AuthLoginIdentifier, nie niezależnym persisted authority,
- ten sam global User/identifier może wspierać LearningAccount w wielu OSK; nie tworzymy tenantowego login namespace,
- LearningAccount `version>=1` i status `active|suspended` są DB-constrained,
- archived Student blokuje nowe learning-access effects bez przepisywania Assignment/Activation history i bez pauzowania entitlement,
- OSK password reset wymaga matching `organization_managed` authority, exclusive learner principal i permission/scope,
- każdy local password mutation zwiększa globalny credential version dokładnie raz,
- plaintext hasła, password hash i secret-bearing PDF nie trafiają do durable storage/audit/outbox/log/idempotency snapshot,
- secret-bearing PDF jest memory-only; późniejszy reprint hasła wymaga nowego resetu,
- bulk secret reset jest all-or-none, a duplicate LearningAccounts tego samego Usera resetują go raz,
- Assignment cross-tenant Inventory/Student/Account oraz wrong Student↔Account pair są odrzucone,
- Inventory i Assignment statusy oraz final-state equivalence są wymuszane,
- dwa concurrent assignments tej samej Inventory jednostki dają maksymalnie jeden commit,
- create new LearningAccount + identity/credentials + Assignment commitują all-or-none,
- revoke unactivated przywraca dokładnie tę samą Inventory jednostkę raz i zachowuje Assignment history,
- activate i revoke tego samego Assignment mają dokładnie jednego zwycięzcę,
- Activation wskazuje exact Assignment/LearningAccount i jest immutable append-only,
- entitlement sequence jest unique, bezlukowe i serializowane na LearningAccount,
- product duration oraz Inventory product binding są immutable po Inventory reference; Activation ma duration snapshot,
- runtime Activation ma rzeczywisty `learner_self|organization_user` origin, dodatni duration snapshot i nie używa migration-only provenance,
- extension przed expiry zachowuje pozostały czas, a activation po expiry nie backfilluje przerwy,
- dwa concurrent extensions nie gubią entitlement time,
- current entitlement end = `MAX(Activation.effective_to)`, nie mutable projection,
- product language support wymaga capability row, nie globalnego language dictionary ani pojedynczej screen listy,
- Assignment language jest immutable snapshot związanym z exact Inventory product/capability i assignment time,
- istniejące konto dziedziczy swój language i Assignment nie może go po cichu zmienić,
- language change z pending Assignment lub live/future entitlement jest odrzucony,
- capability retirement nie revoke'uje retroaktywnie wcześniej przypisanego prawa,
- Assignment sequence określa latest; timestamp/UUID heuristic jest zabroniony,
- management latest/status/count/expiry/remaining/hide-finished są derived z canonical history przy jednym `read_effective_at`,
- bulk credential PDF lokalizuje wpisy według current LearningAccount language,
- DB4_6 migration nie zgaduje tenant target, identity, password authority, Inventory state, Activation chain, Assignment order ani language capability.

Pozostałe obowiązkowe testy:
- generated synthetic IDs są UUIDv7/native uuid,
- organization contact address jest 1:1 i nie jest `locations`,
- settings projection czyta user names + primary email z canonical owners,
- organization_settings version jest >=1 i zwiększa się raz,
- PKK external login nie zmienia application login i plaintext nie jest persistowany,
- account closure nullable-scope uniqueness działa dla global i tenant,
- staff/vehicle multi-category/multi-location działa,
- exam reservation/station concurrency działa i failover nie konsumuje drugiego creditu,
- explicit service entitlement activation jest exactly-once,
- payment event dedupe jest `(provider,provider_event_id)`,
- idempotency działa osobno dla global i tenant scope,
- safe activity payload nie przepuszcza sensitive fields.

---

# 24. Kolejność migracji high-level

1. organizations/users/auth identifiers + `user_password_management` baseline + organization memberships + permissions + scope catalogs + membership permission/scope rows + sessions + account closure,
2. organization settings + company contact address + legal documents/terms acceptance,
3. dictionaries/capabilities,
4. file assets + idempotency,
5. staff/locations/vehicles + assignment tables,
6. Student identity/version + LearningAccount version/status + access handoff/export-batch metadata + same-user auth-identifier boundary,
7. CourseEnrollment lifecycle + requirement rule/context/profile/exemption/override + **local required PKK identity**,
8. TrainingSession + exact Attendance + immutable Ledger + recognized external training,
9. włączyć `btree_gist` przed materializacją finalnych calendar resource exclusion constraints,
10. Calendar: manual event/slot lifecycle history, same-tenant relations, `calendar_resource_claims`, `training_session_calendar_details`, slot→Session link/formalization i TrainingSession claim integration,
11. PKK provider operations/attempts/retry/reconciliation — DB4_8,
12. Student finance,
13. LicenseProduct language capability history + Inventory + Assignment sequences/current uniqueness + immutable Activation entitlement ledger,
14. internal exam capability history, inventory units/adjustments/ledger/reservations, Attempt/Access lifecycle+tokens, Station credentials/sessions, immutable definitions/questions/results/templates/documents and deterministic management projection,
15. orders/payments/service entitlements/activations,
16. audit/activity/outbox/notifications,
17. final partial indexes/cross-table constraints, w tym LearningAccount current-identifier, password-authority/exclusive-principal, Inventory↔Assignment↔Activation equivalence, contiguous sequences, entitlement chain i language-capability guards.

W obrębie DB4_4 migracja najpierw robi legacy prechecks/remediation, dopiero potem NOT NULL/unique/composite FK/deferrable guards. Nie wybiera „latest row” ani nie fabrykuje brakującej formalnej tożsamości, actorów, lineage, attendance czy creditów.

W obrębie DB4_5 kolejność jest równie rygorystyczna: najpierw precheck same-tenant/event-type/status/booking-state/overlap oraz jawna klasyfikacja legacy `driving_lesson`; potem lifecycle baselines i candidate keys; następnie `btree_gist`, technical claims + owner guards + exact-set guards + GiST; dopiero po dowodliwej migracji włączamy finalne constraints. Nie naprawiamy overlapów ani nie tworzymy TrainingSession przez heurystykę.

W obrębie DB4_6 najpierw precheckujemy LearningAccount identity, tenant/exact-target relacje, password authority, legacy credential artifacts, Inventory/Assignment/Activation consistency, activation order/effective periods oraz product-language evidence. Niejednoznaczności trafiają do reviewed remediation. Dopiero potem backfillujemy bezpieczne baselines/sequences/capability history i włączamy same-user/composite FK, credential authority, final-state equivalence, contiguous sequence i entitlement-chain guards. Nie zgadujemy na podstawie timestampów, UUID, creatora, ekranu ani globalnego language dictionary.

---

# 25. Zamknięte i oczekujące decyzje techniczne

Zamknięte:
- synthetic domain ID: UUIDv7 application-side -> PostgreSQL `uuid`,
- physical ownership Ustawień OSK,
- Identity/Tenant/RBAC DB4_2: materialized runtime permissions, per-permission scope, same-user composite session FK, durable membership lifecycle `active|suspended|revoked`, `is_owner` governance marker, last-owner guard, grant ceiling, `version` + `authorization_version`, atomic audit/outbox i session-context clearing na suspend/revoke,
- Staff/Locations/Vehicles DB4_3: same-tenant StaffMembershipLink i location assignments, tenant/purpose/ready FileAsset attachment boundary, versioned current-document projection, PESEL/VIN/registration lifecycle uniqueness oraz bezpieczny Staff archive/restore vs panel-access lifecycle,
- **Students/Courses/Training DB4_4**: same-tenant formal relations; Student formal identity + durable archive/version; Course lifecycle/version/history; reproducible requirement context + immutable rule set/profile history; exact verified Attendance -> exactly-once append-only Ledger; deterministic previous-OSK projection/history; atomowa, wersjonowana local course PKK identity bez wciągania provider lifecycle,
- **Calendar DB4_5**: same-tenant calendar resources; manual/system storage boundary; half-open resource conflict model z `btree_gist` i czterema partial GiST exclusion constraints; CalendarEvent lifecycle/version/history; canonical `own` przez StaffProfile; AvailabilitySlot exactly-once booking/cancel/history bez auto-reavailability; `TrainingSession` jako jedyny formal driving-lesson schedule owner; shared claims dla formalnych sesji; atomowy booked-slot -> TrainingSession handoff bez drugiego reservation fact i bez skrótu do formalnego creditu,
- **Licenses / Learning Access DB4_6**: exact same-tenant Assignment target; current same-user AuthLoginIdentifier bez niezależnej login projection; durable LearningAccount lifecycle/version i operational eligibility; fail-closed global password management authority + credential epoch; memory-only secret-bearing credential PDFs i non-secret handoff/batch metadata; Inventory↔Assignment↔Activation final-state equivalence; immutable serialized entitlement ledger z duration snapshot/sequence; versioned product-language capability; immutable Assignment language/order snapshots oraz czysto derived management projection.

Po DB4_6 nadal osobno wymagają dalszych slice/ADR:
- DB4_7 Internal Exams aggregate contract — synchronized / PASS,
- PKK provider configuration/fetch/update/return/XML/retry/reconciliation/collision policy — DB4_8,
- Student Finance i exact commerce/source-order tenant boundary — DB4_9,
- application encryption + key rotation dla PESEL/PKK/provider snapshots,
- immutable snapshot canonicalization/hash,
- auth account merge/recovery/email verification policy,
- final production legal re-verification słownika kategorii, w tym `PT`,
- exact HTTP expected-version/error-code synchronization w Stage 5.

---

# 26. Reverse-engineering compatibility rule

Schema ma wspierać wszystkie potwierdzone relacje i flow z `specs/reverse-engineering-manifest.yml`, w tym quick preview, search/filter/sort, wiele kursów, wszystkie pola godzinowe, finanse, PKK per course, staff/vehicle multi assignments, calendar resources, licencje, credential PDF, internal exams, local station concurrency/failover, dashboard activity, listę/revoke sesji, account closure i purchase entitlement activation.

Jeżeli screen spec lub canonical API wymaga capability, której aktualny schema nie potrafi zapisać, **rozszerzamy schema — nie usuwamy capability ze scope'u**.
