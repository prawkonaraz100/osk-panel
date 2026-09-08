# 113. Stage 4 — PKK database audit

Data: 2026-09-08

**Etap:** `DB4_8_PKK`  
**Aktualny krok:** `DB_PKK_007_INTEGRATION_CONFIGURATION_REVISION_BINDING_AND_ASYNC_EXECUTION_CONTEXT`
**Status:** `FAIL_WITH_1_P1_BLOCKER / 0 P0 / 1 P1 OPEN`

Machine-readable diagnoza: `specs/database/pkk.yml`.

---

## 1. Zasada pracy

DB4_8 rozpoczynamy wyłącznie od diagnozy całego bounded contextu PKK. W tym kroku nie naprawiamy żadnego blockera i nie modyfikujemy finalnych agregatów Stage 4.

Obowiązują te same bramki jakości jak w DB4_4–DB4_7:

1. najpierw diagnoza całego bounded contextu,
2. zero fixerów w kroku diagnozy,
3. później dokładnie jeden P0/P1 na krok,
4. po każdym fixerze osobny machine self-audit, narrative audit i central gate,
5. `specs/database/core-schema.yml` oraz `docs/87-physical-database-schema.md` pozostają zamrożone do finalnego DB4_8 aggregate sync,
6. DB4_9+, Stage 5, migracje Laravel i UI pozostają poza zakresem.

Diagnoza nie próbuje odtwarzać nieznanego protokołu zewnętrznego PKK. Projektujemy wyłącznie provider-neutralne granice integralności, bezpieczeństwa, historii i concurrency, które da się uzasadnić naszym potwierdzonym produktem i obecnymi kontraktami.

---

## 2. Źródła

### Course i formalna identity PKK

- `specs/database/students-courses-training.yml`, w szczególności zamknięty DB-TRN-008,
- `docs/87-physical-database-schema.md`,
- `docs/66-formal-student-record-and-theory-exemptions.md`.

### Konfiguracja organizacji

- `specs/database/organization-settings.yml`,
- `docs/37-pkk-configuration-gate.md`,
- `specs/screens/pkk-configuration-gate.yml`,
- `specs/api/paths/auth-organization.yaml`.

### Potwierdzony zakres produktu PKK

- `docs/75-pkk-entrypoints-and-student-list.md`,
- `docs/76-pkk-course-operational-panel.md`,
- `docs/77-pkk-management-flow-fallback-design.md`,
- `specs/screens/pkk-entrypoints-and-student-list.yml`,
- `specs/screens/pkk-course-operational-panel.yml`.

### API, security i preservation

- `specs/api/paths/pkk-calendar.yaml`,
- `specs/api/openapi-components-v1.yaml`,
- `specs/api/common-contract.yml`,
- `specs/api/required-operations-v1.yml`,
- `specs/security/permissions.yml`,
- `specs/reverse-engineering-manifest.yml`,
- `specs/traceability/core-v1.yml`,
- `docs/96-reverse-engineering-preservation-contract.md`.

---

## 3. Granice upstream, których DB4_8 nie otwiera ponownie

### 3.1 Course-scoped PKK identity z DB4_4

DB-TRN-008 jest już zamknięty i pozostaje źródłem prawdy dla lokalnej identity PKK:

- canonical owner numeru PKK to `pkk_profiles`,
- PKK jest przypięte do konkretnego `course_enrollment`, nie globalnie do Studenta,
- jeden kurs może mieć dokładnie jeden current identity profile,
- identity profile ma wersjonowaną, liniową historię,
- plaintext PKK nie jest persistowany,
- Course create i początkowa identity PKK commitują się atomowo,
- zmiana PKK tworzy successor zamiast nadpisania historii,
- zmiana kategorii/rodzaju szkolenia wymaga revalidation PKK identity,
- nie wymyślamy globalnego hard unique na numer PKK.

DB4_8 dotyczy **provider lifecycle**, a nie ponownego projektowania tej identity.

### 3.2 Konfiguracja integracji

`pkk_integration_settings` pozostaje jednym rekordem konfiguracji organizacji współdzielonym przez Ustawienia i configuration gate. `external_osk_login` jest identyfikatorem integracyjnym, nie loginem do naszej aplikacji. Imię i nazwisko operatora pozostają w `users` i nie tworzymy ich kopii w tabeli PKK.

`organization_settings.version` pozostaje concurrency root ustawień. DB4_8 może później potrzebować dowodu, z której rewizji konfiguracji korzystała operacja providerowa, ale nie może tworzyć drugiego, sprzecznego modelu ustawień.

### 3.3 RBAC i common idempotency

Zachowujemy istniejące permission-based RBAC: `pkk.view`, `pkk.fetch`, `pkk.training.update_and_return`, trzy permissions zwrotu i `pkk.retry` są student-scoped po tenant validation. `organization.integrations.manage` pozostaje organization-scoped.

Common API contract już wymaga `Idempotency-Key` dla mutacji PKK oraz odrzucenia ponownego użycia tego samego klucza z innym request hash. DB4_8 musi ten kontrakt fizycznie domknąć, nie tworzyć konkurencyjnej definicji idempotency.

---

## 4. Potwierdzone możliwości biznesowe, które muszą przetrwać

Operacyjnym ownerem PKK jest konkretny `course_enrollment`. Jeden Student może mieć wiele kursów i każdy z nich własną identity oraz historię PKK.

Muszą pozostać dostępne:

- `Dodaj kursanta z PKK`,
- configuration gate przed provider operations przy niekompletnej konfiguracji,
- pobranie profilu PKK,
- podgląd pobranego profilu,
- aktualizacja szkolenia i zwrot,
- zwrot do innego OSK,
- zwrot do urzędu,
- zwrot profilu przedawnionego,
- historia operacji,
- retry wyłącznie dla bezpiecznie retryable klas błędów,
- reconciliation po wyniku `unknown`,
- flow XML `pobierz -> podpisz zewnętrznie -> wgraj`, jeżeli wymaga go provider,
- redacted UI projection i chronione przechowywanie wrażliwych payloadów.

Odpowiedź providera jest snapshotem integracyjnym. Nie wolno jej traktować jako bezwarunkowego polecenia nadpisania bieżącego Studenta lub Course.

---

## 5. Co już istnieje w agregacie

Obecny blueprint zawiera:

- `pkk_integration_settings`,
- `pkk_profiles`,
- `pkk_operations`,
- `pkk_operation_attempts`,
- wspólne `file_assets`,
- wspólne `idempotency_records`.

To dobry punkt startowy. DB4_4 domknął identity history `pkk_profiles`. Prowizoryczny provider model zawiera już operation type/status, request/response snapshots, attempt number, provider request id, error class i retryable marker.

Nie wystarcza to jednak do dowodu poprawności provider lifecycle. Brakuje zamkniętych exact-target boundaries, immutable snapshot history, state matrix, exactly-once external effect, reconciliation, podpisanego XML evidence, configuration revision binding i deterministycznego latest operation.

---

## 6. DB-PKK-001 — same-tenant i exact-target integrity

**Severity:** P1  
**Status:** OPEN

### Diagnoza

`PkkProfile -> CourseEnrollment` jest już chronione przez DB4_4, ale `pkk_operations` ma jednocześnie Course i opcjonalny Profile bez pełnego dowodu, że wskazują dokładnie ten sam tenant i ten sam kurs. `pkk_operation_attempts` nie ma jeszcze jawnego tenant/exact-target boundary.

W ramach jednego OSK błędny backend mógłby więc próbować połączyć operację Course A z profilem Course B. Przyszły podpisany XML lub reconciliation evidence ma ten sam problem, jeżeli nie zostanie związany z dokładną operacją/profilem.

### Ryzyko

- provider history przy złym kursie,
- cross-tenant payload exposure,
- retry lub reconciliation wykonane dla niewłaściwej identity PKK.

### Zakres przyszłego fixera

Wyłącznie candidate keys, tenant keys tam gdzie potrzebne, composite exact-target relations, history-preserving `RESTRICT` i fail-closed migration prechecks. Globalnego `User` nie tenantujemy sztucznie.

---

## 7. DB-PKK-002 — immutable provider snapshot history

**Severity:** P1  
**Status:** OPEN

### Diagnoza

Current `pkk_profiles` łączy dwie role: zamkniętą w DB4_4 immutable identity oraz prowizoryczne mutable pola `status/profile_snapshot/fetched_at`. Kolejne fetch/provider responses nie mogą nadpisywać jedynego historycznego dowodu.

Preview i panel `DANE POBRANE Z PKK` potrzebują deterministycznego current provider snapshotu dokładnie dla bieżącej identity revision. DB4_4 już zabrania przenoszenia starego provider snapshotu do nowej identity revision po zmianie PKK.

### Ryzyko

- utrata historii odpowiedzi providera,
- pokazanie snapshotu starej identity jako aktualnego,
- pomieszanie identity authority z mutable sync authority.

### Zakres przyszłego fixera

Wersjonowana/immutable historia provider snapshotów albo równoważny model, exact identity binding, deterministic current projection i evidence hash. Bez automatycznego nadpisywania Studenta/Course.

---

## 8. DB-PKK-003 — operation lifecycle, concurrency i eligibility

**Severity:** P1  
**Status:** OPEN

### Diagnoza

Obecne `business_status` nie ma zamkniętej state matrix. Źródła produktu pokazują stany związane z `pending`, `requires_signature`, `submitted`, `success`, `failed`, `cancelled`, ale diagnoza nie przesądza jeszcze finalnego fizycznego katalogu.

Przed provider effect trzeba pod lockiem ponownie potwierdzić dokładny Course, current PkkProfile, permission i gotowość konfiguracji. Provider command nie może przejść bokiem obok Course edit/cancel albo supersession PKK. Konfliktujące mutacje zewnętrzne dla tego samego profilu nie mogą wykonywać się równolegle bez ustalonej granicy serializacji.

DB4_4 świadomie nie ustalił globalnej unikalności numeru PKK. Nie wolno teraz wymyślić takiej reguły tylko po to, aby uprościć provider flow.

### Ryzyko

- zwrot wykonany na superseded PKK,
- dwa konkurencyjne zwroty/aktualizacje,
- provider effect po zmianie lokalnego kontekstu Course.

### Zakres przyszłego fixera

Provider-neutral operation/state matrix, timestamp equivalence, terminal immutability, lock order i eligibility rechecks. Ambiguous duplicate PKK ma fail-closed do czasu zweryfikowania semantyki providera.

---

## 9. DB-PKK-004 — idempotency, retry, reconciliation, exactly-once effect

**Severity:** P1  
**Status:** OPEN

### Diagnoza

API wymaga `Idempotency-Key`, ale obecny `pkk_operations.idempotency_key` jest prowizorycznie nullable i nie ma kompletnego związania z generic idempotency claim/request hash.

Timeout po wysłaniu requestu nie dowodzi, że provider nic nie zrobił. Taki stan musi być `unknown/reconciliation required`, a nie zwykłym `failed -> retry`.

Unique `(pkk_operation_id, attempt_no)` jest pozytywnym elementem, ale nie rozstrzyga one-inflight attempt, allocation sequence, lock order ani reconciliation przed kolejnym wysłaniem.

### Ryzyko

- podwójny zwrot/aktualizacja po stronie zewnętrznej,
- równoległe provider attempts,
- nowa operacja biznesowa dla tego samego idempotency key.

### Zakres przyszłego fixera

Exact idempotency binding, request hash, one inflight attempt, sekwencja attemptów, retry classification, jawny unknown/reconciliation state i exactly-once external business effect bez założenia, że provider natywnie wspiera idempotency.

---

## 10. DB-PKK-005 — podpisany XML i FileAsset evidence

**Severity:** P1  
**Status:** OPEN

### Diagnoza

Potwierdzony produkt musi obsłużyć flow `download XML -> podpis zewnętrzny -> upload signed XML`, gdy wymaga tego provider. Obecny DB nie dowodzi, który unsigned XML był przedmiotem podpisu i do jakiej dokładnie operacji/attemptu/profile należy wgrany signed asset.

Samo posiadanie `file_assets` nie wystarcza. Dowolny plik tego samego tenantu nie może zostać podpięty pod inną operację PKK.

### Ryzyko

- podpisanie lub wysłanie XML innego kursu,
- podmiana/tampering dokumentu,
- przejście `requires_signature -> submitted` bez kompletnego evidence.

### Zakres przyszłego fixera

Exact operation/profile/attempt binding, właściwy purpose/readiness/mime/size/hash FileAsset, immutable unsigned/signed hash lineage i transition evidence.

---

## 11. DB-PKK-006 — bezpieczeństwo provider payloadów

**Severity:** P1  
**Status:** OPEN

### Diagnoza

Ciphertext i redacted JSON są już przewidziane, ale nie ma zamkniętego contractu cipher/key version, integralności, immutability ani relacji między protected evidence i redacted projection.

Payload providera może zawierać PII i dane formalnego szkolenia. Pełny payload nie może trafić do audit/activity/outbox/logów ani safe response snapshotu idempotency.

Exact schema providera jest nadal nieznana i pozostaje własnością adaptera.

### Ryzyko

- wyciek payloadu,
- brak możliwości zweryfikowania historycznego evidence po key rotation,
- rozjazd redacted projection z chronionym źródłem.

### Zakres przyszłego fixera

Encrypted envelope metadata, key version/integrity evidence, allowlisted redacted projection i jawne wykluczenie sekretów/wrażliwych payloadów z nieodpowiednich kanałów. Bez wymyślania provider schema.

---

## 12. DB-PKK-007 — binding do rewizji konfiguracji

**Severity:** P1  
**Status:** OPEN

### Diagnoza

Provider operation może być async i może być retryowana po zmianie ustawień integracji. Obecny operation/attempt nie dowodzi, jaka rewizja konfiguracji i jaki verification epoch były używane przy zewnętrznym callu.

Zmiana `external_osk_login` lub przyszłego secret reference nie może po cichu zmienić execution context już utworzonego provider attemptu.

### Ryzyko

- retry pod inną identity integracyjną niż pierwsze wysłanie,
- brak odtwarzalnego dowodu konfiguracji użytej przy historycznej operacji,
- race konfiguracja vs provider submission.

### Zakres przyszłego fixera

Immutable configuration revision/version binding per provider attempt, readiness verification epoch i lock-order z ustawieniami. Bez kopiowania plaintext credentials do historii i bez wymyślania niepotwierdzonych secret fields.

---

## 13. DB-PKK-008 — deterministic latest/history projection

**Severity:** P1  
**Status:** OPEN

### Diagnoza

UI pokazuje `Ostatnia operacja PKK` i licznik historii. Same timestampy `created_at/completed_at` nie zapewniają deterministycznego latest, szczególnie przy równych timestampach i async completion.

Retry attempt jest transportowym attemptem tej samej operacji biznesowej. Nie może zwiększać licznika operacji PKK.

### Ryzyko

- niedeterministyczne `Ostatnia operacja`,
- count niezgodny z historią,
- retry zawyża liczbę operacji,
- niestabilna paginacja.

### Zakres przyszłego fixera

Deterministyczna, immutable course-scoped sequence dla business operations, latest przez `MAX(sequence)`, derived count/latest projection oraz stabilny tie-breaker. Bez mutable summary authority.

---

## 14. Provider facts, których diagnoza celowo nie wymyśla

DB4_8 nie ma obecnie zweryfikowanego kontraktu zewnętrznego PKK, dlatego za `unknown/pending adapter contract` pozostają:

- dokładne request/response fields,
- provider-native status catalog,
- native idempotency support,
- native reconciliation lookup,
- schema XML i dokładny format/algorytm podpisu,
- semantyka duplicate PKK/external ownership,
- exact `test connection` behavior.

To nie jest luka, którą należy zasypać zgadywaniem. Bounded DB contract może zamknąć provider-neutralne bezpieczeństwo i concurrency, a adapter później dostarczy zweryfikowaną semantykę protokołu.

---

## 15. API / Stage 5 gaps — zapisane, nie naprawiane

Obecne `PkkProfile` i `PkkOperation` w Stage 3 są celowo cienkimi projekcjami i nie wyrażają jeszcze pełnego snapshot lineage, lifecycle, attempt/reconciliation evidence ani podpisanego XML transportu.

`update-and-return` zachowuje opaque provider-normalized payload jako adapter boundary. Tego nie zmieniamy w diagnozie.

Exact API fields/statuses, retry/reconciliation i signed XML HTTP transport będą zsynchronizowane dopiero w Stage 5 acceptance-contract sync. W DB4_8 diagnosis nie zmieniono żadnego pliku API.

---

## 16. Preservation gate

Wynik: **PASS_DIAGNOSIS_ONLY**.

Potwierdzone:

- nie naprawiono żadnego z 8 blockerów,
- DB4_4 Course/PKK identity pozostaje nienaruszona,
- nadal dokładnie jeden current course-scoped PkkProfile,
- historia PKK i Course versioning pozostają źródłem prawdy,
- nie wymyślono hard unique numeru PKK,
- canonical organization PKK settings pozostają bez duplikacji,
- `external_osk_login` nie został utożsamiony z application login,
- RBAC i common idempotency contract pozostają zachowane,
- wszystkie potwierdzone provider operations i signed XML capability pozostają w scope,
- nie wymyślono provider schema/statusów/protokołu,
- `specs/database/core-schema.yml` pozostaje blob `c04dcbea90ca877c1c4e76196b77fdb33e404dab`,
- `docs/87-physical-database-schema.md` pozostaje blob `a26421c9d3d624693f29f8e9dfa1b9e718f22323`,
- DB4_9+, Stage 5, Laravel migrations i UI nie zostały rozpoczęte.

---

## 17. Wynik diagnozy DB4_8

- diagnosed P0: **0**,
- diagnosed P1: **8**,
- resolved podczas diagnozy: **0**,
- open P0/P1: **8**,
- wynik bounded contextu: **FAIL_WITH_8_P1_BLOCKERS**.

Kolejność fixerów:

1. `DB-PKK-001` — same-tenant and exact Course/Profile/Operation/Attempt integrity,
2. `DB-PKK-002` — immutable provider profile snapshot history,
3. `DB-PKK-003` — provider operation lifecycle/concurrency/eligibility,
4. `DB-PKK-004` — idempotency/retry/reconciliation/exactly-once external effect,
5. `DB-PKK-005` — signed XML/FileAsset evidence integrity,
6. `DB-PKK-006` — sensitive provider payload security,
7. `DB-PKK-007` — integration configuration revision binding,
8. `DB-PKK-008` — deterministic operation latest/history projection.

Po centralnym gate jedynym dopuszczalnym następnym krokiem będzie **DB-PKK-001**, i dopiero po kolejnym jawnym poleceniu użytkownika.

**STOP przed DB-PKK-001.**


---

## 18. DB-PKK-001 — wynik fixera: PASS

Machine contract: `specs/database/pkk.yml`, clean machine commit `55ee706a7fcdd2e71d3844b71f07bef533e90cc2`.

### 18.1 Zakres

DB-PKK-001 zamyka wyłącznie relacyjną granicę **same-tenant + exact-target** dla providerowego PKK. Nie rozwiązuje lifecycle operacji, retry/reconciliation, provider snapshotów, podpisanego XML, kryptografii payloadów, rewizji konfiguracji ani latest-operation projection.

Runtime `pkk_operation` jest związana z dokładnym `CourseEnrollment` i dokładną rewizją `PkkProfile`; runtime provider operation nie może mieć niejednoznacznego `pkk_profile_id = NULL`.

### 18.2 Exact Course/Profile dla Operation

Canonical relacje:

- `(organization_id, course_enrollment_id) -> course_enrollments(organization_id,id)`,
- `(organization_id, pkk_profile_id, course_enrollment_id) -> pkk_profiles(organization_id,id,course_enrollment_id)`.

Drugi composite FK blokuje połączenie Course A z profilem Course B nawet wewnątrz tego samego OSK. `organization_id`, `course_enrollment_id` i `pkk_profile_id` są immutable po insercie; formalna historia używa `RESTRICT`, bez destructive cascade.

### 18.3 Exact Operation context dla Attempt

`pkk_operation_attempts` dostaje jawne `organization_id`, `course_enrollment_id` i `pkk_profile_id`, a exact-parent FK ma postać:

`(organization_id,pkk_operation_id,course_enrollment_id,pkk_profile_id)`
`-> pkk_operations(organization_id,id,course_enrollment_id,pkk_profile_id)`.

Attempt nie może więc wskazywać Operation z jednego kontekstu i jednocześnie innego Course/Profile. Ten exact target jest też bezpieczną bazą dla późniejszego reconciliation i signed-XML evidence, ale ich semantyka pozostaje odpowiednio w DB-PKK-004 i DB-PKK-005.

### 18.4 Actor i historia

`actor_user_id` nadal wskazuje globalne `users(id)`; nie tworzymy sztucznego tenantowego FK do Usera. Tenant bezpieczeństwa wynika z exact Course/Profile/Operation contextu i authorization boundary.

Normalne przepinanie Operation/Attempt do innego tenantu, Course albo Profile oraz hard-delete provider history są zabronione.

### 18.5 Migration safety

Migracja jest fail-closed:

- najpierw weryfikuje tenant Course i exact Profile/Course,
- Attempt context może być backfillowany tylko z wcześniej zweryfikowanego parent Operation,
- legacy Operation z `pkk_profile_id = NULL` nie jest automatycznie wiązany z current ani „najnowszym” profilem,
- wrong-course/wrong-tenant Profile nie jest automatycznie przepinany,
- brak jednoznacznego historycznego dowodu wymaga reviewed remediation,
- migracja nie odpytuje providera, aby zgadywać historię.

### 18.6 Preservation gate

Pozostają OPEN i nierozwiązane: `DB-PKK-002`…`DB-PKK-008`. Nie zmieniono API, `specs/database/core-schema.yml`, `docs/87-physical-database-schema.md`, migracji Laravel ani UI.

Self-audit:

- exact Operation Course/Profile same-tenant boundary — **PASS**,
- explicit tenant + exact context na provider Attempt — **PASS**,
- cross-course/cross-profile rebinding — **zablokowany**,
- history-preserving `RESTRICT` — **PASS**,
- global User nie został błędnie tenant-scoped — **PASS**,
- legacy null-profile heuristic — **zabroniona**,
- DB4_4 PKK identity contract — **zachowany**,
- frozen aggregates — **bez zmian**.

### 18.7 Wynik po fixerze

- P0 open: **0**,
- P1 open: **7**,
- resolved: **1 / 8**,
- wynik DB4_8: **FAIL_WITH_7_P1_BLOCKERS**.

Po centralnym gate jedynym dopuszczalnym następnym krokiem jest `DB-PKK-002 — immutable provider profile snapshot history and current projection`, wyłącznie po kolejnym jawnym poleceniu użytkownika.

**STOP przed DB-PKK-002.**

---

## 19. DB-PKK-002 — wynik fixera: PASS

Machine contract: `specs/database/pkk.yml`, clean machine commit `3283805d56ab331b9b889028cf38ddc28d9a00c0`.

### 19.1 Rozdzielenie identity od provider snapshot history

`pkk_profiles` pozostaje canonical authority course-scoped PKK identity i jego rewizji z DB4_4. Dane od zewnętrznego providera nie są już mutable stanem tej samej identity row.

Nowym canonical authority historii obserwacji providera jest append-only `pkk_provider_profile_snapshots`. Stare pola providerowe na `pkk_profiles` (`status`, `profile_snapshot`, `fetched_at`) po bezpiecznym cutover przestają być runtime authority i mogą być wyłącznie migration-only.

### 19.2 Exact identity binding

Każdy snapshot jest związany kompozytowo z dokładnym `(organization_id, pkk_profile_id, course_enrollment_id)`. Nie może więc przejść pomiędzy tenantami, kursami ani rewizjami PKK identity.

DB-PKK-001 pozostaje nienaruszony i jest bazą relacyjną dla tego evidence.

### 19.3 Append-only revision history

Snapshot ma immutable `snapshot_revision >= 1`, przydzielany jako kolejna rewizja pod blokadą dokładnego `PkkProfile FOR UPDATE`.

Rewizje są deterministyczne i ciągłe dla committed rows. Timestamp, UUID ani hash nie są `latest` authority. Normalny UPDATE lub hard-delete snapshotu jest zabroniony.

### 19.4 Deterministyczny current projection

Current PKK identity dla kursu nadal pochodzi z `pkk_profiles ... superseded_at IS NULL`. Current provider snapshot to wyłącznie `MAX(snapshot_revision)` dla tej dokładnej aktualnej identity revision.

Jeśli aktualna identity nie ma snapshotu, projekcja ma stan „not fetched”/NULL. System nie może fallbackować do snapshotu poprzedniej, superseded rewizji PKK.

Późna odpowiedź providera związana z historyczną rewizją może zostać zachowana w historii tej rewizji, ale nigdy nie staje się current snapshotem kursu.

### 19.5 Evidence hash i granica kryptografii

Snapshot posiada `snapshot_content_hash`, liczony nad canonical normalized provider snapshot envelope przed szyfrowaniem. Hash wiąże historyczną treść, ale nie jest mechanizmem szyfrowania ani uwierzytelnienia.

Pełny encrypted-payload envelope, key version, authenticated encryption i redaction integrity pozostają wyłącznie DB-PKK-006. DB-PKK-002 nie udaje, że zamknął kryptografię providera.

### 19.6 Brak automatycznego overwrite Student/Course

Provider snapshot jest external observation evidence i read projection. Nie zapisuje automatycznie wartości do `students` ani `course_enrollments`. Ewentualna późniejsza aktualizacja domeny wymaga osobnej walidacji, authorization, concurrency i auditu.

### 19.7 Identity change i migration safety

Nowa rewizja `PkkProfile` zaczyna z zerem provider snapshots. Kopiowanie snapshotu poprzedniej identity do nowej jest zabronione.

Migracja jest fail-closed: legacy snapshot można związać tylko z dokładnie udowodnioną identity revision; nie wolno zgadywać current/latest po czasie, UUID ani danych kursu. Nieznane `fetched_at` pozostaje NULL z origin `legacy_reconstructed`, a brak jednoznacznego provenance wymaga reviewed remediation.

### 19.8 Preservation gate

Pozostają OPEN: `DB-PKK-003`…`DB-PKK-008`. Nie zmieniono API, `core-schema.yml`, `docs/87`, migracji Laravel ani UI.

Self-audit:

- identity i provider snapshot authority rozdzielone — **PASS**,
- append-only immutable snapshot history — **PASS**,
- exact tenant/course/profile binding — **PASS**,
- deterministic revision/current projection — **PASS**,
- timestamp/UUID latest heuristic — **zabroniony**,
- stale snapshot fallback po zmianie PKK — **zabroniony**,
- late historical provider response nie psuje current projection — **PASS**,
- Student/Course auto-overwrite — **zabroniony**,
- DB-PKK-003…008 — **nadal OPEN**,
- frozen aggregates — **bez zmian**.

### 19.9 Wynik po fixerze

- P0 open: **0**,
- P1 open: **6**,
- resolved: **2 / 8**,
- wynik DB4_8: **FAIL_WITH_6_P1_BLOCKERS**.

Po centralnym gate jedynym dopuszczalnym następnym krokiem jest `DB-PKK-003 — provider operation lifecycle, concurrency and command eligibility`, wyłącznie po kolejnym jawnym poleceniu użytkownika.

**STOP przed DB-PKK-003.**

---

## 20. DB-PKK-003 — wynik fixera: PASS

Machine contract: `specs/database/pkk.yml`, clean machine commit `88f474bf0c53b55657348289aebfcdf1fed08b65`.

### 20.1 Canonical business lifecycle

DB-PKK-003 zamyka provider-neutralny lifecycle `pkk_operations` bez wymyślania zewnętrznego katalogu statusów providera. Canonical business status ma zamknięty katalog:

- `draft`,
- `pending`,
- `requires_signature`,
- `submitted`,
- `success`,
- `failed`,
- `cancelled`.

Uproszczone `pending/success/failed` pozostaje projekcją UI, a nie drugim źródłem prawdy.

### 20.2 Operation type i command eligibility

Runtime operation types pozostają rozdzielone od statusu: `fetch_profile`, `update_and_return`, `return_to_school`, `return_to_authority`, `return_expired`. `retry` nie jest nowym business operation type; dotyczy istniejącej Operation i pozostaje szczegółowo zamykany w DB-PKK-004.

Przed przejściem `draft -> pending` system pod lockiem ponownie sprawdza tenant, exact Course/Profile, current PkkProfile, uprawnienie do konkretnego typu operacji oraz gotowość integracji. Superseded profile nie może rozpocząć nowego provider effect.

### 20.3 Jedna aktywna Operation na current PkkProfile

Ponieważ nie mamy zweryfikowanej macierzy niezależnych efektów zewnętrznego providera, przyjmujemy bezpieczną granicę serializacji: dla dokładnej current identity revision może istnieć maksymalnie jedna niezamknięta provider Operation.

Nie tworzymy globalnego unique na numer PKK. Granica dotyczy exact course-scoped `PkkProfile`, zgodnie z DB4_4.

### 20.4 Lifecycle transitions

Dozwolone przejścia są jawne i fail-closed. Normalna ścieżka prowadzi od `draft` do `pending`, dalej opcjonalnie przez `requires_signature` i/lub `submitted`, a następnie do `success` albo `failed`. `draft` może zostać anulowany przed provider effect; anulowanie po rozpoczęciu efektu jest dozwolone tylko, gdy command policy jawnie potwierdza brak niejednoznacznego zewnętrznego skutku.

`success` i `cancelled` są terminalne. `failed -> pending` nie jest zwykłym statusem PATCH; może nastąpić wyłącznie przez przyszły jawny retry command zgodny z DB-PKK-004.

### 20.5 Confirmation boundary dla operacji mutujących

`update_and_return`, `return_to_school`, `return_to_authority` i `return_expired` wymagają formalnej pary `confirmed_at + confirmed_by_user_id` przed wejściem w `pending`. `fetch_profile` nie wymaga tej pary.

Nie fabrykujemy confirmation history dla legacy rows. Exact shape HTTP/UI pozostaje do późniejszego Stage 5 contract sync.

### 20.6 Timestamp equivalence i append-only lifecycle history

Current Operation row zachowuje spójność status/timestamps, a każda zmiana lifecycle tworzy append-only `pkk_operation_lifecycle_events` z kolejnym `operation_version`.

Historia zachowuje poprzedni status, nowy status, origin, actor/reason i czas przejścia. Timestamp ani UUID nie są authority dla kolejności eventów; authority stanowi wersja operacji.

### 20.7 Lock order i brak transakcji przez sieć

Command lifecycle lockuje lokalny kontekst w ustalonej kolejności i commit lokalnego stanu następuje przed wywołaniem providera. Transakcja DB nie pozostaje otwarta podczas requestu sieciowego.

Powrót odpowiedzi providera wykonuje nową krótką transakcję, która ponownie lockuje dokładną Operation i stosuje wyłącznie dozwolone przejście z oczekiwanej wersji. Retry, unknown effect i reconciliation pozostają w DB-PKK-004.

### 20.8 Relacja z edycją Course/PKK i konfiguracją

Start provider command serializuje się z edycją Course/PkkProfile tak, aby nie można było uruchomić efektu na profilu, który w tej samej chwili jest superseded. Gotowość konfiguracji jest re-checkowana przed submission.

Dokładne immutable binding do rewizji konfiguracji per provider Attempt pozostaje wyłącznie DB-PKK-007; DB-PKK-003 nie udaje, że zamyka ten blocker.

### 20.9 Migration safety

Legacy status jest akceptowany tylko wtedy, gdy jednoznacznie mapuje się do canonical lifecycle i jego timestampów. Nieznany/ambiguous status nie jest zgadywany. Brak historycznych lifecycle eventów może otrzymać wyłącznie jawny `legacy_baseline` dla udowodnionego current state; nie rekonstruujemy fikcyjnej sekwencji przejść.

Legacy równoległe active Operations dla jednego exact profile wymagają reviewed remediation przed włączeniem finalnej unique-active boundary.

### 20.10 Preservation gate

Pozostają OPEN: `DB-PKK-004`…`DB-PKK-008`. Nie zmieniono API, `specs/database/core-schema.yml`, `docs/87-physical-database-schema.md`, migracji Laravel ani UI.

Self-audit:

- canonical lifecycle/state matrix — **PASS**,
- UI status jako projection, nie authority — **PASS**,
- exact current Profile eligibility — **PASS**,
- one active Operation per exact current Profile — **PASS**,
- mutating confirmation boundary — **PASS**,
- append-only lifecycle history/versioning — **PASS**,
- DB transaction nie obejmuje provider network call — **PASS**,
- retry/reconciliation nie zostały przedwcześnie rozwiązane — **PASS**,
- configuration revision binding nie został przedwcześnie rozwiązany — **PASS**,
- DB-PKK-004…008 — **nadal OPEN**,
- frozen aggregates — **bez zmian**.

### 20.11 Wynik po fixerze

- P0 open: **0**,
- P1 open: **5**,
- resolved: **3 / 8**,
- wynik DB4_8: **FAIL_WITH_5_P1_BLOCKERS**.

Po centralnym gate jedynym dopuszczalnym następnym krokiem jest `DB-PKK-004 — idempotency, retry, reconciliation and exactly-once external effect`, wyłącznie po kolejnym jawnym poleceniu użytkownika.

**STOP przed DB-PKK-004.**

---

## 21. DB-PKK-004 — wynik fixera: PASS

Machine contract: `specs/database/pkk.yml`, clean machine commit `5e3956ffbfe2eb46208c5797471fe376b2b40c22`.

### 21.1 Idempotency HTTP a zewnętrzny efekt to dwie różne granice

`Idempotency-Key` pozostaje wspólnym authority dla ponowienia komendy HTTP. Runtime PKK wiąże przyjętą komendę z trwałym `idempotency_records`, a ten sam key z tym samym canonical request hash zwraca ten sam wcześniej zaakceptowany wynik biznesowy bez utworzenia drugiej `PkkOperation` ani drugiego provider Attemptu.

Ten sam key użyty z innym request hash jest konfliktem. Replay nadal wymaga bieżącej autoryzacji do odczytu pierwotnej Operation; znajomość klucza nie jest tokenem dostępu.

### 21.2 Operation i provider Attempt mają jawne powiązanie z idempotency evidence

Runtime `pkk_operations` posiada exact same-tenant binding do initial idempotency record. Historyczne legacy rows bez dowodliwego key/hash mogą zachować jawny migration-only brak bindingu, ale po cutover nowe Operation muszą go mieć.

Każdy runtime `pkk_operation_attempt` jest również związany z exact command idempotency record. Jeden retry command nie może więc przez race lub HTTP replay utworzyć dwóch transportowych Attemptów.

### 21.3 Deterministyczna sekwencja provider Attemptów

`attempt_no >= 1` jest unikalny per exact Operation i przydzielany pod `PkkOperation FOR UPDATE`. Nowe committed runtime Attempty używają kolejnego numeru i rollback nie konsumuje numeru.

Nie renumerujemy legacy Attemptów, żeby stworzyć pozornie idealną historię. Niejednoznaczne albo duplikujące się historyczne numery wymagają reviewed remediation.

### 21.4 Dispatch claim i jeden replay-blocking Attempt

Canonical transport status ma rozdzielone stany `prepared`, `dispatching`, `succeeded`, `safe_failed`, `effect_unknown`, `reconciled_no_effect`, `reconciled_effect`.

Dla jednej Operation może istnieć maksymalnie jeden replay-blocking Attempt (`prepared|dispatching|effect_unknown`). Worker może wysłać request providera dopiero po atomowym zwycięstwie przejścia `prepared -> dispatching`. Drugi worker widzący `dispatching` nie może wysłać requestu ponownie.

Provider I/O pozostaje poza transakcją DB.

### 21.5 Timeout nie oznacza bezpiecznego failed

Najważniejsza granica bezpieczeństwa: timeout, zerwane połączenie albo crash po rozpoczęciu dispatchu nie dowodzą, że provider nie wykonał operacji.

Taki Attempt przechodzi do `effect_unknown`, a nie do zwykłego retryable failure. Nowy provider dispatch jest zablokowany, dopóki zewnętrzny skutek pozostaje możliwy.

### 21.6 Retry disposition

Canonical retry disposition to:

- `safe_to_retry` — istnieje dowód, że zewnętrzny efekt nie zaszedł,
- `not_retryable` — ponowienie jest niedozwolone,
- `reconciliation_required` — efekt jest nieznany.

Dawne prowizoryczne boolean `retryable` nie jest po cutover samodzielnym runtime authority. Business validation error albo definitywne odrzucenie przez providera nie może zostać automatycznie zakwalifikowane do blind retry.

### 21.7 Append-only reconciliation evidence

Nowy `pkk_operation_attempt_reconciliations` przechowuje append-only historię reconciliation exact-bound do tenant/Course/Profile/Operation/Attempt.

Każda reconciliation dostaje ciągły `reconciliation_sequence` przydzielany pod lockiem Attemptu oraz wynik `effect_present|effect_absent|inconclusive`. Pierwszy conclusive outcome zamyka normalną ścieżkę reconciliation; sprzeczne późniejsze conclusive result nie są zwykłym runtime update.

Pełny wrażliwy provider payload nie trafia do tej tabeli. Może ona przechowywać wyłącznie evidence hash i allowlisted safe summary; pełna kryptografia payloadów pozostaje DB-PKK-006.

### 21.8 Reconciliation przed ponownym dispatch

`effect_absent` może pozwolić na jawny retry dopiero po ponownym sprawdzeniu wszystkich bieżących warunków DB-PKK-003. `effect_present` oznacza brak replay; Operation może zostać uznana za success tylko wtedy, gdy adapter ma wystarczający dowód faktycznego zamierzonego efektu.

`inconclusive` pozostawia Attempt jako `effect_unknown` i blokuje retry.

Jeżeli provider nie oferuje wiarygodnej reconciliation i nie ma natywnej idempotency, system nie zgaduje. Automatyczny retry pozostaje zabroniony nawet kosztem liveness.

### 21.9 Retry command

`POST .../retry` nie tworzy nowej business Operation. Po durable idempotency claim command lockuje Course, current PkkProfile, istniejącą Operation oraz właściwy ostatni Attempt i ponownie sprawdza exact context, current profile, status `failed`, brak replay-blocking Attemptu, `safe_to_retry`, gotowość integracji oraz `pkk.retry` + scope.

Udany nowy retry tworzy kolejny `prepared` Attempt, przeprowadza zarezerwowane w DB-PKK-003 przejście `failed -> pending`, zwiększa `operation_version` raz i appenduje lifecycle event. Provider dispatch następuje dopiero po commit.

### 21.10 Zakres gwarancji exactly-once

DB-PKK-004 nie twierdzi, że może zagwarantować fizyczne exactly-once po stronie obcego systemu bez wsparcia providera.

Gwarancja naszego systemu jest precyzyjna: **nie wykonujemy świadomie drugiego provider dispatch, dopóki poprzedni mógł już spowodować zewnętrzny business effect**. Unknown effect blokuje replay; retry wymaga dowodu no-effect albo zweryfikowanej provider-native idempotency w przyszłym adapter contract.

To jest fail-closed safety boundary, a nie optymistyczne „timeout = spróbuj ponownie”.

### 21.11 Migration safety

Migracja nie może fabrykować historycznych Idempotency-Key ani request hash z obecnego mutable stanu. Nie może wiązać starej Operation z przypadkowym current idempotency record, renumerować Attemptów, oznaczać unknown jako safe failure ani tworzyć fikcyjnych reconciliation events.

Jeżeli można dowieść exact immutable legacy request + key + command scope, generic idempotency record może zostać odtworzony z jawnym legacy provenance. W pozostałych przypadkach brak historycznego bindingu pozostaje jawny i wymaga reviewed remediation.

### 21.12 Preservation gate

Pozostają OPEN: `DB-PKK-005`…`DB-PKK-008`. Nie rozwiązano podpisanego XML, pełnego payload crypto, configuration revision binding ani deterministic latest/history projection. Nie zmieniono API, `core-schema.yml`, `docs/87`, migracji Laravel ani UI.

Self-audit:

- generic idempotency replay authority — **PASS**,
- same key/same hash nie tworzy drugiej Operation/Attempt — **PASS**,
- one replay-blocking Attempt per Operation — **PASS**,
- dispatch claim chroni przed duplicate worker send — **PASS**,
- timeout/connection loss -> unknown, nie safe failure — **PASS**,
- reconciliation append-only + exact Attempt binding — **PASS**,
- retry wymaga definitywnego no-effect proof — **PASS**,
- brak provider-native exactly-once nie został zmyślony — **PASS**,
- DB-PKK-001…003 — **zachowane**,
- DB-PKK-005…008 — **nadal OPEN**,
- frozen aggregates — **bez zmian**.

### 21.13 Wynik po fixerze

- P0 open: **0**,
- P1 open: **4**,
- resolved: **4 / 8**,
- wynik DB4_8: **FAIL_WITH_4_P1_BLOCKERS**.

Po centralnym gate jedynym dopuszczalnym następnym krokiem jest `DB-PKK-005 — signed XML handoff and FileAsset evidence integrity`, wyłącznie po kolejnym jawnym poleceniu użytkownika.

**STOP przed DB-PKK-005.**
---

## 22. DB-PKK-005 — wynik fixera: PASS

Machine contract: `specs/database/pkk.yml`, clean machine commit `a8df64ef06fd900004a36499728f9b9299f7ef7b`.

### 22.1 Podpis XML jest zdolnością warunkową providera

Potwierdzony flow produktu zachowujemy dokładnie jako `pobierz XML -> podpisz zewnętrznie -> wgraj podpisany XML -> wyślij do providera`, ale nie wymuszamy go na każdej mutującej operacji PKK. To adapter, po zweryfikowaniu konkretnego kontraktu providera, rozstrzyga czy dana operacja wymaga zewnętrznego podpisu.

DB-PKK-005 nie inventuje formatu XAdES, profilu podpisu, sposobu walidacji `podpis.gov.pl` ani provider-specific XML schema. Kontrakt DB dowodzi tożsamości, kontekstu i hashy artefaktów; kryptograficzna ważność podpisu pozostaje odpowiedzialnością zweryfikowanego adapter contract.

### 22.2 Durable authority `pkk_signature_handoffs`

`requires_signature` nie może być wyłącznie etykietą UI. Każdy runtime transition do tego stanu posiada dokładny `pkk_signature_handoff` związany z tym samym organization, CourseEnrollment, PkkProfile i PkkOperation.

Handoff ma deterministyczny `handoff_no`, wskazuje dokładną wersję lifecycle eventu `requires_signature`, opcjonalnie dokładny provider Attempt, który wygenerował unsigned XML, oraz write-once identyfikatory i hashe unsigned/signed artefaktu. Normalny hard delete i reparenting są zabronione.

### 22.3 Unsigned XML musi istnieć przed `requires_signature`

Unsigned XML używa istniejącego `FileAsset` z exact purpose `pkk_unsigned_xml_to_sign`. Przed utworzeniem handoffu asset musi należeć do tego samego tenant, być `ready`, mieć niepusty SHA-256, dodatni rozmiar oraz przejść content/MIME/security policy właściwe dla XML.

`unsigned_sha256` w handoffie musi równać się hash FileAsset. Raw XML nie jest kopiowany do lifecycle eventów, idempotency response snapshotów ani bezpiecznych projekcji UI.

### 22.4 `pending -> requires_signature` jest jednym atomowym boundary

Transition lockuje dokładną PkkOperation, weryfikuje expected version/status i adapter requirement, alokuje handoff, wiąże ready unsigned FileAsset, zwiększa operation version dokładnie raz oraz appenduje lifecycle event tej samej wersji.

Commit nie może pozostawić `requires_signature` bez dowodu, który unsigned XML użytkownik ma podpisać. Provider I/O nie jest wykonywane wewnątrz tej transakcji.

### 22.5 Signed upload jest rezerwowany dla jednego exact handoff

Generic purpose-bound upload jest niewystarczający jako dowód zamiaru. Dlatego `pkk_signature_handoff_upload_reservations` rezerwuje konkretny FileAsset dla dokładnego handoffu przed przyjęciem go jako signed evidence.

Reservation niesie organization, handoff, Operation, Course i Profile context, jest immutable i nie może zostać zwyczajnie usunięte. Jeden FileAsset nie może być zarezerwowany do dwóch handoffów.

### 22.6 Signed XML acceptance jest write-once i bez cross-operation reuse

Signed asset ma purpose `pkk_signed_xml_return`, musi być same-tenant, `ready`, posiadać SHA-256, dodatni rozmiar, zweryfikowany detected content/MIME policy oraz dokładną rezerwację dla tego handoffu.

Po akceptacji `signed_file_asset_id` i `signed_sha256` są write-once. Próba podmiany innym plikiem jest konfliktem, a ten sam signed asset nie może zostać użyty dla innej Operation ani innego handoffu. Replay tego samego attachment command może jedynie zwrócić istniejący stan.

### 22.7 FileAsset pozostaje jedynym authority dla binarnego artefaktu

DB-PKK-005 nie tworzy drugiego systemu storage. Reużywa wspólny `file_assets`: purpose, status, sha256, size, detected MIME i storage identity.

Po formalnym związaniu unsigned/signed XML nie wolno normalnie oznaczyć assetu jako deleted, podmienić obiektu pod tym samym FileAsset id ani zmienić hash/size/detected MIME/purpose. Nazwa pliku nie jest authority integralności.

Hash jest dowodem integralności treści, nie szyfrowaniem. Poufność XML, encrypted storage boundary i key-version semantics pozostają wyłącznie DB-PKK-006.

### 22.8 Submission Attempt musi wskazywać exact signature handoff

`pkk_operation_attempts` otrzymuje nullable `signature_handoff_id` z exact-context FK do organization/Operation/Course/Profile. Runtime submission po podpisie ma dokładnie jeden handoff; normalny provider Attempt niezwiązany z podpisem ma `NULL`.

Dla jednego handoffu może istnieć co najwyżej jeden runtime submission Attempt. Retry po podpisanym submission nie może automatycznie użyć starego handoffu; domyślnie nowy cykl wymagający podpisu tworzy nowy handoff, chyba że przyszły zweryfikowany provider contract jawnie dopuści inne zachowanie.

### 22.9 Signed XML submit korzysta z istniejącego idempotency authority

Komenda submission ma canonical operation key `pkk.signed_xml.submit`. Request hash obejmuje exact organization/Course/Operation/handoff/signed FileAsset/signed hash.

Ten sam key + ten sam hash zwraca tę samą Operation i submission Attempt bez nowego dispatchu. Ten sam key z innym hashem jest konfliktem. Safe response nie może zawierać raw XML ani storage key.

### 22.10 `requires_signature -> submitted` jest atomowe przed provider dispatch

Po idempotency claim lock order obejmuje Course, exact current PkkProfile, PkkOperation, current signature handoff i signed FileAsset. Recheck potwierdza current profile, status `requires_signature`, zgodność handoff version, write-once signed evidence, readiness/purpose/hash/content policy, brak consume/cancel, brak replay-blocking Attemptu, integration readiness i permission/scope.

W jednej lokalnej transakcji powstaje kolejny `prepared` provider Attempt związany z handoffem, Operation przechodzi do `submitted`, version rośnie raz, lifecycle event jest appendowany, `consumed_at` zostaje ustawione raz, a idempotency record wskazuje tę samą Operation. Dopiero po commit może nastąpić provider dispatch.

Jeżeli lokalna transakcja nie commitnie, Operation pozostaje `requires_signature`; nie powstaje durable provider Attempt ani dispatch effect, a signed evidence pozostaje zachowane.

### 22.11 Wyjście z signature flow nie niszczy evidence

DB-PKK-003 zachowuje przejścia `requires_signature -> failed|cancelled`. Handoff i unsigned/signed artefakty pozostają w historii. Handoff porzucony bez submission dostaje write-once `cancelled_at`; nie jest przepinany do nowej Operation lub nowej rewizji PkkProfile.

### 22.12 Authorization i download są przez exact PKK context

UI nie dostaje surowego `storage_key`. Download unsigned XML oraz ewentualny historyczny signed XML jest autoryzowany przez exact Operation/handoff context i permission/scope z DB-PKK-003. Sama znajomość FileAsset id nie stanowi tokenu dostępu.

### 22.13 Migration safety

Migracja nie może fabrykować handoffu, podpisu, hashy ani roli pliku na podstawie nazwy, timestampu, „latest upload” lub samego tenant match. Legacy `requires_signature` bez dokładnego unsigned artifact pozostaje jawną evidence gap wymagającą reviewed remediation.

Legacy submitted/completed nie może być automatycznie uznane za flow bez podpisu ani powiązane z przypadkowym same-tenant XML. Import historycznego XML jest możliwy tylko gdy exact provenance i rola unsigned/signed są dowodliwe, a bytes można zweryfikować i wyliczyć SHA-256.

### 22.14 Stage-5 API gaps pozostają jawnie odroczone

Obecny Stage-3 OpenAPI nie ma jeszcze dedykowanego endpointu pobrania unsigned XML, rezerwacji/upload context dla signed XML ani attach/submit signed XML. Generic upload nie niesie jeszcze parent handoff context, a `PkkOperation` nie prezentuje signature-handoff state.

To są acceptance/API sync gaps do Stage 5. DB-PKK-005 nie modyfikuje plików API i nie wymyśla finalnego HTTP shape przed adapter contract.

### 22.15 Preservation gate

Pozostają OPEN: `DB-PKK-006`, `DB-PKK-007`, `DB-PKK-008`. Nie rozwiązano szyfrowania provider payload/XML, configuration revision binding ani deterministic latest/history projection. Nie zmieniono `core-schema.yml`, `docs/87`, API, migracji Laravel ani UI.

Self-audit:

- signature requirement jest adapter-verified, nie globalnie hardcoded — **PASS**,
- `requires_signature` posiada durable exact-context handoff — **PASS**,
- unsigned XML ready/purpose/hash bound przed transition — **PASS**,
- signed upload rezerwowany dla exact handoff — **PASS**,
- signed asset write-once i bez cross-operation reuse — **PASS**,
- FileAsset pozostaje binary authority — **PASS**,
- submission Attempt ma exact handoff binding — **PASS**,
- signed XML submit idempotency reużywa DB-PKK-004 — **PASS**,
- `requires_signature -> submitted` atomowe przed dispatch — **PASS**,
- cryptographic signature validity nie została zmyślona — **PASS**,
- migracja nie fabrykuje historycznych XML/signature evidence — **PASS**,
- DB-PKK-001…004 — **zachowane**,
- DB-PKK-006…008 — **nadal OPEN**,
- frozen aggregates — **bez zmian**.

### 22.16 Wynik po fixerze

- P0 open: **0**,
- P1 open: **3**,
- resolved: **5 / 8**,
- wynik DB4_8: **FAIL_WITH_3_P1_BLOCKERS**.

Po centralnym gate jedynym dopuszczalnym następnym krokiem jest `DB-PKK-006 — sensitive provider payload encryption, redaction, hash and key-version boundary`, wyłącznie po kolejnym jawnym poleceniu użytkownika.

**STOP przed DB-PKK-006.**

## 23. DB-PKK-006 — wynik fixera: PASS

### 23.1. Zakres zamknięcia

DB-PKK-006 zamyka wyłącznie bezpieczeństwo wrażliwych payloadów integracji PKK: minimalizację retencji, szyfrowanie raw evidence, integralność i lineage, redakcję projekcji bezpiecznych dla UI/logów oraz kompatybilność z rotacją kluczy. Nie zmienia semantyki DB-PKK-001…005 i nie rozwiązuje DB-PKK-007 ani DB-PKK-008.

Provider-specific schema pozostaje własnością zweryfikowanego adaptera. Ten etap nie wymyśla zewnętrznych nazw pól, statusów ani dodatkowych danych protokołu.

### 23.2. Minimalizacja przed szyfrowaniem

Canonical policy ma dwa jawne tryby retencji:

- `redacted_only` — brak trwałego raw ciphertextu; zachowujemy tylko bezpieczną, wersjonowaną projekcję i hash źródłowych bajtów,
- `encrypted_raw_plus_redacted` — pełny raw payload jest utrwalany wyłącznie jako zaszyfrowane immutable evidence, razem z bezpieczną projekcją.

Domyślną zasadą jest minimalizacja: pełny raw payload nie jest przechowywany tylko dlatego, że technicznie da się go zapisać. Decyzję retencyjną podejmuje wersjonowana platformowa polityka bezpieczeństwa/retencji, a jej wersja jest zapisywana przy capture.

Plaintext provider payload nie może stać się trwałym rekordem przed szyfrowaniem. Może istnieć jedynie w pamięci procesu przez minimalny czas potrzebny do canonicalization, walidacji, redakcji i ewentualnego szyfrowania.

### 23.3. Canonical raw evidence — `pkk_protected_payloads`

Po cutover jedynym canonical runtime authority dla retained raw provider evidence jest `pkk_protected_payloads`.

Każdy rekord ma:

- `organization_id`,
- zamkniętą provider-neutral rolę payloadu,
- dokładnie jeden właściwy parent: provider snapshot, operation, provider attempt albo reconciliation,
- hash canonical plaintext bytes,
- wersję crypto profile i AAD schema,
- nonce, ciphertext i auth tag,
- hash dokładnych ciphertext bytes,
- wersję polityki retencji.

Database boundary odrzuca payload związany z niewłaściwym parentem lub tenantem. Normalny update raw evidence jest zabroniony; normalny hard-delete jest zabroniony poza kontrolowanym procesem retencyjnym.

### 23.4. Authenticated encryption i exact-context AAD

Runtime crypto profile v1 używa `AES-256-GCM` z losowym 256-bitowym DEK per protected payload, 96-bitowym nonce i 128-bitowym authentication tagiem. Algorytm i profil kryptograficzny nie są wybierane przez klienta.

Canonical AAD obejmuje co najmniej:

- wersję schematu AAD,
- `organization_id`,
- rolę payloadu,
- exact parent kind,
- exact parent id,
- crypto profile version.

Oznacza to, że transplant ciphertextu pomiędzy tenantami, operacjami, attemptami albo snapshotami kończy się błędem uwierzytelnienia. Sam `SHA-256` nie zastępuje AEAD authentication.

### 23.5. Semantyka hashy pozostaje rozdzielona

`canonical_plaintext_sha256` identyfikuje dokładne canonical retained plaintext bytes i wiąże lineage projekcji.

`ciphertext_sha256` identyfikuje dokładne przechowywane ciphertext bytes i może służyć jako precheck integralności storage.

Właściwym kryptograficznym authority dla ciphertext + AAD pozostaje AEAD auth tag.

DB-PKK-002 `snapshot_content_hash` nie zmienia znaczenia: nadal wiąże normalized business snapshot envelope, a nie pełne raw provider bytes, nie jest szyfrowaniem i nie jest mechanizmem authentication.

### 23.6. Envelope encryption i rotacja kluczy

DEK nie jest przechowywany w plaintext. Dla każdego protected payload istnieje append-only historia `pkk_protected_payload_key_wrappings` z:

- contiguous `wrapping_revision`,
- wrapping profile version,
- provider code,
- opaque non-secret `kek_reference`,
- opaque non-secret `kek_version`,
- `wrapped_dek`.

Normalna rotacja KEK nie odszyfrowuje i nie szyfruje ponownie business ciphertextu. Tworzy wyłącznie nową wrapping revision dla tego samego DEK. Nie zmienia nonce, auth tagu, plaintext hash, DB-PKK-002 hash ani historycznych rekordów operation/attempt/snapshot.

Brak bieżącego klucza, błąd unwrap albo AEAD authentication failure failują zamknięcie operacji i przechodzą na ścieżkę security remediation; runtime nie może cicho fallbackować do starego wrappingu lub plaintextu.

### 23.7. Safe UI projection — append-only allowlist

Canonical safe projection po cutover to `pkk_payload_redacted_projections`.

Każda projekcja jest związana z exact tenant + parent + payload role, ma wersję polityki redakcji i retencji, source plaintext hash, revision oraz hash canonical redacted JSON.

Redakcja jest allowlist-based, nie blacklist-based:

- nieznane lub niesklasyfikowane provider fields są domyślnie odrzucane,
- raw provider JSON nie może być kopiowany bezpośrednio jako „redacted JSON”,
- zmiana polityki redakcji tworzy nową projection revision zamiast nadpisywać historię,
- UI może korzystać z tej projekcji jako read modelu, ale projekcja nie jest pełnym evidence authority.

### 23.8. Jeden runtime authority po cutover

Provisional pola payloadów obecne wcześniej na `pkk_provider_profile_snapshots`, `pkk_operations` i `pkk_operation_attempts` przestają być runtime authority po cutover.

Canonical raw authority: `pkk_protected_payloads`.

Canonical safe projection authority: `pkk_payload_redacted_projections`.

Dwa równoległe, mutowalne payload authorities są zabronione.

### 23.9. Zakaz leakage do systemów pomocniczych

Raw provider plaintext, raw provider error body, ciphertext i wrapped key material nie mogą trafiać do:

- `audit_logs`,
- activity feed,
- outbox,
- notifications,
- idempotency safe response snapshot,
- application logs,
- generic error messages.

Do trwałych logów/eventów mogą wejść jedynie provider-neutral error class, bezpiecznie zsanityzowany adapter code/message, bezpieczny request/correlation id i autoryzowany reference do protected payload albo safe projection.

Preferujemy referencję do chronionego evidence zamiast kopiowania payloadu.

### 23.10. Transakcje capture

W trybie `redacted_only` system canonicalizuje transient payload, liczy source hash, wykonuje wersjonowaną allowlist redaction, zapisuje projection i usuwa raw plaintext z pamięci. Nie powstaje trwały raw ciphertext i system nie może później twierdzić, że zachował pełne raw evidence.

W trybie `encrypted_raw_plus_redacted` system generuje DEK/nonce, buduje exact-context AAD, wykonuje AEAD, wrapuje DEK, tworzy bezpieczną projekcję i zapisuje protected payload + pierwszy wrapping + projekcję atomowo w jednej lokalnej transakcji. Partial envelope bez key wrapping lub safe projection nie może się zatwierdzić.

Provider network I/O nie jest wykonywany wewnątrz tej transakcji persistence.

### 23.11. Signed XML z DB-PKK-005 — poufność bez zmiany FileAsset identity

DB-PKK-005 pozostaje źródłem prawdy dla exact signed/unsigned XML handoff i `FileAsset` identity/hash. DB-PKK-006 dodaje poufność final storage.

Finalny `ready` PKK XML object musi być application-encrypted. `FileAsset.sha256` nadal oznacza hash zweryfikowanego plaintext XML; osobny `encrypted_storage_object_sha256` oznacza hash finalnego encrypted storage object.

`pkk_signature_file_asset_protections` wiąże exact handoff, FileAsset, rolę `unsigned|signed`, crypto profile, nonce i auth tag. Historia wrappingu DEK dla takich assetów jest append-only w `pkk_signature_file_asset_key_wrappings`.

Rotacja klucza nie zmienia FileAsset plaintext hash, handoff binding ani encrypted object — tworzy nową wrapping revision.

### 23.12. Bezpieczny ingest XML

Presigned upload może trafić do tymczasowego quarantine object. `FileAsset` nie może przejść do `ready`, dopóki nie przejdą:

1. exact tenant + handoff validation z DB-PKK-005,
2. size/content sniffing i wymagany scan,
3. verification plaintext SHA-256,
4. application encryption do final private object,
5. zapis exact protection row i pierwszego wrappingu,
6. usunięcie tymczasowego plaintext object po udanej finalizacji.

Błąd skanu lub szyfrowania nie może oznaczyć FileAsset jako `ready`. Download odszyfrowuje dopiero po exact PKK authorization.

### 23.13. Migracja — fail closed, bez fabrykowania crypto history

Legacy plaintext payload nie staje się automatycznie trwałym raw evidence. Może zostać zaszyfrowany tylko, gdy exact parent jest udowodniony i polityka retencji wymaga raw evidence; w przeciwnym razie dopuszczalna jest wyłącznie deterministycznie bezpieczna projekcja po zweryfikowanym cutover.

Legacy ciphertext bez algorytmu, nonce, auth tagu, AAD semantics lub key reference nie może zostać nazwany authenticated runtime envelope.

Legacy redacted JSON bez redaction-policy lineage i source hash nie może zostać cicho awansowany do bieżącej safe projection.

Nie wolno zgadywać crypto profile, key version ani redaction policy version. Migracja nie odpytuje providera ani zewnętrznej usługi podpisu w celu rekonstrukcji historii.

Legacy DB-PKK-002 snapshot hash nie jest przeliczany pod nowy ciphertext.

### 23.14. Stage 5/API gaps pozostają odłożone

Ten etap nie modyfikuje OpenAPI. Safe profile/operation projections nadal nie eksponują crypto internals, wrapped DEK ani storage keys. Exact provider payload schema pozostaje adapter-owned. Admin surface dla polityk retencji/redakcji nie jest projektowany w DB-PKK-006.

### 23.15. Self-audit

- minimization before encryption — PASS,
- retained raw AEAD envelope — PASS,
- exact tenant/parent AAD transplant protection — PASS,
- append-only key wrapping history — PASS,
- KEK rotation bez business ciphertext rewrite — PASS,
- DB-PKK-002 hash semantics preserved — PASS,
- append-only allowlisted redacted projection lineage — PASS,
- audit/outbox/log/idempotency leakage exclusion — PASS,
- provisional payload fields deauthorized after cutover — PASS,
- DB-PKK-005 FileAsset identity preserved + XML confidentiality closed — PASS,
- provider-specific schema not invented — PASS,
- legacy crypto/redaction history not guessed — PASS,
- DB-PKK-001…005 preserved — PASS,
- DB-PKK-007 OPEN — preserved,
- DB-PKK-008 OPEN — preserved,
- aggregate files unchanged — PASS,
- API files unchanged — PASS,
- Laravel migrations not created — PASS,
- DB4_9+ not entered — PASS,
- Stage 5 not started — PASS,
- UI/feature implementation not started — PASS.

**Stan po DB-PKK-006:** `0 P0 / 2 P1 OPEN`, `6/8 resolved`, wynik `FAIL_WITH_2_P1_BLOCKERS`.

Następny dopuszczalny blocker po central gate: `DB-PKK-007`.

**STOP przed DB-PKK-007.**

## 24. DB-PKK-007 — wynik fixera: PASS

### 24.1. Zakres zamknięcia

DB-PKK-007 zamyka wyłącznie historyczną rewizję konfiguracji wykonawczej PKK oraz jej wiązanie z asynchronicznymi provider attemptami. Nie zmienia canonical settings ownership, nie kopiuje gate'a PKK do drugiego modelu i nie rozwiązuje DB-PKK-008.

### 24.2. Dwa różne concurrency/version roots

`organization_settings.version` pozostaje wspólnym optimistic-concurrency rootem całego ekranu ustawień.

Dodatkowo `pkk_integration_settings.execution_configuration_revision` staje się wąskim numerem rewizji konfiguracji wykonawczej PKK. Rośnie tylko po materialnej zmianie danych wpływających na integrację PKK lub readiness, a nie po każdej zmianie danych firmy/UI.

Zmiana imienia lub nazwiska użytkownika sama w sobie nie tworzy nowej rewizji wykonawczej, ponieważ nie potwierdzono, że te pola są provider execution inputs; actor nadal jest identyfikowany przez istniejący `actor_user_id`.

### 24.3. Append-only historia konfiguracji

Nowa tabela `pkk_integration_configuration_revisions` przechowuje append-only evidence każdej runtime rewizji:

- organization,
- `execution_configuration_revision`,
- `organization_settings_version_at_capture`,
- school name snapshot,
- OSK registry number snapshot,
- keyed binding HMAC dla external OSK login,
- readiness snapshot,
- keyed HMAC całej canonical execution configuration,
- origin i czas utworzenia.

Rewizja nie jest wyznaczana z `updated_at`, UUID ani timestampu. Runtime rewizje są przydzielane sekwencyjnie pod tym samym lockiem `organization_settings`.

### 24.4. Brak plaintext credential history

Pełny `Login OSK` nie jest kopiowany do operation/attempt/revision history. Historia przechowuje keyed HMAC powiązania logicznej wartości.

Tak samo przyszłe sekrety/credential values nie mogą zostać skopiowane do historii. Jeśli zweryfikowany adapter w przyszłości dostanie versioned secret reference, jego wersja może wejść do configuration binding evidence, ale nie sam sekret.

### 24.5. Attempt wiąże dokładną rewizję

Każdy runtime `pkk_operation_attempt` ma obowiązkowe `integration_configuration_revision` z same-tenant composite FK do dokładnej rewizji konfiguracji.

Binding jest immutable po insert. Initial attempt oraz każdy retry wiążą rewizję niezależnie.

Jeżeli operator zmieni konfigurację po wcześniejszej porażce, nowy retry może jawnie związać nową rewizję. Stary attempt nigdy nie jest przepinany do nowej konfiguracji.

### 24.6. Organization-wide PKK execution serialization

DB-PKK-007 wprowadza transaction-scoped advisory lock albo równoważny database serialization key dla `(organization_id, PKK integration execution domain)`.

Ten lock jest wspólny dla:

- materialnej zmiany konfiguracji PKK,
- tworzenia/bindowania provider attemptu,
- dispatch claim,
- reconciliation command wykonującego provider I/O.

Lock jest pobierany przed istniejącymi row-lockami, dzięki czemu nie odwraca dotychczasowych lock orders DB-PKK-003/004.

### 24.7. Zmiana konfiguracji

Materialna zmiana PKK:

1. pobiera organization PKK execution lock,
2. blokuje `organization_settings FOR UPDATE`,
3. waliduje permission + istniejący If-Match,
4. odrzuca zmianę, jeśli istnieje `dispatching` lub `effect_unknown` attempt,
5. aktualizuje canonical `pkk_integration_settings`,
6. przelicza readiness,
7. inkrementuje `organization_settings.version`,
8. gdy execution configuration rzeczywiście się zmieniła — inkrementuje `execution_configuration_revision` i dopisuje dokładnie jeden revision row,
9. zapisuje redacted audit,
10. commit.

Provider I/O nie jest wykonywany w tej transakcji.

### 24.8. Prepared attempt po zmianie konfiguracji

`prepared` attempt nie blokuje zmiany konfiguracji. Jego historyczny binding pozostaje bez zmian.

Przy późniejszej próbie dispatch system porównuje bound revision z current revision. Jeżeli nie są identyczne, attempt przechodzi do `safe_failed` **przed jakimkolwiek provider I/O**. Nie jest to `effect_unknown`, bo zewnętrzne wywołanie jeszcze nie nastąpiło.

Nie wolno wysłać starego attemptu z nową konfiguracją.

### 24.9. Dispatch claim

Dotychczasowy DB-PKK-004 `prepared -> dispatching` pozostaje authority dla one-sender boundary.

DB-PKK-007 dodaje przed nim organization execution lock oraz recheck:

- bound revision == current execution revision,
- current configuration binding HMAC == immutable revision evidence,
- readiness nadal pozwala na wykonanie zgodnie z DB-PKK-003.

Dopiero po zgodnym rechecku można commitować `dispatching`. Provider network I/O następuje po commit, używając jedynie ephemeral decrypted execution material w pamięci workera przez minimalny czas potrzebny do dispatch.

### 24.10. Dispatching/effect_unknown blokują materialną zmianę configu

Gdy attempt jest `dispatching`, materialna zmiana konfiguracji PKK jest odrzucana do czasu klasyfikacji transport result.

Gdy attempt jest `effect_unknown`, materialna zmiana również jest blokowana, żeby nie utracić konfiguracji potrzebnej do fail-closed reconciliation.

Unrelated settings change, która nie zmienia konfiguracji wykonawczej ani readiness, nie jest fałszywie blokowana.

### 24.11. Reconciliation używa tej samej rewizji

Provider reconciliation po `effect_unknown` musi działać w kontekście tej samej rewizji, do której był przypisany attempt.

Nie zakładamy, że provider reconciliation jest niezależny od credential/config context. Jeżeli awaryjne security revoke uniemożliwia dalsze użycie konfiguracji, system wybiera fail-closed/manual security remediation zamiast cicho rekoncyliować pod nową tożsamością integracyjną.

### 24.12. Readiness pozostaje provider-neutral

Zamknięty katalog readiness pozostaje:

- `not_configured`,
- `configured_unverified`,
- `verified`,
- `requires_attention`.

DB-PKK-007 nie wymyśla connection-test protocol. Zasada, czy `configured_unverified` może wykonywać operacje, pozostaje adapter-capability-dependent zgodnie z DB-PKK-003.

### 24.13. Migracja — bez timestamp guessing

Dla bieżącej konfiguracji można utworzyć legacy baseline revision `1` tylko, jeżeli aktualne logical values są jednoznaczne i możliwe do bezpiecznego odczytu.

Baseline nie udaje historii wcześniejszych zmian.

Historycznych attemptów nie wolno wiązać z current/nearest revision przez `created_at`, `updated_at`, UUID lub inne heurystyki. Bez trusted evidence historyczny attempt pozostaje oznaczony jako evidence gap do reviewed remediation.

Nie rekonstruujemy external login z logów/audytu i nie odpytujemy providera w celu odtworzenia historycznej konfiguracji.

### 24.14. Stage 5/API gaps

OpenAPI pozostaje nietknięte. Do późniejszej synchronizacji trafiają jedynie:

- dokładny HTTP error dla `configuration_changed_before_dispatch`,
- presentation dla zmiany ustawień zablokowanej przez `effect_unknown`,
- przyszły verified secret/config adapter contract.

Crypto internals i secret manager values nie stają się częścią publicznego API.

### 24.15. Self-audit

- settings concurrency root preserved — PASS,
- separate execution configuration revision — PASS,
- append-only revision history — PASS,
- exact attempt revision binding — PASS,
- async dispatch revision recheck — PASS,
- prepared stale attempt safe-fails before I/O — PASS,
- dispatching/effect_unknown config-change interlock — PASS,
- retry may bind new revision without rewriting old attempt — PASS,
- reconciliation same-revision boundary — PASS,
- no plaintext external login or secret history — PASS,
- provider-specific execution schema not invented — PASS,
- DB-PKK-001…006 preserved — PASS,
- DB-PKK-008 remains OPEN — PASS,
- aggregate files unchanged — PASS,
- API files unchanged — PASS,
- Laravel migrations not created — PASS,
- DB4_9+ not entered — PASS,
- Stage 5 not started — PASS,
- UI/feature implementation not started — PASS.

**Stan po DB-PKK-007:** `0 P0 / 1 P1 OPEN`, `7/8 resolved`, wynik `FAIL_WITH_1_P1_BLOCKER`.

Następny dopuszczalny blocker po central gate: `DB-PKK-008`.

**STOP przed DB-PKK-008.**
