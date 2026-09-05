# 105. Stage 4 — Identity / Tenant / RBAC audit

Data: 2026-09-05

**Status:** `ALL DB-IAM BLOCKERS PASS / FINAL AGGREGATE SYNC PENDING`

## Cel slice'u

Identity/Tenant/RBAC zamykamy blocker po blockerze. Nie generujemy migracji Laravel ani nie przechodzimy do DB4_3 przed końcową synchronizacją aggregate blueprintu.

---

# DB-IAM-001 — PASS

Role template jest wyłącznie assignment-time presetem/provenance. Runtime source of truth to `membership_permissions`; brak/future permission = DENY.

# DB-IAM-002 — PASS

Data scope jest per permission przez `membership_permission_scopes`; granted permission bez legalnego scope = fail-closed DENY. Stary membership-wide `data_scope` jest superseded.

# DB-IAM-003 — PASS

Tenant session context jest chroniony composite FK `(organization_membership_id,user_id) -> organization_memberships(id,user_id)`. Sesja nie może wskazać membership innego Usera.

# DB-IAM-004 — PASS

Owner jest governance markerem `is_owner`, nie skrótem permission. Obowiązuje protected owner baseline, serializowany last-owner guard, self-escalation ban, grant ceiling i `authorization_version` dla natychmiastowej utraty stale privileges.

---

# DB-IAM-005 — PASS: membership + permission mutation lifecycle

## Durable membership

Jeden trwały `organization_memberships` na `(organization_id,user_id)`. Membership nie jest hard-delete.

Canonical statusy:
- `active`,
- `suspended`,
- `revoked`.

`user_id` membership jest immutable.

## Status semantics

`active`:
- może autoryzować po permission + scope,
- może być wybrany jako session tenant context.

`suspended`:
- nie może autoryzować,
- permission/scope snapshot pozostaje zachowany do wznowienia,
- aktywne session tenant contexts dla tego membership są czyszczone do `NULL` w tej samej transakcji,
- globalna sesja Usera nie musi być wylogowana, więc dostęp do innego OSK tego samego Usera pozostaje niezależny.

`revoked`:
- nie może autoryzować,
- `is_owner=false`,
- wszystkie current permission decisions po commit mają `granted=false`,
- wszystkie current scope rows są usunięte,
- stare uprawnienia pozostają wyłącznie w immutable audycie, nie jako automatycznie reaktywowalny snapshot.

## Reactivation

`suspended -> active` zachowuje istniejący permission/scope config, ale nie przywraca automatycznie starych session bindings.

`revoked -> active` jest explicit reactivation i wymaga świeżego template/permission+scope snapshotu w tej samej transakcji. Nie przywraca historycznych praw ani Ownera automatycznie.

## Versioning i concurrency

`organization_memberships.version bigint >= 1` jest optimistic-concurrency version całego authorization aggregate.

Każdy admin mutation wymaga expected version. Backend:
1. lockuje membership,
2. porównuje expected version,
3. wykonuje permission/scope/status/owner mutation atomowo,
4. zwiększa `version` dokładnie raz,
5. jeśli zmiana wpływa na authorization — zwiększa `authorization_version` dokładnie raz,
6. zapisuje audit i outbox w tej samej transakcji.

`membership_permissions` i scope rows nie mają niezależnych wersji. `OrganizationMembership` jest single concurrency aggregate root.

Dwa równoległe zapisy z tym samym expected version nie mogą oba nadpisać konfiguracji. Jeden kończy się conflict bez partial write.

## Lock order

Dla permission/scope mutation:
1. organization row — tylko jeśli dotykamy governance/Owner invariant,
2. actor membership — jeśli potrzebny do grant ceiling,
3. target membership.

Przy wielu membershipach tej samej klasy lockujemy deterministycznie po UUID, żeby ograniczyć deadlock race.

## Suspend / revoke i sesje

Jeśli status przestaje być authorization-eligible:
- wszystkie `auth_sessions.organization_membership_id` wskazujące ten membership są ustawiane na `NULL` w tej samej transakcji,
- membership-scoped reauth/elevation proof jest unieważniany,
- globalna identity session pozostaje domyślnie aktywna,
- inne membershipy Usera nie są naruszane.

Po reactivation stara sesja nie wraca automatycznie do tego OSK; wymagany jest explicit context selection.

Permission/scope revoke przy nadal aktywnym membership nie wymaga logoutu. `authorization_version` gwarantuje, że stare prawo nie przeżyje kolejnego autoryzowanego requestu.

## Audit/history

Nie dodajemy osobnej tabeli membership periods jako warunku poprawności. Current projection to durable membership row, a pełna historia zmian jest append-only w `AuditLog` + outbox domain events.

Audit dla każdej mutation zawiera co najmniej:
- actor,
- organization,
- target membership,
- action,
- request_id,
- `version before/after`,
- `authorization_version before/after`,
- bezpieczny before/after lub domain diff,
- timestamp.

Reason jest obowiązkowe m.in. dla revoke, Owner promotion/demotion, elevated grant/revoke i security remediation.

## Quality gate DB-IAM-005

Sprawdzono:
- brak hard-delete membership — **PASS**,
- exact lifecycle states/transitions — **PASS**,
- suspend zachowuje config, revoke zeruje runtime privileges — **PASS**,
- revoked reactivation wymaga fresh provisioning — **PASS**,
- stale expected version nie może nadpisać nowej konfiguracji — **PASS**,
- permission+scope mutation jest jednym atomic aggregate write — **PASS**,
- `version` i `authorization_version` mają rozdzielone znaczenie — **PASS**,
- suspend/revoke natychmiast usuwa tenant context ze wszystkich związanych sesji — **PASS**,
- inne OSK tego samego globalnego Usera pozostają niezależne — **PASS**,
- audit + outbox są w tej samej transakcji — **PASS**,
- last-owner/self-escalation/grant-ceiling pozostają obowiązujące podczas lifecycle transitions — **PASS**.

Nie znaleziono nowego P0/P1 wynikającego z decyzji DB-IAM-005.

**Gate DB-IAM-005: PASS.**

---

# Stan DB4_2 po zamknięciu blockerów

- `DB-IAM-001` — PASS,
- `DB-IAM-002` — PASS,
- `DB-IAM-003` — PASS,
- `DB-IAM-004` — PASS,
- `DB-IAM-005` — PASS.

Nie oznacza to jeszcze finalnego `DB4_2 PASS`, ponieważ bounded-context `specs/database/identity-rbac.yml` musi zostać osobnym krokiem zsynchronizowany z:
- `specs/database/core-schema.yml`,
- `docs/87-physical-database-schema.md`,
- traceability, jeśli wymaga aktualizacji.

To jest **osobna końcowa bramka**, nie część naprawy DB-IAM-005.

**Następny pojedynczy krok: DB4_2 FINAL AGGREGATE SYNC GATE.**
