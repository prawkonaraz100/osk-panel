# 105. Stage 4 — Identity / Tenant / RBAC audit

Data: 2026-09-05

**Status:** `IN_PROGRESS / DB-IAM-001 PASS / DB-IAM-002 PASS / DB-IAM-003 PASS / 2 P1 BLOCKERS OPEN`

## Cel slice'u

Nie projektujemy jeszcze Staff/Locations/Vehicles, Student/Course, Calendar ani innych bounded contexts. Nie generujemy migracji Laravel. Identity/Tenant/RBAC zamykamy blocker po blockerze.

Zasada:

`canonical RBAC -> physical ownership -> tenant constraint -> effective permission resolution -> lifecycle transaction -> invariant test`

Reverse engineering nie jest upraszczany. Techniczny RBAC własnego produktu może być bezpieczniejszy od konkurenta, ale nie może usuwać potwierdzonych funkcji biznesowych.

## Źródła

- `docs/04-roles-permissions.md`,
- `docs/08-security-compliance.md`,
- `docs/05-domain-model.md`,
- `docs/82-canonical-domain-glossary.md`,
- `specs/security/permissions.yml`,
- `specs/database/identity-rbac.yml`,
- `specs/database/core-schema.yml`,
- `docs/87-physical-database-schema.md`,
- `specs/gates/stage-4-database-contract-gate.yml`.

## Co było poprawne już przed naprawami

- globalny `User` jest oddzielony od tenantowego `OrganizationMembership`,
- jedna osoba może należeć do wielu OSK,
- `StaffProfile` nie jest auth identity,
- `staff_type != role_template != permission`,
- backend authorization order zaczyna się od auth + active membership + tenant validation,
- `AuthLoginIdentifier` rozwiązuje login, ale nie autoryzuje tenant resource,
- permission changes i elevated operations wymagają audytu.

---

# DB-IAM-001 — PASS: effective permissions / role template semantics

Role template jest wyłącznie assignment-time presetem + provenance. Nie jest runtime authorization dependency.

Runtime source of truth:

`active OrganizationMembership -> membership_permissions -> permission decision`

Najważniejsze inwarianty:
- `role_template_code` i `role_template_catalog_version` są metadata/provenance,
- template materializuje jawny current permission snapshot,
- brak permission row -> deny,
- nowy permission dodany później -> deny do explicit grant/reapply,
- zmiana definicji template nie zmienia po cichu istniejących membershipów,
- template reapply/change jest explicit atomic commandem.

**Gate DB-IAM-001: PASS.**

---

# DB-IAM-002 — PASS: canonical per-permission data scope

Scope jest materializowany per permission, a nie jako jeden globalny string membership.

Canonical runtime chain:

`OrganizationMembership -> membership_permissions -> membership_permission_scopes -> resolver`

Katalog scope:
- `organization`,
- `own`,
- `assigned_students`,
- `assigned_locations`.

Fizyczne elementy:
- `data_scopes`,
- `permission_scope_options`,
- `membership_permission_scopes`.

Najważniejsze inwarianty:
- granted permission bez legalnego scope -> deny fail-closed,
- unsupported permission/scope pair -> reject,
- wiele scope rows dla jednego permission -> OR po same-tenant validation,
- `organization` nigdy nie omija tenant isolation,
- list/get/search/count/export/PDF używają tej samej policy,
- frontend post-fetch filtering nie jest security boundary,
- `assigned_locations` i `assigned_students` mają zamrożone resolver contracts dla kolejnych slice'ów.

`organization_memberships.data_scope` jest superseded i zostanie usunięty z aggregate blueprint przed finalnym DB4_2 PASS/migracjami.

**Gate DB-IAM-002: PASS.**

---

# DB-IAM-003 — PASS: auth session ↔ membership same-user integrity

## Problem

Poprzedni physical shape posiadał dwa niezależne FK:

- `auth_sessions.user_id -> users.id`,
- `auth_sessions.organization_membership_id -> organization_memberships.id`.

Taki model nie zabraniał fizycznie stanu, w którym sesja `User A` wskazuje membership należący do `User B`.

To jest niedopuszczalne dla tenant security boundary.

## Decyzja canonical

Wprowadzamy **composite same-user foreign key**.

`organization_memberships` musi posiadać unique candidate key:

`UNIQUE(id, user_id)`

A tenant context w `auth_sessions` jest chroniony przez:

`FOREIGN KEY (organization_membership_id, user_id)`
`REFERENCES organization_memberships(id, user_id)`

z `MATCH SIMPLE`, `ON UPDATE RESTRICT`, `ON DELETE RESTRICT`.

Efekt:
- jeśli `organization_membership_id` jest ustawiony, membership musi należeć dokładnie do tego samego `user_id`, co sesja,
- session User A nie może wskazać membership User B nawet przy błędzie aplikacji,
- membership nie może być „przepisywany” na innego Usera; poprawa takiej sytuacji odbywa się przez właściwy membership/lifecycle, nie przez zmianę `user_id` istniejącego membership.

## Global / pre-tenant session

`organization_membership_id` pozostaje nullable.

To jest celowe dla:
- zalogowanej sesji przed wyborem tenant context,
- globalnych flow konta/security, które nie wymagają OSK context.

`MATCH SIMPLE` powoduje, że przy `organization_membership_id IS NULL` composite FK nie wymaga membership row.

Jednocześnie każda operacja tenant-owned wymaga non-null membership context po stronie backend policy.

## Wybór / zmiana tenant context

Canonical flow:
1. wczytaj sesję bez ujawniania raw session secret,
2. rozwiąż requested membership,
3. potwierdź, że membership należy do `auth_sessions.user_id`,
4. potwierdź, że membership jest dozwolony przez aktualny lifecycle policy,
5. zapisz `organization_membership_id`,
6. composite FK jest ostatnią fizyczną granicą integralności.

Nie przyjmujemy client-supplied `organization_id` jako źródła autoryzacji. Aktywna organizacja wynika z wybranego membership.

## Granica tego rozwiązania

Composite FK rozwiązuje **identity integrity**, ale nie rozwiązuje jeszcze:
- kiedy membership jest `active/suspended/revoked`,
- kiedy revoke/suspend unieważnia istniejące sesje,
- cache/version semantics po zmianie permissions.

To pozostaje świadomie w `DB-IAM-005`.

## Migration gate

Przed dodaniem constraintu migracja musi:
- przeskanować istniejące `auth_sessions` z non-null membership,
- wykryć przypadki `session.user_id != membership.user_id`,
- zatrzymać migrację lub poddać rekord explicit security remediation,
- **nie** naprawiać takiego rekordu automatycznie przez przepisanie go na inną osobę,
- następnie dodać `UNIQUE(id, user_id)` i composite FK.

## Test obligations DB-IAM-003

- session + membership tego samego usera -> accepted,
- session User A + membership User B -> rejected przez DB,
- null membership -> dozwolony dla global/pre-tenant session,
- tenant-owned request z null membership -> denied przez backend policy,
- ten sam User może przełączyć się między swoimi membershipami w dwóch OSK,
- `organization_memberships.user_id` nie może być reassigned,
- migration precheck wykrywa historyczne cross-user session rows.

**Gate DB-IAM-003: PASS.**

---

# DB-IAM-004 — OPEN P1 SECURITY: last-owner / privilege escalation

Policy wymaga last-owner protection i audytowanych elevated permission changes, ale nadal brak canonical physical/transactional odpowiedzi dla:
- owner marker,
- liczenia active owners,
- self-escalation,
- grant ceiling,
- session effect po odebraniu elevated permission.

**To jest następny i jedyny blocker do naprawy.**

---

# DB-IAM-005 — OPEN P1: membership i permission mutation lifecycle

Nadal trzeba zamknąć:
- membership states/transitions,
- suspend/revoke/reactivate vs delete,
- trwały membership row vs historyczne membership periods,
- session invalidation po revoke/suspend,
- permission change concurrency,
- actor/reason/version/audit semantics.

Nie naprawiamy jeszcze.

---

# Quality gate DB4_2_STEP_4

Sprawdzono:
- czy non-null tenant session context może wskazać membership innego usera — **NIE / PASS**, composite FK blokuje,
- czy FK jest wykonalny fizycznie w PostgreSQL — **PASS**, target ma jawny unique `(id,user_id)`,
- czy global/pre-tenant session pozostaje możliwy — **PASS**, nullable membership + MATCH SIMPLE,
- czy tenant-owned request bez membership jest dozwolony — **NIE / PASS**, backend deny,
- czy organizacja jest wyprowadzana z membership zamiast client-supplied organization ID — **PASS**,
- czy migracja posiada precheck dla istniejących niespójnych rekordów — **PASS**,
- czy rozwiązanie nie próbuje przy okazji definiować owner/escalation — **PASS**, nadal DB-IAM-004,
- czy rozwiązanie nie próbuje przy okazji definiować revoke/suspend/session invalidation lifecycle — **PASS**, nadal DB-IAM-005.

Nie znaleziono nowego P0/P1 wynikającego z decyzji DB-IAM-003.

Aggregate `core-schema.yml` i `docs/87` zostaną zsynchronizowane dopiero przed finalnym DB4_2 PASS. Migracje nadal są zablokowane.

---

# Wynik po DB4_2_STEP_4

- `DB-IAM-001` — **PASS**,
- `DB-IAM-002` — **PASS**,
- `DB-IAM-003` — **PASS**,
- `DB-IAM-004` — **OPEN P1**,
- `DB-IAM-005` — **OPEN P1**.

DB4_2 jako całość nadal ma **FAIL**. `DB4_3` pozostaje zablokowany.

**Następny pojedynczy krok: `DB-IAM-004` — wyłącznie last-owner i privilege-escalation invariants.**
