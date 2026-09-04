# 21. Pracownicy — lista, dokumenty i konto do logowania

Data weryfikacji: 2026-09-05

**Route:** `/pracownicy`  
**Źródło:** bieżące zalogowane ekrany listy i formularza tworzenia przekazane podczas audytu  
**Status:** `USER_CONFIRMED_AUTH_SCREEN`

Dokument opisuje potwierdzony ekran listy pracowników panelu OSK. Formularz tworzenia jest szczegółowo opisany w `docs/22-staff-create-form.md`. Dane osobowe, adresy e-mail i identyfikatory rekordów z audytowanego konta nie są zapisywane w dokumentacji.

## 1. Cel ekranu

`Pracownicy` jest centralną ewidencją osób pracujących dla OSK i używanych jako zasoby operacyjne co najmniej w kalendarzu.

Ekrany potwierdzają ważne rozdzielenie domenowe:
- rekord pracownika może istnieć bez konta do logowania,
- konto użytkownika aplikacji jest opcjonalnym powiązaniem pracownika,
- utworzenie konta jest jawną opcją formularza `Dodaj pracownika`,
- pracownik nie może być modelowany jako ten sam rekord co `user`.

## 2. Akcja główna

Potwierdzona akcja:
- `Dodaj pracownika`.

Formularz jest już zmapowany i obejmuje:
- E-mail,
- Imię,
- Nazwisko,
- Rodzaj pracownika — wielokrotny wybór,
- PESEL,
- Telefon,
- Numer uprawnień,
- Kategorie — wielokrotny wybór,
- Lokalizacje — wielokrotny wybór,
- Ważność legitymacji,
- Ważność badań lekarskich,
- Ważność badań psychologicznych,
- opcjonalne zdjęcie,
- `Utwórz konto do logowania`,
- `Zapisz`,
- `Anuluj`.

Szczegóły i acceptance criteria: `docs/22-staff-create-form.md`.

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
- formularz potwierdza, że zdjęcie jest opcjonalne,
- zdjęcie jest powiązane z identyfikatorem rekordu,
- u nas przechowujemy referencję do assetu/object storage, nie blob w tabeli pracownika.

### Imię i nazwisko
- prezentowane na liście jako jedna kolumna,
- formularz potwierdza osobne pola `Imię` i `Nazwisko`.

### Email
- osobne pole pracownika,
- samo posiadanie adresu e-mail nie oznacza posiadania konta do logowania.

### PESEL
- potwierdzony w formularzu tworzenia,
- nie jest wyświetlany na liście,
- wymaga ochrony jako dana osobowa.

### Telefon
- potwierdzony w formularzu tworzenia.

### Numer uprawnień
- potwierdzony jako osobne pole formularza,
- nie utożsamiamy go z datą ważności legitymacji.

### Konto do logowania
Potwierdzony stan na liście:
- `Nie`.

Formularz potwierdza opcję:
- `Utwórz konto do logowania`.

To dowodzi, że:
- `staff_profile` i `user` są osobnymi encjami,
- `staff_profile.user_id` powinno być nullable albo relacja powinna być realizowana przez membership/invitation,
- pracownik może być używany w kalendarzu i ewidencji bez dostępu do panelu,
- provisioning konta jest kontrolowaną decyzją administratora przy tworzeniu pracownika.

Stan `Tak` nie został jeszcze zaobserwowany na liście, ale system musi go obsługiwać jako rezultat utworzenia/powiązania konta.

## 5. Dokumenty pracownika

Kolumna `Dokumenty` może prezentować kilka niezależnych dokumentów/statusów w jednym rekordzie.

Potwierdzone typy/etykiety:
- `Legitymacja`,
- `Badania lekarskie`,
- `Badania psychologiczne`.

Formularz potwierdza osobne daty ważności dla wszystkich trzech typów.

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

Nie zakładamy, że `Legitymacja` zawsze oznacza legitymację instruktora, dopóki nie zobaczymy formularza/szczegółów rodzaju pracownika i dokumentu.

## 6. Rodzaje pracownika, kategorie i lokalizacje

Formularz potwierdza trzy relacje wielokrotne.

### Rodzaj pracownika
UI: `Wybierz dowolną ilość`.

Wniosek:
- pracownik może posiadać wiele rodzajów/funkcji,
- nie używamy pojedynczego `staff.type`.

### Kategorie
UI: `Wybierz dowolną ilość kategorii`.

Potwierdza relację many-to-many:
- `staff <-> driving_categories`.

### Lokalizacje
UI: `Wybierz dowolną ilość lokalizacji`.

Potwierdza relację many-to-many:
- `staff <-> locations`.

Każde `location_id` musi być tenant-scoped i zweryfikowane server-side.

## 7. Potwierdzone akcje wiersza

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

Do dalszego audytu tego ekranu:
- sposób edycji wszystkich pól,
- dokładna lista rodzajów pracownika,
- szczegóły konta do logowania i permissions,
- sposób aktualizacji dokumentów,
- ewentualna dezaktywacja/usunięcie,
- historia zmian.

### `Podgląd`

Po `Zobacz` w przekazanym tekście występuje również `Podgląd`. Bez obrazu/DOM nie uznajemy go jeszcze za osobną akcję. Może być tooltipem, accessible label lub opisem linku.

Status: `TO_VERIFY_VISUAL`.

## 8. Identyfikacja pracownika

UUID-like identifier występuje co najmniej w:
- `/pracownicy/<uuid>`,
- `/kalendarz?w=<uuid>`,
- ścieżce zdjęcia.

Rekomendacja:
- `staff_profiles.id` jako UUID,
- każda encja ma `organization_id`,
- publiczny identyfikator nie może zastępować sprawdzenia tenant ownership.

## 9. Kluczowe rozdzielenie: Staff vs User

### `staff_profiles`
Reprezentuje osobę zatrudnioną/współpracującą z OSK:
- `id`
- `organization_id`
- `first_name`
- `last_name`
- `email`
- `pesel`
- `phone`
- `authorization_number`
- `photo_asset_id` nullable
- `user_id` nullable / alternatywnie relation przez membership
- `created_at`
- `updated_at`

### `users`
Reprezentuje konto uwierzytelniające w aplikacji.

Proces potwierdzony koncepcyjnie przez formularz:
`create staff -> optional create_login_account -> linked user/membership`.

Dokładna metoda provisioningowa konkurencyjnego systemu — zaproszenie, hasło generowane lub inny mechanizm — pozostaje do sprawdzenia.

## 10. Potwierdzone relacje

- `staff -> photo` opcjonalne
- `staff -> email/contact`
- `staff -> documents[]`
- `staff -> optional login account`
- `staff -> calendar context`
- `staff -> details screen`
- `staff <-> staff_types` many-to-many
- `staff <-> driving_categories` many-to-many
- `staff <-> locations` many-to-many

Do potwierdzenia:
- `staff -> vehicles`
- `staff -> availability`
- dokładny model `roles/permissions` konta logowania.

## 11. Rekomendowane API

- `GET /api/v1/staff`
- `POST /api/v1/staff`
- `GET /api/v1/staff/{staff}`
- `PATCH /api/v1/staff/{staff}`
- `GET /api/v1/staff/{staff}/documents`
- `GET /api/v1/calendar/events?staff_id={staff}`

`POST /api/v1/staff` powinien przyjmować m.in.:
- `staff_type_ids[]`
- `category_ids[]`
- `location_ids[]`
- trzy daty dokumentów,
- opcjonalne zdjęcie,
- `create_login_account`.

Szczegóły provisioning konta pozostają do dalszego audytu.

## 12. Czego nadal nie potwierdziliśmy

Nie uznajemy jeszcze za parytet 360:
- usuwania/dezaktywacji pracownika,
- resetowania hasła pracownika,
- dokładnej listy rodzajów pracownika,
- szczegółów roles/permissions konta,
- filtrowania/sortowania/wyszukiwania listy,
- akcji zbiorczych,
- wymaganych pól formularza,
- walidacji PESEL/email/telefonu/numeru uprawnień,
- sposobu utworzenia hasła lub zaproszenia,
- progu przypomnienia przed wygaśnięciem dokumentu.

## 13. Acceptance criteria

### AC-STF-01 — pracownik bez konta
**Given** administrator tworzy pracownika z wyłączonym `Utwórz konto do logowania`  
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

### AC-STF-07 — relacje wielokrotne
**Given** administrator tworzy pracownika  
**Then** może przypisać wiele rodzajów pracownika, wiele kategorii i wiele lokalizacji.

### AC-STF-08 — konto opcjonalne
**Given** formularz zawiera `Utwórz konto do logowania`  
**Then** provisioning konta jest oddzielnym efektem sterowanym tą opcją, a nie automatyczną konsekwencją utworzenia pracownika.
