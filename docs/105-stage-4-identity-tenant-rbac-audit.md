# 105. Stage 4 — Identity / Tenant / RBAC audit

Data: 2026-09-05

**Status:** `IN_PROGRESS / DB-IAM-001..004 PASS / 1 P1 BLOCKER OPEN`

## Cel slice'u

Identity/Tenant/RBAC zamykamy blocker po blockerze. Nie projektujemy jeszcze Staff/Locations/Vehicles, Student/Course, Calendar ani innych bounded contexts i nie generujemy migracji Laravel.

Zasada:

`canonical RBAC -> physical ownership -> tenant constraint -> effective permission resolution -> lifecycle transaction -> invariant test`

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

---

# DB-IAM-001 — PASS

Role template jest tylko assignment-time presetem + provenance. Runtime source of truth to `membership_permissions`; brak row i nowe future permissions domyślnie dają DENY.

---

# DB-IAM-002 — PASS

Data scope jest per permission przez:

`membership_permissions -> membership_permission_scopes -> resolver`

Scope codes: `organization`, `own`, `assigned_students`, `assigned_locations`. Brak scope dla granted permission = fail-closed DENY. Stary membership-wide `data_scope` jest superseded.

---

# DB-IAM-003 — PASS

Non-null tenant session context jest chroniony composite FK:

`auth_sessions(organization_membership_id, user_id)`

->

`organization_memberships(id, user_id)`.

Sesja jednego Usera nie może wskazać membership innego Usera. Null membership pozostaje dozwolony wyłącznie dla global/pre-tenant flow; tenant-owned request bez membership jest odrzucany.

---

# DB-IAM-004 — PASS: last-owner / privilege escalation

## Problem

Wcześniejsza dokumentacja mówiła, że ostatni Owner musi być chroniony i że permission changes są elevated, ale nie było jednoznacznego modelu:
- czym fizycznie jest Owner,
- jak liczyć ostatniego Ownera,
- jak blokować self-escalation,
- jaki jest grant ceiling,
- co dzieje się z aktywną sesją po odebraniu elevated permission.

## Decyzja canonical

### Owner marker

`organization_memberships.is_owner boolean not null default false`.

To jest governance marker, nie runtime authorization shortcut. Backend nadal autoryzuje przez `membership_permissions` + scope.

### Protected owner baseline

Membership z `is_owner=true` musi mieć jawnie materializowane:
- `organization.view` / `organization`,
- `organization.members.manage` / `organization`,
- `staff.permissions.manage` / `organization`,
- `sessions.manage.organization` / `organization`.

Nie można odebrać tych praw pozostawiając membership jako Ownera. Demotion i zmiana protected baseline należą do jednej transakcji.

### Last-owner invariant

Zmiana Ownera jest serializowana per organizacja przez lock organizacji lub równoważny governance lock.

Każda transakcja, która degraduje, zawiesza lub usuwa owner-capable membership, liczy **post-transaction active owners** i wymaga wyniku `>= 1`.

Transfer Ownera jest atomowy: successor promotion + predecessor demotion w jednej transakcji. Nie może być widoczny commit z zerem Ownerów.

Exact membership lifecycle status names pozostają do zamknięcia w `DB-IAM-005`, ale nie mogą osłabić tej reguły.

### Self-escalation

Zwykły admin flow nie może:
- nadać sobie nowego permission,
- poszerzyć własnego scope,
- samemu ustawić `is_owner=true`,
- zastosować sobie template'u, który rozszerza privileges.

Self-restriction jest możliwe tylko jeśli nie łamie last-owner i protected owner baseline.

### Grant ceiling

Administrator może delegować tylko capability, które sam aktualnie posiada w tej samej organizacji.

Scope nie może być szerszy niż scope aktora dla tego samego permission:
- aktor z `organization` może delegować legalny węższy scope,
- aktor z non-organization scope może delegować tylko posiadane scope codes,
- scope nieznany/nieporównywalny -> DENY.

Grant lub broadening elevated permission wymaga aktywnego Ownera jako aktora oraz permission management capabilities.

### Authorization version / session effect

`organization_memberships.authorization_version bigint >= 1` jest security epoch.

Wzrasta przy:
- permission grant/revoke,
- scope change,
- owner promotion/demotion,
- membership state change wpływającym na authorization eligibility.

Sesja nie może przechowywać trwałego permission snapshotu jako źródła praw. Jeżeli istnieje cache, musi być związany z bieżącym `authorization_version`.

Odebranie elevated permission jest skuteczne najpóźniej przy następnym autoryzowanym request. Sama sesja może pozostać zalogowana; stale permission/elevation proof nie może być użyty po zmianie version.

Pełny suspend/revoke/session invalidation lifecycle pozostaje `DB-IAM-005`.

## Test obligations DB-IAM-004

- role template `Owner` sam nie autoryzuje requestu,
- Owner bez protected baseline jest consistency failure,
- self-promotion do Ownera jest blokowane,
- self-grant i self-scope-broadening są blokowane,
- non-owner nie może grantować elevated permission,
- actor nie może grantować permission, którego sam nie ma,
- actor nie może delegować scope szerszego niż własny,
- promotion Ownera materializuje baseline atomowo,
- transaction zostawiający zero active owners jest odrzucany,
- dwa równoległe demotiony Ownerów nie mogą oba przejść,
- transfer Ownera jest atomowy,
- protected permission nie może zostać revoked bez owner demotion w tej samej transakcji,
- elevated revoke zwiększa `authorization_version`,
- stale authorization cache/proof nie autoryzuje po zmianie version.

**Gate DB-IAM-004: PASS.**

---

# DB-IAM-005 — OPEN P1: membership + permission mutation lifecycle

Pozostaje zamknięcie jednego ostatniego blockera DB4_2:
- dokładne membership states/transitions,
- suspend/revoke/reactivate vs hard delete,
- durable membership row vs history periods,
- session invalidation po suspend/revoke,
- permission mutation concurrency/lost update,
- actor/reason/version/audit semantics,
- finalna relacja pomiędzy lifecycle `version` i `authorization_version`.

**To jest następny i jedyny blocker do naprawy.**

---

# Quality gate DB4_2_STEP_5

Sprawdzono:
- owner marker nie jest runtime permission shortcut — **PASS**,
- owner ma protected materialized permission baseline — **PASS**,
- last-owner count jest serializowany i oceniany po planowanej transakcji — **PASS**,
- normal self-escalation jest zakazana — **PASS**,
- grant ceiling nie pozwala delegować prawa/scope ponad aktora — **PASS**,
- elevated grant wymaga Ownera — **PASS**,
- revoke elevated permission nie pozostawia stale session authorization — **PASS** przez `authorization_version`,
- nie zamknięto przy okazji pełnego membership/session lifecycle — **PASS**, nadal DB-IAM-005,
- nie wykonano aggregate sync/migracji — **PASS**.

Nie znaleziono nowego P0/P1 wynikającego z decyzji DB-IAM-004.

---

# Wynik po DB4_2_STEP_5

- `DB-IAM-001` — **PASS**,
- `DB-IAM-002` — **PASS**,
- `DB-IAM-003` — **PASS**,
- `DB-IAM-004` — **PASS**,
- `DB-IAM-005` — **OPEN P1**.

DB4_2 jako całość nadal ma **FAIL**, więc `DB4_3` pozostaje zablokowany.

**Następny pojedynczy krok: tylko `DB-IAM-005`.**
