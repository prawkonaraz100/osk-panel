# 05. Model domenowy

> Model po drugiej weryfikacji funkcji publicznych. Celem jest odwzorowanie zachowań biznesowych własną architekturą, bez kopiowania implementacji badanego serwisu.

## Główne encje

### Tenant i użytkownicy
- `organizations`
- `users`
- `organization_memberships`
- `roles`
- `permissions`
- `consents`
- `auth_identities`
- `sessions`
- `account_closure_requests`
- `account_blocks`
- `user_learning_preferences`

`user_learning_preferences` powinno zawierać m.in. `default_driving_category_id`, ponieważ kategoria może być przełączana i wpływać na test/kurs/statystyki/szkolenie.

### OSK
- `school_profiles`
- `staff_profiles`
- `vehicles`
- `vehicle_documents`
- `reminders`
- `work_time_entries`

### Kursanci i szkolenie
- `students`
- `student_accounts`
- `student_access_credentials`
- `courses`
- `course_editions`
- `enrollments`
- `lectures`
- `lecture_assignments`
- `training_programs`
- `training_sections`
- `training_lessons`
- `training_control_question_sets`
- `training_control_attempts`
- `learning_progress`
- `question_stats`

`student_access_credentials` musi obsłużyć co najmniej dwa kanały: `email_account` oraz `osk_generated_credentials`. Hasła nigdy nie są przechowywane w plaintext.

### Kalendarz
- `calendar_events`
- `driving_lessons`
- `availability_slots`
- `event_participants`
- `event_resources`
- `event_change_logs`
- `work_time_entries`

### PKK
- `pkk_profiles`
- `pkk_operations`
- `pkk_operation_attempts`

### Licencje
- `license_products`
- `license_inventory`
- `license_assignments`
- `license_activations`
- `license_languages` / relacja produkt-język

#### Rozróżnienie krytyczne
- `license_inventory` = niewykorzystana sztuka należąca do OSK,
- `license_assignment` = przydzielenie konkretnej sztuki,
- `license_activation` = moment nieodwracalnego rozpoczęcia wykorzystania,
- `license_product.duration` = okres aktywnego dostępu, a nie termin ważności niewykorzystanej sztuki inventory.

Stan:
`inventory -> assigned -> activated/consumed -> expired`

Odwracalnie tylko przed aktywacją:
`assigned + not_activated -> revoked/deleted -> inventory`.

### Egzaminy
- `exam_products`
- `exam_inventory`
- `internal_exams`
- `exam_assignments`
- `exam_sessions`
- `exam_answers`
- `exam_results`
- `exam_cards`
- `exam_language_capabilities`

Nie hardkodować globalnej listy języków. Dostępność języka powinna należeć do produktu/modułu i być wersjonowalna.

### Zamówienia, płatności i aktywacja usługi
- `orders`
- `order_items`
- `payments`
- `payment_events`
- `service_entitlements`
- `service_activations`
- `transfer_confirmations`

#### Lifecycle entitlement
`ordered -> paid -> activation_available -> activated -> expired`

Nie każdy produkt musi używać ręcznej aktywacji, dlatego `activation_mode = automatic | explicit` powinien należeć do konfiguracji produktu.

### Faktury / refundy
- `invoices` — **opcjonalna encja naszego produktu**, ponieważ faktury były `HISTORICAL_INDEX`, ale stara trasa obecnie nie potwierdza bieżącego modułu,
- `refunds` — encja projektowa procesu finansowego, nie dowód istnienia panelowego przycisku refund w 360.

### Ranking i opinie
- `school_public_profiles`
- `reviews`
- `review_moderations`
- `review_reports`
- `ranking_snapshots`
- `ranking_scores`

`ranking_scores` powinno pozwalać przechowywać wersjonowany wynik/agregat zamiast przeliczać historyczne rankingi z aktualnego algorytmu.

### Reklamy / aukcje
- `ad_placements`
- `ad_regions`
- `ad_auctions`
- `ad_bids`
- `ad_bid_privacy_requests`
- `ad_bid_rejection_requests`
- `ad_orders`
- `ad_campaigns`
- `ad_creatives`
- `ad_creative_reviews`
- `ad_campaign_schedules`

#### Stany aukcji
`scheduled -> open -> closed -> settled`

#### Stany zwycięskiej kampanii
`won -> awaiting_payment -> awaiting_creative -> creative_review -> ready -> active -> completed`

Dodatkowe gałęzie:
- `creative_rejected -> awaiting_creative`,
- `creative_missing -> text_fallback` jeśli produkt/reguły na to pozwalają,
- brak emisji przed spełnieniem wymogu płatności.

### Artykuły sponsorowane
- `sponsored_article_orders`
- `sponsored_articles`
- `sponsored_article_assets`
- `sponsored_article_reviews`
- `sponsored_article_publications`

Stan:
`ordered -> content_pending -> editorial_review -> approved -> promoted -> archived`.

### Partner banner / usługi handlowe
- `partner_banner_assets`
- `implementation_help_requests`
- `commercial_service_leads`

### Audyt i komunikacja
- `audit_logs`
- `notifications`
- `integration_logs`
- `complaints`
- `contact_requests`

## Kluczowe relacje

```mermaid
erDiagram
ORGANIZATION ||--o{ ORGANIZATION_MEMBERSHIP : has
USER ||--o{ ORGANIZATION_MEMBERSHIP : belongs
USER ||--o| USER_LEARNING_PREFERENCE : configures
ORGANIZATION ||--o{ STUDENT : trains
ORGANIZATION ||--o{ VEHICLE : owns
ORGANIZATION ||--o{ COURSE_EDITION : runs
STUDENT ||--o{ ENROLLMENT : has
COURSE_EDITION ||--o{ ENROLLMENT : contains
STUDENT ||--o{ LICENSE_ASSIGNMENT : receives
LICENSE_INVENTORY ||--o| LICENSE_ASSIGNMENT : allocated_as
LICENSE_ASSIGNMENT ||--o| LICENSE_ACTIVATION : activates
STUDENT ||--o{ EXAM_ASSIGNMENT : receives
STUDENT ||--o{ DRIVING_LESSON : attends
VEHICLE ||--o{ DRIVING_LESSON : used_in
USER ||--o{ DRIVING_LESSON : instructs
STUDENT ||--o| PKK_PROFILE : linked
PKK_PROFILE ||--o{ PKK_OPERATION : logs
ORDER ||--o{ ORDER_ITEM : contains
ORDER ||--o{ PAYMENT : paid_by
ORDER ||--o{ SERVICE_ENTITLEMENT : grants
SERVICE_ENTITLEMENT ||--o| SERVICE_ACTIVATION : starts
AD_AUCTION ||--o{ AD_BID : receives
AD_AUCTION ||--o| AD_ORDER : settles_to
AD_ORDER ||--o| AD_CAMPAIGN : creates
AD_CAMPAIGN ||--o{ AD_CREATIVE : uses
SPONSORED_ARTICLE_ORDER ||--o| SPONSORED_ARTICLE : creates
REVIEW ||--o{ REVIEW_REPORT : can_be_reported
```

## Multi-tenancy

Każda encja biznesowa OSK ma `organization_id` tam, gdzie zasób należy do OSK. Dodatkowo:
- global scopes w Eloquent są pomocnicze, ale nie mogą być jedyną ochroną,
- Policy/Gate weryfikuje membership i permission,
- identyfikatory publiczne nie zastępują autoryzacji,
- testy integracyjne muszą sprawdzać, że tenant A nie odczyta ani nie zmieni tenant B po ręcznej zmianie ID,
- aukcje/placementy/ranking mogą być zasobami globalnymi operatora, ale bid/order/campaign muszą być jednoznacznie przypisane do tenant/organization.

## Dane konfigurowalne zamiast hard-code

Ze względu na rozbieżności w publicznych źródłach jako dane/CMS/config przechowywać:
- języki dostępne dla każdego produktu/modułu,
- kategorie prawa jazdy,
- liczbę sekcji/lekcji szkolenia,
- okresy pakietów,
- parametry placementów reklamowych,
- czas ekspozycji reklamy pełnoekranowej,
- parametry aukcji,
- teksty i limity produktów handlowych.
