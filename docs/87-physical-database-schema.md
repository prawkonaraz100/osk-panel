# 87. Physical database schema — PostgreSQL core v1

Data: 2026-09-05

**Status:** `IMPLEMENTATION_BLUEPRINT`

> To nie są jeszcze migracje Laravel. To fizyczny blueprint tabel, typów, indeksów i constraintów zgodny z canonical domain model. Przed wdrożeniem migracje mogą doprecyzować nazwy techniczne bez zmiany semantyki domeny.

## 1. Konwencje DB

- primary key: `uuid` albo `ulid` przechowywany jako `uuid`/`varchar(26)` — preferencję finalizuje ADR,
- poniżej używamy `uuid`,
- wszystkie timestampy: `timestamptz`,
- canonical storage czasu: UTC,
- money: `bigint amount_minor` + `char(3) currency`,
- business state: `varchar` + application enum/check constraint, nie PostgreSQL enum jeśli stan może ewoluować,
- JSONB tylko dla snapshotów/metadata,
- `created_at`, `updated_at` tam, gdzie rekord jest mutowalny,
- immutable ledger/audit rows nie wymagają `updated_at`,
- tenant-owned tables mają `organization_id` bezpośrednio tam, gdzie ułatwia to autoryzację/indeksowanie.

## 2. Dane osobowe o wysokiej wrażliwości

Dla PESEL preferowany model:
- `pesel_ciphertext text null` — szyfrowane aplikacyjnie,
- `pesel_lookup_hash char(64) null` — HMAC do exact-match/duplicate detection,
- nigdy zwykły globalny SHA-256 bez secret key.

Analogiczny wzorzec można stosować do innych identyfikatorów wymagających exact lookup, jeżeli uzasadnia to threat model.

---

# 3. Identity / tenant

## `organizations`

- `id uuid PK`
- `name varchar(255) not null`
- `nip varchar(16) null`
- `timezone varchar(64) not null default 'Europe/Warsaw'`
- `status varchar(32) not null`
- `created_at timestamptz`
- `updated_at timestamptz`

Indexes:
- `idx_organizations_status`

## `users`

- `id uuid PK`
- `email_normalized varchar(320) null`
- `password_hash varchar(255) null`
- `status varchar(32) not null`
- `last_login_at timestamptz null`
- `created_at`
- `updated_at`

Unique:
- partial unique `email_normalized` where not null, jeśli globalny login e-mail jest unikalny.

## `organization_memberships`

- `id uuid PK`
- `organization_id uuid FK organizations`
- `user_id uuid FK users`
- `status varchar(32)`
- `role_template_code varchar(64) null`
- `data_scope varchar(64) null`
- `created_at`
- `updated_at`

Unique:
- `(organization_id, user_id)`

Indexes:
- `(user_id, status)`
- `(organization_id, status)`

## `permissions`

- `code varchar(128) PK`
- `description varchar(255)`

## `membership_permissions`

- `membership_id uuid FK`
- `permission_code varchar(128) FK permissions`
- `granted boolean not null`
- `created_at`

Unique:
- `(membership_id, permission_code)`

---

# 4. Staff

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
- `updated_at`

Indexes:
- `(organization_id, archived_at)`
- `(organization_id, last_name, first_name)`
- partial unique `(organization_id, pesel_lookup_hash)` where not null, jeżeli polityka duplikatów tego wymaga.

## `staff_types`

- `code varchar(64) PK`
- `label_key varchar(128)`
- `active boolean`

## `staff_type_assignments`

- `staff_profile_id uuid FK`
- `staff_type_code varchar(64) FK`

Unique:
- `(staff_profile_id, staff_type_code)`

## `staff_user_links`

- `staff_profile_id uuid unique FK`
- `user_id uuid unique FK`
- `created_at`

## `staff_documents`

- `id uuid PK`
- `organization_id uuid FK`
- `staff_profile_id uuid FK`
- `document_type varchar(64)`
- `valid_until date null`
- `document_number varchar(128) null`
- `asset_id uuid null`
- `created_at`
- `updated_at`

Indexes:
- `(organization_id, document_type, valid_until)`
- `(staff_profile_id, document_type)`

---

# 5. Locations

## `locations`

- `id uuid PK`
- `organization_id uuid FK`
- `type_code varchar(64) not null`
- `name varchar(255) not null`
- `street_and_number varchar(255) not null`
- `postal_code varchar(20) not null`
- `city_reference varchar(128) null`
- `city_name varchar(160) not null`
- `voivodeship_name varchar(160) null`
- `archived_at timestamptz null`
- `archived_by_user_id uuid null`
- `created_at`
- `updated_at`

Indexes:
- `(organization_id, archived_at)`
- `(organization_id, type_code)`

---

# 6. Vehicles

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
- `updated_at`

Unique:
- `(organization_id, registration_number_normalized)` dla aktywnych/całej historii zgodnie z polityką,
- optional `(organization_id, vin_normalized)` where not null.

## `vehicle_documents`

- `id uuid PK`
- `organization_id uuid FK`
- `vehicle_id uuid FK`
- `document_type varchar(64)`
- `valid_until date null`
- `asset_id uuid null`
- `created_at`
- `updated_at`

Document types startowe:
- `technical_inspection`,
- `oc_insurance`,
- `ac_insurance`.

## `vehicle_location_assignments`

- `vehicle_id uuid FK`
- `location_id uuid FK`

Unique:
- `(vehicle_id, location_id)`

---

# 7. Driving category dictionary

## `driving_categories`

- `id uuid PK`
- `code varchar(32) unique not null`
- `label varchar(64)`
- `active boolean not null`
- `valid_from date null`
- `valid_to date null`
- `metadata jsonb null`

Nie hardkodować listy wyłącznie w frontendzie.

---

# 8. Students / learning account

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
- `updated_at`

Constraint:
- PESEL albo birth_date zgodnie z formalnym przypadkiem; exact rule w validatorze/rule set.

Indexes:
- `(organization_id, archived_at, last_name, first_name)`
- `(organization_id, contact_email_normalized)`
- partial unique `(organization_id, pesel_lookup_hash)` where not null, jeśli duplikat formalnie niedozwolony.

## `student_learning_accounts`

- `id uuid PK`
- `organization_id uuid FK`
- `student_id uuid FK`
- `user_id uuid null FK users`
- `login_identifier_normalized varchar(320) not null`
- `language_code varchar(16) not null`
- `status varchar(32) not null`
- `created_at`
- `updated_at`

Unique:
- `(organization_id, login_identifier_normalized)`

## `student_access_handoffs`

- `id uuid PK`
- `organization_id uuid FK`
- `student_learning_account_id uuid FK`
- `handoff_type varchar(32)`
- `generated_by_user_id uuid FK`
- `document_asset_id uuid null`
- `created_at timestamptz`

Nie ma kolumny plaintext password.

---

# 9. CourseEnrollment / requirements

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
- `requirements_profile_id uuid null`
- `completed_at timestamptz null`
- `interrupted_at timestamptz null`
- `cancelled_at timestamptz null`
- `cancelled_by_user_id uuid null`
- `created_by_user_id uuid`
- `version integer not null default 1`
- `created_at`
- `updated_at`

Indexes:
- `(organization_id, student_id, started_at desc)`
- `(organization_id, training_stage)`
- `(lead_instructor_id, started_at)`

## `training_requirement_profiles`

- `id uuid PK`
- `organization_id uuid FK`
- `course_enrollment_id uuid FK`
- `rule_set_version varchar(64) not null`
- `theory_training_required boolean`
- `minimum_theory_minutes integer`
- `internal_theory_exam_required boolean`
- `practical_training_required boolean`
- `minimum_practical_minutes integer`
- `internal_practical_exam_required boolean`
- `exemption_basis_code varchar(128) null`
- `input_snapshot jsonb not null`
- `calculated_at timestamptz`
- `superseded_at timestamptz null`

Index:
- `(course_enrollment_id, calculated_at desc)`

## `course_exemption_decisions`

- `id uuid PK`
- `organization_id uuid FK`
- `course_enrollment_id uuid FK`
- `basis_code varchar(128)`
- `evidence_reference varchar(255) null`
- `reason text null`
- `approved_by_user_id uuid`
- `rule_set_version varchar(64)`
- `created_at timestamptz`
- `revoked_at timestamptz null`

## `recognized_external_training`

- `id uuid PK`
- `organization_id uuid FK`
- `course_enrollment_id uuid FK`
- `training_part varchar(32)`
- `recognized_minutes integer not null check (recognized_minutes >= 0)`
- `source_school_reference varchar(255) null`
- `evidence_reference varchar(255) null`
- `reason text not null`
- `approved_by_user_id uuid`
- `created_at timestamptz`
- `revoked_at timestamptz null`

---

# 10. Training sessions / ledger

## `training_sessions`

- `id uuid PK`
- `organization_id uuid FK`
- `course_enrollment_id uuid FK`
- `session_type varchar(32)`
- `starts_at timestamptz`
- `ends_at timestamptz`
- `duration_minutes integer generated/validated`
- `instructor_id uuid FK staff_profiles`
- `vehicle_id uuid null FK vehicles`
- `location_id uuid null FK locations`
- `status varchar(32)`
- `created_by_user_id uuid`
- `version integer default 1`
- `created_at`
- `updated_at`

Check:
- `ends_at > starts_at`.

Indexes:
- `(course_enrollment_id, starts_at)`
- `(instructor_id, starts_at)`
- `(vehicle_id, starts_at)` where vehicle_id not null.

## `training_session_attendance`

- `training_session_id uuid FK`
- `student_id uuid FK`
- `status varchar(32)`
- `confirmed_by_user_id uuid null`
- `confirmed_at timestamptz null`

Unique:
- `(training_session_id, student_id)`

## `training_hour_ledger_entries`

Immutable.

- `id uuid PK`
- `organization_id uuid FK`
- `course_enrollment_id uuid FK`
- `training_session_id uuid null FK`
- `entry_type varchar(32)` — `credit|correction|reversal`
- `training_part varchar(32)`
- `minutes integer not null`
- `source_entry_id uuid null`
- `reason text null`
- `actor_user_id uuid`
- `created_at timestamptz`

No `updated_at`.

Indexes:
- `(course_enrollment_id, training_part, created_at)`
- `(training_session_id)`

---

# 11. PKK

## `pkk_integration_settings`

- `organization_id uuid PK/FK`
- `school_name varchar(255)`
- `osk_registry_number varchar(128)`
- `external_login_ciphertext text null`
- credential references/secrets managed separately,
- `updated_at`.

## `pkk_profiles`

- `id uuid PK`
- `organization_id uuid FK`
- `course_enrollment_id uuid unique FK`
- `pkk_number_ciphertext text null`
- `pkk_lookup_hash char(64) null`
- `status varchar(64) null`
- `profile_snapshot jsonb null`
- `fetched_at timestamptz null`
- `updated_at`

## `pkk_operations`

- `id uuid PK`
- `organization_id uuid FK`
- `course_enrollment_id uuid FK`
- `pkk_profile_id uuid null FK`
- `operation_type varchar(64)`
- `business_status varchar(32)`
- `idempotency_key uuid null`
- `request_id varchar(64)`
- `actor_user_id uuid`
- `created_at`
- `completed_at null`

Unique:
- `(organization_id, idempotency_key)` where key not null.

## `pkk_operation_attempts`

- `id uuid PK`
- `pkk_operation_id uuid FK`
- `attempt_no integer`
- `provider_request_id varchar(128) null`
- `transport_status varchar(32)`
- `provider_status_code varchar(64) null`
- `error_class varchar(64) null`
- `retryable boolean not null`
- `normalized_response jsonb null`
- `started_at`
- `finished_at null`

Unique:
- `(pkk_operation_id, attempt_no)`

---

# 12. Calendar

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
- `updated_at`

Indexes:
- `(organization_id, starts_at, ends_at)`
- `(instructor_id, starts_at, ends_at)`
- `(vehicle_id, starts_at, ends_at)`
- `(student_id, starts_at, ends_at)`.

Conflict detection może wymagać exclusion constraints lub transakcyjnego query+lock; decyzję finalizuje ADR.

## `availability_slots`

- `id uuid PK`
- `organization_id uuid FK`
- `instructor_id uuid null`
- `vehicle_id uuid null`
- `location_id uuid null`
- `starts_at`
- `ends_at`
- `status varchar(32)`
- `booked_student_id uuid null`
- `booked_at timestamptz null`
- `version integer default 1`

---

# 13. Student finance

## `student_charges`

- `id uuid PK`
- `organization_id uuid FK`
- `student_id uuid FK`
- `course_enrollment_id uuid null FK`
- `title varchar(255)`
- `amount_minor bigint not null check (amount_minor >= 0)`
- `currency char(3) not null default 'PLN'`
- `due_at date null`
- `status varchar(32)`
- `created_by_user_id uuid`
- `cancelled_at timestamptz null`
- `created_at`

## `student_payments`

- `id uuid PK`
- `organization_id uuid FK`
- `student_id uuid FK`
- `charge_id uuid FK`
- `amount_minor bigint not null check (amount_minor > 0)`
- `currency char(3)`
- `paid_at timestamptz`
- `payment_method varchar(32) null`
- `note text null`
- `received_by_user_id uuid`
- `idempotency_key uuid null`
- `reversed_at timestamptz null`
- `reversed_by_user_id uuid null`
- `reversal_reason text null`
- `created_at`

Unique:
- `(organization_id, idempotency_key)` where not null.

---

# 14. Licenses

## `license_products`

- `id uuid PK`
- `code varchar(64) unique`
- `duration_days integer`
- `active boolean`
- `activation_mode varchar(32)`
- `metadata jsonb`

## `license_product_languages`

- `license_product_id uuid FK`
- `language_code varchar(16)`

Unique:
- `(license_product_id, language_code)`

## `license_inventory_entries`

Jedna sztuka = jeden row.

- `id uuid PK`
- `organization_id uuid FK`
- `license_product_id uuid FK`
- `source_order_item_id uuid null`
- `status varchar(32)` — `available|assigned|consumed|expired|adjusted`
- `granted_at timestamptz`
- `created_at`

Indexes:
- `(organization_id, license_product_id, status)`

## `license_assignments`

- `id uuid PK`
- `organization_id uuid FK`
- `license_inventory_entry_id uuid unique FK`
- `student_id uuid FK`
- `student_learning_account_id uuid FK`
- `language_code varchar(16)`
- `status varchar(32)`
- `assigned_by_user_id uuid`
- `assigned_at timestamptz`
- `revoked_at timestamptz null`
- `version integer default 1`

## `license_activations`

- `id uuid PK`
- `organization_id uuid FK`
- `license_assignment_id uuid unique FK`
- `activated_by_user_id uuid null`
- `activated_at timestamptz`
- `expires_at timestamptz`
- `created_at`

Unique assignment activation zapewnia exactly-once activation.

---

# 15. Internal exams

## `internal_exam_inventory_entries`

- `id uuid PK`
- `organization_id uuid FK`
- `source_type varchar(32)` — `free|paid|adjustment`
- `source_order_item_id uuid null`
- `status varchar(32)` — `available|reserved|consumed|released|adjusted`
- `granted_at timestamptz`
- `created_at`

Index:
- `(organization_id, source_type, status)`

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
- `created_at`

Indexes:
- `(organization_id, course_enrollment_id, created_at desc)`
- `(student_id, created_at desc)`

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
- `version integer default 1`

Unique:
- one current reservation per inventory entry,
- one active reservation per attempt.

## `internal_exam_accesses`

- `id uuid PK`
- `organization_id uuid FK`
- `internal_exam_attempt_id uuid FK`
- `mode varchar(32)` — `remote_link|local_current_workstation|assigned_exam_station`
- `token_hash char(64) null`
- `station_id uuid null`
- `status varchar(32)`
- `expires_at timestamptz null`
- `revoked_at timestamptz null`
- `opened_at timestamptz null`
- `started_at timestamptz null`
- `created_at`
- `version integer default 1`

Token przechowujemy jako hash, nie plaintext po wydaniu.

## `internal_exam_attempt_questions`

Immutable snapshot.

- `id uuid PK`
- `internal_exam_attempt_id uuid FK`
- `ordinal smallint`
- `question_snapshot jsonb`
- `candidate_answer jsonb null`
- `is_correct boolean null`
- `points_awarded integer null`
- `answered_at timestamptz null`

Unique:
- `(internal_exam_attempt_id, ordinal)`

## `internal_exam_results`

- `id uuid PK`
- `internal_exam_attempt_id uuid unique FK`
- `passed boolean`
- `score integer`
- `max_score integer`
- `result_snapshot jsonb`
- `created_at`

## `internal_exam_documents`

- `id uuid PK`
- `organization_id uuid FK`
- `internal_exam_attempt_id uuid FK`
- `document_type varchar(64)`
- `asset_id uuid`
- `snapshot_hash char(64)`
- `created_at`

## `exam_stations`

- `id uuid PK`
- `organization_id uuid FK`
- `name varchar(128)`
- `status varchar(32)`
- `last_seen_at timestamptz null`
- `created_at`
- `updated_at`

---

# 16. Platform commerce

## `orders`

- `id uuid PK`
- `organization_id uuid FK`
- `status varchar(32)`
- `total_amount_minor bigint`
- `currency char(3)`
- `created_by_user_id uuid`
- `created_at`
- `updated_at`

## `order_items`

- `id uuid PK`
- `order_id uuid FK`
- `product_type varchar(64)`
- `product_reference uuid/null`
- `quantity integer`
- `unit_amount_minor bigint`
- `vat_rate numeric(5,2)`
- `total_amount_minor bigint`
- `snapshot jsonb`

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
- `confirmed_at null`

Unique:
- `(provider, provider_payment_id)` where not null.

## `payment_events`

Immutable:
- `id uuid PK`
- `payment_id uuid FK`
- `provider_event_id varchar(128)`
- `event_type varchar(64)`
- `payload_hash char(64)`
- `received_at timestamptz`
- `processed_at timestamptz null`

Unique:
- `(provider_event_id)` scoped/provider as needed.

---

# 17. Audit / outbox / notifications

## `audit_logs`

Append-only.

- `id uuid PK`
- `organization_id uuid null`
- `actor_user_id uuid null`
- `action varchar(128)`
- `entity_type varchar(128)`
- `entity_id uuid/string null`
- `before_json jsonb null`
- `after_json jsonb null`
- `reason text null`
- `request_id varchar(64)`
- `ip_hash varchar(128) null`
- `user_agent varchar(512) null`
- `created_at timestamptz`

Indexes:
- `(organization_id, created_at desc)`
- `(entity_type, entity_id, created_at desc)`
- `(request_id)`.

## `outbox_messages`

- `id uuid PK`
- `aggregate_type varchar(128)`
- `aggregate_id uuid/string`
- `event_type varchar(128)`
- `payload jsonb`
- `request_id varchar(64)`
- `created_at`
- `published_at null`
- `attempts integer default 0`
- `last_error text null`

Index:
- `(published_at, created_at)`

## `notifications`

- `id uuid PK`
- `organization_id uuid FK`
- `user_id uuid null`
- `type varchar(64)`
- `payload jsonb`
- `read_at timestamptz null`
- `created_at`

---

# 18. Foreign-key delete policy

Preferowane:
- `RESTRICT` dla danych formalnych/finansowych,
- `SET NULL` tylko dla niekrytycznych optional relations, jeżeli utrata relacji nie niszczy historii,
- `CASCADE` tylko dla czysto technicznych child rows, które nie mają niezależnej wartości historycznej.

Nigdy cascade-delete z `Student` do course/exam/payment history.

---

# 19. Indeksy tenantowe

Każda większa lista panelowa powinna mieć indeks zaczynający się od `organization_id`, np.:
- students,
- staff,
- vehicles,
- locations,
- course_enrollments,
- calendar_events,
- student_charges,
- license_inventory_entries,
- internal_exam_attempts,
- orders.

Indeks projektujemy pod faktyczne query z ekranów, nie „indeks na każdą kolumnę”.

---

# 20. Constraints do testów migracyjnych

Minimum:
- one `LicenseActivation` per assignment,
- one inventory entry cannot be assigned twice,
- exam reservation/inventory consume exactly once,
- payment provider event dedup,
- membership unique organization+user,
- learning login unique w wymaganym scope,
- non-negative money/minutes where appropriate,
- start < end for sessions/events.

---

# 21. Kolejność migracji high-level

1. identity/organization,
2. dictionaries,
3. staff/locations/vehicles,
4. students/learning accounts,
5. course enrollments/requirements,
6. training/calendar,
7. PKK,
8. student finance,
9. license inventory,
10. exam inventory/attempts,
11. orders/payments,
12. audit/outbox/notifications,
13. indexes/constraints requiring full relation graph.

---

# 22. Elementy do ADR

Przed migracjami produkcyjnymi rozstrzygnąć:
- UUID vs ULID physical type,
- application-level encryption mechanism dla PESEL/PKK,
- calendar overlap implementation: exclusion constraint vs transactional query locks,
- JSON snapshot hashing/canonicalization,
- partitioning audit log dopiero przy realnej potrzebie skali.
