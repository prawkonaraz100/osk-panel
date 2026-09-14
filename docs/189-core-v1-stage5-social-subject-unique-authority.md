# 189. CORE-V1-STAGE5-SOCIAL-SUBJECT-UNIQUE-AUTHORITY-001 — isolated identity corrective authority

Data: 2026-09-14

**Status:** `IN_VALIDATION`

## Purpose

This gate defines the migration authority needed to close the physical database gap surfaced by `CORE-V1-CLOSURE-AUDIT-006`.

Canonical Stage-4 authority already requires:

`social_provider_subject_unique = UNIQUE(provider, provider_subject)`

for `auth_social_accounts`.

The frozen Stage-4 executable migration tree does not materialize that invariant. Existing Stage-4 migrations and their hashes must not be edited.

This authority creates an isolated Stage-5 corrective extension only. It does not execute DDL in this gate.

## Frozen Stage-4 baseline

The extension is based on the accepted live Stage-4 migration state:

- plan identity: `d2fd6bc999dc2a5ee024e9b91bf00f5a5dad29575554a0ba42eba67107c23f10`
- execution identity: `82da84d3efb312d78b432ad6081491c04b03569a0f250722d6befc11ca233712`
- authority git blob: `ad5f2aa2e14ef248b95dd3dda0d1cbcb2e69d441`
- nodes: **170**
- implemented nodes: **170**
- implemented steps: **261**

The extension may not mutate any of those identities.

## Exact corrective node

One node only:

`S5SOC-IDX-AUTH-SOCIAL-PROVIDER-SUBJECT-UNIQUE`

Target:

- table: `auth_social_accounts`
- columns: `provider, provider_subject`
- uniqueness scope: all rows, including revoked historical links
- predicate: none
- intended physical index name: `auth_social_accounts_provider_subject_unique`

The all-history uniqueness is intentional and matches the canonical critical constraint. A revoked historical provider subject therefore remains reserved and cannot silently become a new identity link.

## Phase path

The node has exactly three phases:

`preflight -> write_fence -> validate`

No expand phase is needed because the table and columns already exist.

No backfill or reconcile phase is allowed because duplicate identity history must not be silently modified.

No contract phase is needed because the write-fence itself is the final physical invariant and validate proves the exact installed object.

## Preflight

Preflight must scan all `auth_social_accounts` rows and reject any duplicate group for:

`(provider, provider_subject)`

including rows where one or more records are revoked.

If duplicates exist, the migration stops for explicit reviewed remediation.

Forbidden automatic remediation:

- hard-delete one duplicate,
- revoke one duplicate merely to make the index pass,
- reassign a duplicate to another User,
- rename/mutate provider or provider_subject,
- pick a winner by created_at, UUID, current/revoked status, e-mail, or any other heuristic.

## Write fence

After successful preflight, write-fence installs one non-partial unique B-tree index:

`auth_social_accounts_provider_subject_unique(provider, provider_subject)`

If an index/object with that name already exists but does not exactly represent the required invariant, execution fails closed.

The old Stage-4 `MIG-IDX-IDENTITY` file remains byte-identical.

## Validate

Validation must prove from PostgreSQL catalog authority that:

- the target table is `auth_social_accounts`,
- the exact index exists,
- it is unique,
- it is valid and ready,
- it has no predicate,
- key order is exactly `provider, provider_subject`.

Executable tests must also prove a second row with the same pair is rejected while different provider or different subject remains allowed.

## Execution boundary

The later implementation gate must create a separate registered extension under:

`database/migrations/stage5/social-identity`

with:

- its own immutable plan identity,
- its own implementation/execution identity,
- shared PostgreSQL migration advisory lock,
- default `php artisan migrate` discovery disabled,
- unregistered extension migrations forbidden,
- automatic destructive down forbidden.

The extension must not modify the existing formal-documents Stage-5 extension.

## Preservation

This authority gate does not:

- execute DDL,
- alter Stage-4 migration files,
- alter Social OAuth HTTP runtime,
- create or relink social accounts,
- change password reset,
- change Commerce,
- activate PKK/PWPW.

## Next gate

`CORE-V1-STAGE5-SOCIAL-SUBJECT-UNIQUE-001`

That gate may materialize only the registered preflight, write-fence and validate steps defined here.
