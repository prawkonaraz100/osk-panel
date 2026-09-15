# AGENTS.md — instrukcja dla Codex / agentów implementacyjnych

## Główny cel

Zbudować własny **panel administracyjny OSK** o parytecie funkcjonalnym opisanym w dokumentacji tego repozytorium, bez kopiowania chronionej implementacji konkurencyjnego serwisu.

**Panel administratora OSK jest obecnie nadrzędnym zakresem projektu.**

Nie buduj samodzielnie pełnego produktu kursanta, jeżeli dana funkcja nie jest potrzebna administratorowi OSK. Funkcje kursanta implementujemy tylko jako zależności zarządzane z panelu, np.:
- utworzenie dostępu,
- licencja,
- egzamin wewnętrzny,
- postęp nauki,
- zapis na jazdę,
- przypisanie materiałów/szkolenia,
- dokumentacja przebiegu szkolenia.

## Reverse-engineering preservation — reguła nadrzędna procesu

To repo zawiera szczegółowy clean-room reverse engineering funkcjonalny. **Konsolidacja dokumentacji nie może skracać zakresu przez pominięcie zaobserwowanych funkcji.**

Obowiązkowo przeczytaj:
- `docs/96-reverse-engineering-preservation-contract.md`,
- `specs/reverse-engineering-manifest.yml`.

Dla modułów core każdy element potwierdzony jako `USER_CONFIRMED_AUTH_SCREEN` albo w równoważnym szczegółowym evidence jest wymaganiem funkcjonalnym, chyba że:
- został jawnie zastąpiony przez `LEGAL_VERIFIED`,
- został jawnie zastąpiony bezpieczniejszą `OWN_PRODUCT_DECISION` przy zachowaniu tej samej zdolności biznesowej,
- jest oznaczony jako anomalia/demo value,
- został jawnie odroczony poza core v1.

Nie wolno zamieniać:
- listy konkretnych pól na samo „CRUD”,
- konkretnych filtrów na samo „filtering”,
- dwóch różnych entry points na jeden, jeśli oba zostały zaobserwowane,
- konkretnych statusów/stanów na ogólne „status”,
- dokumentu/PDF/downloadu na „export”,
- szczegółowego flow na pojedynczy przycisk bez skutków domenowych.

High-level aggregate jest indeksem. **Nie jest skróconym zamiennikiem screen specs.**

## Najpierw przeczytaj

Najpierw ustal **bieżący stan wykonania** w `docs/227-current-project-status-authority.md` oraz `specs/current-project-status.yml`. Historyczne plany, gapy i statusy `READY_FOR_IMPLEMENTATION` opisują zachowany scope/evidence albo stan z wcześniejszego etapu i nie mogą być używane jako aktualny backlog.

Obowiązkowo przed implementacją modułu:

1. `docs/96-reverse-engineering-preservation-contract.md`,
2. `specs/reverse-engineering-manifest.yml`,
3. `specs/implementation-baseline-v1.yml` — **aktywny baseline implementacyjny core v1**,
4. właściwe `specs/legal/*.yml`, jeżeli moduł dotyczy formalnego szkolenia/egzaminu,
5. właściwe `specs/design/*.yml`, jeżeli istnieje jawna decyzja naszego produktu,
6. właściwe `specs/security/*.yml`,
7. wszystkie istniejące `specs/screens/*.yml` dla implementowanego modułu,
8. odpowiadające im dokumenty evidence `docs/17-80`,
9. `docs/71-admin-osk-module-mapping-status.md`,
10. `docs/82-canonical-domain-glossary.md`,
11. `docs/83-core-lifecycle-policy.md`,
12. `docs/05-domain-model.md`,
13. `docs/06-api-contract.md`,
14. `specs/api/common-contract.yml`,
15. `specs/api/openapi-v1.yaml`,
16. `specs/database/core-schema.yml`,
17. `docs/07-architecture.md`,
18. `docs/08-security-compliance.md`,
19. `docs/13-acceptance-criteria.md`,
20. `docs/84-test-strategy.md`,
21. `README.md`.

Starsze agregaty (`docs/01-16`, `specs/admin-osk-services.yml`, `specs/functional-requirements.yml`) zachowują kontekst i katalog funkcji, ale **nie mogą nadpisywać** nowszego baseline/screen/legal/design/security spec.

## Pierwszeństwo dokumentacji

W przypadku konfliktu obowiązuje kolejność:

1. `specs/legal/*.yml` — reguły prawne i formalne,
2. `specs/design/*.yml` — jawne decyzje naszego produktu,
3. `docs/96-reverse-engineering-preservation-contract.md` — reguła zachowania evidence,
4. `specs/screens/*.yml` + odpowiadające `docs/17-80` — aktualnie zweryfikowane szczegóły ekranów/flow,
5. `specs/security/*.yml`,
6. `specs/api/common-contract.yml` i canonical API,
7. `specs/database/core-schema.yml`,
8. `specs/implementation-baseline-v1.yml`,
9. `docs/71-admin-osk-module-mapping-status.md`,
10. pozostałe agregaty i starsze dokumenty.

Ta kolejność nie oznacza, że API albo DB może usunąć funkcję z screen spec. Jeżeli screen spec zawiera potwierdzoną funkcję, a API/DB jej jeszcze nie pokrywa, **to API/DB jest niekompletne i wymaga uzupełnienia**.

Jeżeli starszy dokument ogólny oznacza element jako `TO_VERIFY_AUTH`, ale nowszy screen-level spec zawiera dane z zalogowanego widoku, **nowszy screen-level document ma pierwszeństwo**.

Nie usuwaj historycznego kontekstu tylko dlatego, że szczegół został później zweryfikowany; natomiast nie wolno implementować starego statusu wbrew nowszemu źródłu.

## Definicja kompletnego mapowania admina

Nie uznawaj modułu za w pełni zmapowany tylko dlatego, że znamy jego nazwę lub route.

Pełne mapowanie wymaga co najmniej:
- ekranów i podwidoków,
- pól formularzy,
- kolumn tabel,
- filtrów/sortowania/wyszukiwania,
- wszystkich przycisków i menu kontekstowych,
- akcji pojedynczych i zbiorczych,
- walidacji albo jawnej decyzji własnego produktu,
- modali,
- statusów i state transitions,
- ról/permissions,
- zależności między modułami,
- efektów finansowych,
- powiadomień/activity,
- dokumentów/druków/downloadów,
- integracji,
- błędów/retry/reconciliation,
- audit trail,
- acceptance criteria.

`READY_FOR_IMPLEMENTATION` nie oznacza „pixel-perfect kopia 360”. Oznacza, że zakres jest wystarczający do zbudowania własnego poprawnego odpowiednika, a nieobserwowalne detale mają być rozstrzygane jawnie jako `OWN_PRODUCT_DECISION`.

## Gate kompletności implementacji

Moduł nie może otrzymać statusu `DONE`, dopóki agent nie wykona traceability:

`observed evidence -> requirement -> domain/data -> API/use case -> permission -> UI -> acceptance test`

Przed zakończeniem PR należy przejść wszystkie `confirmed_actions`, pola, opcje, filtry, sortowanie, dokumenty i flow z właściwych `specs/screens/*.yml` oraz `specs/reverse-engineering-manifest.yml`.

Każdy element musi mieć jeden z wyników:
- `IMPLEMENTED`,
- `SUPERSEDED_BY_LEGAL_RULE`,
- `SUPERSEDED_BY_OWN_PRODUCT_DECISION_WITH_EQUIVALENT_CAPABILITY`,
- `DEFERRED_OUTSIDE_CORE_WITH_EXPLICIT_DECISION`,
- `NOT_A_REQUIREMENT_DEMO_ANOMALY`.

Brak wpisu oznacza brak implementacji, a nie zgodę na pominięcie.

## Poziomy pewności

- `USER_CONFIRMED_AUTH_SCREEN` — szczegóły ekranu przekazane z bieżącego zalogowanego panelu podczas audytu.
- `USER_CONFIRMED_AUTH_MENU` — pozycja/route potwierdzona w bieżącym zalogowanym menu.
- `LEGAL_VERIFIED` — reguła została zweryfikowana jako wymaganie prawne dla naszego produktu.
- `OWN_PRODUCT_DECISION` — świadoma decyzja architektoniczna/produktowa PrawkoNaRaz.
- `CURRENT_CONFIRMED` — aktualne publiczne potwierdzenie.
- `RULES_CONFIRMED` — aktualny regulamin potwierdza zachowanie.
- `HISTORICAL_INDEX` — historyczny ślad; **nie zakładaj, że nadal istnieje**.
- `INFERRED` — starsze oznaczenie decyzji projektowej; w nowych specach preferuj `OWN_PRODUCT_DECISION`.
- `TO_VERIFY_AUTH` — wymagane legalne wejście do aktualnego panelu przed twierdzeniem, że tak działa 360.
- `SOURCE_CONFLICT` — nie hardkoduj wartości; użyj config/CMS/capability model.
- `DEMO_BLOCKED` / `UNOBSERVABLE_IN_DEMO_NONBLOCKING` — detal nieobserwowalny, ale nie blokuje własnego bezpiecznego projektu.

## Zasady bezwzględne

1. Priorytetem są moduły core administratora OSK z `specs/implementation-baseline-v1.yml`.
2. Nie implementuj `TO_VERIFY_AUTH` jako „identycznego zachowania 360”. Możesz stworzyć neutralny własny odpowiednik, ale oznacz decyzję jako projektową.
3. Nie promuj `HISTORICAL_INDEX` do bieżącego wymagania bez nowego dowodu.
4. Nie kopiuj kodu, treści, assetów, logo ani layoutu konkurencyjnego serwisu.
5. **Nie pomijaj żadnego potwierdzonego elementu core tylko dlatego, że nowszy aggregate go nie wymienił.**
6. Wszystkie operacje tenantowe muszą sprawdzać `organization_id` i polityki uprawnień.
7. Wszystkie operacje PKK, finansowe, egzaminacyjne, licencyjne, aukcyjne i zmiany ról muszą generować odpowiedni audit log.
8. Nie wiąż logiki biznesowej z komponentami Vue.
9. Płatności, aktywacja usług i PKK muszą być idempotentne tam, gdzie request może zostać powtórzony.
10. Cofnięcie nieaktywowanej licencji musi atomowo przywrócić dokładnie jedną sztukę inventory.
11. Oferta reklamowa po `Licytuj` jest modelowana jako wiążący bid; klient nie dostaje zwykłego `DELETE bid`.
12. Nie usuwaj historii formalnych ani finansowych operacji; stosuj status/archiwizację/correction oraz immutable audit records.
13. Języki, kategorie, liczba lekcji/działów, parametry placementów i czasy emisji są config/data — nie hard-code.
14. Dla każdej funkcji dodaj testy polityk i krytyczny test integracyjny.
15. Każda zmiana zakresu aktualizuje odpowiedni screen/legal/design spec i baseline, jeżeli wpływa na core.
16. PKK w modelu formalnym należy do `course_enrollment`, nie bezpośrednio do całego rekordu `student`.
17. Formalne godziny bieżącego OSK są wyliczane z ewidencji zajęć/ledgera; nie utrzymuj równoległego ręcznie edytowalnego źródła prawdy.
18. Pola „godzin teorii/praktyki” zaobserwowane w formularzu kursu muszą pozostać obsłużone jako UI/projection/import context zgodnie ze specem — nie wolno ich po prostu usunąć przy wprowadzaniu ledgeru.
19. Formalny egzamin wewnętrzny wymaga trwałego `student` i `course_enrollment`.
20. Dla egzaminów core v1 obowiązuje lifecycle z `specs/design/internal-exam-lifecycle.yml`; inventory jest konsumowane przy **start** egzaminu, nie przy finish.
21. Money przechowuj jako decimal/minor units, nigdy float.
22. Publiczne UUID/ULID nie zastępują autoryzacji tenantowej.
23. Opcje językowe/kategorie z `SOURCE_CONFLICT` zachowaj w evidence i rozwiązuj capability matrix, nie przez kasowanie jednej wersji.
24. Dokumenty/PDF-y zaobserwowane w audycie są częścią parytetu funkcjonalnego, nie „nice to have”.
25. Wyszukiwanie, filtry, sortowanie, toggle i quick preview zaobserwowane w audycie są częścią scope'u, nawet jeśli high-level feature map mówi tylko „lista”.

## Szczególne pułapki

- `Faktury`: `HISTORICAL_INDEX`; nie traktuj jako obowiązkowego modułu v1.
- `Historia zakupów`: osobny ledger zakupów OSK, nie alias faktur i nie płatności kursanta.
- `Płatności kursanta`: osobny bounded context od zakupów platformy.
- `Lokalizacje`: osobny zasób panelu; typy potwierdzone: Filia, Sala wykładowa, Plac manewrowy.
- `Moje wizytówki`: model kolekcji, ale moduł jest poza core v1 do czasu screen mapping; zachowaj istniejące evidence.
- `export_progress`: własna funkcja opcjonalna, nie potwierdzone zachowanie 360.
- `pause_campaign`: brak potwierdzonej klientowej akcji.
- `refund`: proces własny; regulamin reklamacji nie potwierdza panelowego przycisku refund.
- egzaminy: TTL linku jest decyzją security naszego produktu, nie potwierdzonym detalem 360.
- session limit: dokładny zakres dla pracowników OSK pozostaje decyzją własnej polityki do czasu dalszej weryfikacji.
- języki: capability matrix per produkt/moduł; zachowaj zaobserwowane PL/EN/DE/RU/UK jako evidence tam, gdzie wystąpiły.
- wartości demo, liczniki, ceny promocyjne, daty sentinel i przykładowe rekordy nigdy nie są wymaganiami biznesowymi.
- `Archiwizuj filie` przy sali wykładowej to niespójna etykieta konkurenta; zachowujemy capability archiwizacji lokalizacji, nie błędny copy.
- przycisk `Dostęp do panelu -> Edytuj` pracownika był widoczny, ale nie działał w obserwowanym stanie; nie wymyślaj zachowania konkurenta, zaimplementuj własny permission/account flow zgodnie z RBAC spec.
- drawer `Zarządzaj PKK` był nieobserwowalny z powodu błędu strony; zachowaj wszystkie potwierdzone operacje PKK i własny bezpieczny orchestration flow.

## Kolejność implementacji

Nie zaczynaj od marketingowych modułów referencyjnego serwisu.

Core v1:
1. Identity + Tenant + RBAC + Audit,
2. Locations / Staff / Vehicles,
3. Students + Course Enrollment,
4. Calendar + Training Session/Hour Ledger,
5. Student Finance,
6. Licenses / Learning Access,
7. Internal Exams,
8. PKK adapter/integration,
9. Dashboard / Notifications / Purchase History,
10. hardening i formalne dokumenty.

### Aktywne odroczenie PKK

Od 2026-09-12 moduł **PKK adapter/integration** jest jawnie odroczony do czasu otrzymania i zweryfikowania autorytatywnych wytycznych lub kontraktu PWPW. Zachowaj istniejące evidence/specy oraz provider-neutral groundwork z Gate 1, ale nie implementuj provider-specific runtime, live calls, status mapping, signing/reconciliation semantics ani mutujących flow PKK z założeń. To odroczenie **nie blokuje** działania Core ani pozostałych modułów. Lokalna identity PKK dla formalnego `CourseEnrollment` pozostaje częścią modelu i może być wprowadzana ręcznie; zamrożone są import z PWPW, live calls, provider-specific adapter/configuration, status mapping, signing/reconciliation semantics i mutujące flow PWPW. Szczegóły: `docs/129-pkk-deferred-pending-pwpw-guidance.md` oraz `specs/current-project-status.yml`.

Przed implementacją konkretnego modułu sprawdź:
- `specs/implementation-baseline-v1.yml`,
- `specs/reverse-engineering-manifest.yml`,
- właściwe screen specs,
- bieżące luki w `docs/227-current-project-status-authority.md` i `specs/current-project-status.yml`. `docs/95-open-items-severity.md` jest historycznym snapshotem i nie jest aktualnym backlogiem.

Jeżeli kontrakt API/DB nie pokrywa potwierdzonego screen flow, **najpierw uzupełnij kontrakt — nie usuwaj flow**.

## Format PR

Każdy PR powinien zawierać:
- zakres modułu admin OSK,
- sekcję `Reverse-engineering traceability`,
- listę użytych docs/specs,
- spełnione wymagania i ich confidence level,
- listę wszystkich confirmed actions/pól/opcji z wynikiem `IMPLEMENTED` albo jawnym wyjątkiem,
- decyzje projektowe dla nieobserwowalnych detali,
- migracje,
- API,
- uprawnienia,
- testy,
- wpływ na audyt,
- screenshoty własnego UI,
- aktualizację docs/specs/baseline, jeśli zmienia się kontrakt.

## Branch hygiene

- `main` jest jedynym canonical base i publication branch dla bieżącej pracy.
- Każdy nowy branch roboczy twórz z aktualnego, zweryfikowanego `main`; nie wznawiaj długowiecznego integration branchu jako równoległego źródła prawdy.
- Zmiany produktu, kontraktów, migracji, CI i dokumentacji wprowadzaj przez krótko żyjący branch zadaniowy i PR do `main`.
- Nie wykonuj force-pusha ani history rewrite na `main`.
- Pełny push/release `Implementation CI` jest autorytatywny na `main`; PR-y zachowują validation-only gate bez publikacji release artifact.
- Branch po zaakceptowanym merge może zostać usunięty dopiero po zielonym post-merge CI na `main` i zapisaniu wymaganych dowodów w PR/commitach/status authority. Nazwa branchu nie jest trwałym dowodem audytowym.
- Helpery `tmp-*` są krótkotrwałe. Nie twórz kolejnego helpera, jeśli istniejący aktywny branch zadania wystarcza.
- Historycznych branchy nie usuwaj hurtowo po prefiksie. Najpierw potwierdź ancestry względem `main` albo jawne supersession/promocję ich efektu; branche rozbieżne wymagają osobnej weryfikacji.
- Po zamknięciu zadania nie utrzymuj równoległego `accepted`/integration branchu. Następna praca startuje z `main`.
