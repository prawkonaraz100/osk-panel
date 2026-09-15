# 07. Architektura techniczna — core OSK v1

Data konsolidacji: 2026-09-05

> **Current implementation note (2026-09-15):** dokument jest authority dla opisywanej semantyki/evidence, ale nie jest bieżącym backlogiem ani listą wdrożonych modułów. Aktualny stan wykonania i freeze boundaries: `docs/227-current-project-status-authority.md` + `specs/current-project-status.yml`.

## 1. Rekomendowany stos

- **Backend:** Laravel,
- **Frontend:** Vue,
- **DB:** PostgreSQL,
- **Cache / distributed locks / queue backend:** Redis,
- **Object storage:** S3-compatible,
- **Background jobs:** Laravel Queue,
- **PDF:** backendowy moduł generowania dokumentów + object storage,
- **Realtime:** SSE/WebSocket tylko tam, gdzie daje realną wartość,
- **E-mail:** wymagany kanał systemowy,
- **SMS:** adapter opcjonalny,
- **Observability:** structured logs + metrics + traces/request correlation.

## 2. Styl architektury

Startujemy jako **modular monolith**, nie mikroserwisy.

Powody:
- silne transakcje między kursantem, kursem, płatnościami, licencją i egzaminem,
- jeden zespół / jeden produkt,
- prostszy deployment,
- mniejszy koszt operacyjny,
- możliwość późniejszego wydzielenia integracji lub ciężkich workerów.

Granice domen są jednak jawne, aby kod nie stał się monolitem proceduralnym.

## 3. Bounded contexts / moduły

```text
IdentityTenant
├── Users
├── Memberships
├── Permissions
├── Sessions
└── OrganizationSettings

SchoolResources
├── Staff
├── Locations
├── Vehicles
└── Documents/Reminders

Students
├── StudentProfile
├── LearningAccounts
└── AccessHandoffs

Training
├── CourseEnrollments
├── RequirementEngine
├── Exemptions
├── TrainingSessions
├── ExternalTrainingRecognition
└── TrainingHourLedger

Calendar
├── Events
├── DrivingLessons
├── Availability
└── ResourceConflicts

PKK
├── Profiles
├── Commands
├── OperationAttempts
└── ProviderAdapter

StudentFinance
├── Charges
├── Payments
└── Corrections

Licensing
├── Products
├── Inventory
├── Assignments
├── Activations
└── LearningProgressProjection

InternalExams
├── Inventory
├── Reservations
├── Accesses
├── Attempts
├── Results
└── Documents

PlatformCommerce
├── Orders
├── Payments
├── Entitlements
└── PurchaseHistory

AuditNotification
├── AuditLogs
├── Outbox
├── ActivityFeed
└── Notifications
```

Marketing/public presence pozostaje poza core dependency.

## 4. Warstwy backendu

Preferowana organizacja każdego modułu:

```text
Domain/
  Entities or Models
  ValueObjects
  Enums/Dictionaries
  Policies
  DomainServices
  Events

Application/
  Actions or Commands
  Queries
  DTOs
  Validators

Infrastructure/
  Persistence
  ExternalAdapters
  QueueJobs
  Mail/PDF/Storage

Http/
  Controllers
  Requests
  Resources
```

Laravel Eloquent może być używany pragmatycznie, ale logika state machine i inwarianty nie mogą żyć wyłącznie w controllerze/model observerze/frontendzie.

## 5. Zasady controllerów i application layer

Controller:
- auth/request parsing,
- FormRequest validation podstawowa,
- wywołanie Action/Query,
- HTTP response.

Action/Command:
- authorization domain scope,
- transaction boundary,
- state validation,
- persistence,
- audit/outbox event.

Query:
- projekcje list/dashboardów,
- pagination/filter/sort,
- bez mutacji.

## 6. Transakcje

Transakcja DB obejmuje tylko to, co musi być atomowe lokalnie.

Nie trzymamy otwartej transakcji PostgreSQL podczas długiego external call.

Wzorzec integracji:

`validate -> persist intent -> commit -> external call -> persist normalized result -> outbox/event`

Dla commandów wymagających silnej synchronizacji z providerem dopuszczalny jest inny flow, ale musi być jawnie udokumentowany i odporny na timeout/retry.

## 7. Krytyczne locki / concurrency

### Licencje
Pessimistic lock/transakcja dla:
- assign inventory,
- activate,
- revoke before activation.

### Egzaminy
Lock dla:
- reservation,
- access start,
- inventory consumption.

### Kalendarz
Conflict detection po stronie DB/backendu.

Dla wybranych rekordów stosujemy optimistic versioning.

### Płatności
Webhooki idempotentne po provider event ID/idempotency key.

## 8. Outbox pattern

Krytyczna mutacja zapisuje:
- zmianę domenową,
- audit record,
- outbox event
w tej samej transakcji DB.

Worker publikuje/obsługuje outbox asynchronicznie.

Przykładowe eventy:
- `StudentCreated`,
- `CourseEnrollmentCreated`,
- `TrainingTimeCredited`,
- `PkkOperationCompleted`,
- `LicenseAssigned`,
- `LicenseActivated`,
- `InternalExamStarted`,
- `InternalExamFinished`,
- `PaymentConfirmed`.

## 9. Queue / retry

Każdy job ma:
- jawny timeout,
- retry count/backoff,
- idempotency,
- dead-letter/failure handling,
- request/correlation ID.

Nie retryujemy automatycznie błędów biznesowych 4xx providera jak błędów transportowych.

## 10. Redis

Redis używany do:
- queue,
- krótkiego cache,
- rate limiting,
- distributed locks tam, gdzie PostgreSQL lock nie jest właściwy,
- ephemeral token/session data zgodnie z polityką.

Redis nie jest source of truth dla inventory, płatności, godzin ani wyników egzaminu.

## 11. PostgreSQL

Wymagania:
- FK dla relacji domenowych,
- unique constraints dla inwariantów,
- check constraints tam, gdzie proste i stabilne,
- `numeric`/minor units dla money,
- timestamp with timezone / UTC policy,
- JSONB tylko dla snapshotów/metadata, nie zamiast jawnego modelu danych.

Przykładowe constrainty:
- unique aktywna rezerwacja inventory,
- unique activation per license assignment,
- unique consume transition per exam reservation,
- tenant-aware uniqueness dla loginów/identyfikatorów tam, gdzie wymagane.

## 12. Frontend

### Stack
- Vue,
- Vue Router,
- Pinia — tylko client/local state,
- TanStack Query/Vue Query — server state/cache,
- typed API client generowany z OpenAPI docelowo.

### Zasady
- nie duplikować permission logic jako źródła prawdy,
- server state nie trafia masowo do Pinia,
- formularze pokazują backend validation errors,
- destructive/high-risk actions mają confirmation,
- loading/empty/error/success jako standard komponentów,
- desktop-first, ale responsive.

## 13. Design system

Własny design system PrawkoNaRaz:
- neutralny/profesjonalny,
- ograniczona paleta statusów,
- komponenty tabel, drawerów, form, alertów, status chips,
- Lucide lub własny spójny zestaw ikon,
- brak kopiowania trade dress konkurenta.

## 14. Files / object storage

S3-compatible dla:
- zdjęć,
- PDF,
- dokumentów,
- uploadów integracyjnych.

Flow preferowany:
1. backend presign,
2. client direct upload,
3. complete callback,
4. malware/content validation pipeline jeśli typ tego wymaga.

Metadata w DB, bytes w object storage.

## 15. PDF

PDF jest dokumentem generowanym z wersjonowanego snapshotu danych.

Nie generujemy historycznego PDF z „dzisiejszych” rekordów bez snapshotu, jeśli dokument ma znaczenie formalne.

## 16. Adaptery integracyjne

Interfejsy:
- `PkkProviderInterface`,
- `PaymentProviderInterface`,
- `MailProviderInterface`,
- `SmsProviderInterface`,
- `StorageProviderInterface`.

Adapter nie może przenosić vendor-specific DTO do domeny. Normalizuje odpowiedź.

## 17. Feature flags / capabilities

Dane zmienne produktowo:
- języki,
- kategorie,
- warianty licencji,
- exam capabilities,
- regulatory rules versions,
- optional modules
są config/data, nie if-else rozsianym po Vue.

## 18. Environmenty

Minimum:
- local,
- test/CI,
- staging,
- production.

Staging nie używa produkcyjnych sekretów ani danych osobowych bez legalnej podstawy/anonymizacji.

## 19. Observability

Każdy request/job/integration ma correlation ID.

Minimum:
- structured JSON logs,
- request duration/error metrics,
- queue depth/failures,
- DB latency,
- external provider latency/error rate,
- payment webhook failures,
- PKK failures,
- exam start/submit failures,
- alerting dla krytycznych failure rates.

Nie logować plaintext password ani pełnych niepotrzebnych payloadów osobowych.

## 20. Backup / disaster recovery

Wymagane:
- automatyczne backupy PostgreSQL,
- wersjonowanie/retencja object storage,
- regularny restore test,
- udokumentowane RPO/RTO przed production go-live,
- procedura odtworzenia secrets/config osobno od backupu danych.

## 21. Deployment

Preferowane:
- immutable build artifact/container,
- migracje uruchamiane kontrolowanie,
- zero-downtime tam, gdzie realistyczne,
- backward-compatible migrations w dwóch krokach dla zmian dużego ryzyka,
- health/readiness endpoints,
- rollback aplikacji bez cofania destrukcyjnej migracji w ciemno.

## 22. Definition of architecture-ready module

Moduł może wejść do implementacji, gdy ma:
- canonical entities,
- API contract,
- permissions,
- lifecycle/state machine,
- audit rules,
- transaction boundaries,
- errors/idempotency,
- acceptance criteria,
- test plan.
