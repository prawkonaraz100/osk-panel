# 25. Pojazdy — ekran szczegółów pojazdu

Data weryfikacji: 2026-09-05

**Route pattern:** `/pojazdy/<vehicle_uuid>`  
**Źródło:** bieżący zalogowany ekran + screenshot przekazany podczas audytu  
**Status:** `USER_CONFIRMED_AUTH_SCREEN`

Dokument uzupełnia `docs/20-vehicles-screen.md`. Konkretny UUID, numer rejestracyjny i inne dane demonstracyjne nie są przepisywane jako dane referencyjne produktu.

---

## 1. Struktura ekranu

Ekran szczegółów zawiera:

1. breadcrumb `Panel główny / Pojazdy / <pojazd>`,
2. akcję `Powrót`,
3. zdjęcie pojazdu i nazwę/markę-model,
4. globalne akcje `Archiwizuj` i `Usuń`,
5. sekcję `Szczegóły pojazdu` z akcją `Edytuj`,
6. podsekcję `Dane`,
7. podsekcję `Ważność`,
8. osadzony `Kalendarz`.

Wzorzec jest analogiczny do karty pracownika: profil zasobu, terminy formalne i planowanie są rozdzielone logicznie.

---

## 2. Potwierdzone akcje globalne

### `Powrót`
Prowadzi do `/pojazdy`.

### `Archiwizuj`
Potwierdzona osobna akcja rekordu pojazdu.

Nie znamy jeszcze:
- modala potwierdzającego,
- wpływu archiwizacji na przyszłe wydarzenia kalendarza,
- możliwości przywrócenia pojazdu.

### `Usuń`
Potwierdzona osobna akcja rekordu.

Nie znamy jeszcze semantyki hard-delete/soft-delete ani reguł zależności. W naszym systemie historyczne wydarzenia, dokumenty i audyt nie mogą zostać utracone przez przypadkowe usunięcie pojazdu.

---

## 3. Sekcja `Szczegóły pojazdu`

Potwierdzona akcja:
- `Edytuj`.

### Pola w podsekcji `Dane`

Potwierdzone pola prezentowane na ekranie:
- `Marka i model`,
- `Nr rejestracyjny`,
- `Nr boczny`,
- `Vin`,
- `Pojemność (w cm³)`,
- `Rok produkcji`,
- `Kategorie`,
- `Data dodania`.

Część pól może być pusta w obserwowanym rekordzie (`Vin`, `Kategorie`). Nie oznacza to jeszcze, że pola są zawsze opcjonalne — wymagania formularza trzeba sprawdzić na `Edytuj` / `Dodaj pojazd`.

### `Nr boczny`
To osobne pole od numeru rejestracyjnego. Dla naszego modelu odpowiada wewnętrznemu numerowi/flotowemu oznaczeniu pojazdu, np. `fleet_number` / `side_number`.

### `Pojemność (w cm³)`
Pole jest prezentowane jako liczba. Dokładna walidacja i dopuszczalne zakresy pozostają do sprawdzenia.

### `Data dodania`
Na obserwowanym rekordzie UI pokazuje `01-01-0001`.

Traktujemy to jako **anomalię/sentinel/default date danych demonstracyjnych**, a nie prawidłową wartość biznesową do odwzorowania.

W naszym systemie:
- `created_at` ma rzeczywistą datę utworzenia,
- brak wartości historycznej powinien być `null`/`unknown`, nie rokiem `0001`,
- warstwa UI nie może prezentować technicznej wartości minimalnej daty jako realnej daty biznesowej.

---

## 4. Sekcja `Ważność`

Potwierdzone trzy niezależne pozycje:
- `Przegląd`,
- `OC`,
- `AC`.

Każda ma osobną datę ważności. Na przekazanym ekranie przy wszystkich trzech widoczna jest ikona ostrzegawcza, a daty są wcześniejsze niż data audytu.

Wniosek domenowy:
- przegląd techniczny, OC i AC są niezależnymi terminami,
- każdy musi mieć własny rekord/status,
- ekran potrafi sygnalizować stan wymagający uwagi.

Potwierdzone typy `vehicle_documents` / validity records:
- `technical_inspection`,
- `oc_insurance`,
- `ac_insurance`.

Nie łączymy ich w jedno pole `vehicle_document_status`.

### Minimalny model terminów

`vehicle_validities` / `vehicle_documents`:
- `id`,
- `organization_id`,
- `vehicle_id`,
- `type`,
- `valid_until`,
- `status`,
- `document_number` nullable,
- `file_id` nullable,
- timestamps.

`AC` może być opcjonalne biznesowo w naszym produkcie; sam ekran konkurenta potwierdza obsługę tego typu terminu, nie obowiązek posiadania AC przez każdy pojazd.

---

## 5. Osadzony `Kalendarz`

Karta pojazdu zawiera osadzony kalendarz.

Potwierdzone elementy:
- `Pełny kalendarz`,
- `Dodaj wydarzenie`,
- poprzedni okres,
- następny okres,
- `Dzisiaj`,
- widoki `Miesiąc`, `Tydzień`, `Dzień`,
- miesięczna siatka kalendarza.

### `Pełny kalendarz`
Potwierdzony URL z przekazanego tekstu:
- `/kalendarz`

Nie wiadomo, czy filtr pojazdu jest zachowywany w stanie aplikacji mimo braku `?v=` w pokazanym linku.

### `Dodaj wydarzenie`
Akcja jest dostępna bezpośrednio z karty pojazdu.

Do sprawdzenia po otwarciu formularza:
- czy pojazd jest wstępnie wybrany,
- typ wydarzenia,
- data/czas,
- pracownik/instruktor,
- kursant,
- lokalizacja,
- konflikty zasobów,
- notatki,
- powtarzalność,
- zapis/anulowanie.

---

## 6. Potwierdzone akcje ekranu szczegółów

- `back_to_vehicle_list`
- `archive_vehicle`
- `delete_vehicle`
- `edit_vehicle_details`
- `open_full_calendar`
- `add_calendar_event`
- `calendar_previous_period`
- `calendar_next_period`
- `calendar_today`
- `calendar_view_month`
- `calendar_view_week`
- `calendar_view_day`

---

## 7. Aktualizacja modelu `vehicles`

Minimalnie:
- `id` UUID,
- `organization_id`,
- `make`,
- `model`,
- `registration_number`,
- `side_number` nullable,
- `vin` nullable / wymagalność do sprawdzenia,
- `engine_capacity_cm3` nullable,
- `production_year` nullable,
- `photo_asset_id` nullable,
- `created_at`,
- `archived_at` nullable,
- `deleted_at` nullable — decyzja naszej implementacji.

Potwierdzone relacje:
- vehicle -> photo,
- vehicle -> validity/documents,
- vehicle -> categories (obecność pola potwierdzona, multiplicity nadal do potwierdzenia formularzem),
- vehicle -> calendar/events context.

---

## 8. Wspólny model zasobów kalendarza

Pracownik i pojazd mają na swoich kartach ten sam wzorzec kalendarza. Dla naszej architektury oznacza to, że wydarzenie powinno łączyć niezależne zasoby, np.:

- `staff_id`,
- `vehicle_id`,
- `location_id`,
- `student_id`.

Konflikty powinny być sprawdzane per zasób w tym samym przedziale czasu. Nie budujemy osobnego silnika kalendarza dla pojazdów i pracowników.

---

## 9. Czego nadal brakuje w module Pojazdy

Po tym ekranie pozostają głównie:
- formularz `Dodaj pojazd`,
- formularz `Edytuj`,
- wymagane/optional pola i walidacje,
- sposób wyboru kategorii i ich multiplicity,
- upload/usuwanie zdjęcia,
- dokładny lifecycle `Archiwizuj`,
- dokładny lifecycle `Usuń`,
- relacja pojazd <-> lokalizacja, jeśli występuje,
- formularz `Dodaj wydarzenie`,
- filtry/wyszukiwanie/sortowanie listy,
- komunikaty success/error.

Nie jest już luką:
- ekran szczegółów,
- OC,
- AC,
- `Nr boczny`,
- pojemność,
- rok produkcji,
- archiwizacja jako dostępna akcja,
- usuwanie jako dostępna akcja,
- kalendarz osadzony na karcie pojazdu.

---

## 10. Acceptance criteria

### AC-VEH-DET-01 — szczegóły
Administrator widzi na karcie pojazdu podstawowe dane techniczno-ewidencyjne oraz terminy formalne.

### AC-VEH-DET-02 — trzy terminy
Przegląd, OC i AC są przechowywane i oceniane niezależnie.

### AC-VEH-DET-03 — archiwizacja i usuwanie
UI udostępnia oddzielne akcje `Archiwizuj` i `Usuń`.

### AC-VEH-DET-04 — embedded calendar
Karta pojazdu udostępnia kalendarz z widokami miesiąc/tydzień/dzień oraz akcją `Dodaj wydarzenie`.

### AC-VEH-DET-05 — data sentinel
System nie prezentuje technicznej wartości minimalnej daty jako prawidłowej `Daty dodania`; brak danych historycznych jest reprezentowany jawnie jako brak/nieznane.

### AC-VEH-DET-06 — tenant isolation
UUID pojazdu i wszystkie operacje na jego dokumentach/kalendarzu są autoryzowane względem bieżącego OSK.
