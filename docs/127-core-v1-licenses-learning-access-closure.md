# 127. Core v1 — Licenses / Learning Access closure

Data: 2026-09-11

**Slice:** `CORE-V1-LICENSES-LEARNING-ACCESS-001`  
**Implementation machine:** PASS  
**Narrative payload:** CANDIDATE  
**Central closure:** PENDING

## 1. Zakres

Slice obejmuje wyłącznie Licenses / Learning Access:

- globalny `User` pozostający źródłem tożsamości logowania,
- tenant-scoped `student_learning_accounts`,
- jawne zarządzanie hasłem przez `user_password_management`,
- bezpieczne credential handoff records,
- globalny katalog produktów licencyjnych i capability językowe,
- organizacyjną pulę konkretnych jednostek inventory,
- historyczne przypisania licencji,
- aktywacje jako append-only entitlement periods,
- revoke-before-activation z przywróceniem tej samej jednostki inventory,
- create-time `initial_license` jako atomowy side effect Student create,
- dependency-closed migracje fazy `expand`,
- backend/API/permissions/audit/idempotency,
- truthful Stage-4 DBT runtime traceability,
- UI panelu `/licencje/panel` oraz panel dostępu na szczegółach kursanta.

Slice nie implementuje Platform Commerce, checkoutu, orders, provider payments ani `Historia zakupów`. Nie rozpoczęto Internal Exams, PKK adaptera ani Dashboard / Notifications / Purchase History.

## 2. Accepted implementation provenance

Clean accepted implementation payload commit:

`6806ae10aa3f138c0f3b3ad5867399ae38f967a8`

Parent accepted commit:

`44c4839073bac9e9d3c9005ee79cb69fbe3c11e5`

Tree SHA clean payload odpowiada dokładnie zwalidowanemu helper tree:

`1c4db790661c40bdae54316af289d02db5df8f44`

Po accepted push secret-scan wykrył wyłącznie testowe fixture strings przypominające hasło. Zostały zastąpione generowanym syntetycznym fixture bez zmiany zachowania produktu:

`ea3bedb0bf1f4dc59807d6d2dd907b20150298f7`

Finalny accepted implementation tip:

`ea3bedb0bf1f4dc59807d6d2dd907b20150298f7`

Accepted Implementation CI:

`34620888683` — **5/5 SUCCESS**.

Accepted API Contract Gate na clean payload:

`34620788836` — **PASS**.

Finalny accepted contracts-and-traceability job na `ea3bedb0...` również zakończył się PASS.

Helper PR secret-scan nie był authority, ponieważ GitHub zwracał przed skanem `403 Resource not accessible by integration`; accepted-branch push secret-scan jest finalnym dowodem i przeszedł PASS.

## 3. Migracje

Materializacja Stage-4 DAG wzrosła z **57/170** do **67/170** node'ów / phase steps.

Dodano dokładnie dziesięć dependency-closed nodes fazy `expand`:

- `MIG-TBL-LANGUAGES`,
- `MIG-TBL-USER_PASSWORD_MANAGEMENT`,
- `MIG-TBL-STUDENT_LEARNING_ACCOUNTS`,
- `MIG-TBL-STUDENT_ACCESS_HANDOFFS`,
- `MIG-TBL-STUDENT_ACCESS_EXPORT_BATCHES`,
- `MIG-TBL-LICENSE_PRODUCTS`,
- `MIG-TBL-LICENSE_PRODUCT_LANGUAGE_CAPABILITIES`,
- `MIG-TBL-LICENSE_INVENTORY_ENTRIES`,
- `MIG-TBL-LICENSE_ASSIGNMENTS`,
- `MIG-TBL-LICENSE_ACTIVATIONS`.

Execution identity:

`e2bbaff93f281685224ae47d83d8815a67feef5e09256b7dff0c4f8cd2413401`

Plan identity pozostał:

`d2fd6bc999dc2a5ee024e9b91bf00f5a5dad29575554a0ba42eba67107c23f10`

Nie materializowano późniejszych candidate-key/FK/index/check/preflight phases. Wszystkie **14** Stage-4 migration preflight contracts pozostają pending zgodnie z globalnym phase barrier.

## 4. Learning Account i identity boundary

`Student`, `User` i `student_learning_account` pozostają trzema różnymi pojęciami.

Runtime:

- nie tworzy tenantowego drugiego namespace tożsamości,
- nie scala użytkowników po nazwisku, PESEL-u ani e-mailu,
- istniejący globalny login należący do innego `User` powoduje fail-closed conflict,
- current login identifier jest powiązany z globalnym `User`,
- archived Student nie może otrzymać nowego operacyjnego efektu Learning Access,
- archive nie usuwa konta, assignment history ani entitlement history.

Zmiana loginu jest versioned mutation. Stary globalny identifier nie jest ukrycie przepisywany ani kasowany.

## 5. Password-management authority i sekret

OSK-managed password ma własną authority w `user_password_management`.

Wymagania runtime:

- initial password wymaga jawnego `student_access.manage_credentials`,
- samo `licenses.assign` nie daje prawa do ustanowienia hasła,
- password reset wymaga `student_access.reset_password`,
- reset zwiększa globalny `credential_version`,
- plaintext nie trafia do bazy, audytu, domain eventów ani outboxa,
- hash jest przechowywany w `users.password_hash`,
- świeży plaintext może pojawić się tylko w pierwszej odpowiedzi,
- idempotent replay tej samej operacji zwraca `one_time_plaintext_password = null`,
- safe replay snapshot zawiera wyłącznie zredagowaną odpowiedź.

Credential handoff zachowuje metadata i snapshot wersji credential, ale nie tworzy odzyskiwalnego magazynu plaintextu.

## 6. License inventory, assignment i revoke

`license_inventory_entries` reprezentują konkretne jednostki puli OSK.

Assignment:

- wiąże inventory entry, Student, Learning Account i capability językową,
- język assignmentu jest snapshotem,
- istniejące konto zachowuje własny current learning language,
- jedna bieżąca jednostka inventory nie może zostać przypisana ponownie przez runtime,
- assignment history jest zachowywana.

Revoke-before-activation:

- działa wyłącznie przed aktywacją,
- zachowuje historyczny assignment row,
- zapisuje aktora, czas i powód,
- przywraca tę samą konkretną jednostkę inventory do `available`,
- nie cofa aktywowanego entitlementu,
- ponowne przypisanie tworzy nowy historyczny assignment z kolejnym sequence.

## 7. Activation i entitlement periods

Aktywacja jest osobnym, append-only effectem w `license_activations`.

Runtime:

- assignment może wytworzyć najwyżej jedną activation,
- inventory przechodzi `assigned -> consumed`,
- duration jest snapshotowana przy aktywacji,
- dzień licencyjny to dokładnie 86400 sekund zgodnie z Stage-4 authority,
- kolejne entitlement periods są układane bez nakładania: nowy `effective_from` zaczyna się od końca poprzedniego okresu, jeżeli poprzedni okres jeszcze trwa,
- historyczne okresy nie są przepisywane przy przedłużeniu.

Expiry jest projekcją z immutable entitlement history, a nie mutowalnym licznikiem dostępu.

## 8. Student create `initial_license` handoff

Poprzedni Students slice pozostawił `initial_license` jako zależność Learning Access / Licenses. Handoff został zamknięty.

Przy Student create:

- klient nie podaje ani nie może podrobić nowego `student_id`,
- backend wstrzykuje właśnie utworzony Student,
- Learning Account i License Assignment powstają w tej samej transakcji biznesowej,
- nieobsługiwany język lub brak permission powoduje rollback całego Student create,
- initial password wymaga dodatkowo `student_access.manage_credentials`,
- brak efektu częściowego: Student bez uzgodnionego access effect nie zostaje zapisany.

## 9. Permissions, tenant scope, audit i idempotency

Runtime używa permission authority, nie role shortcutów.

Kluczowe permissions:

- `student_access.view`,
- `student_access.create`,
- `student_access.manage_credentials`,
- `student_access.reset_password`,
- `licenses.view`,
- `licenses.assign`,
- `licenses.activate`,
- `licenses.revoke_unactivated`.

Student target jest zawsze sprawdzany w tenant scope. Foreign-tenant target failuje jako niewidoczny zasób.

Mutacje emitują audit/domain-event/outbox intent w tej samej lokalnej transakcji. Krytyczne create/activate/revoke/reset commands korzystają ze współdzielonego idempotency store.

Dodane audit actions:

- `learning_account_created`,
- `learning_account_updated`,
- `learning_account_password_reset`,
- `learning_account_handoff_created`,
- `license_assignment_created`,
- `license_assignment_activated`,
- `license_assignment_revoked`.

## 10. API i UI

Runtime API obejmuje m.in.:

- `GET/POST /students/{studentId}/learning-accounts`,
- `PATCH /students/{studentId}/learning-accounts/{accountId}`,
- `POST .../password-reset`,
- `POST .../access-handoffs`,
- `GET /license-products`,
- `GET /license-products/{productId}/languages`,
- `GET /license-inventory`,
- `GET/POST /license-assignments`,
- `GET /license-assignments/{assignmentId}`,
- `POST /license-assignments/{assignmentId}/activate`,
- `POST /license-assignments/{assignmentId}/revoke-unactivated`,
- `GET /students/{studentId}/learning-accounts/{accountId}/license-assignments`.

UI:

- osobny panel `/licencje/panel`,
- liczba dostępnych i aktywnych licencji per produkt,
- wyszukiwanie kursanta / learning login,
- sortowanie,
- toggle ukrywania zakończonych,
- przydzielanie licencji do istniejącego konta,
- utworzenie nowego learning account przy assignment,
- rozwijana historia assignmentów,
- aktywacja,
- revoke-before-activation,
- panel dostępu na szczegółach kursanta,
- reset hasła z jednorazowym ujawnieniem świeżego sekretu.

Zakup licencji / checkout nie jest pozorowany w tym slice i pozostaje w późniejszym Platform Commerce.

## 11. Executable DBT

Przed slice runtime catalog miał:

**73/491 implemented executable assertions**  
**418/491 pending_domain_materialization**

Po truthful hardening dodano dokładnie pięć Stage-4 DBT z realnym executable evidence:

- `DBT-LIC-001`,
- `DBT-LIC-002`,
- `DBT-LIC-003`,
- `DBT-LIC-004`,
- `DBT-LIC-051`.

Finalny runtime catalog:

**78/491 implemented executable assertions**  
**413/491 pending_domain_materialization**

Świadomie nie oznaczono jako implemented:

- fizycznych composite FK/candidate-key/check/index DBT z późniejszych migration phases,
- wszystkich 14 migration preflight,
- formalnych `CP-TWO-WRITERS` concurrency DBT bez niezależnego two-connection evidence,
- Platform Commerce DBT,
- Internal Exams i późniejszych modułów.

## 12. Machine evidence

Accepted Implementation CI `34620888683` na finalnym accepted implementation tip `ea3bedb0...` zakończył się **5/5 SUCCESS**:

- backend-quality — PASS,
- frontend-quality — PASS,
- runtime-tests-and-migrations — PASS,
- contracts-and-traceability — PASS,
- secret-scan — PASS.

Accepted PostgreSQL suite:

**124 tests / 1910 assertions — PASS**

Dodatkowo:

- migration authority / registry validation — PASS,
- Composer strict validation — PASS,
- Pint — PASS,
- PHPStan — PASS zero errors,
- frontend lint — PASS,
- Vue/TypeScript typecheck — PASS,
- Vite production build — PASS,
- npm audit high — PASS,
- changed-module traceability — PASS,
- accepted-push Gitleaks scan — PASS.

Accepted API Contract Gate `34620788836` na clean implementation payload `6806ae10...` — PASS. Test-only synthetic fixture hardening w `ea3bedb0...` nie zmienił API; finalny `contracts-and-traceability` na `ea3bedb0...` również przeszedł PASS.

## 13. Jawnie odroczone zależności

Nie są częścią PASS tego slice:

- późniejsze candidate-key/FK/index/check/preflight phases,
- dedykowane two-writer concurrency DBT bez executable two-connection evidence,
- zakup licencji, orders, checkout i provider payments,
- Platform Purchase History,
- automatyczne identity merge/reconciliation,
- pełny bulk PDF/export pipeline ponad zmaterializowany storage boundary,
- Internal Exams,
- PKK adapter,
- Dashboard / Notifications / Purchase History.

## 14. Narrative result

Implementation machine = **PASS**.

Finalny accepted implementation tip:

`ea3bedb0bf1f4dc59807d6d2dd907b20150298f7`

Accepted machine validation:

- Implementation CI `34620888683` — **5/5 SUCCESS**,
- API Contract Gate `34620788836` — **PASS**,
- PostgreSQL — **124 / 1910 PASS**,
- accepted-push secret-scan — **PASS**.

Ten commit jest **narrative closure candidate**. Central Stage-5 gate pozostaje świadomie niezmieniony do czasu osobnego 5/5 CI PASS na tym dokumencie.

Po PASS narrative candidate jedynym dozwolonym następnym krokiem jest centralne oznaczenie:

`CORE-V1-LICENSES-LEARNING-ACCESS-001 = PASS`

i ustawienie następnego slice zgodnie z `AGENTS.md` na **Internal Exams**, bez jego rozpoczynania w ramach tego closure.
