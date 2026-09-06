# 87. Physical database schema — PostgreSQL core v1

Data: 2026-09-06

**Status:** `IMPLEMENTATION_BLUEPRINT`

> To nie są jeszcze migracje Laravel. To fizyczny blueprint tabel, indeksów, constraintów i najważniejszych transakcji zgodny z canonical domain model. Machine-readable odpowiednik: `specs/database/core-schema.yml`. Przy konflikcie machine spec + późniejszy ADR wygrywa. Reverse-engineered scope chronią `docs/96-reverse-engineering-preservation-contract.md` i `specs/reverse-engineering-manifest.yml`. Cross-layer kompletność kontroluje `specs/traceability/core-v1.yml`.

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
- `pkk_number_ciphertext text null`,
- `pkk_lookup_hash char(64) null`.

## 2.3 Credentials/provider secrets

- sekrety integracyjne przez secret manager albo szyfrowane reference,
- plaintext po zapisie zabroniony,
- audit/log/outbox nie zawiera hasła, tokenu, pełnego secretu.

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

Imię i nazwisko są globalnymi polami tożsamości użytkownika. E-mail/login nie jest kanoniczną kolumną w `users`.

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
- `UNIQUE(organization_id,id)` jako target tenant-aware composite FK dla prywatnych Staff/Vehicle attachments.

Dla Staff/Vehicle attachment:
- prywatny asset musi należeć do tego samego `organization_id`,
- platformowy asset z `organization_id IS NULL` nie może być przypięty jako prywatne zdjęcie/dokument,
- purpose jest związany ze ścieżką attachmentu (`staff_photo`, `staff_document`, `vehicle_photo`, `vehicle_document`),
- business attachment może commitować wyłącznie dla `status='ready'`,
- reusable DB trigger/constraint trigger blokuje asset `FOR SHARE` i jest finalną granicą purpose + ready; Laravel robi wcześniejszy precheck dla UX,
- `purpose` po zakończeniu uploadu nie jest przepisywany,
- późniejszy security transition assetu do non-ready nie usuwa historycznej referencji, ale download musi ponownie sprawdzić bieżący stan assetu,
- replacement attachmentu nie kasuje automatycznie poprzedniego FileAsset.

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

Same key + different request hash w tym samym scope = conflict. One-time plaintext password/token nie trafia do snapshotu.

---

# 7. Dictionaries / capabilities

## `languages`

- `code varchar(16) PK`
- `label_key varchar(128)`
- `active boolean`
- `valid_from date null`
- `valid_to date null`.

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

# 11. Students / learning access

## `students`

- `id uuid PK`
- `organization_id uuid FK`
- `first_name varchar(120) not null`
- `last_name varchar(120) not null`
- `birth_date date null`
- `pesel_ciphertext text null`
- `pesel_lookup_hash char(64) null`
- `contact_email_normalized varchar(320) null`
- `phone varchar(40) null`
- `default_location_id uuid null FK locations`
- `archived_at timestamptz null`
- `archived_by_user_id uuid null`
- `created_at`
- `updated_at`.

Validation: branch z PESEL albo formalny branch bez PESEL + birth date.

## `student_learning_accounts`

- `id uuid PK`
- `organization_id uuid FK`
- `student_id uuid FK`
- `user_id uuid FK users`
- `primary_auth_login_identifier_id uuid FK auth_login_identifiers`
- `login_identifier_projection varchar(320) not null`
- `language_code varchar(16) FK languages`
- `status varchar(32) not null`
- `created_at`
- `updated_at`.

## `student_access_handoffs`

- `id uuid PK`
- `organization_id uuid FK`
- `student_learning_account_id uuid FK`
- `handoff_type varchar(32)`
- `generated_by_user_id uuid FK`
- `document_asset_id uuid null FK file_assets`
- `created_at`.

Nie ma kolumny plaintext password.

---

# 12. CourseEnrollment / requirements

## `course_enrollments`

- `id uuid PK`
- `organization_id uuid FK`
- `student_id uuid FK`
- `training_type varchar(32) not null`
- `driving_category_id uuid FK`
- `started_at timestamptz not null`
- `lead_instructor_id uuid FK staff_profiles`
- `location_id uuid null FK locations`
- `training_stage varchar(64) not null`
- `declared_theory_minutes integer null check >= 0`
- `declared_practical_minutes integer null check >= 0`
- `completed_at timestamptz null`
- `interrupted_at timestamptz null`
- `cancelled_at timestamptz null`
- `cancelled_by_user_id uuid null`
- `created_by_user_id uuid`
- `version integer not null default 1`
- `created_at`
- `updated_at`.

`declared_*` zachowują zaobserwowane pola jako plan/deklarację. **Nie są zaliczonym formalnym czasem.**

## `training_requirement_profiles`

- `id uuid PK`
- `organization_id uuid FK`
- `course_enrollment_id uuid FK`
- `rule_set_version varchar(64)`
- `theory_training_required boolean`
- `minimum_theory_minutes integer`
- `internal_theory_exam_required boolean`
- `practical_training_required boolean`
- `minimum_practical_minutes integer`
- `internal_practical_exam_required boolean`
- `exemption_basis_code varchar(128) null`
- `input_snapshot jsonb not null`
- `calculated_at timestamptz`
- `superseded_at timestamptz null`.

Partial unique `(course_enrollment_id)` where `superseded_at is null`.

## `course_exemption_decisions`

- `id uuid PK`
- `organization_id uuid FK`
- `course_enrollment_id uuid FK`
- `basis_code varchar(128)`
- `evidence_reference varchar(255) null`
- `reason text null`
- `approved_by_user_id uuid`
- `rule_set_version varchar(64)`
- `created_at`
- `revoked_at timestamptz null`.

## `recognized_external_training`

- `id uuid PK`
- `organization_id uuid FK`
- `course_enrollment_id uuid FK`
- `training_part varchar(32)` — `theory|practical`
- `recognized_minutes integer not null check >= 0`
- `source_kind varchar(32)` — `course_form_initial|documented_transfer|correction`
- `source_school_reference varchar(255) null`
- `evidence_reference varchar(255) null`
- `reason text null`
- `approved_by_user_id uuid`
- `created_at`
- `revoked_at timestamptz null`
- `revoked_by_user_id uuid null`
- `reversal_reason text null`.

---

# 13. Training sessions / formal hour ledger

## `training_sessions`

- `id uuid PK`
- `organization_id uuid FK`
- `course_enrollment_id uuid FK`
- `session_type varchar(32)`
- `starts_at timestamptz`
- `ends_at timestamptz`
- `duration_minutes integer`
- `instructor_id uuid FK staff_profiles`
- `vehicle_id uuid null FK vehicles`
- `location_id uuid null FK locations`
- `status varchar(32)`
- `created_by_user_id uuid`
- `version integer default 1`
- `created_at`
- `updated_at`.

Check `ends_at > starts_at`.

## `training_session_attendance`

- `training_session_id uuid FK`
- `student_id uuid FK`
- `status varchar(32)`
- `confirmed_by_user_id uuid null`
- `confirmed_at timestamptz null`.

Unique `(training_session_id,student_id)`.

## `training_hour_ledger_entries`

Immutable.

- `id uuid PK`
- `organization_id uuid FK`
- `course_enrollment_id uuid FK`
- `training_session_id uuid null FK`
- `entry_type varchar(32)` — `credit|opening_balance|correction|reversal`
- `training_part varchar(32)`
- `minutes integer not null`
- `source_entry_id uuid null`
- `reason text null`
- `actor_user_id uuid`
- `created_at`.

Formalny credited time bieżącego OSK = ledger projection.

---

# 14. PKK

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

## `pkk_profiles`

- `id uuid PK`
- `organization_id uuid FK`
- `course_enrollment_id uuid unique FK`
- `pkk_number_ciphertext text null`
- `pkk_lookup_hash char(64) null`
- `status varchar(64) null`
- `profile_snapshot_ciphertext text null`
- `profile_snapshot_redacted jsonb null`
- `fetched_at timestamptz null`
- `updated_at`.

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

---

# 15. Calendar

## `calendar_events`

- `id uuid PK`
- `organization_id uuid FK`
- `event_type varchar(32)`
- `name varchar(255) null`
- `starts_at timestamptz`
- `ends_at timestamptz`
- `student_id uuid null FK`
- `instructor_id uuid null FK staff_profiles`
- `vehicle_id uuid null FK vehicles`
- `location_id uuid null FK locations`
- `custom_meeting_place varchar(255) null`
- `status varchar(32)`
- `created_by_user_id uuid`
- `version integer default 1`
- `created_at`
- `updated_at`.

Check `ends_at > starts_at`.

Backend waliduje konflikt zasobów. Strategia DB exclusion constraint vs transaction lock finalizowana ADR.

## `availability_slots`

- `id uuid PK`
- `organization_id uuid FK`
- `instructor_id uuid null`
- `vehicle_id uuid null`
- `location_id uuid null`
- `starts_at timestamptz`
- `ends_at timestamptz`
- `status varchar(32)`
- `booked_student_id uuid null`
- `booked_at timestamptz null`
- `version integer default 1`.

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

# 17. Licenses

## `license_products`

- `id uuid PK`
- `code varchar(64) unique`
- `duration_days integer`
- `active boolean`
- `activation_mode varchar(32)`
- `metadata jsonb`.

## `license_product_languages`

- `license_product_id uuid FK`
- `language_code varchar(16) FK languages`

Unique `(license_product_id,language_code)`.

## `license_inventory_entries`

- `id uuid PK`
- `organization_id uuid FK`
- `license_product_id uuid FK`
- `source_order_item_id uuid null`
- `status varchar(32)` — `available|assigned|consumed|expired|adjusted`
- `granted_at timestamptz`
- `created_at`.

## `license_assignments`

- `id uuid PK`
- `organization_id uuid FK`
- `license_inventory_entry_id uuid FK`
- `student_id uuid FK`
- `student_learning_account_id uuid FK`
- `language_code varchar(16)`
- `status varchar(32)`
- `assigned_by_user_id uuid`
- `assigned_at timestamptz`
- `revoked_at timestamptz null`
- `revoked_by_user_id uuid null`
- `revoke_reason text null`
- `version integer default 1`.

Partial unique `(license_inventory_entry_id)` where current/not revoked.

## `license_activations`

- `id uuid PK`
- `organization_id uuid FK`
- `license_assignment_id uuid unique FK`
- `activated_by_user_id uuid null`
- `activated_at timestamptz`
- `effective_from timestamptz`
- `effective_to timestamptz`
- `created_at`.

Check `effective_to > effective_from`.

Stacking i revoke pozostają zgodne z `specs/database/core-schema.yml`.

---

# 18. Internal exams

## `internal_exam_inventory_entries`

- `id uuid PK`
- `organization_id uuid FK`
- `source_type varchar(32)` — `free|paid|adjustment`
- `source_order_item_id uuid null`
- `status varchar(32)` — `available|reserved|consumed|released|adjusted`
- `granted_at timestamptz`
- `created_at`.

## `internal_exam_attempts`

- `id uuid PK`
- `organization_id uuid FK`
- `student_id uuid FK`
- `course_enrollment_id uuid FK`
- `exam_part varchar(16)`
- `driving_category_id uuid FK`
- `language_code varchar(16)`
- `requirement_basis varchar(128)`
- `candidate_snapshot jsonb not null`
- `status varchar(32)`
- `started_at timestamptz null`
- `finished_at timestamptz null`
- `invalidated_at timestamptz null`
- `version integer default 1`
- `created_at`.

## `internal_exam_reservations`

- `id uuid PK`
- `organization_id uuid FK`
- `internal_exam_inventory_entry_id uuid FK`
- `internal_exam_attempt_id uuid FK`
- `status varchar(32)` — `reserved|released|consumed`
- `reserved_at timestamptz`
- `released_at timestamptz null`
- `consumed_at timestamptz null`
- `idempotency_key uuid null`
- `version integer default 1`.

Partial unique:
- `(internal_exam_inventory_entry_id)` where `status='reserved'`,
- `(internal_exam_attempt_id)` where `status='reserved'`.

## `internal_exam_accesses`

- `id uuid PK`
- `organization_id uuid FK`
- `internal_exam_attempt_id uuid FK`
- `mode varchar(32)` — `remote_link|local_current_workstation|assigned_exam_station`
- `token_hash char(64) null`
- `station_id uuid null FK exam_stations`
- `status varchar(32)`
- `expires_at timestamptz null`
- `revoked_at timestamptz null`
- `opened_at timestamptz null`
- `started_at timestamptz null`
- `created_at`
- `version integer default 1`.

## `exam_stations`

- `id uuid PK`
- `organization_id uuid FK`
- `name varchar(128)`
- `station_key_hash char(64) null`
- `status varchar(32)`
- `last_seen_at timestamptz null`
- `created_at`
- `updated_at`.

## `internal_exam_station_sessions`

- `id uuid PK`
- `organization_id uuid FK`
- `internal_exam_attempt_id uuid FK`
- `internal_exam_access_id uuid FK`
- `exam_station_id uuid FK`
- `started_at timestamptz`
- `ended_at timestamptz null`
- `end_reason varchar(64) null`
- `transferred_from_session_id uuid null FK self`
- `created_by_user_id uuid null`
- `created_at`.

Partial unique:
- `(exam_station_id)` where `ended_at is null`,
- `(internal_exam_attempt_id)` where `ended_at is null`.

## `internal_exam_attempt_questions`

- `id uuid PK`
- `internal_exam_attempt_id uuid FK`
- `ordinal smallint`
- `question_snapshot jsonb`
- `candidate_answer jsonb null`
- `is_correct boolean null`
- `points_awarded integer null`
- `answered_at timestamptz null`.

Unique `(internal_exam_attempt_id,ordinal)`.

## `internal_exam_results`

- `id uuid PK`
- `internal_exam_attempt_id uuid unique FK`
- `passed boolean`
- `score integer`
- `max_score integer`
- `result_snapshot jsonb`
- `created_at`.

## `internal_exam_documents`

- `id uuid PK`
- `organization_id uuid FK`
- `internal_exam_attempt_id uuid FK`
- `document_type varchar(64)`
- `asset_id uuid FK file_assets`
- `snapshot_hash char(64)`
- `created_at`.

Start transaction: lock access + attempt + reservation + inventory + station(if local), validate, consume inventory exactly once, create station session if local, audit/outbox, commit. Finish nie konsumuje ponownie.

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

Membership permission/scope/status/Owner mutation zapisuje outbox w tej samej transakcji co current state + audit.

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

Nigdy cascade-delete z `Student` do course enrollment, exam, payment ani PKK operation history. Nigdy cascade-delete z `CourseEnrollment` do PKK operations, training hour ledger, internal exam attempts ani student charges.

---

# 22. Indeksy tenantowe i query-driven indexing

Minimum pod obserwowane query:
- organization_memberships: `(organization_id,status)`, `(user_id,status)`,
- membership_permissions: `(membership_id,permission_code)`,
- membership_permission_scopes: `(membership_id,permission_code)`,
- auth_sessions: `(user_id,revoked_at,last_seen_at)` i lookup po `organization_membership_id`,
- students: archived + created/name/search projection,
- staff: archived + name + document expiry,
- vehicles: archived + registration + document expiry,
- locations: archived + type,
- course_enrollments: student + stage + start,
- calendar_events: time range + resource IDs,
- student_charges/payments: student + date/status,
- license inventory: product/status,
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

Pozostałe obowiązkowe testy:
- generated synthetic IDs są UUIDv7/native uuid,
- organization contact address jest 1:1 i nie jest `locations`,
- settings projection czyta user names + primary email z canonical owners,
- organization_settings version jest >=1 i zwiększa się raz,
- PKK external login nie zmienia application login i plaintext nie jest persistowany,
- account closure nullable-scope uniqueness działa dla global i tenant,
- staff/vehicle multi-category/multi-location działa,
- declared course hours nie zwiększają credited ledger time,
- historical license assignment reuse + one current assignment,
- concurrent license extensions nie gubią czasu,
- exam reservation/station concurrency działa i failover nie konsumuje drugiego creditu,
- explicit service entitlement activation jest exactly-once,
- payment event dedupe jest `(provider,provider_event_id)`,
- idempotency działa osobno dla global i tenant scope,
- safe activity payload nie przepuszcza sensitive fields.

---

# 24. Kolejność migracji high-level

1. organizations/users/auth identifiers + organization memberships + permissions + scope catalogs + membership permission/scope rows + sessions + account closure,
2. organization settings + company contact address + legal documents/terms acceptance,
3. dictionaries/capabilities,
4. file assets + idempotency,
5. staff/locations/vehicles + assignment tables,
6. students/learning accounts,
7. course enrollments/requirements,
8. training/calendar,
9. PKK,
10. student finance,
11. license inventory/assignment/activation periods,
12. internal exam inventory/attempt/access/stations/station sessions,
13. orders/payments/service entitlements/activations,
14. audit/activity/outbox/notifications,
15. final partial indexes/cross-table constraints.

---

# 25. Zamknięte i oczekujące decyzje techniczne

Zamknięte:
- synthetic domain ID: UUIDv7 application-side -> PostgreSQL `uuid`,
- physical ownership Ustawień OSK,
- Identity/Tenant/RBAC DB4_2: materialized runtime permissions, per-permission scope, same-user composite session FK, durable membership lifecycle `active|suspended|revoked`, `is_owner` governance marker, last-owner guard, grant ceiling, `version` + `authorization_version`, atomic audit/outbox i session-context clearing na suspend/revoke,
- Staff/Locations/Vehicles DB4_3: same-tenant StaffMembershipLink i location assignments, tenant/purpose/ready FileAsset attachment boundary, versioned current-document projection, PESEL/VIN/registration lifecycle uniqueness oraz bezpieczny Staff archive/restore vs panel-access lifecycle.

Nadal wymagają osobnego etapu/ADR przed produkcyjnymi migracjami odpowiednich modułów:
- application encryption + key rotation dla PESEL/PKK/provider snapshots,
- calendar overlap enforcement,
- immutable snapshot canonicalization/hash,
- auth account merge/recovery/email verification policy.

---

# 26. Reverse-engineering compatibility rule

Schema ma wspierać wszystkie potwierdzone relacje i flow z `specs/reverse-engineering-manifest.yml`, w tym quick preview, search/filter/sort, wiele kursów, wszystkie pola godzinowe, finanse, PKK per course, staff/vehicle multi assignments, calendar resources, licencje, credential PDF, internal exams, local station concurrency/failover, dashboard activity, listę/revoke sesji, account closure i purchase entitlement activation.

Jeżeli screen spec lub canonical API wymaga capability, której aktualny schema nie potrafi zapisać, **rozszerzamy schema — nie usuwamy capability ze scope'u**.
