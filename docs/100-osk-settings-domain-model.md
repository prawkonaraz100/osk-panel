# 100. OSK settings — canonical domain and database model

Data: 2026-09-05

**Status:** `IMPLEMENTATION_BLUEPRINT`

Machine-readable source: `specs/database/organization-settings.yml`.

## Cel

Ekran `/ustawienia` i bramka `Konfiguracja PKK` muszą korzystać z jednego, spójnego modelu danych. Nie kopiujemy pól między przypadkowymi tabelami tylko dlatego, że pojawiają się na dwóch ekranach. Każde pole ma jednego właściciela domenowego, a ekrany są projekcjami z tych samych rekordów.

Zakres jest zachowany z reverse engineeringu. Nie usuwamy żadnego potwierdzonego pola i nie dokładamy do formularza pól niezaobserwowanych tylko dlatego, że istnieją w domenie.

---

## 1. Potwierdzony zakres ekranu Ustawienia

### Dane podstawowe
- Imię,
- Nazwisko,
- Email.

### Dane firmy
- Nazwa firmy,
- Ulica,
- Nr domu,
- Nr lokalu,
- Miejscowość,
- Kod pocztowy,
- Telefon.

Na tym ekranie **nie potwierdziliśmy** pola NIP ani osobnego zestawu danych rozliczeniowych. NIP może istnieć w domenie organizacji, ale nie wolno agentowi dodać go do tego formularza bez osobnej decyzji produktowej.

### Dane API PKK
- Nazwa szkoły,
- Numer ewidencyjny OSK,
- Login OSK.

`Login OSK` jest identyfikatorem integracji PKK przekazywanym przez urząd/starostę. Nie jest loginem właściciela, pracownika ani kursanta do PrawkoNaRaz.

### Regulamin
Ekran zapewnia możliwość otwarcia dokładnie tej wersji regulaminu, która została zaakceptowana dla konta. To wymaga immutable history akceptacji, a nie jednego booleanu `accepted_terms=true`.

---

## 2. Canonical ownership pól

### `users`
Właściciel/operator jako globalna tożsamość użytkownika przechowuje:
- `first_name`,
- `last_name`.

Imienia i nazwiska nie duplikujemy w `pkk_integration_settings`. Bramka konfiguracji PKK odczytuje te same pola z `users`.

### `auth_login_identifiers`
Email z sekcji „Dane podstawowe” jest projekcją bieżącego głównego identyfikatora typu `email`.

Nie dokładamy równoległego `users.email`, bo prowadziłoby to do dwóch źródeł prawdy. Model musi umieć wskazać jeden aktualny główny email użytkownika oraz zachować historię zmiany identyfikatora.

Finalna polityka re-weryfikacji nowego emaila pozostaje ADR-em, ale model nie może jej uniemożliwiać.

### `organizations`
Organizacja jest właścicielem:
- `name` — nazwa firmy,
- `phone` — telefon firmy.

### `organization_contact_addresses`
Adres firmy jest osobnym, strukturalnym rekordem 1:1 z organizacją:
- ulica,
- numer domu,
- numer lokalu,
- kod pocztowy,
- miejscowość,
- opcjonalny stabilny identyfikator miejscowości,
- opcjonalne województwo,
- kraj.

**Nie używamy tabeli `locations`.** Lokalizacja OSK typu filia/sala/plac jest zasobem szkoleniowym i kalendarzowym. Adres firmy w ustawieniach ma inną semantykę.

### `pkk_integration_settings`
Przechowuje tylko dane należące do integracji:
- `school_name`,
- `osk_registry_number`,
- zaszyfrowany `external_osk_login`,
- status gotowości integracji.

Imię/nazwisko operatora nie należą do tej tabeli.

### `terms_acceptances` + `legal_documents`
Każda akceptacja wskazuje konkretną, immutable wersję dokumentu. Dzięki temu link „Zobacz mój regulamin” nie pokazuje przypadkiem aktualnej wersji, jeśli użytkownik zaakceptował starszą.

---

## 3. `organization_settings` jako wersja agregatu, nie magazyn danych biznesowych

`organization_settings` pozostaje rekordem 1:1 z organizacją, ale jego rola jest ograniczona do:
- wersji agregatu ustawień (`version`) używanej do optimistic concurrency / ETag,
- niekrytycznych preferencji UI.

Do JSON `preferences` nie wkładamy:
- adresu firmy,
- telefonu,
- danych PKK,
- imienia/nazwiska,
- emaila.

To są dane biznesowe i muszą mieć jawne kolumny oraz walidację.

---

## 4. Jeden save może dotykać kilku tabel, ale jest jedną transakcją

`PATCH /organization/settings` jest agregatowym use case'em. Backend nie powinien zmuszać frontu do wykonywania pięciu niezależnych zapisów, ponieważ użytkownik widzi jeden formularz i jeden przycisk `Zapisz`.

Transakcja:
1. lock `organization_settings`,
2. sprawdzenie tenant scope i permission,
3. walidacja allowlist pól,
4. aktualizacja imienia/nazwiska,
5. obsługa zmiany głównego emaila według polityki auth,
6. aktualizacja nazwy i telefonu organizacji,
7. upsert adresu firmy,
8. aktualizacja danych PKK,
9. przeliczenie readiness PKK,
10. inkrementacja `organization_settings.version`,
11. redacted audit,
12. outbox/event, jeśli potrzebny,
13. commit.

Przy konflikcie wersji zapis jest odrzucany zamiast nadpisywać zmiany innego pracownika.

---

## 5. Konfiguracja PKK i Ustawienia korzystają z tych samych rekordów

Bramka `Konfiguracja PKK` potwierdzona podczas reverse engineeringu zawiera:
- Nazwa szkoły,
- Numer ewidencyjny OSK,
- Login OSK,
- Imię,
- Nazwisko.

Semantyka jest następująca:
- pierwsze trzy pola zapisują `pkk_integration_settings`,
- dwa ostatnie zapisują `users`,
- po zapisie oba ekrany od razu widzą te same wartości.

Zakazane jest tworzenie dodatkowych pól `operator_first_name` / `operator_last_name` w tabeli PKK tylko po to, żeby uprościć formularz.

---

## 6. Email — bez drugiego źródła prawdy

Email pełni jednocześnie rolę kontaktową i może pełnić rolę loginu. Canonical storage to `auth_login_identifiers`.

Model rozszerzamy o możliwość oznaczenia głównego bieżącego identyfikatora danego typu. Dla emaila obowiązuje co najwyżej jeden aktywny primary identifier na użytkownika.

Zmiana emaila nie może polegać na nadpisaniu starego stringa, jeśli finalna polityka wymaga weryfikacji. System musi wspierać scenariusz:
- utwórz nowy identyfikator jako oczekujący,
- zweryfikuj,
- przełącz primary,
- dopiero potem revoke poprzedni.

Szczegółowa polityka re-weryfikacji zostanie zamknięta osobnym ADR-em.

---

## 7. PKK — bezpieczeństwo danych konfiguracyjnych

`external_osk_login` traktujemy jako chroniony identyfikator integracyjny:
- encryption at rest,
- brak plaintext w audit/log/outbox,
- brak powiązania z auth loginem aplikacji,
- zmiana jest audytowana,
- readiness integracji jest przeliczane po zapisie.

Nie zakładamy jeszcze zachowania `Testuj połączenie`, dopóki realny provider/kontrakt PKK tego nie potwierdzi.

---

## 8. Optimistic concurrency

Ustawienia są ekranem agregatowym, więc potrzebujemy jednego numeru wersji. `organization_settings.version` jest incrementowany po każdym skutecznym zapisie tego agregatu.

API zwraca wersję/ETag. Front wysyła `If-Match` albo równoważny precondition. Przy nieaktualnej wersji backend zwraca konflikt i nie robi częściowego zapisu.

---

## 9. Audit

Audit powinien zapisać:
- kto wykonał zmianę,
- kiedy,
- który agregat został zmieniony,
- bezpieczny opis rodzaju zmiany,
- request/correlation ID.

Audit nie może zawierać:
- pełnego Login OSK PKK,
- pełnego diffu emaila, jeśli polityka prywatności tego nie dopuszcza,
- haseł i tokenów.

Dashboardowy activity feed może dostać neutralny event typu „Zmieniono ustawienia organizacji”, ale nie wartości pól wrażliwych.

---

## 10. Acceptance gate dla tego etapu

Etap uważa się za zamknięty dopiero gdy:
- GET ustawień zwraca wszystkie potwierdzone sekcje,
- PATCH pozwala zmieniać każdą potwierdzoną wartość bez wymuszania pól z innych sekcji,
- PKK gate i Ustawienia korzystają z tych samych rekordów,
- adres firmy nie staje się rekordem `Location`,
- zmiana Login OSK PKK nie wpływa na login aplikacji,
- konflikt wersji blokuje silent overwrite,
- historia regulaminu rozwiązuje dokładną wersję dokumentu,
- NIP nie staje się wymaganym polem obserwowanego formularza,
- audit nie ujawnia chronionych danych.

---

## 11. Co pozostaje otwarte

Te decyzje nie blokują obecnego kształtu modelu:
- czy zmiana emaila zawsze wymaga ponownej weryfikacji,
- dokładne zachowanie testu połączenia PKK,
- czy `external_osk_login` wymaga lookup hash oprócz ciphertext,
- czy w przyszłości formularz adresu firmy dostanie jawny wybór kraju.

Nie rozwiązujemy ich przez zgadywanie. Odpowiednie ADR-y zostaną zamknięte przed implementacją danego zachowania produkcyjnego.
