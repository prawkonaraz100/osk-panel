# 137. Provider-neutral reconciliation

Data: 2026-09-13

Status: **PRE-PRODUCTION AUTHORITY**

## 1. Cel

H7 zamyka repozytoryjny kontrakt reconciliation bez wymyślania zachowania zewnętrznych providerów.

Runtime:
- `operations:reconciliation:scan`,
- scheduler co 15 minut,
- `--fail-on-findings`,
- machine-readable JSON,
- bez automatycznej naprawy.

Scanner jest read-only. Finding oznacza: „operator/maintenance path musi wyjaśnić stan”, a nie „scanner ma zgadnąć poprawny stan”.

## 2. PKK — zamrożone

PKK jest poza H7.

Do jawnego odmrożenia:
- nie ma PKK reconciliation job,
- nie ma PWPW polling,
- nie ma provider status mapping,
- nie ma retry/replay specyficznego dla PWPW,
- nie tworzymy fałszywego provider evidence.

Dotychczasowa dokumentacja PKK pozostaje zachowana.

## 3. Commerce payment / settlement

Scanner wykrywa:
- payment `confirmed` bez exact settlement,
- settlement wskazujący brakującą, inną tenant/order albo niepotwierdzoną płatność,
- fulfillment w stanie `requires_reconciliation`,
- settled order bez fulfillment.

Nie uznajemy browser return, lokalnego `pending` ani samego provider_payment_id za dowód zapłaty.

Remote provider truth lookup nie jest częścią H7. Gdy zostanie wybrany provider wymagający takiego lookupu, jego adapter musi wejść przez osobny zwalidowany kontrakt.

## 4. Purchase grant cardinality

Dla settled order:
- `license`: liczba `license_inventory_entries` z exact `source_order_item_id` musi równać się `order_items.quantity`,
- `internal_exam`: liczba paid exam units z exact source item musi równać się quantity.

Scanner wykrywa również inventory pochodzące z order item, którego order nie ma trusted settlement/zero-total settlement.

Nie tworzy brakujących unitów.

## 5. License inventory

Zamknięte invariants:
- `available` → 0 assigned, 0 activated, 0 activation,
- `assigned` → dokładnie 1 assigned, 0 activated, 0 activation,
- `consumed` → 0 assigned, dokładnie 1 activated i dokładnie 1 activation.

Historyczne `revoked_before_activation` pozostaje zachowane i nie jest liczone jako current assignment.

Scanner nie wykonuje auto-revoke, auto-assignment ani auto-activation.

## 6. Internal-exam inventory

Ledger pozostaje canonical accounting history.

Scanner porównuje:
- COUNT current `available` units,
- SUM ledger `available_delta`,
per organization + source_type.

Dodatkowo:
- reserved unit musi mieć dokładnie jedną current reserved reservation,
- consumed unit musi mieć dokładnie jedną consumed reservation,
- reserved/consumed reservation musi odpowiadać guarded `current_state`.

Nigdy nie rekonstruujemy ledger history z timestampów ani UUID order.

## 7. Outbox

Scanner surfacuje:
- `requires_reconciliation`,
- expired current lease,
- invalid publication state / timestamp / lease matrix.

Nowy pending outbox intent dostaje server-derived `next_attempt_at=now`, dzięki czemu jest od razu jawnie due dla przyszłego publishera.

Scanner nie oznacza wiadomości jako `published`, bo brak delivery acknowledgement nie może być zastąpiony zgadywaniem.

## 8. Command i scheduler

Manualnie:

`php artisan operations:reconciliation:scan --json --fail-on-findings`

Scheduler rejestruje ten sam scan co 15 minut, z safe summary w application log.

Exit code:
- 0 — PASS,
- non-zero przy findings, gdy użyto `--fail-on-findings`.

## 9. Granica produkcyjna

Repo może udowodnić:
- scanner istnieje,
- SQL jest wykonywalny w PostgreSQL runtime,
- schedule jest zarejestrowany,
- finding daje failing exit,
- scanner nie mutuje business authority.

Repo nie może samo udowodnić:
- że production scheduler faktycznie wykonuje się co 15 minut,
- że warning/failing job trafia do człowieka,
- że konkretny payment provider ma działający remote reconciliation adapter.

Przed go-live pozostaje P1: **production scheduler + alert-delivery smoke test**.

Każda korekta findingu musi używać osobnego audytowanego business/maintenance path.
