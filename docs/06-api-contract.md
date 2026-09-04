# 06. Projekt API

Prefiks: `/api/v1`

> To kontrakt naszego odpowiednika funkcjonalnego. Nazwy endpointów nie są reverse-engineered z badanego serwisu. Są projektowane na podstawie zweryfikowanych akcji biznesowych.

## Auth
- `POST /auth/register`
- `POST /auth/login`
- `POST /auth/logout`
- `POST /auth/password/forgot` — payload `identifier` = e-mail lub login
- `POST /auth/password/reset`
- `GET /auth/social/{provider}/redirect`
- `GET /auth/social/{provider}/callback`
- `POST /auth/account-closure-requests`
- `GET /auth/sessions`
- `DELETE /auth/sessions/{id}`

Frontend zachowuje bezpieczny `return_url` dla chronionych tras. Return URL musi być walidowany jako lokalny/dozwolony, aby uniknąć open redirect.

## Organization
- `GET /organization`
- `PATCH /organization`
- `GET /organization/profile`
- `PATCH /organization/profile`
- `GET /organization/reviews`
- `POST /organization/reviews/{reviewId}/reports`

## Staff / permissions
- `GET /staff`
- `POST /staff`
- `GET /staff/{id}`
- `PATCH /staff/{id}`
- `DELETE /staff/{id}`
- `POST /staff/{id}/roles`
- `POST /staff/{id}/permissions`
- `GET /staff/{id}/calendar`
- `GET /work-time`
- `POST /work-time`

Dokładne role 360 są `TO_VERIFY_AUTH`; API naszego produktu powinno opierać się o permissions.

## Students
- `GET /students`
- `POST /students`
- `GET /students/{id}`
- `PATCH /students/{id}`
- `POST /students/{id}/archive`
- `GET /students/{id}/progress`
- `POST /students/{id}/access/email`
- `POST /students/{id}/access/credentials`

### Learning preference / category
- `GET /learning/preferences`
- `PATCH /learning/preferences` — np. `default_driving_category_id`
- `GET /learning/categories`

## PKK
- `POST /students/{id}/pkk/fetch`
- `GET /students/{id}/pkk`
- `POST /students/{id}/pkk/training-update`
- `POST /students/{id}/pkk/return-school`
- `POST /students/{id}/pkk/return-authority`
- `POST /students/{id}/pkk/return-expired`
- `GET /students/{id}/pkk/operations`

Dla operacji nieodwracalnych wymagany `Idempotency-Key`.

## Calendar
- `GET /calendar/events`
- `POST /calendar/events`
- `PATCH /calendar/events/{id}`
- `DELETE /calendar/events/{id}`
- `POST /calendar/events/{id}/complete`
- `GET /availability`
- `POST /availability`
- `POST /availability/student-bookable-slots`
- `POST /availability/student-bookable-slots/{id}/book`
- `GET /calendar/instructors/activity`

## Vehicles
- `GET /vehicles`
- `POST /vehicles`
- `PATCH /vehicles/{id}`
- `POST /vehicles/{id}/unavailable`
- `POST /vehicles/{id}/reminders`

Dokładne pola/statusy panelu pojazdów są `TO_VERIFY_AUTH`.

## Licenses
- `GET /licenses/products`
- `GET /licenses/inventory`
- `POST /licenses/orders`
- `GET /licenses/assignments`
- `POST /licenses/assignments`
  - `student_id`
  - `license_inventory_id` / product selection
  - `language`
  - `access_mode: email | generated_credentials`
- `DELETE /licenses/assignments/{id}` — wyłącznie przed aktywacją; operacja ma przywrócić inventory
- `POST /licenses/assignments/{id}/activate`
- `GET /licenses/languages?product_id=...`

### Inwariant
`DELETE assignment` przed aktywacją musi być transakcją atomową: assignment -> revoked/deleted oraz inventory +1.

## Progress
- `GET /progress`
- `GET /progress/students/{id}`

`GET /progress/export` jest opcjonalnym endpointem własnego produktu (`INFERRED`), nie potwierdzoną funkcją 360.

## Training with instructor
- `GET /training/programs`
- `GET /training/programs/{id}/sections`
- `GET /training/lessons/{id}`
- `POST /training/lessons/{id}/progress`
- `GET /training/sections/{id}/control-questions`
- `POST /training/sections/{id}/control-attempts`
- `POST /training/sections/{id}/control-questions/skip`

Próby pytań kontrolnych powinny być wielokrotne. Struktura programu ma wynikać z kategorii/config, nie z hard-code.

## Exams
- `GET /internal-exams/products`
- `GET /internal-exams/inventory`
- `POST /internal-exams/orders`
- `POST /internal-exams/assignments`
- `POST /internal-exams/{id}/link`
- `POST /internal-exams/{id}/start-local`
- `POST /internal-exams/{id}/start`
- `POST /internal-exams/{id}/submit`
- `GET /internal-exams/{id}/result`
- `GET /internal-exams/{id}/card.pdf`
- `GET /internal-exams/history`
- `GET /internal-exams/languages`

Aktualna lista języków ma być danymi konfiguracyjnymi; publiczne źródła są niespójne w czasie.

## Orders / payments / service activation
- `GET /orders`
- `GET /orders/{id}`
- `POST /orders/{id}/payment`
- `POST /payments/webhooks/{provider}`
- `POST /payments/{id}/transfer-confirmation`
- `GET /payments`
- `GET /entitlements`
- `POST /entitlements/{id}/activate`

### Lifecycle
`ordered -> paid -> activation_available -> activated -> expired`

Webhook płatności nie powinien automatycznie aktywować usługi, jeżeli `activation_mode = explicit`.

### Faktury
Opcjonalne dla naszego produktu:
- `GET /invoices`
- `GET /invoices/{id}.pdf`

Status źródłowy odpowiednika w 360: `HISTORICAL_INDEX / TO_VERIFY_AUTH`.

## Ads — katalog / aukcje
- `GET /ads/placements`
- `GET /ads/regions`
- `GET /ads/auctions?region_id=&placement_id=`
- `GET /ads/auctions/{id}`
- `GET /ads/auctions/{id}/bids`
- `POST /ads/auctions/{id}/bids`
- `POST /ads/bids/{id}/rejection-request`
- `POST /ads/bids/{id}/privacy-request`

### Wymagania bid API
- atomowe sprawdzanie minimalnego przebicia,
- timestamp serwerowy,
- przy remisie kolejność po `created_at`/sekwencji serwerowej,
- po zamknięciu aukcji brak nowych ofert,
- oferta jest wiążąca; „odrzucenie” jest osobnym requestem do operatora, nie samodzielnym delete przez klienta.

## Ads — wygrana / kampania / kreacje
- `GET /ads/orders`
- `GET /ads/campaigns`
- `POST /ads/orders/{id}/payment`
- `POST /ads/campaigns/{id}/creatives` — wariant desktop/mobile
- `POST /ads/campaigns/{id}/creative-service-request`
- `GET /ads/campaigns/{id}/creative-review`
- `POST /ads/campaigns/{id}/text-fallback`

Operator/admin:
- `POST /operator/ads/creatives/{id}/approve`
- `POST /operator/ads/creatives/{id}/reject`
- `POST /operator/ads/bids/{id}/remove`
- `POST /operator/ads/campaigns/{id}/activate`

Nie definiujemy klientowego `pause_campaign` jako odwzorowanej funkcji, bo brak publicznego potwierdzenia.

## Sponsored articles
- `POST /sponsored-articles/orders`
- `POST /sponsored-articles/{id}/content`
- `POST /sponsored-articles/{id}/assets`
- `POST /sponsored-articles/{id}/copywriting-request`
- `GET /sponsored-articles/{id}`

Operator:
- `POST /operator/sponsored-articles/{id}/approve`
- `POST /operator/sponsored-articles/{id}/request-changes`
- `POST /operator/sponsored-articles/{id}/publish`
- `POST /operator/sponsored-articles/{id}/archive`

## Partner banner / commercial services
- `GET /partner-banners`
- `POST /partner-banners/implementation-help`
- `POST /commercial-services/leads`

## Contact / complaints
- `POST /contact`
- `POST /complaints`

## Impersonation — własny bezpieczny odpowiednik
- `POST /impersonation/students/{id}/start`
- `POST /impersonation/stop`

Historyczne źródło potwierdza istnienie pozycji `Przeglądaj jako kursant`; dokładny zakres bieżącego ekranu jest `TO_VERIFY_AUTH`.

## Audit
- `GET /audit`

To wymaganie naszego systemu; nie twierdzimy, że badany panel wystawia użytkownikowi identyczny ekran audytu.

## Zdarzenia domenowe

### Auth / account
- `PasswordResetRequested`
- `AccountClosureRequested`
- `AccountBlocked`
- `SessionRevoked`

### Student / license
- `StudentCreated`
- `StudentAccessCreated`
- `LicenseAssigned`
- `LicenseLanguageSelected`
- `LicenseActivated`
- `LicenseAssignmentDeletedBeforeActivation`
- `LicenseInventoryRestored`

### PKK
- `PkkFetched`
- `PkkTrainingUpdated`
- `PkkReturned`
- `PkkOperationFailed`

### Training
- `TrainingLessonProgressed`
- `ControlQuestionsAttempted`
- `ControlQuestionsSkipped`
- `DefaultDrivingCategoryChanged`

### Exams
- `InternalExamGenerated`
- `InternalExamStarted`
- `InternalExamFinished`
- `ExamCardGenerated`

### Calendar
- `CalendarEventCreated`
- `CalendarEventRescheduled`
- `StudentBookableSlotPublished`
- `StudentBookedSlot`
- `VehicleDocumentExpiring`
- `EmployeeDocumentExpiring`

### Finance / activation
- `PaymentConfirmed`
- `ServiceActivationAvailable`
- `ServiceActivated`
- `TransferConfirmationUploaded`
- `InvoiceIssued` — własna opcjonalna funkcja finansowa

### Ads
- `AdBidPlaced`
- `AdAuctionWon`
- `AdBidRejectionRequested`
- `AdBidPrivacyRequested`
- `AdCreativeUploaded`
- `AdCreativeApproved`
- `AdCreativeRejected`
- `AdCampaignActivated`

### Sponsored content
- `SponsoredArticleSubmitted`
- `SponsoredArticleApproved`
- `SponsoredArticlePublished`
