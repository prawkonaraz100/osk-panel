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

**Status:** IN_PROGRESS

Cel:
- każde `POST` ma create/command schema,
- każde `PATCH` ma partial-update schema bez wymuszania pól create,
- immutable/read-only pola nie trafiają do requestów update,
- krytyczne commandy mają idempotency,
- mutable zasoby mają optimistic concurrency tam, gdzie potrzebne.

W tej rundzie poprawiono PATCH dla:
- `Location -> UpdateLocationRequest`,
- `Staff -> UpdateStaffRequest`,
- `Vehicle -> UpdateVehicleRequest`,
- `VehicleDocument -> UpdateVehicleDocumentRequest`.

Przed zamknięciem etapu:
1. przeskanować wszystkie pliki `specs/api/paths/*.yaml`,
2. wykryć każde PATCH używające create/response schema,
3. wykryć requesty zawierające read-only ID/status/timestamps,
4. sprawdzić zgodność `required-operations-v1.yml` z `operationId`,
5. sprawdzić wszystkie `$ref`.

**Gate Etapu 1:** zero znanych błędów request semantics i zero dangling refs.

---

# Etap 2 — Ustawienia OSK: spójny model domenowy i DB

**Status:** NEXT

Cel: ekran `/ustawienia` ma jeden spójny model zamiast rozrzucania danych po przypadkowych JSON-ach.

Zakres potwierdzony ekranem:
- użytkownik: imię, nazwisko, e-mail,
- firma: nazwa firmy, ulica, nr domu, nr lokalu, miejscowość, kod pocztowy, telefon,
- PKK: nazwa szkoły, numer ewidencyjny OSK, Login OSK,
- link do wersji zaakceptowanego regulaminu.

Plan modelu:
- global `users`: dane osobowe właściciela/operatora potrzebne ekranowi,
- `organizations`: canonical nazwa i telefon organizacji,
- strukturalny adres organizacji zamiast jednego niekontrolowanego stringa,
- `pkk_integration_settings`: wyłącznie ustawienia PKK,
- `terms_acceptances`: immutable history.

Ważne:
- nie dodajemy NIP do obserwowanego formularza tylko dlatego, że NIP istnieje w domenie,
- `external_osk_login` nie jest loginem do naszej aplikacji,
- settings i PKK configuration gate muszą korzystać z tych samych rekordów source-of-truth.

**Gate Etapu 2:** screen spec, OpenAPI, machine DB schema i narrative DB opisują te same pola i ownership.

---

# Etap 3 — OpenAPI coverage + automatyczny contract gate

**Status:** PLANNED

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
- exam station transfer,
- license activation/revoke race,
- uploads i asset lifecycle.

**Gate Etapu 3:** required HTTP coverage = 100% albo jawny wyjątek z decyzją.

---

# Etap 4 — DB invariants + migracje projektowe

**Status:** PLANNED

Cel:
- przenieść blueprint do finalnego zestawu migracji Laravel dopiero po zamknięciu kontraktów,
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
