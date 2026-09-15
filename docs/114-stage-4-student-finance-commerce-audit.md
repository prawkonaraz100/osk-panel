# 114. Stage 4 — Student Finance / Commerce database audit

Data: 2026-09-08

**Etap:** `DB4_9_STUDENT_FINANCE_COMMERCE`  
**Aktualny krok:** `DB4_9_FINAL_AGGREGATE_SYNC`
**Status:** `PASS / 0 P0 / 0 P1 OPEN`

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

## 21. DB-COM-001 — wynik fixera: PASS

DB-COM-001 domyka tenant, tożsamość produktu oraz immutable pricing snapshot pozycji zamówienia. Nie zmienia lifecycle płatności, fulfillment ani purchase-history projection — DB-COM-002…008 pozostają OPEN.

### 21.1. Jeden canonical sale-SKU

Wprowadzamy globalny `commerce_catalog_items` jako stabilną tożsamość produktu sprzedawanego przez platformę. Nie jest to tenant-owned tabela i nie zastępuje specialized domain authority.

Potwierdzone rodzaje produktu:
- `license`,
- `internal_exam`,
- `generic_service`.

Dla `license` katalogowa pozycja musi wskazywać exact `license_product_id`. Dla egzaminu wewnętrznego i generic service sama pozycja commerce jest sale-SKU; nie wymyślamy niepotwierdzonych tabel `internal_exam_products` ani `service_products`. Exact downstream mapping pozostaje DB-COM-004/007.

Nie używamy jednego pozornego polymorphic FK `product_reference`.

### 21.2. OrderItem jest tenant-owned

`order_items` dostaje własny `organization_id`. Exact relacja do Order używa `(organization_id, order_id, currency) -> orders(organization_id, id, currency)`.

To jednocześnie blokuje:
- OrderItem z innego tenant,
- linię w innej walucie niż Order,
- późniejsze purchase provenance oparte wyłącznie na niezweryfikowanym `order_id`.

`order_items(organization_id,id)` staje się candidate key potrzebnym później dla exact downstream grant FKs.

### 21.3. Product kind jest częścią relacyjnej integralności

OrderItem przechowuje `commerce_catalog_item_id` oraz `product_kind`, a composite FK wymaga zgodności z `commerce_catalog_items(id,product_kind)`.

Macierz:
- `license` → `license_product_id` wymagany,
- `internal_exam` → `license_product_id = NULL`,
- `generic_service` → `license_product_id = NULL`.

Zmiana lub dezaktywacja katalogu nie może przepisać historycznego OrderItem.

### 21.4. Pricing snapshot

Kwoty pozostają w minor units. Pozycja zapisuje co najmniej:
- `quantity > 0`,
- `currency`,
- list unit amount,
- charged unit amount,
- unit discount amount,
- VAT rate snapshot w basis points,
- line total,
- immutable product snapshot,
- immutable pricing snapshot,
- snapshot content hash.

Reguły:
- `unit_discount = list_unit - charged_unit`,
- `0 <= charged_unit <= list_unit`,
- `line_total = quantity * charged_unit`,
- `Order.total = SUM(OrderItem.line_total)` na finalnej granicy transakcji.

VAT rate jest evidence snapshotem. DB-COM-001 nie wymyśla jednej uniwersalnej formuły netto/VAT ani provider-specific tax engine.

### 21.5. Server authority

Frontend może wysłać wybór SKU i quantity, ale cena, rabat, VAT i total nie są client authority. Backend ponownie rozwiązuje aktywną pozycję katalogową i pricing context, tworzy Order oraz wszystkie OrderItems i sprawdza sumę przed commit.

Order może zawierać kilka wariantów licencji. Egzaminy pozostają produktem ilościowym, nie kopią duration modelu licencji.

### 21.6. Immutability po utworzeniu Order

Po commit normalny lifecycle nie zmienia biznesowych pól OrderItem: tenant, Order, SKU, kind, quantity, currency, price/discount/VAT/total ani snapshotów.

Późniejsza zmiana katalogu, cennika lub display name nie zmienia historii. Korekta nie jest wykonywana przez ciche przepisywanie pozycji historycznej.

Payment status oraz fulfillment status nie są rozwiązane w tym blockerze.

### 21.7. Migration safety

`organization_id` i `currency` legacy OrderItem można backfillować wyłącznie z exact parent Order. To deterministyczna relacja, nie heurystyka.

Nie wolno:
- zgadywać SKU z nazwy, ceny lub timestampu,
- zgadywać LicenseProduct tylko z duration/ceny,
- tworzyć generic SKU wyłącznie po to, by ukryć ambiguous legacy reference,
- przeliczać historycznych cen/rabatów z bieżącego cennika,
- zmieniać line total bez historycznego dowodu,
- usuwać OrderItem, aby constraint przeszedł.

Ambiguous product/pricing history = FAIL + reviewed remediation.

### 21.8. Preservation gate

Zachowane bez zmian:
- DB-FIN-001 i DB-FIN-002,
- specialized LicenseProduct authority,
- Internal Exam jako quantity product,
- mixed license cart,
- Student Finance oddzielone od Platform Commerce,
- DB-COM-002…008 OPEN,
- `core-schema.yml` i `docs/87...` zamrożone,
- DB4_10+, Stage 5, Laravel migrations i UI nieruszone.

Stan po fixerze:
- P0 OPEN: **0**,
- P1 OPEN: **7**,
- resolved: **3/10**,
- result: **FAIL_WITH_7_P1_BLOCKERS**.

Następny dozwolony krok po central gate: **DB-COM-002 only**.

**STOP przed DB-COM-002.**


## 22. DB-COM-002 — wynik fixera: PASS

DB-COM-002 domyka lifecycle payment attempt, exact PaymentEvent binding, trusted confirmation, idempotency/retry oraz pojedynczy business settlement Orderu. Nie przyznaje jeszcze licencji, egzaminów ani service entitlements — fulfillment pozostaje DB-COM-003.

### 22.1. Payment attempt nie jest jeszcze skutkiem biznesowym Orderu

Tabela `payments` pozostaje ledgerem **prób płatności**. Jeden Order może mieć więcej niż jeden attempt, bo retry, timeout operatora albo ponowienie płatności są poprawnymi scenariuszami.

Każdy Payment jest związany z exact tenant Order oraz jego niezmiennym payable snapshotem:

`payments(organization_id, order_id, amount_minor, currency)` → `orders(organization_id, id, total_amount_minor, currency)`.

Dla obecnego full-payment modelu attempt zawsze dotyczy całej kwoty Orderu. Nie można więc utworzyć płatności dla innego tenant, innej waluty albo innej kwoty i później uznać jej za płatność tego Orderu.

Potwierdzenie pieniędzy u operatora i zastosowanie jednego business effect dla Orderu są celowo rozdzielone. To pozwala zachować prawdę o drugim rzeczywiście potwierdzonym attempt bez przyznania produktów po raz drugi.

### 22.2. Zamknięty lifecycle Payment

Canonical status attemptu ma trzy wartości:
- `pending`,
- `confirmed`,
- `failed`.

Macierz timestampów jest zamknięta:
- `pending` → `confirmed_at = NULL`, `failed_at = NULL`,
- `confirmed` → tylko `confirmed_at` jest non-null,
- `failed` → tylko `failed_at` jest non-null.

`confirmed_at` i `failed_at` są write-once. Nie ma zwykłego patchowania stringa statusu.

Dozwolone automatyczne przejścia to tylko `pending -> confirmed` po trusted confirmation albo `pending -> failed` po trusted terminal failure. `confirmed` nie może zostać cofnięty przez późne `failed`. Jeżeli po stanie terminalnym pojawi się sprzeczne zdarzenie terminalne, nie przepisujemy historii — oznaczamy konflikt do reconciliation.

### 22.3. PaymentEvent musi wskazywać dokładnie ten Payment operatora

`payment_events` dostaje własny `organization_id` oraz `provider_payment_id`. Event nie może być związany z Payment wyłącznie przez luźne `payment_id` i string `provider`.

Exact relation wiąże:

`payment_events(organization_id, payment_id, provider, provider_payment_id)` → `payments(organization_id, id, provider, provider_payment_id)`.

Zachowujemy istniejący dedupe `(provider, provider_event_id)`. Dedupe eventu i idempotency komendy płatniczej są jednak dwiema różnymi granicami bezpieczeństwa.

Raw event pozostaje append-only evidence. Adapter serwera normalizuje jego wynik do `pending | confirmed | failed | informational`; dokładne mapowanie payloadu PayU lub innego operatora pozostaje Stage 5. Po obsłużeniu event zapisuje `processed_at` i wynik `state_applied`, `no_change_duplicate_or_stale` albo `conflict_requires_reconciliation`.

### 22.4. Browser return nigdy nie oznacza `paid`

Wiarygodne źródło potwierdzenia jest tylko jedno z dwóch:

1. zweryfikowany podpisem provider event związany z exact provider payment reference i znormalizowany do `confirmed`, albo
2. jawna privileged reconciliation oparta o niezależny dowód operatora/banku.

Powrót przeglądarki, parametr URL, ekran „sukces” albo wartość z frontendu nie mogą ustawić `confirmed` ani rozliczyć Orderu.

Dla `Przelewu bezpośredniego` Payment pozostaje `pending`, dopóki zaufana reconciliation nie potwierdzi księgowania. Samo oświadczenie użytkownika, że wykonał przelew, nie jest authority.

### 22.5. Jeden `order_payment_settlement` = jeden paid business effect

Wprowadzamy `order_payment_settlements` jako trwałą authority, że non-zero Order został rozliczony. Klucz `(organization_id, order_id)` jest unique, więc jeden Order może mieć dokładnie jeden settlement.

Settlement wskazuje exact Payment tego samego Orderu i zapisuje:
- `settled_at`,
- `confirmation_source = provider_event | reconciliation`,
- dla provider event: exact `source_payment_event_id`,
- dla reconciliation: actor oraz reason/reference.

Macierz source jest XOR: nie mieszamy provider-event provenance i manual reconciliation provenance.

Pierwszy trusted confirmed attempt, gdy Order nie ma settlementu, w jednej transakcji:
1. serializuje się na exact Order,
2. zapisuje `Payment=confirmed`,
3. tworzy settlement,
4. powoduje canonical paid projection Orderu.

Jeżeli inny attempt tego samego Orderu również zostanie później rzeczywiście potwierdzony, zachowujemy tę zewnętrzną prawdę jako `Payment=confirmed`, ale **nie tworzymy drugiego settlementu i nie wykonujemy drugiego purchase/grant effect**. Taki confirmed non-settling attempt wymaga reconciliation.

DB-COM-003 będzie konsumował settlement, a nie browser return, raw webhook ani sam luźny string `payments.status`.

### 22.6. Order payment status jest zamkniętą projekcją

Canonical payment projection Orderu ma tylko:
- `unpaid`,
- `pending`,
- `paid`.

`orders.status` nie może pozostać niezależnym client-mutable authority. Jeżeli projekcja jest materializowana dla wydajności, musi być transakcyjnie zgodna z canonical facts.

Reguły:
- `paid` — istnieje valid `order_payment_settlement` albo valid zero-total internal settlement,
- `pending` — brak paid resolution i istnieje co najmniej jeden pending Payment,
- `unpaid` — brak paid resolution i brak pending Payment.

Failed attempt nie robi Orderu `paid`. Dokładna etykieta pokazywana w historii zakupów oraz `booked_at` pozostają DB-COM-005.

### 22.7. Zero-total Order

DB-COM-001 dopuścił możliwość `total_amount_minor = 0`, np. przy pełnym rabacie, ale odroczył payment semantics do tego blockera.

Dla takiego Orderu nie tworzymy sztucznej zewnętrznej płatności na 0. Backend atomowo z utworzeniem poprawnego zero-total Orderu zapisuje server-owned, write-once `zero_total_settled_at`. Pole jest zabronione dla Orderu z kwotą większą od zera.

Zero-total resolution daje canonical `paid`, ale dokładny fulfillment tej transakcji nadal należy do DB-COM-003.

### 22.8. Idempotency i retry

Tworzenie payment attempt korzysta ze wspólnego `infrastructure_idempotency_records` z operation key `commerce.payment.create`. Reconciliation używa osobnego `commerce.payment.reconcile`.

Ten sam `Idempotency-Key` + ten sam request hash replayuje ten sam wynik bez nowego attemptu lub efektu. Ten sam klucz z innym payloadem powoduje conflict.

Provider webhook ma niezależny dedupe `(provider, provider_event_id)`. Powtórzony event nie może ponownie wykonać transition ani settlementu.

Nowy attempt z nowym idempotency key może powstać tylko, gdy Order nie ma jeszcze settlementu. Równoległe/powtórzone attempty są bezpieczne dlatego, że finalną granicą jest unique settlement Orderu, a nie założenie „zawsze istnieje tylko jeden Payment”.

### 22.9. Migration safety

Legacy migration może backfillować tenant i `provider_payment_id` Eventu wyłącznie z exact parent Payment.

Nie wolno:
- uznać samego starego `orders.status=paid` za dowód płatności,
- uznać browser-return timestamp za potwierdzenie,
- wybierać „zwycięskiego” Payment po najbliższym timestampie, amount albo podobnym provider string,
- automatycznie wybierać jednego z kilku prawdopodobnych confirmed Payments,
- oznaczać przelewu bezpośredniego jako paid bez trusted reconciliation evidence,
- usuwać Payment/Event, aby constraint przeszedł.

Settlement można odtworzyć tylko wtedy, gdy istnieje dokładnie jeden udowodniony confirmed Payment i wiarygodny confirmation evidence. Ambiguous payment history = FAIL + reviewed remediation.

### 22.10. Preservation gate

Zachowane bez zmian:
- DB-FIN-001 i DB-FIN-002,
- DB-COM-001 tenant/catalog/pricing snapshot,
- wspólna historia zamówień i możliwość późniejszego opłacenia unpaid Orderu,
- potwierdzone metody `Płatności online PayU` i `Przelew bezpośredni` bez wymyślania payload schema,
- browser return != payment confirmation,
- generic command idempotency i provider-event dedupe jako osobne mechanizmy,
- License/Exam Inventory nie są jeszcze grantowane,
- explicit ServiceActivation != payment success,
- DB-COM-003…008 pozostają OPEN,
- `core-schema.yml` i `docs/87...` pozostają zamrożone,
- DB4_10+, Stage 5, Laravel migrations i UI pozostają nieruszone.

Stan po fixerze:
- P0 OPEN: **0**,
- P1 OPEN: **6**,
- resolved: **4/10**,
- result: **FAIL_WITH_6_P1_BLOCKERS**.

Następny dozwolony krok po central gate: **DB-COM-003 only**.

**STOP przed DB-COM-003.**


## 23. DB-COM-003 — wynik fixera: PASS

DB-COM-003 domyka **order-level fulfillment orchestration** po trusted payment resolution. Nie rozwiązuje jeszcze exact `OrderItem -> downstream grant` provenance ani quantity equivalence — to pozostaje DB-COM-004. Nie zmienia też lifecycle przypisania/aktywacji licencji ani reservation/consume-on-start egzaminu.

### 23.1. `paid` i `fulfilled` to dwa różne fakty

DB-COM-002 zdefiniował canonical paid resolution:
- dla Orderu z kwotą > 0: `order_payment_settlements`,
- dla zero-total Orderu: server-owned `zero_total_settled_at`.

DB-COM-003 dodaje osobną durable authority `order_fulfillments`.

To oznacza, że poprawne są przejściowe stany:
- `paid + fulfillment pending`,
- `paid + fulfillment requires_reconciliation`,
- `paid + fulfilled`.

Nie wolno natomiast traktować samego `paid` jako dowodu, że licencje, egzaminy lub entitlementy zostały już przyznane. Purchase-history display mapping pozostaje DB-COM-005.

### 23.2. Jeden trwały `order_fulfillment` na dokładny Order

`order_fulfillments` jest tenant-owned i ma unique `(organization_id, order_id)`.

Źródło fulfillmentu ma zamkniętą macierz:
- `payment_settlement` — dla non-zero Order, z exact relacją `(organization_id, order_id, settlement_payment_id)` do `order_payment_settlements`,
- `zero_total` — tylko dla Orderu z authoritative total = 0 i valid `zero_total_settled_at`, bez zewnętrznego Payment.

`source_kind` jest server-derived. Browser return, frontend success flag ani raw webhook nie mogą samodzielnie utworzyć fulfillmentu.

Lifecycle `order_fulfillments` ma trzy stany:
- `pending`,
- `fulfilled`,
- `requires_reconciliation`.

`fulfilled` jest finalnym write-once business fact. Zwykły lifecycle nie cofa go do pending.

### 23.3. Paid resolution nie może zostać bez recoverable work record

Dla non-zero Orderu pierwsze valid settlement i `pending order_fulfillment` powstają w **tej samej transakcji**.

Dla zero-total Orderu atomowa granica obejmuje:
1. poprawny Order i jego immutable items,
2. `zero_total_settled_at`,
3. jeden `pending order_fulfillment`.

W efekcie nie może powstać stan: „płatność rozliczona, ale system nie ma żadnego trwałego śladu, że trzeba przyznać produkt”.

Powtórzony provider event albo retry settlementu nie tworzy drugiego fulfillmentu — unique Order boundary powoduje reuse/no-op.

### 23.4. Wszystkie lokalne grant effects są jednym commitem

Właściwa komenda fulfillmentu serializuje się na exact `order_fulfillment` i przed grantem ponownie sprawdza canonical payment resolution.

Dla jednego Orderu wszystkie wymagane **lokalne** downstream grant rows muszą powstać w jednej transakcji PostgreSQL razem z przejściem `order_fulfillments -> fulfilled`.

Dotyczy to docelowo:
- jednostek `license_inventory_entries`,
- płatnych `internal_exam_inventory_entries` oraz ich canonical grant-ledger effect,
- generic service entitlement, którego exact source/activation model zostanie zamknięty w DB-COM-007.

Jeżeli choć jeden item nie może zostać poprawnie zgrantowany, cała transakcja grantów jest rollbackowana. Partial commit mixed/multi-item Orderu jest zabroniony.

Exact per-item provenance, ordinal i quantity equivalence nie są tu projektowane przedwcześnie — to DB-COM-004.

### 23.5. Retry i crash są bezpieczne

Fulfillment ma jedną durable identity per Order.

Jeżeli proces padnie **przed commit**, nie ma committed częściowych grantów ani `fulfilled_at`; ten sam `pending fulfillment` może zostać ponowiony.

Jeżeli proces padnie **po commit**, granty i `fulfilled` są już zapisane razem. Retry widzi `fulfilled` i zwraca idempotentne „already fulfilled” bez drugiego zestawu skutków.

Retryable błąd infrastrukturalny pozostawia recoverable `pending`. Semantyczna niespójność, której nie wolno automatycznie zgadywać, prowadzi do `requires_reconciliation`, bez częściowego grantu.

Globalna kolejność locków Payment -> Fulfillment -> downstream inventory pozostaje świadomie DB-COM-008.

### 23.6. Fulfillment nie przejmuje lifecycle licencji ani egzaminu

Dla licencji commerce może jedynie utworzyć zakupione **one-unit inventory rows**. Nie przypisuje kursanta, nie aktywuje licencji, nie wykonuje revoke i nie modyfikuje entitlement stacking — te reguły pozostają authority DB4_6.

Dla egzaminu commerce może jedynie utworzyć płatne jednostki inventory i wymagany grant-ledger effect. Nie tworzy Attempt, Reservation, Access i nie konsumuje jednostki. `consume-on-start` pozostaje authority DB4_7.

Dla generic service payment/fulfillment **nie może oznaczać explicit activation**. Exact entitlement source i activation matrix pozostają DB-COM-007.

### 23.7. Fulfillment attempts są historią, nie drugim source of truth

`order_fulfillment_attempts` zapisuje append-only operational retry evidence: kolejny numer próby, czas rozpoczęcia/zakończenia, wynik `succeeded | retryable_failure | requires_reconciliation` oraz bezpieczny error code.

Attempt history nie może sam oznaczyć Orderu jako fulfilled. Canonical completion nadal wynika wyłącznie z `order_fulfillments.fulfilled_at + state=fulfilled` zapisanych w tym samym commicie co wszystkie lokalne grant effects.

Raw provider payload, sekrety i wrażliwe snapshoty nie są przechowywane w attempt history.

### 23.8. External side effects nie wchodzą do transakcji grantu

Atomowa granica DB-COM-003 dotyczy lokalnych rekordów biznesowych w PostgreSQL. Nie wykonujemy requestu do zewnętrznego providera wewnątrz transakcji, aby „dokończyć” fulfillment.

Jeżeli dany produkt kiedyś wymaga zewnętrznej dostawy, najpierw musi istnieć committed lokalna authority, a dalsze delivery idzie przez późniejszy durable outbox/event contract. Fizyczne domknięcie outbox pozostaje DB4_10.

### 23.9. Migration safety

Stare `orders.status=paid` nie jest wystarczającym dowodem, aby backfillować `fulfilled`.

Nie wolno również:
- uznać przypadkowo istniejącego inventory za dowód fulfillmentu bez exact lineage,
- uruchamiać nowych grantów tylko po to, aby migration wyglądała spójnie,
- odtwarzać fulfillment z timestamp proximity albo podobieństwa kwoty/SKU.

Dokładne legacy evidence classes i inventory-to-order-item reconciliation pozostają DB-COM-006. W tym blockerze zamykamy jedynie zasadę: **schema migration nie regrantuje produktów i nie fabrykuje fulfillment completion**.

### 23.10. Preservation gate

Zachowane bez zmian:
- DB-FIN-001 i DB-FIN-002,
- DB-COM-001 immutable order/item/catalog/pricing contract,
- DB-COM-002 trusted payment settlement, zero-total resolution i reconciliation,
- DB4_6 License Inventory / Assignment / Activation / Revoke,
- DB4_7 Exam Inventory ledger / Reservation / consume-on-start,
- explicit ServiceActivation != payment success,
- exact item provenance i quantity pozostają DB-COM-004,
- generic service entitlement source/activation pozostaje DB-COM-007,
- purchase-history projection pozostaje DB-COM-005,
- global lock order pozostaje DB-COM-008,
- legacy reconciliation pozostaje DB-COM-006,
- `core-schema.yml` i `docs/87...` nadal zamrożone,
- DB4_10+, Stage 5, Laravel migrations i UI nieruszone.

Stan po fixerze:
- P0 OPEN: **0**,
- P1 OPEN: **5**,
- resolved: **5/10**,
- result: **FAIL_WITH_5_P1_BLOCKERS**.

Następny dozwolony krok po central gate: **DB-COM-004 only**.

**STOP przed DB-COM-004.**

## 24. DB-COM-004 — wynik fixera: PASS

DB-COM-004 domyka **dokładną lineage każdej zakupionej jednostki** od niezmiennego `OrderItem` do właściwego downstream inventory/entitlement oraz wymusza, że `quantity=N` oznacza dokładnie `N` lokalnych grantów. Nie zmienia order-level fulfillment z DB-COM-003 i nie rozwiązuje jeszcze pełnego source XOR / activation lifecycle generic service — to pozostaje DB-COM-007.

### 24.1. `OrderItem.quantity` jest jedyną authority ilości zakupu

Nie wprowadzamy mutable countera jako drugiego źródła prawdy. Dla zakupionych produktów canonical quantity pochodzi wyłącznie z immutable `order_items.quantity` zamkniętego w DB-COM-001.

Każda konkretna zakupiona jednostka dostaje niezmienną parę:
- `source_order_item_id`,
- `source_order_item_grant_ordinal`.

Ordinal jest tylko techniczną tożsamością jednostki w obrębie jednej pozycji zamówienia. Nie oznacza czasu utworzenia, kolejności aktywacji ani ceny. Dla `quantity=5` poprawny finalny zbiór ordinali to dokładnie `1..5`.

### 24.2. Licencja musi wskazywać exact OrderItem i exact LicenseProduct

`license_inventory_entries.source_order_item_id` pozostaje purchase lineage. Dla zakupionych jednostek dokładna relacja używa również `license_product_id`:

`license_inventory_entries(organization_id, source_order_item_id, license_product_id)`
→ `order_items(organization_id, id, license_product_id)`.

Dzięki macierzy DB-COM-001 non-license OrderItem nie może mieć `license_product_id`, więc jednostka licencji nie może zostać podpięta do pozycji egzaminu albo generic service.

Każdy purchase-sourced LicenseInventoryEntry ma dodatkowo `source_order_item_grant_ordinal`, unique w ramach exact OrderItem. Po fulfillment grant zaczyna jako `available`, bez Assignment i bez Activation. Commerce nie przejmuje lifecycle DB4_6.

Jeżeli LicenseInventoryEntry nie pochodzi z zakupu, `source_order_item_id` i ordinal są `NULL` i taki rekord nigdy nie może być policzony jako płatny purchase grant. Nie wymyślamy w tym blockerze niepotwierdzonego katalogu manual/operator license grants.

### 24.3. Egzamin zachowuje własny `source_type`

DB4_7 już zdefiniował `internal_exam_inventory_entries.source_type = free | paid | adjustment`. DB-COM-004 tylko domyka purchase lineage:

- `paid` → exact same-tenant `source_order_item_id`, ordinal oraz parent `product_kind=internal_exam`,
- `free` → brak `source_order_item_id` i brak purchase ordinal,
- `adjustment` → brak `source_order_item_id` i brak purchase ordinal.

Każda płatna jednostka zaczyna jako `available` i ma wymagany przez DB4_7 initial ledger event `unit_granted`, `event_sequence=1`, `available_delta=1`.

Zakup nie tworzy Attempt, Reservation ani Access i nie konsumuje jednostki. Consume-on-start pozostaje authority DB4_7.

### 24.4. Generic service dostaje exact purchase source, ale nie rozwiązujemy przedwcześnie DB-COM-007

Dla `service_entitlements` istniejący `source_order_item_id` może być źródłem zakupu. Jeśli jest non-null, DB wymaga:
- tego samego tenant,
- exact OrderItem,
- `product_kind=generic_service`,
- non-null `source_order_item_grant_ordinal`,
- unique ordinal w obrębie OrderItem.

Nie tworzymy osobnej tabeli `service_products`, ponieważ obecne dowody jej nie wymagają. Exact sale-SKU pozostaje immutable `commerce_catalog_item_id` na wskazanym OrderItem.

W tym blockerze **nie** zamykamy jeszcze globalnej macierzy `source_order_item_id XOR source_grant_reference`, semantyki `service_type`, activation-mode ani status/activation equivalence. To jest dokładnie DB-COM-007.

### 24.5. Final-state quantity equivalence

Deferrable final-state guard ocenia cały exact Order fulfillment.

Dla każdego fulfilled OrderItem:
- `license` → dokładnie `quantity` purchase-sourced `license_inventory_entries`, ordinal `1..quantity`, zero purchase grants w pozostałych downstream kinds,
- `internal_exam` → dokładnie `quantity` `source_type=paid` exam inventory units z ordinalami `1..quantity` i poprawnym initial ledger effect,
- `generic_service` → dokładnie `quantity` purchase-sourced service entitlement rows z ordinalami `1..quantity`.

Brak jednej jednostki, dodatkowa jednostka, luka ordinali albo grant w niewłaściwej domenie powoduje rollback finalnego fulfillment commit.

`pending` oraz `requires_reconciliation` nie mogą po runtime cutover mieć committed purchase grantów. Jest to zgodne z DB-COM-003, gdzie wszystkie lokalne granty i `state=fulfilled` commitują albo rollbackują się razem.

### 24.6. Exactly-once na poziomie każdej jednostki

Partial unique `(organization_id, source_order_item_id, source_order_item_grant_ordinal)` daje lokalną granicę exactly-once dla każdego typu downstream.

Retry po rollbacku może ponownie spróbować stworzyć ordinal `1..N`, bo poprzednia transakcja nie zostawiła committed rows. Retry po sukcesie widzi `fulfilled`, a nawet błędna próba ponownego insertu tego samego ordinalu zostałaby odrzucona.

Nie definiujemy tutaj globalnej kolejności locków ani kolejności przetwarzania mixed OrderItemów — to świadomie DB-COM-008.

### 24.7. Purchased i non-purchased provenance nie mogą się mieszać

Najważniejsza reguła provenance:
- purchase row musi mieć exact `source_order_item_id + ordinal`,
- non-purchase row nie może być policzony jako purchase tylko dlatego, że ma podobny timestamp, SKU, kwotę, tenant albo status.

Dla Exam granicę dodatkowo wzmacnia `source_type`. Dla License brak source OrderItem oznacza „nieudowodniony jako purchase”, a nie „automatycznie darmowy/operator”. Dla Service pełna alternatywna provenance zostaje DB-COM-007.

### 24.8. Migration safety

Schema migration nie może:
- tworzyć brakujących inventory/entitlements, aby liczba zgadzała się z quantity,
- usuwać nadmiarowych grantów,
- łączyć istniejącego inventory z OrderItem po timestampie, UUID, cenie, nazwie, tym samym tenant albo podobnym SKU,
- zamieniać free/adjustment/operator grant w purchase,
- przepisywać już assigned/activated/consumed/reserved historii, aby constraint przeszedł,
- wykonywać regrantów.

Ordinal można nadać dopiero po udowodnieniu exact purchase row set; sam ordinal nie może służyć do zgadywania lineage. Pełna klasyfikacja legacy evidence oraz reconciliation pozostaje DB-COM-006.

### 24.9. Preservation gate

Zachowane bez zmian:
- DB-FIN-001 i DB-FIN-002,
- DB-COM-001 immutable OrderItem/SKU/quantity/pricing,
- DB-COM-002 trusted payment settlement,
- DB-COM-003 durable fulfillment i all-or-none local grant transaction,
- DB4_6 License Inventory / Assignment / Activation / Revoke,
- DB4_7 Exam Inventory ledger / Reservation / consume-on-start,
- brak wymyślonej `internal_exam_products` lub `service_products` jako wymagania,
- generic service source XOR i activation pozostają DB-COM-007,
- purchase-history pozostaje DB-COM-005,
- global lock order pozostaje DB-COM-008,
- legacy reconciliation pozostaje DB-COM-006,
- `core-schema.yml` i `docs/87...` nadal zamrożone,
- DB4_10+, Stage 5, Laravel migrations i UI nieruszone.

Stan po fixerze:
- P0 OPEN: **0**,
- P1 OPEN: **4**,
- resolved: **6/10**,
- result: **FAIL_WITH_4_P1_BLOCKERS**.

Następny dozwolony krok po central gate: **DB-COM-007 only**.

**STOP przed DB-COM-007.**

## 25. DB-COM-007 — wynik fixera: PASS

DB-COM-007 domyka brakującą granicę dla `service_entitlements`: każda jednostka generic service ma dokładnie jedno źródło biznesowe — **purchase** albo **operator grant** — oraz zamkniętą relację między `activation_mode`, bieżącym stanem entitlementu i dokładnie jedną historyczną `service_activation`. Nie zmieniamy modeli licencji ani egzaminów i nie tworzymy sztucznej tabeli `service_products`.

### 25.1. Provenance ma twardy XOR

`service_entitlements` pozostaje tenant-owned. Źródło nie jest osobnym mutable stringiem, tylko wynika z istniejących pól.

Dla **purchase** wymagamy jednocześnie:
- `source_order_item_id != NULL`,
- `source_order_item_grant_ordinal != NULL`,
- `source_grant_reference = NULL`.

Dla **operator grant** wymagamy:
- `source_order_item_id = NULL`,
- `source_order_item_grant_ordinal = NULL`,
- `source_grant_reference` non-null i niepuste.

Stan „oba źródła” oraz „brak źródła” są niedozwolone. `organization_id`, provenance, `service_type` i `activation_mode` są po insercie niezmienne.

### 25.2. Purchase entitlement wskazuje exact generic-service OrderItem

Zachowujemy lineage DB-COM-004:

`service_entitlements(organization_id, source_order_item_id)` → `order_items(organization_id, id)`.

Parent musi mieć `product_kind=generic_service`, ordinal musi mieścić się w `1..OrderItem.quantity`, a `(organization_id, source_order_item_id, source_order_item_grant_ordinal)` jest unique dla purchase-sourced service units.

Dodatkowo immutable `OrderItem.product_snapshot` dla generic service musi zawierać znormalizowane:
- `service_type`,
- `activation_mode = immediate | explicit`.

Purchase entitlement musi odzwierciedlać dokładnie te wartości. Nie dodajemy osobnej tabeli produktu tylko po to, aby powielić SKU — canonical sale SKU nadal pozostaje `commerce_catalog_item_id` zapisany na OrderItem.

To ważne rozróżnienie: dla generic service `Order fulfilled` oznacza **entitlement przyznany**, a nie automatycznie „usługa explicit została aktywowana”.

### 25.3. Operator grant nie udaje zakupu

Operator-granted entitlement nie ma OrderItem, Payment ani purchase-history Orderu. `source_grant_reference` jest trwałą, niepustą referencją provenance i nie może być payment ID, browser-return tokenem ani drugim źródłem płatności.

Nie narzucamy globalnej unique na `source_grant_reference`, ponieważ jedna sprawa/operator action może legalnie obejmować kilka jednostek. Exactly-once komendy operator grant realizuje wspólny `idempotency_records` z operation key `commerce.service_entitlement.operator_grant`; sama referencja provenance nie jest idempotency authority.

Nie dodajemy w tym fixerze nowego HTTP endpointu operator grant — Stage-3 API pozostaje frozen.

### 25.4. `service_activation` musi należeć do exact tenant entitlementu

`service_activations` dostaje zamkniętą same-tenant relację:

`service_activations(organization_id, service_entitlement_id)` → `service_entitlements(organization_id, id)`.

Na exact entitlement może istnieć najwyżej jedna activation. Activation row jest append-only po insercie i nie jest usuwany po późniejszym expiry/revoke, bo jest historycznym dowodem, że aktywacja rzeczywiście nastąpiła.

`effective_to`, jeśli istnieje, nie może być wcześniejsze niż `effective_from`. `activated_by_user_id` może pozostać null dla systemowej aktywacji w trybie immediate.

### 25.5. `immediate` i `explicit` mają różne transakcje

Dla `activation_mode=explicit` purchase fulfillment tworzy entitlement w stanie `available` i **zero** `service_activations`. Dopiero osobna akcja `/service-entitlements/{id}/activate` może utworzyć activation. API już wymaga dla niej `Idempotency-Key`; database contract korzysta z operation key `commerce.service_entitlement.activate`.

Dla `activation_mode=immediate` grant transaction tworzy entitlement i dokładnie jedną activation atomowo, a bieżący stan po commit to `activated`.

Ta sama reguła działa dla operator grant:
- explicit → `available`, bez activation,
- immediate → entitlement + activation w jednej transakcji.

W purchase flow payment settlement lub webhook nie wywołują bezpośrednio activation. Najpierw działa canonical order fulfillment. Tylko polityka entitlementu `immediate` może spowodować activation w tej samej lokalnej transakcji grantowej. Dla `explicit` payment success nigdy nie jest activation.

### 25.6. Final-state equivalence `activation_mode ↔ status ↔ activation`

Zachowujemy katalog statusów `granted | available | activated | expired | revoked`, ale po cutover `granted` nie jest poprawnym committed runtime final state. Jest wyłącznie wartością legacy / przejściową wewnątrz transakcji do czasu klasyfikacji.

Macierz finalna:
- `available` → tylko `explicit` i dokładnie 0 activation rows,
- `activated` → dokładnie 1 activation row,
- `immediate` → po grant transaction zawsze dokładnie 1 activation row i nigdy `available`,
- `expired` → nowa aktywacja zabroniona; immediate zachowuje 1 historyczną activation, explicit może mieć 0 albo 1 zależnie od tego, czy wygasł przed czy po aktywacji,
- `revoked` → analogicznie blokuje nową aktywację i nie kasuje wcześniejszego dowodu activation.

`activated` bez activation row oraz `available` z activation row są stanami niespójnymi. Terminalnego entitlementu nie cofamy do `available` ani `activated` zwykłą mutacją statusu.

### 25.7. Exactly-once i concurrency

Purchase exactly-once pozostaje oparte na DB-COM-004 `source_order_item_id + grant_ordinal`.

Explicit activation serializuje się na exact `service_entitlement FOR UPDATE` i dodatkowo korzysta z generic idempotency record. Kolejność operacji:
1. claim idempotency record,
2. lock entitlement,
3. sprawdzenie `activation_mode=explicit` i braku terminalnego stanu,
4. jeżeli activation już istnieje — zwrot istniejącego sukcesu bez drugiego efektu,
5. insert jednej activation,
6. ustawienie nonterminal current state na `activated`,
7. zapis bezpiecznego wyniku idempotency.

Retry płatności albo provider event nie może zmienić explicit entitlementu z `available` na `activated`.

### 25.8. Migration safety

Przed włączeniem constraints każdy legacy entitlement musi zostać sklasyfikowany jako exact purchase albo exact operator grant.

Nie wolno:
- tworzyć sztucznego `source_grant_reference`, tylko żeby przejść XOR,
- zgadywać OrderItem po timestampie, kwocie, cenie, tenant, service type lub podobnym SKU,
- przepinać wrong-tenant entitlement/activation,
- tworzyć activation row tylko dlatego, że legacy status brzmi `activated`,
- wymyślać timestampu immediate activation bez historycznego evidence,
- usuwać istniejącej activation, żeby otrzymać `available`,
- zamieniać operator grant w purchase lub odwrotnie bez dowodu,
- robić regrant podczas migracji schematu.

Ambiguous source lub activation history = FAIL + reviewed remediation. Pełne klasy legacy evidence pozostają DB-COM-006.

### 25.9. Preservation gate

Zachowane bez zmian:
- DB-FIN-001/002,
- DB-COM-001 catalog, pricing i immutable OrderItem snapshot,
- DB-COM-002 trusted payment settlement i browser-return != paid authority,
- DB-COM-003 order-level fulfillment atomicity,
- DB-COM-004 exact OrderItem provenance i quantity equivalence,
- DB4_6 License Inventory / Assignment / Activation,
- DB4_7 Internal Exam Inventory / Reservation / consume-on-start,
- explicit service activation pozostaje oddzielona od payment success,
- operator grants nie stają się zakupami,
- DB-COM-005, DB-COM-008 i DB-COM-006 pozostają OPEN,
- `core-schema.yml` i `docs/87...` nadal są zamrożone,
- DB4_10+, Stage 5, Laravel migrations i UI pozostają nieruszone.

Stan po fixerze:
- P0 OPEN: **0**,
- P1 OPEN: **3**,
- resolved: **7/10**,
- result: **FAIL_WITH_3_P1_BLOCKERS**.

Następny dozwolony krok po central gate: **DB-COM-005 only**.

**STOP przed DB-COM-005.**

## 26. DB-COM-005 — wynik fixera: PASS

DB-COM-005 domyka wyłącznie deterministyczną projekcję `Historia zakupów`: prezentacyjny numer zamówienia, biznesową datę zamówienia, osobną datę księgowania, status wynikający z canonical payment/fulfillment facts oraz stabilny porządek paginacji. Nie zmieniamy API Stage 3, nie projektujemy jeszcze globalnego lock order i nie wykonujemy legacy backfillu.

### 26.1. `Number` ma jedną authority

Nowy Order otrzymuje niezmienny `order_sequence BIGINT` w zakresie jednego Organization. Candidate key `(organization_id, order_sequence)` jest finalną granicą unikalności.

Sekwencja nie powstaje przez `MAX(order_sequence)+1`. Wprowadzamy tenantowy licznik `organization_commerce_order_sequences`, którego dokładny wiersz jest blokowany `FOR UPDATE`; rezerwacja numeru, zwiększenie licznika i insert Orderu należą do jednej transakcji.

Nie wymagamy numeracji bez luk. Raz przypisany numer committed Orderu nie może zostać ponownie użyty ani zmieniony.

Widoczne `Number` jest deterministycznym dziesiętnym renderingiem `order_sequence`, a nie osobnym mutable polem. Nie jest też sekretem ani payment authority — publiczny identyfikator płatności pozostaje osobnym opaque reference.

### 26.2. `Data` używa `ordered_at`, nie technicznego przypadku `created_at`

Order dostaje server-owned `ordered_at timestamptz`, ustawiane raz przy złożeniu zamówienia. Dla nowego Orderu może początkowo być równe `created_at`, ale jest osobnym business field i po zapisie jest immutable.

Klient nie może podać własnego authoritative czasu zamówienia.

### 26.3. `Data księgowania` ma exact trusted source

`orders.booked_at` jest nullable i może przejść wyłącznie `NULL -> exact trusted paid-resolution timestamp`.

Macierz źródeł:
- `unpaid` → `booked_at = NULL`,
- `payment_pending` → `booked_at = NULL`,
- non-zero paid Order → `booked_at = order_payment_settlements.settled_at`,
- zero-total paid Order → `booked_at = zero_total_settled_at`.

Ustawienie jest atomowe z pierwszym canonical paid resolution. Późniejszy confirmed payment attempt, który nie jest settlement winnerem, nie może zmienić `booked_at`. Fulfillment pending/reconciliation również nie zmienia daty księgowania.

Po ustawieniu `booked_at` nie wolno go wyczyścić ani przepisać. Legacy backfill bez exact trusted evidence pozostaje DB-COM-006.

### 26.4. Purchase-history status nie jest drugim mutable statusem

Canonical projection ma wartości:
- `unpaid`,
- `payment_pending`,
- `paid_processing`,
- `completed`,
- `requires_reconciliation`.

Mapowanie jest czysto po canonical facts:
- payment projection `unpaid` → `unpaid`,
- payment projection `pending` → `payment_pending`,
- `paid + fulfillment.pending` → `paid_processing`,
- `paid + fulfillment.fulfilled` → `completed`,
- `paid + fulfillment.requires_reconciliation` → `requires_reconciliation`.

`orders.status` nie może po cutover pozostać niezależnym business authority. Jeżeli projection jest materializowana, musi być transactionally guarded względem źródłowych payment/fulfillment facts.

### 26.5. Nie wymyślamy niepotwierdzonych polskich etykiet

Z obserwowanego ekranu potwierdzona jest wyłącznie etykieta `Nieopłacone`. Pozostałe polskie labelki nie są zamrażane w DB4_9.

To samo dotyczy niepotwierdzonych search/sort/filter controls, faktur oraz innych akcji historycznych.

### 26.6. Projekcja każdego wiersza historii jest jednoznaczna

Źródła kolumn:
- `Number` → `orders.order_sequence`,
- `Zamówienie` → immutable `order_items` + snapshot DB-COM-001,
- `Data` → `orders.ordered_at`,
- `Data księgowania` → `orders.booked_at`,
- `Kwota` → `orders.total_amount_minor + currency`,
- `Status` → canonical purchase-history status projection.

Nazwy i ceny historycznych pozycji nie mogą być ponownie pobierane z bieżącego katalogu. Zmiana katalogu nie może zmienić starej historii.

Operator-granted generic service bez Orderu nie trafia do purchase history.

### 26.7. Stable pagination ma pełny tie-breaker

Domyślny porządek to:

`ordered_at DESC, order_sequence DESC, id DESC`.

Dzięki tenantowo unikalnemu `order_sequence` dwa Ordery z identycznym timestampem nie mają niedeterministycznej kolejności. UUID pozostaje ostatnim defensywnym tie-breakerem, nie główną business ordering authority.

Stage-3 `page/per_page` pozostaje bez zmian. Dla niezmiennego zbioru wynik jest deterministyczny. Nie deklarujemy fałszywie snapshot-stable offset pagination pomiędzy osobnymi requestami, gdy w międzyczasie powstają nowe Ordery; ewentualny snapshot/cursor contract może zostać zsynchronizowany dopiero w Stage 5, jeśli produkt będzie tego wymagał.

### 26.8. Page size zachowuje istniejący API contract

UI potwierdza selektor `10 / 25 / 50 / 100`. Jednocześnie Stage-3 API dopuszcza zakres `1..100`, więc DB-COM-005 nie zawęża API tylko do czterech wartości.

Jeżeli używany jest filtr statusu, filtruje canonical derived status, nie legacy mutable label.

### 26.9. Historia jest immutable po złożeniu Orderu

Po order placement immutable są m.in. tenant, `order_sequence`, `ordered_at`, currency, total oraz item product/quantity/pricing/VAT/discount/display snapshots. Jedyną późniejszą zmianą z tego zestawu projekcyjnego jest pojedyncze trusted ustawienie `booked_at`.

Nieopłaconego lub nieudanego Orderu nie wolno hard-delete tylko po to, by zniknął z historii. Późniejsze assignment/activation/consumption licencji, egzaminu lub usługi nie przepisuje historii zakupu.

### 26.10. Legacy data nie jest naprawiana heurystycznie w tym kroku

DB-COM-005 definiuje runtime contract dla nowych Orderów. Nie przypisuje numerów ani `booked_at` starym rekordom na podstawie timestampu, UUID, amount, bieżącej kolejności czy `updated_at`. Nie fabrykuje też booking date z browser-return albo payment-attempt creation time.

Pełna evidence-class migration/reconciliation matrix pozostaje DB-COM-006.

### 26.11. Preservation gate

Zachowane bez zmiany:
- DB-COM-001 immutable items/pricing snapshots,
- DB-COM-002 payment/settlement/zero-total authority,
- DB-COM-003 fulfillment authority,
- DB-COM-004 grant lineage i quantity equivalence,
- DB-COM-007 ServiceEntitlement source/activation integrity.

Nie rozwiązano DB-COM-008 ani DB-COM-006. Frozen agregaty, Stage 5, migracje Laravel i UI pozostają nietknięte.

### 26.12. Wynik po DB-COM-005

- P0 OPEN: **0**,
- P1 OPEN: **2**,
- resolved: **8/10**,
- result: **FAIL_WITH_2_P1_BLOCKERS**.

Następny dozwolony krok po central gate: **DB-COM-008 only**.

**STOP przed DB-COM-008.**

## 27. DB-COM-008 — wynik fixera: PASS

DB-COM-008 domyka wyłącznie concurrency boundary dla Student Finance i Platform Commerce. Nie zmieniamy semantyki Payment/PaymentEvent, fulfillmentu, purchase grantów ani purchase history; ustalamy wspólny porządek blokad i jednoznaczne race-winner semantics, aby retry, webhook, manual reconciliation i fulfillment worker nie mogły wejść w lock inversion albo wykonać drugiego business effectu.

### 27.1. Student Finance i Platform Commerce nie dostają wspólnego money root

`student_payments` oraz platformowe `payments` nadal należą do różnych bounded contexts. Komenda Student Finance nie może dla swojej operacji blokować `orders`, platformowego `payments` ani `order_fulfillments`; analogicznie komenda Platform Commerce nie może blokować `student_charges` ani `student_payments`.

To jest ważne również dla `create charge from Course cost`: ta operacja tworzy należność kursanta w Student Finance. Nie jest zakupem platformowym i nie może być modelowana przez platformowy Order/Payment.

### 27.2. Canonical Commerce root = exact `orders` row

Wszystkie komendy, które mogą zmienić business state tego samego Order, serializują się na dokładnym `orders` row przez `FOR UPDATE`.

Dotyczy to co najmniej:
- utworzenia payment attempt,
- zastosowania trusted provider event,
- manual reconciliation payment attemptu,
- utworzenia lub odczytu jedynego settlementu Order,
- utworzenia lub odczytu durable fulfillment authority,
- fulfillment/retry fulfillment,
- reviewed fulfillment reconciliation transition.

Po przejęciu locka system ponownie odczytuje canonical payment i fulfillment facts. Stan przeczytany przed lockiem nie może być podstawą finalnej decyzji.

### 27.3. Jedna kolejność blokad

Po command-local idempotency/event dedupe obowiązuje:

`Order -> relevant Payment(s) -> OrderPaymentSettlement -> OrderFulfillment -> purchase grant rows`.

Jeżeli komenda dotyka więcej niż jednego Payment, istniejące rows blokuje po `payment_id ASC`. OrderItems po placement są immutable, więc fulfillment czyta je deterministycznie bez `FOR UPDATE`, w kolejności `order_items.id ASC`.

Granty mixed Order są tworzone w kolejności:

`order_item.id ASC -> source_order_item_grant_ordinal 1..quantity ASC`.

Zakazane są odwrotne ścieżki typu Payment→Order, Fulfillment→Order, Inventory→Order albo Entitlement→Order.

### 27.4. Payment creation vs settlement

Jeżeli settlement zdąży się zatwierdzić przed zdobyciem locka przez `create payment attempt`, komenda po rechecku widzi Order jako paid i nie tworzy nowego payable effectu.

Jeżeli nowy attempt zdąży powstać przed settlementem innego attemptu, taki pending attempt może historycznie pozostać. Jego późniejsze potwierdzenie nie może jednak utworzyć drugiego `order_payment_settlement`, drugiego fulfillmentu ani drugiego grant setu. External money truth jest zachowywana zgodnie z DB-COM-002, a konflikt trafia do reconciliation.

### 27.5. Webhook vs manual reconciliation

Obie ścieżki serializują się na tym samym Order i dopiero później blokują odpowiedni Payment.

Pierwsza transakcja, która zgodnie z trusted confirmation rules utworzy unique `order_payment_settlement`, wygrywa business settlement.

Dla tego samego Payment późniejsza zgodna informacja jest no-op/replay. Dla innego, rzeczywiście potwierdzonego Payment zachowujemy external payment truth, ale nie przepinamy settlementu i nie wykonujemy ponownie fulfillmentu; taki przypadek wymaga reconciliation.

### 27.6. Settlement vs fulfillment worker

Settlement oraz pending `order_fulfillment` dla non-zero Order powstają atomowo pod tym samym Order root.

Worker, który przyjdzie przed commit paid resolution, nie może tworzyć fulfillmentu na podstawie luźnego payment status — zwraca `no runnable fulfillment`. Worker po commit widzi exact pending fulfillment, blokuje `Order -> OrderFulfillment` i dopiero wtedy wykonuje lokalne granty.

Dwa fulfillment workers nie mogą wykonać dwóch zestawów grantów: pierwszy, który przeprowadzi `pending -> fulfilled`, wygrywa; kolejny po locku widzi `fulfilled` i kończy jako `already fulfilled`.

### 27.7. Fulfillment vs reviewed reconciliation

Worker i reviewed reconciliation używają identycznej kolejności `Order -> OrderFulfillment`.

Jeżeli `pending -> fulfilled` zatwierdzi się pierwszy, nie wolno później zdegradować row do `requires_reconciliation`. Jeżeli najpierw zatwierdzi się `pending -> requires_reconciliation`, worker nie tworzy żadnych grantów.

Przejście `requires_reconciliation -> pending` pozostaje wyłącznie reviewed remediation i samo nie może tworzyć grantów. Dopiero późniejszy worker, po nowym lock/recheck, może ponowić fulfillment.

### 27.8. Downstream domains nie mogą zamknąć cyklu locków

Commerce fulfillment tworzy nowe available License Inventory i Internal Exam Inventory units. Nie przypisuje licencji, nie aktywuje jej, nie rezerwuje egzaminu i nie konsumuje attemptu. Późniejsze komendy tych domen używają własnych roots i nie wracają do transakcji Commerce.

Explicit `ServiceActivation` po zakupie serializuje się na exact `service_entitlement` row zgodnie z DB-COM-007 i nie przejmuje Order locka. Immediate activation wykonywana wewnątrz purchase fulfillment tworzy nowe Entitlement + Activation rows w tej samej transakcji — nie zaczyna od blokowania istniejącego Entitlement.

### 27.9. `create charge from Course cost` ma exact source boundary

Sam HTTP `Idempotency-Key` nie chroni przed dwiema różnymi request keys dla tego samego kursu. Dlatego wprowadzamy durable `course_cost_charge_origins`.

Dla source effectu `create from course cost` obowiązuje unique `(organization_id, course_enrollment_id)`. Origin wskazuje exact Course/Student oraz exact utworzony StudentCharge i przechowuje server-owned amount/currency snapshot źródłowego kosztu.

Komenda używa operation key `student_finance.charge.create_from_course_cost`, a concurrency root to exact `course_enrollment` row:

1. claim generic idempotency,
2. lock exact CourseEnrollment,
3. ponowny odczyt trusted cost source,
4. jeżeli origin istnieje — zwróć ten sam Charge,
5. w przeciwnym razie utwórz Charge i origin atomowo.

Zwykły ręczny Charge z optional Course context nie staje się przez podobieństwo kwoty `course_cost` origin. Dwie różne idempotency keys dla tego samego source effectu nie mogą stworzyć dwóch należności.

Semantyki późniejszej zmiany ceny kursu, repricingu albo kolejnej korekty kosztu nie wymyślamy w DB-COM-008.

### 27.10. Brak provider I/O pod lockiem

Nie wolno trzymać Order/Charge/Course locka ani otwartej business DB transaction podczas oczekiwania na zewnętrzny provider network call, browser redirect lub inne zewnętrzne I/O.

Deadlock/serialization failure oznacza retry całej komendy od jej idempotency boundary. Nie wolno ponawiać wykonania od środka lock sequence na podstawie założenia, że poprzednia próba „prawie się udała”.

### 27.11. Audit/outbox intent bez wchodzenia w DB4_10

Każda state-changing komenda ma atomowo zapisać business audit/outbox intent razem ze zmianą domenową. Audit/outbox nie jest jednak concurrency root i nie może być blokowany przed Order/Charge/Course root.

Dokładne tabele, kolumny, indeksy, retry worker, retention i delivery semantics pozostają wyłącznie DB4_10.

### 27.12. Preservation gate

Zachowane bez zmiany:
- DB-FIN-001 i DB-FIN-002,
- DB-COM-001 pricing/snapshot,
- DB-COM-002 Payment/Event/Settlement,
- DB-COM-003 fulfillment authority,
- DB-COM-004 exact purchase grant lineage + quantity,
- DB-COM-007 ServiceEntitlement activation,
- DB-COM-005 purchase history projection,
- License Assignment/Activation lifecycle z DB4_6,
- Internal Exam Reservation/Consumption lifecycle z DB4_7,
- frozen agregaty,
- Stage 3 API.

DB-COM-006 pozostaje **OPEN**. Nie wykonaliśmy żadnego legacy backfillu, payment-winner reconstruction ani inventory lineage guessing.

### 27.13. Wynik po DB-COM-008

- DB-COM-008: **PASS**,
- P0 OPEN: **0**,
- P1 OPEN: **1**,
- resolved: **9/10**,
- result: **FAIL_WITH_1_P1_BLOCKER**.

Następny dozwolony krok po central gate: **DB-COM-006 only**.

**STOP przed DB-COM-006.**## 28. DB-COM-006 — wynik fixera: PASS

DB-COM-006 domyka ostatni blocker DB4_9: bezpieczne przejście danych legacy do kontraktów Student Finance i Platform Commerce. Zasada nadrzędna jest fail-closed: migracja może odtworzyć tylko fakt wynikający z dokładnej relacji, wiarygodnego historycznego dowodu albo jawnie zatwierdzonej remediacji. Podobieństwo nie jest dowodem.

### 28.1. Cztery klasy dowodu

Każdy brakujący fakt trafia do jednej z czterech klas:
- `safe_exact_derivation` — wynik jednoznaczny z istniejącego exact parentu, np. tenant OrderItem z jego dokładnego Order,
- `proven_historical_fact` — trwały historyczny dowód bezpośrednio potwierdza fakt,
- `reviewed_exact_remediation` — dokładne ID rekordów + evidence reference + reviewer/actor + reason,
- `unresolved_ambiguity` — automatyczny backfill zabroniony; cutover zatrzymany.

Timestamp proximity, UUID, sama kwota, ten sam tenant, podobna nazwa, czas trwania, aktualna cena, nearest row, `created_at`, `updated_at`, browser redirect i czas uruchomienia migracji nie mogą być dowodem exact lineage ani payment causality.

### 28.2. OrderItem, produkt i historyczna cena

`order_items.organization_id` wolno wyprowadzić z dokładnego istniejącego parent Order. Brak albo niejednoznaczny parent zatrzymuje migrację; nie wolno przepinać itemu do „najbardziej pasującego” Order.

Exact SKU/product/catalog reference wymaga trwałego exact reference albo reviewed mapping z dowodem. Nie wolno wybierać produktu po nazwie, długości licencji, cenie, VAT, kwocie czy czasie.

Historycznych cen nie przeliczamy z aktualnego cennika. Legacy snapshot można zachować, jeśli jest autorytatywny i spełnia formułę DB-COM-001. Brak lub niespójność ceny/VAT/discount/total wymaga reviewed remediation albo FAIL.

### 28.3. Numer zamówienia i daty

Istniejący wiarygodny, unikalny numer zamówienia zachowujemy. Gdy fizyczne `order_sequence` musi zostać uzupełnione, a historycznej kolejności nie da się udowodnić, dopuszczalna jest wyłącznie reviewable techniczna alokacja per tenant. Taki numer nie może być przedstawiany jako odtworzona historyczna chronologia.

`ordered_at` wymaga udowodnionego historycznego czasu lub reviewed exact remediation. `booked_at` wymaga trusted payment resolution, zero-total settlement albo reviewed exact evidence. Browser return, ogólne `created_at/updated_at` i migration current time są zakazane jako substytut.

### 28.4. Payment, PaymentEvent i Settlement

Legacy `paid` string nie tworzy settlementu. Settlement może zostać odtworzony tylko dla exact Payment z trusted confirmation zgodną z DB-COM-002.

Nie wybieramy zwycięzcy po najbliższym czasie, kwocie, providerze czy UUID. Jeżeli istnieje więcej niż jeden wiarygodny kandydat, zachowujemy external payment truths, ale settlement wymaga review. Direct bank transfer wymaga niezależnego bank/provider evidence.

Jeżeli dokładnego eventu sprawczego nie można udowodnić, nie fabrykujemy `source_event`. `reviewed_reconciliation` jest legalne wyłącznie wtedy, gdy istnieje realny niezależny dowód i komplet reviewer/reason metadata.

### 28.5. Fulfillment: paid nie znaczy fulfilled

Migracja nie uruchamia fulfillment worker ani żadnego grant command. Sam paid/settled status oraz „pasujące” inventory nie dowodzą fulfillmentu.

Jeżeli settlement jest udowodniony, lecz kompletny exact grant set nie jest, `order_fulfillment` ma być `requires_reconciliation` — nie `pending` i nie `fulfilled`. Dzięki temu worker nie może automatycznie dograntować brakujących jednostek i stworzyć duplikatów.

`fulfilled` wolno odtworzyć dopiero po udowodnieniu settlement/zero-total source oraz kompletnego exact grant set dla każdej pozycji Order.

### 28.6. Downstream inventory i quantity

Legacy License Inventory, Internal Exam Inventory i ServiceEntitlement można połączyć z OrderItem wyłącznie przy exact purchase lineage. Free, adjustment, manual/operator grant nie mogą zostać przemianowane na paid tylko po to, aby constraints przeszły.

Dla `quantity=N` wymagamy dokładnie N udowodnionych właściwych jednostek. Dopiero po udowodnieniu całego setu można technicznie przypisać ordinals `1..N`, np. stabilnie po immutable row id. Ordinal jest enumeracją — nie dowodem lineage ani chronologii.

Migracja nie tworzy brakujących units, nie klonuje istniejących i nie usuwa „nadmiarowych”, aby wymusić zgodność quantity.

### 28.7. Istniejący lifecycle pozostaje nietknięty

Nie wolno delete/recreate ani przepisywać:
- License Assignment / Activation,
- Exam Reservation / Attempt / Consumption,
- Service Activation.

Ambiguous ServiceEntitlement source pozostaje unresolved. Payment success nadal nie fabrykuje explicit activation.

### 28.8. Legacy `course_cost_charge_origins`

Origin wolno odtworzyć tylko wtedy, gdy trusted history bezpośrednio dowodzi, że exact StudentCharge powstał przez `create from Course cost`. Ten sam Course, Student, amount, currency, title lub timestamp nie wystarczają.

Jeżeli fakt nie jest udowodniony, Charge pozostaje zwykłą historyczną należnością z optional Course context. Migracja nie tworzy drugiego Charge tylko po to, aby wypełnić origin.

### 28.9. Write fence i kolejność cutover

Finalny preflight nie może ścigać się ze starymi write paths. Przed finalną walidacją i aktywacją constraints potrzebny jest maintenance write fence albo równoważny tryb, w którym wszystkie writes już przechodzą przez nowy kontrakt.

Kolejność:
1. write fence / new-contract-only writes,
2. structural fields i constraints jako temporary nullable/not-valid gdzie potrzebne,
3. tylko safe exact derivations,
4. row-level reconciliation manifest,
5. tylko reviewed exact remediations,
6. pełny preflight pod fence,
7. NOT NULL/FK/unique/check/transactional guards,
8. przełączenie runtime i wznowienie writes,
9. post-cutover invariant verification.

Jakikolwiek `unresolved_ambiguity` blokuje activation constraints.

### 28.10. Reconciliation manifest i brak regrantu

Manifest jest artefaktem migracyjnym/review report, a nie nową runtime business authority. Dla każdego case przechowuje exact row ID, problem, evidence class/reference, mapping, reviewer/reason i disposition. Raw legacy evidence pozostaje zachowany.

Restart migracji nie może powielić Settlement, Fulfillment ani grantów. Formalnej historii finansowej i downstream business history nie wolno hard-delete, aby „naprawić” constraints.

### 28.11. Preservation gate

Zachowane są DB-FIN-001/002 oraz DB-COM-001/002/003/004/005/007/008, rozdział Student Finance ≠ Platform Commerce, DB4_6 License lifecycle, DB4_7 Exam lifecycle i DB4_8 PKK. Nie powstały Laravel migrations, nie zmieniono Stage-3 API, nie rozpoczęto DB4_10 ani Stage 5.

Frozen agregaty nadal pozostają nietknięte. Ich synchronizacja jest osobną bramką.

### 28.12. Wynik po DB-COM-006

- DB-COM-006: **PASS**,
- P0 OPEN: **0**,
- P1 OPEN: **0**,
- resolved: **10/10**,
- blocker result: **PASS**,
- final aggregate sync: **PASS**.

DB-COM-006 pozostaje ostatnim zamkniętym blockerem; jego legacy/reconciliation contract nie został zmieniony podczas aggregate sync.

---

## 29. DB4_9 final aggregate sync — PASS

Finalny sync został wykonany dopiero po zamknięciu wszystkich 10 blockerów DB4_9. Nie powstała nowa decyzja biznesowa; agregaty są projekcją zamkniętych kontraktów bounded contextu.

### 29.1. Provenance aggregate sync

- base po central gate DB-COM-006: `d899491bc8dd73a7b82d1c28c50f32b6eea1a169`,
- machine aggregate commit: `9c06a7928aecb61627ccb52c083cda58b2505c25`,
- machine aggregate blob `specs/database/core-schema.yml`: `f3519060967085517329bc668fac02e5c1fd2dbd`,
- narrative aggregate commit: `05a030bc4513ab06514f748909559e35d0c78996`,
- narrative aggregate blob `docs/87-physical-database-schema.md`: `f3ebb764a5279cbabc9c75c2fcf9733f36ed22a8`,
- bounded source close commit: `19a6e51692d37299508c046f9a65ea99af72bd90`,
- bounded source blob `specs/database/student-finance-commerce.yml`: `a5ac5c33fcc38c251531d815c18918691b170076`.

### 29.2. Semantic-loss gate

PASS. Machine i narrative aggregate zachowują:
- rozdział Student Finance i Platform Commerce,
- exact tenant/student/course/payment relations,
- derived Student Finance balance oraz reversal/cancellation history,
- `course_cost_charge_origins` jako exact source effect,
- immutable OrderItem/catalog/pricing snapshots,
- trusted payment confirmation + one-per-order settlement authority,
- zero-total settlement boundary,
- paid ≠ fulfilled oraz durable exactly-once fulfillment,
- exact downstream `OrderItem + grant ordinal` provenance,
- License exact product lineage bez zmiany DB4_6 lifecycle,
- paid Exam lineage bez zmiany DB4_7 Reservation/consume-on-start lifecycle,
- generic service purchase/operator provenance XOR i activation equivalence,
- deterministic purchase-history number/date/booking/status/order,
- global Commerce lock hierarchy i bounded-context race separation,
- fail-closed legacy reconciliation/backfill bez heurystyk i bez regrantu.

Nie zachowano żadnego starego provisional skrótu jako równoległej authority.

### 29.3. Migration order i invariant tests

PASS. `core-schema.yml` oraz `docs/87...` zawierają DB4_9 migration ordering i invariant-test obligations wymagane przez DB-FIN-001/002 i DB-COM-001..008. Finalny DB4_11 nadal pozostaje osobnym Stage-4 slice i nie został rozpoczęty tutaj.

### 29.4. Preservation / scope gate

PASS:
- DB4_6 License lifecycle zachowany,
- DB4_7 Internal Exam lifecycle zachowany,
- DB4_8 PKK zachowany,
- payment browser return nadal nie jest payment-confirmation authority,
- Student Finance Payment nadal nie jest Platform Commerce Payment,
- audit/outbox business intent jest zachowany, ale jego finalny physical model pozostaje DB4_10,
- nie rozpoczęto DB4_10 ani DB4_11,
- Stage 5 pozostaje zablokowany,
- nie utworzono migracji Laravel,
- nie rozpoczęto UI/feature implementation.

### 29.5. Wynik slice

**DB4_9_STUDENT_FINANCE_COMMERCE = PASS.**
**10/10 blockerów resolved. 0 P0 / 0 P1 OPEN. Final aggregate sync PASS.**

Następny dozwolony krok: **DB4_10_AUDIT_OUTBOX_NOTIFICATIONS_DIAGNOSIS**, wyłącznie po kolejnym jawnym poleceniu użytkownika. Nie wolno wcześniej przejść do DB4_11, Stage 5, Laravel migrations ani UI.

**STOP przed DB4_10.**
