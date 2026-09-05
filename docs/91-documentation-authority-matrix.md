# 91. Documentation authority matrix

Data: 2026-09-05

Cel: jednoznacznie wskazać developerowi, który dokument wygrywa przy konflikcie.

| Typ decyzji | Źródło nadrzędne | Przykład |
|---|---|---|
| Prawo/formal requirements | `specs/legal/*.yml` | czy teoria jest wymagana |
| Własny lifecycle | `specs/design/*.yml` | exam consume-on-start |
| RBAC | `specs/security/*.yml` | permission names |
| API conventions | `specs/api/common-contract.yml` | errors, money, idempotency |
| API paths/schemas | `specs/api/openapi-v1.yaml` + `docs/06` | course-first PKK |
| Physical DB blueprint | `specs/database/core-schema.yml` + `docs/87` | table relations |
| Zweryfikowany ekran | `specs/screens/*.yml` | pola formularza |
| Gotowość modułu | `docs/71` + implementation baseline | READY_FOR_IMPLEMENTATION |
| Canonical naming | `docs/82` | CourseEnrollment |
| Cross-module summary | `specs/implementation-baseline-v1.yml` | core invariants |
| Historyczny audyt | starsze docs | evidence/context only |

## Conflict examples

### Stary dokument mówi `/students/{id}/pkk`
Wygrywa current API/domain contract:
`/course-enrollments/{id}/pkk`.

### Stary dokument mówi exam consumed at finish
Wygrywa `specs/design/internal-exam-lifecycle.yml`: consume at start.

### Screen pokazuje ręczne pole godzin
Formalny source of truth nadal wynika z ADR/legal/domain model: current OSK hours są projection z ledgeru.

### Konkurent ma przycisk `Usuń`
Nie oznacza to automatycznie hard-delete w naszym backendzie. Wygrywa core lifecycle policy.

## Zasada

Screen evidence odpowiada na pytanie **co użytkownik widział i mógł zrobić**. Legal/design/domain contracts odpowiadają na pytanie **jak bezpiecznie i poprawnie implementujemy to u siebie**.
