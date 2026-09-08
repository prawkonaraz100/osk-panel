# 114. Stage 4 — Student Finance / Commerce database audit

Data: 2026-09-08

**Etap:** `DB4_9_STUDENT_FINANCE_COMMERCE`  
**Aktualny krok:** `DB_FIN_002_CHARGE_PAYMENT_REVERSAL_LIFECYCLE_BALANCE_OVERPAYMENT_AND_CONCURRENCY`
**Status:** `FAIL_WITH_8_P1_BLOCKERS / 0 P0 / 8 P1 OPEN`

Machine-readable diagnoza: `specs/database/student-finance-commerce.yml`.

---

## 1. Zakres diagnozy

DB4_9 obejmuje dwa powiązane, ale rozdzielone bounded contexts:

1. **Student Finance** — wewnętrzne rozrachunki OSK z kursantem: należność (`student_charge`), rzeczywiste wpłaty, saldo, anulowanie należności i odwrócenie błędnej wpłaty.
2. **Platform Commerce** — zakupy produktów i usług PrawkoNaRaz przez OSK: Order, OrderItem, płatność platformowa/provider, purchase history oraz downstream grant licencji, egzaminów wewnętrznych lub generic service entitlement.

Te dwa modele nie mogą używać jednego wspólnego `Payment` jako business authority. `student_payments` opisują pieniądze otrzymane od kursanta przez OSK; `payments` opisują płatności OSK za zakup usług platformy.

Diagnoza nie zmienia Stage-3 API, agregatów `core-schema.yml` / `docs/87...`, migracji Laravel ani UI.

## 2. Źródła i potwierdzone capability

Student Finance wynika z:
- `docs/40-student-payment-charge-form.md`,
- `docs/41-own-student-finance-ledger.md`,
- `specs/design/student-finance-ledger.yml`,
- istniejących endpointów Student Finance w `specs/api/paths/students-courses.yaml`.

Potwierdzone są: osobna należność i wpłata, wiele częściowych wpłat do jednej należności, saldo jako projekcja, MVP bez nadpłaty, korekta wpłaty przez reversal zamiast delete, historyczne anulowanie należności, opcjonalny Course context oraz idempotentne tworzenie należności z kosztu kursu.

Commerce wynika z:
- `docs/17-history-purchases-screen.md`,
- `docs/52-license-purchase-screen.md`,
- `docs/59-internal-exam-purchase-screen.md`,
- `docs/16-admin-osk-service-catalog.md`,
- `specs/api/paths/commerce-dashboard.yaml`,
- `specs/api/paths/licenses.yaml`,
- `specs/api/paths/internal-exams.yaml`.

Potwierdzone są: wspólna historia zakupów OSK, Order z wieloma items, niezapłacony Order przed płatnością, odrębne order/booking dates, server-authoritative price/VAT/totals, trusted provider confirmation zamiast browser-return authority oraz inventory grant dopiero po potwierdzonej płatności. Explicit generic service activation pozostaje osobnym efektem i nie jest automatycznie równa payment success.

## 3. Granice zachowane z wcześniejszych slice

DB4_9 nie przepisuje downstream lifecycle:
- License Inventory / Assignment / Activation pozostaje authority `specs/database/licenses-learning-access.yml`.
- Internal Exam Inventory / Reservation / Consumption pozostaje authority `specs/database/internal-exams.yml`.
- DB4_9 domyka wyłącznie purchase provenance i exactly-once grant do tych domen.
- Audit/outbox physical finalization pozostaje DB4_10.

W DB4_6 `license_inventory_entries.source_order_item_id` został świadomie odroczony do DB4_9. W DB4_7 analogicznie odroczono exact commerce relation i payment grant trigger dla płatnych jednostek egzaminu wewnętrznego.

## 4. Obecny provisional Student Finance

`student_charges` ma dziś tenant, Student, optional Course, amount/currency, due date, generic status i cancellation metadata. `student_payments` ma tenant, Student, Charge, amount/currency, payment metadata i reversal metadata.

Pozytywny kierunek jest zachowany, ale obecna fizyczna forma nie dowodzi jeszcze:
- że optional Course należy do dokładnie tego Studenta i tenant,
- że Payment należy do dokładnie tego Charge + Student + tenant,
- że waluty Charge i Payment są zgodne,
- że status Charge nie rozjeżdża się z ledgerem wpłat,
- że dwa concurrent payments nie przekroczą remaining amount,
- że reversal/cancel/payment races mają jednego spójnego zwycięzcę.

## 5. Obecny provisional Platform Commerce

Największa luka strukturalna: `order_items` nie ma własnego `organization_id`. W efekcie specialized inventory może przechowywać `source_order_item_id`, ale DB nie może jeszcze wymusić composite same-tenant FK do dokładnego OrderItem.

Dodatkowo obecny model nie zamyka jeszcze:
- exact product-kind/reference relation,
- immutable product/price/VAT/discount snapshot,
- line-total/order-total equivalence,
- Order/Payment/Event lifecycle i status/timestamp matrices,
- trusted payment confirmation i out-of-order provider events,
- exactly-once fulfillment po paid,
- quantity-to-downstream-grant equivalence,
- deterministic purchase-history order number i pagination,
- generic service entitlement provenance,
- canonical lock order między Payment, fulfillment i downstream grant.

## 6. DB-FIN-001 — same-tenant i exact-target integrity

**P1 / OPEN.**

Charge musi wskazywać exact Student, a optional Course — dokładnie ten sam Student i Organization. Payment musi wskazywać exact Charge/Student/Organization, a jego currency musi być zgodne z Charge currency.

Ryzyko bez zamknięcia: cross-tenant finance row, Charge przypięty do kursu innego Studenta, Payment przypięty do należności innego Studenta oraz niespójne saldo wielowalutowe.

Fixer tego blockera może dotyczyć wyłącznie candidate keys, composite FKs, exact Student/Course/Charge binding, RESTRICT history policy i fail-closed migration prechecks.

## 7. DB-FIN-002 — lifecycle, saldo, overpayment i concurrency

**P1 / OPEN.**

MVP zabrania wpłaty ponad remaining amount, ale zwykły application precheck nie chroni przed dwiema równoległymi wpłatami. Potrzebna jest jawna serializacja na Charge albo równoważna finalna DB boundary.

Do zamknięcia pozostają również:
- durable cancellation vs derived paid/partially-paid/open state,
- definicja valid payment,
- write-once reversal metadata,
- double-reversal prevention,
- cancel vs payment vs reversal race,
- generic Idempotency-Key claim zamiast niezależnego nullable pola jako jedynej ochrony retry.

## 8. DB-COM-001 — OrderItem tenant, produkt i immutable pricing snapshot

**P1 / OPEN.**

`order_items` musi otrzymać exact tenant relation do Order. Product type/reference, quantity, unit price, VAT/discount i line total muszą mieć zamkniętą server-authoritative snapshot semantics. Historycznego OrderItem nie wolno przeliczać po zmianie cennika.

Nie tworzymy fałszywego jednego polymorphic FK dla wszystkich product kinds. Exact reference boundary musi respektować różne specialized produkty.

## 9. DB-COM-002 — Payment / PaymentEvent / Order lifecycle i reconciliation

**P1 / OPEN.**

Browser redirect nie jest authority płatności. Potrzebne są exact same-tenant Order→Payment→PaymentEvent relations, zamknięty lifecycle attemptu płatniczego, trusted confirmation source, provider/event consistency oraz bezpieczna obsługa duplicate/out-of-order events.

Obecny dedupe `(provider,provider_event_id)` jest wartościowy i ma zostać zachowany, ale sam nie dowodzi poprawnego business transition ani exactly-once paid effect.

Nie wymyślamy w DB4_9 niepotwierdzonego provider-specific payload schema, refund/chargeback/invoice API.

## 10. DB-COM-003 — paid Order → exactly-once fulfillment

**P1 / OPEN.**

Po trusted payment confirmation system musi móc deterministycznie utworzyć downstream grant dokładnie raz. Duplicate webhook/retry nie może dodać kolejnych jednostek. Failure podczas fulfillment nie może pozostawić nierozróżnialnego stanu „paid, ale nie wiadomo czy grant wykonano”.

Mixed/multi-item Order wymaga atomowego albo jawnie recoverable fulfillment state. DB4_9 nie zmienia późniejszego License Assignment ani Exam Reservation/Consumption.

## 11. DB-COM-004 — exact OrderItem provenance i quantity equivalence

**P1 / OPEN.**

Płatne License Inventory, płatne Internal Exam Inventory i purchase-backed generic ServiceEntitlement muszą wskazywać exact same-tenant OrderItem oraz właściwy product kind/reference.

`quantity=N` nie może prowadzić do mniej lub więcej niż N jednostkowych grantów bez jawnego quantity modelu. Potrzebny jest unique grant ordinal albo równoważna deterministic cardinality boundary.

Paid provenance musi pozostać odróżnialne od free, adjustment i operator grant.

## 12. DB-COM-005 — deterministic purchase history projection

**P1 / OPEN.**

Historia zakupów pokazuje numer, Order Date, Booking Date, amount i status. Obecny Order nie ma deterministic per-tenant display/order sequence ani fizycznego `booked_at`, mimo że API projection je przewiduje.

Do zamknięcia: deterministic order number, write-once booking-date source, stable pagination i status wynikający z canonical payment/fulfillment facts zamiast drugiego mutable UI label.

## 13. DB-COM-006 — migration / legacy reconciliation safety

**P1 / OPEN.**

Legacy commerce może nie mieć exact tenant OrderItem, product linkage, payment-event causality ani inventory provenance. Timestamp, UUID, amount albo „ten sam tenant” nie są wystarczającym dowodem exact purchase lineage.

Migracja nie może:
- zgadywać payment winner,
- zgadywać provider event powodującego paid,
- heurystycznie przypinać Inventory do OrderItem,
- regrantować już istniejących jednostek,
- przepisywać consumed/activated downstream history, aby constraints przeszły.

Ambiguity = FAIL + reviewed remediation.

## 14. DB-COM-007 — generic ServiceEntitlement source integrity

**P1 / OPEN.**

Generic ServiceEntitlement pozostaje dla usług niewchodzących w License/Exam Inventory. Purchase source i operator grant source nie mogą być jednocześnie puste ani jednocześnie konkurencyjne bez jawnej semantyki.

Potrzebne są exact same-tenant source OrderItem relation, purchase-vs-operator-grant provenance matrix oraz exact ServiceActivation→Entitlement tenant/final-state equivalence. Dla `activation_mode=explicit` payment success nadal nie jest activation.

## 15. DB-COM-008 — canonical lock order i cross-domain races

**P1 / OPEN.**

Webhook, manual reconciliation, payment creation i fulfillment worker mogą działać równolegle na tym samym Order. Same uniqueness constraints nie wystarczą do zdefiniowania kolejności i finalnej projection consistency.

Potrzebny jest canonical concurrency root/lock order dla Order/Payment/fulfillment i deterministic downstream grant order dla mixed Order.

Osobno `create charge from Course cost` musi mieć własny idempotency boundary w Student Finance i nie może sprzęgać wpłaty kursanta z platformowym Payment.

Audit/outbox business intent można wskazać, ale jego finalny physical shape pozostaje DB4_10.

## 16. Stage-3 API gaps — zapisane, nie naprawiane

- `StudentCharge` / `StudentPayment` są thin projections i nie niosą pełnej lifecycle/concurrency metadata.
- `Order.items` nadal używa `GenericObject`, nie exact immutable OrderItem schema.
- Order i Payment status są open strings.
- webhook payload jest `GenericObject`; exact provider adapter contract pozostaje późniejszym acceptance/API sync.
- refund, chargeback i invoice semantics nie są wystarczająco potwierdzone i nie są wymyślane w diagnozie DB4_9.

Żaden plik API nie został zmodyfikowany.

## 17. Preservation / frozen aggregate gate

Frozen przez całą diagnozę i fixery DB4_9:
- `specs/database/core-schema.yml` blob `65c6daab0330a2c6cf57a6a2caaba288a13a7a70`,
- `docs/87-physical-database-schema.md` blob `4d5f24f2948bdb55e7367c7b013ed918dade40ae`.

Preserved:
- Student Finance != Platform Commerce,
- DB4_6 License Inventory/Assignment/Activation,
- DB4_7 Exam Inventory/Reservation/Consumption,
- DB4_8 PKK,
- generic idempotency model,
- payment-event provider/event dedupe,
- explicit ServiceActivation != payment success,
- DB4_10+ not entered,
- Stage 5 not started,
- Laravel migrations not created,
- UI/feature implementation not started.

## 18. Wynik diagnozy

- P0 OPEN: **0**,
- P1 OPEN: **10**,
- resolved: **0/10**,
- result: **FAIL_WITH_10_P1_BLOCKERS**.

Wymagana kolejność fixerów:
1. DB-FIN-001
2. DB-FIN-002
3. DB-COM-001
4. DB-COM-002
5. DB-COM-003
6. DB-COM-004
7. DB-COM-007
8. DB-COM-005
9. DB-COM-008
10. DB-COM-006

Kolejność jest zależnościowa, nie numeryczna: deterministic history i migration safety są domykane dopiero po ustaleniu canonical state/provenance/concurrency modelu.

Następny dozwolony krok po centralnej bramce diagnozy: **DB-FIN-001 only** i dopiero po następnym jawnym poleceniu użytkownika.

**STOP przed fixerem DB-FIN-001.**

## 19. DB-FIN-001 — wynik fixera: PASS

DB-FIN-001 domyka wyłącznie fizyczną integralność relacji Student Finance. Nie zmienia semantyki salda, reversal, cancellation ani concurrency należności — te elementy pozostają DB-FIN-002.

### 19.1. Exact Charge → Student

`student_charges(organization_id, student_id)` musi wskazywać `students(organization_id, id)` przez composite FK z `RESTRICT`. Tenant nie jest wartością klienta; jest wyprowadzany z aktywnego kontekstu OSK lub zweryfikowanego parentu.

### 19.2. Optional Charge → exact Course tego samego Studenta

Samo FK `(organization_id, course_enrollment_id)` nie wystarcza, ponieważ nie dowodzi, że Course należy do tego samego Studenta, którego wskazuje Charge.

Dlatego `course_enrollments` dostaje candidate key `(organization_id, id, student_id)`, a non-null relacja Charge używa:

`student_charges(organization_id, course_enrollment_id, student_id)` → `course_enrollments(organization_id, id, student_id)`.

`course_enrollment_id = NULL` pozostaje legalne; osobny FK Charge→Student nadal obowiązuje.

### 19.3. Payment → exact Charge + Student + currency

`student_charges` dostaje candidate key `(organization_id, id, student_id, currency)`. `student_payments` wiąże się z nim przez dokładnie ten sam zestaw pól.

W efekcie Payment nie może:
- wskazać Charge innego tenant,
- wskazać Charge innego Studenta w tym samym OSK,
- użyć innej waluty niż Charge.

Dodatkowy direct FK Payment→Student pozostaje jawny. Wszystkie historyczne relacje używają `RESTRICT`.

### 19.4. Globalni actorzy nie dostają fałszywego tenant FK

`created_by_user_id`, `received_by_user_id`, `cancelled_by_user_id` i `reversed_by_user_id` pozostają relacjami do globalnego User identity. Organizacyjna autoryzacja aktora jest obowiązkiem RBAC/command boundary, nie sztucznego `(organization_id,user_id)` FK do globalnej tabeli users.

### 19.5. Migration safety

Przed dodaniem constraints migracja musi udowodnić:
- exact Charge→Student,
- dla non-null Course: exact tenant + Course + Student,
- exact Payment→Charge + Student + currency,
- brak NULL w wymaganych kluczach.

Mismatched legacy row nie może zostać automatycznie przepięty na „pasującego” Studenta/Course ani mieć automatycznie zmienionej waluty. Ambiguity oznacza FAIL i reviewed remediation. Formalna historia finansowa nie może być usuwana tylko po to, aby constraint przeszedł.

### 19.6. Preservation gate

Zachowane bez zmian:
- Student Finance i Platform Commerce pozostają rozdzielone,
- wiele częściowych wpłat do jednej należności pozostaje dozwolone,
- optional Course context pozostaje dozwolony,
- DB-FIN-002 pozostaje OPEN,
- DB-COM-001…008 pozostają OPEN,
- agregaty `core-schema.yml` i `docs/87...` pozostają zamrożone,
- DB4_10+, Stage 5, Laravel migrations i UI pozostają nieruszone.

Stan po fixerze:
- P0 OPEN: **0**,
- P1 OPEN: **9**,
- resolved: **1/10**,
- result: **FAIL_WITH_9_P1_BLOCKERS**.

Następny dozwolony krok po central gate: **DB-FIN-002 only**.

**STOP przed DB-FIN-002.**

## 20. DB-FIN-002 — wynik fixera: PASS

DB-FIN-002 domyka lifecycle należności i wpłat, saldo, reversal, zakaz nadpłaty oraz concurrency. Nie zmienia żadnego modelu Platform Commerce — DB-COM-001…008 pozostają OPEN.

### 20.1. Jeden trwały stan Charge, reszta jako projekcja

`cancelled` jest trwałym stanem biznesowym wynikającym z write-once tuple `cancelled_at + cancelled_by_user_id + cancellation_reason`. `open`, `partially_paid` i `paid` nie są drugim mutable authority; wynikają z kwoty należności i sumy ważnych, nieodwróconych wpłat.

`overdue` jest czasowym modifierem projekcji: aktywna należność ma remaining > 0, `due_at` istnieje i termin minął według czasu lokalnego organizacji. Nie zapisujemy go jako niezależnego stanu mogącego rozjechać się z zegarem lub saldem.

### 20.2. Ważna wpłata i reversal

Wpłata jest ważna, gdy `reversed_at IS NULL`. Dane finansowe Payment są po insert immutable; jedyną normalną zmianą jest pełny reversal tuple `reversed_at + reversed_by_user_id + reversal_reason`. Tuple jest albo całe NULL, albo całe non-NULL. Reversal jest jednokierunkowy i może nastąpić tylko raz. Hard delete wpłaty jest zabroniony.

Odwrócenie wpłaty automatycznie zmienia projekcję Charge. Jeżeli cofamy ostatnią lub pełną wpłatę, Charge może wrócić z `paid` do `partially_paid` albo `open` bez przepisywania pola status.

### 20.3. Saldo

Dla Charge:
- `paid_amount = SUM(amount_minor)` tylko dla Payment z `reversed_at IS NULL`,
- `remaining_amount = amount_minor - paid_amount`,
- finalny invariant: `remaining_amount >= 0`.

Dla Studenta sumujemy tylko nieanulowane Charge. Frontend nie zapisuje `paid`, `remaining` ani totals jako authority.

### 20.4. Zakaz nadpłaty i concurrency root

Application precheck nie wystarcza. `student_charges` jest concurrency rootem dla `record payment`, `reverse payment` i `cancel charge`. Każda z tych komend blokuje exact Charge `FOR UPDATE` przed obliczeniem salda lub zmianą efektu finansowego.

Record Payment po locku ponownie liczy sumę ważnych wpłat i wymaga `new amount <= remaining`. Dwie równoległe wpłaty nie mogą więc skonsumować tej samej pozostałej kwoty.

### 20.5. Cancellation vs istniejące pieniądze

W MVP nie ma jeszcze `student_credit` ani unallocated payment modelu. Dlatego Charge nie może zostać anulowany, jeśli istnieje jakakolwiek nieodwrócona wpłata. Sekretariat najpierw wykonuje reversal z powodem, a dopiero potem cancellation.

To zamyka race:
- reversal i cancellation serializują się na Charge,
- payment i cancellation serializują się na Charge,
- payment na anulowany Charge jest odrzucany.

### 20.6. Idempotency

Jedyną authority retry jest wspólny `idempotency_records`. Dotyczy `create charge`, `record payment`, `reverse payment` i `cancel charge`. Ten sam key + ten sam request hash replayuje zapisany bezpieczny wynik bez drugiego efektu; ten sam key z innym payloadem powoduje conflict.

Nullable `student_payments.idempotency_key` nie może pozostać równoległym systemem deduplikacji po cutover. Historyczny klucz bez pełnego operation key i request hash nie jest automatycznie przepisywany na generic idempotency record.

Exact race dla automatycznego `create charge from Course cost` pozostaje świadomie DB-COM-008.

### 20.7. Migration safety

Preflight musi wykryć co najmniej:
- częściowy reversal tuple,
- częściowy cancellation tuple,
- aktywną należność z sumą valid payments > amount,
- anulowaną należność z nieodwróconą wpłatą.

Migracja nie może ucinać nadpłaty, dopisywać sztucznego reversal, automatycznie cofać wpłaty ani fabrykować generic idempotency records. Ambiguous legacy finance history = FAIL i reviewed remediation.

### 20.8. Preservation gate

Zachowane bez zmian:
- DB-FIN-001 exact relation contract,
- Charge i Payment jako osobne byty,
- wiele częściowych wpłat,
- MVP bez nadpłaty,
- korekta przez reversal zamiast delete,
- optional Course context,
- DB-COM-001…008 OPEN,
- `core-schema.yml` i `docs/87...` zamrożone,
- DB4_10+, Stage 5, Laravel migrations i UI nieruszone.

Stan po fixerze:
- P0 OPEN: **0**,
- P1 OPEN: **8**,
- resolved: **2/10**,
- result: **FAIL_WITH_8_P1_BLOCKERS**.

Następny dozwolony krok po central gate: **DB-COM-001 only**.

**STOP przed DB-COM-001.**
