# 105. Stage 4 — Identity / Tenant / RBAC audit

Data: 2026-09-05

**Status:** `DB4_2 FINAL PASS`

## Cel slice'u

Identity/Tenant/RBAC został zaprojektowany i zamknięty blocker po blockerze. W tym slice nie uruchamialiśmy implementacji Laravel ani nie projektowaliśmy Staff/Locations/Vehicles poza zamrożeniem zależności wymaganych przez RBAC.

Zasada:

`canonical RBAC -> physical ownership -> tenant constraint -> effective permission resolution -> lifecycle transaction -> invariant test`

Źródła canonical:
- `specs/database/identity-rbac.yml`,
- `specs/security/permissions.yml`,
- `specs/database/core-schema.yml`,
- `docs/04-roles-permissions.md`,
- `docs/08-security-compliance.md`,
- `docs/87-physical-database-schema.md`.

---

# DB-IAM-001 — PASS

Role template jest wyłącznie assignment-time presetem + provenance. Backend runtime nie autoryzuje po `role_template_code`.

Source of truth dla capability:

`active OrganizationMembership -> membership_permissions`

Brak permission row = DENY. Nowy permission dodany później jest DENY dla istniejącego membership do explicit grant/reapply. Zmiana template nie zmienia po cichu istniejących praw.

---

# DB-IAM-002 — PASS

Data scope jest per permission, nie jako jeden string na membership.

Canonical chain:

`membership_permissions -> membership_permission_scopes -> permission-specific resolver`

Scope codes:
- `organization`,
- `own`,
- `assigned_students`,
- `assigned_locations`.

Fizyczne tabele:
- `data_scopes`,
- `permission_scope_options`,
- `membership_permission_scopes`.

Granted permission bez legalnego scope = fail-closed DENY. `organization` scope nie omija tenant validation. List/GET/search/count/export/PDF stosują tę samą policy.

Stary `organization_memberships.data_scope` został usunięty z canonical runtime model.

---

# DB-IAM-003 — PASS

Non-null tenant context sesji jest zabezpieczony przez composite FK:

`auth_sessions(organization_membership_id,user_id)`

->

`organization_memberships(id,user_id)`.

Membership innego Usera nie może być przypięty do sesji. `organization_membership_id` może być `NULL` dla global/pre-tenant flow, ale tenant-owned request bez aktywnego membership context = DENY.

`organization_memberships.user_id` jest immutable.

---

# DB-IAM-004 — PASS

`organization_memberships.is_owner` jest governance markerem, a nie skrótem permission.

Owner musi mieć jawnie materializowany protected baseline z `organization` scope:
- `organization.view`,
- `organization.members.manage`,
- `staff.permissions.manage`,
- `sessions.manage.organization`.

Last-owner invariant jest serializowany per organizacja. Post-transaction active Owner count musi być `>= 1`. Transfer Ownera jest atomowy.

Normal admin flow blokuje:
- self-promotion,
- self-grant,
- self-scope-broadening,
- template reapply na sobie, jeśli rozszerza privileges.

Grant ceiling nie pozwala delegować permission/scope ponad aktualne prawa aktora. Elevated grant/broadening wymaga aktywnego Ownera.

`authorization_version >= 1` jest security epoch i unieważnia stale authorization cache/elevation proof.

---

# DB-IAM-005 — PASS

Jeden trwały `organization_memberships` istnieje per `(organization_id,user_id)`. Hard-delete membership jest zabroniony.

Canonical statusy:
- `active`,
- `suspended`,
- `revoked`.

`version >= 1` jest optimistic concurrency version całego authorization aggregate. `membership_permissions` i scope rows nie mają własnych niezależnych wersji.

Semantyka:
- `active` może autoryzować po permission + scope,
- `suspended` nie autoryzuje, ale zachowuje permission/scope config,
- `revoked` nie autoryzuje, zeruje Owner/current grants/scopes; historyczne prawa pozostają w immutable audit.

Reactivation:
- suspended -> active zachowuje config, ale nie rebinduje automatycznie starych sesji,
- revoked -> active wymaga fresh permission/template provisioning i nie przywraca Ownera.

Stale expected membership version -> conflict bez partial write.

Suspend/revoke w tej samej transakcji czyści `auth_sessions.organization_membership_id` dla sesji związanych z tym membership. Globalna identity session może pozostać aktywna, więc inne OSK tego samego Usera pozostają niezależne.

Audit + outbox commitują atomowo z permission/scope/Owner/status mutation.

---

# Final aggregate synchronization gate — PASS

Po zamknięciu pięciu blockerów wykonano osobny final sync, zamiast przechodzić od razu do DB4_3.

## Machine aggregate

`specs/database/core-schema.yml` zawiera teraz:
- `data_scopes`,
- `permission_scope_options`,
- `membership_permission_scopes`,
- finalny `organization_memberships` z `is_owner`, `version`, `authorization_version`, role-template provenance i `active|suspended|revoked`,
- composite same-user session FK,
- Owner/last-owner/grant-ceiling invariants,
- permission/scope mutation concurrency,
- suspend/revoke/reactivate semantics,
- session context clearing,
- audit/outbox atomicity,
- migration/invariant tests dla DB-IAM-001..005.

## Narrative aggregate

`docs/87-physical-database-schema.md` zawiera ten sam canonical RBAC shape i nie zawiera już membership-wide `data_scope` jako runtime model.

## Preservation self-audit

Podczas finalnego sync sprawdzono również, czy aktualizacja narrative DB nie staje się nowym źródłem redukcji reverse-engineered scope.

Wniosek: **PASS**.

Uzasadnienie:
- `docs/87` jest physical/narrative blueprintem, nie źródłem kompletności screen capability,
- szczegółowy scope nadal jest chroniony przez screen specs, reverse-engineering manifest i `specs/traceability/core-v1.yml`,
- machine `core-schema.yml` zachowuje wcześniejsze non-RBAC modele oraz dodaje DB4_2 zamiast usuwać capability,
- potwierdzone ekrany/akcje/PDF/filter/sort nie zostały usunięte ze swoich authoritative specs,
- skrócenie części prose w `docs/87` nie może być interpretowane jako zmiana zakresu; obowiązuje authority matrix/preservation contract.

`specs/traceability/core-v1.yml` nie wymagał bezpośredniej mutacji do zamknięcia DB4_2: już wskazuje `core-schema.yml` i `specs/security/permissions.yml`, a DB4_2 nie dodał nowej konkurencyjnie zaobserwowanej funkcji HTTP/UI — dodał fizyczne security/integrity support dla istniejących capabilities. Stage 5 rozszerzy acceptance traceability per moduł.

## Scope lock self-audit

- brak migracji Laravel — PASS,
- brak implementacji UI/backend — PASS,
- DB4_3 Staff/Locations/Vehicles nie został projektowo rozpoczęty — PASS,
- brak zmian w Student/Course/Calendar/Licenses/Exams/PKK/Finance poza zachowaniem istniejącego blueprintu — PASS.

---

# Wynik DB4_2

- `DB-IAM-001` — PASS,
- `DB-IAM-002` — PASS,
- `DB-IAM-003` — PASS,
- `DB-IAM-004` — PASS,
- `DB-IAM-005` — PASS,
- final aggregate sync — PASS,
- otwarte P0/P1 w DB4_2 — **0**.

**DB4_2_IDENTITY_TENANT_RBAC: PASS.**

Następny etap może zostać otwarty dopiero po aktualizacji głównej Stage-4 gate:

`DB4_3_STAFF_LOCATIONS_VEHICLES — DIAGNOSIS ONLY`.
