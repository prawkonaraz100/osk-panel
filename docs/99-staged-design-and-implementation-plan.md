# 99. Staged design and implementation plan

Data: 2026-09-05

**Status:** `ACTIVE_PROCESS_GATE`

## Cel

Prace nad panelem OSK prowadzimy etapami. Nie przechodzimy do kolejnej warstwy tylko dlatego, że poprzednia wygląda „wystarczająco”. Każdy etap ma własny zakres, artefakty i gate jakości.

Zasada nadrzędna pozostaje bez zmian:

`evidence -> requirement -> domain/data -> API/use case -> permission -> UI -> acceptance test`

Potwierdzone capability z reverse engineeringu nie może zniknąć w późniejszym uproszczeniu dokumentacji, API albo DB.

---

# Etap 0 — preservation i authority

**Status:** DONE

Obowiązują:
- `docs/96-reverse-engineering-preservation-contract.md`,
- `specs/reverse-engineering-manifest.yml`,
- `docs/91-documentation-authority-matrix.md`,
- `specs/implementation-baseline-v1.yml`,
- `AGENTS.md`.

Gate:
- źródła mają hierarchię,
- screen evidence kontroluje scope capability,
- legal/design/security kontrolują semantykę implementacji,
- aggregate nie może po cichu usuwać szczegółów.

---

# Etap 1 — semantyka kontraktu HTTP

**Status:** DONE

Cel:
- każde `POST` ma create/command schema,
- każde `PATCH` ma partial-update schema bez wymuszania pól create,
- immutable/read-only pola nie trafiają do requestów update,
- krytyczne commandy mają idempotency,
- mutable zasoby mają optimistic concurrency tam, gdzie potrzebne.

Zamknięte mapowania PATCH obejmują m.in.:
- `Location -> UpdateLocationRequest`,
- `Staff -> UpdateStaffRequest`,
- `Vehicle -> UpdateVehicleRequest`,
- `VehicleDocument -> UpdateVehicleDocumentRequest`,
- `Student -> UpdateStudentRequest`,
- `LearningAccount -> UpdateLearningAccountRequest`,
- `CourseEnrollment -> UpdateCourseEnrollmentRequest`,
- `TrainingSession -> UpdateTrainingSessionRequest`,
- `CalendarEvent -> UpdateCalendarEventRequest`,
- `AvailabilitySlot -> UpdateAvailabilitySlotRequest`,
- `Organization -> UpdateOrganizationRequest`.

Przeskanowano wszystkie pliki `specs/api/paths/*.yaml`. Nie ma znanego P0/P1 request-semantics blocker.

Pełna automatyczna walidacja `$ref`/`operationId`/coverage należy do Etapu 3.

**Gate Etapu 1:** PASSED.

Self-audit: `docs/101-stage-1-2-self-audit.md`.

---

# Etap 2 — Ustawienia OSK: spójny model domenowy i DB

**Status:** DONE

Cel: ekran `/ustawienia` ma jeden spójny model zamiast rozrzucania danych po przypadkowych JSON-ach.

Zakres potwierdzony ekranem:
- użytkownik: imię, nazwisko, e-mail,
- firma: nazwa firmy, ulica, nr domu, nr lokalu, miejscowość, kod pocztowy, telefon,
- PKK: nazwa szkoły, numer ewidencyjny OSK, Login OSK,
- link do wersji zaakceptowanego regulaminu.

Canonical ownership:
- `users` -> imię/nazwisko,
- `auth_login_identifiers` -> primary email,
- `organizations` -> nazwa firmy i telefon,
- `organization_contact_addresses` -> strukturalny adres firmy,
- `pkk_integration_settings` -> wyłącznie pola integracji PKK,
- `terms_acceptances + legal_documents` -> historia regulaminu.

Ważne:
- nie dodajemy NIP do obserwowanego formularza tylko dlatego, że NIP istnieje w domenie,
- `external_osk_login` nie jest loginem do naszej aplikacji,
- settings i PKK configuration gate korzystają z tych samych rekordów source-of-truth,
- adres firmy nie jest rekordem `Location`,
- jeden `Zapisz` jest atomowym use case'em dotykającym kilku tabel,
- `organization_settings.version` zapewnia optimistic concurrency.

Artefakty:
- `specs/database/organization-settings.yml`,
- `docs/100-osk-settings-domain-model.md`,
- `specs/api/openapi-settings-components.yaml`,
- zsynchronizowane `specs/screens/settings.yml`,
- zsynchronizowane `specs/screens/pkk-configuration-gate.yml`,
- API dla `GET/PATCH /organization/settings`,
- API dla `GET/PUT /organization/integrations/pkk/configuration`.

**Gate Etapu 2:** PASSED.

Self-audit: `docs/101-stage-1-2-self-audit.md`.

---

# Etap 3 — OpenAPI coverage + automatyczny contract gate

**Status:** NEXT / IN_PROGRESS

Cel:
- każda HTTP operation z `specs/api/required-operations-v1.yml` ma operationId,
- schema request/response istnieje,
- permission jest jawne,
- security mode jest jawny,
- contract lint działa w CI.

Sprawdzić szczególnie:
- auth vs remote exam token,
- payment webhook signature security,
- PKK async/retry/reconciliation,
- PKK configuration gate operations,
- exam station transfer,
- license activation/revoke race,
- uploads i asset lifecycle,
- nowe cross-file refs do `openapi-settings-components.yaml`.

Plan Etapu 3:
1. zrobić machine-readable inventory wszystkich `operationId`,
2. porównać go z `required-operations-v1.yml`,
3. oznaczyć exact coverage / intentional non-HTTP / deferred,
4. sprawdzić dangling `$ref`,
5. sprawdzić duplicate operationId,
6. sprawdzić brak permission/security annotation,
7. dodać lint/contract check do CI lub przynajmniej skrypt repozytoryjny,
8. uruchomić self-audit i dopiero potem przejść do DB migrations.

**Gate Etapu 3:** required HTTP coverage = 100% albo jawny wyjątek z decyzją; zero dangling refs i duplicate operationId.

---

# Etap 4 — DB invariants + migracje projektowe

**Status:** PLANNED

Cel:
- przenieść blueprint oraz bounded-context specs do finalnego zestawu migracji Laravel dopiero po zamknięciu kontraktów,
- zachować historyczne lifecycle przez partial unique,
- nie implementować hard-delete dla formalnej/finansowej historii,
- zamknąć concurrency constraints.

Priorytet:
- identity/tenant/RBAC,
- staff/locations/vehicles,
- students/course,
- ledger czasu,
- licenses,
- internal exams,
- commerce,
- audit/outbox.

W Etapie 4 decyzje z `specs/database/organization-settings.yml` muszą zostać przeniesione do finalnego physical schema/migrations.

**Gate Etapu 4:** migration/invariant tests przechodzą przed warstwą UI.

---

# Etap 5 — acceptance traceability per moduł

**Status:** PLANNED

Dla każdego modułu tworzymy checklistę:
- wszystkie pola,
- wszystkie akcje,
- search/filter/sort,
- stany i lifecycle,
- PDF/download,
- role/permissions,
- audit,
- błędy,
- empty/loading/error/success states.

Każdy element dostaje status:
- `IMPLEMENTED`,
- `SUPERSEDED_BY_LEGAL_RULE`,
- `SUPERSEDED_BY_OWN_PRODUCT_DECISION_WITH_EQUIVALENT_CAPABILITY`,
- `DEFERRED_OUTSIDE_CORE_WITH_EXPLICIT_DECISION`,
- `NOT_A_REQUIREMENT_DEMO_ANOMALY`.

**Gate Etapu 5:** brak niejawnych pominięć.

---

# Etap 6 — implementacja modułów core

**Status:** PLANNED

Kolejność:
1. Identity + Tenant + RBAC + Audit,
2. Locations / Staff / Vehicles,
3. Students + Course Enrollment,
4. Calendar + Training Session + formal hour ledger,
5. Student Finance,
6. Licenses / Learning Access,
7. Internal Exams,
8. PKK adapter,
9. Dashboard / settings / purchase history projections.

Po każdym module:
- backend policy tests,
- integration tests,
- contract tests,
- minimal E2E,
- screenshot własnego UI,
- traceability update.

---

# Etap 7 — wysokiego ryzyka legal/security/provider verification

**Status:** PLANNED / PARALLEL BEFORE PRODUCTION

Nie blokuje całego developmentu, ale blokuje produkcję odpowiedniego obszaru:
- finalny słownik kategorii i `PT`,
- PESEL/PKK encryption + key rotation,
- retencja/RODO,
- provider PKK contract,
- payment provider webhook contract,
- calendar overlap enforcement ADR,
- backup/restore/RPO/RTO.

---

# Zasada pracy od tej chwili

Nie robimy pięciu problemów naraz.

Dla każdego etapu:
1. diagnoza,
2. decyzja projektowa,
3. zmiana machine-readable spec,
4. synchronizacja dokumentacji opisowej,
5. self-audit,
6. dopiero wtedy kolejny etap.

Jeżeli w self-audicie pojawi się nowy błąd P0/P1, zatrzymujemy przejście dalej i naprawiamy go w tym samym etapie.
