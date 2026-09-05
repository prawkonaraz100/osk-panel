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

## Najpierw przeczytaj

Obowiązkowo przed implementacją:

1. `specs/implementation-baseline-v1.yml` — **aktywny baseline implementacyjny core v1**,
2. właściwe `specs/legal/*.yml`, jeżeli moduł dotyczy formalnego szkolenia/egzaminu,
3. właściwe `specs/design/*.yml`, jeżeli istnieje jawna decyzja naszego produktu,
4. wszystkie istniejące `specs/screens/*.yml` dla implementowanego modułu,
5. odpowiadające im nowsze dokumenty `docs/17-...` i późniejsze,
6. `docs/71-admin-osk-module-mapping-status.md`,
7. `docs/81-developer-consolidation-plan.md`,
8. `README.md`,
9. `docs/05-domain-model.md`,
10. `docs/06-api-contract.md`,
11. `docs/07-architecture.md`,
12. `docs/08-security-compliance.md`,
13. `docs/13-acceptance-criteria.md`.

Starsze agregaty (`docs/01-16`, `specs/admin-osk-services.yml`, `specs/functional-requirements.yml`) zachowują kontekst historyczny, ale **nie mogą nadpisywać** nowszego baseline/screen/legal/design spec.

## Pierwszeństwo dokumentacji

W przypadku konfliktu obowiązuje kolejność:

1. `specs/legal/*.yml` — reguły prawne i formalne,
2. `specs/design/*.yml` — jawne decyzje naszego produktu,
3. `specs/screens/*.yml` — aktualnie zweryfikowany ekran,
4. najnowszy odpowiadający dokument `docs/17-...` lub późniejszy,
5. `specs/implementation-baseline-v1.yml`,
6. `docs/71-admin-osk-module-mapping-status.md`,
7. pozostałe agregaty i starsze dokumenty.

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
- powiadomień,
- dokumentów/druków,
- integracji,
- błędów/retry,
- audit trail,
- acceptance criteria.

`READY_FOR_IMPLEMENTATION` nie oznacza „pixel-perfect kopia 360”. Oznacza, że zakres jest wystarczający do zbudowania własnego poprawnego odpowiednika, a nieobserwowalne detale mają być rozstrzygane jawnie jako `OWN_PRODUCT_DECISION`.

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
5. Wszystkie operacje tenantowe muszą sprawdzać `organization_id` i polityki uprawnień.
6. Wszystkie operacje PKK, finansowe, egzaminacyjne, licencyjne, aukcyjne i zmiany ról muszą generować odpowiedni audit log.
7. Nie wiąż logiki biznesowej z komponentami Vue.
8. Płatności, aktywacja usług i PKK muszą być idempotentne tam, gdzie request może zostać powtórzony.
9. Cofnięcie nieaktywowanej licencji musi atomowo przywrócić dokładnie jedną sztukę inventory.
10. Oferta reklamowa po `Licytuj` jest modelowana jako wiążący bid; klient nie dostaje zwykłego `DELETE bid`.
11. Nie usuwaj historii formalnych ani finansowych operacji; stosuj status/archiwizację/correction oraz immutable audit records.
12. Języki, kategorie, liczba lekcji/działów, parametry placementów i czasy emisji są config/data — nie hard-code.
13. Dla każdej funkcji dodaj testy polityk i krytyczny test integracyjny.
14. Każda zmiana zakresu aktualizuje odpowiedni screen/legal/design spec i baseline, jeżeli wpływa na core.
15. PKK w modelu formalnym należy do `course_enrollment`, nie bezpośrednio do całego rekordu `student`.
16. Formalne godziny bieżącego OSK są wyliczane z ewidencji zajęć/ledgera; nie utrzymuj równoległego ręcznie edytowalnego źródła prawdy.
17. Formalny egzamin wewnętrzny wymaga trwałego `student` i `course_enrollment`.
18. Dla egzaminów core v1 obowiązuje lifecycle z `specs/design/internal-exam-lifecycle.yml`; domyślnie inventory jest konsumowane przy **start** egzaminu, nie przy finish.
19. Money przechowuj jako decimal/minor units, nigdy float.
20. Publiczne UUID/ULID nie zastępują autoryzacji tenantowej.

## Szczególne pułapki

- `Faktury`: `HISTORICAL_INDEX`; nie traktuj jako obowiązkowego modułu v1.
- `Historia zakupów`: osobny ledger zakupów OSK, nie alias faktur i nie płatności kursanta.
- `Płatności kursanta`: osobny bounded context od zakupów platformy.
- `Lokalizacje`: osobny zasób panelu; typy potwierdzone: Filia, Sala wykładowa, Plac manewrowy.
- `Moje wizytówki`: model kolekcji, ale moduł jest poza core v1 do czasu screen mapping.
- `export_progress`: własna funkcja opcjonalna, nie potwierdzone zachowanie 360.
- `pause_campaign`: brak potwierdzonej klientowej akcji.
- `refund`: proces własny; regulamin reklamacji nie potwierdza panelowego przycisku refund.
- egzaminy: TTL linku jest decyzją security naszego produktu, nie potwierdzonym detalem 360.
- session limit: dokładny zakres dla pracowników OSK pozostaje decyzją własnej polityki do czasu dalszej weryfikacji.
- języki: capability matrix per produkt/moduł.
- wartości demo, liczniki, daty sentinel i przykładowe rekordy nigdy nie są wymaganiami biznesowymi.

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

Przed implementacją konkretnego modułu sprawdź `p0_consolidation_remaining` w `specs/implementation-baseline-v1.yml`. Jeżeli punkt dotyczy tego modułu i nie został jeszcze rozstrzygnięty, najpierw napraw dokumentację/kontrakt.

## Format PR

Każdy PR powinien zawierać:
- zakres modułu admin OSK,
- spełnione wymagania i ich confidence level,
- decyzje projektowe dla nieobserwowalnych detali,
- migracje,
- API,
- uprawnienia,
- testy,
- wpływ na audyt,
- screenshoty własnego UI,
- aktualizację docs/specs/baseline, jeśli zmienia się kontrakt.
