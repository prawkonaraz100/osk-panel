# 115. Stage 4 — Audit / Outbox / Notifications database audit

Data: 2026-09-09

**Etap:** `DB4_10_AUDIT_OUTBOX_NOTIFICATIONS`
**Aktualny krok:** `DB-OUT-002`
**Status:** `FAIL_WITH_4_P1_BLOCKERS / 0 P0 / 4 P1 OPEN`

Machine-readable diagnoza: `specs/database/audit-outbox-notifications.yml`.

---

## 1. Zakres diagnozy

DB4_10 domyka fizyczny model czterech powiązanych, ale różnych konceptów:

1. `audit_logs` — ograniczona, historyczna ewidencja krytycznych operacji biznesowych i bezpieczeństwa,
2. `outbox_messages` — techniczna kolejka publikacji committed domain/event intentów,
3. `organization_activity_events` — bezpieczna projekcja zdarzeń dla dashboardu,
4. `notifications` — powiadomienia użytkownika/organizacji z własnym stanem odczytu.

Nie wolno tych konceptów scalić w jeden „event table”. Audit nie jest dashboard feedem. Outbox nie jest business ledgerem. Powiadomienie nie jest audytem. Dashboardowa karta nazwana w UI `Powiadomienia` została wcześniej zidentyfikowana jako **organization activity feed** i pozostaje osobnym konceptem od API `/notifications`.

Diagnoza nie zmienia Stage-3 API, nie modyfikuje `core-schema.yml` ani `docs/87...`, nie tworzy migracji Laravel i nie rozpoczyna DB4_11 ani Stage 5.

Frozen aggregate blobs:
- `specs/database/core-schema.yml` = `f3519060967085517329bc668fac02e5c1fd2dbd`,
- `docs/87-physical-database-schema.md` = `f3ebb764a5279cbabc9c75c2fcf9733f36ed22a8`.

## 2. Potwierdzone wymagania, które muszą zostać zachowane

### Audit

Potwierdzone są:
- append-only history,
- actor / organization / entity / request context,
- audyt krytycznych zmian permissions, PKK, godzin formalnych, licencji, egzaminów, finansów i credentials,
- redacted/allowlisted before/after albo domain diff,
- `reason` dla operacji wysokiego ryzyka tam, gdzie wcześniejsze slice’y go wymagają,
- zakaz zapisu plaintext password, password hash, tokenów, pełnego PESEL i pełnego PKK w zwykłym audit payloadzie.

### Activity feed

Dashboard nie czyta raw audit JSON. Potwierdzony activity feed wymaga:
- tenantowego eventu,
- typu zdarzenia,
- czasu,
- aktora,
- subject/related entity,
- bezpiecznego opisu i metadata,
- opcjonalnego `Rozwiń`, target link i payment link tam, gdzie zaobserwowano taki capability.

### Outbox

Wiele zamkniętych slice’ów wymaga:

`business state + audit + outbox intent` **w tej samej transakcji**.

Production policy wymaga także retry, backoff, failed-job visibility, reconciliation i manual replay z audytem dla krytycznych jobów.

### Notifications

Stage-3 API potwierdza:
- listę powiadomień,
- `unread_only`,
- `mark read`,
- aktywny membership jako boundary odczytu,
- own-notification scope dla `mark read`,
- idempotency key dla operacji mark-read.

Nie mamy podstaw do dodawania teraz e-mail/SMS/push jako obowiązkowego kanału DB4_10.

## 3. Stan provisional physical model

### `audit_logs`

Obecny blueprint posiada `organization_id nullable`, `actor_user_id nullable`, action/entity/request context oraz redacted JSON. Problem: deklaracja „append-only” nie ma jeszcze domkniętej fizycznej polityki update/delete, actor nie jest związany z exact tenant membership context, a polymorphic entity reference nie ma zamkniętej same-tenant validation boundary.

### `organization_activity_events`

Ma tenant, event type, actor, subject, related student, `safe_payload`, `occurred_at` i luźny `source_event_id varchar`. Brakuje exact source relation, dedupe i zamkniętego deterministic order.

### `outbox_messages`

Ma aggregate/event/payload/request oraz `published_at`, `attempts`, `last_error`. Brakuje tenant context, stabilnego source/domain-event identity, publisher claim/lease i pełnej retry/reconciliation state machine.

### `notifications`

Ma tenant, nullable `user_id`, payload i pojedyncze `read_at`. To nie domyka organization-wide notification semantics, ponieważ jeden wspólny `read_at` nie może oznaczać stanu odczytu wielu użytkowników.

---

## 4. Diagnoza blockerów

### DB-AUD-001 — audit append-only + ownership/actor/entity context integrity — P1

Problem:
- append-only jest tylko deklaracją,
- organization audit API musi zawsze pozostać tenant-scoped,
- globalny `User` może należeć do kilku OSK, więc samo `actor_user_id` nie dowodzi, w jakim membership context działał,
- polymorphic `entity_type + entity_id` może wskazywać obiekt bez sprawdzalnej relacji do Organization.

Ryzyko:
- edycja/usunięcie historii przez normalną rolę aplikacyjną,
- niejednoznaczny actor context,
- wrong-tenant entity reference,
- leakage z `/audit-logs`.

Fixer musi domknąć tylko tę granicę. Nie może jeszcze rozwiązywać redaction policy ani outboxu.

### DB-AUD-002 — safe payload/redaction provenance + elevated reason — P1

`before_redacted_json` i `after_redacted_json` są nadal zbyt otwartym JSONB, jeśli nie ma zamkniętej validation/allowlist boundary. Historyczny rekord nie ma też provenance wersji redaction policy.

Dodatkowo kilka wcześniejszych high-risk commandów wymaga reason, ale DB4_10 nie ma jeszcze spójnego kontraktu kompletności `action class -> reason required`.

Ryzyko:
- sekret/PII w długowiecznym audycie,
- brak możliwości wykazania, jaką polityką redakcji powstał historyczny payload,
- high-risk correction bez reason.

### DB-OUT-001 — durable event identity + transactional coupling — P1

Zamknięte slice’y już wymagają atomic business state + audit + outbox, lecz obecny outbox:
- nie ma tenant context,
- nie ma stabilnej event identity odróżnionej od `request_id`,
- activity ma tylko luźny source string,
- notifications nie mają source event.

Ryzyko:
- committed state bez recoverable projection intentu,
- duplikaty projection przy retry,
- cross-tenant routing,
- brak przejrzystej causation/correlation chain.

Outbox nadal nie może stać się business source of truth.

### DB-OUT-002 — publisher concurrency / retry / reconciliation — P1

`published_at + attempts + last_error` nie wystarcza dla dwóch workerów, lease expiry, backoff, failed visibility i manual replay.

Szczególnie ważny przypadek:
1. external publish succeeds,
2. DB update `published_at` fails,
3. worker retryuje.

Nie wolno obiecywać exactly-once external delivery. Model ma być retry-safe i co najmniej at-least-once z deduplikacją downstream.

### DB-ACT-001 — activity source/dedupe/order/history display — P1

Dashboard feed wymaga bezpiecznej historycznej projekcji, ale:
- `source_event_id` nie jest exact relation,
- projector retry może wstawić drugi wpis,
- actor display/role snapshot może zmienić się historycznie, jeśli projection odczytuje bieżący User/Role,
- list ordering nie jest domknięty.

Fixer musi zachować safe allowlisted payload i nie może serializować raw audit before/after.

### DB-NOT-001 — recipient membership / organization audience / read-state integrity — P1

Obecne `user_id nullable + read_at` ma błąd modelowy dla organization-wide notification:
- jeśli jeden row jest „dla organizacji”, jeden użytkownik nie może ustawić `read_at` bez zmiany stanu dla pozostałych,
- ten sam globalny User może mieć membership w wielu OSK,
- target musi być udowodniony jako należący do exact Organization context.

Potrzebna jest exact tenant-recipient boundary. Nie rozstrzygamy jeszcze source dedupe ani mark-read lifecycle — to DB-NOT-002.

### DB-NOT-002 — notification source/dedupe/mark-read idempotency — P1

Brak exact source event oznacza, że retry outbox/projectora może wygenerować kilka identycznych notification rows dla jednego recipienta.

`read_at` nie ma jeszcze write-once transition contract. API natomiast wymaga idempotent `mark read` dla własnego notification.

Fixer ma domknąć:
- exact source + recipient dedupe,
- authorized mark-read root,
- `NULL -> non-NULL` once,
- retry bez drugiego skutku.

Nie dodajemy `mark unread` ani delete, bo Stage-3 API tego nie wymaga.

### DB-EVT-001 — legacy backfill / retention / cleanup safety — P1

Po zamknięciu poprzednich blockerów legacy rows mogą nie mieć:
- source event,
- recipient membership,
- redaction policy version,
- publisher state/lease metadata.

Migracja nie może zgadywać lineage po timestampie, tym samym tenant, actorze czy podobnej nazwie. Nie wolno też tworzyć sztucznego `read_at` ani fabricated source event tylko po to, żeby constraint przeszedł.

Audit jest append-only w normalnym runtime, ale production privacy policy nadal wymaga retention per data class. Exact durations są zewnętrznym production blockerem i **nie wolno ich wymyślić w DB4_10**. Model musi jedynie rozdzielić normalną immutability od privileged policy-driven retention execution.

Outbox jest technical delivery history, więc cleanup nie może usuwać business authority ani być wykorzystywany do odtwarzania biznesowej historii.

---

## 5. Kolejność fixerów

Dependency order:

`DB-AUD-001 -> DB-AUD-002 -> DB-OUT-001 -> DB-OUT-002 -> DB-ACT-001 -> DB-NOT-001 -> DB-NOT-002 -> DB-EVT-001`

Ta kolejność jest celowa:
- najpierw zamykamy audit boundary,
- potem canonical event/outbox source,
- dopiero później projekcje activity/notifications,
- legacy migration safety na końcu może wtedy odwołać się do finalnych struktur.

## 6. Preservation gate diagnozy

PASS:
- DB4_1..DB4_9 pozostają bez zmian,
- dashboardowa karta `Powiadomienia` nadal jest organization activity feed,
- osobne API notifications pozostaje zachowane,
- raw audit nie staje się dashboard feedem,
- Student Finance i Platform Commerce pozostają oddzielne,
- PKK/password/token redaction rules pozostają obowiązujące,
- nie dodano e-mail/SMS/push,
- nie wymyślono exact retention durations,
- `core-schema.yml` i `docs/87...` są zamrożone,
- Stage-3 API nieruszony,
- DB4_11 nie rozpoczęty,
- Stage 5 nierozpoczęty,
- Laravel migrations nieutworzone,
- UI/feature implementation nierozpoczęte.

## 7. Wynik diagnozy

- P0: **0**,
- P1: **8**,
- fixes applied in diagnosis: **0**,
- resolved: **4/8**,
- open: **4/8**,
- result: **FAIL_WITH_4_P1_BLOCKERS**.

## 8. DB-AUD-001 — wynik fixera: PASS

DB-AUD-001 zamyka fizyczną granicę własności i niezmienności `audit_logs`, bez rozwiązywania jeszcze zawartości payloadów/redakcji z DB-AUD-002.

### 8.1. Scope audytu

Każdy nowy runtime row ma jawne, immutable `audit_scope`: `organization` albo `platform_global`. Dla `organization` wymagane jest server-derived `organization_id`; dla `platform_global` `organization_id` musi być `NULL`. Nie wolno pozostawiać scope implicit na podstawie samej nullowalności tenant id.

`GET /audit-logs` pozostaje wyłącznie organization-scoped: wymaga aktywnego selected membership i `organization.audit.view`, a query zawsze zaczyna się od `audit_scope=organization AND organization_id=current organization`. Globalne i obce tenantowo rekordy nie mogą pojawić się w tym endpointcie. Filtry entity/request działają dopiero wewnątrz tej granicy. Kolejność jest deterministyczna: `created_at DESC, id DESC`.

### 8.2. Actor context

Jawny `actor_kind` ma trzy wartości: `organization_membership`, `global_user`, `system`.

Dla organizacyjnego użytkownika sam `actor_user_id` jest niewystarczający. Audit zapisuje również `actor_organization_membership_id`, a DB wymusza exact composite relation `(organization_id, membership_id, user_id)` do `organization_memberships`. Dzięki temu ten sam globalny User należący do dwóch OSK nie może zostać przypisany do niewłaściwego tenant contextu. Status membership w przyszłości nie kasuje historii — authorization jest sprawdzane w chwili command write, a audit przechowuje historyczną referencję.

`global_user` jest dopuszczony wyłącznie dla `platform_global`; organization-scoped business mutation nie może użyć globalnego User bez membership context. `system` ma null user/membership i może działać globalnie lub w exact organization scope. Actor/scope są server-derived, nigdy authority z request body.

### 8.3. Polymorphic entity reference

Nie tworzymy fałszywego uniwersalnego FK. `entity_reference_mode` rozróżnia `none`, `tenant_relational`, `global_relational`, `snapshot_only`.

Dla `tenant_relational` closed allowlist + DEFERRABLE constraint trigger (lub równoważny DB guard) musi dowieść istnienia exact entity w `audit.organization_id`; unknown type albo wrong-tenant target jest reject. `global_relational` dowodzi tylko istnienia globalnego obiektu i nie udaje tenant ownership. `snapshot_only` jest jawnie allowlisted dla external/nonrelational referencji i nie może być traktowane jako potwierdzony FK; sensitive identifier w `entity_id` jest zabroniony. Nie wolno inferować modelu po nazwie, UUID-shape albo podobieństwie.

### 8.4. Append-only

Normalna rola aplikacyjna może insertować wymagany audit i czytać tylko przez autoryzowaną projekcję; UPDATE i DELETE są zabronione zarówno privilege boundary, jak i `BEFORE UPDATE OR DELETE` guardem (lub równoważnym). Wszystkie business fields audytu są immutable po insert.

Wyjątek policy-driven retention nie jest definiowany tutaj — pozostaje DB-EVT-001 wraz z exact retention policy. DB-AUD-001 nie wymyśla okresów przechowywania i nie daje normalnej aplikacji furtki do kasowania historii.

### 8.5. Atomic write i legacy boundary

Tam, gdzie wcześniejszy slice wymaga `business state + audit`, audit insert jest częścią tej samej transakcji; failure audytu rollbackuje command. Audit nadal nie jest business source of truth.

Nie backfillujemy teraz starych actor membership/entity lineage heurystycznie. Legacy rows bez wystarczającego dowodu pozostają problemem DB-EVT-001; po runtime write fence wszystkie nowe rekordy muszą spełniać DB-AUD-001.

### 8.6. Co pozostaje OPEN

DB-AUD-001 nie zamyka: redaction/payload-policy/reason (DB-AUD-002), durable outbox event identity (DB-OUT-001), publisher retry (DB-OUT-002), activity projection (DB-ACT-001), notification recipient/read lifecycle (DB-NOT-001/002) ani legacy/retention cleanup (DB-EVT-001).

Po fixerze DB-AUD-001 pozostaje **PASS**.

## 9. DB-AUD-002 — wynik fixera: PASS

DB-AUD-002 zamyka safe-payload/redaction provenance oraz reason completeness bez rozszerzania katalogu biznesowych akcji. Nie definiuje jeszcze durable event identity/outboxu.

### 9.1. Immutable action-policy revisions

Powstaje immutable `audit_action_policy_revisions`. Klucz `(action, policy_version)` wskazuje dokładną wersję kontraktu użytego do zbudowania audytu. Revision zapisuje versioned `payload_validator_code`, wymagania dla before/after (`forbidden|optional|required`), `reason_requirement` (`optional|required`) oraz `policy_hash`. Normalna aplikacja nie może aktualizować ani usuwać revision.

DB-AUD-002 nie tworzy nowych biznesowych action names. Rejestrujemy tylko akcje już wymagane albo emitowane przez potwierdzone kontrakty domenowe. Zmiana allowed payload shape, redaction semantics lub reason requirement oznacza **nową revision**, nigdy przepisywanie starej.

Osobny current pointer `audit_action_policy_currents(action, policy_version)` wskazuje revision obowiązującą dla nowych runtime writes. `audit_logs.audit_policy_version` ma exact FK do `(action, policy_version)`, a nowy runtime insert musi użyć dokładnie current revision. Klient nie wybiera policy version i nie może użyć starej słabszej wersji po zmianie policy. Historyczne rekordy nadal wskazują revision, z którą zostały zapisane.

### 9.2. Safe payload zamiast raw serialization

`before_redacted_json` i `after_redacted_json` nie są już arbitrary JSONB. Semantycznie są wyłącznie allowlisted safe projection/domain diff. Gdy pole jest nie-NULL, top-level musi być JSON object, a versioned validator dla exact action-policy revision sprawdza dozwolone keys/paths, typy i zagnieżdżenia. Unknown key/path, nieznany validator, catch-all metadata oraz raw ORM/request/provider serialization są reject.

Finalna granica persistence to DB trigger/constraint guard (lub równoważny DB guard), ale payload jest najpierw budowany przez typed application allowlist. DB validation nie zastępuje data minimization — jest drugim, fail-closed poziomem.

Globalnie zabronione pozostają m.in. plaintext/reversible passwords i one-time credentials, password hashes/verifiers, auth/session/reset/access tokens, provider credentials/secrets/signatures/private keys, pełny PESEL, pełny PKK, external OSK credentials, payment-card/provider secret material, secret-bearing file/PDF bytes oraz raw provider request/response payloads. Samo przemianowanie sensitive value pod innym kluczem nie daje obejścia, bo arbitrary keys nie przechodzą allowlisty.

Masked/minimized identifier albo non-secret evidence/correlation hash może wystąpić tylko wtedy, gdy exact validator jawnie pozwala jego semantykę i format. Zachowujemy potrzebne domenowo bezpieczne klasy danych — np. resource IDs, status transitions, category/language, money minor units/currency, formal time/duration oraz masked identifiers — ale **nie robimy z nich globalnej allowlisty**.

### 9.3. Reason integrity

`reason` pozostaje immutable razem z audit row. Revision określa `reason_requirement`. Dla `required` DB odrzuca NULL, pusty i whitespace-only reason. Dla `optional` NULL jest dozwolony, ale podany reason również nie może być pusty/whitespace i nie może służyć jako kanał do kopiowania raw request/provider payloadu lub secretu.

DB-AUD-002 nie zgaduje, które akcje wymagają reason. Jeżeli wcześniejszy kontrakt domenowy już wymaga reason (np. konkretna correction/invalidation ścieżka), jego revision musi mieć `reason_requirement=required`; seed/migration guard nie może obniżyć takiego upstream requirement do `optional`. Sam fakt elevated permission nie tworzy automatycznie nowego requirement bez wcześniejszego kontraktu.

### 9.4. Policy history i transactional failure

Każda zmiana policy tworzy nową immutable revision i przesuwa current pointer. Stare audyty nie są re-redagowane ani przepinane do nowej revision. Dzięki temu historycznie wiadomo, według którego kontraktu payload został zapisany.

Tam, gdzie wcześniejszy slice wymaga audit w tej samej transakcji co business mutation, błąd payload validatora albo brak wymaganego reason rollbackuje cały command. Audit nadal nie staje się business source of truth.

### 9.5. Legacy boundary

Nie przypisujemy starym audit rows policy version po timestampie, nazwie action, podobieństwie payloadu ani aktualnej policy. Legacy provenance/backfill/quarantine pozostaje DB-EVT-001. Po runtime write fence wszystkie nowe audyty muszą używać exact current revision.

### 9.6. Co pozostaje OPEN

DB-AUD-002 nie zamyka durable event/outbox identity (DB-OUT-001), publisher retry/reconciliation (DB-OUT-002), activity projection (DB-ACT-001), notification recipient/read lifecycle (DB-NOT-001/002) ani legacy/retention cleanup (DB-EVT-001). Exact privacy retention durations nadal nie są wymyślane.

Po fixerze DB-AUD-002 pozostaje **PASS**.

## 10. DB-OUT-001 — wynik fixera: PASS

DB-OUT-001 zamyka trwałą tożsamość domenowego zdarzenia oraz atomowe powiązanie business effect + wymagany audit + domain event + outbox intent. Nie definiuje jeszcze publisher retry/lease/DLQ — to pozostaje DB-OUT-002.

### 10.1. `domain_events` jako trwała tożsamość

Powstaje append-only `domain_events`. `id` jest server-generated, globalnie unikalnym i immutable `event_id`. To właśnie ten identyfikator jest canonical source identity dla późniejszych projekcji activity i notifications. `request_id` pozostaje wyłącznie correlation dla request/job chain: nie jest unique, może obejmować wiele eventów i nie może służyć jako event dedupe ani tenant authority.

Każdy event ma jawny `event_scope`: `organization` albo `platform_global`. Organization event wymaga server-derived `organization_id`; global event wymaga `organization_id=NULL`. Event type pochodzi wyłącznie z potwierdzonego katalogu kontraktów domenowych — DB-OUT-001 nie tworzy marketingowych ani nieobserwowanych event types.

### 10.2. Causation i aggregate reference nie zastępują event identity

Opcjonalny `causation_event_id` ma exact FK do istniejącego `domain_events.id`, ale nie jest zgadywany po request id, timestampie ani podobieństwie. Causation nie nadpisuje scope/organization bieżącego eventu.

`aggregate_type/aggregate_id` opisują subject/business aggregate, a nie event. `aggregate_reference_mode` rozróżnia `none`, `tenant_relational`, `global_relational`, `snapshot_only`. Tenant relational target przechodzi closed allowlist + exact same-tenant guard; snapshot-only nie udaje FK i nie może przenosić sensitive identifier w aggregate id.

### 10.3. Powiązanie z wymaganym audytem

`domain_events.required_audit_log_id` jest wymagane tam, gdzie wcześniejszy slice już wymaga audytu dla event-producing mutation. Ma exact FK do `audit_logs.id`, a DB guard wymusza zgodność scope/organization. Nie kopiujemy raw audit payloadu do eventu, a audit nadal nie staje się business source of truth.

### 10.4. Exact binding `outbox_messages -> domain_events`

Nowy runtime outbox row wymaga `domain_event_id`, `event_scope` i `organization_id`. `domain_event_id` ma exact FK do `domain_events.id` oraz uniqueness — jeden committed logical domain event ma dokładnie jeden lokalny outbox intent.

Event scope, tenant, event type, aggregate snapshot i request id w outboxie są server-copied i muszą odpowiadać dokładnie source domain eventowi. Outbox `id` jest wyłącznie techniczną identity wiadomości; nie zastępuje event id i nie staje się business authority. Outbox payload pozostaje publisher-safe/secret-free i nie może być raw auditem ani raw provider response.

### 10.5. Jedna transakcja lokalna

Jeżeli wcześniejszy kontrakt wymaga outboxu, jedna lokalna transakcja obejmuje: canonical business effect, wymagany audit, exactly one `domain_event` dla logical event oraz exactly one outbox intent. Kolejność: istniejący domain lock/race winner -> business write -> audit -> domain event -> outbox -> commit.

Failure wymaganej części rollbackuje business effect. Zabroniony jest normalny model `commit business, potem best-effort insert outbox` oraz późniejsze odtwarzanie eventów przez polling tabel biznesowych. External publish odbywa się dopiero po commit; network call wewnątrz business transaction jest zabroniony. Losing concurrent command i no-op idempotent replay nie emitują drugiego eventu/outboxu.

### 10.6. Source dla activity i notifications

Przyszłe `organization_activity_events.source_event_id` i notification source mają wskazywać `domain_events.id`, nie `outbox_messages.id`, `request_id` ani timestamp. Dla organization projection tenant musi odpowiadać source event tenantowi. Exact projection dedupe/display rules pozostają odpowiednio DB-ACT-001 i DB-NOT-001/002.

Dzięki temu późniejszy cleanup technicznego outboxu nie niszczy lineage projekcji.

### 10.7. Legacy boundary i zakres stop

Nie tworzymy synthetic domain events tylko po to, aby stare rekordy przeszły constraints. Legacy outbox/projection lineage bez exact evidence nie jest dopasowywany po timestampie, request id, aggregate similarity ani samym tenant. Backfill/quarantine i phased activation pozostają DB-EVT-001.

DB-OUT-001 nie zamyka publisher claim/retry/backoff/DLQ/manual replay (DB-OUT-002), activity display/dedupe/order (DB-ACT-001), notification recipient/read/source lifecycle (DB-NOT-001/002) ani legacy retention cleanup (DB-EVT-001).

Po fixerze DB-OUT-001 pozostaje **PASS**.

## 11. DB-OUT-002 — wynik fixera: PASS

DB-OUT-002 domyka techniczny lifecycle publikacji outboxu. Nie zmienia canonical event identity z DB-OUT-001 i nie udaje exactly-once delivery.

### 11.1. Publication state i lease fencing

`outbox_messages.publication_state` ma cztery stany: `pending`, `leased`, `published`, `requires_reconciliation`. Nowy rekord zaczyna jako `pending`; `published` jest terminalny dla normalnego publishera, a `requires_reconciliation` nie jest automatycznie claimowany.

Claim jest atomowy i oparty o exact outbox row. Worker może przejąć due `pending` albo wygasły `leased` row przez row lock / `UPDATE ... RETURNING` / `SKIP LOCKED` lub równoważny mechanizm. Przy claimie powstaje opaque `lease_token`, rośnie monotoniczny `lease_version`, lifetime `attempts` oraz `attempts_in_cycle`, a `leased_by` i `lease_expires_at` stają się wymagane.

Każdy późniejszy ACK/failure transition wymaga zgodności `message_id + lease_token + lease_version`. Stary worker po wygaśnięciu lease nie może oznaczyć rekordu jako published ani nadpisać wyniku nowszego workera.

### 11.2. Retry, backoff i najtrudniejszy ACK race

Publish ACK przeprowadza wyłącznie aktualny lease `leased -> published` i zapisuje `published_at` tylko raz. Retryable albo ambiguous failure przed limitem przechodzi `leased -> pending` z server-derived `next_attempt_at`. Non-retryable failure albo wyczerpanie cyklu przechodzi do `requires_reconciliation`.

Jeżeli external publish faktycznie się udał, ale proces padł albo DB ACK nie został zapisany, rekord może pozostać `leased` do wygaśnięcia i później zostać dostarczony ponownie. To jest świadome zachowanie **at-least-once**. `published_at` oznacza, że co najmniej jeden publish call został potwierdzony publisherowi; nie dowodzi jednej i tylko jednej dostawy ani exactly-once processing po stronie odbiorcy.

### 11.3. At-least-once i deduplikacja

Canonical dedupe identity dla konsumenta pozostaje `domain_event_id`. Nie wolno używać w tej roli `request_id`, `outbox_messages.id`, timestampu ani aggregate id.

Wewnętrzny consumer wykonujący side effect musi utrwalić dedupe po `domain_event_id` przed albo atomowo z efektem. Konkretne unique constraints dla activity i notifications zostają zamknięte odpowiednio w DB-ACT-001 i DB-NOT-002. Dla zewnętrznego destination przekazujemy `domain_event_id` jako idempotency/dedupe key, jeśli destination to wspiera. Brak takiego wsparcia nigdy nie uprawnia nas do reklamowania exactly-once.

### 11.4. Safe failure metadata

`last_error` jest wyłącznie krótkim, bezpiecznym summary. Nie może zawierać raw exception dump, stack trace z danymi, raw provider request/response, tokenów, sekretów, pełnego PESEL/PKK ani credentials OSK. Osobny allowlisted `last_error_code`, timestamp błędu i operacyjne correlation references wystarczają do diagnostyki; pełne bezpieczne szczegóły należą do security-compliant logs.

### 11.5. Reconciliation i manual replay

Reconciliation musi widzieć `requires_reconciliation`, exhausted/invalid pending rows, stare expired leases i naruszenia state matrix. Nie wolno markować rekordu jako published tylko dlatego, że ma dużo attempts, podobny timestamp albo wygląda na wysłany.

Manual replay jest wyłącznie uprzywilejowaną operacją techniczną, nie nowym publicznym UI/API. Jest dozwolony tylko z `requires_reconciliation`, wymaga dokładnego row lock, obowiązkowego audytu z niepustym reason i exact entity reference do outbox message. Replay zwiększa `replay_count`, resetuje wyłącznie `attempts_in_cycle`, wraca do `pending`, zachowuje ten sam `outbox id` i `domain_event_id`, lifetime attempts oraz historyczne audit evidence. Nie powstaje drugi domain event ani drugi outbox row.

### 11.6. Locking, legacy i granica stop

Network publish nie odbywa się podczas trzymania business aggregate lock ani wewnątrz krótkiej DB ACK transaction. Publisher nie może re-enterować lock graphu DB4_9 podczas posiadania lease. Batch claim ma deterministyczny order `next_attempt_at ASC, created_at ASC, id ASC`.

Legacy rows bez wiarygodnego final publication state, lease/policy metadata albo bezpiecznego error evidence nie są tutaj zgadywane. Sam `published_at` albo `attempts` nie dowodzi exactly-once delivery. Backfill/quarantine pozostaje DB-EVT-001.

DB-OUT-002 nie zamyka activity projection (DB-ACT-001), notification recipient/read/source lifecycle (DB-NOT-001/002) ani legacy retention cleanup (DB-EVT-001).

Po fixerze: **4/8 resolved, 4 P1 OPEN**. Następny dozwolony krok: **DB-ACT-001 only**, dopiero po kolejnym jawnym poleceniu użytkownika.

**STOP przed DB-ACT-001.**
