# 110. Stage 4 — Licenses / Learning Access database audit

Data: 2026-09-07

**Etap:** `DB4_6_LICENSES_LEARNING_ACCESS`  
**Aktualny krok:** `DB4_6_STEP_6 / DB-LIC-006`  
**Status:** `DB-LIC-001..006 PASS / 0 P0 / 1 P1 OPEN`

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

---

## 20. DB-LIC-002 — resolution contract and self-audit

**Current result: PASS.**

Bieżący fixer zachowuje DB4_2 jako nadrzędny model globalnego loginu. Nie tworzymy osobnej tenantowej tabeli ani unikalności loginów dla LearningAccount.

### 20.1 Jedno canonical źródło loginu

Canonical źródłem pozostaje:

`auth_login_identifiers`

z globalną current-unikalnością `identifier_normalized` ustanowioną w DB4_2.

LearningAccount nie przechowuje drugiego niezależnego loginu. Provisional `login_identifier_projection` zostaje usunięty z canonical persisted modelu, a `StudentLearningAccount.login_identifier` w API/listach/wyszukiwaniu jest projekcją przez join do `auth_login_identifiers.identifier_normalized`.

Jeżeli kiedyś potrzebny będzie performance cache, może być wyłącznie derived/non-authoritative i musi mieć równoważny DB refresh guard.

### 20.2 Pointer nie oznacza globalnego primary loginu

Provisional pole:

`primary_auth_login_identifier_id`

zostaje zastąpione przez:

`auth_login_identifier_id`.

Powód: jeden globalny User może mieć kilka current identifiers (`email`/`username`), a różne LearningAccounts mogą korzystać z dowolnego current identifiera tego samego Usera. Nie wymuszamy `is_primary_for_type=true` i nie zmieniamy semantyki globalnego primary e-mail/loginu.

### 20.3 Same-User DB boundary

Wymagany candidate key:

`auth_login_identifiers(id,user_id)`.

LearningAccount ma composite FK:

`student_learning_accounts(auth_login_identifier_id,user_id)`

`-> auth_login_identifiers(id,user_id)`.

To fizycznie dowodzi, że wybrany login należy dokładnie do tego samego globalnego Usera co LearningAccount. `user_id` nie może być normalnie przepięty na innego Usera.

### 20.4 Tylko current identifier

LearningAccount może wskazywać tylko identifier z `revoked_at IS NULL`.

Ponieważ predykatu current nie da się wyrazić zwykłym FK, wymagamy `DEFERRABLE INITIALLY DEFERRED` constraint triggera lub równoważnej transactional DB boundary działającej w obu kierunkach:
- finalny LearningAccount nie może wskazywać revoked identifiera,
- revoke identifiera nie może commitować, jeśli po transakcji jakiś LearningAccount nadal go wskazuje.

Identity revoke musi więc atomowo przepiąć zależne LearningAccounts albo zakończyć się konfliktem.

### 20.5 Brak tenantowego namespace loginów

Nie dodajemy:
- unique `(organization_id,login_identifier)`,
- unique LearningAccount per AuthLoginIdentifier.

Ten sam current identifier tego samego Usera może wspierać LearningAccounts w kilku OSK. Generic login najpierw rozwiązuje globalnego Usera, dopiero później aplikacja wybiera autoryzowany kontekst nauki/OSK.

### 20.6 Create flow i zakaz heuristic merge

Dla `Email lub login`:
1. input jest normalizowany według istniejącej Identity policy,
2. lookup odbywa się globalnie w current `auth_login_identifiers`,
3. jeżeli identifier jest wolny, OSK-managed flow może atomowo utworzyć nowego globalnego principal + identifier + LearningAccount,
4. system nie może szukać „tej samej osoby” po imieniu, contact_email, PESEL ani innych polach Studenta,
5. jeżeli current identifier już należy do Usera, sam wpisany string **nie jest wystarczającym dowodem**, że LearningAccount należy podpiąć do tego Usera.

Reuse istniejącego Usera wymaga jawnego trusted same-user binding context. Bez niego wynik to conflict wymagający identity resolution. Dokładne merge/recovery/email-verification policy pozostaje istniejącą osobną bramką Identity.

Wpisanie e-maila nie oznacza automatycznie `verified_at`.

### 20.7 Update login_identifier

Normalny LearningAccount update nie zmienia `user_id`.

Dla nowego identyfikatora:
- jeśli current identifier istnieje dla tego samego Usera → LearningAccount może zostać przepięty na ten identifier,
- jeśli istnieje dla innego Usera → conflict, bez rebind/merge,
- jeśli nie istnieje → tworzony jest nowy identifier dla tego samego Usera, a następnie LearningAccount jest przepinany.

Nowy identifier nie staje się po cichu `is_primary_for_type=true`.

Stary globalny identifier **nie jest automatycznie revokowany** jako ukryty efekt PATCH LearningAccount, bo może być używany przez inne LearningAccounts lub globalne flow konta. Jeśli produkt wymaga unieważnienia starego aliasu, musi to być jawny command domeny Identity.

Optimistic concurrency/lost-update policy LearningAccount pozostaje DB-LIC-003.

### 20.8 Migration safety

Przed przyszłym sync/migration wymagane są prechecki:
- każdy LearningAccount ma `user_id` i identifier pointer,
- pointer należy do tego samego Usera,
- pointer jest current/non-revoked,
- jeżeli legacy `login_identifier_projection` istnieje, musi dokładnie odpowiadać `identifier_normalized` wskazanego row.

Zabronione jest:
- szukanie brakującego pointera po projection i wybieranie „najlepszego” matcha,
- przepinanie LearningAccount do innego Usera,
- automatyczne od-revokowanie identifiera,
- tworzenie identifiera z legacy projection bez reviewed evidence,
- merge Userów przy kolizji loginu.

Niejednoznaczność → migration FAIL + reviewed remediation.

Po zweryfikowanym cutoverze:
- dodajemy `(id,user_id)` candidate key,
- canonical `auth_login_identifier_id`,
- same-User composite FK,
- current-identifier deferred guard,
- read/search/API przechodzą na join,
- persisted `login_identifier_projection` jest usuwany.

### 20.9 Required tests

- LearningAccount identifier innego Usera → reject,
- LearningAccount z revoked identifierem → reject przy commit,
- revoke identifiera nadal wskazywanego przez LearningAccount → reject,
- globalna current-unikalność visible loginu DB4_2 pozostaje nienaruszona,
- jeden User może mieć wiele current identifiers,
- ten sam User/identifier może wspierać LearningAccounts w wielu OSK,
- brak per-tenant login namespace,
- API `login_identifier` = joined `identifier_normalized`,
- typed existing identifier innego Usera bez trusted binding → conflict,
- update na identifier tego samego Usera → repoint bez zmiany Usera,
- update na identifier innego Usera → reject,
- update na wolny identifier → nowy identifier dla tego samego Usera bez hidden primary promotion,
- LearningAccount update nie revokuje po cichu poprzedniego globalnego aliasu,
- migration failuje przy pointer/User mismatch, revoked pointer lub nierozliczonej legacy projection.

### 20.10 Scope preservation

Self-audit potwierdził:
- DB-LIC-001 pozostaje PASS,
- DB-LIC-003..007 pozostają OPEN,
- nie dodano jeszcze LearningAccount `version` ani lifecycle,
- nie rozstrzygnięto Student archive/restore,
- nie zmieniono password/handoff/PDF policy,
- nie rozwiązano inventory↔assignment exactly-once,
- nie zmieniono activation stacking,
- nie zamknięto language capability/projection,
- nie zmieniono DB4_2 global login uniqueness ani verification authority,
- agregaty pozostają zamrożone,
- brak migracji Laravel, DB4_7, Stage 5 i UI.

Aktualny stan po DB-LIC-002:
- resolved: **2/7**,
- open P0: **0**,
- open P1: **5**,
- DB4_6: **FAIL_WITH_5_P1_BLOCKERS**.

Następny dozwolony krok po centralnym gate: **DB-LIC-003 only**.

**STOP przed DB-LIC-003.**

---

## 21. DB-LIC-003 — resolution contract and self-audit

**Current result: PASS.**

Ten krok zamyka wyłącznie lifecycle i concurrency `StudentLearningAccount` oraz wpływ `Student archive/restore`. Nie zmienia secret/PDF, inventory, activation stacking ani language capability.

### 21.1 LearningAccount ma własny concurrency root

Dodajemy:

`student_learning_accounts.version bigint not null default 1 check (version >= 1)`.

`version` obejmuje materialne zmiany samego konta:
- `auth_login_identifier_id`,
- `language_code`,
- jawne przejście statusu.

LearningAccount PATCH:
1. lockuje Student po regule parent-first,
2. wymaga non-archived Student dla normalnej konfiguracji,
3. lockuje LearningAccount `FOR UPDATE`,
4. porównuje expected version z `If-Match`,
5. dopiero po zgodności stosuje zmianę,
6. zwiększa `version` dokładnie raz.

Stale expected version nie może pozostawić częściowego zapisu. Semantic no-op nie zwiększa wersji.

Dokładny HTTP required-marker/428 pozostaje Stage 5. Password/credential epoch pozostaje DB-LIC-004, assignment version DB-LIC-005, a activation stacking DB-LIC-006.

### 21.2 Minimalny zamknięty lifecycle konta

Canonical statusy LearningAccount:
- `active`,
- `suspended`.

Nie dodajemy `expired` jako statusu konta, ponieważ wygasa **entitlement/licencja**, a nie sama globalna możliwość istnienia LearningAccount.

Nowe konto startuje jako:
- `status=active`,
- `version=1`.

Przejścia:
- `active -> suspended` tylko przez jawny domain/security command,
- `suspended -> active` tylko przez jawny resume command,
- same-state command = idempotent no-op.

Generic PATCH nie może zmieniać statusu. W aktualnym Stage-3 API nie ma potwierdzonego publicznego suspend/resume endpointu, więc DB4_6 nie dodaje nowej funkcji HTTP/UI. Fizyczny lifecycle jest jednak zamknięty, a ewentualny późniejszy command musi użyć tej samej wersji i audytu.

Nie dodajemy osobnej tabeli lifecycle tylko dla tego konta. Status transition wymaga audit + outbox; finalny model tych tabel jest DB4_10.

### 21.3 Immutable ownership

W normalnym lifecycle nie wolno przepinać LearningAccount pomiędzy:
- `organization_id`,
- `student_id`,
- `user_id`.

`organization_id` chroni DB-LIC-001, a `user_id` i identifier binding — DB-LIC-002. Zmiana Studenta nie jest zwykłą edycją konta.

### 21.4 Effective operational eligibility

Nie tworzymy drugiego persisted statusu typu `student_archived_access`.

Canonical predicate dla nowych operacyjnych skutków to:

`learning_account.status = active`

AND

`student.archived_at IS NULL`

AND current AuthLoginIdentifier z DB-LIC-002

AND niezależne globalne reguły auth Usera.

Ten predykat musi być konsumowany przez:
- learner learning/progress context,
- nowy LicenseAssignment do istniejącego LearningAccount,
- activation licencji,
- reset/generowanie nowych credentials,
- sensitive handoff,
- single/bulk credential PDF/export.

Historyczny odczyt assignmentów/activationów dla administratora nie wymaga operational eligibility, ale nadal wymaga poprawnego tenant permission/scope.

Normalny PATCH loginu/języka może działać dla `active|suspended`, jeśli Student nie jest zarchiwizowany. Korekta historycznych danych zarchiwizowanego Studenta wymaga osobnego audytowanego correction command, nie generic PATCH.

### 21.5 Student archive nie przepisuje dzieci

DB-TRN-003 pozostaje canonical parent archive transaction.

Archive Studenta:
- nie usuwa LearningAccount,
- nie zmienia `learning_account.status`,
- nie zwiększa `learning_account.version`,
- nie revokuje LicenseAssignment,
- nie zwraca inventory,
- nie usuwa ani nie przepisuje Activation,
- nie zatrzymuje licznika aktywnego entitlementu,
- nie wydłuża jego końca,
- nie revokuje globalnego Usera ani AuthLoginIdentifiera,
- nie wylogowuje globalnie Usera, który może mieć inne OSK/konteksty.

Po commit `Student archive` operational eligibility dla tego tenantowego LearningAccount wynosi `false`.

Jeśli aktywna licencja miała ważność do określonego czasu, czas nadal biegnie po wall clock. Archive nie jest refundem, revoke ani pause entitlementu.

### 21.6 Restore usuwa tylko parent gate

Student restore:
- nie zmienia statusu kont,
- nie zwiększa LearningAccount version,
- nie od-revokowuje assignmentów,
- nie odtwarza activation,
- nie wydłuża wygasłego entitlementu.

LearningAccount, który przed archive miał `status=active`, ponownie może stać się operationally eligible — ale tylko jeśli jego bieżący entitlement i globalny auth również na to pozwalają.

LearningAccount jawnie `suspended` pozostaje `suspended`.

To jest kluczowe: restore Studenta nie może przypadkiem reaktywować dostępu zawieszonego niezależną decyzją bezpieczeństwa/administracyjną.

### 21.7 Wspólna granica wyścigów

Wszystkie state-dependent operacje używają wspólnego prefixu locków:

`Student FOR UPDATE -> StudentLearningAccount FOR UPDATE`.

Nowe operacyjne skutki po Student lock wymagają `archived_at IS NULL`, a po LearningAccount lock wymagają `status=active`.

Dzięki temu:
- create account vs archive ma jeden porządek serialny,
- PATCH vs archive nie gubi zmian,
- assignment/activation/reset/handoff vs archive mają jeden winner/order,
- jeśli efekt licencyjny lub credentialowy commitnie pierwszy, późniejszy archive go **nie cofa** — tylko blokuje kolejne użycie,
- jeśli archive commitnie pierwszy, nowy efekt jest odrzucany po parent lock.

DB-LIC-005/006 mogą rozszerzyć ten prefix o własne locki inventory/assignment/activation. Nie mogą zmienić kolejności parent-first. DB-LIC-004 doprecyzuje locki reset/handoff.

### 21.8 Cache/session safety

Archive zwiększa `students.version` zgodnie z DB-TRN-003. Każdy cache/token decyzji learning-context musi więc rewalidować co najmniej:
- `students.version`,
- `student_learning_accounts.version`,
- `student_learning_accounts.status`.

Nie wolno traktować starego, długo żyjącego cache jako prawa do dalszego korzystania po archive.

Nie oznacza to revokowania całej globalnej AuthSession tego Usera — inne organizacje i globalne funkcje pozostają niezależne.

### 21.9 Migration safety

Przyszła migracja:
1. precheck istniejących `student_learning_accounts.status`,
2. unknown/null status bez wiarygodnego mapowania → FAIL + reviewed remediation,
3. dodanie `version bigint >=1`,
4. CHECK `status IN ('active','suspended')`,
5. zabezpieczenie normalnej immutability organization/student/user,
6. wiring PATCH do expected-version + lock,
7. wiring state-dependent operations do Student→LearningAccount lock prefix,
8. wdrożenie effective eligibility w operacyjnych commandach/projekcjach.

Zabronione:
- auto-map unknown status do `active`,
- auto-suspend wszystkich kont zarchiwizowanych Studentów,
- auto-revoke ich assignmentów,
- auto-pause/extend entitlementów.

### 21.10 Required tests

- nowe konto: `active`, `version=1`,
- invalid status → reject,
- current `If-Match` PATCH → commit + version +1,
- stale PATCH → reject bez partial write,
- dwa PATCH z tą samą wersją → maksymalnie jeden commit,
- semantic no-op → bez version bump,
- generic PATCH nie może ustawić statusu,
- account create dla archived Student → reject,
- archive nie zmienia status/version LearningAccount,
- archive nie revokuje assignmentu, nie zwraca inventory i nie przepisuje Activation,
- archive blokuje nowe assignment/activation/reset/handoff/export/learning context,
- entitlement nadal biegnie podczas archive i nie jest automatycznie wydłużany,
- restore nie resume'uje jawnie suspended account,
- restore nie od-revokowuje assignmentu i nie wydłuża expired entitlementu,
- archive/create/PATCH/assignment/activation races są linearizable przez Student parent lock,
- local Student archive nie revokuje globalnego Usera ani LearningAccount tego Usera w innym OSK,
- cache eligibility rewaliduje parent/account version i status,
- migracja nie zgaduje statusu ani nie wykonuje fan-out lifecycle mutation na child rows.

### 21.11 Scope preservation

Self-audit potwierdził:
- DB-LIC-001 i DB-LIC-002 pozostają PASS,
- DB-LIC-004..007 pozostają OPEN,
- nie zaprojektowano one-time secret/PDF storage,
- nie zamknięto inventory/assignment state equivalence,
- nie rozstrzygnięto dokładnego activation stacking/history,
- nie zamknięto language capability/projection,
- Student archive pozostaje zgodny z DB-TRN-003 i nie mutuje course/formal history,
- nie dodano nowego publicznego suspend/resume UI/API,
- agregaty `core-schema.yml` i `docs/87` pozostają zamrożone,
- brak migracji Laravel, DB4_7, Stage 5 i UI.

Aktualny stan po DB-LIC-003:
- resolved: **3/7**,
- open P0: **0**,
- open P1: **4**,
- DB4_6: **FAIL_WITH_4_P1_BLOCKERS**.

Następny dozwolony krok po centralnym gate: **DB-LIC-004 only**.

**STOP przed DB-LIC-004.**

---

## 22. DB-LIC-004 — resolution contract and self-audit

**Current result: PASS.**

Ten krok zamyka wyłącznie credential authority, one-time plaintext handoff, reset/reprint, pojedynczy i zbiorczy PDF oraz secret non-persistence. Nie zmienia inventory/assignment lifecycle, activation stackingu ani product-language authority.

### 22.1 Hasło jest credentialem globalnego Usera

Canonical local-password owner pozostaje:

`users.password_hash`.

Nie dodajemy hasha na `StudentLearningAccount` i nie tworzymy tenantowego hasła obok globalnej tożsamości. Plaintext ani odwracalny ciphertext hasła logowania nie może istnieć w żadnej zwykłej tabeli.

To ma ważną konsekwencję: permission OSK do `student_access.reset_password` sam w sobie nie może oznaczać prawa do zmiany dowolnego `users.password_hash`, ponieważ ten sam globalny User może mieć inne konteksty tożsamości.

### 22.2 Fail-closed global password management authority

Dodajemy globalny rekord `user_password_management` — jeden na Usera:

- `user_id` PK/FK `users`,
- `management_mode = unclassified | self_service | organization_managed`,
- nullable `managing_organization_id`,
- `credential_version bigint >= 0`,
- nullable `password_changed_at`,
- timestamps.

`unclassified` oraz `self_service` mają `managing_organization_id = NULL` i nie pozwalają OSK resetować hasła.

`organization_managed` wymaga dokładnie jednego `managing_organization_id`; dopiero zgodność tego Organization z LearningAccount oraz właściwa permission/scope pozwalają wejść do resetu.

Samo podpięcie istniejącego globalnego Usera do LearningAccount **nie przenosi** password-management authority.

### 22.3 Organization-managed User musi być exclusive learner principal

Self-audit ujawnił krytyczne ryzyko: gdyby OSK mogło zarządzać globalnym hasłem Usera używanego również jako pracownik, owner, konto social-login lub dostęp w innym OSK, reset hasła mógłby przejąć inne konteksty tego Usera.

Dlatego `organization_managed` ma fail-closed guard:

- zero `OrganizationMembership` dla tego Usera,
- zero LearningAccount w innym Organization,
- zero current `AuthSocialAccount`.

Guard jest final-state DB/transactional boundary i działa również przy próbie późniejszego dodania membership, cross-org LearningAccount albo social account.

Jeżeli taki principal ma zostać rozszerzony na globalną/współdzieloną identity, najpierw musi przejść jawny elevated identity/security transition do trybu, który nie pozostawia tenantowi prawa do resetu globalnego hasła. Dokładny claim/transfer/recovery flow pozostaje istniejącą osobną bramką Identity.

To nie cofa decyzji DB-LIC-002 o możliwości jednego globalnego Usera w wielu OSK. Taki User może istnieć w wielu OSK, ale wtedy **nie może pozostawać organization-managed przez jedno OSK**.

### 22.4 Credential version jest osobnym concurrency rootem

Globalne lokalne hasło ma concurrency root:

`user_password_management.credential_version`.

Każda zmiana `users.password_hash`, niezależnie czy wykonuje ją OSK czy self-service/global auth flow, musi:
- lockować ten sam management row,
- zwiększyć `credential_version` dokładnie raz.

OSK-managed set/reset wymaga expected credential version. Stale version daje conflict bez zmiany hasha, handoffu ani audytu.

`credential_version` jest monotonicznym epokiem stanu local-password, a nie wskaźnikiem „czy hash istnieje”. Non-NULL `password_hash` wymaga `credential_version >= 1`, ale po jawnym, audytowanym wyłączeniu local-password hash może być NULL przy dodatniej wersji. Takie wyłączenie — jeśli dopuści je Identity flow — również zwiększa tę samą wersję i nigdy nie resetuje jej do zera.

Reset hasła nie zwiększa `StudentLearningAccount.version`, ponieważ hasło jest globalnym credentialem Usera, a nie konfiguracją konkretnego LearningAccount.

### 22.5 Lock order i idempotency przed non-replayable effect

Single reset/set używa kolejności:

`Idempotency claim -> Student FOR UPDATE -> LearningAccount FOR UPDATE -> User FOR UPDATE -> UserPasswordManagement FOR UPDATE`.

Po lockach ponownie sprawdzamy:
- tenant/scope,
- DB-LIC-003 operational eligibility,
- matching organization-managed authority,
- exclusive-principal guard,
- expected credential version.

Idempotency-Key musi zostać zajęty przed efektem, którego sekretu nie można później replayować.

### 22.6 One-time plaintext istnieje wyłącznie w pamięci procesu

Świeże hasło przechodzi tylko przez:

`accept/generate -> validate -> hash -> optional immediate render -> durable hash+redacted metadata commit -> one-time return/stream -> discard`.

Plaintext jest zabroniony w:
- tabelach,
- FileAsset/object storage,
- audit,
- outbox,
- logach/traces,
- `idempotency_records.safe_response_snapshot`,
- QR.

Do audit/outbox/handoff nie zapisujemy również samego password hash.

Po zakończeniu commandu serwer nie ma ścieżki `pokaż stare hasło`.

### 22.7 StudentAccessHandoff przechowuje metadane, nie sekret

`student_access_handoffs` staje się trwałą historią **nie-sekretnych** handoffów/dokumentów. Finalizujemy m.in.:

- `handoff_type = initial_credentials | password_reset | credentials_document`,
- `credential_version_snapshot`,
- `contains_fresh_secret`,
- `fresh_secret_issued_at`,
- nullable `batch_id`,
- nullable `batch_ordinal`,
- istniejący nullable `document_asset_id`.

Jeżeli `contains_fresh_secret=true`:
- typ to initial/password_reset,
- credential version >= 1,
- issued timestamp jest wymagany,
- `document_asset_id` musi być NULL.

Nazwa `issued_at` jest celowa: świeże hasło może być wygenerowane przez system albo wpisane ręcznie przez uprawniony sekretariat. Handoff potwierdza moment jednorazowego wydania sekretu, ale nie przechowuje go i nie pozwala go odtworzyć.

### 22.8 Secret-bearing PDF nigdy nie jest FileAsset

PDF zawierający świeże jawne hasło:
- może powstać tylko w tym samym commandzie co create/reset,
- jest renderowany w pamięci procesu,
- nie jest zapisywany jako `FileAsset`,
- nie trafia do object storage,
- jest zwracany/streamowany tylko raz.

Późniejszy GET po `handoffId` może zwrócić wyłącznie dokument bez starego hasła: login, instrukcję i stan hasła.

Jeżeli produkt cache'uje taki **sekret-free** PDF, wymagamy osobnego purpose `student_access_credentials_nonsecret`, `ready` FileAsset i same-tenant relation z DB-LIC-001. Generator tego wariantu nie może nawet przyjmować plaintext password jako input.

Kartka z widocznym hasłem ponownie = **nowy reset, nowa credential version, nowy immediate secret render**.

QR może zawierać login URL i opcjonalny nonsecret login prefill; nigdy hasło/reset token.

### 22.9 Reset transaction i delivery failure

Przed durable write można opcjonalnie wyrenderować secret-bearing PDF w pamięci. Jeżeli render zawiedzie przed commit:
- hash się nie zmienia,
- credential version się nie zmienia,
- handoff nie powstaje.

Po commit:
- `users.password_hash` i version są trwałe,
- metadata handoff/audit/outbox istnieją,
- secret response/PDF jest przekazywany jeden raz.

Jeżeli sieć/klient zerwie odbiór już po commit, system **nie cofa** hasła i **nie zapisuje sekretu do późniejszego retry**. Retry tym samym Idempotency-Key nie robi drugiego resetu i nie replayuje hasła. Jeżeli operator potrzebuje nowej kartki z hasłem, wykonuje jawny nowy reset z nowym key i aktualną credential version.

Dokładne HTTP mapping „secret no longer replayable” pozostaje Stage 5.

### 22.10 Nonsecret reprint pozostaje dostępny

Pobranie dokumentu bez jawnego hasła:
- nie zmienia credential,
- nie zwiększa credential version,
- może działać dynamicznie albo przez secret-free FileAsset,
- wymaga właściwego download permission i target scope.

Czyli zachowujemy praktyczne `Pobierz dostęp`, ale nie udajemy, że system zna stare hasło.

### 22.11 Bulk PDF — dwa jawne tryby

Zachowujemy jeden połączony, wielostronicowy PDF i mixed locales.

Rozdzielamy:

1. `nonsecret_combined_pdf`
   - zero zmian haseł,
   - batch metadata + jeden nonsecret handoff item per selected LearningAccount.

2. `reset_and_secret_combined_pdf`
   - wymaga download permission oraz `student_access.reset_password` dla **każdego** targetu,
   - download permission sam nie może resetować credentials,
   - przed pierwszym password write wszystkie targety muszą przejść tenant/scope/eligibility/authority/version checks,
   - cały PDF jest renderowany w pamięci przed durable password writes,
   - wszystkie password mutations + batch metadata commitują all-or-none.

`regenerate_credentials_when_required` nie może oznaczać „zresetuj wszystko, czego starego hasła nie umiemy odczytać”. Stage 5 musi zamienić ten boolean na jednoznaczny command mode albo jawny reset target set.

### 22.12 Ten sam globalny User wielokrotnie w batchu

Jeśli zaznaczono kilka LearningAccounts tego samego globalnego Usera:
- grupujemy po `user_id`,
- generujemy jedno nowe hasło dla tego Usera,
- wykonujemy jeden hash write,
- jeden `credential_version +1`,
- w tym samym immediate batchu ta sama świeża wartość może znaleźć się na kilku stronach dotyczących tego samego Usera.

Nie resetujemy tego samego globalnego hasła kilka razy tylko dlatego, że ma kilka widocznych kart.

Multi-target lock order jest deterministyczny:

`Students UUID sort -> LearningAccounts UUID sort -> Users UUID sort -> PasswordManagement user UUID sort`.

### 22.13 Bulk failure jest all-or-none

Jeśli przed commit nie przejdzie choć jeden:
- tenant/scope,
- eligibility,
- management authority,
- exclusive-principal guard,
- expected credential version,
- render całego PDF,

to wynik wynosi:
- 0 zmienionych password hashes,
- 0 credential version bumps,
- 0 handoff items.

Delivery failure po commit nie daje server-side secret replay i nie wykonuje resetów ponownie przy tym samym idempotency key.

### 22.14 Audit/outbox bez sekretów

Audytujemy co najmniej:
- Organization,
- account albo batch,
- target User,
- action,
- actor,
- credential version before/after,
- request_id,
- timestamp.

Zabronione pola:
- plaintext password,
- password hash,
- secret PDF bytes,
- reset token,
- QR secret.

Finalny fizyczny shape audit/outbox pozostaje DB4_10; obowiązek redaction działa już teraz.

### 22.15 Migration safety

Przyszła migracja:
1. tworzy `user_password_management`,
2. dla istniejących Users ustawia `unclassified` — **nie zgaduje managing OSK** z LearningAccount, creatora ani ostatniej aktywności,
3. ustawia migration epoch `credential_version=0` przy NULL hash i `1` przy istniejącym hash; nie udaje historycznej liczby resetów,
4. nie zgaduje `password_changed_at`,
5. dodaje final-state guard tylko w kierunku `password_hash IS NOT NULL -> credential_version >= 1`; dodatnia wersja nie wymusza istnienia hasha, bo epoch musi przeżyć jawne wyłączenie local-password,
6. dodaje exclusive-principal guards,
7. dodaje batch i handoff metadata,
8. skanuje legacy handoff assets pod kątem potencjalnego plaintext secretu/purpose,
9. legacy secret-bearing PDF wymaga reviewed security remediation,
10. nie migruje plaintextu do ciphertext/secret table.

Nie wolno po prostu oznaczyć istniejącego dokumentu jako „nonsecret” bez dowodu.

### 22.16 Required tests

Obowiązkowe testy obejmują m.in.:
- canonical hash pozostaje na User,
- matching OSK + permission/scope + exclusive principal może resetować,
- organization-managed User nie może mieć Membership, cross-org LearningAccount ani current social auth,
- próba utworzenia któregoś z tych kontekstów wymaga wcześniejszego authority transition,
- self-service/unclassified OSK reset failuje,
- attach istniejącego Usera nie przenosi authority,
- stale credential version nie zmienia niczego,
- dwa resety z tą samą version nie commitują oba,
- każdy local-password mutation zwiększa ten sam credential epoch,
- jawne wyłączenie local-password — jeśli wspierane przez Identity flow — ustawia hash NULL, zwiększa ten sam credential epoch i nie zeruje wersji,
- plaintext nie występuje w DB/audit/outbox/log/idempotency,
- secret-bearing PDF nie trafia do FileAsset/object storage,
- later PDF GET nie odzyskuje hasła,
- reprint z hasłem wymaga resetu,
- QR nie ma secretu,
- same-key retry nie resetuje ponownie i nie replayuje secretu,
- precommit render failure zostawia stary credential,
- bulk nonsecret nie zmienia haseł,
- bulk reset wymaga reset permission dla każdego targetu,
- bulk failure zmienia zero credentials,
- kilka kont tego samego Usera daje jeden reset/epoch,
- archived/ineligible LearningAccount nie przechodzi reset/sensitive export,
- migracja nie zgaduje managing OSK i nie zachowuje legacy secret PDF bez review.

### 22.17 Scope preservation

Self-audit potwierdził:
- DB-LIC-001..003 pozostają PASS,
- DB-LIC-005..007 pozostają OPEN,
- nie zmieniono inventory/assignment lifecycle,
- nie zamknięto activation stacking/entitlement history,
- nie zamknięto language capability/projection,
- global login authority DB-LIC-002 pozostaje jedna,
- organization-managed credential authority nie tworzy tenantowego login namespace,
- credential epoch pozostaje monotoniczny także po jawnym wyłączeniu local-password,
- exact identity authority transfer/claim/recovery pozostaje odrębną bramką Identity,
- exact password algorithm/cost pozostaje security implementation policy,
- exact HTTP secret-PDF/expected credential version pozostaje Stage 5,
- agregaty `core-schema.yml` i `docs/87` pozostają zamrożone,
- brak migracji Laravel, DB4_7, Stage 5 i UI.

Aktualny stan po DB-LIC-004:
- resolved: **4/7**,
- open P0: **0**,
- open P1: **3**,
- DB4_6: **FAIL_WITH_3_P1_BLOCKERS**.

Następny dozwolony krok po centralnym gate: **DB-LIC-005 only**.

**STOP przed DB-LIC-005.**

---

## 23. DB-LIC-005 — resolution contract and self-audit

**Current result: PASS.**

Ten krok zamyka wyłącznie lifecycle konkretnej jednostki `LicenseInventoryEntry`, lifecycle `LicenseAssignment`, atomowe przydzielenie/cofnięcie przed aktywacją oraz wspólną granicę concurrency dla `activate vs revoke`. Nie zamyka jeszcze matematyki entitlementu, snapshotu efektu aktywacji ani language capability.

### 23.1 Jedna pozycja inventory = jedna konkretna sztuka

`license_inventory_entries` nie jest licznikiem. Jeden rekord reprezentuje dokładnie jedną jednostkę licencji.

Canonical statusy inventory:
- `available`,
- `assigned`,
- `consumed`,
- `expired`,
- `adjusted`.

Normalny lifecycle przypisania używa wyłącznie:

`available -> assigned -> consumed`

albo przed aktywacją:

`available -> assigned -> available`.

Drugie przejście oznacza jawny `revoke-unactivated` i zwrot dokładnie **tej samej jednostki**, a nie zwiększenie luźnego licznika czy utworzenie replacement row.

`consumed` nie jest ponownie assignable. `expired` i `adjusted` również nie są targetem zwykłego assignment flow.

Dokładne operacje grant/expiry/adjustment inventory pozostają poza tym fixerem — do DB4_9 lub osobnej jawnej polityki. Historycznego `consumed` nie wolno przepisywać na `adjusted`; ewentualny refund/replacement po wykorzystaniu musi być osobnym kompensującym efektem biznesowym.

### 23.2 Zamknięty lifecycle Assignment

Canonical statusy `license_assignments.status`:
- `assigned`,
- `activated`,
- `revoked_before_activation`.

Nowy Assignment zaczyna jako:
- `status=assigned`,
- `version=1`,
- `assigned_at` non-NULL i immutable,
- `assigned_by_user_id` non-NULL, globalny FK do Usera z RESTRICT.

`license_assignments.version bigint >= 1` jest concurrency rootem stanu Assignment.

Normalne przejścia:
- create: `none -> assigned`,
- activate: `assigned -> activated`,
- revoke: `assigned -> revoked_before_activation`.

`activated` i `revoked_before_activation` są terminalne w normalnym lifecycle. Starego revoked Assignment nie reaktywujemy przy ponownym użyciu odzyskanej sztuki — powstaje nowy Assignment row.

### 23.3 State-field matrix

`assigned`:
- zero revoke metadata,
- zero Activation rows.

`activated`:
- zero revoke metadata,
- dokładnie jeden Activation row.

`revoked_before_activation`:
- `revoked_at` non-NULL,
- `revoked_by_user_id` non-NULL,
- `revoke_reason` nonblank,
- zero Activation rows.

Generic PATCH nie może ustawiać statusu. Normalny hard-delete Assignment jest zabroniony.

### 23.4 `activated` nie znaczy „ważna dzisiaj”

Status `activated` jest historycznym faktem:

**ta konkretna jednostka inventory została nieodwracalnie zużyta przez aktywację.**

Nie jest to temporalny flag „kursant ma dziś aktywny dostęp”. Po wygaśnięciu entitlementu Assignment nadal pozostaje `activated`, a Inventory nadal `consumed`.

Bieżąca ważność, expiry i remaining time będą wyliczane z immutable entitlement history w DB-LIC-006.

### 23.5 Current Assignment i historia

Current Assignment dla konkretnej jednostki inventory to row spełniający:

`status IN ('assigned','activated') AND revoked_at IS NULL`.

Wymagamy partial unique:

`(organization_id, license_inventory_entry_id)` dla tego predykatu.

To daje maksymalnie jeden current Assignment na jednostkę, ale pozwala zachować wiele historycznych `revoked_before_activation` dla tej samej sztuki po kolejnych cyklach przydzielenie → cofnięcie → ponowne przydzielenie.

Po `consumed` current Assignment jest permanentnie tym `activated` row — aż do końca historii jednostki; nie jest zwalniany przez samo wygaśnięcie czasowego dostępu.

### 23.6 Cross-row final-state equivalence

Partial unique nie wystarcza. Dodajemy `DEFERRABLE INITIALLY DEFERRED` constraint trigger lub równoważną final transactional DB boundary.

W stanie finalnym:
- `inventory.available` → zero current Assignment,
- `inventory.assigned` → dokładnie jeden current Assignment w `assigned`, zero Activation,
- `inventory.consumed` → dokładnie jeden current Assignment w `activated`, dokładnie jeden Activation,
- `inventory.expired|adjusted` → zero current Assignment.

W drugą stronę:
- `assignment.assigned` wymaga `inventory.assigned` i zero Activation,
- `assignment.activated` wymaga `inventory.consumed` i dokładnie jeden Activation,
- `assignment.revoked_before_activation` wymaga zero Activation, ale nie narzuca na zawsze stanu inventory, ponieważ ta sama sztuka może później zostać przypisana ponownie przez nowy row.

Bezpośrednia zmiana statusu, która pozostawia rozjazd, nie może commitować.

### 23.7 Concurrency roots i lock order

Dla alokacji konkretnej sztuki finalną granicą jest:

`license_inventory_entries row FOR UPDATE`.

Nie dodajemy osobnego `inventory.version`, ponieważ wszystkie create/revoke/activate dla tej sztuki serializują się na dokładnie tym samym row i końcowym cross-row guardzie.

Dla istniejącego Assignment:

`license_assignments.version + Assignment row FOR UPDATE`.

Activate i revoke wymagają expected assignment version na granicy domenowej. Stage-3 POST-y nie mają jeszcze `If-Match`, więc dokładny required header/error mapping jest markerem do Stage 5. `Idempotency-Key` nie zastępuje optimistic concurrency.

Wspólny porządek:

`Student -> LearningAccount -> InventoryEntry -> Assignment`.

DB-LIC-006 może dopiero po tym prefixie dołożyć własne locki/operacje entitlementu; nie może zmienić kolejności.

### 23.8 Assignment do istniejącego LearningAccount

`POST /license-assignments`:
- wymaga `licenses.assign`,
- wymaga Idempotency-Key,
- zachowuje exactly-one target,
- po lockach wymaga exact Student/Account/Organization z DB-LIC-001,
- wymaga operational eligibility z DB-LIC-003,
- lockuje wskazany InventoryEntry,
- wymaga `inventory.status=available` i zero current Assignment.

Sukces atomowo:
1. tworzy Assignment `assigned`, version 1, z actor/time,
2. ustawia tę samą jednostkę InventoryEntry na `assigned`,
3. zapisuje redacted audit/outbox,
4. finalizuje idempotency,
5. przechodzi deferred final-state guard.

Dwa równoległe assignmenty tej samej jednostki — maksymalnie jeden może commitować.

### 23.9 Create-new-learning-account + assignment jest jednym outer transaction

Potwierdzony flow z nowym dostępem nie może tworzyć sieroty.

W jednym outer transaction znajdują się:
- Student parent lock,
- identity/LearningAccount z DB-LIC-002/003,
- opcjonalne OSK-managed credential z DB-LIC-004,
- InventoryEntry lock,
- Assignment insert i inventory state transition.

Jeżeli końcowy assignment nie może commitować, nowe konto/identifier/User — jeśli były tworzone wyłącznie dla tego flow — oraz credential mutation są rollbackowane.

Świeży sekret DB-LIC-004 może zostać zwrócony operatorowi dopiero po commit całej operacji. Nie może być wydany po częściowo udanym create-account przed nieudanym assignmentem.

### 23.10 Idempotency assignmentu

Ten sam Idempotency-Key + ten sam request:
- nie tworzy drugiego Assignment,
- nie wykonuje drugi raz `available -> assigned`,
- zwraca rezultat oryginalnej operacji.

Ten sam key + inny payload → conflict.

Inny key po pierwszym sukcesie → konflikt, bo InventoryEntry nie jest już `available`.

### 23.11 Revoke-unactivated = zwrot tej samej sztuki dokładnie raz

`POST /license-assignments/{assignmentId}/revoke-unactivated` wymaga:
- permission `licenses.revoke_unactivated`,
- Idempotency-Key,
- expected Assignment version.

Po wspólnych lockach wymagamy:
- exact tenant/target integrity,
- Assignment `assigned`,
- Inventory `assigned`,
- zero Activation,
- current Assignment to dokładnie wskazany row,
- zgodna expected version.

Sukces atomowo:
- Assignment → `revoked_before_activation`,
- zapisuje `revoked_at`, actor i nonblank reason,
- Assignment version +1,
- ta sama jednostka Inventory → `available`,
- audit/outbox/idempotency,
- deferred final-state guard.

To jest znaczenie „restore exactly one”. Nie tworzymy nowej jednostki i nie inkrementujemy niezależnego counta.

Same-key retry nie robi drugiego zwrotu ani version bump. Nowy key wobec już revoked Assignment kończy się konfliktem bez zmiany inventory.

### 23.12 Jawny revoke pozostaje możliwy po archive/suspension

Self-audit poprawił tu pierwszy draft.

Student archive z DB-LIC-003 nadal:
- nie revokuje automatycznie Assignment,
- nie zwraca automatycznie Inventory.

Jednak uprawniony administrator może później wykonać **jawny `revoke-unactivated`** także dla archived Student albo suspended LearningAccount, jeśli cały tenant/state/version check przechodzi i Assignment nadal jest nieaktywowany.

Powód: archive/suspension nie może uwięzić niewykorzystanej konkretnej sztuki na zawsze.

Ten revoke:
- nie restore'uje Studenta,
- nie resume'uje LearningAccount,
- nie zmienia globalnej identity,
- tylko zamyka historyczny nieaktywowany Assignment i zwraca jego InventoryEntry.

Nowy assignment i activation nadal wymagają normalnej operational eligibility.

### 23.13 Minimalna activation boundary w DB-LIC-005

Żeby zamknąć `activate vs revoke`, DB-LIC-005 musi określić minimalny niepodzielny efekt aktywacji na lifecycle jednostki, ale **nie** matematykę entitlementu.

Po wspólnych lockach successful activation musi w jednej transakcji:
- utworzyć dokładnie jeden Activation row,
- Assignment `assigned -> activated`, version +1,
- Inventory `assigned -> consumed`,
- przejść final-state guard.

DB-LIC-005 nie określa jeszcze:
- `effective_from`,
- `effective_to`,
- duration snapshot,
- stacking,
- expiry_before/after,
- learner-vs-OSK actor provenance.

To pozostaje wyłącznie DB-LIC-006.

### 23.14 Activate vs revoke — dokładnie jeden winner

Oba commandy używają tych samych rows i tego samego porządku locków.

Jeśli activation wygra:
- Assignment = `activated`,
- Inventory = `consumed`,
- Activation count = 1,
- późniejszy revoke kończy się conflict/no restore.

Jeśli revoke wygra:
- Assignment = `revoked_before_activation`,
- Inventory = `available`,
- Activation count = 0,
- późniejsza activation kończy się conflict/no consume.

Nie istnieje finalny stan, w którym ta sama licencja została jednocześnie zużyta i zwrócona.

### 23.15 Migration safety

Przyszła migracja najpierw precheckuje i zatrzymuje się na sprzecznym legacy data.

W szczególności nie wolno:
- automatycznie revokować live Assignment tylko dlatego, że Inventory jest `available`,
- tworzyć brakującego Assignmentu dla `assigned` Inventory,
- fabrykować Activation dla `consumed`,
- zgadywać revoke actor/time/reason,
- mapować statusów po display text albo `latest row`.

Niejednoznaczność = FAIL + reviewed remediation.

Dopiero potem dodajemy closed checks, Assignment version, current partial unique, state-field matrix i deferred final-state equivalence.

### 23.16 Required race/invariant tests

Obowiązkowe testy obejmują m.in.:
- unknown Inventory/Assignment status → reject,
- nowy Assignment = assigned/version1 + actor/time i Inventory=assigned,
- available + current Assignment → reject przy commit,
- assigned bez dokładnie jednego assigned current Assignment → reject,
- consumed bez dokładnie jednego activated Assignment + Activation → reject,
- expired/adjusted z current Assignment → reject,
- dwa równoległe assignmenty jednej sztuki → maksymalnie jeden commit,
- create-new-account + assignment → all-or-none,
- nieudany assignment po OSK-managed credential create → brak wydanego sekretu i rollback,
- revoke zwraca tę samą sztukę raz,
- revoke archived/suspended targetu jest jawnie możliwy i nie reaktywuje targetu,
- retry revoke nie zwraca drugi raz,
- activation vs revoke → dokładnie jeden winner,
- historyczny revoked row przetrwa późniejszy reassign tej sztuki,
- activated row pozostaje activated po późniejszym expiry entitlementu,
- direct SQL drift nie przechodzi deferred guard,
- migracja nie fabrykuje brakujących eventów/metadanych.

### 23.17 Scope preservation

Self-audit potwierdził:
- DB-LIC-001..004 pozostają PASS,
- DB-LIC-006 i DB-LIC-007 pozostają OPEN,
- nie zamknięto effective-period/stacking/duration/effect snapshot ani activation actor provenance,
- nie zamknięto product-language capability ani panel projection rules,
- nie zaprojektowano purchase/payment/inventory grant z DB4_9,
- `source_order_item_id` tenant/commercial boundary nadal pozostaje DB4_9,
- agregaty `core-schema.yml` i `docs/87` pozostają zamrożone,
- nie utworzono migracji Laravel,
- nie rozpoczęto DB4_7, Stage 5 ani UI.

Aktualny stan po DB-LIC-005:
- resolved: **5/7**,
- open P0: **0**,
- open P1: **2**,
- DB4_6: **FAIL_WITH_2_P1_BLOCKERS**.

Następny dozwolony krok po centralnym gate: **DB-LIC-006 only**.

**STOP przed DB-LIC-006.**

---

## 24. DB-LIC-006 — resolution contract and self-audit

**Current result: PASS.**

Ten krok zamyka wyłącznie aktywację, stacking, immutable entitlement history, concurrency i reprodukowalność historycznego efektu licencji. Nie rozstrzyga jeszcze product-language capability ani finalnych projekcji panelu z DB-LIC-007 i nie wchodzi w pricing/payment/inventory grant DB4_9.

### 24.1 Activation jest immutable entitlement ledgerem

`license_activations` nie jest mutable projection. Każdy row jest niezmiennym, append-only efektem jednej aktywacji konkretnego Assignmentu.

Normalny UPDATE i DELETE Activation są zabronione. Nadal obowiązuje exactly-one Activation per Assignment z DB-LIC-005.

Finalizujemy pola:
- `student_learning_account_id`,
- `entitlement_sequence`,
- `activation_origin`,
- `duration_snapshot_source`,
- `duration_days_snapshot`,
- `expiry_before`,
- `activated_by_user_id`,
- `activated_at`,
- `effective_from`,
- `effective_to`,
- `created_at`.

Nie dodajemy osobnego `expiry_after`: canonical `expiry_after` to `effective_to`.

Nie duplikujemy również `license_product_id` w Activation. Canonical product identity pozostaje ścieżką:

`Activation -> Assignment -> InventoryEntry -> LicenseProduct`.

### 24.2 Activation musi wskazywać dokładny LearningAccount Assignmentu

Dodajemy/reużywamy candidate key:

`license_assignments(organization_id,id,student_learning_account_id)`.

Activation ma composite FK:

`license_activations(organization_id,license_assignment_id,student_learning_account_id)`

`-> license_assignments(organization_id,id,student_learning_account_id)`.

Dzięki temu nie da się zapisać efektu entitlementu Assignmentu A pod innym LearningAccount w tym samym OSK ani w innym tenant.

### 24.3 Entitlement sequence porządkuje immutable historię

Każdy LearningAccount ma sekwencję:

`entitlement_sequence = 1, 2, 3, ...`

z unique:

`(organization_id,student_learning_account_id,entitlement_sequence)`.

Pierwszy row ma sequence 1. Kolejny numer to poprzedni max + 1, obliczony dopiero pod lockiem LearningAccount.

Sequence nie może mieć luk ani duplikatów. Nie jest drugim optimistic concurrency rootem — służy wyłącznie do jednoznacznego porządku immutable effect history.

### 24.4 Duration produktu jest częścią wartości prawa

Canonical runtime duration pozostaje:

`license_products.duration_days`.

Wymagamy dodatniej liczby całych dni. Dla tego kontraktu jeden entitlement day oznacza dokładnie `86400` sekund.

Po utworzeniu choć jednej jednostki Inventory wskazującej dany LicenseProduct nie wolno zmieniać `duration_days` tego produktu w miejscu. Granica DB to `BEFORE UPDATE` trigger lub równoważny guard, który odrzuca zmianę duration, jeśli istnieje referencja Inventory.

Zmiana oferowanego czasu oznacza nowy `LicenseProduct` row.

Tak samo `license_inventory_entries.license_product_id` jest immutable po utworzeniu jednostki i chroniony DB przed przepięciem produktu.

Dezaktywacja produktu katalogowego nie unieważnia prawa już przypisanego w Inventory. Pricing, payment i commercial grant pozostają DB4_9.

### 24.5 Runtime zapisuje duration snapshot

Przed aktywacją stabilnie odczytujemy/lockujemy LicenseProduct i zapisujemy:

`duration_snapshot_source = product_at_activation`

oraz

`duration_days_snapshot = locked license_products.duration_days`.

Historyczny Activation nie zależy później od aktualnej tabeli produktu przy obliczaniu swojego efektu.

To daje reprodukowalność: po latach wiemy, ile dni dokładnie zastosował konkretny Activation.

### 24.6 Migration-only source nie może wejść do runtime

Dla danych legacy dopuszczamy drugi source:

`legacy_effect_reconstructed`.

Tylko podczas kontrolowanego backfillu można odtworzyć snapshot z istniejącego `effective_from -> effective_to`, i tylko jeśli interval jest dodatnią, dokładną wielokrotnością 86400 sekund.

`duration_snapshot_source` ma zamknięty katalog:
- `product_at_activation`,
- `legacy_effect_reconstructed`.

Po legacy backfill, przed runtime cutover, włączamy DB-level `BEFORE INSERT`/equivalent migration-only guard. Nowy row nie może użyć `legacy_effect_reconstructed`.

Istniejące zaimportowane legacy rows pozostają legalne, ale immutable.

### 24.7 Activation origin rozróżnia learner i OSK

`activation_origin` ma zamknięty katalog:
- `learner_self`,
- `organization_user`,
- `legacy_unknown`.

Runtime dopuszcza tylko pierwsze dwa.

Dla `learner_self`:
- `activated_by_user_id` jest wymagany,
- musi wskazywać dokładnie `student_learning_accounts.user_id`,
- nie tworzymy fikcyjnego Staff/Organization actor.

Dla `organization_user`:
- realny `activated_by_user_id` jest wymagany,
- command musi przejść `licenses.activate` i tenant/scope authorization.

`legacy_unknown` jest migration-only. Nie zgadujemy origin po dzisiejszym Membership ani po tym, że actor przypadkiem równa się LearningAccount User.

Tak jak dla legacy duration, po backfillu DB-level insert guard blokuje nowe runtime/direct-SQL rows z `legacy_unknown`.

### 24.8 Deferred entitlement-chain guard

Sam unique sequence nie wystarcza. Wymagamy `DEFERRABLE INITIALLY DEFERRED` constraint triggera lub równoważnej transactional DB boundary per:

`(organization_id,student_learning_account_id)`.

Finalny łańcuch musi spełniać:
- sequence ciągła od 1,
- sequence 1 ma `expiry_before IS NULL`,
- sequence > 1 ma `expiry_before = previous.effective_to`,
- runtime `activated_at` pochodzi z jednego `command_effective_at` uchwyconego po wymaganych lockach,
- `effective_from = greatest(activated_at, expiry_before)` dla kolejnych efektów,
- `effective_to = effective_from + duration_days_snapshot * 86400 seconds`,
- `effective_to > effective_from`.

Bezpośredni insert z luką sequence, błędnym predecessor albo ręcznie wymyślonym końcem nie może commitować.

### 24.9 Dokładna reguła stackingu

Brak wcześniejszego entitlementu:

`expiry_before = NULL`

`effective_from = activated_at`.

Jeżeli poprzedni entitlement jeszcze trwa:

`expiry_before = previous.effective_to`

`effective_from = previous.effective_to`.

Pełna nowa duration jest dokładana **po aktualnym końcu** — kursant nie traci pozostałych dni.

Jeżeli poprzedni entitlement już wygasł:

`expiry_before = previous.effective_to`

`effective_from = activated_at`.

Nie uzupełniamy retroaktywnie wygasłej luki.

Własna runtime rule produktu w tym kontrakcie to `always stack`; nie importujemy niezaobserwowanych wyjątków konkurenta.

### 24.10 Current entitlement nie ma drugiej mutable authority

Nie dodajemy niezależnego `current_expires_at` na LearningAccount.

Canonical bieżący koniec to:

`MAX(license_activations.effective_to)` per LearningAccount.

Brak Activation rows daje `NULL`.

Temporalnie entitlement jest live, jeśli ten koniec jest większy od czasu odczytu, ale realne operational access nadal musi równocześnie spełnić DB-LIC-003 eligibility.

Cache tej wartości może istnieć tylko jako derived, rebuildable, non-authoritative projection.

### 24.11 Archive/suspension nie zatrzymuje zegara

Student archive ani LearningAccount suspension:
- nie modyfikują Activation rows,
- nie przesuwają `effective_to`,
- nie pauzują czasu,
- nie przedłużają entitlementu.

Blokują nowe operational effects zgodnie z DB-LIC-003, ale istniejący okres nadal starzeje się po wall clock.

Po upływie końca Assignment pozostaje `activated`, a Inventory `consumed`, zgodnie z DB-LIC-005.

### 24.12 Activation transaction

`POST /license-assignments/{assignmentId}/activate` zachowuje Idempotency-Key i expected Assignment version.

Lock order:
1. claim Idempotency-Key,
2. Student `FOR UPDATE`,
3. LearningAccount `FOR UPDATE`,
4. InventoryEntry `FOR UPDATE`,
5. Assignment `FOR UPDATE`,
6. LicenseProduct `FOR SHARE` lub równoważny stable read.

Po lockach ponownie wymagamy:
- DB-LIC-003 operational eligibility,
- Assignment `assigned`,
- Inventory `assigned`,
- zero Activation,
- current Assignment = target,
- expected Assignment version zgodna,
- dodatni `duration_days`.

Dopiero wtedy wyliczamy sequence, predecessor expiry, `effective_from`, snapshot duration, `effective_to` i provenance.

Sukces atomowo:
- insertuje jeden immutable Activation,
- Assignment `assigned -> activated` + version 1x,
- Inventory `assigned -> consumed`,
- audit/outbox/idempotency,
- przechodzi DB-LIC-005 final-state guard,
- przechodzi DB-LIC-006 entitlement-chain guard.

Activation nie zwiększa `StudentLearningAccount.version`, bo entitlement history nie jest konfiguracją konta.

### 24.13 Dwa równoległe extension nie gubią czasu

Serialization root dla aktywacji różnych Assignmentów tego samego LearningAccount to:

`student_learning_accounts row FOR UPDATE`.

Dwa równoległe Assignmenty mogą oba zostać aktywowane, ale serialnie:
- pierwszy pod lockiem tworzy następny effect,
- drugi po uzyskaniu locka widzi już `effective_to` pierwszego,
- dostaje kolejny sequence,
- dokłada pełną własną duration po nowym końcu.

Nie ma lost update ani utraty dni.

Same Assignment nadal nie może zostać aktywowany dwa razy dzięki DB-LIC-005 state/version + unique Activation.

Activation vs revoke pozostaje dokładnie-one-winner z DB-LIC-005.

### 24.14 Idempotency aktywacji

Ten sam key + ten sam request po sukcesie:
- nie tworzy drugiego Activation,
- nie zajmuje kolejnego sequence,
- nie robi kolejnego Assignment version bump,
- nie konsumuje Inventory drugi raz,
- nie przedłuża entitlementu drugi raz,
- zwraca bezpieczny rezultat pierwszej operacji.

Ten sam key + inny request → conflict.

Nowy key po już wykonanej aktywacji również nie stosuje kolejnego efektu; Assignment nie jest już `assigned`.

### 24.15 Audit i reprodukowalność

Activation row jest primary historycznym dowodem entitlement effect.

Audit/outbox w tej samej transakcji musi móc wskazać co najmniej:
- Organization,
- Assignment,
- LearningAccount,
- sequence,
- origin i actor,
- duration source/snapshot,
- `expiry_before`,
- `expiry_after = effective_to`,
- `activated_at`,
- `effective_from`,
- `effective_to`,
- request_id.

Finalny fizyczny model audit/outbox nadal pozostaje DB4_10.

### 24.16 Migration safety

Legacy activation history nie jest przepisywana tak, by pasowała do nowej reguły.

Migracja może backfillować nowy chain tylko, jeśli istniejące dane dają jednoznaczny porządek i efekt. Zabronione jest:
- wybieranie orderu tylko po `created_at` albo UUID,
- branie dzisiejszego product duration, jeśli nie zgadza się z istniejącym historycznym efektem,
- przepisywanie starych `effective_from/effective_to`,
- zgadywanie learner-vs-OSK origin z obecnych Memberships,
- fabrykowanie sequence lub snapshotu przy niejednoznaczności.

Niejednoznaczny chain albo interval, którego nie da się bezstratnie zrekonstruować jako dodatnią liczbę pełnych 86400-sekundowych dni, daje migration FAIL + reviewed remediation.

Po backfillu włączamy closed catalogs, immutability, runtime product snapshot validation, deferred chain guard i migration-only insert guard **przed** runtime cutover.

### 24.17 Required tests i self-audit

Obowiązkowe testy obejmują m.in.:
- `duration_days > 0`,
- product duration nie może zmienić się po referencji Inventory,
- Inventory nie może zostać przepięte na inny produkt,
- runtime snapshot = locked product duration,
- unknown origin/source → reject,
- legacy-only origin/source → runtime/direct-SQL insert reject po cutover,
- migrated legacy rows pozostają legalne i immutable,
- Activation wskazuje exact Assignment/LearningAccount,
- sequence jest unique i contiguous od 1,
- pierwszy effect ma NULL predecessor,
- extension przed expiry zachowuje wszystkie pozostałe dni,
- activation po expiry startuje w command time i nie wypełnia luki,
- `effective_to` odpowiada dokładnie snapshot duration,
- Activation nie może być update/delete,
- dwa concurrent extensions tego samego LearningAccount nie gubią czasu,
- same Assignment nie aktywuje się dwa razy,
- retry nie stosuje entitlementu drugi raz,
- archive/suspension blokują nową aktywację, ale nie pauzują starej,
- learner self ma rzeczywistego Usera LearningAccount,
- OSK activation ma realnego actor + permission/scope,
- current entitlement end pochodzi z `MAX(effective_to)`, nie mutable projection,
- migration nie zgaduje chain/duration/origin ani nie przepisuje starych czasów.

Self-audit potwierdził również:
- DB-LIC-001..005 pozostają PASS,
- DB-LIC-007 pozostaje OPEN,
- product-language capability, assignment language snapshot i panel projection rules nie zostały naprawione przedwcześnie,
- ceny, płatności i commercial inventory grant pozostają DB4_9,
- agregaty `core-schema.yml` i `docs/87` pozostają zamrożone,
- brak migracji Laravel, DB4_7, Stage 5 i UI.

Aktualny stan po DB-LIC-006:
- resolved: **6/7**,
- open P0: **0**,
- open P1: **1**,
- DB4_6: **FAIL_WITH_1_P1_BLOCKER**.

Następny dozwolony krok po centralnym gate: **DB-LIC-007 only**.

**STOP przed DB-LIC-007.**
