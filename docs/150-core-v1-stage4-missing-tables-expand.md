# 150. CORE-V1-STAGE4-MISSING-TABLES-001 — six missing expand tables

Date: 2026-09-13

Status: `IN_VALIDATION`

## Purpose

The repository closure audit proved that Stage 4 still had six authoritative table nodes that were present in the frozen 170-node DAG but absent from the executable migration registry.

This gate materializes exactly those six `table / expand` nodes:

1. `MIG-TBL-LEGAL_DOCUMENTS` — order 110,
2. `MIG-TBL-AUTH_SOCIAL_ACCOUNTS` — order 160,
3. `MIG-TBL-ACCOUNT_CLOSURE_REQUESTS` — order 220,
4. `MIG-TBL-TERMS_ACCEPTANCES` — order 230,
5. `MIG-TBL-EVENT_PROJECTION_MIGRATION_CASES` — order 1170,
6. `MIG-TBL-DATA_RETENTION_EXECUTION_RUNS` — order 1180.

The Stage-4 DAG, plan identity and authority blob are unchanged.

## Migration authority

Frozen Stage-4 authority:

- canonical DAG nodes: **170**,
- authority blob: `ad5f2aa2e14ef248b95dd3dda0d1cbcb2e69d441`,
- plan identity: `d2fd6bc999dc2a5ee024e9b91bf00f5a5dad29575554a0ba42eba67107c23f10`.

Before this gate:

- materialized nodes: **112**,
- materialized steps: **112**,
- execution identity: `1d2f1d3a47a4a09b749d0b164137857a241c07afbaad02789ec21e03cb4118ce`.

Candidate after this gate:

- materialized nodes: **118**,
- materialized steps: **118**,
- execution identity: `bba8fb733d634e180b2133057dd73dcabae116d344496027b2f1ecaf0f48de6c`.

Changing the execution identity is expected because six executable migration steps were added. The plan identity does not change.

## Expand-only boundary

This gate intentionally creates only:

- table columns,
- the table primary key required to identify rows.

It does **not** pre-materialize later authoritative integrity nodes:

- candidate keys,
- secondary indexes,
- foreign keys,
- check / exclusion constraints,
- append-only or validation triggers,
- projections.

The executable PostgreSQL test requires zero non-PK FK/unique/check/exclusion constraints and zero user triggers on all six newly materialized tables.

## Table contracts

### legal_documents

Immutable legal-document version storage:

- id,
- document_type,
- version,
- content_hash,
- optional storage_asset_id,
- published_at,
- optional effective_from,
- created_at.

The unique `(document_type, version)` rule and FileAsset relation are later integrity work, not part of this table node.

### auth_social_accounts

Provider identity binding base columns:

- id,
- user_id,
- provider,
- provider_subject,
- created_at,
- optional revoked_at.

Current-provider-subject uniqueness and User FK remain later integrity nodes.

### account_closure_requests

Auditable account-closure workflow base columns:

- id,
- user_id,
- optional organization_id,
- requested_at,
- optional reason,
- status,
- optional resolution metadata,
- request_id.

Status checks, relations and one-pending-request partial uniqueness are not materialized in this expand gate.

### terms_acceptances

Append-only acceptance record base columns:

- id,
- organization_id,
- user_id,
- legal_document_id,
- accepted_at,
- optional IP hash,
- optional user agent,
- request_id.

Tenant/User/LegalDocument FKs, uniqueness and append-only enforcement are later integrity nodes.

### event_projection_migration_cases

DB-EVT-001 migration-review evidence base columns:

- source table and row reference,
- safe row fingerprint,
- issue code,
- evidence class,
- resolution state,
- JSON-safe evidence reference,
- optional review/resolution metadata,
- created_at.

Source allowlist, state constraints, unique case key and append-only enforcement are deliberately deferred to their authoritative later nodes.

### data_retention_execution_runs

Privileged retention execution evidence base columns:

- policy version reference,
- data class,
- cutoff,
- optional organization scope,
- optional initiating user,
- reason,
- candidate/deleted-or-redacted/skipped-hold counts,
- start/completion timestamps,
- result.

Result checks, relational integrity and privileged append-only/update boundaries remain later integrity work.

## Repository closure effect

This gate reduces Stage-4 migration gaps:

- missing nodes: **58 → 52**,
- missing tables: **6 → 0**,
- missing candidate keys: **8** unchanged,
- missing indexes: **10** unchanged,
- missing foreign keys: **11** unchanged,
- missing constraints: **10** unchanged,
- missing triggers: **9** unchanged,
- missing projections: **4** unchanged.

Core v1 remains `REPO_P1_REMAINS`; this gate closes only the six missing table nodes.

## Next canonical migration tranche after PASS

The next Stage-4 nodes in topological order are the eight `MIG-CK-*` candidate-key nodes.

Their authoritative phase path is:

`preflight → write_fence`

and their restart classification is `manual_review`.

They must be materialized in a separate gate. No candidate-key DDL belongs in this gate.

## Preservation

This gate:

- does not modify the frozen Stage-4 DAG,
- does not modify Stage-5 formal-document migration authority,
- does not change the API inventory,
- does not implement Identity/Auth HTTP runtime,
- does not implement Organization Settings UI/runtime,
- does not touch provider-specific payment truth,
- does not unfreeze PKK/PWPW,
- does not reopen earlier local PASS records.

PKK provider runtime remains `FROZEN_UNTIL_EXPLICIT_UNFREEZE`.
