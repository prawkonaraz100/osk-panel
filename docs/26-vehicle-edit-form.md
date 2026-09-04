# 26. Pojazdy — formularz edycji pojazdu

Data weryfikacji: 2026-09-05

**Kontekst:** akcja `Edytuj` w sekcji `Szczegóły pojazdu` na `/pojazdy/<vehicle_uuid>`  
**Źródło:** bieżący zalogowany ekran + screenshot przekazany podczas audytu  
**Status:** `USER_CONFIRMED_AUTH_SCREEN`

Dane konkretnego pojazdu, numer rejestracyjny, UUID i nazwa pliku zdjęcia z konta demonstracyjnego nie są przepisywane jako dane referencyjne produktu.

---

## 1. Forma UI

Edycja otwiera się jako boczny panel/drawer z:
- nagłówkiem `Pojazd: <nr rejestracyjny>`,
- akcją zamknięcia `X`,
- formularzem,
- akcjami `Zapisz` i `Anuluj`.

Nie kopiujemy layoutu 1:1; mapujemy zakres funkcjonalny.

---

## 2. Potwierdzone pola formularza

Kolejność widoczna w UI:

1. `Nr rejestracyjny *`
2. `Numer boczny`
3. `Marka *`
4. `Model *`
5. `Rok produkcji`
6. `Pojemność (w cm³)`
7. `Następny przegląd`
8. `Numer VIN`
9. `Ważność OC`
10. `Ważność AC`
11. `Wybierz kategorie obsługiwane przez pojazd`
12. `Lokalizacje`
13. `Dodaj zdjęcie (opcjonalnie)`

### Widocznie wymagane pola
Asterisk `*` potwierdza jako wymagane w UI:
- numer rejestracyjny,
- marka,
- model.

Brak gwiazdki nie jest dowodem pełnej opcjonalności backendowej; dokładne walidacje serwera pozostają do sprawdzenia.

---

## 3. Kategorie

Pole kategorii ma placeholder:
`Wybierz dowolną ilość kategorii`

To potwierdza wielokrotny wybór kategorii dla pojazdu.

Relacja:
- `vehicle <-> categories` = many-to-many.

W naszym modelu rekomendowane:
`vehicle_categories(vehicle_id, category_id)`.

---

## 4. Lokalizacje

Pole `Lokalizacje` ma placeholder:
`Wybierz dowolną ilość lokalizacji`

To potwierdza wielokrotny wybór lokalizacji dla pojazdu.

Relacja:
- `vehicle <-> locations` = many-to-many.

Backend musi walidować, że wszystkie `location_ids` należą do bieżącego OSK.

---

## 5. Terminy formalne pojazdu

Potwierdzone są trzy niezależne pola daty:
- `Następny przegląd`,
- `Ważność OC`,
- `Ważność AC`.

Każde posiada własny date picker.

Potwierdza to wcześniejszy model niezależnych rekordów/terminów:
- `technical_inspection`,
- `oc_insurance`,
- `ac_insurance`.

Nie łączymy tych terminów w jedno pole.

---

## 6. Zdjęcie

Pole jest jawnie oznaczone jako opcjonalne.

Przy istniejącym zdjęciu formularz pokazuje:
- aktualny plik/identyfikator pliku,
- miniaturę,
- kontrolkę `X` pozwalającą usunąć/odpiąć wybrane zdjęcie z formularza.

Do potwierdzenia pozostaje, czy usunięcie zdjęcia następuje dopiero po `Zapisz`; przyjmujemy to jako rekomendowany model naszej implementacji.

---

## 7. Potwierdzone akcje

- `close_vehicle_edit_drawer`
- `save_vehicle_changes`
- `cancel_vehicle_edit`
- `select_vehicle_categories`
- `select_vehicle_locations`
- `pick_next_inspection_date`
- `pick_oc_valid_until`
- `pick_ac_valid_until`
- `select_or_replace_vehicle_photo`
- `remove_vehicle_photo_from_form`

---

## 8. Model danych pojazdu — potwierdzone pola

### `vehicles`
- `id` UUID
- `organization_id`
- `registration_number`
- `side_number` nullable
- `make`
- `model`
- `production_year` nullable
- `engine_capacity_cm3` nullable
- `vin` nullable
- `photo_asset_id` nullable
- timestamps

### Relacje
- `vehicle <-> categories` many-to-many
- `vehicle <-> locations` many-to-many
- `vehicle -> validity records/documents`
- `vehicle -> calendar events/context`

---

## 9. Rekomendowany payload naszego API

`PATCH /api/v1/vehicles/{vehicle}`

- `registration_number`
- `side_number`
- `make`
- `model`
- `production_year`
- `engine_capacity_cm3`
- `next_inspection_at`
- `vin`
- `oc_valid_until`
- `ac_valid_until`
- `category_ids[]`
- `location_ids[]`
- `photo_asset_id`

Własna implementacja może mapować trzy daty do osobnej tabeli `vehicle_validities`, zamiast przechowywać je bezpośrednio w `vehicles`.

---

## 10. Bezpieczeństwo i integralność

- wszystkie `category_ids` i `location_ids` są walidowane względem bieżącego tenant/OSK,
- VIN i numer rejestracyjny nie mogą być używane jako jedyny mechanizm autoryzacji rekordu,
- operacje na zdjęciu korzystają z bezpiecznego object storage,
- aktualizacja terminów powinna generować audit trail,
- nie wolno nadpisywać historycznych zdarzeń kalendarza przy zmianie danych pojazdu.

---

## 11. Czego nadal nie znamy

Po tym ekranie w module Pojazdy pozostają głównie:
- formularz `Dodaj pojazd` i czy jest identyczny z edycją,
- dokładne walidacje numeru rejestracyjnego, VIN, roku produkcji i pojemności,
- pełna lista kategorii,
- komunikaty success/error po zapisie,
- dokładne zachowanie po usunięciu zdjęcia,
- lifecycle `Archiwizuj`,
- lifecycle `Usuń`,
- formularz `Dodaj wydarzenie`,
- filtry/wyszukiwanie/sortowanie listy.

Nie jest już luką:
- multiplicity kategorii,
- multiplicity lokalizacji,
- relacja pojazd <-> lokalizacje,
- pola edycji,
- widocznie wymagane pola,
- edycja terminów Przegląd/OC/AC,
- opcjonalne zdjęcie.

---

## 12. Acceptance criteria

### AC-VEH-EDIT-01 — wymagane pola
Formularz oznacza jako wymagane co najmniej numer rejestracyjny, markę i model.

### AC-VEH-EDIT-02 — wiele kategorii
Administrator może przypisać pojazd do wielu kategorii.

### AC-VEH-EDIT-03 — wiele lokalizacji
Administrator może przypisać pojazd do wielu lokalizacji swojego OSK.

### AC-VEH-EDIT-04 — niezależne terminy
Przegląd, OC i AC są edytowane jako trzy niezależne daty.

### AC-VEH-EDIT-05 — zdjęcie
Administrator może zachować, zastąpić albo przygotować do usunięcia aktualne zdjęcie pojazdu.

### AC-VEH-EDIT-06 — tenant isolation
Żadna kategoria ani lokalizacja z innego OSK nie może zostać przypisana przez podmianę identyfikatora.
