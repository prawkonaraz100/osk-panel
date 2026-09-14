# 167. Core v1 closure re-audit — po pełnym Stage-4 migration materialization

Data: 2026-09-14

**Gate:** `CORE-V1-CLOSURE-AUDIT-001`

**Wynik:** `AUDIT_COMPLETE_REPO_P1_REMAINS`

**P0 repo:** 0

**P1 repo:** pozostają. Stage-4 jest już kompletny, ale core v1 nadal nie jest repository-complete.

PKK provider runtime pozostaje `FROZEN_UNTIL_EXPLICIT_UNFREEZE`.

## 1. Co zmieniło się od audytu 149

Poprzedni audyt wskazywał jako pierwszy P1 niepełną materializację Stage-4. Ten blocker jest zamknięty:

- DAG: **170 / 170 node'ów**,
- materialized steps: **261**,
- contract: **4 / 4**,
- execution identity: `82da84d3efb312d78b432ad6081491c04b03569a0f250722d6befc11ca233712`,
- closure head: `36ce96dccd58f305a20bc38d6cea43d613e1dd00`,
- closure CI #482 / run `34809229705`: **5 / 5 PASS**,
- PostgreSQL: **261 tests / 5110 assertions — PASS**,
- restore: **121 -> 121 PASS**.

Stage-4 nie jest już repo P1.

## 2. Machine HTTP coverage

Porównano machine-readable `specs/api/required-operations-v1.yml` z rzeczywistymi bindingami w `routes/web.php`.

Wynik:

- inline canonical HTTP operations objętych porównaniem: **172**,
- aktualne runtime HTTP bindings: **135**,
- missing canonical bindings: **39**.

Z tych 39:

- **14** należy do zamrożonego PKK/PWPW boundary,
- **1** to provider-specific payment webhook truth,
- **24** to repo-actionable core v1 HTTP gaps.

Zamrożonych/provider-specific 15 pozycji nie podnosi się do repo P1.

## 3. Repo-actionable HTTP gaps — 24

### Auth / session — 10

Brakuje:

- `auth.register`
- `auth.login`
- `auth.logout`
- `auth.password_forgot`
- `auth.password_reset`
- `auth.sessions_list`
- `auth.session_revoke`
- `auth.account_closure_request`
- `auth.social_redirect`
- `auth.social_callback`

Najważniejsza zależność: istniejące kontrolery wymagają Laravel session key `auth_session_id`, ale repo nie ma HTTP lifecycle, który bezpiecznie tworzy i zamyka ten kontekst.

Fizyczne authority już istnieje: `users`, `auth_login_identifiers`, `user_password_management`, `auth_social_accounts`, `auth_sessions`, `account_closure_requests`. Nie wolno tworzyć równoległego modelu auth.

### Organization Settings — 5

Brakuje:

- `organization.get`
- `organization.update`
- `organization.settings.get`
- `organization.settings.update`
- `organization.accepted_terms.get`

`OrganizationSettingsService` istnieje, ale traceability nadal deklaruje UI jako `DEFERRED_UI_UNTIL_FEATURE_SLICE`; brak też route `/ustawienia`.

### Commerce / entitlements — 4

Brakuje:

- `license_orders.create`
- `exam_orders.create`
- `service_entitlements.list`
- `service_entitlements.activate`

Purchase history i provider-neutral payment attempt istnieją, ale order-create dla zakupów licencji/egzaminu oraz service-entitlement runtime nadal nie są zmaterializowane.

### Learning credential documents — 2

Brakuje:

- `learning_accounts.download_handoff_pdf`
- `license_credentials.bulk_pdf`

Nie wolno odzyskiwać historycznego plaintext hasła. Single/bulk PDF musi bazować wyłącznie na autoryzowanym handoff flow.

### Student Progress — 1

Brakuje `students.progress`, a potwierdzona zakładka „Postęp” nadal jest fizycznie `disabled` w `StudentCourseWorkspace.vue`.

### Małe runtime gaps — 2

Brakuje:

- `dictionaries.languages.list`
- `audit_logs.list`

## 4. Dwa UI-only P1

Te braki nie zwiększają countu missing HTTP bindings, bo ich API/runtime już istnieje.

### Resource assets / vehicle documents

UploadsAssets runtime i vehicle-document API są zmaterializowane, ale `ResourceWorkspace.vue` nadal tylko zapisuje nazwę wybranego zdjęcia i informuje, że transport „zostanie podpięty”. Canonical `presign -> private PUT -> complete -> resource mutation` nie jest podłączony do Resource UI.

### Course completion

Backendowy stage transition, Training Hour Ledger i Internal Exam evidence istnieją, ale `training_completed` nadal jest sztucznie blokowane w Vue komunikatem o przyszłym podpięciu tych modułów. UI powinno wysłać istniejący transition i pozostawić fail-closed eligibility backendowi.

## 5. External boundaries — nie są repo P1

### PKK/PWPW

**14** brakujących canonical bindings pozostaje zamrożonych. Nie implementować provider runtime, status mapping, signing/XML, provider retry ani mutating PKK UI bez explicit unfreeze i autorytatywnego kontraktu.

### Payment provider webhook

`payment_webhook.receive` pozostaje external-provider authority. Repo może implementować provider-neutral order/payment/entitlement semantics, ale nie może wymyślać podpisów webhooka, remote status protocol ani reconciliation truth.

## 6. Wynik

Repository nadal ma actionable P1, więc wynik pozostaje:

`AUDIT_COMPLETE_REPO_P1_REMAINS`

Zmiana względem audytu 149 jest istotna: **Stage-4 jest całkowicie zamknięty**, a pozostały backlog jest już czysto runtime/API/UI.

## 7. Następny pojedynczy gate

`CORE-V1-AUTH-SESSION-LIFECYCLE-001`

Zakres wyłącznie:

- `auth.login`
- `auth.logout`
- `auth.sessions_list`
- `auth.session_revoke`

Reguły:

- użyć istniejących tabel i invariants; bez nowych identity tables,
- Laravel session przechowuje tylko bezpieczne odniesienie `auth_session_id`,
- raw session/token secret nie trafia do bazy; baza zachowuje hash/framework-session reference zgodnie z authority,
- revoke/logout nie może wymagać nadal aktywnego membership context,
- list/revoke own sessions respektuje `sessions.manage.own`,
- registration, password reset, account closure, social OAuth i Organization Settings pozostają osobnymi późniejszymi gate'ami,
- PKK i provider-specific payment runtime pozostają zamrożone.

Po kolejnych corrective tranche wymagany jest ponowny zero-gap closure audit.
