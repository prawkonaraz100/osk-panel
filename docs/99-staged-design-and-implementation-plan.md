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

Pełna walidacja `$ref`/`operationId`/coverage została domknięta w Etapie 3.

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

# Etap 3 — OpenAPI coverage + contract gate

**Status:** DONE

Cel:
- każda HTTP operation z `specs/api/required-operations-v1.yml` ma `operationId`,
- request/response contract istnieje,
- permission i security mode są jawne,
- wszystkie cross-file `$ref` są rozwiązywalne,
- potwierdzone capability z reverse engineeringu mają pełne mapowanie,
- kontrakt jest chroniony wykonywalnym walidatorem repozytoryjnym i workflow CI.

Zamknięte wyniki:
- 169 wymaganych capability HTTP,
- 158 kanonicznych operacji `path + method`,
- 11 jawnych shared-capability mappings przez `covered_by_operationId`,
- 0 niezmapowanych wymagań HTTP,
- 129 root path refs i 0 dangling path refs,
- 158 unikalnych `operationId` i 0 duplikatów,
- 0 operacji bez security contract,
- jawne wyjątki dla publicznego auth, podpisanego webhooka płatności oraz scoped exam access token,
- krytyczne commandy mają uzgodnione idempotency/concurrency semantics,
- requesty nie mogą przyjmować server-owned lifecycle fields przez przypadkowy mass assignment,
- 0 dangling component/schema/parameter/response refs.

W trakcie audytu naprawiono także realne błędy:
- `TRACE-001` — brak PKK configuration-gate w traceability,
- `SEC-001` — zewnętrzny wynik/review egzaminu wymagał scoped opaque token zamiast samej sesji OSK,
- `REQ-001` — `StudentLearningAccount.status` nie może być bezpośrednio edytowalnym polem requestu,
- `CI-001` — dokumentowane reguły kontraktu nie miały wykonywalnego validatora.

Executable gate:
- validator: `scripts/contract-validation/validate_openapi_contract.py`,
- dependency pin: `scripts/contract-validation/requirements.txt`,
- komenda lokalna: `python scripts/contract-validation/validate_openapi_contract.py`,
- CI: `.github/workflows/api-contract-gate.yml`.

Validator chroni co najmniej:
- parse YAML,
- recursive `$ref` resolution,
- root/module path parity,
- unique `operationId`,
- bidirectional `required-operations` coverage,
- zamrożone liczniki 169/158/11,
- security scheme references,
- permission annotations dla operacji niepublicznych,
- brak `readOnly` fields w request schemas.

Ważne: brak obserwowalnego runu nowo dodanego workflow na izolowanej gałęzi nie zmienia wyniku dokumentacyjnego gate — plan wymagał co najmniej wykonywalnego validatora repozytoryjnego. Pierwszy faktyczny run CI jest nadal obowiązkowym pre-merge checkiem.

**Gate Etapu 3:** PASSED.

Machine-readable gate: `specs/gates/stage-3-openapi-contract-gate.yml`.
Self-audit: `docs/103-stage-3-final-contract-audit.md`.

---

# Etap 4 — DB invariants + migracje projektowe

**Status:** IN_PROGRESS — FOUNDATION_GATE_FAIL

Cel:
- przenieść blueprint oraz bounded-context specs do finalnego zestawu migracji Laravel dopiero po zamknięciu kontraktów,
- zachować historyczne lifecycle przez partial unique,
- nie implementować hard-delete dla formalnej/finansowej historii,
- zamknąć concurrency constraints.

Pierwszy slice `DB4_1_FOUNDATION_DIAGNOSIS` został wykonany bez generowania migracji. Gate wykrył cztery P1, które muszą zostać rozwiązane kolejno:
- `DB-FOUND-001` — finalny physical primary-key strategy,
- `DB-FOUND-002` — nullable-scope uniqueness dla idempotency i account closure,
- `DB-FOUND-003` — brak `organization_contact_addresses` w aggregate core inventory,
- `DB-FOUND-004` — Stage-2 settings fields/constraints nie są jeszcze scalone do physical core blueprint.

Nie naprawiamy ich wszystkich naraz. Następny pojedynczy krok dotyczy wyłącznie `DB-FOUND-001`.

Priorytet dalszych slice'ów:
- identity/tenant/RBAC,
- staff/locations/vehicles,
- students/course,
- ledger czasu,
- calendar,
- licenses,
- internal exams,
- PKK,
- commerce,
- audit/outbox.

W Etapie 4 decyzje z `specs/database/organization-settings.yml` muszą zostać przeniesione do finalnego physical schema/migrations.

Machine-readable gate: `specs/gates/stage-4-database-contract-gate.yml`.
Foundation audit: `docs/104-stage-4-database-foundation-audit.md`.

**Gate Etapu 4:** FAIL / IN_PROGRESS. Stage 5 i implementacja feature/UI pozostają zablokowane.

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
