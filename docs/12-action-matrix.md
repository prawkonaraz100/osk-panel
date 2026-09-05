# 12. Macierz akcji i skutków ubocznych — core v1

Data konsolidacji: 2026-09-05

> Ten dokument opisuje skutki biznesowe naszego produktu. Nowsze `specs/legal`, `specs/design` i `specs/screens` mają pierwszeństwo. Dla egzaminów źródłem prawdy jest `specs/design/internal-exam-lifecycle.yml`.

## Oznaczenia

- `AUTH_SCREEN` — potwierdzone na bieżącym ekranie zalogowanym,
- `LEGAL` — wynika z formalnych reguł własnego produktu,
- `DESIGN` — jawna decyzja własnego produktu,
- `PUBLIC/RULES` — kontekst funkcji potwierdzony publicznie/regulaminowo.

---

# Identity / tenant

| Akcja | Źródło | Warunek | Skutek | Audit |
|---|---|---|---|---:|
| register | PUBLIC | poprawne dane | `User + Organization + Membership` | tak |
| login | PUBLIC | credentials | session | security log |
| logout | PUBLIC | session | revoke session | security log |
| password reset | PUBLIC | e-mail/login | reset token/process | security log |
| change permissions | DESIGN | permission | membership/permission update | **tak** |
| deactivate staff login | DESIGN | permission | membership/session revoke | **tak** |

---

# Kursant

| Akcja | Źródło | Warunek | Skutek | Audit |
|---|---|---|---|---:|
| create student | AUTH_SCREEN | tenant permission | `Student` | tak |
| edit student | AUTH_SCREEN | tenant permission | update kartoteki | tak |
| archive student | AUTH_SCREEN/DESIGN | brak zakazującego procesu | archived state, historia zachowana | **tak** |
| delete student visible action | AUTH_SCREEN | confirmation | u nas nie hard-delete historii formalnej | **tak** |
| create learning account | AUTH_SCREEN/DESIGN | student exists | `StudentLearningAccount` | tak |
| generate access handoff/PDF | AUTH_SCREEN/DESIGN | learning account | `StudentAccessHandoff` | **tak** |
| reset learner password | DESIGN | permission | nowy hash, starego hasła nie odtwarzamy | **tak** |

---

# CourseEnrollment / formalne szkolenie

| Akcja | Źródło | Warunek | Skutek | Audit |
|---|---|---|---|---:|
| create course enrollment | AUTH_SCREEN/LEGAL | student + wymagane dane | `CourseEnrollment` + requirement profile | **tak** |
| edit enrollment | AUTH_SCREEN/DESIGN | permission | update dopuszczalnych danych | **tak** |
| change training stage | AUTH_SCREEN/DESIGN | backend transition rules | stage changed | **tak** |
| delete/cancel course visible action | AUTH_SCREEN/DESIGN | confirmation | u nas `cancel/archive`, nie hard-delete formalnej historii | **tak** |
| recognize external training | AUTH_SCREEN/LEGAL/DESIGN | podstawa/dowód | `RecognizedExternalTraining` | **tak** |
| add training session | DESIGN/LEGAL | enrollment | `TrainingSession` | **tak** |
| credit training time | LEGAL/DESIGN | valid attendance/session | `TrainingHourLedgerEntry` | **tak** |
| correct credited time | DESIGN | elevated permission + reason | immutable correction entry | **tak** |

### Source of truth godzin

Bieżące OSK:
`TrainingSession -> TrainingHourLedgerEntry -> totals`

Inne OSK:
`RecognizedExternalTraining -> totals`

Nie zapisujemy ręcznego agregatu bieżącego OSK jako niezależnego źródła prawdy.

---

# PKK

| Akcja | Źródło | Warunek | Skutek | Audit |
|---|---|---|---|---:|
| fetch PKK | AUTH_SCREEN/PUBLIC | `CourseEnrollment` + integration config | snapshot `PkkProfile` | **tak** |
| view PKK | AUTH_SCREEN | authorized enrollment | read | optional read log |
| update training and return | AUTH_SCREEN/PUBLIC | valid state | external command + local operation record | **tak** |
| return to other OSK | PUBLIC | elevated permission | external status change | **tak** |
| return to authority | PUBLIC | elevated permission | external status change | **tak** |
| return expired profile | PUBLIC | rule permits | external status change | **tak** |
| retry safe failed operation | DESIGN | retryable class | new `PkkOperationAttempt` | **tak** |

Wszystkie mutacje PKK odnoszą się do `course_enrollment_id`, nie wyłącznie do `student_id`.

---

# Kalendarz

| Akcja | Źródło | Warunek | Skutek | Audit |
|---|---|---|---|---:|
| create event | AUTH_SCREEN | permission + valid resources | `CalendarEvent` | tak |
| create driving lesson | AUTH_SCREEN/DESIGN | valid enrollment/resources | event + lesson relation | **tak** |
| reschedule | DESIGN | no hard conflict | timestamps changed | **tak** |
| cancel | DESIGN | allowed state | cancelled | **tak** |
| complete | DESIGN | allowed state | completed | **tak** |
| publish self-book slot | PUBLIC/DESIGN | permission | `AvailabilitySlot` | tak |
| book slot | PUBLIC/DESIGN | available + atomic lock | reservation/event | **tak** |

Backend sprawdza konflikty instruktora, kursanta i pojazdu.

---

# Lokalizacje / pojazdy / pracownicy

| Akcja | Źródło | Skutek | Audit |
|---|---|---|---:|
| create/update location | AUTH_SCREEN | `Location` | tak |
| archive location | DESIGN, UI visible/demo blocked | archived, history preserved | **tak** |
| restore location | DESIGN | active again | **tak** |
| create/update vehicle | AUTH_SCREEN | `Vehicle` | tak |
| archive vehicle | DESIGN, UI visible/demo blocked | unavailable for new assignments, history preserved | **tak** |
| create/update staff | AUTH_SCREEN | `StaffProfile` | tak |
| create staff login | AUTH_SCREEN/DESIGN | User/Membership link | **tak** |
| deactivate staff | DESIGN | account/resources state changed | **tak** |

---

# Student finance

| Akcja | Źródło | Warunek | Skutek | Audit |
|---|---|---|---|---:|
| add charge | AUTH_SCREEN/DESIGN | valid student/course | `StudentCharge` | **tak** |
| record payment | DESIGN | amount valid | `StudentPayment`, balance projection | **tak** |
| reverse payment | DESIGN | elevated permission + reason | reversal record | **tak** |
| cancel charge | DESIGN | allowed | charge cancelled, history preserved | **tak** |

No hard-delete po wystąpieniu aktywności finansowej.

---

# Licencje

| Akcja | Źródło | Warunek | Skutek | Audit |
|---|---|---|---|---:|
| purchase | AUTH_SCREEN/RULES | payment confirmed | inventory grant | **tak** |
| assign | AUTH_SCREEN/RULES | inventory available | inventory reserved/allocated + assignment | **tak** |
| create/access existing learning account | AUTH_SCREEN | valid student | assignment target selected | tak |
| select language | AUTH_SCREEN | capability supports language | assignment/account config | tak |
| revoke unactivated | AUTH_SCREEN/RULES | `activated_at IS NULL` | assignment revoked + **exactly one** inventory restored | **tak** |
| activate | AUTH_SCREEN/DESIGN | valid assignment | one `LicenseActivation`, period starts | **tak** |
| expire | SYSTEM | end date | expired | system audit/event |

### Krytyczna transakcja

`lock assignment + inventory -> verify not activated -> revoke -> restore exactly one -> audit -> commit`

Race `activate vs revoke`: tylko jeden request może zakończyć się sukcesem.

---

# Egzamin wewnętrzny

## Inventory/access lifecycle

Źródło prawdy: `specs/design/internal-exam-lifecycle.yml`.

| Akcja | Źródło | Warunek | Skutek | Audit |
|---|---|---|---|---:|
| purchase/grant exam credit | AUTH_SCREEN/DESIGN | grant/payment | `InternalExamInventoryEntry` | **tak** |
| create exam access | AUTH_SCREEN/DESIGN | formal student + course + available inventory | reserve one inventory entry + create attempt/access draft | **tak** |
| send/share remote link | AUTH_SCREEN | access ready | delivery event | **tak** |
| revoke unused access | DESIGN | not started | reservation released | **tak** |
| expire unused access | SYSTEM | TTL reached, not started | reservation released | system audit |
| **start exam** | AUTH_SCREEN/DESIGN | valid access/reservation + no active conflict | **inventory consumed atomically** + attempt `in_progress` | **tak** |
| submit/finish exam | AUTH_SCREEN | attempt in progress | result + immutable answer snapshot + attempt passed/failed | **tak** |
| technical abort | DESIGN | attempt started | `technical_abort`; inventory remains consumed | **tak** |
| restore credit after technical incident | DESIGN | elevated audited correction | compensating inventory ledger entry | **tak** |
| generate/download answer sheet PDF | AUTH_SCREEN | completed/recorded attempt | immutable document snapshot | **tak** |

### Zasada rozstrzygająca konflikt starszych dokumentów

**Egzamin jest konsumowany przy starcie, nie przy finish.**

`finish` zapisuje wynik i dokumentację, ale nie jest momentem pierwszego zużycia inventory.

---

# Platform orders / payments

| Akcja | Źródło | Warunek | Skutek | Audit |
|---|---|---|---|---:|
| create order | AUTH_SCREEN | valid cart | `Order + OrderItems` | tak |
| start payment | AUTH_SCREEN | order payable | payment attempt | tak |
| confirm webhook | DESIGN | valid signature + idempotency | `Payment confirmed` | **tak** |
| make explicit activation available | DESIGN | paid + product explicit mode | entitlement `activation_available` | **tak** |
| activate entitlement | DESIGN | activation available | single activation/start period | **tak** |

`paid != activated` dla produktów z `activation_mode=explicit`.

---

# Historia zakupów

`/historia-zakupow` prezentuje zakupy OSK u platformy.

Nie jest tym samym co:
- `StudentCharge`,
- `StudentPayment`,
- historyczna trasa Faktury.

---

# Moduły odłożone

Reklamy, wizytówki, wykłady i szkolenie z instruktorem pozostają w starszej dokumentacji domenowej, ale nie są dependency core v1.

Nie usuwamy zasad integralności już ustalonych dla reklam, lecz nie powinny blokować implementacji core.
