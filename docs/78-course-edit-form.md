# 78. Kursanci — edycja istniejącego kursu (PKK)

Data weryfikacji: 2026-09-05

**Kontekst:** `Kursanci -> Profil kursanta -> Kursy (PKK) -> Edytuj`  
**Źródło:** bieżący zalogowany drawer przekazany podczas audytu  
**Status:** `USER_CONFIRMED_AUTH_SCREEN`

## 1. Najważniejsze potwierdzenie

Akcja `Edytuj` przy istniejącym kursie działa i otwiera formularz edycji kursu w kontekście konkretnego numeru PKK.

Zaobserwowany nagłówek:
- `Kurs: 445645645646455`.

Formularz pozwala zmieniać zarówno podstawowe dane kursu, jak i dane dotyczące godzin odbytych w bieżącym lub innym OSK.

## 2. Pola formularza

Potwierdzone pola:
- `Rodzaj` — wymagane; opcje: `Szkolenie podstawowe`, `Szkolenie uzupełniające`,
- `Kategoria` — wymagane; potwierdzone opcje: `A`, `B`, `C`, `D`, `T`, `A1`, `B1`, `C1`, `D1`, `AM`, `A2`, `B+E`, `C1+E`, `C+E`, `D1+E`, `D+E`, `PT`,
- `PKK` — wymagane,
- `Data rozpoczęcia` — wymagane, data + godzina,
- `Koszt (edytuj koszt w sekcji 'Płatności')` — widoczny, ale w tym formularzu nieedytowalny,
- `Godzin teorii`,
- `Teoria odbyta w innej szkole`,
- `Godzin praktyki` — wymagane,
- `Praktyka odbyta w innej szkole`,
- `Instruktor` — wymagany,
- `Lokalizacja`.

Akcje:
- `Zapisz`,
- `Anuluj`,
- zamknięcie `X`.

## 3. Zaobserwowany przykład

- Rodzaj: `Szkolenie podstawowe`,
- Kategoria: `A`,
- PKK: `445645645646455`,
- Data rozpoczęcia: `27.01.2026 15:40`,
- Koszt: `4000.00`,
- Godzin teorii: `10`,
- Teoria odbyta w innej szkole: `20`,
- Godzin praktyki: `30`,
- Praktyka odbyta w innej szkole: `0`,
- Instruktor: `Anna Nowak`,
- Lokalizacja: `Plan nauki jazdy`.

## 4. Ważny wniosek dotyczący płatności

Pole kosztu jest w formularzu kursu tylko informacyjne. UI wprost wskazuje:
`Koszt (edytuj koszt w sekcji 'Płatności')`.

Dla naszego produktu oznacza to rozdzielenie:
- danych formalnych i szkoleniowych kursu,
- warunków finansowych/rozliczenia kursanta.

Zmiana ceny kursu powinna następować w module płatności/rozliczeń, a nie jako zwykła edycja danych kursu.

## 5. Godziny szkolenia

Formularz przechowuje osobno:
- godziny teorii z bieżącego kursu,
- teorię odbytą w innej szkole,
- godziny praktyki z bieżącego kursu,
- praktykę odbytą w innej szkole.

To jest istotne dla formalnej ewidencji i potwierdza, że model kursu musi umieć uwzględnić szkolenie rozpoczęte lub częściowo odbyte w innym OSK.

Dla własnego produktu rekomendujemy, aby wartości zagregowane w formularzu były projekcją z ewidencji źródłowych sesji/zajęć oraz ewentualnych uznanych godzin z innego OSK, a nie jedynym źródłem prawdy.

## 6. Relacja z elastycznymi wymaganiami kursu

Edycja kursu musi pozostać możliwa również po jego utworzeniu. Jest to spójne z wcześniejszą decyzją produktową, że sekretariat może później poprawić kategorię, rodzaj szkolenia, dane formalne i podstawę zwolnienia z teorii, jeżeli pierwotnie kurs został skonfigurowany błędnie.

Zmiana danych nie może kasować historii:
- zachowujemy audit `before/after`,
- wykonane zajęcia pozostają w historii,
- wymagania są przeliczane ponownie,
- formalne korekty po zamknięciu kursu wymagają trybu korekty.

## 7. Model danych

Minimalne pola `course_enrollment` wspierane przez ten ekran:
- `training_type`,
- `license_category`,
- `pkk_number`,
- `started_at`,
- `theory_hours_current_osk`,
- `theory_hours_previous_osk`,
- `practice_hours_current_osk`,
- `practice_hours_previous_osk`,
- `lead_instructor_id`,
- `location_id`,
- `updated_at`.

Koszt pozostaje relacją do rozliczenia/płatności, nie polem edytowanym w tym formularzu.

## 8. Potwierdzone akcje

- `open_course_edit_drawer`
- `edit_training_type`
- `edit_license_category`
- `edit_pkk_number`
- `edit_course_start_datetime`
- `edit_theory_hours`
- `edit_theory_hours_from_previous_school`
- `edit_practice_hours`
- `edit_practice_hours_from_previous_school`
- `change_lead_instructor`
- `change_course_location`
- `save_course_changes`
- `cancel_course_edit`

## 9. Nieobserwowane detale

- exact walidacje przy zmianie numeru PKK,
- zachowanie przy zmianie kategorii po wykonanych zajęciach,
- konflikty ze zrealizowanym egzaminem wewnętrznym,
- komunikaty sukcesu/błędu.

Te elementy projektujemy po swojemu z walidacją, audytem i bez utraty danych historycznych.
