# 103. Stage 3 — final OpenAPI contract self-audit

Data: 2026-09-05

**Status:** `PASS`

## Zakres audytu

Ten dokument zamyka Etap 3 z `docs/99-staged-design-and-implementation-plan.md`.

Audyt obejmuje wyłącznie kontrakt HTTP/OpenAPI i jego ochronę przed regresją. Nie otwiera Etapu 4 i nie rozstrzyga jeszcze fizycznych migracji, szyfrowania PESEL/PKK, finalnego słownika kategorii z `PT`, provider contract PKK ani database-level calendar overlap enforcement.

Źródła prawdy użyte w gate:
- `specs/reverse-engineering-manifest.yml`,
- `specs/traceability/core-v1.yml`,
- `specs/api/required-operations-v1.yml`,
- `specs/api/openapi-v1.yaml`,
- `specs/api/openapi-components-v1.yaml`,
- `specs/api/openapi-settings-components.yaml`,
- wszystkie `specs/api/paths/*.yaml`,
- `specs/api/common-contract.yml`,
- `specs/gates/stage-3-openapi-contract-gate.yml`.

## Wynik

Etap 3 spełnia gate jakości.

Zamrożony baseline kontraktu:
- `169` requirement rows z metodą i ścieżką HTTP,
- `158` kanonicznych operacji `method + path`,
- `11` świadomych capability korzystających z istniejącej operacji przez `covered_by_operationId`,
- `0` niezmapowanych requirement rows,
- `129` root path refs,
- `0` dangling root path refs,
- `158` unikalnych `operationId`,
- `0` duplicate `operationId`,
- `0` dangling component/schema/parameter/response refs,
- `0` operacji pozostawionych bez jawnego lub dziedziczonego security contract.

## Reverse-engineering preservation

Zamknięcie Etapu 3 nie oznacza uproszczenia zakresu.

W szczególności nadal obowiązuje zasada:

`screen evidence -> preserved capability -> required operation/use case -> API contract`

Jeżeli kilka potwierdzonych zachowań UI korzysta z jednego endpointu, capability pozostają oddzielnie zapisane w `required-operations-v1.yml`. Przykłady:
- filtry i sortowanie kursantów korzystają z `studentsList`,
- ważne daty kalendarza są projekcją w `calendarEventsList`,
- search/sort/hide-finished licencji korzystają z `licenseAssignmentsList`,
- search/filter/sort egzaminów korzystają z `internalExamSubjectsList`,
- akcja zapłaty nieopłaconego zamówienia korzysta z `orderPaymentsCreate`.

Takie współdzielenie endpointu nie pozwala agentowi pominąć żadnej potwierdzonej opcji UI.

## Błędy znalezione i naprawione podczas Etapu 3

### TRACE-001

PKK configuration gate nie był kompletnie wpięty do traceability.

Naprawa:
- `pkk.configuration_gate.get`,
- `pkk.configuration_gate.save_and_continue`,
- wspólne ownership danych dla `/ustawienia` i configuration gate,
- wspólne test obligations.

### SEC-001

Zewnętrzny wynik i review egzaminu były wcześniej opisane tak, jakby wymagały wyłącznie sesji OSK.

Naprawa:
- `sessionCookie OR examAccessToken`,
- token tylko dla odpowiadającej mu próby,
- review dopiero dla zakończonej próby,
- sam `attemptId` nigdy nie jest autoryzacją.

### REQ-001

`StudentLearningAccount.status` był akceptowany przez request update mimo że jest lifecycle projection.

Naprawa:
- `status` jest response/read-only,
- update konta nauki przyjmuje wyłącznie jawnie edytowalne pola,
- backend ma obowiązek whitelistować request fields,
- raw request mass-assignment do formalnych/finansowych/tenantowych modeli jest zabroniony.

Nie usunięto `create_login_account` z edycji pracownika, ponieważ ta akcja została potwierdzona ekranem reverse engineeringu. Jest traktowana jako jawny orchestration input, a nie jako zapis response field `has_login_account`.

### CI-001

Reguły kontraktu były opisane, ale nie istniał wykonywalny gate repozytoryjny.

Naprawa:
- `scripts/contract-validation/validate_openapi_contract.py`,
- `scripts/contract-validation/requirements.txt`,
- `.github/workflows/api-contract-gate.yml`.

## Executable contract gate

Komenda lokalna:

```bash
python scripts/contract-validation/validate_openapi_contract.py
```

Validator sprawdza strukturalnie m.in.:
- parsowanie YAML,
- recursive `$ref` resolution,
- zgodność root paths z modułami,
- unikalność `operationId`,
- dwukierunkowe mapowanie `required-operations <-> OpenAPI`,
- zamrożone liczniki `169 / 158 / 11`,
- istnienie security schemes,
- permission annotations dla niepublicznych operacji,
- brak `readOnly` fields w request schemas.

Workflow CI uruchamia dokładnie tę samą komendę dla zmian kontraktowych i ma wyłącznie `contents: read`.

Na izolowanej gałęzi nie zaobserwowano jeszcze pull-request-triggered runu nowo dodanego workflow. Nie traktujemy tego jako pozwolenia na pominięcie CI. Pierwszy faktyczny run pozostaje obowiązkowym pre-merge checkiem. Minimalny warunek Etapu 3 — wykonywalny validator repozytoryjny — jest spełniony.

## Security i concurrency

Potwierdzono:
- domyślne API OSK dziedziczy `sessionCookie`,
- public auth ma jawne `security: []`,
- webhook płatności jest wyjątkiem bez sesji użytkownika i wymaga weryfikacji podpisu providera oraz deduplikacji eventu,
- zdalny egzamin ma scoped opaque token,
- krytyczne retryable commandy korzystają z `Idempotency-Key`,
- webhook providerowy deduplikuje po `(provider, provider_event_id)`,
- bezpośrednie edycje wymagające ochrony przed lost update korzystają z `If-Match`,
- state-machine transitions wymagają dodatkowo server-side state validation i transakcyjnego lockowania tam, gdzie określają to inwarianty domenowe.

## Co Etap 3 celowo pozostawia na później

Nie wolno interpretować PASS Etapu 3 jako rozwiązania poniższych tematów:
- physical PostgreSQL schema i migracje,
- partial unique/index/check constraints,
- pełny model FK/on-delete,
- DB-level resource overlap enforcement kalendarza,
- encryption/key rotation dla PESEL/PKK,
- finalna legalna interpretacja `PT`,
- dokładny provider contract PKK,
- dokładny payment-provider contract,
- finalny question-engine contract egzaminu.

Te tematy mają własne późniejsze bramki.

## Decyzja gate

`PASS`

Można rozpocząć **Etap 4 — DB invariants + migracje projektowe**, ale tylko jako nowy, oddzielny etap. Nie wolno przy okazji implementować UI lub kodu Laravel przed zamknięciem odpowiednich bramek projektu bazy.
