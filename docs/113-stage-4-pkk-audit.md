# 113. Stage 4 — PKK database audit

Data: 2026-09-08

**Etap:** `DB4_8_PKK`  
**Aktualny krok:** `DB4_8_PKK_DIAGNOSIS`  
**Status:** `FAIL_WITH_8_P1_BLOCKERS / 0 P0 / 8 P1 OPEN`

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
