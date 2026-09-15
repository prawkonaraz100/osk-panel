# 90. Pre-implementation checklist — core OSK v1

Data: 2026-09-05

**Status:** `HISTORICAL_PROCESS_CHECKLIST`

> Checklist zachowuje użyteczną dyscyplinę implementacyjną, ale Core V1 jest już zaimplementowany. `READY_FOR_IMPLEMENTATION` poniżej oznacza dawną gotowość mapowania, nie bieżący stan kodu. Aktualny backlog: `docs/227-current-project-status-authority.md`.

Przed rozpoczęciem kodowania konkretnego modułu developer/Codex sprawdza:

## Contract
- [ ] moduł ma status `READY_FOR_IMPLEMENTATION`,
- [ ] istnieje właściwy `specs/screens/*.yml`,
- [ ] canonical entities są w `docs/82-canonical-domain-glossary.md`,
- [ ] API modułu jest w `docs/06-api-contract.md` i OpenAPI,
- [ ] permissions są zdefiniowane,
- [ ] lifecycle/state machine jest zdefiniowany,
- [ ] acceptance criteria istnieją.

## Data
- [ ] tenant ownership jest jednoznaczne,
- [ ] FK/constraints/indexes są zaplanowane,
- [ ] money nie używa float,
- [ ] datetimes używają UTC/timestamptz,
- [ ] dane formalne nie będą hard-delete,
- [ ] PII ma politykę ochrony/maskowania.

## Concurrency
- [ ] wiadomo, czy akcja wymaga locka,
- [ ] wiadomo, czy wymaga `Idempotency-Key`,
- [ ] retry nie duplikuje efektu,
- [ ] istnieje test race, jeśli dotyczy inventory/slot/payment.

## Security
- [ ] Policy/Gate backendowy,
- [ ] cross-tenant deny test,
- [ ] relacyjne ID tenant-validated,
- [ ] elevated action ma audit,
- [ ] plaintext password/token nie trafia do logów.

## Audit/events
- [ ] określono audit event,
- [ ] określono outbox/domain event, jeśli są side effects,
- [ ] request_id/correlation jest propagowany.

## Tests
- [ ] happy-path integration,
- [ ] validation/error test,
- [ ] permission allow/deny,
- [ ] cross-tenant test,
- [ ] idempotency/race test, jeśli dotyczy,
- [ ] E2E krytycznego flow.

## UI
- [ ] loading,
- [ ] empty,
- [ ] error,
- [ ] success feedback,
- [ ] destructive confirmation,
- [ ] backend validation mapping,
- [ ] brak duplikowania backendowych reguł jako jedynego źródła prawdy.

## Docs
- [ ] PR aktualizuje screen/API/spec, jeśli kontrakt się zmienił,
- [ ] nowa decyzja architektoniczna ma ADR,
- [ ] nie oparto implementacji na historycznym `TO_VERIFY_AUTH` wbrew nowszemu spec.
