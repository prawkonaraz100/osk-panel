# 07. Architektura techniczna

## Rekomendowany stos

Zgodnie z istniejącym kierunkiem projektu:
- **Backend:** Laravel,
- **Frontend:** Vue,
- **DB:** PostgreSQL,
- **Cache/queue:** Redis,
- **Storage:** S3-compatible object storage,
- **PDF:** serwis generowania dokumentów po stronie backendu,
- **Background jobs:** Laravel Queue,
- **Realtime/notifications:** WebSocket/SSE opcjonalnie; e-mail obligatoryjnie.

## Granice modułów

```text
Identity & Tenant
├── Users
├── Roles
└── Organization

Training
├── Students
├── Courses
├── Lectures
└── Progress

Operations
├── Calendar
├── Staff
├── Vehicles
└── Reminders

Compliance
├── PKK
├── Audit
└── Consents

Commerce
├── Orders
├── Payments
├── Licenses
├── Internal Exams Inventory
├── Ads
└── Invoices

Public Presence
├── School Profile
├── Ranking
└── Reviews
```

## Zasady backendu

- kontrolery cienkie,
- logika w Action/Service + domenowe Policy,
- osobne DTO dla integracji PKK,
- żadnych zewnętrznych calli w transakcji DB dłuższej niż niezbędne,
- outbox pattern dla krytycznych zdarzeń,
- webhooki płatnicze idempotentne,
- optimistic locking dla kalendarza i stanów inventory,
- soft delete tylko tam, gdzie prawo i logika pozwalają; log audytowy immutable.

## Frontend

### Layout
- desktop-first panel administracyjny + pełna responsywność,
- jednoznaczna hierarchia: operacje codzienne > formalne > sprzedaż/marketing,
- statusy i alerty bez nadmiaru kolorów,
- tabele z filtrami i zapisanymi widokami,
- formularze z autosave tylko dla danych nieformalnych.

### State
- Pinia dla stanu aplikacji,
- TanStack Query/Vue Query dla danych serwerowych,
- brak przechowywania uprawnień wyłącznie po stronie frontu.

## Integracje

Adapter pattern:
- `PkkProviderInterface`
- `PaymentProviderInterface`
- `MailProviderInterface`
- `SmsProviderInterface`
- `StorageProviderInterface`

Dzięki temu zmiana dostawcy nie zmienia domeny.
