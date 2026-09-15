# Architecture Decision Records

Aktualny rejestr ADR core OSK v1:

- `0001-modular-monolith.md` — modular monolith,
- `0002-course-first-formal-model.md` — CourseEnrollment jako owner procesu formalnego,
- `0003-training-hour-ledger.md` — source of truth czasu szkolenia,
- `0004-internal-exam-consume-on-start.md` — moment zużycia inventory egzaminu,
- `0005-permission-based-rbac.md` — permissions zamiast sztywnych ról,
- `0006-archive-correction-over-hard-delete.md` — lifecycle danych formalnych,
- `0007-pending-technical-decisions.md` — rejestr decyzji nadal nierozstrzygniętych,
- `0008-calendar-resource-conflict-boundary.md` — finalna i przejściowa granica konfliktów Calendar/Training.

Statusy:
- `Accepted` — obowiązuje implementację,
- `Proposed` — nie jest jeszcze decyzją,
- `Superseded` — zastąpiony późniejszym ADR.

Nowy ADR nie powinien po cichu zmieniać istniejącego accepted decision. Jeżeli zmiana jest konieczna, tworzymy nowy ADR i oznaczamy poprzedni jako superseded.
