# 83. Core lifecycle policy — archive / cancel / correction / hard delete

Data: 2026-09-05

**Status:** `OWN_PRODUCT_DECISION / ACTIVE_CORE_POLICY`

Cel: ujednolicić zachowanie danych formalnych i finansowych, aby każdy moduł nie implementował własnej przypadkowej semantyki `Usuń`.

## 1. Zasada ogólna

UI może używać słowa `Usuń`, jeżeli jest ono zrozumiałe dla użytkownika, ale backend rozróżnia:

- `archive` — zasób nieaktywny dla nowych operacji, historia zostaje,
- `cancel` — proces/rekord został anulowany, ale pozostaje historycznie,
- `revoke` — cofnięcie prawa/przydziału przed nieodwracalnym użyciem,
- `reverse` — księgowa/finansowa kompensacja wcześniejszego wpisu,
- `correct` — audytowalna korekta danych formalnych,
- `hard_delete` — fizyczne usunięcie, dopuszczalne tylko dla danych czysto technicznych/roboczych bez obowiązku retencji i bez historycznych skutków.

## 2. Domyślne zasady per encja

| Encja | Domyślna operacja | Hard delete? |
|---|---|---:|
| Student | archive | nie po powstaniu formalnego/finansowego powiązania |
| StaffProfile | archive/deactivate | nie, jeśli użyty historycznie |
| Location | archive | nie, jeśli użyta historycznie |
| Vehicle | archive | nie, jeśli użyty historycznie |
| CourseEnrollment | cancel/archive | nie |
| TrainingSession | cancel/correct | nie po zaliczeniu czasu |
| TrainingHourLedgerEntry | correction entry | nie |
| RecognizedExternalTraining | revoke/correct | nie |
| PkkOperation | immutable result/history | nie |
| StudentCharge | cancel | nie po aktywności finansowej |
| StudentPayment | reverse | nie |
| LicenseAssignment | revoke przed aktywacją | nie jako utrata historii |
| LicenseActivation | immutable | nie |
| InternalExamReservation | release przed startem | techniczny cleanup tylko jeśli brak historycznego znaczenia |
| InternalExamAttempt | invalidate/technical_abort | nie |
| InternalExamResult | correction/versioned supersession | nie |
| Order | cancel zgodnie ze stanem | nie po płatności/księgowaniu |
| Payment | reverse/refund/adjust | nie |
| AuditLog | immutable | nie |

## 3. Archiwizacja zasobu

Archiwizacja:
- ustawia stan i timestamp,
- zapisuje actor,
- blokuje nowe przypisania,
- nie usuwa historycznych relacji,
- może mieć restore, jeśli nie narusza późniejszych danych.

`archived_at`, `archived_by_user_id` są preferowane względem samego boolean `is_archived`.

## 4. CourseEnrollment

Widoczna w UI konkurenta akcja `Usuń kurs` w naszym produkcie oznacza domyślnie:
- jeżeli rekord jest pustym draftem bez formalnych/finansowych skutków, możliwy controlled hard-delete przed publikacją do historii,
- jeżeli istnieją PKK operations, training sessions, payments, exam attempts albo inne formalne skutki -> `cancelled/archived`, bez hard-delete,
- reason + actor + timestamp,
- powiązana należność nie znika automatycznie; ma własny lifecycle.

## 5. Student

`Student` może zostać fizycznie usunięty tylko, jeżeli:
- nie posiada course enrollment,
- nie posiada płatności/należności,
- nie posiada licencji/egzaminów/PKK/historycznych działań,
- polityka retencji i RODO na to pozwala.

W przeciwnym razie `archive`.

Żądanie usunięcia danych osobowych z RODO nie oznacza automatycznie usunięcia wymaganej prawem dokumentacji; stosujemy politykę retencji/anonymizacji tam, gdzie legalnie możliwe.

## 6. Staff / Vehicle / Location

Po archiwizacji:
- nie można wybrać zasobu do nowych wydarzeń/kursów,
- historyczne wydarzenia nadal pokazują snapshot/relację,
- przyszłe wydarzenia wymagają jawnej decyzji: przenieś/anuluj/pozostaw jako wyjątek,
- restore jest możliwe, jeżeli zasób nadal spełnia wymagania.

## 7. Student finance

Nigdy nie edytujemy historii wpłaty przez destrukcyjne nadpisanie kwoty po zaksięgowaniu.

Korekta:
`original payment -> reversal -> new corrected payment`

Należność:
`open -> partially_paid -> paid` albo `cancelled`.

## 8. Licencje

Przed aktywacją:
`assigned -> revoked -> inventory restored exactly once`

Po aktywacji:
- zwykły revoke-unactivated niedozwolony,
- ewentualne skrócenie/zwrot/reklamacja to osobny proces własnego produktu.

## 9. Egzaminy

Przed startem:
- access może zostać revoked/expired/cancelled,
- reservation jest released.

Po starcie:
- attempt pozostaje historycznie,
- awaria -> `technical_abort`,
- błędny formalny wpis -> `invalidated` z reason,
- inventory nie wraca automatycznie; tylko audytowana compensating adjustment.

## 10. PKK

Operacji zewnętrznej nie „usuwamy”.

Błąd/korekta to nowa operacja lub attempt, a historia poprzednich requestów pozostaje.

## 11. Audit requirements

Każde archive/cancel/revoke/reverse/correct/invalidate zapisuje:
- actor,
- organization,
- entity,
- poprzedni stan,
- nowy stan,
- reason, jeśli operacja nie jest zwykłą zmianą workflow,
- request_id,
- timestamp.

## 12. API naming

Preferowane command endpoints zamiast mylącego `DELETE`:
- `/archive`,
- `/restore`,
- `/cancel`,
- `/revoke-unactivated`,
- `/reverse`,
- `/technical-abort`,
- `/invalidate`.

HTTP `DELETE` pozostawiamy dla czysto technicznych, bezpiecznie usuwalnych rekordów lub sesji.
