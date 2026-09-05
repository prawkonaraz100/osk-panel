# 87. Physical database schema — PostgreSQL core v1

Data: 2026-09-05

**Status:** `IMPLEMENTATION_BLUEPRINT`

> To nie są jeszcze migracje Laravel. To fizyczny blueprint tabel, indeksów, constraintów i najważniejszych transakcji zgodny z canonical domain model. Machine-readable odpowiednik: `specs/database/core-schema.yml`. Przy konflikcie machine spec + późniejszy ADR wygrywa. Reverse-engineered scope chronią `docs/96-reverse-engineering-preservation-contract.md` i `specs/reverse-engineering-manifest.yml`. Cross-layer kompletność kontroluje `specs/traceability/core-v1.yml`.

---

# 1. Konwencje DB

- syntetyczny primary key i odpowiadające mu foreign keys: natywny PostgreSQL `uuid`,
- identyfikatory domenowe generowane przez naszą aplikację: **UUIDv7**, generowany przed `INSERT`; nie wolno po cichu używać UUIDv4,
- nie wymagamy DB-default do generowania UUIDv7 — dzięki temu physical schema nie zależy od wersji PostgreSQL ani rozszerzenia udostępniającego generator UUIDv7,
- zewnętrzne identyfikatory providerów/importów pozostają osobnymi polami i nie są używane jako nasze primary keys,
- czyste tabele join/dictionary mogą zachować jawnie zaprojektowany klucz naturalny/composite, jeśli nie potrzebują własnej historycznej tożsamości rekordu,
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

Decyzja UUIDv7 dotyczy naszego synthetic domain ID. Publiczne API nadal przekazuje UUID jako string, więc zamknięcie tej decyzji nie wymaga zmiany Stage-3 kontraktu HTTP.

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
- `timezone varchar(64) not null default 'Europe/Warsaw'`
- `status varchar(32) not null`
- `created_at timestamptz`
- `updated_at timestamptz`

Indexes:
- `(status)`.

## `organization_settings`

One-to-one z `organizations`.

- `organization_id uuid PK/FK`
- `default_language_code varchar(16) null`
- `preferences jsonb null` — tylko niekrytyczne UI/preferences,
- `created_at`
- `updated_at`

Krytyczne ustawienia domenowe nie trafiają bezrefleksyjnie do `preferences`; np. PKK ma własną tabelę.

## `users`

Globalna tożsamość auth.

- `id uuid PK`
- `password_hash varchar(255) null`
- `status varchar(32) not null`
- `last_login_at timestamptz null`
- `created_at`
- `updated_at`

E-mail/login nie musi być jedyną kolumną w `users`; resolver loginu jest w `auth_login_identifiers`.

## `auth_login_identifiers`

Obsługuje generic login page bez wyboru OSK.

- `id uuid PK`
- `user_id uuid FK users`
- `identifier_type varchar(32)` — `email|username`
- `identifier_normalized varchar(320) not null`
- `verified_at timestamptz null`
- `created_at`
- `revoked_at timestamptz null`

Partial unique:
- `(identifier_normalized)` where `revoked_at is null`.

Inwariant: jedna widoczna bieżąca wartość loginu rozwiązuje się do najwyżej jednego `User`.

## `auth_social_accounts`

- `id uuid PK`
- `user_id uuid FK`
- `provider varchar(64)`
- `provider_subject varchar(255)`
- `created_at`
- `revoked_at timestamptz null`

Unique:
- `(provider, provider_subject)` dla aktywnej identity.

## `auth_sessions`

Trwała projekcja/store sesji potrzebna przez `GET /auth/sessions` i revoke. Może być fizycznie oparta na własnej tabeli albo bezpiecznym frameworkowym session store, ale kontrakt musi pozostać ten sam.

- `id uuid PK`
- `user_id uuid FK users`
- `organization_membership_id uuid null FK organization_memberships`
- `token_or_framework_session_hash varchar(255) unique not null`
- `created_at timestamptz`
- `last_seen_at timestamptz null`
- `revoked_at timestamptz null`
- `revoke_reason varchar(255) null`
- `ip_hash varchar(128) null`
- `user_agent varchar(512) null`.

Nie zapisujemy raw cookie/bearer secret. Revoke jest zmianą stanu, nie kasowaniem historii bezpieczeństwa.

## `account_closure_requests`

Audytowalny workflow dla żądania zamknięcia konta.

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
- `(user_id, organization_id)` where `status='pending'`.

Żądanie nie oznacza automatycznego hard-delete danych formalnych/finansowych.

## `organization_memberships`

- `id uuid PK`
- `organization_id uuid FK`
- `user_id uuid FK`
- `status varchar(32)`
- `role_template_code varchar(64) null`
- `data_scope varchar(64) null`
- `created_at`
- `updated_at`

Unique:
- `(organization_id, user_id)`.

Jedna osoba może mieć membership w wielu OSK.

## `permissions`

- `code varchar(128) PK`
- `description varchar(255)`.

## `membership_permissions`

- `membership_id uuid FK`
- `permission_code varchar(128) FK`
- `granted boolean not null`
- `created_at`

Unique:
- `(membership_id, permission_code)`.

---

# 4. Legal documents / acceptance

## `legal_documents`

Immutable versions.

- `id uuid PK`
- `document_type varchar(64)` — np. `terms_of_service`,
- `version varchar(64)`
- `content_hash char(64)`
- `storage_asset_id uuid null FK file_assets`
- `published_at timestamptz`
- `effective_from timestamptz null`
- `created_at`

Unique:
- `(document_type, version)`.

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

Unique:
- `(organization_id, user_id, legal_document_id)`.

To pozwala UI pokazać konkretną zaakceptowaną wersję regulaminu zamiast tylko „aktualnego dokumentu”.

---

# 5. File assets

## `file_assets`

Wspólny record dla zdjęć, PDF, podpisanych XML i dokumentów.

- `id uuid PK`
- `organization_id uuid null FK` — null tylko dla platformowego/global asset,
- `storage_disk varchar(64)`
- `storage_key varchar(512) not null`
- `original_filename varchar(255) null` — sanitized display only,
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

Unique:
- `storage_key`.

Business entity przypina plik dopiero po `ready`. Storage key nie pochodzi wprost z user filename.

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

Unique:
- `(organization_id, operation_key, idempotency_key)`.

Reguły:
- claim key przed business effect,
- same key + same hash -> ten sam efekt/result,
- same key + different hash -> conflict,
- one-time plaintext password/token nie trafia do `safe_response_snapshot`.

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

Start:
- `branch`,
- `lecture_room`,
- `maneuvering_area`.

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

Unique current capability zależnie od lifecycle/versioning.

## `internal_exam_capability_languages`

- `internal_exam_capability_id uuid FK`
- `language_code varchar(16) FK languages`

Unique:
- `(internal_exam_capability_id, language_code)`.

Języki i kategorie są capability/config, nie stałą w Vue.

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
- `photo_asset_id uuid null FK file_assets`
- `archived_at timestamptz null`
- `archived_by_user_id uuid null`
- `created_at`
- `updated_at`.

Indexes:
- `(organization_id, archived_at)`,
- `(organization_id, last_name, first_name)`,
- optional partial unique `(organization_id, pesel_lookup_hash)` where not null.

## `staff_type_assignments`

- `staff_profile_id uuid FK`
- `staff_type_code varchar(64) FK`

Unique `(staff_profile_id, staff_type_code)`.

## `staff_category_assignments`

- `staff_profile_id uuid FK`
- `driving_category_id uuid FK`

Unique `(staff_profile_id, driving_category_id)`.

## `staff_location_assignments`

- `staff_profile_id uuid FK`
- `location_id uuid FK`

Unique `(staff_profile_id, location_id)`.

## `staff_membership_links`

Historyczne powiązanie profilu pracownika z membership w konkretnym OSK.

- `id uuid PK`
- `organization_id uuid FK`
- `staff_profile_id uuid FK`
- `organization_membership_id uuid FK`
- `linked_at timestamptz`
- `linked_by_user_id uuid null`
- `unlinked_at timestamptz null`
- `unlinked_by_user_id uuid null`.

Partial unique:
- `(staff_profile_id)` where `unlinked_at is null`,
- `(organization_membership_id)` where `unlinked_at is null`.

Backend sprawdza zgodność organization po obu stronach.

## `staff_documents`

- `id uuid PK`
- `organization_id uuid FK`
- `staff_profile_id uuid FK`
- `document_type varchar(64)`
- `valid_until date null`
- `document_number varchar(128) null`
- `asset_id uuid null FK file_assets`
- `created_at`
- `updated_at`.

Startowe typy z UI:
- `card_or_authorization`,
- `medical_exam`,
- `psychological_exam`.

Indexes:
- `(organization_id, document_type, valid_until)`,
- `(staff_profile_id, document_type)`.

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

Indexes:
- `(organization_id, archived_at)`,
- `(organization_id, type_code)`.

Wyszukiwany katalog miejscowości może być zewnętrznym providerem lub lokalnym słownikiem; `city_reference` zachowuje stabilny identyfikator, jeśli provider go daje.

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
- `photo_asset_id uuid null FK file_assets`
- `archived_at timestamptz null`
- `archived_by_user_id uuid null`
- `created_at`
- `updated_at`.

Unique:
- `(organization_id, registration_number_normalized)` zgodnie z finalną polityką reuse po archiwizacji,
- optional `(organization_id, vin_normalized)` where not null.

## `vehicle_documents`

- `id uuid PK`
- `organization_id uuid FK`
- `vehicle_id uuid FK`
- `document_type varchar(64)`
- `valid_until date null`
- `asset_id uuid null FK file_assets`
- `created_at`
- `updated_at`.

Typy:
- `technical_inspection`,
- `oc_insurance`,
- `ac_insurance`.

## `vehicle_category_assignments`

- `vehicle_id uuid FK`
- `driving_category_id uuid FK`

Unique `(vehicle_id, driving_category_id)`.

## `vehicle_location_assignments`

- `vehicle_id uuid FK`
- `location_id uuid FK`

Unique `(vehicle_id, location_id)`.

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

Validation:
- branch z PESEL albo formalny branch bez PESEL + birth date.

Indexes:
- `(organization_id, archived_at, last_name, first_name)`,
- `(organization_id, contact_email_normalized)`,
- optional partial unique `(organization_id, pesel_lookup_hash)` where not null.

## `student_learning_accounts`

Tenantowy kontekst dostępu edukacyjnego.

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

Login resolution canonical = `auth_login_identifiers`; projection służy tabelom/adminowi.

## `student_access_handoffs`

- `id uuid PK`
- `organization_id uuid FK`
- `student_learning_account_id uuid FK`
- `handoff_type varchar(32)`
- `generated_by_user_id uuid FK`
- `document_asset_id uuid null FK file_assets`
- `created_at`.

Nie ma kolumny plaintext password. Plaintext może istnieć tylko w jednorazowym response/handoff przy create/reset.

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

`declared_*` zachowują zaobserwowane pola „Godzin teorii/praktyki” jako plan/deklarację. **Nie są zaliczonym formalnym czasem.**

Current requirement profile nie jest pointerem na row; to latest `training_requirement_profiles` with `superseded_at IS NULL`.

Indexes:
- `(organization_id, student_id, started_at desc)`,
- `(organization_id, training_stage)`,
- `(lead_instructor_id, started_at)`.

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

Partial unique:
- `(course_enrollment_id)` where `superseded_at is null`.

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

Nie służy do dowolnego obniżania wymogów prawnych; operator koryguje fakty/podstawę, a rule engine wylicza wynik.

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

Pola „Teoria/Praktyka odbyta w innej szkole” tworzą/aktualizują te rekordy poprzez audited lifecycle.

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

Unique `(training_session_id, student_id)`.

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

Import istniejącego kursu może utworzyć `opening_balance` tylko w jawnej mode z reason/audit. Zwykła edycja `declared_*` nie zwiększa formalnych godzin.

---

# 14. PKK

## `pkk_integration_settings`

- `organization_id uuid PK/FK`
- `school_name varchar(255)`
- `osk_registry_number varchar(128)`
- `external_login_ciphertext text null`
- `credential_secret_reference varchar(255) null`
- `updated_at`.

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

Plain full provider payload w JSONB jest zabroniony.

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

Partial unique/idempotency policy zgodna ze wspólnym idempotency store.

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

Unique `(pkk_operation_id, attempt_no)`.

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

Important dates mogą być event_type/projection z dokumentów i terminów; nie wymagają duplikowania źródłowych dat.

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

Balance/`Pozostało` = projection z charge - nieodwrócone payments.

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

Unique `(license_product_id, language_code)`.

Zaobserwowany generator zawierał PL/EN/DE/RU/UK, a publiczna oferta miała inny zakres — capability per product, nie globalny hardcode.

## `license_inventory_entries`

Jedna sztuka = jeden row.

- `id uuid PK`
- `organization_id uuid FK`
- `license_product_id uuid FK`
- `source_order_item_id uuid null`
- `status varchar(32)` — `available|assigned|consumed|expired|adjusted`
- `granted_at timestamptz`
- `created_at`.

Index `(organization_id, license_product_id, status)`.

## `license_assignments`

Historyczny fakt przypisania.

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

Partial unique:
- `(license_inventory_entry_id)` where current/not revoked.

Nie ma globalnego UNIQUE po inventory przez całą historię.

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

Stacking:
1. lock `StudentLearningAccount`,
2. validate assignment/inventory,
3. `current_end = max(existing effective_to)` dla aktywnego entitlementu,
4. `effective_from = max(now, current_end)`,
5. `effective_to = effective_from + product duration`,
6. consume inventory,
7. audit/outbox,
8. commit.

Dwa równoległe extensiony nie mogą zgubić czasu.

Revoke nieaktywowanej:
`lock -> verify no activation -> revoke -> restore exactly one inventory -> audit -> commit`.

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
- `candidate_snapshot jsonb not null` — sanitized immutable snapshot,
- `status varchar(32)`
- `started_at timestamptz null`
- `finished_at timestamptz null`
- `invalidated_at timestamptz null`
- `version integer default 1`
- `created_at`.

## `internal_exam_reservations`

Historyczny fakt rezerwacji.

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

Released history nie blokuje ponownej rezerwacji inventory.

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

Raw remote token nie jest później odzyskiwalny z DB.

## `exam_stations`

Stabilne stanowisko OSK.

- `id uuid PK`
- `organization_id uuid FK`
- `name varchar(128)`
- `station_key_hash char(64) null`
- `status varchar(32)`
- `last_seen_at timestamptz null`
- `created_at`
- `updated_at`.

## `internal_exam_station_sessions`

Historia powiązania rozpoczętej próby ze stanowiskiem.

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

Failover po starcie zamyka starą session i tworzy nową z `technical_transfer`; inventory nie jest konsumowane drugi raz.

## `internal_exam_attempt_questions`

Immutable snapshot:
- `id uuid PK`
- `internal_exam_attempt_id uuid FK`
- `ordinal smallint`
- `question_snapshot jsonb`
- `candidate_answer jsonb null`
- `is_correct boolean null`
- `points_awarded integer null`
- `answered_at timestamptz null`.

Unique `(internal_exam_attempt_id, ordinal)`.

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

Observed answer-sheet PDF/signature requirement maps here.

### Start transaction

1. lock access + attempt + reservation + inventory + station (if local),
2. validate startability,
3. verify station available if local,
4. consume inventory exactly once,
5. reservation -> consumed,
6. attempt -> in_progress,
7. create active station session if local,
8. audit/outbox,
9. commit.

Finish nie konsumuje inventory ponownie.

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

Unique `(provider, provider_payment_id)` where not null.

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

Unique `(provider, provider_event_id)`.

Dedupe następuje przed business effect.

## `service_entitlements`

Generic entitlement dla opłaconej/przyznanej usługi, kiedy produkt nie ma wyspecjalizowanego inventory jak licencja lub egzamin.

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

Dla `activation_mode=explicit` potwierdzenie płatności tworzy entitlement, ale **nie jest jeszcze aktywacją**. Aktywacja jest exactly-once, idempotentna i audytowana.

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

Audit nie zawiera plaintext password/token/full PESEL/full PKK domyślnie.

## `organization_activity_events`

Bezpieczna projekcja dla dashboardowego feedu.

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

Indexes:
- `(organization_id, occurred_at desc)`,
- `(organization_id, event_type, occurred_at desc)`,
- `(related_student_id, occurred_at desc)`.

`safe_payload` jest allow-listed i nie zawiera danych wrażliwych. Dashboard nie renderuje raw audit before/after.

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
- `RESTRICT` dla danych formalnych/finansowych,
- `SET NULL` tylko dla optional relation, gdy historia nadal ma sens,
- `CASCADE` tylko dla technicznych child rows bez samodzielnej wartości historycznej.

Nigdy cascade-delete z `Student` do:
- course enrollment history,
- exam history,
- payment history,
- PKK operation history.

Nigdy cascade-delete z `CourseEnrollment` do:
- PKK operations,
- training hour ledger,
- internal exam attempts,
- student charges.

FileAsset lifecycle jest niezależny od przypadkowego hard-delete parenta.

---

# 22. Indeksy tenantowe i query-driven indexing

Każda większa lista panelowa ma indeks zaczynający się od `organization_id` tam, gdzie to praktyczne.

Minimum pod obserwowane query:
- students: archived + created/name/search projection,
- staff: archived + name + document expiry,
- vehicles: archived + registration + document expiry,
- locations: archived + type,
- course_enrollments: student + stage + start,
- calendar_events: time range + resource IDs,
- student_charges/payments: student + date/status,
- license inventory: product/status,
- license assignments: learning account + status/date,
- exam attempts: course/student/date/status/language/category,
- activity events: organization + occurred_at,
- orders: organization + status/date,
- service_entitlements: organization + service_type + status,
- auth_sessions: user + revoked_at + last_seen_at.

Nie tworzymy „indeksu na każdą kolumnę”; indeks powstaje pod faktyczne screen/API query.

---

# 23. Obowiązkowe migration/invariant tests

- generowane przez system synthetic domain IDs są UUIDv7 i są przechowywane jako natywny PostgreSQL `uuid`,
- generic login resolves to at most one current user,
- auth session jest listowalna/revokowalna bez ujawnienia raw secretu,
- tylko jedno pending account closure request w tym samym scope,
- same global User może mieć membership w dwóch OSK,
- staff może istnieć bez panel account,
- staff ma wiele categories/locations,
- vehicle ma wiele categories/locations,
- tylko jeden current TrainingRequirementProfile per course,
- declared hours nie zwiększają credited time bez ledger entry,
- revoked license assignment pozwala reuse tej samej inventory sztuki,
- dwa current assignmenty tej samej inventory sztuki są odrzucone,
- jedna activation per assignment,
- concurrent license extensions nie gubią czasu,
- released exam reservation pozwala ponownie użyć inventory,
- dwa active reservation per inventory/attempt są odrzucone,
- jedno active local exam per station,
- jedna active station session per attempt,
- failover nie konsumuje drugiego egzaminu,
- explicit service entitlement activation jest exactly-once,
- payment success nie aktywuje przedwcześnie entitlementu z `activation_mode=explicit`,
- duplicate payment event same provider jest odrzucony,
- ten sam provider_event_id u dwóch providerów nie koliduje,
- same idempotency key + same request nie duplikuje effect,
- same key + different request -> conflict,
- activity safe payload nie przepuszcza PESEL/PKK/password/token.

---

# 24. Kolejność migracji high-level

1. organizations/users/auth identifiers/sessions/account closure,
2. organization settings + legal documents/terms acceptance,
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
- synthetic domain ID: UUIDv7 generowany application-side, przechowywany jako natywny PostgreSQL `uuid`.

Nadal wymagają osobnego etapu/ADR przed produkcyjnymi migracjami odpowiednich modułów:
- application encryption + key rotation dla PESEL/PKK/provider snapshots,
- calendar overlap enforcement: exclusion constraint vs transaction locks,
- immutable snapshot canonicalization/hash,
- auth account merge/recovery/email verification policy.

---

# 26. Reverse-engineering compatibility rule

Schema ma wspierać wszystkie potwierdzone relacje i flow z `specs/reverse-engineering-manifest.yml`, w tym:
- quick preview kursanta,
- search/filter/sort,
- wiele kursów jednego kursanta,
- wszystkie cztery pola godzinowe kursu,
- raty i saldo kursanta,
- PKK per course + operation history,
- staff/vehicle multi-category i multi-location,
- dokumenty ważności i alerty,
- calendar resource filters/custom meeting place,
- wiele historycznych licencji i stacking,
- bulk/single credential PDF,
- wiele prób egzaminu, result review i answer-sheet PDF,
- dwa entry pointy generowania egzaminu,
- local station concurrency/failover,
- dashboard activity feed,
- listę/revoke sesji konta,
- audytowalne żądanie zamknięcia konta,
- zakup -> entitlement -> osobna aktywacja dla usług z activation mode explicit.

Jeżeli screen spec lub canonical API wymaga capability, której aktualny schema nie potrafi zapisać, **rozszerzamy schema — nie usuwamy capability ze scope'u**.
