# 113. Stage 4 — PKK database audit

Data: 2026-09-08

**Etap:** `DB4_8_PKK`  
**Aktualny krok:** `DB_PKK_003_PROVIDER_OPERATION_LIFECYCLE_CONCURRENCY_AND_COMMAND_ELIGIBILITY`
**Status:** `FAIL_WITH_5_P1_BLOCKERS / 0 P0 / 5 P1 OPEN`

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
