# ADR-0004 — Egzamin wewnętrzny: inventory consume-on-start

Status: Accepted  
Data: 2026-09-05

## Context

Starsze dokumenty raz wiązały zużycie sztuki egzaminu z zakończeniem, a nowsza polityka lifecycle z rozpoczęciem. Potrzebna jest jedna reguła odporna na awarie i double-start.

## Decision

Domyślny lifecycle core v1:

1. create access -> reserve one inventory entry,
2. revoke/expire/cancel przed startem -> release reservation,
3. **start exam -> atomowo consume inventory exactly once + attempt `in_progress`**,
4. finish -> zapis wyniku/snapshotu, bez drugiej konsumpcji,
5. technical abort po starcie nie przywraca automatycznie sztuki,
6. ewentualny zwrot po awarii jest osobnym audytowanym inventory adjustment.

Źródło machine-readable: `specs/design/internal-exam-lifecycle.yml`.

## Consequences

- zamknięcie przeglądarki po starcie nie daje darmowej nowej próby,
- start wymaga lock/idempotency,
- remote i local flow mają ten sam moment konsumpcji,
- historia awarii pozostaje audytowalna.

## Rejected alternatives

- consume-on-finish jako default,
- automatyczny restore po każdym technical abort,
- consume już przy samym wygenerowaniu linku bez możliwości release.
