# 111. Stage 4 — Licenses / Learning Access final aggregate sync audit

Data: 2026-09-07

**Etap:** `DB4_6_LICENSES_LEARNING_ACCESS`  
**Krok:** `DB4_6_FINAL_AGGREGATE_SYNC`  
**Status:** `PASS`  
**Blockery:** `DB-LIC-001..007 = PASS / 7 of 7 resolved / 0 P0 / 0 P1 open`

Audit blockerów i pełna historia decyzji pozostają w `docs/110-stage-4-licenses-learning-access-audit.md`. Ten plik jest wyłącznie addytywnym audytem końcowej synchronizacji agregatów i nie zastępuje ani nie skraca `docs/110`.

---

## 1. Warunek wejścia

Final aggregate sync rozpoczął się dopiero po:
- `DB-LIC-001..007 = PASS`,
- `open P0 = 0`,
- `open P1 = 0`,
- centralnym gate DB-LIC-007,
- zachowaniu frozen aggregate SHA przez cały blocker work.

Head przed odblokowaniem agregatów:
- `61a344701e8b527f93d6e91826dfdfa75b3560c4`.

Frozen blob SHA na tej granicy:
- `specs/database/core-schema.yml` = `69ee76f5e0bf630e6a9b08e43e43c4157a03fde7`,
- `docs/87-physical-database-schema.md` = `4eb0ef78b8d72e018c9ca16d1b5311f325f55583`.

Do final aggregate sync wolno było zmienić wyłącznie:
- `specs/database/core-schema.yml`,
- `docs/87-physical-database-schema.md`,
- końcowy audit/gate.

DB4_7+, Stage 5, OpenAPI contract sync, Laravel migrations i UI pozostały poza zakresem.

---

## 2. Machine aggregate sync

Pierwszy machine commit:
- `7e1ad06bf354f038e11b4a9663b28b887aa6b32b`,
- `DB4_6: synchronize licenses learning access aggregate machine schema`.

Self-audit po pierwszym zapisie wykrył brakującą jawność części DB4_6 constraintów. Nie ogłoszono PASS. Uzupełniono wyłącznie brakujące constraint boundaries w:
- `eec0f1450f1f99ee704539b7e07501fc22a1ae88`,
- `DB4_6: complete licenses learning access aggregate constraint projection`.

Finalny blob:
- `specs/database/core-schema.yml` = `5d8f4d661f56115740d3c3a59d8424c85ec1cf24`.

Compare:
- base: `61a344701e8b527f93d6e91826dfdfa75b3560c4`,
- head: `eec0f1450f1f99ee704539b7e07501fc22a1ae88`,
- dokładnie 1 zmieniony plik: `specs/database/core-schema.yml`,
- statystyka: `+472 / -45`.

Drugi machine commit dodawał tylko brakujące DB4_6 `critical_constraints`; nie usuwał ani nie przepisywał wcześniejszych domen.

### Machine semantic coverage

Machine aggregate zawiera końcowe DB-LIC-001..007 boundaries, w tym:
- `user_password_management`,
- `student_access_export_batches`,
- current same-User `AuthLoginIdentifier` boundary,
- LearningAccount lifecycle/version/operational eligibility,
- memory-only secret-bearing PDF policy,
- exact same-tenant Student/LearningAccount/Inventory Assignment target,
- Inventory↔Assignment↔Activation final-state equivalence,
- exactly-once revoke/consume semantics,
- Assignment sequence i entitlement sequence,
- immutable product duration po Inventory reference,
- immutable Inventory product binding,
- immutable Activation entitlement ledger,
- duration snapshot i activation provenance,
- migration-only provenance runtime guard,
- versioned `license_product_language_capabilities`,
- immutable Assignment language/capability snapshot,
- language-change guards,
- derived management projection,
- safe migration order i mandatory race/invariant tests.

### Machine stale-shortcut gate

Po finalnym machine sync canonical model nie używa już jako authority:
- `license_product_languages`,
- `primary_auth_login_identifier_id`,
- persisted `login_identifier_projection`,
- `max_nonrevoked_effective_to`,
- current Assignment definiowanego wyłącznie przez `revoked_at IS NULL`.

Wynik machine aggregate gate: **PASS**.

---

## 3. Narrative aggregate sync

Narrative commit:
- `940bbbfa3bf2308d667231830961174f6be64e4f`,
- `DB4_6: synchronize licenses learning access aggregate narrative`.

Finalny blob:
- `docs/87-physical-database-schema.md` = `191afe107e830baa928b18f67e6cb75b2d4a73b8`.

Compare:
- base: `eec0f1450f1f99ee704539b7e07501fc22a1ae88`,
- head: `940bbbfa3bf2308d667231830961174f6be64e4f`,
- dokładnie 1 zmieniony plik: `docs/87-physical-database-schema.md`,
- statystyka: `+357 / -76`.

Narrative sync zaktualizował wyłącznie obszary, które po DB-LIC-001..007 były stale lub niepełne:
- header/status DB4_6,
- Identity password-management authority,
- Student LearningAccount/Handoff/ExportBatch,
- pełną sekcję Licenses/Entitlement,
- security storage rules,
- FK/delete policy,
- query-driven indexes,
- obowiązkowe tests,
- migration order/safety,
- closed/pending decisions.

Wcześniejsze DB4_2–DB4_5 rozdziały pozostały obecne i semantycznie zachowane.

String `license_product_languages` występuje w narracji wyłącznie jako opis nazwy zastępowanego legacy modelu; nie jest canonical tabelą ani runtime authority.

Wynik narrative aggregate gate: **PASS**.

---

## 4. Cross-file semantic-loss gate

`specs/database/core-schema.yml` i `docs/87-physical-database-schema.md` są zgodne w krytycznych rozstrzygnięciach:

1. exact tenant/Student/LearningAccount/Inventory target,
2. current same-User AuthLoginIdentifier i brak niezależnej login projection,
3. LearningAccount `active|suspended`, version i archive eligibility,
4. global password authority + credential epoch,
5. exclusive organization-managed learner principal,
6. plaintext/secret-PDF non-persistence,
7. nonsecret Handoff/ExportBatch metadata,
8. Inventory↔Assignment↔Activation final-state equivalence,
9. immutable Activation entitlement ledger,
10. exact entitlement sequence/duration/effective chain,
11. current entitlement end = `MAX(effective_to)`,
12. versioned product-language capability,
13. immutable Assignment language + sequence,
14. capability retirement bez retroactive revoke,
15. language change przy zero pending i zero live/future entitlement,
16. derived latest/status/count/expiry/remaining/hide-finished,
17. Student archive nie pauzuje/extenduje entitlement i nie przepisuje historii,
18. migration nie zgaduje identity, password authority, Inventory state, Activation chain, Assignment order ani language capability.

Nie znaleziono semantic-loss, w którym bounded-context `specs/database/licenses-learning-access.yml` miałby silniejszą regułę niż agregat.

Wynik cross-file semantic gate: **PASS**.

---

## 5. Migration-order / invariant-test gate

Agregaty zachowują kolejność:
1. Identity/User/AuthIdentifier + PasswordManagement base,
2. Student/LearningAccount/Handoff/Batch + same-User identifier boundary,
3. dopiero potem LicenseProduct capability history + Inventory + Assignment + Activation,
4. na końcu cross-table/deferred guards.

Przed constraints wymagane są prechecks i reviewed remediation. Silent repair pozostaje zabroniony.

Mandatory tests pokrywają co najmniej:
- same-tenant/wrong-Student rejection,
- stale LearningAccount/credential/Assignment versions,
- secret non-persistence,
- one-time secret rendering,
- bulk all-or-none,
- assignment allocation race,
- revoke exactly once,
- activate-vs-revoke one winner,
- immutable Activation chain,
- concurrent extension no lost time,
- product-duration/product-binding immutability,
- migration-only provenance runtime rejection,
- product-language capability/history,
- language-change guards,
- deterministic latest/projection,
- DB4_6 migration non-guessing.

Wynik migration/test gate: **PASS**.

---

## 6. Audit preservation incident

Pierwsza próba dopisania końcowego wyniku bezpośrednio do `docs/110` utworzyła commit:
- `3e84b6ae518d2050d7d9ffba104880ecdb40d55d`.

Preservation compare wykazał:
- tylko `docs/110`, ale
- `+267 / -1667`.

Oznaczało to skondensowanie historycznych sekcji 1–25. Commit został **odrzucony przed centralnym PASS**.

Branch został cofnięty wyłącznie o ten audit commit do:
- `940bbbfa3bf2308d667231830961174f6be64e4f`.

Dzięki temu pełny `docs/110` pozostał bitowo zachowany. Finalny audit aggregate sync został zapisany jako ten osobny addytywny dokument `docs/111...`, zamiast ponownie ryzykować przepisywanie pełnej historii przez GitHub Contents API.

Wynik preservation incident gate: **PASS AFTER CORRECTION**.

---

## 7. Aggregate-only compare gate

Zakres machine+narrative sync przed audytem:

`61a344701e8b527f93d6e91826dfdfa75b3560c4 -> 940bbbfa3bf2308d667231830961174f6be64e4f`

Oczekiwane dokładnie 2 pliki:
- `specs/database/core-schema.yml`,
- `docs/87-physical-database-schema.md`.

`specs/database/licenses-learning-access.yml` nie został zmieniony podczas final aggregate sync.

Nie zmieniono:
- DB4_7+ bounded-context specs,
- OpenAPI/Stage-5 contracts,
- Laravel migrations,
- UI/feature implementation.

Wynik aggregate-only scope gate: **PASS**.

---

## 8. Finalny wynik DB4_6

- diagnosed blockers: **7**,
- resolved blockers: **7**,
- open P0: **0**,
- open P1: **0**,
- machine aggregate sync: **PASS**,
- narrative aggregate sync: **PASS**,
- semantic-loss gate: **PASS**,
- migration/test gate: **PASS**,
- preservation gate: **PASS**,
- final aggregate sync: **PASS**.

DB4_6 może otrzymać finalny centralny status **PASS**.

Po centralnym gate dozwolony będzie wyłącznie następny etap:

`DB4_7_INTERNAL_EXAMS_DIAGNOSIS`

— i dopiero po kolejnym jawnym poleceniu użytkownika.

Stage 5, Laravel migrations i UI pozostają zablokowane.

**STOP przed DB4_7.**
