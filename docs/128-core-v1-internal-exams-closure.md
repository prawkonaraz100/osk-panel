# 128. Core v1 — Internal Exams closure

Data: 2026-09-12

**Slice:** `CORE-V1-INTERNAL-EXAMS-001`  
**Implementation machine:** PASS  
**Narrative payload:** CANDIDATE  
**Central closure:** PENDING_NARRATIVE_VALIDATION

## 1. Zakres

Slice obejmuje wyłącznie Internal Exams:

- capability matrix kategorii / części / języka,
- wersjonowane definicje egzaminów i snapshoty pytań,
- pulę konkretnych jednostek inventory OSK z provenance `free`, `paid`, `adjustment`,
- reservation przed startem oraz dokładnie jednokrotne zużycie jednostki przy starcie,
- formalne próby powiązane z trwałym `Student` i `CourseEnrollment`,
- część teoretyczną i praktyczną jako osobny kontekst próby,
- remote link, bieżącą stację i przypisaną stację egzaminacyjną,
- bezpieczne jednorazowe tokeny bez odzyskiwalnego plaintextu,
- authenticated station credentials, heartbeat i failover bez drugiego zużycia inventory,
- immutable question/result history,
- historyczny wynik oraz deterministic answer-sheet PDF,
- audited inventory adjustment,
- zarządzanie egzaminami w panelu OSK,
- zakładkę egzaminów na profilu kursanta,
- e-mail delivery jednorazowego linku wraz z retry i recovery po przerwaniu procesu,
- handoff tworzenia nowego kandydata do kanonicznego trwałego Student + pierwszego CourseEnrollment.

Slice nie implementuje Platform Commerce, checkoutu, orders/payments ani Purchase History. `exam_orders.create` pozostaje kontraktem przyszłego modułu Commerce i nie jest pozorowany przez Internal Exams.

## 2. Accepted implementation provenance

Accepted parent po zamkniętym poprzednim slice:

`05c9640d70087997a04e08084cb84390802376ac`

Finalny accepted implementation tip Internal Exams:

`efeecfde2484fadc0f3c52bd3dc083c60e1d5c31`

Finalny exact tree:

`5d1e390a8c49a010fc911ebd548becece35962c4`

Accepted Implementation CI:

`34688769004` — **5/5 SUCCESS**.

Accepted API Contract Gate:

`34688769006` — **PASS**.

Accepted PostgreSQL suite:

**153 tests / 2482 assertions — PASS**

Accepted-push secret scan:

**PASS**.

Helper PR secret-scan failures użyte w części bramek nie stanowiły authority, ponieważ GitHub App kończył je przed skanem błędem `403 Resource not accessible by integration`. Finalnym dowodem dla każdej promocji był accepted-branch push.

## 3. Migracje

Materializacja Stage-4 DAG wzrosła z **67/170** do **85/170** node'ów / phase steps.

Dodano dokładnie 18 dependency-closed nodes fazy `expand`:

- `MIG-TBL-INTERNAL_EXAM_CAPABILITIES`,
- `MIG-TBL-EXAM_STATIONS`,
- `MIG-TBL-EXAM_STATION_CREDENTIALS`,
- `MIG-TBL-INTERNAL_EXAM_DEFINITIONS`,
- `MIG-TBL-INTERNAL_EXAM_DOCUMENT_TEMPLATES`,
- `MIG-TBL-INTERNAL_EXAM_INVENTORY_ENTRIES`,
- `MIG-TBL-INTERNAL_EXAM_INVENTORY_ADJUSTMENTS`,
- `MIG-TBL-INTERNAL_EXAM_INVENTORY_LEDGER_ENTRIES`,
- `MIG-TBL-INTERNAL_EXAM_ATTEMPTS`,
- `MIG-TBL-INTERNAL_EXAM_ATTEMPT_LIFECYCLE_EVENTS`,
- `MIG-TBL-INTERNAL_EXAM_RESERVATIONS`,
- `MIG-TBL-INTERNAL_EXAM_ACCESSES`,
- `MIG-TBL-INTERNAL_EXAM_ACCESS_LIFECYCLE_EVENTS`,
- `MIG-TBL-INTERNAL_EXAM_ACCESS_TOKENS`,
- `MIG-TBL-INTERNAL_EXAM_STATION_SESSIONS`,
- `MIG-TBL-INTERNAL_EXAM_ATTEMPT_QUESTIONS`,
- `MIG-TBL-INTERNAL_EXAM_RESULTS`,
- `MIG-TBL-INTERNAL_EXAM_DOCUMENTS`.

Execution identity:

`bd695611d07723f344c5571ea21de2fcb7ef3f09a4673ace9b2f5a46d652f0c8`

Plan identity pozostał:

`d2fd6bc999dc2a5ee024e9b91bf00f5a5dad29575554a0ba42eba67107c23f10`

Nie materializowano późniejszych candidate-key/FK/index/check/preflight phases. Globalny phase barrier pozostaje nienaruszony.

## 4. Database contracts

Wszystkie osiem authority contracts Internal Exams ma status PASS:

- `DB-EXAM-001` — same-tenant i exact-target integrity,
- `DB-EXAM-002` — formal course requirement i exam capability basis,
- `DB-EXAM-003` — inventory reservation authority oraz consume-on-start exactly once,
- `DB-EXAM-004` — attempt/access lifecycle i concurrency,
- `DB-EXAM-005` — access token i finished-result token security,
- `DB-EXAM-006` — station session concurrency, device binding i failover,
- `DB-EXAM-007` — immutable definition/question/result/document evidence,
- `DB-EXAM-008` — deterministic management/history/latest/statistics projection.

Stage-4 authority nie został przepisany ani osłabiony.

## 5. Formal attempt i eligibility

Formalna próba egzaminacyjna:

- zawsze wskazuje trwały `student_id`,
- zawsze wskazuje konkretny `course_enrollment_id`,
- nie tworzy egzaminowego „tymczasowego kandydata”,
- korzysta z current requirement profile i capability matrix,
- snapshotuje kategorię, część, język i dane kandydata,
- może edytować candidate snapshot wyłącznie przed startem i przez optimistic concurrency,
- po starcie historyczny snapshot nie jest przepisywany.

Standalone „nowy kandydat” jest własną, bezpieczną decyzją produktową: UI przechodzi do kanonicznego Student create, wymaga pierwszego formalnego kursu i wraca do generatora po exact `student_id`.

## 6. Inventory ledger

Inventory reprezentuje konkretne jednostki, nie sam licznik.

Runtime zachowuje:

- provenance `free`, `paid`, `adjustment`,
- reservation konkretnej jednostki przy utworzeniu próby,
- consume dokładnie raz przy starcie egzaminu,
- finish bez ponownego consume,
- pre-start release dla odwołanego/nieważnego dostępu zgodnie z lifecycle,
- technical abort po starcie bez automatycznego przywracania jednostki,
- append-only ledger,
- spójność operational projection z sumą ledgeru.

Audited adjustment:

- dodatni delta tworzy nowe konkretne units,
- ujemny delta wycofuje tylko `available` i działa all-or-none,
- `reserved` i `consumed` nie są wycofywane,
- technical refund tworzy nową kompensującą jednostkę i nie otwiera historii consumed.

## 7. Access, tokeny i e-mail

Remote token:

- jest opaque i jednorazowy,
- plaintext nie trafia do bazy, audit logu, domain eventu ani outboxa,
- replay idempotency zwraca bezsekretowy snapshot,
- resend rotuje token zamiast odzyskiwać stary.

E-mail delivery jest osobnym command flow:

1. transakcyjny prepare rotuje token i zapisuje tylko bezsekretny stan,
2. mail transport działa poza transakcją,
3. dopiero sukces transportu potwierdza delivered state i audit `internal_exam.access.sent`,
4. transport failure unieważnia dokładnie przygotowany token i zapisuje `delivery_failed`,
5. ten sam Idempotency-Key może wtedy bezpiecznie wygenerować świeży token i ponowić wysyłkę.

Recovery po crashu procesu:

- `delivery_prepared` ma lease,
- raw token / raw URL nadal nie są persistowane,
- przed wygaśnięciem lease retry failuje konfliktem,
- po wygaśnięciu tylko exact przygotowany token może zostać unieważniony,
- jeżeli został superseded przez nowszy delivery, recovery nie unieważnia nowszego tokenu i nie wysyła duplikatu.

## 8. Stacje egzaminacyjne i failover

Runtime obsługuje:

- rejestrację stacji,
- provisioning i rotation credential,
- hashed credential verification,
- authenticated heartbeat,
- `local_current_workstation`,
- `assigned_exam_station`,
- maksymalnie jedną aktywną próbę na stacji zgodnie z authority,
- transfer po awarii technicznej,
- station-session history,
- failover bez drugiego inventory consumption.

Tożsamość stacji jest rozwiązywana serwerowo; klient nie może podrobić tenantowej station authority samym UUID.

## 9. Result, review i answer sheet

Po zakończeniu próby zachowywane są historyczne:

- immutable question snapshots,
- kolejność pytań,
- odpowiedzi kandydata,
- poprawność i punktacja,
- wynik oraz threshold,
- evidence bundle hash.

Result view i question review czytają ten sam historyczny attempt.

Teoretyczny answer sheet:

- jest związany z dokładnie jednym effective immutable template,
- używa historycznego candidate/question/result evidence,
- generuje canonical tenant-owned PDF asset,
- przy kolejnym pobraniu nie czyta bieżącego profilu kursanta ani aktualnej bazy pytań,
- content hash musi odpowiadać utrwalonemu dokumentowi.

## 10. Permissions, tenant scope i audit

Runtime używa permission authority i data scopes.

Istotne permissions:

- `exams.view`,
- `exams.generate`,
- `exams.access.send`,
- `exams.start.local`,
- `exams.stations.view`,
- `exams.stations.manage`,
- `exams.results.view`,
- `exams.documents.download`,
- `exams.inventory.adjust`.

`exams.purchase` pozostaje authority przyszłego Platform Commerce.

Target Student/Course/Attempt/Access/Station jest zawsze tenant-scoped. `assigned_students` pozostaje nadrzędnym ograniczeniem tam, gdzie permission scope tego wymaga.

Krytyczne mutacje emitują audit/domain-event/outbox intent. Internal Exam audit catalog obejmuje m.in.:

- `internal_exam.attempt.created`,
- `internal_exam.access.created`,
- `internal_exam.access.sent`,
- `internal_exam.access.delivery_failed`,
- `internal_exam.access.revoked`,
- `internal_exam.started`,
- `internal_exam.submitted`,
- `internal_exam.technical_aborted`,
- `internal_exam.station_transferred`,
- station registration / credential provisioning / credential rotation,
- `internal_exam.answer_sheet.downloaded`,
- `internal_exam.inventory.adjusted`.

## 11. API i UI

Runtime API obejmuje m.in.:

- `GET /internal-exam/inventory`,
- `GET /internal-exam/subjects`,
- `GET /internal-exam/capabilities`,
- `POST /internal-exam/inventory-adjustments`,
- `GET/POST /course-enrollments/{courseEnrollmentId}/internal-exam-attempts`,
- `GET/PATCH /internal-exam-attempts/{attemptId}`,
- `POST /internal-exam-attempts/{attemptId}/accesses`,
- `POST /internal-exam-accesses/{accessId}/send`,
- `POST /internal-exam-accesses/{accessId}/revoke`,
- `POST /internal-exam-accesses/{accessId}/start`,
- `POST /internal-exam-attempts/{attemptId}/submit`,
- `POST /internal-exam-attempts/{attemptId}/technical-abort`,
- `GET /internal-exam-attempts/{attemptId}/result`,
- `GET /internal-exam-attempts/{attemptId}/questions`,
- `GET /internal-exam-attempts/{attemptId}/documents/answer-sheet.pdf`,
- station list/register/credential/heartbeat,
- `POST /internal-exam-attempts/{attemptId}/station-transfer`.

`POST /internal-exam/orders` jest zachowany w publicznym contract inventory jako przyszły Commerce boundary, ale nie ma runtime bindingu w tym slice.

UI:

- osobny panel `/egzamin-wewnetrzny/panel`,
- inventory summary,
- partial search,
- category/status filters,
- hide-finished,
- sortowanie,
- generowanie z wiersza i standalone,
- historyczne próby,
- edycja pre-start snapshotu,
- remote link / e-mail,
- local / assigned station,
- wynik i question review,
- PDF,
- zakładka „Egzamin wewnętrzny” na `/kursanci/{student_id}`,
- handoff nowego kursanta do trwałego Student + pierwszego CourseEnrollment.

Zakup dodatkowych egzaminów nie jest symulowany przez lokalny fake checkout.

## 12. Executable Stage-4 DBT

Finalny katalog Stage-4 nadal ma:

**491 test IDs**

Executable registry:

**78/491 implemented executable assertions**  
**413/491 pending_domain_materialization**

Internal Exams slice nie dopisuje fałszywych DBT claims.

Feature/integration tests z tego slice są executable evidence runtime, ale nie są automatycznie przepisywane na finalne `DBT-*` authority IDs bez dokładnego pokrycia test contractu.

Świadomie nie oznaczono jako implemented:

- późniejszych physical FK/candidate-key/check/index DBT,
- 14 migration preflight DBT,
- niezależnych two-writer concurrency DBT bez wymaganego two-connection evidence,
- Platform Commerce / payment DBT,
- provider-backed production question-engine assertions.

## 13. Jawnie odroczone zależności

Nie są częścią PASS tego slice:

- Platform Commerce: `exam_orders.create`, orders, order_items, payments, payment callbacks i Purchase History,
- późniejsze candidate-key/FK/index/check/preflight migration phases,
- production provider question-engine integration i finalny zewnętrzny schema contract,
- finalne production capability data per category/language,
- globalna legal-category reverification, w tym PT,
- provider-backed PKK operations,
- Dashboard / Notifications / Purchase History,
- hardening produkcyjny wymagający późniejszych globalnych decyzji privacy/retention/DR.

Odroczenia nie mogą być zastępowane atrapą lokalnego stanu w Internal Exams.

## 14. Machine evidence

Accepted Implementation CI `34688769004` na `efeecfde2484fadc0f3c52bd3dc083c60e1d5c31`:

- backend-quality — PASS,
- frontend-quality — PASS,
- runtime-tests-and-migrations — PASS,
- contracts-and-traceability — PASS,
- secret-scan — PASS.

Accepted PostgreSQL:

**153 tests / 2482 assertions — PASS**

Dodatkowo:

- migration authority / registry — PASS,
- Composer strict validate — PASS,
- Pint — PASS,
- PHPStan — PASS zero errors,
- frontend lint — PASS,
- Vue/TypeScript typecheck — PASS,
- Vite build — PASS,
- npm audit high — PASS,
- changed-module traceability — PASS,
- API Contract Gate `34688769006` — PASS,
- accepted-push Gitleaks — PASS.

## 15. Narrative result

Implementation machine = **PASS**.

Finalny accepted implementation tip:

`efeecfde2484fadc0f3c52bd3dc083c60e1d5c31`

Narrative closure candidate:

**PENDING VALIDATION**

Po walidacji tego dokumentu central Stage-5 gate może otrzymać:

`CORE-V1-INTERNAL-EXAMS-001 = PASS`

Następny dozwolony slice zgodnie z `AGENTS.md` to **PKK adapter/integration**, ale nie może zostać rozpoczęty w ramach tego closure. Central gate ma po zamknięciu zatrzymać wykonanie przed PKK do kolejnej jawnej instrukcji użytkownika.
