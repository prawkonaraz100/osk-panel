# 110. Stage 4 — Licenses / Learning Access database audit

Data: 2026-09-06

**Etap:** `DB4_6_LICENSES_LEARNING_ACCESS`  
**Aktualny krok:** `DB4_6_STEP_1 / DB-LIC-001`  
**Status:** `DB-LIC-001 PASS / 0 P0 / 6 P1 OPEN`

Machine-readable diagnoza: `specs/database/licenses-learning-access.yml`.

---

## 1. Zasada pracy

DB4_6 rozpoczynamy tak samo jak wcześniejsze slice'y Stage 4:

1. najpierw pełna diagnoza,
2. bez naprawiania blockerów w kroku diagnostycznym,
3. następnie dokładnie jeden blocker na krok,
4. po każdym blockerze self-audit i centralny gate,
5. agregaty `specs/database/core-schema.yml` i `docs/87-physical-database-schema.md` pozostają zamrożone do finalnego DB4_6 aggregate sync,
6. DB4_7+, Stage 5, migracje Laravel i UI pozostają poza bieżącym zakresem.

W tym kroku **nie utworzono migracji i nie zmieniono istniejącego fizycznego agregatu**.

---

## 2. Źródła

Najważniejsze źródła produktowe i reverse-engineeringowe:

- `docs/38-student-assign-license-form.md`,
- `docs/39-student-license-delete-confirmation.md`,
- `docs/43-student-access-credentials-pdf.md`,
- `docs/44-own-student-access-document-policy.md`,
- `docs/52-license-purchase-screen.md`,
- `docs/53-license-management-panel.md`,
- `docs/54-license-expanded-assignments.md`,
- `docs/55-license-generate-access-form.md`,
- `docs/56-license-existing-student-selected-state.md`,
- `docs/57-license-list-sorting.md`,
- `docs/58-license-bulk-access-pdf.md`,
- `specs/screens/student-assign-license.yml`,
- `specs/screens/student-license-delete.yml`,
- `specs/screens/student-access-credentials-pdf.yml`,
- `specs/screens/license-management-panel.yml`,
- `specs/screens/license-expanded-assignments.yml`,
- `specs/screens/license-generate-access.yml`,
- `specs/screens/license-bulk-access-pdf.yml`,
- `specs/reverse-engineering-manifest.yml`.

Źródła architektury, lifecycle i security:

- `AGENTS.md`,
- `docs/83-core-lifecycle-policy.md`,
- `specs/design/core-lifecycle.yml`,
- `docs/84-test-strategy.md`,
- `specs/security/permissions.yml`,
- `specs/api/paths/students-courses.yaml`,
- `specs/api/paths/licenses.yaml`,
- `specs/api/openapi-components-v1.yaml`.

Aktualne agregaty DB były w tym kroku wyłącznie źródłem odczytu:

- `specs/database/core-schema.yml`,
- `docs/87-physical-database-schema.md`.

---

## 3. Potwierdzony model biznesowy, którego nie wolno uprościć

Licencja i dostęp kursanta nie są jednym rekordem.

Potwierdzony model rozdziela co najmniej:

`LicenseProduct -> LicenseInventoryEntry -> LicenseAssignment -> LicenseActivation/effective entitlement`

oraz niezależnie:

`Student -> StudentLearningAccount -> global User/AuthLoginIdentifier`.

Jeden LearningAccount może mieć wiele LicenseAssignments. Przydzielenie licencji może wskazać istniejący dostęp albo utworzyć nowy dostęp w tej samej biznesowej operacji.

Jedno zatwierdzenie assignmentu dotyczy dokładnie jednego targetu. Bulk selection istnieje dla dokumentu dostępowego, ale **nie potwierdza batch assignmentu**.

---

## 4. Assignment i activation pozostają osobnymi zdarzeniami

Evidence oraz własna polityka produktu potwierdzają:

`assigned != activated`.

Nieaktywowane przydzielenie:
- ma własny historyczny rekord,
- nie musi mieć końca okresu dostępu,
- może zostać cofnięte przed aktywacją,
- cofnięcie nie jest hard-delete.

Aktywacja:
- jest osobnym zdarzeniem,
- może zostać wykonana przez kursanta albo uprawniony sekretariat OSK,
- uruchamia lub przedłuża entitlement,
- po aktywacji zwykłe `revoke-unactivated` jest zabronione.

`docs/83` i `specs/design/core-lifecycle.yml` ustanawiają dla naszego produktu:

`assigned -> revoked -> inventory restored exactly once`

przed aktywacją oraz immutable activation history po aktywacji.

---

## 5. Stacking / extension jest wymaganym capability

Zaobserwowane aktywne rekordy oraz wcześniejsza decyzja architektoniczna wymagają obsługi kolejnych licencji przedłużających ten sam LearningAccount.

Docelowy efekt musi wspierać semantykę równoważną:

`next effective period starts no earlier than current entitlement end`

bez utraty dni przy współbieżnych aktywacjach.

W kroku diagnostycznym **nie zamykamy jeszcze dokładnej fizycznej reprezentacji ani wszystkich wyjątków produktu**. Stwierdzamy jedynie, że aktualny blueprint nie dowodzi jeszcze pełnej, reprodukowalnej i race-safe historii tego efektu.

---

## 6. OSK-managed credentials są obowiązkowym capability

Nasza własna decyzja produktowa nie wymusza self-service kursanta.

Uprawniony pracownik OSK może:
- utworzyć dostęp,
- ustawić albo wygenerować hasło początkowe,
- jednorazowo zobaczyć hasło,
- wydrukować je w świeżo generowanym dokumencie,
- opcjonalnie od razu aktywować licencję,
- później zresetować hasło i przygotować nową kartkę.

Jednocześnie obowiązuje bezwzględna granica security:
- starego hasła nie da się odzyskać,
- baza przechowuje hash, nie recoverable plaintext,
- audit/log/outbox/idempotency snapshot nie mogą przechowywać hasła,
- QR nie zawiera hasła.

Ta kombinacja wymaga jawnego, bezpiecznego one-time handoff lifecycle. Sam fakt, że `student_access_handoffs` nie ma kolumny password, jeszcze nie zamyka problemu, ponieważ trwały `document_asset_id` może wskazywać dokument zawierający świeży sekret, jeżeli nie zdefiniujemy jego storage/lifetime policy.

---

## 7. Obecny API contract jest bardziej szczegółowy niż fizyczny blueprint

Stage-3 API ma już osobne operacje:
- LearningAccount create/update,
- password reset,
- access handoff,
- handoff PDF,
- LicenseAssignment create,
- activation,
- revoke-unactivated,
- assignment history,
- bulk access PDF.

LearningAccount PATCH ma `If-Match`, ale aktualny physical blueprint `student_learning_accounts` nie posiada jeszcze jawnego `version`.

`CreateLicenseAssignmentRequest` rozdziela target:
- `existing_learning_account_id`, albo
- `student_id + new_learning_account`.

To jest poprawne biznesowe rozdzielenie, ale baza nadal musi fizycznie udowodnić exact Student/Account/Organization/Inventory target.

---

# 8. DB-LIC-001 — same-tenant i exact-target integrity

**Severity: P1 — OPEN.**

Aktualne encje licencyjne przechowują równolegle:
- `organization_id`,
- `student_id`,
- `student_learning_account_id`,
- `license_inventory_entry_id`,
- później `license_assignment_id` w activation.

W agregacie nie ma jeszcze pełnego zestawu composite same-tenant/exact-target boundaries, który uniemożliwia skonstruowanie np.:
- assignmentu OSK A na inventory OSK B,
- assignmentu Studenta A na LearningAccount Studenta B,
- activation z `organization_id` różnym od assignmentu,
- handoff podpiętego do konta z innego tenant.

Application validation nie jest wystarczającą końcową granicą dla tych relacji.

**Ryzyko:** cross-tenant access/licensing albo licencja przypisana do niewłaściwego kursanta.

---

# 9. DB-LIC-002 — LearningAccount ↔ global auth identity

**Severity: P1 — OPEN.**

DB4_2 ustanowił `auth_login_identifiers` jako canonical global login-resolution source, ponieważ ekran logowania nie ma tenant selectora.

`student_learning_accounts` posiada obecnie jednocześnie:
- `user_id`,
- `primary_auth_login_identifier_id`,
- `login_identifier_projection`.

Brakuje jeszcze fizycznego dowodu, że wskazany AuthLoginIdentifier należy do dokładnie tego samego Usera. Brakuje również zamkniętego invariant, który chroni projection przed drift względem canonical identifier.

DB4_6 nie może tworzyć drugiego tenantowego namespace loginów ani omijać globalnej unikalności DB4_2. Nie może też automatycznie scalać dwóch globalnych Users tylko dlatego, że operator wpisał podobny e-mail/login.

**Ryzyko:** konto nauki wskazuje niewłaściwą globalną identity albo wyświetla login inny od faktycznego login resolvera.

---

# 10. DB-LIC-003 — LearningAccount lifecycle, concurrency i Student archive

**Severity: P1 — OPEN.**

Aktualny `student_learning_accounts.status` nie ma zamkniętego katalogu ani state matrix.

Jednocześnie:
- API update ma `If-Match`,
- fizyczny blueprint nie ma `student_learning_accounts.version`,
- DB4_4 jawnie odroczył wpływ Student archive/restore na learning access i licencje do DB4_6.

Trzeba przed implementacją ustalić:
- concurrency root konta,
- kiedy konto przyjmuje nowe assignmenty,
- kiedy można resetować credentials,
- kiedy może zostać aktywowana licencja,
- co dokładnie robi Student archive wobec istniejącego aktywnego dostępu,
- co restore może, a czego nie może reaktywować automatycznie.

Nie wolno rozwiązać archive przez usunięcie historycznych assignmentów/activationów.

**Ryzyko:** stale update lub archive pozostawia konto w niezdefiniowanym stanie dostępowym.

---

# 11. DB-LIC-004 — one-time credentials / handoff / PDF

**Severity: P1 — OPEN.**

To blocker security.

Produkt musi jednocześnie umożliwić sekretariatowi jednorazowe przekazanie świeżego hasła i zagwarantować, że po tej granicy stary plaintext nie jest odzyskiwalny.

Aktualny model `student_access_handoffs` nie definiuje jeszcze:
- one-time secret state,
- expiry/consumption semantics,
- zachowania retry,
- bezpiecznego sposobu wygenerowania PDF ze świeżym hasłem,
- czy i kiedy PDF zawierający sekret może być utrwalony jako FileAsset,
- pełnej atomowości bulk `regenerate_credentials_when_required`.

Trwały PDF z plaintext password jest funkcjonalnie recoverable secret storage, nawet jeśli sama tabela nie ma kolumny `password`.

Idempotency replay również nie może przechowywać świeżego hasła w `safe_response_snapshot`.

**Ryzyko:** recoverable plaintext credential, secret leakage albo częściowe resetowanie kilku kont przy bulk export.

---

# 12. DB-LIC-005 — inventory ↔ assignment lifecycle i exactly-once

**Severity: P1 — OPEN.**

Aktualny blueprint ma partial unique dla current assignment jednego inventory entry oraz opisuje revoke transaction. To są dobre elementy, ale jeszcze nie tworzą pełnej fizycznej równoważności stanów.

Nie jest jeszcze DB-zamknięte, że np.:
- `available` inventory nie ma live assignment,
- current assignment odpowiada właściwemu inventory state,
- activation/revoke nie mogą oba zastosować skutku do tej samej jednostki,
- `revoke-unactivated` przywraca tę samą jednostkę dokładnie raz,
- create-new-account + assignment nie może commitować połowicznie,
- bezpośredni błędny zapis nie pozostawi inventory `assigned` bez assignmentu albo `available` z live assignmentem.

Assignment status również nie ma jeszcze zamkniętego katalogu/state-field matrix.

**Ryzyko:** overselling, utrata jednostki inventory, podwójny zwrot albo assignment bez prawidłowego inventory effect.

---

# 13. DB-LIC-006 — activation, stacking i reprodukowalność entitlementu

**Severity: P1 — OPEN.**

Aktualny aggregate już deklaruje:
- lock LearningAccount przy extension,
- `max(now,current entitlement end)` jako bazę,
- immutable `license_activations`,
- one activation per assignment,
- projection expiry z max `effective_to`.

Diagnoza nie odrzuca tych decyzji. Problem polega na tym, że fizyczny model nadal nie niesie wystarczającej historycznej informacji i cross-row guards, żeby wykazać dokładny efekt każdej licencji po latach.

Brakuje m.in. jawnego historycznego snapshotu/effect provenance odpowiadającego potrzebom:
- duration użyty przy activation,
- stan expiry przed zastosowaniem licencji,
- stan expiry po zastosowaniu licencji,
- stabilność historycznego wyniku po zmianie LicenseProduct,
- jednoznaczna provenance learner activation vs OSK-managed activation.

Mandatory race test już wymaga, aby dwa równoległe extensions nie zgubiły dni i zużyły każdą inventory unit dokładnie raz.

**Ryzyko:** utrata dni, double-application albo historyczny entitlement, którego nie da się odtworzyć/audytować.

---

# 14. DB-LIC-007 — product-language capability i projection consistency

**Severity: P1 — OPEN.**

Język bieżącego dostępu należy do LearningAccount, ale LicenseAssignment również przechowuje `language_code`, a Stage-3 assignment request wymaga language także wtedy, gdy targetem jest już istniejący LearningAccount.

Bez jawnej owner/snapshot rule wartości mogą się rozjechać.

Dodatkowo evidence ma `SOURCE_CONFLICT`:
- purchase page pokazuje cztery języki,
- operational assignment UI pokazuje również rosyjski.

Dlatego nie wolno hardkodować globalnej listy. Kompatybilność musi wynikać z danych/capability produktu.

Ten sam problem dotyczy reprodukowalności projekcji panelu:
- latest license,
- active/available counts,
- hide finished,
- remaining time,
- assignment history.

Te projekcje muszą pochodzić z jednej spójnej historii assignment/activation/entitlement, a nie z niezależnie przepisywanych tekstowych statusów.

**Ryzyko:** produkt przyznaje dostęp w niewspieranym języku albo panel pokazuje stan sprzeczny z faktycznym entitlementem.

---

## 15. Granica z DB4_9 — zakup nie jest naprawiany w DB4_6

Ekran zakupu potwierdza sekwencję:

`order/payment -> inventory grant -> later assignment -> activation`.

DB4_6 rozpoczyna się od istniejącej jednostki `LicenseInventoryEntry` i rozwiązuje jej późniejszy lifecycle.

Nie projektujemy teraz:
- cennika,
- rabatów,
- VAT,
- PayU,
- przelewu,
- payment webhooków,
- momentu komercyjnego grantowania inventory.

Te kwestie należą do DB4_9. DB4_6 musi jedynie pozostawić bezpieczną granicę, aby późniejszy grant inventory mógł wejść do już poprawnego lifecycle.

---

## 16. Granica z DB4_7 / DB4_8 / DB4_10

DB4_6 nie rozwiązuje:
- inventory i lifecycle egzaminu wewnętrznego — DB4_7,
- PKK provider operations — DB4_8,
- finalnego fizycznego modelu audit/outbox/notifications — DB4_10.

Jednocześnie operacje licencyjne i credentials muszą już teraz deklarować obowiązek audytu i zakaz umieszczania sekretów w audit/outbox.

---

## 17. Self-audit diagnozy

Wynik: **PASS_DIAGNOSIS_COMPLETE**.

Sprawdzone:
- żaden z siedmiu blockerów nie został naprawiony w kroku diagnozy,
- nie usunięto rozdzielenia LearningAccount / Assignment / Activation,
- zachowano existing-access i create-new-access assignment flows,
- zachowano exactly-one target i nie wymyślono batch assignmentu,
- zachowano OSK-managed credentials oraz learner self-service,
- zachowano jednorazowy plaintext handoff bez akceptacji recoverable storage,
- zachowano reset+reprint,
- zachowano single i bulk PDF,
- zachowano per-access localization,
- zachowano stacking/extension,
- nie hardkodowano języka rosyjskiego ani czterojęzykowej oferty jako globalnej reguły,
- nie zmieniono globalnego auth-login modelu DB4_2,
- nie rozwiązano zakupów/płatności DB4_9,
- nie rozpoczęto DB4_7+,
- nie zmieniono aggregate DB blueprint,
- nie utworzono migracji Laravel,
- nie rozpoczęto Stage 5 ani UI.

---

## 18. Wynik bramki diagnostycznej

- P0: **0**,
- P1: **7**,
- rozwiązane w diagnozie: **0**,
- otwarte P0/P1: **7**,
- wynik DB4_6: **FAIL_WITH_7_P1_BLOCKERS**,
- final DB4_6 aggregate sync: **BLOCKED**,
- DB4_7: **BLOCKED**,
- Stage 5: **BLOCKED**,
- Laravel migrations: **BLOCKED**,
- UI/feature implementation: **BLOCKED**.

Kolejność napraw:
1. `DB-LIC-001` — same-tenant + exact target integrity,
2. `DB-LIC-002` — LearningAccount ↔ global auth identity,
3. `DB-LIC-003` — LearningAccount lifecycle/concurrency/archive,
4. `DB-LIC-004` — one-time credentials/handoff/PDF,
5. `DB-LIC-005` — inventory/assignment exactly-once lifecycle,
6. `DB-LIC-006` — activation/stacking entitlement,
7. `DB-LIC-007` — product-language/projection consistency.

Następny dozwolony krok po centralnym gate to wyłącznie **DB-LIC-001**.

**STOP przed pierwszym fixerem DB4_6.**

---

## 19. DB-LIC-001 — resolution contract and self-audit

**Current result: PASS.**

Bieżący fixer nie zmienia historycznej diagnozy powyżej; dopisuje rozstrzygnięcie DB-LIC-001.

### 19.1 Canonical same-tenant pattern

Dla tenant-owned relacji używamy:

`child(organization_id, relation_id) -> parent(organization_id,id)`

z `ON UPDATE RESTRICT / ON DELETE RESTRICT`. Dla nullable `document_asset_id` używamy `MATCH SIMPLE`.

Wymagane/reużywane candidate keys:
- `students(organization_id,id)`,
- `file_assets(organization_id,id)`,
- `student_learning_accounts(organization_id,id)`,
- `student_learning_accounts(organization_id,id,student_id)`,
- `license_inventory_entries(organization_id,id)`,
- `license_assignments(organization_id,id)`.

### 19.2 Exact Student / LearningAccount target

Najważniejsza granica:

`license_assignments(organization_id,student_learning_account_id,student_id)`

`-> student_learning_accounts(organization_id,id,student_id)`.

To blokuje zarówno cross-tenant account, jak i konto innego Studenta w tym samym OSK.

Dodatkowo Assignment ma same-tenant FK do:
- `license_inventory_entries(organization_id,id)`,
- `students(organization_id,id)`.

### 19.3 Handoff i Activation

`student_access_handoffs`:
- `(organization_id,student_learning_account_id) -> student_learning_accounts(organization_id,id)`,
- nullable `(organization_id,document_asset_id) -> file_assets(organization_id,id)`.

Non-NULL dokument handoffu musi więc być prywatnym assetem tego samego OSK. DB-LIC-001 nie rozstrzyga readiness/purpose ani trwałego storage PDF zawierającego świeży sekret; to nadal DB-LIC-004.

`license_activations`:
- `(organization_id,license_assignment_id) -> license_assignments(organization_id,id)`.

### 19.4 Global relations pozostają globalne

Nie tenant-scope'ujemy sztucznie:
- `users`,
- `auth_login_identifiers`,
- `languages`,
- `license_products`.

Same-User AuthLoginIdentifier guard i projection consistency pozostają wyłącznie DB-LIC-002.

### 19.5 Commerce boundary

`license_inventory_entries.source_order_item_id` pozostaje do DB4_9. Aktualny `order_items` nie ma `organization_id`; tenant wynika przez `orders`. Dodanie poprawnej same-tenant granicy wymaga rozstrzygnięcia commerce candidate keys/tenant key i nie może być wykonane połowicznie w DB-LIC-001.

To odroczenie dotyczy provenance źródła inventory, a nie docelowego Student/LearningAccount/Assignment/Activation effect.

### 19.6 Migration safety

Przed przyszłym dodaniem constraintów wymagane są prechecki dla:
- LearningAccount↔Student,
- Handoff↔LearningAccount,
- Handoff↔optional FileAsset,
- Assignment↔Inventory,
- Assignment↔Student,
- Assignment↔exact LearningAccount/Student pair,
- Activation↔Assignment.

Legacy mismatch powoduje FAIL + reviewed remediation. Nie wolno automatycznie:
- przepisywać tenantów,
- zmieniać Studenta assignmentu, żeby pasował do konta,
- podmieniać LearningAccount,
- nullować cross-tenant FileAsset,
- przepisywać organization Activation.

### 19.7 Required tests

- LearningAccount cross-tenant Student → reject,
- Handoff cross-tenant LearningAccount → reject,
- Handoff cross-tenant non-NULL FileAsset → reject,
- Assignment cross-tenant InventoryEntry → reject,
- Assignment cross-tenant Student → reject,
- Assignment cross-tenant LearningAccount → reject,
- Assignment same OSK + wrong Student/Account pair → reject,
- Activation cross-tenant Assignment → reject,
- ten sam globalny User może wspierać LearningAccounts w różnych OSK,
- Language i LicenseProduct pozostają globalnymi słownikami/capabilities.

### 19.8 Scope preservation

Self-audit potwierdził:
- DB-LIC-002..007 pozostają OPEN,
- nie dodano lifecycle/version LearningAccount,
- nie rozstrzygnięto credential secret lifecycle,
- nie zamknięto inventory state equivalence/revoke exactly-once,
- nie zmieniono stacking semantics,
- nie zamknięto product-language authority,
- nie rozwiązano DB4_9 commerce,
- agregaty `core-schema.yml` i `docs/87` pozostają zamrożone,
- brak migracji Laravel, DB4_7, Stage 5 i UI.

Aktualny stan po DB-LIC-001:
- resolved: **1/7**,
- open P0: **0**,
- open P1: **6**,
- DB4_6: **FAIL_WITH_6_P1_BLOCKERS**.

Następny dozwolony krok po centralnym gate: **DB-LIC-002 only**.

**STOP przed DB-LIC-002.**
