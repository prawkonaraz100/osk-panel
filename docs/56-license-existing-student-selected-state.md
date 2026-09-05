# 56. Licencje — stan po wybraniu istniejącego kursanta

Data weryfikacji: 2026-09-05

**Kontekst:** `/licencje/panel` -> `Generuj dostęp dla kursanta` -> wyszukanie istniejącego kursanta -> wybór kursanta  
**Źródło:** bieżący zalogowany ekran + screenshot przekazany podczas audytu  
**Status:** `USER_CONFIRMED_AUTH_SCREEN`

## 1. Stan po wyborze kursanta

Po wybraniu istniejącego kursanta formularz `Przydzielanie licencji` zmienia się z wyszukiwarki na kartę wybranego kursanta.

W obserwowanym przykładzie:
- wybrany kursant: `Jan Nowak`,
- wybrany wariant: `1 miesiąc`,
- widoczny stan puli: `Dostępne: 1`,
- język istniejącego dostępu: `EN`.

## 2. Karta wybranego kursanta

Potwierdzone pola/projekcje:
- nagłówek z imieniem i nazwiskiem,
- `Email:` — zaobserwowana wartość `jannowakprawojazdy360`, która wygląda jak identyfikator/login, nie klasyczny adres e-mail,
- `Język:` — `EN`,
- `Pesel:` — może być pusty,
- `Imię i nazwisko:` — `Jan Nowak`,
- akcja `Usuń`.

### Granica interpretacji pola `Email`

Nie zapisujemy jako faktu domenowego, że wartość pokazana przy `Email:` jest adresem e-mail. W obserwowanym przykładzie brak znaku `@`, a wartość odpowiada znanemu loginowi kursanta.

Dla naszego produktu używamy neutralnego pola/projekcji `learning_identifier` i osobno przechowujemy `contact_email`.

## 3. Zachowanie języka

Po wybraniu istniejącego kursanta nie jest już widoczny globalny select `Język` z poprzedniego kroku.

Karta pokazuje język istniejącego dostępu (`EN`).

To silnie wspiera regułę:
- dla istniejącego `learning_access` język jest dziedziczony z tego dostępu,
- operator nie wybiera go ponownie w tym stanie formularza.

Dla naszego produktu:
- przypisanie kolejnej licencji do istniejącego accessu zachowuje język accessu,
- zmiana języka dostępu jest osobną operacją, nie skutkiem zwykłego assignmentu licencji.

## 4. Usunięcie wybranego celu

Karta ma akcję `Usuń`.

Najsilniejsza interpretacja UI:
- usuwa wybranego kursanta z bieżącego formularza przed zatwierdzeniem,
- nie jest to usunięcie profilu kursanta ani licencji z systemu.

Nie wykonano akcji w demo; dokładny efekt UI po kliknięciu pozostaje `TO_VERIFY`, ale dla naszego produktu przyjmujemy `clear_selected_target`.

## 5. Zatwierdzenie

Po wyborze kursanta pozostaje główna akcja:
- `Przydziel licencje`.

Formularz pokazuje jeden wybrany cel.

Ten ekran **nie jest dowodem batch assignmentu do wielu kursantów**. Batch flow z zaznaczonych wierszy panelu licencji należy dokumentować osobno, jeśli uda się otworzyć jego drawer.

## 6. Wymagania dla naszego produktu

- po wybraniu istniejącego kursanta wyświetlić summary card,
- język pochodzi z wybranego `learning_access`,
- nie używać etykiety `Email`, jeśli faktycznie prezentowany jest login/identifier,
- `Usuń` w tym kontekście tylko czyści wybór z formularza,
- przypisanie licencji nie zmienia automatycznie języka istniejącego accessu,
- student target tenant-scoped,
- assignment transakcyjny i audytowany.

## 7. Potwierdzone akcje

- `select_existing_student_for_license`
- `show_selected_student_summary`
- `inherit_existing_learning_access_language`
- `clear_selected_student_target_visible_action`
- `submit_license_assignment`

## 8. Pozostałe niewiadome

- dokładny efekt kliknięcia `Usuń` w karcie wyboru,
- zachowanie gdy kursant ma więcej niż jeden learning access,
- czy można z tego formularza przełączyć language/access wariant dla jednego kursanta,
- osobny batch assignment flow do wielu zaznaczonych osób.
