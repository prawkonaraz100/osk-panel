# 105. Stage 4 — Identity / Tenant / RBAC audit

Data: 2026-09-05

**Status:** `DIAGNOSIS_COMPLETE / GATE_FAIL_WITH_BLOCKERS`

## Cel tego kroku

Ten dokument jest wyłącznie diagnozą slice'u `DB4_2_IDENTITY_TENANT_RBAC`.

Nie projektujemy jeszcze Staff/Locations/Vehicles, Student/Course, Calendar ani innych bounded contexts. Nie generujemy migracji Laravel. Najpierw ustalamy, czy obecny physical model Identity/Tenant/RBAC potrafi bezpiecznie zrealizować canonical model oraz wszystkie wymagane permission/data-scope flows.

Zasada:

`canonical RBAC -> physical ownership -> tenant constraint -> effective permission resolution -> lifecycle transaction -> invariant test`

Reverse engineering nie jest w tym kroku upraszczany. Techniczny model uprawnień własnego produktu może być bezpieczniejszy od konkurenta, ale nie może usuwać żadnej potwierdzonej funkcji biznesowej.

## Źródła

Sprawdzone:
- `docs/04-roles-permissions.md`,
- `docs/08-security-compliance.md`,
- `docs/05-domain-model.md`,
- `docs/82-canonical-domain-glossary.md`,
- `specs/security/permissions.yml`,
- `specs/database/core-schema.yml`,
- `docs/87-physical-database-schema.md`,
- `specs/gates/stage-4-database-contract-gate.yml`.

## Co jest już poprawne

### Globalny User + tenant membership

Canonical rozdzielenie jest prawidłowe:

`User -> OrganizationMembership -> Organization`

Jedna osoba może być członkiem więcej niż jednego OSK. `StaffProfile` nie jest auth identity i może istnieć bez dostępu do panelu.

### Permission-based RBAC

Źródło prawdy jest permission-based. `staff_type`, `role_template` i `permission` są osobnymi pojęciami.

Backend authorization order jest już poprawnie określony:
1. authenticated user,
2. active membership,
3. tenant relation validation,
4. permission,
5. data scope,
6. domain state,
7. optional re-auth/MFA.

### Global login identity

`AuthLoginIdentifier` jest globalnym resolverem logowania, ale sam login nie autoryzuje tenant resource. Po rozpoznaniu `User` nadal obowiązuje membership i policy.

### Audit requirement

Zmiany permissions i operacje elevated mają podlegać audytowi. Nie ma zgody na autoryzację wyłącznie po stronie Vue.

---

# Blockery DB4_2

## DB-IAM-001 — P1: effective permission resolution i role-template semantics są fizycznie niejednoznaczne

### Stan obecny

`organization_memberships` posiada:
- `role_template_code varchar(64) null`,
- `data_scope varchar(64) null`.

`membership_permissions` posiada:
- `membership_id`,
- `permission_code`,
- `granted boolean`.

Jednocześnie canonical RBAC mówi:
- role template = wygodny pakiet startowy permissions,
- Owner może modyfikować permissions,
- template nie jest tym samym co permission,
- `specs/security/permissions.yml` definiuje startowe templates i ich bundle.

### Problem

Physical model nie odpowiada jednoznacznie na pytanie, jak wyliczyć **effective permissions**:
- czy `membership_permissions` jest pełnym snapshotem permissions nadanym przy wyborze template,
- czy jest tylko allow/deny override na żywym role template,
- czy późniejsza zmiana definicji template zmienia istniejących użytkowników,
- co oznacza `granted=false` względem permission pochodzącej z template,
- czy `role_template_code` jest tylko metadanym/audytowym źródłem inicjalizacji czy runtime dependency.

Nie istnieje też physical/canonical kontrakt `role_templates -> role_template_permissions`, jeśli template miałby być runtime source.

### Ryzyko

Dwóch agentów może zaimplementować dwa różne modele autoryzacji, oba zgodne z nazwami kolumn, ale dające inne uprawnienia po zmianie template lub explicit revoke.

### Wymagane rozstrzygnięcie

W następnym pojedynczym kroku należy wybrać **jedną** semantykę effective permission resolution i zapisać ją w machine-readable schema + narrative + tests.

Nie rozwiązujemy tego w tym audycie.

---

## DB-IAM-002 — P1: `data_scope varchar(64)` nie ma wystarczająco precyzyjnego physical contract

Canonical scopes:
- `own`,
- `assigned_students`,
- `assigned_locations`,
- `organization`.

Dokumentacja mówi, że scope jest dodatkowym ograniczeniem dla części permissions, a nie zamiennikiem permission.

### Problem

Jeden `organization_memberships.data_scope varchar(64)` nie definiuje:
- czy scope jest globalnym defaultem membership czy może różnić się per permission/domain,
- jak reprezentowane są konkretne lokalizacje, jeśli użytkownik ma dostęp tylko do części lokalizacji,
- jak `assigned_students` jest rozwiązywane z aktualnych relacji kurs/instruktor,
- jak `own` działa dla kalendarza wobec innych domen,
- czy explicit permission może rozszerzać scope, czy tylko capability w ramach scope.

### Ryzyko

Permission może zostać poprawnie przyznana, ale query scope może niechcący rozszerzyć widoczność na cały tenant albo być implementowany inaczej w każdym module.

### Wymagane rozstrzygnięcie

Potrzebny jest canonical model scope resolution z jawnie określonym defaultem, ewentualnymi resource-specific restrictions i backend query policy. Physical relacje do Staff/Location/Student zostaną dopięte dopiero w odpowiednich kolejnych slice'ach.

Nie rozwiązujemy tego w tym audycie.

---

## DB-IAM-003 — P1 SECURITY: `auth_sessions` nie gwarantuje zgodności `user_id` z `organization_membership_id`

Current physical shape ma:
- `auth_sessions.user_id FK users`,
- `auth_sessions.organization_membership_id nullable FK organization_memberships`.

Canonical security wymaga active membership i tenant context.

### Problem

Dwa niezależne proste FK nie zabraniają fizycznie stanu:

`auth_sessions.user_id = User A`

przy jednoczesnym:

`organization_membership_id = membership należący do User B`.

Backend ma obowiązek to walidować, ale Stage 4 wymaga dla relacji bezpieczeństwa jawnego DB lub transactional invariant. Obecny blueprint go nie definiuje.

Podobny wzorzec trzeba potem zastosować do innych tenant-aware relacji, ale **w tym slice analizujemy tylko identity/session/membership**.

### Ryzyko

Błąd w session-context switch lub migracji danych może utworzyć cross-user/cross-tenant session context, którego pojedyncze FK nie wykryją.

### Wymagane rozstrzygnięcie

Należy wybrać i udokumentować composite FK / constrained relation albo równie mocny transactional invariant wraz z migration testem.

Nie rozwiązujemy tego w tym audycie.

---

## DB-IAM-004 — P1 SECURITY: last-owner i privilege-escalation invariants istnieją w policy, ale nie w physical/transaction contract

`docs/04` i `specs/security/permissions.yml` wymagają:
- ostatni aktywny Owner nie może zostać usunięty bez transferu,
- permission changes są audytowane,
- elevated permissions podlegają zaostrzonym regułom.

### Problem

Obecny physical model nie określa jednoznacznie:
- co jest canonical markerem Ownera w danych,
- jak policzyć „active Owner” przy custom permissions,
- czy Owner jest specjalnym membership kind, template provenance czy permission capability,
- jak zablokować revoke/suspend ostatniego Ownera,
- jak zablokować nieautoryzowane self-escalation,
- czy użytkownik z `staff.permissions.manage` może nadać sobie permission wyższego poziomu niż sam posiada,
- co dzieje się z aktywnymi sesjami po odebraniu elevated permission.

### Ryzyko

Możliwy lockout całego OSK albo escalation do uprawnień administracyjnych mimo poprawnego pojedynczego `membership_permissions` row.

### Wymagane rozstrzygnięcie

Potrzebny jest jawny owner/privilege-management invariant, transaction boundary i testy race/concurrency.

Nie rozwiązujemy tego w tym audycie.

---

## DB-IAM-005 — P1: membership lifecycle i explicit permission mutation lifecycle nie są zamknięte

Obecna tabela `organization_memberships` ma ogólne `status varchar(32)`, a `membership_permissions` ma mutable `granted boolean`.

### Problem

Nie ma jeszcze canonical physical odpowiedzi dla:
- dokładnych stanów membership i dozwolonych przejść,
- revoke/suspend/reactivate vs hard delete,
- czy `(organization_id,user_id)` oznacza jeden trwały membership row reaktywowany w czasie, czy historię okresów członkostwa,
- kiedy revoked membership traci sesje,
- jak szybko permission revoke staje się skuteczny,
- jak audit wskazuje actor/reason/version przy zmianie permissions,
- jak uniknąć lost update przy dwóch administratorach zmieniających permissions równocześnie.

### Ryzyko

Agent może zaimplementować destructive delete membership, nadpisywanie permissions bez kontroli wersji albo opóźnione cofnięcie dostępu mimo wymagań bezpieczeństwa.

### Wymagane rozstrzygnięcie

Potrzebny jest jeden lifecycle membership + permission mutation contract z optimistic/transactional concurrency i session invalidation policy.

Nie rozwiązujemy tego w tym audycie.

---

# Uwaga o źródłach

W poprzednim gate jako potencjalne źródło pojawiała się nazwa `docs/88-rbac-policy-matrix.md`. Taki plik nie jest canonical source w aktualnym repo. Canonical machine-readable RBAC source to:

`specs/security/permissions.yml`

wraz z `docs/04-roles-permissions.md` i `docs/08-security-compliance.md`.

Gate zostaje skorygowany w ramach tej diagnozy, aby agent nie próbował czytać nieistniejącego dokumentu.

---

# Wynik DB4_2_STEP_1

**FAIL z pięcioma P1 blockerami.**

To jest poprawny wynik diagnozy. Nie przechodzimy do Staff/Locations/Vehicles ani nie generujemy migracji.

Kolejność napraw:
1. `DB-IAM-001` — effective permissions / role template semantics,
2. gate rerun,
3. `DB-IAM-002` — data scope model,
4. gate rerun,
5. `DB-IAM-003` — session-membership integrity,
6. gate rerun,
7. `DB-IAM-004` — owner/escalation invariants,
8. gate rerun,
9. `DB-IAM-005` — membership/permission lifecycle,
10. final DB4_2 gate.

**Następny pojedynczy krok:** tylko `DB-IAM-001`.
