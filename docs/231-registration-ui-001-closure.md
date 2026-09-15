# 231. REGISTRATION-UI-001 closure

Data: 2026-09-15

**Gate:** `REGISTRATION-UI-001`  
**Status:** `PASS_ACCEPTED`

## Accepted scope

PR #123 materialized the public `/register` SPA route and
`resources/js/modules/IdentityTenant/RegistrationWorkspace.vue` on top of the
already accepted `POST /api/v1/auth/register` backend.

The UI:

- discovers `accepted_terms_version` from
  `GET /api/v1/development/sample/legal/terms/current`,
- never hardcodes a Terms version,
- fails closed when no legal-document discovery authority is available,
- accepts the discovered Terms target only after same-origin URL resolution,
- keeps marketing consent false until an exact versioned marketing-document
  authority exists,
- preserves the backend rule that registration creates no implicit authenticated
  application session,
- keeps PKK/PWPW provider runtime frozen and untouched.

Production registration still requires a real approved, published and versioned
Terms authority/resolver. The development sample Terms resolver is not production
authority.

## Candidate validation

Exact candidate head:

`da8b5c35590c77f512a17e21f1e173bce46a6088`

Implementation CI #732 / run `35010038323`: **5/5 PASS**.

Runtime evidence:

- PostgreSQL: **385 tests / 6367 assertions — PASS**,
- deterministic restore: **122 -> 122 PASS**,
- schema fingerprint:
  `f3cab286cc8f0aabef219971d90afe424a8dab694c47ade2f517a3d95970716d`.

The prior candidate run exposed one stale `AuthRecoveryUiContractTest` assertion
that expected `AuthWorkspace v-if`; adding the earlier registration branch in
the pathname shell correctly changed that component to `v-else-if`. Only the
stale contract assertion was corrected; auth runtime semantics did not change.

## Accepted main evidence

PR #123 was promoted to `main` by clean fast-forward. No merge commit or tree
rewrite was introduced.

Accepted runtime SHA:

`da8b5c35590c77f512a17e21f1e173bce46a6088`

Accepted Implementation CI #733 / run `35010888852`: **6/6 PASS**.

- backend-quality: PASS,
- frontend-quality: PASS,
- contracts-and-traceability: PASS,
- secret-scan: PASS,
- runtime-tests-and-migrations: PASS,
- release-artifact: PASS,
- PostgreSQL: **385 tests / 6367 assertions — PASS**,
- deterministic restore: **122 -> 122 PASS**,
- restored schema fingerprint:
  `f3cab286cc8f0aabef219971d90afe424a8dab694c47ade2f517a3d95970716d`,
- immutable artifact ID: `10413962163`,
- artifact name:
  `osk-panel-da8b5c35590c77f512a17e21f1e173bce46a6088`,
- release archive SHA-256:
  `f1b3de0bc8cb48ab1d930b6a7b60b8906205b35a455ad715d3eab5aa1746b6c2`,
- GitHub artifact ZIP SHA-256:
  `19fb29322c40ff8baae9f29515d52e7440c8c0cbc7bf075519224f12a32509fa`.

## Remaining productization sequence

Repository-owned launch work continues in this order:

1. License Purchase UI,
2. Internal Exam Purchase UI,
3. browser E2E golden paths,
4. fixes demonstrated by E2E,
5. final immutable release candidate,
6. retarget issue #106 to the exact final release SHA and artifact hash,
7. collect production target evidence.

Issue #106 must not be certified against this intermediate Registration UI release.

PKK/PWPW remains `FROZEN_UNTIL_EXPLICIT_UNFREEZE`.
