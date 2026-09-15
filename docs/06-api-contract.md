# 06. API Contract — core OSK v1

Data konsolidacji: 2026-09-05

Prefix: `/api/v1`

> To jest kontrakt **naszego produktu**, nie reverse-engineered API konkurenta. Nazwy endpointów wynikają z canonical domain model (`docs/82-canonical-domain-glossary.md`).

---

# 1. Wspólne konwencje

## 1.1. Identyfikatory

- publiczne ID: UUID/ULID jako string,
- brak sekwencyjnego ID jako mechanizmu bezpieczeństwa,
- każdy tenant-owned resource jest dodatkowo autoryzowany przez `organization_id`.

## 1.2. Daty i czas

- canonical storage: UTC,
- API: ISO-8601 z offsetem / `Z`,
- organizacja ma `timezone` w formacie IANA, np. `Europe/Warsaw`,
- kalendarz wykonuje konwersję strefy jawnie.

Przykład:
`2026-09-05T12:30:00Z`

## 1.3. Money

Nigdy float.

API money object:

```json
{
  "amount_minor": 400000,
  "currency": "PLN"
}
```

## 1.4. Pagination

Standard list:
- `page` — >= 1,
- `per_page` — domyślnie 25, max 100.

Odpowiedź:

```json
{
  "data": [],
  "meta": {
    "page": 1,
    "per_page": 25,
    "total": 0,
    "last_page": 1
  }
}
```

## 1.5. Sorting

- `sort=<field>`
- `direction=asc|desc`

Backend whitelistuje pola per endpoint.

## 1.6. Filtering/search

- `q=<text>` dla search,
- jawne query params dla filtrów, np. `status=active`,
- multi-select jako powtarzalny param albo CSV zgodnie z OpenAPI; nie mieszać obu stylów.

## 1.7. Error envelope

```json
{
  "error": {
    "code": "VALIDATION_FAILED",
    "message": "Nie udało się zapisać danych.",
    "fields": {
      "pkk_number": ["Nieprawidłowy numer PKK."]
    },
    "request_id": "01J..."
  }
}
```

Wymagane klasy HTTP:
- `400` malformed request,
- `401` unauthenticated,
- `403` forbidden,
- `404` not found in authorized scope,
- `409` state/version conflict,
- `422` validation/business validation,
- `429` rate limit,
- `502/503` external dependency unavailable, jeśli właściwe.

Nie ujawniać istnienia cross-tenant resource przez różnicowanie komunikatów.

## 1.8. Idempotency

Dla operacji, które mogą zostać bezpiecznie powtórzone przez klienta/network retry:

Header:
`Idempotency-Key: <uuid>`

Obowiązkowo dla m.in.:
- PKK mutations,
- create order/payment initiation,
- license assignment/activation/revoke,
- exam access/start,
- student payment recording,
- krytycznych korekt finansowych.

## 1.9. Optimistic concurrency

Encje podatne na konkurencyjną edycję zwracają `version`.

PATCH może wymagać:
`If-Match: "<version>"`

Konflikt:
`409 RESOURCE_VERSION_CONFLICT`.

Dotyczy co najmniej:
- calendar event,
- course enrollment,
- license assignment,
- exam access/attempt state,
- finance correction.

## 1.10. Request ID

Każda odpowiedź zawiera lub koreluje `request_id`.

Ten sam ID trafia do:
- application logs,
- audit log,
- integration logs,
- outbox events.

---

# 2. Auth / identity

- `POST /auth/register`
- `POST /auth/login`
- `POST /auth/logout`
- `POST /auth/password/forgot`
- `POST /auth/password/reset`
- `GET /auth/sessions`
- `DELETE /auth/sessions/{sessionId}`
- `POST /auth/account-closure-requests`

Social:
- `GET /auth/social/{provider}/redirect`
- `GET /auth/social/{provider}/callback`

`return_url` musi być lokalną/dozwoloną ścieżką.

---

# 3. Organization / settings

- `GET /organization`
- `PATCH /organization`
- `GET /organization/settings`
- `PATCH /organization/settings`
- `GET /organization/accepted-terms`

PKK integration settings:
- `GET /organization/integrations/pkk`
- `PATCH /organization/integrations/pkk`
- `POST /organization/integrations/pkk/test-connection` — jeśli provider bezpiecznie to wspiera.

Nigdy nie zwracamy sekretów w plaintext po zapisaniu.

---

# 4. Staff / RBAC

Staff profiles:
- `GET /staff`
- `POST /staff`
- `GET /staff/{staffId}`
- `PATCH /staff/{staffId}`
- `POST /staff/{staffId}/archive`
- `POST /staff/{staffId}/restore`

Login/account:
- `POST /staff/{staffId}/user-account`
- `DELETE /staff/{staffId}/user-account`

Permissions:
- `GET /staff/{staffId}/permissions`
- `PUT /staff/{staffId}/permissions`

Dictionaries:
- `GET /staff-types`

Nie używać `DELETE /staff/{id}` do niszczenia historii formalnej.

---

# 5. Locations

- `GET /locations`
- `POST /locations`
- `GET /locations/{locationId}`
- `PATCH /locations/{locationId}`
- `POST /locations/{locationId}/archive`
- `POST /locations/{locationId}/restore`

Dictionaries/search:
- `GET /location-types`
- `GET /geography/cities?q=...`

Potwierdzone typy startowe:
- branch,
- lecture_room,
- maneuvering_area.

---

# 6. Vehicles

- `GET /vehicles`
- `POST /vehicles`
- `GET /vehicles/{vehicleId}`
- `PATCH /vehicles/{vehicleId}`
- `POST /vehicles/{vehicleId}/archive`
- `POST /vehicles/{vehicleId}/restore`

Dokumenty/ważności mogą być częścią PATCH albo osobnym subresource, jeśli historia zmian tego wymaga:
- `GET /vehicles/{vehicleId}/documents`
- `POST /vehicles/{vehicleId}/documents`
- `PATCH /vehicles/{vehicleId}/documents/{documentId}`

---

# 7. Students

- `GET /students`
- `POST /students`
- `GET /students/{studentId}`
- `PATCH /students/{studentId}`
- `POST /students/{studentId}/archive`
- `POST /students/{studentId}/restore`

Lista obsługuje:
- `q`,
- filters,
- sort,
- pagination.

## Learning accounts

- `GET /students/{studentId}/learning-accounts`
- `POST /students/{studentId}/learning-accounts`
- `PATCH /students/{studentId}/learning-accounts/{accountId}`
- `POST /students/{studentId}/learning-accounts/{accountId}/password-reset`
- `POST /students/{studentId}/learning-accounts/{accountId}/access-handoffs`
- `GET /students/{studentId}/learning-accounts/{accountId}/access-handoffs/{handoffId}/pdf`

Password-reset response może jednorazowo zwrócić plaintext tylko do bezpiecznego handoff flow; plaintext nie jest później odtwarzalny.

## Progress

- `GET /students/{studentId}/progress?learning_account_id=&category=`

Metryki są dynamiczne i wersjonowane względem aktualnej bazy/treści.

---

# 8. CourseEnrollments

- `GET /students/{studentId}/course-enrollments`
- `POST /students/{studentId}/course-enrollments`
- `GET /course-enrollments/{courseEnrollmentId}`
- `PATCH /course-enrollments/{courseEnrollmentId}`
- `POST /course-enrollments/{courseEnrollmentId}/cancel`
- `POST /course-enrollments/{courseEnrollmentId}/restore` — jeżeli polityka dopuszcza.

Training stage:
- `POST /course-enrollments/{courseEnrollmentId}/stage-transitions`

Request nie ustawia dowolnie flag prawnych; backend odpala rule engine.

## Requirements / exemptions

- `GET /course-enrollments/{courseEnrollmentId}/requirements`
- `POST /course-enrollments/{courseEnrollmentId}/requirement-context`
- `POST /course-enrollments/{courseEnrollmentId}/exemption-decisions`

Manual correction wymaga reason/permission/audit.

---

# 9. Training sessions / hours

## Bieżące OSK

- `GET /course-enrollments/{courseEnrollmentId}/training-sessions`
- `POST /course-enrollments/{courseEnrollmentId}/training-sessions`
- `GET /training-sessions/{sessionId}`
- `PATCH /training-sessions/{sessionId}`
- `POST /training-sessions/{sessionId}/complete`
- `POST /training-sessions/{sessionId}/cancel`

Ledger:
- `GET /course-enrollments/{courseEnrollmentId}/training-hours`
- `POST /course-enrollments/{courseEnrollmentId}/training-hour-corrections`

Nie udostępniać zwykłego `PATCH total_hours` jako primary source of truth.

## Uznanie zewnętrznego szkolenia

- `GET /course-enrollments/{courseEnrollmentId}/recognized-external-training`
- `POST /course-enrollments/{courseEnrollmentId}/recognized-external-training`
- `POST /course-enrollments/{courseEnrollmentId}/recognized-external-training/{recordId}/revoke`

---

# 10. PKK — course-first

**Runtime status: `FROZEN_UNTIL_EXPLICIT_UNFREEZE`.** Poniższe endpointy są zachowanym provider-neutralnym kontraktem przyszłej integracji i **nie mają obecnie fizycznych bindingów w `routes/web.php`**. Aktualny Core utrzymuje jedynie lokalną, szyfrowaną identity PKK w `CourseEnrollment`, wprowadzaną ręcznie. Provider-specific runtime może powstać dopiero po otrzymaniu i weryfikacji wymagań PWPW.

**Stare endpointy `/students/{id}/pkk/...` są zdeprecjonowane jako model projektowy.**

Canonical:
- `GET /course-enrollments/{courseEnrollmentId}/pkk`
- `POST /course-enrollments/{courseEnrollmentId}/pkk/fetch`
- `POST /course-enrollments/{courseEnrollmentId}/pkk/update-and-return`
- `POST /course-enrollments/{courseEnrollmentId}/pkk/return-to-school`
- `POST /course-enrollments/{courseEnrollmentId}/pkk/return-to-authority`
- `POST /course-enrollments/{courseEnrollmentId}/pkk/return-expired`
- `GET /course-enrollments/{courseEnrollmentId}/pkk/operations`
- `GET /course-enrollments/{courseEnrollmentId}/pkk/operations/{operationId}`
- `POST /course-enrollments/{courseEnrollmentId}/pkk/operations/{operationId}/retry`

Mutacje wymagają `Idempotency-Key`.

Retry endpoint akceptuje tylko operation/error class oznaczone jako retryable.

---

# 11. Calendar

- `GET /calendar/events`
- `POST /calendar/events`
- `GET /calendar/events/{eventId}`
- `PATCH /calendar/events/{eventId}`
- `POST /calendar/events/{eventId}/cancel`
- `POST /calendar/events/{eventId}/complete`

Filters:
- `staff_id`,
- `vehicle_id`,
- `location_id`,
- `student_id`,
- `event_type`,
- `from`, `to`.

Availability/self-booking:
- `GET /availability-slots`
- `POST /availability-slots`
- `PATCH /availability-slots/{slotId}`
- `POST /availability-slots/{slotId}/book`
- `POST /availability-slots/{slotId}/cancel`

Conflict returns `409 RESOURCE_SCHEDULE_CONFLICT` z bezpiecznym opisem konfliktu.

---

# 12. Student finance

Charges:
- `GET /students/{studentId}/charges`
- `POST /students/{studentId}/charges`
- `POST /students/{studentId}/charges/{chargeId}/cancel`

Payments:
- `GET /students/{studentId}/payments`
- `POST /students/{studentId}/payments`
- `POST /students/{studentId}/payments/{paymentId}/reverse`

Summary:
- `GET /students/{studentId}/finance-summary`

Payment/charge mutations wymagają idempotency i audytu.

---

# 13. Licenses

Catalog/inventory:
- `GET /license-products`
- `GET /license-inventory`

Orders:
- `POST /license-orders`

Assignments:
- `GET /license-assignments`
- `POST /license-assignments`
- `GET /license-assignments/{assignmentId}`
- `POST /license-assignments/{assignmentId}/revoke-unactivated`
- `POST /license-assignments/{assignmentId}/activate`

Nie używamy zwykłego `DELETE` dla historycznego assignmentu.

Inwariant revoke:
`not activated -> revoke + restore exactly one inventory entry atomically`.

Capabilities:
- `GET /license-products/{productId}/languages`

---

# 14. Internal exams

Inventory:
- `GET /internal-exam/inventory`
- `POST /internal-exam/orders`

Formal attempt/access creation:
- `POST /course-enrollments/{courseEnrollmentId}/internal-exam-attempts`
- `GET /course-enrollments/{courseEnrollmentId}/internal-exam-attempts`
- `GET /internal-exam-attempts/{attemptId}`

Access:
- `POST /internal-exam-attempts/{attemptId}/accesses`
- `POST /internal-exam-accesses/{accessId}/send`
- `POST /internal-exam-accesses/{accessId}/revoke`
- `POST /internal-exam-accesses/{accessId}/start`

Start jest krytyczną transakcją:
- validate reservation,
- lock inventory/reservation/access,
- consume inventory once,
- set attempt `in_progress`,
- audit,
- commit.

Attempt:
- `POST /internal-exam-attempts/{attemptId}/submit`
- `POST /internal-exam-attempts/{attemptId}/technical-abort`
- `GET /internal-exam-attempts/{attemptId}/result`
- `GET /internal-exam-attempts/{attemptId}/questions`
- `GET /internal-exam-attempts/{attemptId}/documents/answer-sheet.pdf`

Inventory correction after start:
- `POST /internal-exam/inventory-adjustments`

Tylko elevated permission + reason + audit.

Capabilities:
- `GET /internal-exam/capabilities?category=&part=`

---

# 15. Orders / payments / purchase history

- `GET /orders`
- `GET /orders/{orderId}`
- `POST /orders/{orderId}/payments`
- `GET /payments`
- `POST /payment-webhooks/{provider}`

Historia zakupów:
- `GET /purchase-history`

To osobny ledger od student finance.

Entitlements:
- `GET /service-entitlements`
- `POST /service-entitlements/{entitlementId}/activate`

---

# 16. Dashboard / activity / notifications

Dashboard:
- `GET /dashboard`

Minimalny response:
- license counters,
- exam counters,
- activity feed preview,
- calendar preview.

Activity:
- `GET /activity`

Notifications:
- `GET /notifications`
- `POST /notifications/{notificationId}/read`

Dokładne rozdzielenie activity vs notification jest własną decyzją UX; backend może używać wspólnego event source z oddzielnymi projekcjami.

---

# 17. Uploads / assets

Preferowany flow dla zdjęć/PDF/załączników:
- `POST /uploads/presign`
- direct upload do object storage,
- `POST /uploads/{uploadId}/complete`.

Backend waliduje:
- owner/tenant,
- MIME/content sniff,
- size,
- purpose.

---

# 18. Audit

- `GET /audit-logs` — tylko dla uprawnionych ról i scope.

Nie każdy użytkownik musi mieć UI audytu, ale backend musi utrzymywać audit records.

---

# 19. Domenowe kody błędów — minimum

- `VALIDATION_FAILED`
- `FORBIDDEN`
- `RESOURCE_NOT_FOUND`
- `RESOURCE_VERSION_CONFLICT`
- `RESOURCE_SCHEDULE_CONFLICT`
- `COURSE_REQUIREMENT_VIOLATION`
- `PKK_OPERATION_NOT_RETRYABLE`
- `PKK_PROVIDER_UNAVAILABLE`
- `LICENSE_INVENTORY_EMPTY`
- `LICENSE_ALREADY_ACTIVATED`
- `LICENSE_ASSIGNMENT_ALREADY_REVOKED`
- `EXAM_INVENTORY_EMPTY`
- `EXAM_ACCESS_EXPIRED`
- `EXAM_ACCESS_REVOKED`
- `EXAM_ALREADY_STARTED`
- `EXAM_REQUIREMENT_NOT_APPLICABLE`
- `PAYMENT_ALREADY_PROCESSED`
- `IDEMPOTENCY_KEY_REUSED_WITH_DIFFERENT_PAYLOAD`

Kody są stabilne dla frontendu/testów; komunikaty są lokalizowalne.

---

# 20. Następny krok techniczny

Na podstawie tego dokumentu należy wygenerować/utrzymywać `OpenAPI 3.1` jako machine-readable contract. Implementacja Laravel i klient Vue mają być testowane względem tego samego kontraktu.
