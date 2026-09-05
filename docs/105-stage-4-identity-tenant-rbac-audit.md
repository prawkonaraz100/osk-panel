# 105. Stage 4 — Identity / Tenant / RBAC audit

Data: 2026-09-05

**Status:** `IN_PROGRESS / DB-IAM-001 PASS / 4 P1 BLOCKERS OPEN`

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

## Problem

Poprzedni model miał jednocześnie:
- `organization_memberships.role_template_code`,
- `membership_permissions(permission_code, granted)`,
- role templates w `specs/security/permissions.yml`,

ale nie określał, czy template jest runtime source permissions, czy tylko presetem. To pozwalało na kilka niekompatybilnych implementacji.

## Decyzja canonical

**Role template jest wyłącznie assignment-time presetem + provenance. Nie jest runtime authorization dependency.**

Runtime source of truth:

`active OrganizationMembership -> membership_permissions -> permission decision`

Backend przy request nie sprawdza `role_template_code`.

## Materializacja template

`specs/security/permissions.yml` ma wersjonowany katalog template'ów.

Przy pierwszym zastosowaniu template:
1. resolve exact template catalog version,
2. enumeruj wszystkie znane w tej wersji core permissions,
3. dla każdego permission utwórz/update current `membership_permissions` decision,
4. permission w template -> `granted=true`,
5. permission spoza template -> `granted=false`,
6. zapisz `role_template_code` + `role_template_catalog_version` jako provenance,
7. audit snapshot.

Zmiana/reapply template jest osobnym explicit commandem i atomowo materializuje nowy snapshot dla wszystkich znanych permissions.

## Runtime effective permission

- `granted=true` -> capability może przejść do następnych warstw policy,
- `granted=false` -> deny,
- brak row -> **deny**,
- template code/version -> nie bierze udziału w runtime authorization.

Dzięki temu nowy permission dodany w przyszłości jest dla istniejącego membership domyślnie **DENY**. Zmiana definicji template nie rozszerza po cichu praw istniejących użytkowników.

## Physical contract

Dodano:

`specs/database/identity-rbac.yml`

Jest to bounded-context machine-readable source dla DB4_2. Do finalnego PASS całego DB4_2 zostanie zsynchronizowany z aggregate `core-schema.yml` i `docs/87`.

`OrganizationMembership` przechowuje provenance:
- `role_template_code nullable`,
- `role_template_catalog_version nullable`.

`membership_permissions` pozostaje authoritative current decision table:
- `membership_id`,
- `permission_code`,
- `granted`,
- unique `(membership_id, permission_code)`.

Historię actor/reason/version i concurrency dla permission mutations zamykamy osobno w `DB-IAM-005`; nie mieszamy tego do tej naprawy.

## Test obligations DB-IAM-001

- template materializuje decyzję dla każdego permission znanego w danej wersji katalogu,
- included -> true,
- nonincluded -> false,
- missing row -> deny,
- template definition change nie zmienia istniejącego membership,
- nowy permission -> deny do explicit grant/reapply,
- explicit permission decision wygrywa nad starym template provenance,
- runtime authorization nie czyta `role_template_code`,
- template reapply jest atomowy,
- provenance przechowuje code + exact catalog version.

**Gate DB-IAM-001: PASS.**

---

# DB-IAM-002 — OPEN P1: data-scope physical contract

Canonical scopes:
- `own`,
- `assigned_students`,
- `assigned_locations`,
- `organization`.

Jeden `organization_memberships.data_scope varchar(64)` nadal nie definiuje:
- czy scope jest globalnym defaultem czy może różnić się per permission/domain,
- jak reprezentować konkretne lokalizacje,
- jak `assigned_students` jest wyprowadzane z relacji kurs/instruktor,
- jak `own` działa między domenami,
- czy permission może scope rozszerzyć, czy wyłącznie działa w jego granicach.

**To jest następny i jedyny blocker do naprawy.**

---

# DB-IAM-003 — OPEN P1 SECURITY: auth session ↔ membership integrity

`auth_sessions.user_id` i `organization_membership_id` są obecnie niezależnymi FK. Sama baza nie zabrania wskazania membership należącego do innego Usera.

Potrzebny composite/constrained relation albo równie mocny transactional invariant + test.

Nie naprawiamy jeszcze.

---

# DB-IAM-004 — OPEN P1 SECURITY: last-owner / privilege escalation

Policy wymaga last-owner protection i audytowanych elevated permission changes, ale nadal brak canonical physical/transactional odpowiedzi dla:
- owner marker,
- liczenia active owners,
- self-escalation,
- grant ceiling,
- session effect po odebraniu elevated permission.

Nie naprawiamy jeszcze.

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

# Uwaga o źródłach

Nieistniejący `docs/88-rbac-policy-matrix.md` nie jest używany. Canonical RBAC sources to:
- `specs/security/permissions.yml`,
- `docs/04-roles-permissions.md`,
- `docs/08-security-compliance.md`,
- oraz od DB4_2 `specs/database/identity-rbac.yml`.

---

# Wynik po DB4_2_STEP_2

- `DB-IAM-001` — **PASS**,
- `DB-IAM-002` — **OPEN P1**,
- `DB-IAM-003` — **OPEN P1**,
- `DB-IAM-004` — **OPEN P1**,
- `DB-IAM-005` — **OPEN P1**.

DB4_2 jako całość nadal ma **FAIL**. `DB4_3` pozostaje zablokowany.

**Następny pojedynczy krok: `DB-IAM-002` — tylko canonical data-scope model.**
