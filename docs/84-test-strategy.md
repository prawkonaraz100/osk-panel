# 84. Test strategy — core OSK v1

Data: 2026-09-05

**Status:** `ACTIVE_IMPLEMENTATION_POLICY`

## 1. Cel

Testy mają chronić jednocześnie:
- **kompletność funkcjonalną reverse engineeringu**,
- tenant isolation,
- formalne reguły kursu,
- inventory licencji/egzaminów,
- płatności i saldo,
- PKK idempotency/retry,
- kalendarz i konflikty zasobów,
- audyt/activity projection,
- bezpieczeństwo credentials i danych osobowych,
- historyczne lifecycle bez utraty danych.

Nie dążymy do sztucznego 100% line coverage. Krytyczne inwarianty i każdy potwierdzony core flow muszą mieć jawne pokrycie.

Źródła scope testowego:
- `docs/96-reverse-engineering-preservation-contract.md`,
- `specs/reverse-engineering-manifest.yml`,
- właściwe `specs/screens/*.yml`,
- `specs/api/required-operations-v1.yml`.

## 2. Reverse-engineering traceability gate

Dla każdego modułu core tworzymy checklistę:

`evidence -> requirement -> API/use case -> UI -> test`

Każdy potwierdzony element screen spec otrzymuje status:
- `IMPLEMENTED_AND_TESTED`,
- `SUPERSEDED_BY_LEGAL_RULE_AND_TESTED`,
- `SUPERSEDED_BY_OWN_PRODUCT_DECISION_WITH_EQUIVALENT_CAPABILITY_AND_TESTED`,
- `DEFERRED_OUTSIDE_CORE_WITH_EXPLICIT_DECISION`,
- `NOT_A_REQUIREMENT_DEMO_ANOMALY`.

Nie wolno oznaczyć modułu `DONE`, jeśli potwierdzone pole, opcja, filtr, sortowanie, toggle, PDF, entry point albo akcja nie ma statusu.

### Contract-level UI capability tests

Nie muszą być pixel-perfect. Sprawdzają, że:
- wszystkie potwierdzone controls istnieją,
- conditional fields pojawiają się w odpowiednich stanach,
- select ma wymagane capability/options,
- listy przyjmują potwierdzone search/filter/sort,
- akcje kierują do właściwych use cases,
- dokumenty/PDF są dostępne zgodnie z permission.

## 3. Piramida testów

### Unit
Dla:
- rule engine szkolenia,
- value objects,
- state transitions,
- calculation/projection logic,
- error mapping providerów,
- redaction/allowlist policies,
- auth identifier normalization.

### Integration
Najważniejsza warstwa backendu.

Dla:
- Action + DB + Policy,
- transakcji,
- locków,
- partial unique constraints,
- idempotency,
- audit/outbox/activity projection,
- query projections,
- asset readiness/ownership.

### Contract
Dla:
- OpenAPI request/response,
- `specs/api/required-operations-v1.yml` coverage,
- PKK adapter,
- payment provider webhooks,
- storage/mail adapters.

### E2E
Krytyczne user flow **oraz** reprezentatywne reverse-engineered flow. Nie testujemy każdego pixela, ale nie pomijamy potwierdzonych ścieżek.

## 4. Obowiązkowe cross-tenant tests

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
- exams,
- file assets,
- activity feed,
- orders/settings.

## 5. Identity / login tests

- generic login identifier resolves to exactly one current User or none,
- two Users cannot own same current normalized login string,
- one User can have memberships in OSK A and OSK B,
- one User can be linked to StaffProfile in different OSKs through distinct memberships,
- revoked login identifier behavior follows explicit policy,
- login normalization prevents trivial duplicates,
- social provider subject unique per provider,
- password hash only; plaintext never persisted,
- account linking does not trust unverified email alone.

## 6. Race-condition tests

### License activation vs revoke
Równocześnie:
- request A: activate,
- request B: revoke unactivated.

Assertion:
- tylko jeden lifecycle wins,
- inventory nie jest podwójnie zmienione,
- jeden spójny audit trail.

### Concurrent license stacking
Dwa równoległe activation/extension dla jednego `StudentLearningAccount`.

Assertion:
- oba ważne okresy są zachowane,
- `effective_from/effective_to` nie gubią dni,
- projection expiry = max effective_to,
- inventory każdej sztuki consumed exactly once.

### Exam access start
Dwa równoległe starty tego samego accessu.

Assertion:
- jeden `in_progress`,
- inventory consumed exactly once,
- drugi request dostaje conflict/idempotent result.

### Exam station concurrency
Dwie różne próby próbują wystartować na tym samym `ExamStation`.

Assertion:
- maksymalnie jedna active `InternalExamStationSession`.

### Exam failover
Rozpoczęta próba przenoszona na inne stanowisko po technical failure.

Assertion:
- stara station session zakończona,
- nowa wskazuje transfer origin,
- próba nadal jedna,
- druga sztuka inventory NIE jest konsumowana,
- audit/reason istnieje.

### Calendar booking
Dwa bookingi jednego slotu/zasobu.

Assertion:
- maksymalnie jeden booking zgodny z conflict policy.

### Payment webhook
Ten sam provider event kilka razy.

Assertion:
- jedna zmiana stanu/grant,
- dedup po `(provider, provider_event_id)`.

## 7. Idempotency tests

Dla endpointu wymagającego `Idempotency-Key`:

1. pierwszy request -> success,
2. ten sam key + ten sam payload -> ten sam rezultat / bez duplikatu,
3. ten sam key + inny payload -> `IDEMPOTENCY_KEY_REUSED_WITH_DIFFERENT_PAYLOAD`,
4. dwa równoległe requesty z tym samym key -> jeden claim/effect,
5. `safe_response_snapshot` nie zawiera secretu/one-time password/tokenu.

## 8. PKK tests

**Current runtime boundary:** provider-specific PKK/PWPW runtime jest `FROZEN_UNTIL_EXPLICIT_UNFREEZE`. Obecne executable tests pokrywają lokalną course-scoped identity PKK, szyfrowanie/lookup rotation oraz provider-neutralne Stage 4 DB guards bez provider I/O. Poniższa macierz fake/sandbox jest **przyszłym planem adapter tests** i zaczyna obowiązywać dopiero po otrzymaniu oraz zweryfikowaniu autorytatywnego kontraktu PWPW.

Provider fake/sandbox po odmrożeniu musi obsługiwać:
- success,
- business validation error,
- unauthorized integration,
- timeout,
- 5xx,
- duplicate/replayed command,
- delayed result/reconciliation scenario,
- return to other OSK,
- return to authority,
- return expired profile,
- provider flow wymagający signed XML handoff, jeśli adapter go wspiera.

Assertion:
- PKK owner = CourseEnrollment,
- business errors nie są automatycznie retryowane,
- retryable transport errors tworzą kolejny attempt, nie nową niezależną operację biznesową,
- każda próba ma correlation ID/audit,
- UI-safe snapshot jest redacted,
- pełny provider payload nie trafia do plain audit/log,
- retry nie powtarza nieodwracalnego skutku po unknown state bez reconciliation.

## 9. Rule engine tests

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

Legal category dictionary, w tym `PT`, musi mieć osobne testy po końcowej re-weryfikacji.

## 10. Course form / training time tests

### Preservation tests dla reverse-engineered pól
Create/edit course musi obsługiwać:
- training type,
- category,
- PKK,
- start date/time,
- cost context,
- theory current OSK,
- theory previous OSK,
- practice current OSK,
- practice previous OSK,
- instructor,
- location.

### Source-of-truth tests
- theory conversion 45 min,
- practical conversion 60 min,
- `declared_theory_minutes`/`declared_practical_minutes` nie zwiększają formalnego credited time same z siebie,
- current OSK totals from ledger,
- external recognized training separate,
- initial previous-school values create RecognizedExternalTraining,
- import opening balance wymaga explicit mode + reason/audit,
- correction does not mutate original ledger entry,
- cancelled session does not credit time,
- cross-tenant session relation rejected.

## 11. Student finance tests

- create charge from observed title+amount flow,
- optional initial course cost -> StudentCharge,
- partial payment,
- full payment,
- overpayment policy,
- reversal,
- cancelled charge,
- decimal/minor units precision,
- no hard-delete after payment,
- balance projection deterministic,
- audit contains amount/reference but not unnecessary sensitive profile data.

## 12. Credentials/security tests

- plaintext password never persisted,
- password absent from audit/logs/outbox/idempotency store,
- handoff PDF generation audited,
- old password not recoverable,
- reset invalidates/updates credentials according to auth policy,
- permission required for reset/download,
- QR never contains plaintext password,
- printed login resolves globally without tenant ambiguity.

## 13. FileAsset tests

- user filename never becomes trusted storage path,
- cross-tenant attachment denied,
- mismatched MIME detected/rejected according policy,
- oversized file rejected,
- entity cannot attach asset before `ready`,
- rejected scan cannot be served,
- signed download expires,
- deleting/archive parent does not accidentally destroy retained formal asset,
- asset hash/metadata deterministic.

## 14. Permissions tests

Dla każdej permission group:
- allow,
- deny,
- scope deny,
- cross-tenant deny,
- revoked permission effect.

Elevated permissions mają osobne testy.

Staff type change nie może nadawać permission poza jawnie skonfigurowanym role template/process.

## 15. Audit + activity feed tests

### Audit
- append-only,
- correct actor/org/request ID,
- critical mutation recorded,
- before/after redacted,
- no password/token/full PESEL/full PKK by default.

### OrganizationActivityEvent
- powstaje po committed domain event,
- tenant scoped,
- zachowuje timestamp/actor/subject,
- reprezentuje zaobserwowane dashboard event types,
- `Rozwiń` może otrzymać safe redacted diff,
- raw audit JSON nie jest bezpośrednio serializowany do dashboardu,
- allowlist blokuje sensitive keys.

## 16. Internal exam result/document tests

- 32-question immutable snapshot dla observed theory result shape,
- 20 basic + 12 specialized w observed category/test mode,
- result `score/max_score` immutable,
- question review pokazuje candidate answer/correctness/media snapshot,
- answer-sheet PDF jest związany z konkretnym attemptem,
- ponowne otwarcie historycznego wyniku nie używa „dzisiejszej” wersji pytania,
- PDF/download permission i tenant scope.

Nie hardkodujemy 32/20/12 globalnie dla przyszłych innych exam capabilities; snapshot/capability decyduje.

## 17. API contract tests

- wszystkie HTTP operations z `specs/api/required-operations-v1.yml` mają `operationId` w OpenAPI albo jawny non-HTTP classification,
- status codes,
- error envelope,
- pagination meta,
- sort whitelist,
- filter schema,
- money shape,
- date/time shape,
- request_id,
- idempotency behavior,
- optimistic concurrency,
- sensitive fields absent from unsafe responses.

CI powinno walidować OpenAPI parserem i automatycznie porównywać required operations z operationIds.

## 18. E2E core flows

To jest docelowa strategia browser E2E. **Repozytorium nie ma jeszcze materializowanego browser E2E suite**; jest to bieżąca luka productization w `docs/227-current-project-status-authority.md`.

Minimum:

### E2E-01 — nowy kursant i kurs
`create student -> create course with all observed blocks -> calculated requirements -> visible in detail`

### E2E-02 — student list controls
`search -> confirmed filters -> confirmed sort -> quick preview -> full detail`

### E2E-03 — zajęcia i formalne godziny
`create lesson -> complete -> attendance -> ledger -> totals`

### E2E-04 — import/external hours
`course form external hours -> RecognizedExternalTraining -> projection -> correction history`

### E2E-05 — licencja
`inventory -> choose one target -> learning account -> assign -> activate -> effective period -> visible active`

### E2E-06 — revoke license before activation
`assign -> revoke -> inventory restored exactly once -> same inventory can later be assigned again`

### E2E-07 — license bulk PDF
`select multiple visible accesses -> one combined localized PDF`

### E2E-08 — egzamin remote
`course -> required exam -> reserve -> remote link -> start -> consumed -> finish -> result -> question review -> PDF`

### E2E-09 — egzamin local station
`select exactly one student/course -> create local access -> start on station -> one active candidate -> finish`

### E2E-10 — egzamin standalone entry
`standalone generate -> search persistent student -> choose course -> same formal generation flow`

### E2E-11 — exam station failover
`start local -> technical fail -> transfer to second station -> finish without second credit`

### E2E-12 — student finance
`charge -> partial payment -> balance -> next payment -> paid`

### E2E-13 — calendar
`month/week/day -> resource filters -> custom meeting place -> conflict handling`

### E2E-14 — locations/staff/vehicles
`create with observed multi-selects/validities -> detail -> calendar context -> archive/restore`

### E2E-15 — PKK fake provider — DEFERRED
`course -> fetch -> operation history -> retryable failure -> retry success -> safe snapshot`

Ten scenariusz pozostaje wyłączony z bieżącego Core test scope do czasu explicit PKK/PWPW unfreeze po otrzymaniu zweryfikowanych wymagań PWPW.

### E2E-16 — dashboard
`domain operations -> safe activity feed -> license/exam counters -> embedded calendar`

### E2E-17 — credentials handoff
`secretary creates/reset password -> sees one-time plaintext -> downloads PDF -> later plaintext cannot be retrieved`

## 19. Test data

Fixtures/factories:
- nie używają realnych PESEL/loginów/PKK z audytowanego konta,
- mają syntetyczne dane,
- jawnie rozdzielają OSK A/OSK B,
- zawierają przypadki bez PESEL,
- obejmują tę samą globalną osobę w dwóch organizacjach,
- obejmują history revoked/released license/exam records.

## 20. CI gates

PR nie przechodzi, jeśli:
- unit/integration tests fail,
- OpenAPI parser/contract test fail,
- required-operation coverage fail,
- reverse-engineering traceability dla zmienianego modułu jest niekompletne,
- lint/static analysis fail,
- migration validation fail,
- security secret scan fail.

Krytyczne E2E mogą działać na merge/staging, jeśli czas wykonania jest zbyt duży na każdy commit, ale smoke E2E dla zmienionego modułu powinno działać przed merge.

## 21. Regression policy

Każdy production bug w core invariant powinien dostać regression test przed lub razem z fixem.

Każde znalezione w trakcie implementacji pominięcie reverse-engineered capability dostaje regression/traceability test, żeby kolejne refaktory go nie zgubiły.

## 22. Definition of Done testowa

Moduł jest gotowy, jeśli ma:
- complete reverse-engineering traceability,
- policy tests,
- cross-tenant tests,
- validation tests,
- happy-path integration,
- audit/activity assertion,
- race/idempotency test tam, gdzie dotyczy,
- contract coverage w OpenAPI,
- dokument/PDF test, jeśli screen spec go potwierdza,
- co najmniej jedno krytyczne E2E dla głównego flow,
- jawne statusy dla wszystkich świadomie odroczonych elementów.
