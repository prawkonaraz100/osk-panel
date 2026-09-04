# 27. Pojazdy — formularz dodawania pojazdu

Data weryfikacji: 2026-09-05

**Kontekst:** akcja `Dodaj pojazd` na `/pojazdy`  
**Źródło:** bieżący zalogowany ekran + screenshot przekazany podczas audytu  
**Status:** `USER_CONFIRMED_AUTH_SCREEN`

Formularz dodawania pojazdu został zweryfikowany i jest funkcjonalnie niemal identyczny z formularzem edycji opisanym w `docs/26-vehicle-edit-form.md`. Różnica polega głównie na braku istniejących wartości i istniejącego zdjęcia.

---

## 1. Forma UI

Dodawanie pojazdu otwiera się jako boczny panel/drawer z:
- nagłówkiem `Dodaj pojazd`,
- akcją zamknięcia `X`,
- formularzem,
- akcjami `Zapisz` i `Anuluj`.

Nie kopiujemy layoutu 1:1; mapujemy zachowanie i zakres funkcjonalny.

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

Brak gwiazdki przy pozostałych polach nie jest dowodem pełnej opcjonalności po stronie backendu; dokładne walidacje serwera pozostają do sprawdzenia.

---

## 3. Parity z formularzem edycji

Formularz create i edit mają ten sam zestaw pól biznesowych:
- numer rejestracyjny,
- numer boczny,
- marka,
- model,
- rok produkcji,
- pojemność,
- przegląd,
- VIN,
- OC,
- AC,
- kategorie,
- lokalizacje,
- zdjęcie.

Różnice obserwowane:
- create ma puste pola,
- daty pokazują placeholder `dd.mm.rrrr`,
- zdjęcie pokazuje `Wybierz zdjęcie...`,
- edit pokazuje istniejące wartości i aktualne zdjęcie.

Dla naszego produktu najlepiej użyć wspólnego komponentu formularza z trybem `create` / `edit`, ale oddzielnymi command/use-case'ami backendowymi.

---

## 4. Kategorie

Pole pokazuje placeholder:
`Wybierz dowolną ilość kategorii`

Potwierdza to możliwość przypisania pojazdu do wielu kategorii już podczas tworzenia.

Relacja:
- `vehicle <-> categories` many-to-many.

---

## 5. Lokalizacje

Pole pokazuje placeholder:
`Wybierz dowolną ilość lokalizacji`

Potwierdza to możliwość przypisania pojazdu do wielu lokalizacji już podczas tworzenia.

Relacja:
- `vehicle <-> locations` many-to-many.

Backend musi walidować wszystkie `location_ids` względem bieżącego OSK.

---

## 6. Terminy formalne

Formularz create posiada trzy niezależne pola daty:
- `Następny przegląd`,
- `Ważność OC`,
- `Ważność AC`.

Każde posiada własny date picker.

W naszym modelu są to niezależne validity records / documents:
- `technical_inspection`,
- `oc_insurance`,
- `ac_insurance`.

---

## 7. Zdjęcie

Pole jest jawnie oznaczone jako opcjonalne.

Stan początkowy:
- `Wybierz zdjęcie...`

Wnioski:
- pojazd można utworzyć bez zdjęcia,
- upload zdjęcia jest częścią formularza create,
- dokładne limity typu/rozmiaru/crop pozostają do sprawdzenia.

---

## 8. Potwierdzone akcje

- `close_vehicle_create_drawer`
- `save_new_vehicle`
- `cancel_vehicle_create`
- `select_vehicle_categories`
- `select_vehicle_locations`
- `pick_next_inspection_date`
- `pick_oc_valid_until`
- `pick_ac_valid_until`
- `select_vehicle_photo`

---

## 9. Rekomendowany API command

`POST /api/v1/vehicles`

Payload biznesowy:
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

Tworzenie pojazdu, relacji category/location oraz terminów validity powinno być transakcyjne.

---

## 10. Bezpieczeństwo i integralność

- wszystkie category/location IDs muszą należeć do bieżącego OSK,
- numer rejestracyjny i VIN nie są mechanizmem autoryzacji,
- upload zdjęcia korzysta z bezpiecznego storage,
- częściowe utworzenie pojazdu bez relacji/terminów przy błędzie transakcji nie powinno pozostać w bazie,
- walidacja unikalności numeru rejestracyjnego powinna być decyzją naszego produktu / tenant scope; dokładne zachowanie 360 nie jest jeszcze potwierdzone.

---

## 11. Czego nadal nie znamy

Po tym ekranie formularz create jest funkcjonalnie zmapowany. Pozostają tylko szczegóły walidacyjne i lifecycle modułu:
- dokładne walidacje numeru rejestracyjnego,
- format i walidacja VIN,
- zakres roku produkcji,
- zakres pojemności,
- pełny słownik kategorii,
- zasady uploadu zdjęcia,
- komunikaty success/error,
- zachowanie przy duplikacie pojazdu,
- lifecycle `Archiwizuj`,
- lifecycle `Usuń`,
- formularz `Dodaj wydarzenie`,
- filtry/wyszukiwanie/sortowanie listy.

Nie jest już luką:
- formularz `Dodaj pojazd`,
- zestaw pól create,
- wymagane pola widoczne w UI,
- wiele kategorii,
- wiele lokalizacji,
- Przegląd/OC/AC w create,
- opcjonalne zdjęcie.

---

## 12. Acceptance criteria

### AC-VEH-CREATE-01 — wymagane minimum
Administrator nie może zapisać formularza bez numeru rejestracyjnego, marki i modelu w UI.

### AC-VEH-CREATE-02 — multi-select
Podczas tworzenia pojazdu można przypisać wiele kategorii i wiele lokalizacji.

### AC-VEH-CREATE-03 — niezależne terminy
Przegląd, OC i AC mają osobne pola daty.

### AC-VEH-CREATE-04 — zdjęcie opcjonalne
Pojazd może zostać utworzony bez zdjęcia.

### AC-VEH-CREATE-05 — create/edit consistency
Zakres pól create i edit jest spójny; edycja nie może utracić danych możliwych do podania podczas tworzenia.

### AC-VEH-CREATE-06 — tenant isolation
Nie można przypisać kategorii ani lokalizacji należącej do innego OSK przez manipulację identyfikatorami.
