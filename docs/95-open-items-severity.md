# 95. Open items severity after consolidation

Data: 2026-09-05

## P0 before high-risk modules

- legal category dictionary re-verification before final rule-engine production data,
- final encryption/key-management decision before storing real PESEL/PKK credentials,
- calendar conflict enforcement ADR before production booking concurrency,
- privacy/retention schedule before production go-live.

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
