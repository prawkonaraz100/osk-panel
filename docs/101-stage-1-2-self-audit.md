# 101. Self-audit — Etap 1 i Etap 2 konsolidacji

Data: 2026-09-05

## Wynik

- **Etap 1 — semantyka kontraktu HTTP:** `DONE`
- **Etap 2 — Ustawienia OSK: spójny model domenowy i DB:** `DONE`
- następny etap: **Etap 3 — OpenAPI coverage + contract gate**

---

# Etap 1 — sprawdzone elementy

Przejrzano wszystkie aktualne pliki w `specs/api/paths/`:
- `auth-organization.yaml`,
- `students-courses.yaml`,
- `pkk-calendar.yaml`,
- `resources.yaml`,
- `licenses.yaml`,
- `internal-exams.yaml`,
- `commerce-dashboard.yaml`.

## PATCH semantics

Potwierdzono, że mutable PATCH nie korzystają już z create schema wymagających pól obowiązkowych dla tworzenia.

Poprawione/zweryfikowane mapowania:
- Location -> `UpdateLocationRequest`,
- Staff -> `UpdateStaffRequest`,
- Vehicle -> `UpdateVehicleRequest`,
- VehicleDocument -> `UpdateVehicleDocumentRequest`,
- Student -> `UpdateStudentRequest`,
- LearningAccount -> `UpdateLearningAccountRequest`,
- CourseEnrollment -> `UpdateCourseEnrollmentRequest`,
- TrainingSession -> `UpdateTrainingSessionRequest`,
- CalendarEvent -> `UpdateCalendarEventRequest`,
- AvailabilitySlot -> `UpdateAvailabilitySlotRequest`,
- Organization -> `UpdateOrganizationRequest`.

`InternalExamAttempt` ma celowo wąski PATCH tylko na candidate snapshot przed startem egzaminu; nie używa response schema całej próby.

## Commands

Krytyczne POST/PUT używają command schemas albo jawnie zdefiniowanych inline requestów. Operacje inventory/license/exam/PKK zachowują idempotency tam, gdzie występuje efekt biznesowy wymagający ochrony przed retry.

## Read-only / immutable

ID, timestamps, lifecycle statusy oraz serwerowe readiness/version projections nie są wymagane w update requestach ustawień i głównych mutable zasobów.

## Remaining work przeniesiony do Etapu 3

Pełna automatyczna walidacja wszystkich `$ref`, `operationId` i required-operation coverage jest świadomie zadaniem Etapu 3. W Etapie 1 nie znaleziono już znanego błędu request semantics blokującego dalsze projektowanie.

---

# Etap 2 — Ustawienia OSK

## Zachowany reverse-engineered scope

### Dane podstawowe
- Imię,
- Nazwisko,
- Email.

### Dane firmy
- Nazwa firmy,
- Ulica,
- Nr domu,
- Nr lokalu,
- Miejscowość,
- Kod pocztowy,
- Telefon.

### Dane API PKK
- Nazwa szkoły,
- Numer ewidencyjny OSK,
- Login OSK.

### Regulamin
- otwarcie dokładnie zaakceptowanej wersji.

Nie dodano NIP ani billing data do obserwowanego formularza.

## Canonical ownership

- Imię/Nazwisko -> `users`.
- Email -> primary current `auth_login_identifiers`.
- Nazwa firmy/Telefon -> `organizations`.
- Adres firmy -> `organization_contact_addresses`.
- Dane PKK -> `pkk_integration_settings`.
- Regulamin -> `terms_acceptances + legal_documents`.

## Ważne rozdzielenia

- adres firmy != `Location` używane do szkolenia/kalendarza,
- `external_osk_login` != login aplikacji,
- imię/nazwisko z PKK gate nie są kopiowane do tabeli PKK,
- jeden ekran `Zapisz` może atomowo zmieniać kilka tabel.

## Concurrency

`organization_settings.version` jest wersją agregatu ustawień. Settings update oraz PKK configuration save używają optimistic concurrency (`If-Match` lub równoważny precondition) i inkrementują tę samą wersję po skutecznym commit.

## API

Dodano dedykowane komponenty:
- `specs/api/openapi-settings-components.yaml`.

`/organization/settings` używa canonical settings projection/request.

Dodano bezpośredni kontrakt dla zaobserwowanego flow PKK gate:
- `GET /organization/integrations/pkk/configuration`,
- `PUT /organization/integrations/pkk/configuration`.

Dzięki temu pojedynczy przycisk `Zapisz i kontynuuj` nie wymaga od frontu wykonywania niespójnych zapisów user + PKK osobno.

## Machine DB

Dodano:
- `specs/database/organization-settings.yml`.

Spec jest autorytatywnym detailed bounded-context blueprintem dla ustawień i rozszerza ogólny `core-schema.yml`. Przeniesienie pól do finalnych migracji nastąpi w Etapie 4.

## Screen specs

Zsynchronizowano:
- `specs/screens/settings.yml`,
- `specs/screens/pkk-configuration-gate.yml`.

Oba pliki wskazują te same canonical source records.

---

# Otwarte decyzje, które nie blokują Etapu 2

- finalna polityka re-weryfikacji emaila,
- dokładna semantyka testu połączenia PKK,
- czy `external_osk_login` wymaga lookup hash,
- przyszły selector kraju adresu firmy.

Te decyzje mają własne ADR/provider gates i nie wymagają zmiany obecnego ownership modelu.

---

# Gate result

Nie stwierdzono P0/P1 wymagającego cofnięcia modelu ustawień.

Można przejść do Etapu 3, którego celem jest **100% traceability required operations -> operationId -> schema -> permission -> security** oraz automatyzacja lint/contract checks.
