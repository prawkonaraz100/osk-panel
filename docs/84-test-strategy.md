# 84. Test strategy — core OSK v1

Data: 2026-09-05

**Status:** `ACTIVE_IMPLEMENTATION_POLICY`

## 1. Cel

Testy mają chronić przede wszystkim:
- tenant isolation,
- formalne reguły kursu,
- inventory licencji/egzaminów,
- płatności i saldo,
- PKK idempotency/retry,
- kalendarz i konflikty zasobów,
- audyt,
- bezpieczeństwo credentials.

Nie dążymy do sztucznego 100% coverage. Krytyczne inwarianty muszą mieć testy bezpośrednie.

## 2. Piramida testów

### Unit
Dla:
- rule engine szkolenia,
- value objects,
- state transitions,
- calculation/projection logic,
- error mapping providerów.

### Integration
Najważniejsza warstwa backendu.

Dla:
- Action + DB + Policy,
- transakcji,
- locków,
- idempotency,
- audit/outbox,
- query projections.

### Contract
Dla:
- OpenAPI request/response,
- PKK adapter,
- payment provider webhooks,
- storage/mail adapters.

### E2E
Tylko krytyczne flow użytkownika, nie każda drobna kontrolka.

## 3. Obowiązkowe cross-tenant tests

Każdy tenant-owned moduł ma testy:
- OSK A reads own resource -> allowed,
- OSK A updates own resource -> allowed,
- OSK A reads OSK B by ID -> denied/not found,
- OSK A mutates OSK B by ID -> denied/not found,
- OSK A sends relation ID from OSK B -> validation/authorization fail.

Dotyczy co najmniej:
- students,
- course enrollments,
- PKK,
- staff,
- locations,
- vehicles,
- calendar,
- student finance,
- licenses,
- exams.

## 4. Race-condition tests

### License activation vs revoke
Równocześnie:
- request A: activate,
- request B: revoke unactivated.

Assertion:
- tylko jeden sukces,
- inventory nie jest podwójnie zmienione,
- jeden spójny audit trail.

### Exam access start
Dwa równoległe starty tego samego accessu.

Assertion:
- jeden `in_progress`,
- inventory consumed exactly once,
- drugi request dostaje conflict/idempotent response.

### Calendar booking
Dwa bookingi jednego slotu.

Assertion:
- maksymalnie jeden booking.

### Payment webhook
Ten sam provider event kilka razy.

Assertion:
- jedna zmiana stanu/grant.

## 5. Idempotency tests

Dla endpointu wymagającego `Idempotency-Key`:

1. pierwszy request -> success,
2. ten sam key + ten sam payload -> ten sam rezultat / bez duplikatu,
3. ten sam key + inny payload -> `IDEMPOTENCY_KEY_REUSED_WITH_DIFFERENT_PAYLOAD`.

## 6. PKK tests

Provider fake/sandbox musi obsługiwać:
- success,
- business validation error,
- unauthorized integration,
- timeout,
- 5xx,
- duplicate/replayed command,
- delayed result/reconciliation scenario.

Assertion:
- business errors nie są automatycznie retryowane,
- retryable transport errors tworzą kolejny attempt, nie nową niezależną operację biznesową,
- każda próba ma correlation ID/audit.

## 7. Rule engine tests

Test matrix z `specs/legal/*.yml`.

Obowiązkowe przypadki:
- standard category with theory,
- +E categories,
- state theory passed before course,
- recognized prior theory,
- correction of exemption basis,
- removal of exemption,
- closed course correction mode.

Testy używają wersji rule setu, nie „dzisiejszej” globalnej reguły bez snapshotu.

## 8. Training time tests

- theory conversion 45 min,
- practical conversion 60 min,
- current OSK totals from ledger,
- external recognized training separate,
- correction does not mutate original ledger entry,
- cancelled session does not credit time,
- cross-tenant session relation rejected.

## 9. Student finance tests

- partial payment,
- full payment,
- overpayment policy,
- reversal,
- cancelled charge,
- decimal/minor units precision,
- no hard-delete after payment,
- balance projection deterministic.

## 10. Credentials/security tests

- plaintext password never persisted,
- password absent from audit/logs/events,
- handoff PDF generation audited,
- old password not recoverable,
- reset invalidates/updates credentials according to auth policy,
- permission required for reset/download.

## 11. Permissions tests

Dla każdej permission group:
- allow,
- deny,
- scope deny,
- cross-tenant deny,
- revoked permission effect.

Elevated permissions mają osobne testy.

## 12. API contract tests

- status codes,
- error envelope,
- pagination meta,
- sort whitelist,
- money shape,
- date/time shape,
- request_id,
- idempotency behavior,
- optimistic concurrency.

## 13. E2E core flows

Minimum:

### E2E-01 — nowy kursant i kurs
`create student -> create course enrollment -> calculated requirements -> visible in detail`

### E2E-02 — zajęcia i formalne godziny
`create lesson -> complete -> attendance -> ledger -> totals`

### E2E-03 — licencja
`inventory -> assign -> create/access account -> activate -> visible active`

### E2E-04 — revoke license before activation
`assign -> revoke -> inventory restored exactly once`

### E2E-05 — egzamin remote
`course -> required exam -> reserve -> link -> start -> consumed -> finish -> result/PDF`

### E2E-06 — egzamin local station
`select student/course -> create access -> start local -> finish`

### E2E-07 — student finance
`charge -> partial payment -> balance -> next payment -> paid`

### E2E-08 — calendar conflict
`book instructor/vehicle -> overlapping booking rejected`

### E2E-09 — PKK fake provider
`course -> fetch -> operation history -> retryable failure -> retry success`

## 14. Test data

Fixtures/factories:
- nie używają realnych PESEL/loginów z audytowanego konta,
- mają syntetyczne dane,
- jawnie rozdzielają OSK A/OSK B,
- zawierają przypadki bez PESEL, jeśli wspierane.

## 15. CI gates

PR nie przechodzi, jeśli:
- unit/integration tests fail,
- API contract test fail,
- lint/static analysis fail,
- migration validation fail,
- security secret scan fail.

Krytyczne E2E mogą działać na merge/staging, jeśli czas wykonania jest zbyt duży na każdy commit.

## 16. Regression policy

Każdy production bug w core invariant powinien dostać regression test przed lub razem z fixem.

## 17. Definition of Done testowa

Moduł jest gotowy, jeśli ma:
- policy tests,
- cross-tenant tests,
- validation tests,
- happy-path integration,
- audit assertion,
- race/idempotency test tam, gdzie dotyczy,
- co najmniej jedno krytyczne E2E dla głównego flow.
