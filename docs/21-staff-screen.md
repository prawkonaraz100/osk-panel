# 21. Pracownicy — lista, dokumenty i konto do logowania

Data weryfikacji: 2026-09-05

**Route:** `/pracownicy`  
**Źródło:** bieżący zalogowany ekran przekazany podczas audytu  
**Status:** `USER_CONFIRMED_AUTH_SCREEN`

Dokument opisuje potwierdzony ekran listy pracowników panelu OSK. Dane osobowe, adresy e-mail i identyfikatory rekordów z audytowanego konta nie są zapisywane w dokumentacji.

## 1. Cel ekranu

`Pracownicy` jest centralną ewidencją osób pracujących dla OSK i używanych jako zasoby operacyjne co najmniej w kalendarzu.

Ekran potwierdza również bardzo ważne rozdzielenie domenowe:

- rekord pracownika może istnieć bez konta do logowania,
- konto użytkownika aplikacji jest opcjonalnym powiązaniem pracownika,
- pracownik nie może być modelowany jako ten sam rekord co `user`.

## 2. Akcja główna

Potwierdzona akcja:
- `Dodaj pracownika`.

Dokładny formularz dodawania pozostaje do zweryfikowania.

## 3. Potwierdzone kolumny listy

Na ekranie występują:

1. `Zdjęcie`
2. `Imię i nazwisko`
3. `Email`
4. `Dokumenty`
5. `Konto do logowania`

Dodatkowo każdy rekord posiada akcje prowadzące do kalendarza i ekranu szczegółów.

## 4. Potwierdzone dane pracownika

### Zdjęcie
- pracownik może posiadać zdjęcie,
- zdjęcie jest powiązane z identyfikatorem rekordu,
- u nas przechowujemy referencję do assetu/object storage, nie blob w tabeli pracownika.

### Imię i nazwisko
- prezentowane jako jedna kolumna,
- w modelu danych rekomendowane osobne `first_name` i `last_name`.

### Email
- osobne pole pracownika,
- samo posiadanie adresu e-mail nie oznacza posiadania konta do logowania.

### Konto do logowania
Potwierdzony stan:
- `Nie`.

To dowodzi, że:
- `staff_profile` i `user` są osobnymi encjami,
- `staff_profile.user_id` powinno być nullable albo relacja powinna być realizowana przez membership/invitation,
- pracownik może być używany w kalendarzu i ewidencji bez dostępu do panelu,
- nadanie konta do logowania powinno być osobnym procesem, nie skutkiem ubocznym utworzenia pracownika.

Stan `Tak` nie został jeszcze zaobserwowany, ale model musi go obsłużyć jako logiczne przeciwieństwo kolumny boolean.

## 5. Dokumenty pracownika

Kolumna `Dokumenty` może prezentować kilka niezależnych dokumentów/statusów w jednym rekordzie.

Potwierdzone typy/etykiety:
- `Legitymacja`,
- `Badania lekarskie`,
- `Badania psychologiczne`.

Potwierdzone komunikaty stanów:
- `<dokument> wygasła/wygasły <data>`,
- `Legitymacja ważna do <data>`.

Wnioski:
- każdy typ dokumentu ma własny termin ważności,
- system oblicza stan względem bieżącej daty,
- co najmniej `valid` i `expired` są funkcjonalnie potwierdzone,
- jeden pracownik może mieć jednocześnie dokument ważny i inne przeterminowane,
- status dokumentów nie jest pojedynczym statusem całego pracownika.

### Model `staff_documents`

Minimalnie:
- `id`
- `organization_id`
- `staff_id`
- `type`
- `valid_from` nullable
- `valid_until`
- `status`
- `document_number` nullable
- `file_id` nullable
- `created_at`
- `updated_at`

Potwierdzone wartości domenowe `type` powinny odpowiadać co najmniej:
- `card_or_license` — etykieta UI `Legitymacja`; dokładna semantyka dokumentu do potwierdzenia na ekranie szczegółów,
- `medical_exam`,
- `psychological_exam`.

Nie zakładamy, że `Legitymacja` zawsze oznacza legitymację instruktora, dopóki nie zobaczymy formularza/szczegółów.

## 6. Potwierdzone akcje wiersza

### `Kalendarz`

Wzorzec trasy:

`/kalendarz?w=<staff_uuid>`

Znaczenie:
- pracownik jest zasobem kalendarzowym,
- parametr `w` filtruje kalendarz po konkretnym pracowniku,
- ten sam pracownik może istnieć bez konta do logowania, a mimo to mieć własny kontekst kalendarza.

W naszym odpowiedniku:

`GET /calendar?staff_id=<uuid>`

oraz API:

`GET /api/v1/calendar/events?staff_id=<uuid>`.

Każda operacja musi sprawdzać tenant ownership.

### `Zobacz`

Wzorzec trasy:

`/pracownicy/<staff_uuid>`

Potwierdza osobny ekran szczegółów pracownika.

Do dalszego audytu:
- wszystkie pola pracownika,
- role/funkcje,
- kategorie uprawnień,
- dokumenty i ich edycja,
- zdjęcie,
- kontakt,
- lokalizacje,
- uprawnienia do logowania,
- sposób tworzenia konta użytkownika,
- dostępność/kalendarz,
- ewentualna dezaktywacja/usunięcie.

### `Podgląd`

Po `Zobacz` w przekazanym tekście występuje również `Podgląd`. Bez obrazu/DOM nie uznajemy go jeszcze za osobną akcję. Może być tooltipem, accessible label lub opisem linku.

Status: `TO_VERIFY_VISUAL`.

## 7. Identyfikacja pracownika

UUID-like identifier występuje co najmniej w:
- `/pracownicy/<uuid>`,
- `/kalendarz?w=<uuid>`,
- ścieżce zdjęcia.

Rekomendacja:
- `staff_profiles.id` jako UUID,
- każda encja ma `organization_id`,
- publiczny identyfikator nie może zastępować sprawdzenia tenant ownership.

## 8. Kluczowe rozdzielenie: Staff vs User

### `staff_profiles`
Reprezentuje osobę zatrudnioną/współpracującą z OSK:
- `id`
- `organization_id`
- `first_name`
- `last_name`
- `email` nullable
- `photo_asset_id` nullable
- `user_id` nullable / alternatywnie relation przez membership
- `created_at`
- `updated_at`

### `users`
Reprezentuje konto uwierzytelniające w aplikacji.

Proces nadania dostępu powinien być osobny:

`staff_without_login -> invitation/account_creation -> linked_user_account`.

Nie należy automatycznie tworzyć loginu dla każdej osoby dodanej do ewidencji pracowników.

## 9. Dokumenty i alerty

Dla naszego produktu wymagane są co najmniej:
- alert przed końcem ważności dokumentu,
- alert po wygaśnięciu,
- prezentacja wielu alertów dla jednego pracownika,
- możliwość filtrowania/raportowania terminów jako funkcja własna; filtr nie jest jeszcze potwierdzony na tym ekranie.

Publiczny ekran potwierdza automatyczną ocenę terminów, ale nie potwierdza progu `expiring_soon` ani kanałów powiadomień.

## 10. Potwierdzone relacje

- `staff -> photo`
- `staff -> email/contact`
- `staff -> documents[]`
- `staff -> optional login account`
- `staff -> calendar context`
- `staff -> details screen`

Do potwierdzenia:
- `staff -> roles`
- `staff -> driving categories`
- `staff -> locations`
- `staff -> vehicles`
- `staff -> availability`
- `staff -> account permissions`

## 11. Rekomendowane API

- `GET /api/v1/staff`
- `POST /api/v1/staff`
- `GET /api/v1/staff/{staff}`
- `PATCH /api/v1/staff/{staff}`
- `GET /api/v1/staff/{staff}/documents`
- `GET /api/v1/calendar/events?staff_id={staff}`

Dla konta logowania projektowo:
- `POST /api/v1/staff/{staff}/account-invitation`
- `DELETE /api/v1/staff/{staff}/account-access` — tylko jeśli polityka naszego produktu to dopuści.

Te endpointy dostępu są decyzją naszego produktu; dokładne akcje 360 trzeba potwierdzić na ekranie szczegółów.

## 12. Czego lista NIE potwierdza

Nie uznajemy jeszcze za parytet 360:
- usuwania/dezaktywacji pracownika,
- resetowania hasła pracownika,
- edycji ról z listy,
- filtrowania/sortowania/wyszukiwania,
- akcji zbiorczych,
- przypisania lokalizacji,
- przypisania kategorii,
- szczegółów uprawnień konta,
- progu przypomnienia przed wygaśnięciem dokumentu.

## 13. Acceptance criteria

### AC-STF-01 — pracownik bez konta
**Given** administrator tworzy pracownika bez dostępu do panelu  
**Then** pracownik istnieje w ewidencji i może być używany w kalendarzu, ale nie posiada aktywnego konta uwierzytelniającego.

### AC-STF-02 — dokumenty niezależne
**Given** pracownik ma kilka typów dokumentów  
**When** ich terminy mają różne stany  
**Then** każdy dokument pokazuje własny status i datę; jeden wygasły dokument nie nadpisuje pozostałych.

### AC-STF-03 — wygasły dokument
**Given** `valid_until` jest w przeszłości  
**Then** dokument otrzymuje stan `expired` i UI pokazuje alert z datą wygaśnięcia.

### AC-STF-04 — ważny dokument
**Given** `valid_until` jest w przyszłości  
**Then** dokument może być prezentowany jako ważny do wskazanej daty.

### AC-STF-05 — kalendarz pracownika
**Given** administrator wybiera akcję `Kalendarz` przy pracowniku  
**Then** otwierany jest kalendarz w kontekście tego pracownika, bez możliwości odczytania danych pracownika innego OSK przez podmianę identyfikatora.

### AC-STF-06 — ekran szczegółów
**Given** administrator wybiera `Zobacz`  
**Then** otwierany jest osobny ekran szczegółów pracownika.
