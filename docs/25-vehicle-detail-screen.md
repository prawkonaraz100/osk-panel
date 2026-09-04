# 25. Pojazdy — ekran szczegółów pojazdu

Data weryfikacji: 2026-09-05

**Route pattern:** `/pojazdy/<vehicle_uuid>`  
**Źródło:** bieżący zalogowany ekran + screenshoty i obserwacje przekazane podczas audytu  
**Status:** `USER_CONFIRMED_AUTH_SCREEN`

Dokument uzupełnia `docs/20-vehicles-screen.md`, `docs/26-vehicle-edit-form.md`, `docs/27-vehicle-create-form.md` oraz `docs/28-vehicle-delete-confirmation.md`.

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

---

## 2. Akcje globalne

### `Powrót`
Prowadzi do `/pojazdy`.

### `Usuń`
Akcja działa do etapu potwierdzenia i została opisana osobno w `docs/28-vehicle-delete-confirmation.md`.

Potwierdzony jest modal z:
- identyfikacją pojazdu,
- `Tak, usuń`,
- `Nie, anuluj`,
- zamknięciem `X`.

Semantyka backendowa po potwierdzeniu pozostaje nieznana.

### `Archiwizuj`
Akcja jest widoczna i po kliknięciu zwraca komunikat, że **wersja demo nie może archiwizować**.

Status audytowy:
- `DEMO_BLOCKED`
- akcja istnieje,
- ograniczenie jest jawnie związane z trybem demonstracyjnym,
- brak możliwości sprawdzenia dalszego flow nie oznacza błędu ani braku funkcji.

Nie znamy jeszcze zachowania poza trybem demo:
- czy pojawia się modal potwierdzający,
- czy można przywrócić z archiwum,
- co dzieje się z przyszłymi wydarzeniami,
- gdzie trafia zarchiwizowany pojazd.

Dla naszej implementacji archiwizacja powinna być oddzielnym, odwracalnym stanem zasobu, o ile nie ma przeciwwskazań formalnych.

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

Formularze create/edit potwierdzają:
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

Formularze potwierdzają osobne date pickery:
- `Następny przegląd`,
- `Ważność OC`,
- `Ważność AC`.

Potwierdzone typy domenowe:
- `technical_inspection`,
- `oc_insurance`,
- `ac_insurance`.

Każdy termin ma własny lifecycle i status.

---

## 5. Potwierdzone relacje

- `vehicle -> photo asset`,
- `vehicle -> validity/documents`,
- `vehicle <-> categories` many-to-many,
- `vehicle <-> locations` many-to-many,
- `vehicle -> calendar/events context`.

---

## 6. Osadzony Kalendarz

Potwierdzone elementy:
- `Pełny kalendarz`,
- `Dodaj wydarzenie`,
- poprzedni/następny okres,
- `Dzisiaj`,
- widoki `Miesiąc`, `Tydzień`, `Dzień`.

Karta pojazdu i karta pracownika korzystają z tego samego wzorca kalendarza.

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

Po zweryfikowaniu listy, create, detail, edit i modala delete pozostają głównie:
- dokładne backendowe walidacje pól,
- pełny słownik kategorii,
- lifecycle `Archiwizuj` poza trybem demo,
- backendowa semantyka `Usuń`,
- formularz `Dodaj wydarzenie`,
- komunikaty success/error,
- filtry/wyszukiwanie/sortowanie listy,
- dokładne zachowanie usuwania zdjęcia po `Zapisz`.

Nie jest już luką:
- lista,
- dodawanie,
- szczegóły,
- edycja,
- relacje Kategorie/Lokalizacje,
- Przegląd/OC/AC,
- potwierdzenie usuwania,
- istnienie archiwizacji,
- informacja, że archiwizacja jest blokowana w trybie demo.

---

## 9. Acceptance criteria

### AC-VEH-DET-01
Administrator widzi dane techniczno-ewidencyjne pojazdu i trzy niezależne terminy formalne.

### AC-VEH-DET-02
Pojazd może być przypisany do wielu kategorii i wielu lokalizacji OSK.

### AC-VEH-DET-03
UI udostępnia oddzielne akcje `Archiwizuj`, `Usuń` i `Edytuj`.

### AC-VEH-ARCH-01
W trybie demo kliknięcie `Archiwizuj` nie wykonuje archiwizacji i pokazuje komunikat o ograniczeniu wersji demonstracyjnej.

### AC-VEH-DET-04
Karta pojazdu udostępnia kalendarz z widokami miesiąc/tydzień/dzień i akcją `Dodaj wydarzenie`.

### AC-VEH-DET-05
System nie prezentuje technicznej minimalnej daty jako prawidłowej daty biznesowej.

### AC-VEH-DET-06
Wszystkie operacje i relacje Kategorie/Lokalizacje są autoryzowane względem bieżącego OSK.
