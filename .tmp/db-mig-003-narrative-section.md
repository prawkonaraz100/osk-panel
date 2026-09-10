## 5. DB-MIG-003 — cutover, restart, failure i rollback safety

**Status: PASS / P1 RESOLVED**

DB-MIG-003 domyka globalny execution contract dla migracji Stage 4. Nie zmienia kolejności zależności z DB-MIG-001 ani siedmiofazowego modelu z DB-MIG-002. Dodaje trzeci wymiar: warunki wejścia i wyjścia z fazy, warunki przerwania, sposób wznowienia po awarii oraz dozwolone strategie rollbacku.

Machine authority to `migration_cutover_execution_contract` w `specs/database/final-migration-order-invariant-matrix.yml`. Kontrakt obejmuje wszystkie 170 node'ów DAG i rozstrzyga ich restart posture bez tworzenia migracji Laravel.

### 5.1. Execution control i restart safety

Migracja wymaga jednego aktywnego executora dla danego planu. Dokładny mechanizm lock/lease pozostaje decyzją implementacyjną, ale równoległe niezależne wykonanie tego samego planu jest zabronione. Po utracie executora takeover wymaga dowodu, że poprzedni executor nie jest już aktywny, oraz odnotowanej decyzji operatora.

Najważniejsza zasada restartu brzmi: **postcondition first, nigdy blind retry**. Po przerwaniu najpierw sprawdza się dokładny stan obiektu i częściowe efekty. Jeżeli oczekiwany postcondition już istnieje, krok można uznać za wykonany bez ponownego nakładania efektu. Jeżeli nie ma efektu i preconditions nadal obowiązują, automatyczne ponowienie jest dopuszczalne tylko dla node'a sklasyfikowanego `restart_safe`. Efekt częściowy, niejednoznaczny albo obiekt o innej definicji oznacza `manual_review` lub `STOP`.

Machine gate używa dwóch wartości: `restart_safe` i `manual_review`. Domyślnie `extension`, `table`, `foreign_key` i `trigger` są restart-safe po sprawdzeniu postcondition; `candidate_key`, `index`, `projection` i `constraint` wymagają manual review. Jawne override do manual review obejmuje także Calendar GiST, purchase downstream lineage, event lineage i event trigger, ponieważ ich fazy mogą obejmować konflikt, legacy backfill albo reconciliation.

`restart_safe` nie oznacza bezwarunkowej idempotencji. Każdy retry nadal wymaga sprawdzenia dokładnej definicji lub efektu wierszowego.

### 5.2. Entry / exit / abort dla siedmiu faz

Każda z siedmiu faz DB-MIG-002 ma teraz własny execution gate:

- `expand` — wejście wymaga zgodnego plan identity, jednego executora i kompatybilności aplikacji z forward-compatible schema; wyjście wymaga dokładnych postconditions i braku destructive change; niezgodny lub częściowy DDL zatrzymuje wykonanie,
- `preflight` — wejście wymaga gotowego expand i obowiązujących exact-evidence rules; wyjście wymaga pełnej inwentaryzacji i klasyfikacji naruszeń oraz zera konfliktów blokujących fence'y, których nie da się aktywować jako `NOT VALID`; brak lub konflikt evidence oznacza STOP,
- `write_fence` — writer musi być kompatybilny z nowym guardem; po wyjściu nowe write'y nie mogą tworzyć kolejnych legacy exceptions; nie wolno wyłączać guardu tylko po to, aby backfill przeszedł,
- `backfill` — działa tylko na exact durable evidence, z checkpoint/resume semantics tam, gdzie przerwanie może zostawić częściowy postęp; unproven rows trafiają do reconciliation, a retry nie może tworzyć podwójnego grant/event/payment effect,
- `reconcile` — wymagane przypadki muszą mieć trwały issue reason i bezpieczny fingerprint; przed validation liczba wymaganych nierozwiązanych P0/P1 musi wynosić zero; nie wolno fabrykować lineage, recipient, read state, payment ani inventory history,
- `validate` — write fence pozostaje aktywny, backfill i reconciliation muszą mieć PASS, a wszystkie final invariants/constraints i projection postconditions muszą przejść; failure blokuje przejście do `contract`,
- `contract` — wejście wymaga PASS validation, zera unresolved, decyzji o kompatybilności fallback application, zatrzymania starych writer paths oraz — dla produkcji i destructive/deauthorization scope — zdrowego backupu i udokumentowanego restore evidence. Przerwanie tej fazy zawsze wymaga manual review.

DB-MIG-003 nie ustala konkretnych limitów lock timeout, statement timeout ani wielkości batchy. To decyzje operacyjne wdrożenia, ale ich przekroczenie musi prowadzić do bezpiecznego abortu, a nie do omijania inwariantów.

### 5.3. Failure i resume matrix

Kontrakt rozróżnia rodzaje awarii zamiast stosować jedno ogólne „spróbuj ponownie”. Transactional DDL może być ponowiony dopiero po potwierdzeniu rollbacku transakcji i postcondition. Nieudany nontransactional/concurrent index build wymaga inspekcji partial/invalid artifact i manual review — blind `drop and recreate by name` jest zabroniony.

Przerwany backfill może być wznowiony tylko wtedy, gdy checkpoint i resume predicate są udowodnione jako idempotentne względem exact evidence. Przerwana privileged reconciliation nie jest automatycznie replayowana. Validation failure utrzymuje write fence, blokuje `contract` i prowadzi do forward-fix/reconciliation, po czym validation jest uruchamiana ponownie od własnego entry gate.

Jeżeli po zmianie DB psuje się health aplikacji, rollback aplikacji jest dopuszczalny tylko wtedy, gdy poprzednia/fallback wersja nadal jest kompatybilna z aktualnym schema i aktywnymi write fences. W przeciwnym razie wymagany jest schema forward-fix.

### 5.4. Rollback nie oznacza automatycznego `down`

DB-MIG-003 dopuszcza dokładnie trzy tryby rollback posture:

1. `application_rollback` — powrót do kompatybilnej wersji aplikacji bez cofania authoritative historii w bazie,
2. `schema_forward_fix` — preferowany po powstaniu trwałych efektów; zachowuje zatwierdzoną historię i naprawia kontrakt do przodu,
3. `explicit_safe_down` — wyłącznie jawnie opisany i zreviewowany reverse operation, gdy można udowodnić, że jest niedestrukcyjny i żaden authoritative write od niego nie zależy.

Generic/automatic destructive `down` nie jest strategią rollbacku dla formalnej, finansowej, audytowej ani innej wymaganej historii. Nie wolno także traktować restore starego backupu nad nowszymi prawidłowymi write'ami produkcyjnymi jako zwykłego rollbacku release.

Destructive `contract` wymaga zdrowego backupu i skutecznego restore evidence. Sam fakt istnienia backupu nie wystarcza. PITR/full restore pozostaje mechanizmem incident recovery, a nie domyślnym sposobem cofania schematu.

DB-MIG-003 nie wymyśla produkcyjnych RPO/RTO ani exact retention duration; pozostają one zewnętrzną polityką biznesową/legal/privacy zgodnie z `docs/85-production-operations.md`.

### 5.5. Final cutover smoke and integrity gate

Przed uznaniem cutoveru za zakończony wymagane są m.in. oczekiwane postconditions wszystkich node'ów wymaganych przez release, zero wymaganych unresolved migration cases, skuteczna final validation, brak niezaklasyfikowanych partial DDL artifacts, zgodne active write fences, health/readiness aplikacji oraz bounded-context migration/projection integrity. Destructive contract actions wymagają dodatkowo backup/restore evidence.

Ten smoke/integrity gate jest **release-level execution evidence**, a nie macierzą testów DB-TST-001. DB-MIG-003 świadomie nie nadaje stabilnych `test_id` i jego PASS nie zamyka DB-TST-001 ani DB-TST-002.

