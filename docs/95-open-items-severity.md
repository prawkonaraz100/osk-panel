# 95. Open items severity after consolidation

Data: 2026-09-05

## P0 before high-risk modules

- legal category dictionary re-verification before final rule-engine production data,
- privacy/retention schedule before production go-live.

## Resolved after consolidation

- calendar conflict enforcement — rozstrzygnięte przez zamknięty Stage-4 Calendar authority i `ADR-0008`; produkcyjna aktywacja rezerwacji nadal wymaga materializacji GiST exclusion boundary zgodnie z migration phase plan.
- sensitive identifier key-management decision — rozdzielono Laravel encryption key ring od keyed lookup HMAC ring; current lookup secret nie może być `APP_KEY`, previous lookup keys są jawnie wspierane podczas rollover, a formalna historia PKK nie jest ukrycie przepisywana; authority: `docs/131-sensitive-identifier-key-management.md`.

## Deferred pending external authority

- PKK provider/runtime integration is deferred until authoritative PWPW guidance or contract is received and verified. Existing PKK evidence, contracts and provider-neutral Gate 1 schema groundwork are preserved; no provider-specific behavior may be invented in the meantime. This deferment does not block the remaining core v1 slices.

## P1 before production

- RPO/RTO values approved by business,
- OpenAPI validator in CI,
- restore drill,
- incident runbooks,
- provider reconciliation jobs.

## P2 can be completed during normal implementation

- exact UI messages,
- noncritical filter persistence,
- optional exports,
- deferred marketing modules.
