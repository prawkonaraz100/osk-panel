# 06. Projekt API

Prefiks: `/api/v1`

## Auth
- `POST /auth/register`
- `POST /auth/login`
- `POST /auth/logout`
- `POST /auth/password/forgot`
- `POST /auth/password/reset`
- `GET /auth/social/{provider}/redirect`
- `GET /auth/social/{provider}/callback`

## Organization
- `GET /organization`
- `PATCH /organization`
- `GET /organization/profile`
- `PATCH /organization/profile`

## Staff
- `GET /staff`
- `POST /staff`
- `GET /staff/{id}`
- `PATCH /staff/{id}`
- `DELETE /staff/{id}`
- `POST /staff/{id}/roles`

## Students
- `GET /students`
- `POST /students`
- `GET /students/{id}`
- `PATCH /students/{id}`
- `POST /students/{id}/archive`
- `GET /students/{id}/progress`

## PKK
- `POST /students/{id}/pkk/fetch`
- `GET /students/{id}/pkk`
- `POST /students/{id}/pkk/training-update`
- `POST /students/{id}/pkk/return-school`
- `POST /students/{id}/pkk/return-authority`
- `POST /students/{id}/pkk/return-expired`
- `GET /students/{id}/pkk/operations`

## Calendar
- `GET /calendar/events`
- `POST /calendar/events`
- `PATCH /calendar/events/{id}`
- `DELETE /calendar/events/{id}`
- `POST /calendar/events/{id}/complete`
- `GET /availability`
- `POST /availability`

## Vehicles
- `GET /vehicles`
- `POST /vehicles`
- `PATCH /vehicles/{id}`
- `POST /vehicles/{id}/unavailable`

## Licenses
- `GET /licenses/inventory`
- `POST /licenses/orders`
- `GET /licenses/assignments`
- `POST /licenses/assignments`
- `DELETE /licenses/assignments/{id}`  # tylko nieaktywna
- `POST /licenses/assignments/{id}/activate`

## Progress
- `GET /progress`
- `GET /progress/students/{id}`
- `GET /progress/export`

## Exams
- `GET /internal-exams/inventory`
- `POST /internal-exams/orders`
- `POST /internal-exams/assignments`
- `POST /internal-exams/{id}/link`
- `POST /internal-exams/{id}/start`
- `POST /internal-exams/{id}/submit`
- `GET /internal-exams/{id}/result`
- `GET /internal-exams/{id}/card.pdf`
- `GET /internal-exams/history`

## Finance
- `GET /orders`
- `GET /orders/{id}`
- `POST /orders/{id}/payment`
- `POST /payments/webhooks/{provider}`
- `GET /payments`
- `GET /invoices`
- `GET /invoices/{id}.pdf`

## Ads
- `GET /ads/placements`
- `POST /ads/campaigns`
- `POST /ads/campaigns/{id}/creative`
- `POST /ads/bids`
- `GET /ads/campaigns`

## Audit
- `GET /audit`

## Zdarzenia domenowe

- `StudentCreated`
- `PkkFetched`
- `PkkOperationFailed`
- `LicenseAssigned`
- `LicenseActivated`
- `LicenseRevokedBeforeActivation`
- `InternalExamGenerated`
- `InternalExamStarted`
- `InternalExamFinished`
- `CalendarEventCreated`
- `CalendarEventRescheduled`
- `VehicleDocumentExpiring`
- `EmployeeDocumentExpiring`
- `PaymentConfirmed`
- `InvoiceIssued`
