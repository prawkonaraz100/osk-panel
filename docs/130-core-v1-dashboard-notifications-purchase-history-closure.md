# 130. Core v1 — Dashboard / Notifications / Purchase History closure

Data: 2026-09-12

**Slice:** `CORE-V1-DASHBOARD-NOTIFICATIONS-PURCHASE-HISTORY-001`  
**Implementation machine:** PASS  
**Machine closure:** PASS  
**Narrative payload:** PASS  
**Central closure:** PASS

## 1. Zakres

Slice obejmuje wyłącznie:

- Dashboard OSK,
- organization activity / notifications read model,
- exact-recipient notifications i mark-read,
- Platform Purchase History,
- rozpoczęcie provider-neutralnego pending payment attempt dla istniejącego nieopłaconego Order,
- dependency-closed migracje fazy `expand`,
- backend/API/permissions/tests/traceability,
- własny UI potwierdzonego dashboardu i potwierdzonego ekranu historii zakupów.

Slice nie implementuje pełnego checkoutu zakupu licencji ani egzaminów, tworzenia nowych Order z cennika, provider-specific PayU/network I/O, payment webhook reconciliation, automatycznego potwierdzania płatności przez browser return ani produkcyjnego hardeningu.

PKK provider runtime pozostaje odroczony zgodnie z `docs/129-pkk-deferred-pending-pwpw-guidance.md`.

## 2. Accepted implementation provenance

Finalny accepted implementation tip przed machine closure:

`12247c3fa6d1792ec8ac6e59da70322aa60f30ca`

Finalny implementation tree:

`ccf1906951f3fe431490ecc685aa52455089f8ac`

Accepted Implementation CI:

`34705218194` — **5/5 SUCCESS**.

Accepted API Contract Gate:

`34705218209` — **PASS**.

Accepted PostgreSQL suite:

**166 tests / 2598 assertions — PASS**.

Machine closure został następnie promowany jako clean accepted commit:

`c399bb40576218777b9c7fae7acb0ee9d987cbc2`

Machine-closure exact tree:

`b46372c5f85fcb350acea6a34b39768eb52fa3bb`

Accepted machine-closure Implementation CI:

`34706166926` — **5/5 SUCCESS**.

PostgreSQL na machine closure:

**166 tests / 2598 assertions — PASS**.

## 3. Migracje

Materializacja Stage-4 DAG wzrosła w tym slice z **99/170** do **112/170** node'ów / phase steps.

Dodano dokładnie 13 dependency-closed node'ów fazy `expand`:

- `MIG-TBL-COMMERCE_CATALOG_ITEMS`,
- `MIG-TBL-ORDERS`,
- `MIG-TBL-ORDER_ITEMS`,
- `MIG-TBL-PAYMENTS`,
- `MIG-TBL-PAYMENT_EVENTS`,
- `MIG-TBL-ORDER_PAYMENT_SETTLEMENTS`,
- `MIG-TBL-ORDER_FULFILLMENTS`,
- `MIG-TBL-SERVICE_ENTITLEMENTS`,
- `MIG-TBL-SERVICE_ACTIVATIONS`,
- `MIG-TBL-ACTIVITY_PROJECTION_POLICY_REVISIONS`,
- `MIG-TBL-ACTIVITY_PROJECTION_POLICY_CURRENTS`,
- `MIG-TBL-ORGANIZATION_ACTIVITY_EVENTS`,
- `MIG-TBL-NOTIFICATIONS`.

Execution identity:

`1d2f1d3a47a4a09b749d0b164137857a241c07afbaad02789ec21e03cb4118ce`

Plan identity pozostał:

`d2fd6bc999dc2a5ee024e9b91bf00f5a5dad29575554a0ba42eba67107c23f10`

Nie materializowano późniejszych candidate-key/FK/index/check/preflight phases. Globalny phase barrier pozostał nienaruszony.

## 4. Purchase History

`/api/v1/purchase-history` oraz ekran `/historia-zakupow` korzystają z jednego tenant-scoped ledgeru zamówień OSK.

Runtime zachowuje:

- immutable multi-item OrderItem snapshots,
- osobny czas zamówienia i księgowania,
- money w minor units,
- deterministyczną paginację i ordering,
- derived purchase status z canonical settlement + fulfillment facts,
- brak zaufania do klientowego status label,
- brak mieszania Platform Commerce z Student Finance.

UI pokazuje potwierdzony ekran historii zakupów, w tym multi-item orders, zaobserwowane kolumny, page sizes 10/25/50/100 i akcję `Opłać` wyłącznie dla `unpaid`.

## 5. Pending payment attempt

Akcja `Opłać` dla istniejącego nieopłaconego Order:

- wymaga `purchases.pay`,
- jest tenant-scoped,
- używa `Idempotency-Key`,
- tworzy provider-neutralny pending payment attempt,
- zwraca opaque public payment reference,
- emituje wymagany audit/domain-event/outbox intent,
- nie oznacza Order jako paid,
- nie traktuje browser return, lokalnego redirectu ani samego utworzenia payment attempt jako potwierdzenia płatności.

Provider signature verification, webhook, reconciliation, settlement execution i external network I/O nie są pozorowane w tym slice.

## 6. Activity feed

`GET /api/v1/activity` czyta wyłącznie bezpieczną projekcję `organization_activity_events`.

Właściwości:

- exact `organization_id` scope,
- deterministyczna kolejność,
- optional event-type filter,
- safe payload zamiast raw audit/outbox payload,
- actor i subject jako bezpieczne snapshoty,
- brak ujawniania source_event/audit internals w odpowiedzi UI.

Dashboard korzysta z tego samego read modelu zamiast ręcznie składać komunikaty z wielu kontrolerów.

## 7. Notifications

`GET /api/v1/notifications` jest scoped do dokładnego tuple:

`organization_id + organization_membership_id + user_id`.

`unread_only` jest stosowane dopiero po exact recipient scope.

`POST /api/v1/notifications/{notificationId}/read`:

- wymaga aktywnego membership,
- nie pozwala oznaczać jako przeczytane cudzego notification,
- wykonuje tylko transition `NULL -> read_at`,
- nie czyści ani nie przepisuje istniejącego `read_at`,
- jest idempotentne także przy ponownym fresh request key,
- nie emituje recursive domain event/outbox tylko z powodu read-state.

## 8. Dashboard runtime

`GET /api/v1/dashboard` jest agregatorem, nie nowym business source-of-truth.

Zwraca projekcję:

- aktywnych i dostępnych licencji,
- dostępnego internal-exam inventory,
- safe activity preview,
- bieżącego okresu kalendarza.

Licencje są liczone z istniejących inventory/assignment/activation facts.

Dostępne egzaminy są wyprowadzane z canonical inventory ledger i sprawdzane względem operational projection. Drift powoduje fail-closed conflict zamiast pokazania zmyślonego licznika.

Kalendarz pozostaje wspólnym źródłem danych z modułem Calendar.

## 9. Dashboard UI

Route `/` renderuje potwierdzony ekran główny z czterema obszarami:

- Licencje,
- Egzaminy,
- Powiadomienia / activity feed,
- Kalendarz.

UI używa realnego `GET /api/v1/dashboard`; wartości demo 15/93/160 nie są hardkodowane.

Embedded calendar obsługuje:

- poprzedni/następny okres,
- Dzisiaj,
- Miesiąc,
- Tydzień,
- Dzień,
- przejście do pełnego kalendarza,
- dodanie wydarzenia.

Zmiana okresu korzysta z istniejącego `GET /api/v1/calendar/events`.

## 10. Permissions i tenant isolation

Runtime nie traktuje publicznego UUID jako autoryzacji.

Istotne boundaries:

- `purchases.view`,
- `purchases.pay`,
- current active membership dla dashboard/activity/notifications,
- exact organization scope dla Order/Payment,
- exact recipient scope dla Notification.

Foreign-tenant Order nie jest widoczny ani mutowalny.

Suspended/inactive membership nie może korzystać z dashboard/notification authority.

## 11. API i UI

Zaimplementowany runtime w scope tego slice:

- `GET /orders`,
- `GET /orders/{orderId}`,
- `POST /orders/{orderId}/payments`,
- `GET /payments`,
- `GET /purchase-history`,
- `GET /dashboard`,
- `GET /activity`,
- `GET /notifications`,
- `POST /notifications/{notificationId}/read`.

Potwierdzony UI:

- `/` — Panel główny,
- `/historia-zakupow` — Historia zakupów.

Nie implementowano w tym slice ekranów `/licencje/wykup` ani `/egzamin-wewnetrzny/wykup`, ponieważ wymagają osobnego contractu tworzenia Order i server-side catalog/pricing authority. Nie wolno zastępować tego fake checkoutem.

## 12. Executable DBT

Finalny Stage-4 registry nadal zawiera:

**491 test IDs**

Runtime registry:

**78/491 implemented executable assertions**  
**413/491 pending_domain_materialization**

Ten slice nie promuje feature/integration tests do finalnych `DBT-*` bez exact contract coverage.

Świadomie nie oznaczono jako implemented:

- późniejszych physical FK/candidate-key/check/index assertions,
- migration preflight przed właściwą fazą,
- niezależnych two-writer concurrency DBT bez wymaganego evidence,
- provider webhook/reconciliation DBT, których runtime nie został tutaj zaimplementowany.

## 13. Machine evidence

Finalny accepted implementation `12247c3f...`:

- Implementation CI `34705218194` — **5/5 PASS**,
- PostgreSQL — **166 tests / 2598 assertions PASS**,
- API Contract Gate `34705218209` — **PASS**,
- backend static analysis — PASS,
- frontend lint/typecheck/build/audit — PASS,
- contracts-and-traceability — PASS,
- secret scan — PASS,
- migration authority / registry — PASS.

Machine closure accepted `c399bb40...`:

- Implementation CI `34706166926` — **5/5 PASS**,
- PostgreSQL — **166 tests / 2598 assertions PASS**,
- backend/frontend/contracts/secret-scan — PASS.

## 14. Jawnie odroczone zależności

Nie są częścią PASS tego slice:

- provider payment webhook/reconciliation i external network I/O,
- full checkout Order creation,
- server-side catalog pricing/discount checkout authority,
- license purchase checkout UI,
- internal exam purchase checkout UI,
- późniejsze candidate-key/FK/index/check/preflight phases,
- provider-backed PKK operations do czasu autorytatywnych wytycznych PWPW,
- globalny hardening,
- formalne dokumenty końcowe.

Te zależności nie mogą być zastępowane atrapą lokalnego stanu.

## 15. Final closure result

Implementation machine = **PASS**.

Machine closure = **PASS**.

Narrative payload = **PASS**.

Accepted narrative payload commit:

`543383c40dec8e24f4449aca323a0cb3a6baa44e`

Accepted narrative validation:

- Implementation CI `34706481532` — **5/5 SUCCESS**,
- PostgreSQL — **166 tests / 2598 assertions — PASS**,
- backend / frontend / contracts-and-traceability / secret scan — **PASS**.

Finalny wynik central closure:

`CORE-V1-DASHBOARD-NOTIFICATIONS-PURCHASE-HISTORY-001 = PASS`

Następny etap według `AGENTS.md` to **hardening i formalne dokumenty**, ale nie został rozpoczęty.

PKK pozostaje odroczony i może zostać wznowiony dopiero po otrzymaniu oraz zweryfikowaniu autorytatywnych wytycznych PWPW.

**STOP przed hardeningiem / formalnymi dokumentami / wznowieniem PKK do kolejnej jawnej instrukcji użytkownika.**
