# 105. Stage 4 — Identity / Tenant / RBAC audit

Data: 2026-09-05

**Status:** `IN_PROGRESS / DB-IAM-001 PASS / DB-IAM-002 PASS / 3 P1 BLOCKERS OPEN`

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

`specs/database/identity-rbac.yml` jest bounded-context machine-readable source dla DB4_2. Do finalnego PASS całego DB4_2 zostanie zsynchronizowany z aggregate `core-schema.yml` i `docs/87`.

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

# DB-IAM-002 — PASS: canonical per-permission data scope

## Problem

Poprzedni blueprint miał jeden:

`organization_memberships.data_scope varchar(64)`

To jest za mało precyzyjne. Ten sam pracownik może mieć różny zakres dla różnych capabilities, np. własny kalendarz, przypisanych kursantów i jednocześnie pełny tenantowy dostęp do zakupów.

Jedna wartość membership-wide tworzyłaby ryzyko przypadkowego rozszerzenia danych w całym systemie.

## Decyzja canonical

**Scope jest materializowany per permission. Nie jest globalnym polem membership.**

Canonical runtime chain:

`OrganizationMembership -> membership_permissions -> membership_permission_scopes -> resolver`

`organization_memberships.data_scope` otrzymuje status `SUPERSEDED_BY_PER_PERMISSION_SCOPE_ROWS` i ma zostać usunięte z aggregate physical blueprint przed finalnym DB4_2 PASS/migracjami.

Nie jest dozwolone używanie starego stringa jako fallbacku.

## Fizyczny model

### `data_scopes`

Katalog canonical scope codes:
- `organization`,
- `own`,
- `assigned_students`,
- `assigned_locations`.

### `permission_scope_options`

Whitelist par:

`permission_code + scope_code`

oraz `resolver_code`.

To oznacza, że baza/config nie pozwala materializować dowolnej kombinacji permission/scope. Przykładowo permission typu `own_only` nie dostanie przez pomyłkę `organization`.

### `membership_permission_scopes`

Klucz:

`membership_id + permission_code + scope_code`

FK:
- do konkretnej decyzji `membership_permissions`,
- do dozwolonej pary `permission_scope_options`.

Reguły:
- `granted=true` wymaga co najmniej jednego scope row,
- `granted=false` ma zero aktywnych scope rows,
- brak scope mimo `granted=true` -> **DENY fail-closed**,
- wiele scope rows dla jednego permission -> logiczne **OR**.

Nie rozwiązujemy tutaj historii/concurrency tych mutacji; to pozostaje `DB-IAM-005`.

## Runtime order

1. authenticated user,
2. active membership,
3. **same-tenant target validation**,
4. `membership_permissions.granted=true`,
5. load scope rows,
6. evaluate registered resolver dla każdej scope row,
7. allow scope layer, gdy co najmniej jeden resolver pasuje,
8. domain state,
9. elevated re-auth/MFA jeśli wymagane.

`organization` nigdy nie oznacza globalnego dostępu. Tenant validation jest wcześniejszą i niezależną warstwą.

## Resolver `own`

Permission-specific adapter musi udowodnić, że target należy do:
- bieżącego `User`, albo
- `StaffProfile` aktywnie powiązanego z membership.

Jeśli dana domena nie ma canonical ownership relation, resolver zwraca false. Nie ma fallbacku do `organization`.

## Resolver `assigned_locations`

Zamrożona ścieżka:

`OrganizationMembership -> active StaffMembershipLink -> StaffProfile -> StaffLocationAssignments`

Target musi rozwiązać się przez permission-specific adapter do jednej z tych lokalizacji.

Brak aktywnego staff linku lub brak dopasowania = pusty zbiór / deny.

Physical FK i szczegóły zasobów dopnie `DB4_3`, ale nie może już zmienić znaczenia scope bez ponownego otwarcia gate RBAC.

## Resolver `assigned_students`

Zamrożona ścieżka zaczyna się od:

`OrganizationMembership -> active StaffMembershipLink -> StaffProfile`

Student należy do assigned set, gdy istnieje scope-eligible relacja:
- `CourseEnrollment.lead_instructor_id == StaffProfile.id`, albo
- `TrainingSession.instructor_id == StaffProfile.id` dla kursu tego studenta.

Relacja anulowana/zarchiwizowana/nieaktywna z punktu widzenia scope nie może rozszerzać dostępu. Exact lifecycle-to-scope mapping zostanie fizycznie zapisany w `DB4_4`, ale źródła relacji są już zamrożone.

PKK, exam, license, finance, progress i training resources najpierw rozwiązują canonical Student/CourseEnrollment, potem stosują assigned-student predicate.

Brak active staff link = pusty zbiór.

## Query/command safety

Scope musi być stosowany tak samo dla:
- list,
- GET by id,
- search,
- count,
- update/cancel/archive,
- bulk,
- export,
- PDF.

Filtrowanie danych po pobraniu ich do Vue jest zabronione jako security boundary.

Dla create:
- jeśli istnieje parent/subject, scope sprawdzamy na nim,
- jeśli nie istnieje scoped subject, `own/assigned_*` nie jest zgadywane; wymagany jest legalny `organization` scope.

## Role template scope materialization

Template materializuje jednocześnie permission decisions i scope rows:
- Owner/OfficeAdmin -> `organization`, gdy permission profile to dopuszcza,
- `*.own` -> `own`,
- Instructor/Lecturer student-scoped -> `assigned_students`,
- odpowiednie training/calendar -> `assigned_students + own`.

Explicit grant bez jawnego legalnego scope set jest niedozwolony.

## Test obligations DB-IAM-002

- granted permission bez scope -> deny,
- denied permission ze stale scope -> deny + consistency failure,
- unsupported permission/scope pair -> reject przez composite relation,
- organization scope nie przepuszcza cross-tenant target,
- wiele scope rows działa jako OR,
- own bez ownership relation -> deny,
- assigned_locations bez staff link -> pusty zbiór,
- assigned_students bez staff link -> pusty zbiór,
- assigned student przez lead instructor relation -> match,
- assigned student przez non-cancelled training-session instructor relation -> match,
- relacja anulowana/zarchiwizowana nie rozszerza scope,
- list i GET-by-id mają równoważny scope predicate,
- template materializuje scope tylko dla granted permissions,
- explicit grant wymaga legalnego scope set.

**Gate DB-IAM-002: PASS.**

---

# DB-IAM-003 — OPEN P1 SECURITY: auth session ↔ membership integrity

`auth_sessions.user_id` i `organization_membership_id` są obecnie niezależnymi FK. Sama baza nie zabrania wskazania membership należącego do innego Usera.

Potrzebny composite/constrained relation albo równie mocny transactional invariant + test.

**To jest następny i jedyny blocker do naprawy.**

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

# Quality gate DB4_2_STEP_3

Sprawdzono:
- czy scope jest per-permission zamiast membership-wide — **PASS**,
- czy permission i scope są dwiema osobnymi warstwami — **PASS**,
- czy unsupported scope pair jest blokowane — **PASS**,
- czy brak scope failuje zamknięcie — **PASS**,
- czy wiele scope'ów ma jednoznaczną semantykę OR — **PASS**,
- czy `organization` nadal podlega tenant isolation — **PASS**,
- czy `own`, `assigned_students`, `assigned_locations` mają zamrożone resolvery — **PASS**,
- czy list/get/search/export używają tej samej security policy — **PASS**,
- czy downstream DB4_3/DB4_4 ma jawne obligations zamiast prawa do redefinicji scope — **PASS**,
- czy nie naprawiono przy okazji DB-IAM-003/004/005 — **PASS**.

Nie znaleziono nowego P0/P1 wynikającego z decyzji DB-IAM-002.

Aggregate `core-schema.yml` i `docs/87` nadal zawierają stary `data_scope` i zostaną zsynchronizowane przed **finalnym DB4_2 PASS**, zgodnie z bounded-context authority rule. Nie generujemy jeszcze migracji.

---

# Wynik po DB4_2_STEP_3

- `DB-IAM-001` — **PASS**,
- `DB-IAM-002` — **PASS**,
- `DB-IAM-003` — **OPEN P1**,
- `DB-IAM-004` — **OPEN P1**,
- `DB-IAM-005` — **OPEN P1**.

DB4_2 jako całość nadal ma **FAIL**. `DB4_3` pozostaje zablokowany.

**Następny pojedynczy krok: `DB-IAM-003` — wyłącznie integralność `auth_sessions.user_id <-> organization_membership_id`.**
