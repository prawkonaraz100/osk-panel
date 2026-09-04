# 25. Pojazdy — ekran szczegółów pojazdu

Data weryfikacji: 2026-09-05

**Route pattern:** `/pojazdy/<vehicle_uuid>`  
**Źródło:** bieżący zalogowany ekran + screenshot przekazany podczas audytu  
**Status:** `USER_CONFIRMED_AUTH_SCREEN`

Dokument uzupełnia `docs/20-vehicles-screen.md`. Formularz edycji jest opisany szczegółowo w `docs/26-vehicle-edit-form.md` i ma pierwszeństwo dla pól edytowalnych, wymaganych markerów oraz relacji Kategorie/Lokalizacje.

---

## 1. Struktura ekranu

Ekran szczegółów zawiera:
1. breadcrumb `Panel główny / Pojazdy / <pojazd>`,
2. `Powrót`,
3. zdjęcie i nazwę pojazdu,
4. globalne akcje `Archiwizuj` i `Usuń`,
5. sekcję `Szczegóły pojazdu` z `Edytuj`,
6. podsekcje `Dane` i `Ważność`,
7. osadzony `Kalendarz`.

Wzorzec jest analogiczny do karty pracownika: profil zasobu, terminy formalne i planowanie są rozdzielone logicznie.

---

## 2. Potwierdzone akcje globalne

- `Powrót` -> `/pojazdy`
- `Archiwizuj`
- `Usuń`
- `Edytuj`

Nie znamy jeszcze dokładnych modali, semantyki hard/soft delete, wpływu archiwizacji na przyszłe wydarzenia ani możliwości przywrócenia.

---

## 3. Potwierdzone pola danych

Na szczegółach widoczne są:
- Marka i model,
- Nr rejestracyjny,
- Nr boczny,
- VIN,
- Pojemność (w cm³),
- Rok produkcji,
- Kategorie,
- Data dodania.

Formularz edycji potwierdził osobne pola `Marka` i `Model`, a także:
- wielokrotny wybór kategorii,
- wielokrotny wybór lokalizacji,
- widocznie wymagane: Nr rejestracyjny, Marka, Model.

### `Data dodania`
Na obserwowanym rekordzie UI pokazuje `01-01-0001`.

Klasyfikacja: `SENTINEL_OR_DEMO_DATA_ANOMALY`.

Nie kopiujemy tego zachowania. U nas brak historycznej daty powinien być `null/unknown`, a nie rokiem 0001.

---

## 4. Ważność / dokumenty

Potwierdzone trzy niezależne terminy:
- `Przegląd`,
- `OC`,
- `AC`.

Formularz edycji potwierdził osobne date pickery:
- `Następny przegląd`,
- `Ważność OC`,
- `Ważność AC`.

Potwierdzone typy domenowe:
- `technical_inspection`,
- `oc_insurance`,
- `ac_insurance`.

Każdy termin ma własny lifecycle i status. Nie łączymy ich w jeden `vehicle_document_status`.

---

## 5. Potwierdzone relacje

Po audycie szczegółów i formularza edycji potwierdzamy:
- `vehicle -> photo asset`,
- `vehicle -> validity/documents`,
- `vehicle <-> categories` many-to-many,
- `vehicle <-> locations` many-to-many,
- `vehicle -> calendar/events context`.

Do sprawdzenia pozostają ewentualne bezpośrednie relacje z instruktorami oraz okresy serwisowe/niedostępności.

---

## 6. Osadzony Kalendarz

Potwierdzone elementy:
- `Pełny kalendarz`,
- `Dodaj wydarzenie`,
- poprzedni/następny okres,
- `Dzisiaj`,
- widoki `Miesiąc`, `Tydzień`, `Dzień`.

Karta pojazdu i karta pracownika korzystają z tego samego wzorca kalendarza. Nasza architektura powinna więc używać jednego silnika wydarzeń i konfliktów zasobów.

Do sprawdzenia pozostaje formularz `Dodaj wydarzenie` i to, czy pojazd jest w nim automatycznie preselected.

---

## 7. Model pojazdu

Minimalnie:
- `id` UUID,
- `organization_id`,
- `registration_number`,
- `side_number` nullable,
- `make`,
- `model`,
- `vin` nullable,
- `engine_capacity_cm3` nullable,
- `production_year` nullable,
- `photo_asset_id` nullable,
- `created_at`,
- `archived_at` nullable,
- `deleted_at` nullable — decyzja naszej implementacji.

Relacje:
- `vehicle_categories(vehicle_id, category_id)`,
- `vehicle_locations(vehicle_id, location_id)`,
- `vehicle_validities` / `vehicle_documents`,
- calendar events.

---

## 8. Czego nadal brakuje w module Pojazdy

Po zweryfikowaniu listy, szczegółów i edycji pozostają głównie:
- formularz `Dodaj pojazd` i potwierdzenie, czy jest równoważny edycji,
- dokładne backendowe walidacje pól,
- pełny słownik kategorii,
- lifecycle `Archiwizuj`,
- lifecycle `Usuń`,
- formularz `Dodaj wydarzenie`,
- komunikaty success/error,
- filtry/wyszukiwanie/sortowanie listy,
- dokładne zachowanie usuwania zdjęcia po `Zapisz`.

Nie jest już luką:
- ekran szczegółów,
- pola edycji,
- Nr boczny,
- VIN,
- pojemność,
- rok produkcji,
- Przegląd/OC/AC,
- wielokrotne Kategorie,
- wielokrotne Lokalizacje,
- relacja pojazd <-> lokalizacje,
- archiwizacja i usuwanie jako dostępne akcje,
- osadzony kalendarz.

---

## 9. Acceptance criteria

### AC-VEH-DET-01
Administrator widzi dane techniczno-ewidencyjne pojazdu i trzy niezależne terminy formalne.

### AC-VEH-DET-02
Pojazd może być przypisany do wielu kategorii i wielu lokalizacji OSK.

### AC-VEH-DET-03
UI udostępnia oddzielne akcje `Archiwizuj`, `Usuń` i `Edytuj`.

### AC-VEH-DET-04
Karta pojazdu udostępnia kalendarz z widokami miesiąc/tydzień/dzień i akcją `Dodaj wydarzenie`.

### AC-VEH-DET-05
System nie prezentuje technicznej minimalnej daty jako prawidłowej daty biznesowej.

### AC-VEH-DET-06
Wszystkie operacje i relacje Kategorie/Lokalizacje są autoryzowane względem bieżącego OSK.
