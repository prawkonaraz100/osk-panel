# 115. Stage 4 — Audit / Outbox / Notifications database audit

Data: 2026-09-09

**Etap:** `DB4_10_AUDIT_OUTBOX_NOTIFICATIONS`
**Aktualny krok:** `DB-AUD-001`
**Status:** `FAIL_WITH_7_P1_BLOCKERS / 0 P0 / 7 P1 OPEN`

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
- resolved: **1/8**,
- open: **7/8**,
- result: **FAIL_WITH_7_P1_BLOCKERS**.

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

Po fixerze: **1/8 resolved, 7 P1 OPEN**. Następny dozwolony krok: **DB-AUD-002 only**, dopiero po następnym jawnym poleceniu użytkownika.

**STOP przed DB-AUD-002.**
