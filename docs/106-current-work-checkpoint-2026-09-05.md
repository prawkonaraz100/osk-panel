# 106. Current work checkpoint — 2026-09-05

**Branch:** `docs-consolidation-2026-09-05`

**Purpose:** trwały punkt wznowienia pracy. Ten dokument nie otwiera nowego etapu i nie zmienia decyzji domenowych; zapisuje wyłącznie aktualny stan po ostatniej bramce jakości.

## Gdzie dokładnie skończyliśmy

Aktualny etap główny:

`Stage 4 — DB invariants + migration design`

Aktualny slice:

`DB4_2_IDENTITY_TENANT_RBAC`

Zamknięte w tym slice:
- `DB-IAM-001` — **PASS** — effective permissions / role-template semantics,
- `DB-IAM-002` — **PASS** — per-permission data-scope model.

Nie rozpoczęto jeszcze następnej poprawki.

**Dokładny punkt wznowienia:**

`DB-IAM-003 — auth_sessions.user_id <-> organization_membership_id integrity`

Zakres następnego kroku jest ograniczony wyłącznie do fizycznego/transactional zapewnienia, że sesja użytkownika nie może wskazywać membership należącego do innego użytkownika. W tym samym kroku nie wolno poprawiać Owner/privilege escalation ani membership lifecycle.

## Aktualna bramka DB4_2

Znane otwarte blockery P1:

1. `DB-IAM-003` — session ↔ membership same-user integrity,
2. `DB-IAM-004` — last-owner / privilege-escalation invariants,
3. `DB-IAM-005` — membership + permission mutation lifecycle, concurrency i session effects.

**Znana liczba pozostałych blockerów w już zdiagnozowanym DB4_2: 3.**

DB4_2 jako całość pozostaje `FAIL / IN_PROGRESS`, więc `DB4_3` oraz implementacja Laravel/UI są nadal zablokowane.

## Ile jeszcze zostało w całym Stage 4

Po aktualnym `DB4_2` pozostaje dziewięć kolejnych slice'ów, które nie zostały jeszcze zdiagnozowane lub zamknięte:

1. `DB4_3_STAFF_LOCATIONS_VEHICLES`,
2. `DB4_4_STUDENTS_COURSES_TRAINING_LEDGER`,
3. `DB4_5_CALENDAR`,
4. `DB4_6_LICENSES_LEARNING_ACCESS`,
5. `DB4_7_INTERNAL_EXAMS`,
6. `DB4_8_PKK`,
7. `DB4_9_STUDENT_FINANCE_COMMERCE`,
8. `DB4_10_AUDIT_OUTBOX_NOTIFICATIONS`,
9. `DB4_11_FINAL_MIGRATION_ORDER_AND_INVARIANT_TEST_MATRIX`.

Nie wolno dziś podawać dokładnej liczby przyszłych poprawek P0/P1 w tych slice'ach, ponieważ zgodnie z przyjętym procesem blocker powstaje dopiero po diagnozie danego slice'u. Podawanie liczby z góry byłoby zgadywaniem.

Dlatego rozróżniamy:
- **znane poprawki typu blocker w aktualnie zdiagnozowanym zakresie: 3**,
- **niezdiagnozowane jeszcze slice'y Stage 4: 9**,
- **dokładna liczba przyszłych blockerów w tych slice'ach: obecnie nieznana**.

## Co jest po Stage 4

Po pełnym PASS Stage 4 nadal istnieją kolejne bramki projektu, ale nie należy liczyć ich jako obecnych „błędów DB”:

- Stage 5 — acceptance traceability per moduł,
- Stage 6 — implementacja core moduł po module z testami i gate'em,
- Stage 7 — produkcyjne legal/security/provider verification.

Stage 7 obejmuje m.in. finalne PT/tram, szyfrowanie i key rotation PESEL/PKK, retention/RODO, PKK provider contract, payment webhook contract, calendar overlap enforcement oraz backup/restore/RPO/RTO.

## Źródła bieżącego statusu

Przy wznowieniu pracy kolejność źródeł statusowych:
1. `specs/gates/stage-4-database-contract-gate.yml`,
2. ten checkpoint `docs/106-current-work-checkpoint-2026-09-05.md`,
3. `docs/105-stage-4-identity-tenant-rbac-audit.md`,
4. `specs/database/identity-rbac.yml`,
5. `specs/security/permissions.yml`.

`docs/99-staged-design-and-implementation-plan.md` opisuje proces i ogólną kolejność, ale jego sekcja Stage 4 może pozostawać bardziej ogólna/starsza niż machine-readable gate. Aktualny machine-readable gate wygrywa dla bieżącego statusu.

`specs/implementation-baseline-v1.yml` również zawiera kilka historycznych „remaining decisions”, które zostały już zamknięte podczas Stage 3/4 (np. OpenAPI coverage i wybór UUIDv7). Nie używać tej listy do liczenia bieżących blockerów bez porównania z nowszymi gate'ami.

## Następny pojedynczy krok

Tylko:

`DB-IAM-003`

Po jego zmianach:
1. machine-readable spec,
2. narrative sync,
3. self-audit,
4. rerun gate,
5. jeżeli PASS — dopiero wtedy `DB-IAM-004`.

Nie łączyć `DB-IAM-003`, `DB-IAM-004` i `DB-IAM-005` w jeden commit/etap projektowy.
