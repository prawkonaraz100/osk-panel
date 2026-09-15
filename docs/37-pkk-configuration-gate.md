# 37. PKK — ekran konfiguracji przed użyciem funkcji


> **Current runtime boundary (2026-09-15):** ten dokument zachowuje reverse-engineering/evidence i przyszłą capability PKK. Nie jest dowodem aktywnej integracji PWPW. Aktualny Core ma lokalną, ręcznie wprowadzaną course-scoped identity PKK; provider-specific import, live calls, konfiguracja połączenia i operacyjny UI pozostają `FROZEN_UNTIL_EXPLICIT_UNFREEZE` do czasu autorytatywnych wytycznych PWPW.
Data weryfikacji: 2026-09-05

**Kontekst:** wejście przez `Kursanci -> Dodaj kursanta z PKK` przy nieuzupełnionej konfiguracji PKK  
**Źródło:** bieżący zalogowany ekran + screenshot przekazany podczas audytu  
**Status:** `USER_CONFIRMED_AUTH_SCREEN`

---

## 1. Znaczenie ekranu

Próba użycia funkcji PKK nie przechodzi od razu do wyszukiwania/pobierania profilu PKK, jeżeli konto OSK nie ma uzupełnionej konfiguracji.

System pokazuje drawer:
- `Konfiguracja PKK`.

Komunikat informuje, że aby korzystać z funkcji PKK należy uzupełnić dane oraz że można je później zmienić w ustawieniach konta.

To potwierdza zależność:

`funkcja PKK -> wymagane skonfigurowane dane konta/integracji -> właściwy flow PKK`

Nie znamy jeszcze ekranu następującego po poprawnym `Zapisz i kontynuuj`.

---

## 2. Potwierdzone pola

Wszystkie widoczne pola posiadają marker `*`:

1. `Nazwa szkoły *`
2. `Numer ewidencyjny OSK *`
3. `Login OSK *`
4. `Imię *`
5. `Nazwisko *`

Potwierdzona akcja:
- `Zapisz i kontynuuj`.

Potwierdzona kontrolka:
- zamknięcie `X`.

---

## 3. Login OSK

Ekran zawiera wyjaśnienie semantyczne:
- jest to login użytkownika przekazany przez starostę / wydział komunikacji,
- znajduje się na zaświadczeniu rejestracyjnym OSK.

To jest identyfikator integracyjny i nie powinien być utożsamiany z loginem użytkownika do naszego panelu.

Własny model:
- `pkk_integration_settings.external_osk_login`.

---

## 4. Powiązanie z Ustawieniami

Komunikat na ekranie potwierdza, że dane można później zmienić w ustawieniach konta.

Wcześniej na `/ustawienia` potwierdziliśmy:
- `Nazwa szkoły`,
- `Numer ewidencyjny OSK`,
- `Login OSK`

w sekcji `Dane API PKK`, oraz:
- `Imię`,
- `Nazwisko`

w sekcji `Dane podstawowe`.

### Wniosek domenowy
Ekran konfiguracji PKK jest orkiestracją danych z co najmniej dwóch domen:
- użytkownik / dane podstawowe,
- ustawienia integracji PKK.

Nie zapisujemy wszystkich 5 wartości w jednym technicznym rekordzie API bez wyraźnej potrzeby.

---

## 5. Warunek wejścia do funkcji PKK

Potwierdzony jest mechanizm precondition/gate:
- jeśli wymagane dane konfiguracji są niekompletne, system pokazuje `Konfiguracja PKK`,
- użytkownik musi uzupełnić dane przed kontynuacją.

Nie potwierdzono jeszcze:
- czy walidacja sprawdza jedynie obecność pól,
- czy wykonywana jest weryfikacja danych z usługą zewnętrzną,
- czy login OSK jest testowany przed przejściem dalej,
- jakie błędy może zwrócić integracja.

---

## 6. Wymagania dla naszego produktu

Własny system powinien mieć jawny status gotowości integracji, np.:
- `not_configured`,
- `configured_unverified`,
- `verified`,
- `error` / `requires_attention`.

Sam fakt wpisania 3 pól nie powinien automatycznie oznaczać, że zewnętrzna integracja działa, jeśli technicznie możemy wykonać bezpieczny test konfiguracji.

Dane integracyjne powinny być:
- tenant-scoped,
- audytowane przy zmianie,
- chronione przed nieuprawnionym odczytem,
- rozdzielone od zwykłych danych logowania do panelu.

---

## 7. Potwierdzone akcje

- `open_pkk_configuration_gate`
- `set_pkk_school_name`
- `set_pkk_osk_registry_number`
- `set_pkk_external_osk_login`
- `set_user_first_name`
- `set_user_last_name`
- `save_pkk_configuration_and_continue`
- `close_pkk_configuration_gate`

---

## 8. Pozostałe niewiadome

- ekran po `Zapisz i kontynuuj`,
- dokładne walidacje numeru ewidencyjnego OSK,
- dokładne walidacje loginu OSK,
- czy istnieje techniczny test połączenia,
- czy zapis może częściowo się udać,
- komunikaty sukcesu/błędu,
- zachowanie przy błędnych danych zewnętrznych,
- czy każdy użytkownik OSK może zmienić konfigurację, czy tylko właściciel/uprawniona rola.

---

## 9. Acceptance criteria

### AC-PKK-CONFIG-01 — gate
Przy braku wymaganej konfiguracji próba wejścia w funkcję PKK pokazuje ekran konfiguracji zamiast kontynuować do operacji PKK.

### AC-PKK-CONFIG-02 — required data
Ekran wymaga nazwy szkoły, numeru ewidencyjnego OSK, loginu OSK, imienia i nazwiska.

### AC-PKK-CONFIG-03 — settings reuse
Dane użyte w konfiguracji są tymi samymi danymi domenowymi, które można później edytować w Ustawieniach; nie tworzymy drugiej, niesynchronizowanej kopii.

### AC-PKK-CONFIG-04 — identity separation
`Login OSK` integracji nie jest loginem użytkownika aplikacji.

### AC-PKK-CONFIG-05 — audit
Zmiany danych integracji PKK są audytowane i tenant-scoped.
