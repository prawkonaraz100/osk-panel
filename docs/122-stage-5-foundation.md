# 122. Stage 5 — S5-FOUND-001 foundation closure

Data: 2026-09-10

**Krok:** `S5-FOUND-001`
**Machine:** PASS
**Narrative:** PASS
**Central:** PENDING

## 1. Przyjęty zakres

Pierwszy core foundation slice został ograniczony do zakresu dopuszczonego przez Stage-5 gate:

- `IdentityTenant`,
- `OrganizationSettings`,
- minimalny `AuditNotification` potrzebny do atomowego audit/domain-event/outbox,
- dependency-closed foundation subset migracji Stage 4,
- wykonywalne testy i DBT traceability,
- reverse-engineering traceability dla zmienionych modułów.

Nie rozpoczęto późniejszych modułów core ani szerokiego UI.

## 2. Clean implementation provenance

Clean implementation commit:

`f32d5c77bb844aac6e2ea0770b70b415fd28354e`

Parent accepted tip:

`3f87f828f6832465eda318be302bdb745f30dfcd`

Validated helper final tree:

`ad2796a0b2bc77e80d270d2fdcddb3984f9224f5`

Helper history nie weszła do accepted branch. Clean commit ma dokładnie jednego rodzica i zawiera wyłącznie foundation code, migracje, seeder, testy oraz implementation traceability.

## 3. Materializacja bazy

Registry wykonawczy materializuje teraz 18/170 node'ów Stage-4 migration DAG:

- istniejący `MIG-EXT-BTREE-GIST`,
- 17 foundation table nodes wymaganych przez Identity/Tenant/Settings oraz minimalny Audit/Event/Outbox.

Wszystkie nowe table nodes należą do fazy `expand`, zachowują canonical topological order i tworzą dependency-closed subset. Nie materializowano niezależnych późniejszych tabel, constraintów ani cutover phases tylko dlatego, że mają niższy lub wyższy numer order.

Stage-4 authority 170-node DAG i siedmiofazowy contract nie zostały zmienione.

## 4. Identity / Tenant / RBAC

Foundation implementuje:

- rzeczywisty model authenticated `User`,
- aktywny tenant membership context przez `auth_sessions`,
- backend authorization wyłącznie na podstawie `membership_permissions`,
- per-permission scopes przez `membership_permission_scopes`,
- fail-closed brak membership, permission lub scope,
- bezwzględną same-tenant walidację przed scope resolution,
- zakaz traktowania `is_owner` lub role-template provenance jako skrótu autoryzacyjnego,
- permission ceiling dla zmian uprawnień,
- zakaz self privilege escalation,
- `version` i `authorization_version` dla zmian autoryzacyjnych.

Konfiguracja Laravel auth została przełączona z bootstrapowego fail-closed placeholdera na realny session guard + Eloquent provider dopiero w tym kroku.

## 5. Owner governance i concurrency hardening

Końcowy code review po pierwszym zielonym machine runie wykrył dwa realne ryzyka, dlatego pierwszy zielony kandydat nie został promowany.

Naprawiono:

1. normalna zmiana permission nie może osłabić protected Owner baseline przy nadal aktywnym `is_owner=true`;
2. authorization/settings mutations serializują tenant i membership rows przed finalnym permission check, więc równoległy revoke nie może ominąć aktualnej autoryzacji przez TOCTOU.

Owner transfer:

- wymaga aktywnego Ownera jako aktora,
- wymaga aktywnego successor membership,
- wymaga zmaterializowanego protected permission baseline następcy,
- serializuje tenant governance przez lock organizacji i membership rows,
- atomowo przenosi Owner marker,
- sprawdza invariant co najmniej jednego aktywnego Ownera,
- emituje audit/domain-event/outbox w tej samej transakcji.

## 6. Organization Settings

`OrganizationSettingsService` wykonuje tenant-authorized optimistic-concurrency write.

W tym foundation slice świadomie obsługiwane są tylko już potwierdzone podstawowe pola:

- imię,
- nazwisko,
- nazwa firmy,
- telefon,
- adres organizacji.

Nieznane lub jeszcze niezmaterializowane pola są odrzucane. Stale `version` zatrzymuje zapis przed efektem biznesowym. Udany write zwiększa settings version dokładnie raz i zapisuje audit/outbox atomowo.

## 7. Audit / Domain Event / Outbox

Minimalny foundation tworzy i wykorzystuje:

- `audit_action_policy_revisions`,
- `audit_action_policy_currents`,
- `audit_logs`,
- `domain_events`,
- `outbox_messages`.

Krytyczna zmiana nie może zatwierdzić business state bez obowiązującej audit policy. Audit, domain event oraz outbox intent są zapisywane w tej samej lokalnej transakcji co mutacja.

Audit payload jest allowlistowany; klucze związane m.in. z password, token, PESEL, PKK i secret są odrzucane.

## 8. Canonical reference catalog

`FoundationReferenceCatalogSeeder` nie utrzymuje drugiej ręcznej listy permissionów. Czyta kanoniczny katalog z:

`specs/security/permissions.yml`

i materializuje:

- permission catalog,
- 4 data scopes,
- dozwolone permission/scope pairs,
- resolver codes,
- 3 foundation audit policies.

Seeder jest idempotentny i działa na czystym PostgreSQL po controlled migrations.

## 9. Wykonywalne testy i DBT traceability

Po foundation:

- finalny Stage-4 DBT catalog nadal ma dokładnie 491 IDs,
- 13 IDs ma status `implemented_executable_assertion`,
- 478 pozostaje `pending_domain_materialization`.

Zaimplementowane foundation assertions obejmują m.in.:

- tenant request bez membership context -> deny,
- granted permission bez scope -> deny,
- organization scope nie omija tenant validation,
- Owner marker bez permission rows -> deny,
- suspended membership -> deny,
- actor nie może grantować ponad własny ceiling,
- successor bez Owner baseline -> transfer deny,
- authorization mutation zwiększa authorization version raz,
- protected Owner baseline nie może zostać osłabiony normalnym mutation,
- poprawny Owner transfer zostawia jednego aktywnego Ownera,
- stale settings version rollback,
- bieżący permission jest sprawdzany przed settings mutation,
- brak current audit policy powoduje rollback,
- business state + audit + domain event + outbox commitują razem,
- produkcyjny reference seeder materializuje katalog i jest idempotentny.

## 10. Machine evidence

Hardening helper validation:

- run `34532023466` — SUCCESS,
- static quality — PASS,
- controlled migration + repeat run — PASS,
- production `db:seed` — PASS,
- registry assertions — PASS,
- PostgreSQL suite — PASS,
- frontend quality — PASS,
- OpenAPI + changed-module traceability — PASS,
- preservation + scope — PASS.

Accepted branch validation dla clean implementation commit:

- Implementation CI run `34532350584` — **5/5 jobs SUCCESS**,
- API Contract Gate run `34532350492` — **SUCCESS**,
- PostgreSQL suite — **35 tests / 1181 assertions PASS**.

## 11. Preservation

Bez zmian pozostały:

- Stage-4 gate blob: `306dabaa58223abe2f3b2a34b888b075c88e9ec4`,
- core-schema blob: `b0629cc48e26798d945356eaa1d0c9b1187aa542`,
- final matrix blob: `ad5f2aa2e14ef248b95dd3dda0d1cbcb2e69d441`,
- docs/87 blob: `a48eacd92d4a9c8e989f5d70c3bec95b9231a77c`,
- docs/116 blob: `6f76255742d4902546f45c28d54c1da6ee3d3fd7`,
- `AGENTS.md`: `96ec177c82ac6f031eb22f443295147a83482baf`,
- `README.md`: `710166c3189451a765c2314609e37607cc39e529`,
- API contract workflow preservation: PASS.

## 12. Narrative result

**S5-FOUND-001 machine + narrative = PASS.**

Pozostał wyłącznie centralny update `specs/gates/stage-5-implementation-gate.yml`, który może ustawić blocker na PASS i `open_P0_P1: 0` dopiero po własnej preservation/consistency validation.

**STOP przed następnym core slice.**
