# 126. Core v1 — Student Finance closure

Data: 2026-09-11

**Slice:** `CORE-V1-STUDENT-FINANCE-001`  
**Implementation machine:** PASS  
**Narrative payload:** PASS
**Central closure:** PASS

## 1. Zakres

Slice obejmuje wyłącznie Student Finance / `Płatności kursanta`:

- należności kursanta,
- wpłaty przypisane do konkretnej należności,
- częściowe i pełne spłaty,
- bezpieczne anulowanie należności,
- history-preserving reversal wpłaty,
- saldo jako projekcję serwerową,
- create-time `initial_cost` kursu jako atomowy side effect Student Finance,
- dependency-closed migracje fazy `expand`,
- backend/API/permissions/audit/idempotency,
- wykonywalne testy, truthful Stage-4 DBT runtime traceability i implementation traceability,
- własny UI finansów na szczegółach kursanta oraz aktywację pola kosztu przy tworzeniu kursu.

Student Finance pozostaje osobnym bounded contextem od Platform Commerce / `Historia zakupów`. Ten slice nie implementuje orders, purchase history, płatności za produkty platformy ani provider payment webhooków.

Nie rozpoczęto Learning Access / Licenses, Internal Exams, PKK provider adaptera, Dashboard / Notifications ani Platform Purchase History.

## 2. Clean accepted implementation provenance

Clean accepted implementation commit:

`ed09193e3e0b9135d687651dcd2db1c67e47b7db`

Parent accepted commit:

`84dd71addd94200caab20772f565df9ebd9acb3b`

Accepted Implementation CI:

`34606896087` — **5/5 SUCCESS**.

Accepted API Contract Gate:

`34606896081` — **PASS**.

Tymczasowe helper/PR validation zmiany, w tym dodatkowe uprawnienie workflow potrzebne wyłącznie do PR secret-scan, nie weszły do clean accepted implementation commit.

## 3. Migracje

Materializacja Stage-4 DAG wzrosła z **54/170** do **57/170** node'ów / phase steps. Slice dodał dokładnie trzy restart-safe nodes fazy `expand`:

- `MIG-TBL-STUDENT_CHARGES`,
- `MIG-TBL-STUDENT_PAYMENTS`,
- `MIG-TBL-COURSE_COST_CHARGE_ORIGINS`.

Execution identity:

`75312e6bd283334be21a38975fa868e742dd1c649acf07abaafe1cf5842cd4fb`

Plan identity pozostał:

`d2fd6bc999dc2a5ee024e9b91bf00f5a5dad29575554a0ba42eba67107c23f10`

Stage-4 authority blob pozostał:

`ad5f2aa2e14ef248b95dd3dda0d1cbcb2e69d441`

Nie materializowano późniejszych candidate-key/FK/index/check/preflight phases. Globalny migration phase barrier pozostaje obowiązujący, a zamknięte Stage-4 authority nie zostały przepisane.

## 4. Student Finance ledger

Zaimplementowano osobne ledger records dla należności i wpłat:

- `student_charges` jest źródłem należności,
- `student_payments` przechowuje wpłaty przypisane do konkretnej należności,
- system nie utrzymuje osobnego mutowalnego pola `balance`,
- `paid_amount`, `remaining_amount` i podsumowanie finansów są projekcjami z ważnych rekordów ledgerowych,
- kwoty są przechowywane i przesyłane jako `amount_minor`, nigdy jako float,
- waluta jest jawna,
- payment currency musi odpowiadać walucie należności,
- wpłata nie może przekroczyć pozostałej kwoty,
- cancelled charge nie przyjmuje nowych wpłat.

Status należności jest deterministyczną projekcją:

- `open`,
- `partially_paid`,
- `paid`,
- `cancelled`.

## 5. Reversal i cancellation zamiast destrukcyjnej edycji

Historia finansowa nie jest hard-delete'owana ani przepisywana.

Payment reversal:

- zachowuje oryginalny payment row,
- zapisuje `reversed_at`, aktora i powód,
- usuwa wpływ wpłaty z bieżącej projekcji salda,
- jest write-once lifecycle effect.

Charge cancellation:

- zachowuje oryginalny charge row,
- zapisuje `cancelled_at`, aktora i powód,
- wymaga wcześniejszego reversal wszystkich aktywnych wpłat,
- nie usuwa historycznych payment records,
- wyłącza anulowaną należność z aktywnego salda.

## 6. Idempotency, locking i fail-closed runtime

Krytyczne mutacje finansowe korzystają z generic `Idempotency-Key` i współdzielonego runtime idempotency store.

Ten sam idempotency key z tym samym requestem może odtworzyć wcześniejszy wynik bez drugiego efektu dla:

- utworzenia należności,
- zapisania wpłaty,
- reversal wpłaty,
- anulowania należności.

Payment allocation i lifecycle commands serializują się na właściwym charge root przed wyliczeniem pozostałej kwoty. Runtime odrzuca m.in.:

- nadpłatę ponad remaining amount,
- wpłatę do cancelled charge,
- anulowanie charge z aktywnymi wpłatami,
- drugi fresh reversal tego samego payment,
- drugi fresh cancellation tego samego charge,
- payment w innej walucie niż parent charge,
- foreign-tenant i wrong-student targets.

To jest aktualna application/runtime boundary. Nie jest deklarowana jako zamiennik późniejszych fizycznych composite FK/check constraints ani nieistniejących jeszcze dedykowanych two-writer DBT.

## 7. Course `initial_cost` handoff

Poprzedni Students + Course Enrollment slice świadomie pozostawił `initial_cost` jako zależność Student Finance. Ten handoff został teraz zamknięty.

Przy tworzeniu CourseEnrollment:

- `initial_cost` jest opcjonalnym create-time Student Finance side effect,
- całość wykonuje się atomowo w tej samej operacji biznesowej,
- tworzona jest dokładnie jedna `student_charge`,
- `course_cost_charge_origins` zachowuje durable provenance między kursem i charge,
- retry nie tworzy drugiej należności,
- `CourseEnrollment` nie dostaje równoległego financial balance source-of-truth,
- odczyt `initial_cost` jest projekcją z durable origin,
- wymagane jest `courses.create` oraz `student_finance.create_charge`,
- brak finance permission powoduje rollback całego course create, zamiast stworzenia kursu bez uzgodnionego efektu finansowego.

Późniejsza zmiana ceny nie jest udawana jako zwykły patch pola kursu. Musi przejść przez jawny Student Finance command i ledger history.

## 8. Permissions, API i audit/outbox

Student Finance nie korzysta z role shortcutów. Kluczowe permissions pozostają jawne i rozdzielone:

- `student_finance.view`,
- `student_finance.create_charge`,
- `student_finance.record_payment`,
- `student_finance.cancel_charge`,
- `student_finance.reverse_payment`.

Student scope jest weryfikowany po tenant authority; foreign-tenant target failuje jako niewidoczny zasób.

Runtime API obejmuje:

- `GET /students/{studentId}/charges`,
- `POST /students/{studentId}/charges`,
- `POST /students/{studentId}/charges/{chargeId}/cancel`,
- `GET /students/{studentId}/payments`,
- `POST /students/{studentId}/payments`,
- `POST /students/{studentId}/payments/{paymentId}/reverse`,
- `GET /students/{studentId}/finance-summary`.

Mutacje finansowe emitują wymagany audit/domain-event/outbox intent w lokalnej transakcji zgodnie z foundation audit policy. OpenAPI został zsynchronizowany z rzeczywistymi request/response schemas, `amount_minor`, lifecycle fields i idempotency contract.

## 9. UI

Na szczegółach kursanta działa własny panel `Finanse kursanta`.

Potwierdzony runtime UI obejmuje:

- podsumowanie `Do zapłaty / Wpłacono / Pozostało`,
- listę należności,
- kwotę pierwotną i pozostałą,
- liczbę i sumę aktywnych wpłat,
- status należności,
- powiązanie z kursem,
- `Dodaj należność`,
- `Dodaj wpłatę`,
- historię wpłat,
- cancellation należności z powodem,
- reversal/korektę wpłaty z powodem,
- metody wpłaty i notatkę,
- blokadę mutacji dla archived Student.

Kwoty w UI są parsowane do minor units i wysyłane do backendu jako `PLN` w MVP zamiast używania floatów jako kontraktu finansowego.

Pole kosztu przy tworzeniu kursu jest aktywne i kieruje się do canonical `initial_cost -> Student Finance charge` handoff zamiast utrzymywać koszt jako niezależne saldo CourseEnrollment.

## 10. Executable DBT

Przed Student Finance slice runtime catalog miał:

**71/491 implemented executable assertions**  
**420/491 pending_domain_materialization**

Po truthful hardening dodano dokładnie dwa Stage-4 DBT mające realne executable assertions:

- `DBT-FIN-001` — exact Student/Course/Charge/Payment/Currency runtime relation fail-closed evidence,
- `DBT-FIN-005` — idempotent finance mutation replay bez drugiego efektu.

Finalny runtime catalog wynosi:

**73/491 implemented executable assertions**  
**418/491 pending_domain_materialization**

Świadomie nie oznaczono jako wykonanych DBT dotyczących:

- fizycznych composite FK/candidate-key/check/index constraints z późniejszych migration phases,
- Stage-4 migration preflight przed materializacją właściwej fazy,
- dedykowanych two-writer overpayment/concurrency races bez osobnego executable race testu,
- Platform Commerce, orders i provider payments, które należą do późniejszego slice.

Wszystkie **14** Stage-4 `migration_preflight` contracts nadal pozostają `pending_domain_materialization`.

## 11. Machine evidence

Accepted Implementation CI run `34606896087` na dokładnym clean implementation commit `ed09193e...` zakończył się **5/5 SUCCESS**:

- backend-quality — PASS,
- frontend-quality — PASS,
- runtime-tests-and-migrations — PASS,
- contracts-and-traceability — PASS,
- secret-scan — PASS.

PostgreSQL suite:

**117 tests / 1834 assertions — PASS**

Dodatkowo:

- migration plan / registry validation — PASS,
- Composer strict validation — PASS,
- Pint — PASS,
- PHPStan — PASS,
- frontend lint — PASS,
- Vue/TypeScript typecheck — PASS,
- production build — PASS,
- npm audit high — PASS,
- changed-module traceability — PASS,
- accepted-push Gitleaks scan — PASS.

Accepted API Contract Gate run `34606896081` — PASS.

## 12. Jawnie odroczone zależności

Nie są częścią PASS tego slice:

- późniejsze fizyczne candidate-key/FK/index/check/preflight phases zgodnie z globalnym migration phase barrier,
- dedykowane two-writer concurrency DBT nieposiadające jeszcze osobnego executable evidence,
- wielowalutowe UI ponad aktualny MVP PLN flow,
- faktury i księgowość, których potwierdzony kontrakt nie został wynaleziony na potrzeby tego slice,
- Platform Commerce / orders / `Historia zakupów`,
- provider payment webhook i payment reconciliation,
- Learning Access / Licenses,
- Internal Exams,
- provider-backed PKK operations,
- Dashboard / Notifications.

## 13. Narrative result

Implementation machine = **PASS**.

Clean accepted implementation commit:

`ed09193e3e0b9135d687651dcd2db1c67e47b7db`

Accepted machine validation:

- Implementation CI `34606896087` — 5/5 SUCCESS,
- API Contract Gate `34606896081` — PASS.

Narrative closure candidate commit:

`fc85d8861209a694d646e0dd71d060f0dc41a038`

Narrative validation:

- Implementation CI `34609560215` — **5/5 SUCCESS**,
- accepted-push secret-scan — **PASS**.

Finalny wynik central closure:

`CORE-V1-STUDENT-FINANCE-001 = PASS`

Następny dozwolony slice zgodnie z `AGENTS.md` to `CORE-V1-LICENSES-LEARNING-ACCESS-001`. Nie został rozpoczęty; wymaga kolejnej jawnej instrukcji użytkownika.
