# 97. Consolidation scan 3 — technical integrity + reverse-engineering completeness

Data: 2026-09-05

## Cel

Ponowny przegląd dokumentacji po konsolidacji, tym razem z dwoma równoległymi kryteriami:

1. **czy model jest technicznie poprawny i implementowalny**, oraz
2. **czy porządkowanie nie zgubiło żadnej potwierdzonej funkcji z reverse engineeringu**.

## Wynik ogólny

Screen-level evidence jest bogate i wystarczające do budowy core. Problemem nie jest brak wiedzy o ekranach, tylko ryzyko utraty szczegółów w krótszych agregatach oraz kilka zbyt prostych constraintów w pierwszym blueprintcie DB/API.

Wprowadzono preservation contract i machine-readable manifest. Od tej rundy high-level aggregate nie może być traktowany jako pełny scope modułu.

---

# P0 — naprawione w tej rundzie

## 1. Utrata funkcji przy skracaniu agregatów

**Problem:** część nowych high-level dokumentów była znacznie krótsza niż wcześniejsze reverse-engineering notes. Technicznie agent mógł przeczytać tylko aggregate i pominąć pola, filtry, entry points, PDF-y albo stany.

**Naprawa:** 
- `docs/96-reverse-engineering-preservation-contract.md`,
- `specs/reverse-engineering-manifest.yml`,
- rozszerzony `AGENTS.md`,
- baseline i authority matrix wymagają pełnego traceability.

**Zasada:** confirmed screen evidence nie może zostać usunięte przez krótszy aggregate.

## 2. Globalny UNIQUE na license inventory assignment

**Problem:** pierwszy DB blueprint pozwalał tylko na jeden `LicenseAssignment` dla `LicenseInventoryEntry` przez całą historię. To łamało potwierdzony flow:

`assigned + not activated -> revoke -> inventory -> assign again`.

**Naprawa:** historyczne assignmenty są dozwolone; partial unique blokuje tylko więcej niż jeden current assignment.

## 3. Historia exam reservations

**Problem:** trzeba zachować released reservations i ponownie użyć inventory przed startem.

**Naprawa:** partial unique tylko dla `status=reserved`:
- per inventory entry,
- per attempt.

## 4. Staff login a multi-tenant identity

**Problem:** `staff_user_links.user_id UNIQUE` blokował tę samą osobę pracującą w dwóch OSK.

**Naprawa:** `StaffMembershipLink -> OrganizationMembership`, z najwyżej jednym aktywnym linkiem per staff profile i membership, przy zachowaniu historii odłączeń.

## 5. Brak junction tables dla potwierdzonych multi-selectów

Dodane do modelu:
- `staff_category_assignments`,
- `staff_location_assignments`,
- `vehicle_category_assignments`,
- `vehicle_location_assignments`.

## 6. Payment webhook dedup scope

**Problem:** sam `provider_event_id` może nie być globalnie unikalny między providerami.

**Naprawa:** canonical unique `(provider, provider_event_id)` przed business effect.

## 7. Circular requirement profile pointer

**Problem:** `course_enrollments.requirements_profile_id` + FK z requirement profile do course tworzył zbędny circular ownership.

**Naprawa:** current profile = najnowszy `superseded_at IS NULL`; partial unique na jeden current profile per course.

## 8. Pola godzin kursu vs formalny ledger

**Problem:** nie wolno ani usunąć reverse-engineered pól „Godzin teorii/praktyki”, ani użyć ich jako drugiego formalnego source of truth obok ledgeru.

**Naprawa:**
- pola są zachowane w screen specs,
- current-OSK values domyślnie mapują się na `declared_theory_minutes` / `declared_practical_minutes`,
- credited formal time nadal wynika z ledgeru,
- import istniejącego kursu może w jawnej correction/import mode stworzyć opening-balance ledger entries z audytem,
- previous-OSK values tworzą `RecognizedExternalTraining`.

---

# P0/P1 — wykryte, do dalszego domknięcia

## A. OpenAPI coverage

Pierwszy OpenAPI blueprint nie pokrywa jeszcze wszystkich potwierdzonych flow.

Dodano `specs/api/required-operations-v1.yml` jako pełny inventory wymaganych capability. Moduł nie może być `DONE`, dopóki wszystkie HTTP operations z inventory nie mają finalnego operationId/request/response/security w OpenAPI.

Braki dotyczą m.in.:
- quick preview / learning account handoffs / progress,
- course cancellation/corrections/external training,
- wszystkie PKK returns/history/retry,
- calendar edit/cancel/availability,
- pełny CRUD staff/vehicles/locations,
- student finance reversal/summary,
- license grouped management projection/bulk documents,
- exam management projection/send/revoke/result/questions/PDF/technical abort,
- settings/activity/notifications.

## B. Global uniqueness loginu kursanta

Do rozstrzygnięcia przed auth implementation:
- jeżeli kursant loguje się samym `login/email` bez tenant context, custom learning login musi być globalnie jednoznaczny,
- jeśli login jest tenant-qualified, UI/auth flow musi to jawnie zapewniać.

Preferowany kierunek dla prostego logowania z kartki: globalnie unikalny login identifier albo centralny AuthIdentity.

## C. License stacking / entitlement period

Zaobserwowano wiele aktywnych license assignments i przedłużanie okresu. `LicenseActivation.expires_at` jest za mało precyzyjne dla concurrency i stacking.

Docelowy model powinien zapisywać per assignment:
- activation moment,
- entitlement effective-from,
- entitlement effective-to,
- atomic extension pod lockiem learning account/entitlement projection.

## D. Exam station concurrency

Zaobserwowany komunikat: egzamin lokalny dostępny tylko dla 1 kursanta jednocześnie.

Własny model powinien mieć:
- resolved station identity,
- najwyżej jeden active/in-progress exam per station,
- failover na inne stanowisko,
- audit/recovery.

## E. Audit vs activity feed

Dashboard pokazuje activity feed. Nie należy renderować bezpośrednio surowego `audit_logs.before_json/after_json`, bo może zawierać dane wrażliwe.

Potrzebna bezpieczna `OrganizationActivityEvent` projection z allow-listowanym payloadem.

## F. PKK/audit sensitive snapshots

`PkkProfile.profile_snapshot` i provider response mogą zawierać dane osobowe. Plain JSONB bez polityki szyfrowania/redakcji jest zbyt słaby.

Przed provider implementation wymagane:
- encrypted sensitive snapshot albo minimal normalized storage,
- redacted integration logs,
- audit bez plaintext PESEL/sekretów,
- retention policy.

## G. Idempotency persistence

Same `Idempotency-Key` w kontrakcie nie wystarcza. Wskazane jest wspólne `idempotency_records` albo równoważny mechanizm storage/cache+DB, który:
- wiąże key z operation + request hash,
- odrzuca ten sam key z innym payloadem,
- zwraca ten sam business result dla retry.

## H. File assets

W schema używamy `photo_asset_id`, `document_asset_id`, `asset_id`, ale pierwszy blueprint nie zawierał jawnego `file_assets`.

Należy dodać:
- storage key,
- organization scope,
- media type,
- size/hash,
- upload/scanning status,
- created_by,
- lifecycle/retention.

## I. Terms acceptance / organization settings

Ustawienia i zaakceptowana wersja regulaminu są wymaganiem, a DB blueprint powinien posiadać:
- organization settings,
- immutable terms acceptance history.

## J. Dictionaries/capabilities

Dla config-driven systemu należy jawnie modelować/seedować:
- driving categories,
- languages,
- location types,
- staff types,
- product-language capability,
- exam capability.

---

# Gate po tym skanie

### Można rozpocząć implementację fundamentów
- tenant/auth,
- bazowy RBAC,
- locations/staff/vehicles po uwzględnieniu corrected schema,
- student/course skeleton.

### Nie oznaczać modułu jako DONE dopóki
- reverse-engineering traceability nie jest kompletne,
- wymagane API operations nie są pokryte,
- migrations/tests nie pokrywają partial unique i history lifecycle,
- sensitive data policy danego modułu nie jest wdrożona.

## Najważniejsza reguła

Nie naprawiamy technicznych problemów przez usuwanie funkcji z audytu. Naprawiamy model tak, aby **obsłużył pełny zaobserwowany flow**.
