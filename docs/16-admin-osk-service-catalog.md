# 16. Admin OSK — katalog usług core v1

Data konsolidacji: 2026-09-05

> **Current implementation note (2026-09-15):** ten dokument zachowuje mapping/evidence i docelowe capability. Statusy `READY_FOR_IMPLEMENTATION` oznaczają historyczną gotowość mapowania, **nie bieżący stan kodu**. Aktualny implementation/backlog/freeze authority: `docs/227-current-project-status-authority.md` i `specs/current-project-status.yml`.

> Ten dokument opisuje usługi administratora OSK po pełniejszym audycie ekranowym. Szczegóły screen-level znajdują się w `specs/screens/*.yml`, a aktywny baseline w `specs/implementation-baseline-v1.yml`.

## Definicja gotowości

Moduł jest `READY_FOR_IMPLEMENTATION`, gdy znamy:
- główne ekrany,
- pola i akcje,
- kluczowe stany/lifecycle,
- relacje domenowe,
- wymagania bezpieczeństwa,
- a nieobserwowalne detale są jawnie rozstrzygnięte jako własny design.

---

# A. Core OSK

## A1. Panel główny

Route: `/`  
Status: `READY_FOR_IMPLEMENTATION`

Usługi:
- szybki podgląd licencji,
- szybki podgląd puli egzaminów,
- activity feed/powiadomienia,
- kalendarz,
- skróty do głównych procesów.

Dashboard jest projection/read model, nie source of truth.

## A2. Integracja PKK

Route informacyjna: `/integracja-pkk`  
Operacyjny owner: `CourseEnrollment`  
Status: `READY_FOR_IMPLEMENTATION`

Usługi:
- fetch PKK,
- preview,
- update training + return,
- return to other OSK,
- return to authority,
- return expired,
- operation history.

Wymagania:
- provider adapter,
- operation/attempt model,
- idempotency,
- retry classification,
- audit,
- reconciliation.

## A3. Kursanci

Route: `/kursanci`  
Status: `READY_FOR_IMPLEMENTATION`

Usługi:
- lista/search/filter/sort,
- create/edit/archive,
- profil,
- learning access,
- kursy,
- licencje,
- płatności kursanta,
- postępy,
- egzamin wewnętrzny,
- dokument dostępu.

Canonical separation:
`Student != StudentLearningAccount != CourseEnrollment`.

## A4. Kurs formalny / CourseEnrollment

Status: `READY_FOR_IMPLEMENTATION`

Usługi:
- create/edit/cancel,
- rodzaj szkolenia,
- kategoria,
- PKK,
- data rozpoczęcia,
- instruktor,
- lokalizacja,
- training stage,
- requirement engine,
- recognized external training,
- powiązanie z finance/PKK/exams.

Source of truth czasu:
- bieżący OSK -> sessions + ledger,
- inne OSK -> recognized external training.

## A5. Kalendarz

Route: `/kalendarz`  
Status: `READY_FOR_IMPLEMENTATION`

Usługi:
- month/week/day,
- event/driving lesson,
- zasoby staff/vehicle/location/student,
- conflict detection,
- custom meeting place,
- future self-booking/worktime extensions.

## A6. Lokalizacje

Route: `/lokalizacje`  
Status: `READY_FOR_IMPLEMENTATION`

Usługi:
- list/create/edit,
- Filia,
- Sala wykładowa,
- Plac manewrowy,
- adres + wyszukiwana miejscowość,
- kontekst kalendarza,
- archive/restore jako własny bezpieczny lifecycle.

## A7. Pojazdy

Route: `/pojazdy`  
Status: `READY_FOR_IMPLEMENTATION`

Usługi:
- CRUD operacyjny,
- archiwizacja,
- zdjęcie,
- rejestracja/nr boczny,
- marka/model/rok/VIN/pojemność,
- kategorie/lokalizacje,
- przegląd/OC/AC,
- kalendarz.

## A8. Pracownicy

Route: `/pracownicy`  
Status: `READY_FOR_IMPLEMENTATION`

Usługi:
- StaffProfile,
- create/edit/archive,
- staff types,
- kategorie/lokalizacje,
- dokumenty i badania,
- zdjęcie,
- opcjonalne konto panelowe,
- permission-based RBAC,
- kalendarz.

## A9. Ustawienia

Route: `/ustawienia`  
Status: `READY_FOR_IMPLEMENTATION`

Usługi:
- dane OSK,
- dane firmy,
- login/konto,
- integration settings PKK,
- nr ewidencyjny,
- accepted terms.

## A10. Historia zakupów

Route: `/historia-zakupow`  
Status: `READY_FOR_IMPLEMENTATION`

Usługi:
- lista orders,
- items,
- kwota/status,
- data/księgowanie,
- payment CTA,
- paginacja.

To nie jest student finance.

---

# B. Edukacja i sprzedaż dla kursanta

## B1. Licencje — zakup

Route: `/licencje/wykup`  
Status: `READY_FOR_IMPLEMENTATION`

Usługi:
- 31/90/180 dni,
- quantity,
- mixed cart,
- ceny/rabaty jako config,
- PayU/przelew,
- grant inventory po potwierdzeniu płatności.

## B2. Licencje — zarządzanie

Route: `/licencje/panel`  
Status: `READY_FOR_IMPLEMENTATION`

Usługi:
- inventory,
- assign,
- existing/new learning account,
- language,
- activation,
- revoke before activation,
- exact one inventory restore,
- history,
- PDF handoffs,
- progress.

State:
`inventory -> assigned -> activated -> expired`

## B3. Egzamin wewnętrzny — zakup

Route: `/egzamin-wewnetrzny/wykup`  
Status: `READY_FOR_IMPLEMENTATION`

Usługi:
- paid/free inventory,
- purchase,
- payment.

## B4. Egzamin wewnętrzny — zarządzanie

Route: `/egzamin-wewnetrzny/panel`  
Status: `READY_FOR_IMPLEMENTATION`

Usługi:
- generate for student/course,
- remote link,
- local station,
- edit candidate data,
- filters/sort/search,
- history,
- result,
- question review,
- PDF.

Canonical inventory lifecycle:
- reserve at access creation,
- consume at start,
- release before start,
- technical abort after start keeps consumption unless audited adjustment.

## B5. Postęp

Status: `READY_FOR_IMPLEMENTATION` jako część student detail/licensing.

Usługi:
- account/category context,
- tests pass/fail,
- answered/correct/incorrect questions,
- taxonomy topic progress,
- handbook progress,
- lecture progress.

Exact formulas pozostają projektowalne/versioned.

---

# C. Student finance

Status: `READY_FOR_IMPLEMENTATION` jako własny bounded context.

Usługi:
- charge,
- partial/full payment,
- balance,
- reversal,
- cancel charge,
- audit.

Model: `specs/design/student-finance-ledger.yml`.

---

# D. Formalne reguły

Status: `LEGAL_VERIFIED` dla obecnie udokumentowanych rule sets.

Usługi systemowe:
- requirement calculation,
- theory/practical minimums,
- theory exemptions,
- internal exam requirements,
- rule version snapshot,
- correction mode.

Źródła:
- `specs/legal/training-theory-exemptions.yml`,
- `specs/legal/editable-training-requirements.yml`.

---

# E. Moduły poza core v1

## E1. Moje wizytówki
Route: `/wizytowki`  
Status: `NOT_SCREEN_MAPPED`

## E2. Moje reklamy
Status: `NOT_SCREEN_MAPPED`

Domena aukcyjna znana z publicznych źródeł, ale nie jest dependency core.

## E3. Wykłady
Route: `/wyklady`  
Status: `NOT_SCREEN_MAPPED`

## E4. Szkolenie z instruktorem
Route: `/szkolenie-z-instruktorem`  
Status: `NOT_SCREEN_MAPPED`

---

# F. Funkcje historyczne/opcjonalne

- Faktury — `HISTORICAL_INDEX`, nie wymóg parytetu core v1,
- impersonation — historyczne, własna implementacja opcjonalna,
- export progress — własna funkcja opcjonalna,
- refund — własny proces finansowy, nie potwierdzony przycisk konkurenta.

---

# G. Cross-cutting requirements

Każdy core service ma:
- tenant isolation,
- permission check,
- audit dla krytycznych mutacji,
- idempotency tam, gdzie retry jest możliwe,
- common error contract,
- no hard-delete formalnej/finansowej historii,
- tests zgodnie z `docs/84-test-strategy.md`,
- production requirements zgodnie z `docs/85-production-operations.md`.
