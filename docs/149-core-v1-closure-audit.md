# 149. Core v1 closure audit — repository completion vs deployment/external blockers

Data: 2026-09-13

**Gate:** `CORE-V1-CLOSURE-AUDIT-001`

**Wynik:** `AUDIT_COMPLETE_REPO_P1_REMAINS`

**P0 repo:** 0

**P1 repo:** pozostają — core v1 nie może jeszcze otrzymać statusu repository-complete.

PKK provider runtime pozostaje `FROZEN_UNTIL_EXPLICIT_UNFREEZE`.

## 1. Cel audytu

Po zamknięciu FORMAL-DOC-011 wszystkie dotychczasowe slice'y miały własne lokalne PASS. Ten audyt sprawdza inną rzecz: czy suma lokalnych PASS rzeczywiście daje kompletne core v1 repozytorium.

Audyt rozdziela trzy klasy:

1. **repo-actionable P1** — brak kodu/migracji/API/UI wymagany przez aktywny core baseline lub potwierdzone screen evidence;
2. **deployment / production evidence** — kod może być kompletny, ale produkcja wymaga rzeczywistego środowiska, kontaktów, schedulerów, alertów albo restore drill;
3. **external-provider authority** — nie wolno implementować szczegółów bez autorytatywnego kontraktu zewnętrznego.

Lokalny `PASS` bounded-contextu nie jest automatycznie dowodem kompletności całego produktu.

## 2. Główne ustalenie

Central gate potwierdza PASS dla zmaterializowanych tranche:

- Locations / Staff / Vehicles,
- Students / CourseEnrollment,
- Calendar / TrainingSession / HourLedger,
- Student Finance,
- Learning Access / Licenses,
- Internal Exams,
- Dashboard / Notifications / Purchase History,
- hardening,
- Stage 5 foundation,
- Formal Documents 002–011.

Jednocześnie ich własne closure records zawierają jawne deferred dependencies. Część została później zamknięta, ale część nadal istnieje w accepted tree.

Dlatego finalny wynik audytu to:

`AUDIT_COMPLETE_REPO_P1_REMAINS`

a nie `CORE_V1_COMPLETE`.

## 3. Repo-actionable P1

### CORE-CLOSE-P1-DB — Stage 4 migration materialization incomplete

Canonical Stage-4 DAG ma **170 node'ów**.

Accepted repo materializuje **112 node'ów**:

- 1 extension,
- 111 table nodes.

Brakuje **58 node'ów**:

- 6 table,
- 8 candidate_key,
- 10 index,
- 11 foreign_key,
- 10 constraint,
- 9 trigger,
- 4 projection.

Brakujące table nodes:

- `MIG-TBL-LEGAL_DOCUMENTS`,
- `MIG-TBL-AUTH_SOCIAL_ACCOUNTS`,
- `MIG-TBL-ACCOUNT_CLOSURE_REQUESTS`,
- `MIG-TBL-TERMS_ACCEPTANCES`,
- `MIG-TBL-EVENT_PROJECTION_MIGRATION_CASES`,
- `MIG-TBL-DATA_RETENTION_EXECUTION_RUNS`.

To nie są wyłącznie production cutover steps. Brakuje fizycznych core tables oraz finalnych candidate keys, indeksów, FK, constraints, triggers i projections wymaganych przez `specs/database/final-migration-order-invariant-matrix.yml`.

Naprawa musi respektować canonical DAG i siedem faz. Nie wolno zbiorczo „dodać final constraints” bez preflight/write-fence/validate semantics.

**Pierwszy corrective step:** zmaterializować dokładnie sześć brakujących table nodes w `expand`, bez ruszania późniejszych faz.

### CORE-CLOSE-P1-SETTINGS — Ustawienia OSK są tylko foundation

Potwierdzony core route `/ustawienia` nie istnieje w `routes/web.php` ani w Vue routing.

Istnieje `OrganizationSettingsService`, ale nie ma pełnego HTTP/UI slice pokrywającego:

- dane podstawowe użytkownika,
- dane firmy,
- structured organization contact address,
- current primary email projection,
- PKK integration-settings projection zgodnie z freeze boundary,
- accepted terms / regulation link,
- optimistic concurrency całego settings aggregate.

`specs/traceability/implementation/OrganizationSettings.yml` nadal ma UI `DEFERRED_UI_UNTIL_FEATURE_SLICE`.

To jest repo P1.

### CORE-CLOSE-P1-COMMERCE — checkout dla licencji i egzaminów nie jest zmaterializowany

Potwierdzone core entry points:

- `/licencje/wykup`,
- `/egzamin-wewnetrzny/wykup`.

Nie mają runtime routes/UI.

Brakuje provider-neutral order creation i server-side catalog pricing dla:

- `POST /license-orders`,
- `POST /internal-exam/orders`.

Obecne License/Internal Exam UI jawnie komunikuje, że zakup jest odroczony do Commerce.

Commerce slice potwierdza, że:

- purchase history i pending payment attempt działają,
- checkout/order creation pozostaje deferred,
- provider network truth nie został wymyślony.

Repository musi materializować provider-neutral checkout/order snapshot/fulfillment boundary. Provider-specific online payment callback pozostaje osobnym external boundary.

### CORE-CLOSE-P1-LEARNING-DOCUMENTS — credential PDFs incomplete

Canonical requirements zawierają:

- single learning-access credential PDF,
- bulk combined access PDF.

Brak runtime routes:

- `GET /students/{studentId}/learning-accounts/{accountId}/access-handoffs/{handoffId}/pdf`,
- `POST /learning-accesses/bulk-access-document`.

Single PDF musi respektować secret-safe handoff policy: nie wolno odzyskiwać starego plaintext password. Bulk PDF musi renderować jeden połączony, lokalizowany dokument dla autoryzowanego zestawu learning accesses.

### CORE-CLOSE-P1-PROGRESS — zweryfikowany Student Progress nie jest materializowany

`specs/screens/student-progress.yml` ma confidence `USER_CONFIRMED_AUTH_SCREEN`.

W accepted UI zakładka „Postęp” jest nadal `disabled`.

Brakuje canonical runtime:

`GET /students/{studentId}/progress`.

Wymagane minimum to read-only, tenant/student-scoped projection z:

- learning-account selector,
- category selector,
- tests/questions summary,
- handbook progress,
- lecture progress,
- basic/specialized taxonomy breakdown,
- dynamic totals.

Nie wolno hardkodować zaobserwowanych demo totals.

### CORE-CLOSE-P1-RESOURCE-ASSETS — UploadsAssets nie jest podpięte do Resource UI

FORMAL-DOC-010 zmaterializował client upload purposes:

- `staff_photo`,
- `vehicle_photo`,
- `vehicle_document`.

Jednak Resource UI nadal tylko zapamiętuje nazwę wybranego pliku i mówi, że transport zostanie podłączony później.

Wymagane jest podpięcie canonical:

`presign -> private PUT -> complete -> resource mutation`.

Dodatkowo istnieją vehicle-document APIs, ale własny Vehicle UI nie materializuje zarządzania wersjonowanymi dokumentami.

### CORE-CLOSE-P1-COURSE-COMPLETION — training_completed UI nadal jest sztucznie zablokowane

Backendowe źródła wymaganych dowodów są już zmaterializowane:

- formal Training Hour Ledger,
- Internal Exam evidence.

Jednak Student/Course UI nadal oznacza `training_completed` jako blocked i pokazuje stary komunikat, że completion zostanie odblokowane dopiero po podpięciu tych modułów.

UI powinno wywoływać istniejący server-side stage transition i pozwolić backendowi fail-closed rozstrzygnąć aktualną eligibility.

### CORE-CLOSE-P1-API-RUNTIME — canonical API ma jeszcze repo-local operations bez HTTP binding

Po odjęciu:

- świadomie zamrożonych PKK provider operations,
- provider-specific payment webhook/network truth,
- parser artifacts dla już istniejących operations,

repo nadal ma canonical operations bez runtime binding. Najważniejsze:

- auth/session lifecycle,
- `GET /languages`,
- settings/organization operations,
- progress,
- credential PDFs,
- license/exam order create,
- service-entitlements operations,
- audit-log read API.

Każda pozycja musi otrzymać jeden z wyników podczas corrective tranches:

- `IMPLEMENTED`,
- `DEFERRED_OUTSIDE_CORE_WITH_EXPLICIT_DECISION`,
- `EXTERNAL_PROVIDER_BOUNDARY`.

Nie wolno uznać samego OpenAPI operationId za dowód runtime.

## 4. External / provider blockers — nie są repo P1

### PKK / PWPW

Pozostaje zamrożone:

- provider adapter,
- live PWPW calls,
- provider status/error mapping,
- provider-specific idempotency/reconciliation,
- signing/XML semantics,
- mutating provider-backed PKK UI.

Resume tylko po:

- explicit user unfreeze,
- authoritative PWPW guidance/contract,
- verified onboarding/access requirements,
- legal/security review.

### Online payment provider truth

Repo może i powinno materializować provider-neutral checkout, orders, payment attempts, settlement/fulfillment authorities.

Nie może natomiast wymyślić:

- webhook signature scheme,
- remote status protocol,
- provider-specific callback truth,
- network reconciliation semantics.

Te elementy wymagają realnego adapter contract.

### Production exam/question provider data

Final production capability/question data per category/language oraz provider-backed question contract są deployment/external content evidence, nie powodem do hardkodowania demo values.

## 5. Deployment evidence — nie są repo implementation blockers

Przed produkcyjnym go-live nadal wymagane są:

- target-infrastructure restore drill potwierdzający zatwierdzone RPO/RTO,
- production incident contact roster i paging-channel smoke test,
- production scheduler + alert-delivery smoke test dla reconciliation findings.

CI restore drill pozostaje poprawnym repository evidence, ale nie zastępuje target-infrastructure drill.

## 6. Świadomie poza core v1

Nie blokują core v1:

- Moje wizytówki,
- Moje reklamy,
- Wykłady,
- Szkolenie z instruktorem,
- Faktury jako historical index,
- własna impersonation jako funkcja opcjonalna.

## 7. Elementy niepodnoszone do P1

Canonical locality directory pozostaje jawnie nieustalonym external/product-data source. Obecny typed locality fallback zachowuje możliwość wpisania miejscowości i nie tworzy fałszywego authority. Wybór finalnego katalogu może zostać wykonany później bez zmiany modelu Location.

Exact UI copy, filter persistence i podobne P2 nie blokują repository closure.

## 8. Corrective order

Naprawy prowadzić pojedynczymi bramkami:

1. **CORE-V1-STAGE4-MISSING-TABLES-001** — sześć brakujących table nodes w `expand`; 112 -> 118.
2. Kolejne Stage-4 integrity phases według canonical DAG: candidate keys / indexes / FK / constraints / triggers / projections, z wymaganym preflight/write-fence/validate podziałem.
3. Identity/Auth + Organization Settings runtime/UI.
4. Provider-neutral Commerce checkout + license/exam purchase UI + service entitlements.
5. Learning credential PDFs + Student Progress.
6. Resource asset/document UI handoff.
7. Course completion UI + małe canonical runtime gaps (`/languages`, audit read itp.).
8. Ponowny `CORE-V1-CLOSURE-AUDIT`.

Nie wolno przeskoczyć do statusu core-complete przed ponownym zero-gap audytem.

## 9. Preservation

Closure audit nie:

- przepisuje Stage-4 DAG,
- zmienia Stage-5 formal-document authority,
- cofa żadnego wcześniejszego PASS,
- odblokowuje PKK,
- inventuje provider contracts,
- usuwa deferred evidence.

Wcześniejsze PASS-y oznaczają poprawność ich zaakceptowanego slice scope. Ten audyt jedynie dowodzi, że ich suma nie pokrywa jeszcze całego core baseline.
