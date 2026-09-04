# 24. Pracownicy — formularz edycji danych pracownika

Data weryfikacji: 2026-09-05

**Kontekst:** akcja `Edytuj` w sekcji `Szczegóły pracownika` na `/pracownicy/<staff_uuid>`  
**Źródło:** bieżący zalogowany ekran + screenshot przekazany podczas audytu  
**Status:** `USER_CONFIRMED_AUTH_SCREEN`

Dane osobowe, adres e-mail, nazwa pliku zdjęcia i UUID z audytowanego konta nie są przepisywane do dokumentacji.

---

## 1. Forma UI

Edycja otwiera się jako boczny panel/drawer z:
- nagłówkiem z imieniem i nazwiskiem pracownika,
- akcją zamknięcia `X`,
- formularzem,
- akcjami `Zapisz` i `Anuluj`.

Nie wymagamy kopiowania tego layoutu 1:1; istotny jest zakres funkcjonalny formularza.

---

## 2. Potwierdzone pola

Kolejność widoczna na ekranie:

1. `E-mail *`
2. `Imię *`
3. `Nazwisko *`
4. `Rodzaj pracownika *`
5. `Pesel`
6. `Telefon`
7. `Numer uprawnień`
8. `Kategorie`
9. `Lokalizacje`
10. `Ważność legitymacji`
11. `Ważność badań lekarskich`
12. `Ważność badań psychologicznych`
13. `Dodaj zdjęcie (opcjonalnie)`
14. `Utwórz konto do logowania`

### Widocznie wymagane pola

Asterisk `*` potwierdza jako wymagane w UI:
- e-mail,
- imię,
- nazwisko,
- rodzaj pracownika.

Brak gwiazdki przy pozostałych polach nie jest sam w sobie dowodem, że backend zawsze zaakceptuje pustą wartość; dokładne walidacje serwera wymagają osobnego testu.

---

## 3. Rodzaj pracownika

Pole jest wielokrotnym selektorem.

Na obserwowanym ekranie wybrana jest etykieta:
- `Pracownik biurowy`.

Wybrana wartość jest renderowana jako removable chip/tag z `X`.

Wcześniejszy ekran szczegółów pokazywał dla tego samego rekordu wartość `OfficeWorker`.

Wniosek:
- `OfficeWorker` może być kodem technicznym/enumeracją,
- `Pracownik biurowy` jest etykietą użytkową,
- dokładnego mechanizmu 360 nie przesądzamy, ale w naszym systemie stosujemy rozdzielenie `staff_type.code` od tłumaczonego `staff_type.label`.

Rekomendowany własny model:
- code: `office_worker`
- label pl-PL: `Pracownik biurowy`

Nie pokazujemy surowych kodów technicznych w UI.

---

## 4. Kategorie i Lokalizacje

Oba pola są wielokrotnymi selektorami.

Potwierdzone placeholdery:
- `Wybierz dowolną ilość kategorii`
- `Wybierz dowolną ilość lokalizacji`

To potwierdza istniejące relacje many-to-many:
- `staff <-> categories`
- `staff <-> locations`

Przy zapisie backend musi sprawdzić, że wszystkie przekazane `location_ids` należą do bieżącego OSK.

---

## 5. Terminy ważności

Potwierdzone są trzy osobne kontrolki daty:
- ważność legitymacji,
- ważność badań lekarskich,
- ważność badań psychologicznych.

Każde pole:
- może być wypełnione istniejącą datą,
- posiada kontrolkę wyboru daty/kalendarza.

Nie łączymy terminów w jedno pole ani jeden status pracownika.

---

## 6. Zdjęcie

Pole jest jawnie oznaczone jako opcjonalne.

Przy istniejącym zdjęciu ekran pokazuje:
- nazwę/identyfikator wybranego pliku,
- miniaturę aktualnego zdjęcia,
- kontrolkę `X` pozwalającą usunąć/odpiąć wybrane zdjęcie z formularza.

Nie przesądzamy jeszcze, czy usunięcie zdjęcia jest zapisywane natychmiast czy dopiero po `Zapisz`; obserwowany układ sugeruje zapis formularza, ale wymaga potwierdzenia.

Dla naszego produktu:
- plik -> object storage,
- profil -> `photo_asset_id`,
- walidacja MIME/rozmiaru,
- brak ekspozycji wewnętrznej ścieżki storage.

---

## 7. `Utwórz konto do logowania`

Na formularzu edycji pracownika, który aktualnie nie ma dostępu do panelu, widoczna jest osobna kontrolka:
- `Utwórz konto do logowania`.

To jest kluczowe potwierdzenie lifecycle:

`staff_without_account -> edit_staff -> optionally_create_login_account`

Czyli konto może zostać nadane **po utworzeniu pracownika**, a nie wyłącznie podczas `Dodaj pracownika`.

Nie znamy jeszcze skutku po zaznaczeniu:
- czy pojawiają się dodatkowe pola,
- czy konto tworzy się po `Zapisz`,
- czy wysyłane jest zaproszenie,
- czy generowane jest hasło,
- jakie są domyślne permissions.

To pozostaje do audytu.

---

## 8. Potwierdzone akcje

- `close_edit_drawer`
- `save_staff_changes`
- `cancel_staff_edit`
- `remove_selected_staff_type`
- `select_staff_types`
- `select_categories`
- `select_locations`
- `pick_card_valid_until`
- `pick_medical_exam_valid_until`
- `pick_psychological_exam_valid_until`
- `select_or_replace_photo`
- `remove_photo_from_form`
- `request_create_login_account`

---

## 9. Rekomendowany payload naszego API

`PATCH /api/v1/staff/{staff}`

Przykładowy model danych:

- `email`
- `first_name`
- `last_name`
- `staff_type_ids[]`
- `pesel`
- `phone`
- `authorization_number`
- `category_ids[]`
- `location_ids[]`
- `card_valid_until`
- `medical_exam_valid_until`
- `psychological_exam_valid_until`
- `photo_asset_id`
- `create_login_account` boolean

W naszej implementacji tworzenie konta najlepiej traktować jako osobny command/use-case wykonywany transakcyjnie po walidacji formularza, a nie jako przypadkowy efekt uboczny modelu ORM.

---

## 10. Bezpieczeństwo i integralność

- PESEL nie trafia do zwykłych logów aplikacyjnych.
- `location_ids` i inne tenantowe relacje są walidowane względem `organization_id`.
- zmiana e-maila pracownika nie może automatycznie zmieniać e-maila powiązanego `user`, jeśli nie przewiduje tego jawny proces.
- `staff_type` nie jest równoznaczny z permissions konta.
- operacja utworzenia konta musi być audytowana.

---

## 11. Czego nadal brakuje w edycji

- zachowanie po zaznaczeniu `Utwórz konto do logowania`,
- ekran/flow provisioning konta,
- pełny słownik rodzajów pracownika,
- dokładne walidacje PESEL/e-mail/telefonu/numeru uprawnień,
- błędy przy niepoprawnych datach,
- komunikat sukcesu po zapisie,
- komunikaty błędów API,
- zachowanie po usunięciu aktualnego zdjęcia.

---

## 12. Acceptance criteria

### AC-STF-EDIT-01 — wymagane dane
Formularz oznacza jako wymagane co najmniej: e-mail, imię, nazwisko i rodzaj pracownika.

### AC-STF-EDIT-02 — multi-select
Administrator może przypisać pracownikowi wiele rodzajów, kategorii i lokalizacji.

### AC-STF-EDIT-03 — niezależne terminy
Trzy terminy ważności są edytowane niezależnie.

### AC-STF-EDIT-04 — zdjęcie
Administrator może zachować, zastąpić lub przygotować do usunięcia istniejące zdjęcie; finalny lifecycle usunięcia wymaga potwierdzenia.

### AC-STF-EDIT-05 — późniejsze konto
Pracownik istniejący bez konta może podczas późniejszej edycji zostać oznaczony do utworzenia konta logowania.

### AC-STF-EDIT-06 — tenant safety
Przypisanie lokalizacji/kategorii nie może odwoływać się do zasobów innego OSK.
