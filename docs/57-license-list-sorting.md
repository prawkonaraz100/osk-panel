# 57. Licencje — sortowanie listy dostępów

Data weryfikacji: 2026-09-05

**Kontekst:** `/licencje/panel` -> `Sortuj`  
**Źródło:** bieżący zalogowany ekran + screenshot przekazany podczas audytu  
**Status:** `USER_CONFIRMED_AUTH_SCREEN`

## 1. Drawer

Akcja `Sortuj` otwiera boczny drawer zatytułowany `Sortowanie`.

Potwierdzone elementy:
- `Kierunek sortowania` — single select,
- `Kolumna` — single choice/radio,
- przycisk `Sortuj`,
- zamknięcie przez `X`.

## 2. Kierunek sortowania

Potwierdzone opcje:
- `Rosnąco`,
- `Malejąco`.

W przekazanym stanie widoczne było `Malejąco`, ale pojedynczy snapshot nie jest podstawą do stwierdzenia, że jest to globalny domyślny kierunek systemu.

## 3. Kolumny sortowania

Potwierdzone opcje:
- `Dane logowania prawo-jazdy-360.pl`,
- `Imię i nazwisko`,
- `Data wygenerowania`,
- `Język`,
- `Status`,
- `Przypisane licencje`.

W obserwowanym stanie zaznaczona była `Data wygenerowania`.

## 4. Mapowanie domenowe dla naszego produktu

Rekomendowane klucze API:
- `learning_identifier`,
- `student_full_name`,
- `latest_license_generated_at`,
- `learning_access_language`,
- `latest_license_status`,
- `assigned_license_count`.

Kierunek:
- `asc`,
- `desc`.

Przykład kontraktu:

`GET /api/osk/license-accesses?sort=latest_license_generated_at&direction=desc`

## 5. Wymagania

- sortowanie server-side dla dużej liczby kursantów,
- whitelist dozwolonych pól sortowania,
- stabilny secondary sort, np. po `id`, aby uniknąć skakania rekordów przy paginacji,
- tenant isolation,
- sortowanie po statusie powinno opierać się na stabilnej wartości domenowej, nie na przetłumaczonym labelu UI,
- `assigned_license_count` powinien być agregatem/query projection, nie ręcznie utrzymywaną liczbą.

## 6. Potwierdzone akcje

- `open_license_list_sorting`
- `select_license_sort_direction`
- `select_license_sort_column`
- `apply_license_list_sorting`
- `close_license_sorting_drawer`

## 7. Pozostałe niewiadome

- czy `Malejąco + Data wygenerowania` jest rzeczywistym domyślnym sortem przy pierwszym wejściu,
- czy sort jest pamiętany po odświeżeniu/nawigacji,
- zachowanie sortowania po `Status` przy wielu stanach,
- dokładne zasady sortowania pustych wartości,
- zachowanie z paginacją.
