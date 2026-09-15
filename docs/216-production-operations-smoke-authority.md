# 216. Production operations smoke authority

Data: 2026-09-15

**Gate:** `PROD-OPS-SMOKE-001`  
**Status:** `IMPLEMENTATION_CANDIDATE`

## Purpose

Repository authority already defines incident roles/runbooks and a read-only reconciliation scanner, but production go-live still requires two real environment proofs:

1. contact/paging configuration reaches an operator,
2. the reconciliation scheduler actually runs and surfaces failures.

This gate creates the repository-owned smoke substrate without claiming either external proof.

## Command

`operations:production:smoke`

Default invocation validates:

- all required incident contact references are configured,
- provider-neutral reconciliation is clean,
- reconciliation performs zero mutations.

No alert is sent by default.

Alert delivery requires:

- `--send-alert`,
- `INCIDENT_PAGING_SMOKE_ENABLED=true`,
- deployment-only recipient,
- deployment-only mailer,
- exact confirmation token.

The command refuses Laravel `log` and `array` mail transports because they do not prove delivery outside the process.

## Evidence boundary

A successful send proves only that the application handed the smoke message to the configured real mail transport without throwing.

The result intentionally reports:

- `human_ack_proven=false`,
- `scheduler_runtime_proven=false`.

A deployment operator must separately record receipt/acknowledgement and prove the scheduler invokes the reconciliation job on the target environment.

## Privacy and provider boundary

The smoke email contains only:

- smoke ID,
- generation timestamp,
- incident policy version.

It contains no Student/customer data, PKK data, payment data, secrets or provider credentials.

PKK/PWPW remains frozen and outside reconciliation.
