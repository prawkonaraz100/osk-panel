# 102. Etap 3 — bramka jakości kontraktu OpenAPI

Data: 2026-09-05

**Status:** `IN_PROGRESS / GATE_FAIL`

Powiązany gate maszynowy: `specs/gates/stage-3-openapi-contract-gate.yml`

## Zasada

Nie przechodzimy do Etapu 4 (DB invariants / migracje projektowe), dopóki Etap 3 nie ma wyniku `PASS`.

W tym etapie nie poprawiamy równolegle migracji, UI, ADR kalendarza, szyfrowania ani prawa kategorii. Zakres Etapu 3 to wyłącznie kompletność i spójność kontraktu HTTP/OpenAPI oraz jego traceability.

## Co już przeszło

1. Semantyka `PATCH` z Etapu 1 pozostaje poprawiona — aktualizacje zasobów korzystają z częściowych `Update*Request`, a nie ze schematów create.
2. Operacje konfiguracji PKK są już w authoritative inventory:
   - `pkk.configuration_gate.get`,
   - `pkk.configuration_gate.save_and_continue`.
3. Root OpenAPI zawiera `/organization/integrations/pkk/configuration`.
4. Kontrakt tej ścieżki zachowuje zaobserwowany flow „Konfiguracja PKK → Zapisz i kontynuuj” i zapisuje atomowo dane należące do różnych źródeł prawdy bez ich duplikowania.

## Aktualny blocker

### TRACE-001 — traceability nie nadąża za Etapem 2

`specs/traceability/core-v1.yml` nadal nie wymienia dwóch nowych operacji configuration gate i nie pokazuje pełnego ownershipu danych Ustawień OSK ustalonego w Etapie 2.

Przed kolejnym krokiem trzeba wyłącznie:
- dodać `pkk.configuration_gate.get`,
- dodać `pkk.configuration_gate.save_and_continue`,
- dopisać konfigurację PKK jako zachowany onboarding flow,
- zsynchronizować `organization_settings` z `specs/database/organization-settings.yml`,
- wskazać `users`, `auth_login_identifiers`, `organization_contact_addresses`, `organizations`, `organization_settings`, `pkk_integration_settings`, `legal_documents`, `terms_acceptances`,
- dopisać test, że ekran Ustawień i configuration gate PKK czytają/zapisują te same rekordy source-of-truth.

## Co będzie sprawdzane po TRACE-001

Dopiero po usunięciu tego jednego blockera wykonujemy następny scan Etapu 3:
- required operations ↔ OpenAPI w obie strony,
- unikalność `operationId`,
- wszystkie `$ref`,
- security inheritance / jawne wyjątki publiczne,
- idempotency commandów krytycznych,
- request schema vs read-only response fields.

Jeżeli scan znajdzie nowe P0/P1, Etap 3 pozostaje otwarty i naprawiamy je pojedynczo. Nie rozpoczynamy Etapu 4.

## Wynik bramki

`FAIL` — kontrolowany, oczekiwany podczas pracy.

To nie oznacza problemu z projektem. Oznacza, że proces działa: wykryty brak jest jawny i blokuje przejście dalej, zamiast zostać ukryty przez ogólny status „gotowe”.
