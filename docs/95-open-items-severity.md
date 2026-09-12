# 95. Open items severity after consolidation

Data: 2026-09-05

## P0 before high-risk modules

- legal category dictionary re-verification before final rule-engine production data,
- final encryption/key-management decision before storing real PESEL/PKK credentials,
- privacy/retention schedule before production go-live.

## Resolved after consolidation

- calendar conflict enforcement — rozstrzygnięte przez zamknięty Stage-4 Calendar authority i `ADR-0008`; produkcyjna aktywacja rezerwacji nadal wymaga materializacji GiST exclusion boundary zgodnie z migration phase plan.

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
