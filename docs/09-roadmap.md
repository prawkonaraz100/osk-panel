# 09. Roadmap wdrożenia — core OSK v1

Data konsolidacji: 2026-09-05

**Status:** `HISTORICAL_IMPLEMENTATION_ROADMAP_SUPERSEDED`

> Ten roadmap zapisuje pierwotną kolejność implementacji. Core V1 został później zaimplementowany i zamknięty; bieżący stan/backlog znajduje się w `docs/227-current-project-status-authority.md` oraz `specs/current-project-status.yml`. Statusy faz poniżej nie są aktualnym planem wykonania.

> Roadmap jest oparta o `specs/implementation-baseline-v1.yml`, canonical domain model i aktualny screen mapping. Marketingowe moduły nie blokują core.

## Faza 0 — konsolidacja kontraktów

Przed szerokim kodowaniem:
- canonical domain glossary,
- course-first PKK,
- jednoznaczny source of truth godzin,
- egzamin consume-on-start,
- core lifecycle archive/cancel/correction,
- permission matrix,
- wspólny API contract,
- acceptance criteria,
- test strategy.

Status historyczny z 2026-09-05: w trakcie w `docs/81-developer-consolidation-plan.md`; obecnie superseded.

## Faza 1 — fundament platformy

- Laravel/Vue bootstrap,
- PostgreSQL,
- Redis,
- object storage,
- auth,
- organization/tenant,
- membership,
- permission-based RBAC,
- audit log,
- outbox,
- request correlation,
- common API errors/pagination/money/datetime,
- CI/test environment.

Definition of Done:
- cross-tenant tests,
- permission tests,
- audit/outbox smoke test,
- baseline observability.

## Faza 2 — zasoby OSK

- Lokalizacje,
- Pracownicy,
- konta pracowników,
- Pojazdy,
- dokumenty/ważności,
- archiwizacja/restore.

Na tym etapie przygotować resource selectors dla kalendarza i kursów.

## Faza 3 — kursanci i formalny kurs

- Student,
- student detail/list/search/filter/sort,
- learning account skeleton,
- CourseEnrollment,
- training stage,
- requirement engine,
- exemption decisions,
- recognized external training,
- create/edit/cancel course.

Nie wdrażać ręcznego agregatu godzin bieżącego OSK jako source of truth.

## Faza 4 — kalendarz i ewidencja szkolenia

- CalendarEvent,
- DrivingLesson,
- zasoby staff/vehicle/location/student,
- conflict detection,
- TrainingSession,
- attendance,
- TrainingHourLedgerEntry,
- formal totals,
- ważne daty jako projections,
- cancel/reschedule lifecycle.

Później:
- self-booking,
- work time,
- notifications.

## Faza 5 — student finance

- StudentCharge,
- StudentPayment,
- saldo,
- częściowe wpłaty,
- reversal/correction,
- powiązanie kosztu kursu z należnością,
- audit.

Student finance pozostaje oddzielone od zakupów OSK na platformie.

## Faza 6 — learning access i licencje

- StudentLearningAccount,
- password set/reset/handoff,
- PDF dostępów,
- license products,
- inventory,
- assignments,
- language capability,
- activation,
- revoke before activation,
- atomowe restore dokładnie jednej sztuki,
- progress projection.

Krytyczne testy:
- activation vs revoke race,
- duplicate assignment,
- tenant isolation.

## Faza 7 — egzamin wewnętrzny

- exam products/grants,
- inventory ledger,
- reservation,
- formal attempt tied to student + course enrollment,
- remote access,
- local station access,
- consume-on-start,
- submit/result,
- immutable question snapshot,
- PDF,
- history/filter/sort,
- technical_abort,
- audited inventory adjustment.

Nie używać starszego modelu `finished -> consumed`.

## Faza 8 — PKK

Najpierw:
- fake/sandbox provider,
- PkkProviderInterface,
- course-first local model,
- operation/attempt logs,
- idempotency,
- retry classification,
- diagnostics/reconciliation.

Dopiero potem:
- rzeczywisty provider po formalnym dostępie,
- podpis XML/upload flow zgodnie z wymaganiami integracji.

## Faza 9 — zakupy OSK / historia zakupów

- Order,
- OrderItem price/VAT snapshot,
- Payment,
- webhook idempotency,
- license/exam grants,
- purchase history,
- payment reconciliation.

Opcjonalna jawna aktywacja entitlementów tylko dla produktów, które jej wymagają.

## Faza 10 — dashboard i notifications

Dashboard jako projection:
- licencje,
- egzaminy,
- activity feed,
- kalendarz.

Notifications:
- dokumenty pracowników/pojazdów,
- wydarzenia,
- PKK failures,
- license/exam actions,
- płatności.

## Faza 11 — formalne dokumenty i hardening

- dokumenty kursanta,
- dokumenty egzaminu,
- wersjonowane snapshoty,
- PDF audit,
- privacy/retention,
- backup/restore,
- observability,
- incident runbooks,
- performance/load tests,
- security review.

## Faza 12 — moduły poza core

Dopiero po stabilnym core:
- Moje wizytówki,
- reklamy/aukcje,
- Wykłady,
- Szkolenie z instruktorem,
- dodatkowe public profile/ranking funkcje.

Nie wiążemy ich architektonicznie tak, aby core nie mógł działać bez nich.

---

# Definition of Done modułu

Każdy moduł przed merge/gotowością posiada:
1. canonical entities,
2. screen/spec requirements,
3. API contract,
4. permission rules,
5. lifecycle/state machine,
6. audit rules,
7. tenant isolation,
8. validation/error codes,
9. idempotency/concurrency tam, gdzie dotyczy,
10. acceptance criteria,
11. integration tests,
12. cross-tenant tests,
13. critical E2E,
14. migration plan,
15. docs/spec update.

# Go-live blockers

Przed produkcją wymagane:
- backup i sprawdzony restore,
- alerting/observability,
- secrets poza repo,
- payment reconciliation,
- PKK failure handling,
- pełne tenant isolation tests,
- formal rule versioning,
- privacy/retention policy,
- incident runbooks.
