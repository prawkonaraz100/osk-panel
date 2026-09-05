# 01. Mapa funkcjonalności — core OSK v1

Data konsolidacji: 2026-09-05

> To jest aktualny top-level feature map. Szczegóły implementacyjne: `specs/implementation-baseline-v1.yml`, `specs/screens/*.yml`, `docs/71-admin-osk-module-mapping-status.md`.

## Statusy

- `READY_FOR_IMPLEMENTATION`
- `NOT_SCREEN_MAPPED`
- `LEGAL_VERIFIED`
- `OWN_PRODUCT_DECISION`
- `HISTORICAL_INDEX`

---

# 1. Identity / konto OSK

**Status:** `READY_FOR_IMPLEMENTATION` na poziomie wymagań core.

Funkcje:
- rejestracja/logowanie,
- social login,
- reset hasła przez e-mail/login,
- bezpieczny ReturnUrl,
- session management,
- organization membership,
- permission-based RBAC,
- account/settings,
- audit.

---

# 2. Panel główny

Route: `/`  
**Status:** `READY_FOR_IMPLEMENTATION`

Funkcje:
- widget licencji,
- widget egzaminów,
- activity feed/powiadomienia,
- embedded calendar,
- quick actions.

---

# 3. Kursanci

Route: `/kursanci`  
**Status:** `READY_FOR_IMPLEMENTATION`

Funkcje:
- lista,
- search/filter/sort,
- preview/detail,
- create/edit/archive,
- learning accounts,
- access documents,
- kursy,
- licencje,
- student finance,
- progress,
- internal exams.

Canonical:
`Student != StudentLearningAccount != CourseEnrollment`.

---

# 4. Formalny kurs / CourseEnrollment

**Status:** `READY_FOR_IMPLEMENTATION / LEGAL_VERIFIED` dla obecnie opisanych reguł.

Funkcje:
- create/edit/cancel,
- training type,
- category,
- PKK context,
- start datetime,
- lead instructor,
- location,
- training stage,
- requirement engine,
- theory exemptions,
- recognized external training,
- training sessions,
- hour ledger,
- completion/interruption.

Source of truth czasu:
- bieżący OSK -> sessions + ledger,
- inny OSK -> recognized external training.

---

# 5. PKK

Route informacyjna: `/integracja-pkk`  
Operacyjny owner: `CourseEnrollment`  
**Status:** `READY_FOR_IMPLEMENTATION`

Funkcje:
- fetch,
- preview,
- update-and-return,
- return to other OSK,
- return to authority,
- return expired,
- operation history,
- retry/reconciliation w bezpiecznych klasach błędów.

---

# 6. Kalendarz

Route: `/kalendarz`  
**Status:** `READY_FOR_IMPLEMENTATION`

Funkcje:
- month/week/day,
- event/driving lesson,
- staff/vehicle/location/student resources,
- meeting place,
- conflict detection,
- important date projections,
- future self-booking/worktime.

---

# 7. Lokalizacje

Route: `/lokalizacje`  
**Status:** `READY_FOR_IMPLEMENTATION`

Potwierdzone typy:
- Filia,
- Sala wykładowa,
- Plac manewrowy.

Funkcje:
- list/create/edit,
- address/city search,
- calendar context,
- archive/restore własnego produktu.

---

# 8. Pojazdy

Route: `/pojazdy`  
**Status:** `READY_FOR_IMPLEMENTATION`

Funkcje:
- list/create/detail/edit/archive,
- registration/side number,
- make/model/year/engine/VIN,
- categories/locations,
- technical inspection,
- OC/AC,
- photo,
- calendar resource.

---

# 9. Pracownicy

Route: `/pracownicy`  
**Status:** `READY_FOR_IMPLEMENTATION`

Funkcje:
- StaffProfile,
- staff types,
- create/detail/edit/archive,
- PESEL/contact/authorization,
- categories/locations,
- documents/exams validity,
- optional panel account,
- permission-based RBAC,
- calendar resource.

---

# 10. Student finance

**Status:** `READY_FOR_IMPLEMENTATION / OWN_PRODUCT_DECISION`

Funkcje:
- charge,
- payment,
- partial payment,
- balance,
- reversal,
- cancel charge.

Oddzielone od zakupów OSK na platformie.

---

# 11. Licencje

Routes:
- `/licencje/wykup`,
- `/licencje/panel`.

**Status:** `READY_FOR_IMPLEMENTATION`

Funkcje:
- purchase,
- inventory,
- 31/90/180-day variants,
- assign,
- create/select learning account,
- language capability,
- activation,
- revoke before activation,
- exact-one restore,
- progress,
- access PDF/history.

Lifecycle:
`inventory -> assigned -> activated -> expired`.

---

# 12. Egzamin wewnętrzny

Routes:
- `/egzamin-wewnetrzny/wykup`,
- `/egzamin-wewnetrzny/panel`.

**Status:** `READY_FOR_IMPLEMENTATION / LEGAL_VERIFIED` dla formalnego powiązania z kursem.

Funkcje:
- free/paid inventory,
- search/filter/sort,
- formal attempt tied to student + course,
- remote link,
- local station,
- edit candidate data before start,
- history,
- result,
- question review,
- answer sheet PDF.

Canonical inventory lifecycle:
- reserve at access creation,
- consume at start,
- release before start,
- technical abort after start does not auto-restore.

---

# 13. Ustawienia

Route: `/ustawienia`  
**Status:** `READY_FOR_IMPLEMENTATION`

Funkcje:
- account/company data,
- organization settings,
- PKK integration settings,
- accepted terms.

---

# 14. Historia zakupów OSK

Route: `/historia-zakupow`  
**Status:** `READY_FOR_IMPLEMENTATION`

Funkcje:
- orders/items,
- amount/status,
- created/booked date,
- pay unpaid order,
- pagination.

---

# 15. Formalne reguły szkolenia

**Status:** `LEGAL_VERIFIED` dla obecnych specs.

Funkcje systemowe:
- theory/practical requirements,
- 45/60-minute conversion,
- theory exemptions,
- internal theory/practical requirement,
- rule versioning,
- correction mode.

Źródło: `specs/legal/*.yml`.

---

# 16. Cross-cutting

**Status:** `OWN_PRODUCT_DECISION / ACTIVE`

- tenant isolation,
- RBAC,
- audit,
- outbox,
- idempotency,
- optimistic concurrency,
- common API contract,
- no hard-delete formal/financial history,
- backups/restore,
- observability,
- test strategy.

---

# 17. Moduły poza core v1

| Moduł | Status |
|---|---|
| Moje wizytówki | `NOT_SCREEN_MAPPED` |
| Moje reklamy | `NOT_SCREEN_MAPPED` |
| Wykłady | `NOT_SCREEN_MAPPED` |
| Szkolenie z instruktorem | `NOT_SCREEN_MAPPED` |

Nie blokują implementacji core.

---

# 18. Historyczne/opcjonalne

- Faktury — `HISTORICAL_INDEX`, nie wymagane do core v1,
- impersonation — opcjonalny własny secure extension,
- export progress — opcjonalne,
- refund panel action — własny proces, nie potwierdzona funkcja konkurenta.

---

# 19. Zasada implementacyjna

Nowy kod, migracje i testy nie mogą opierać się wyłącznie na tym high-level map. Przed implementacją modułu wymagane są:
- canonical entities,
- screen specs,
- API contract,
- permissions,
- lifecycle,
- acceptance criteria,
- test plan.
