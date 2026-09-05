# 80. Kursanci — dodawanie nowego kursu (PKK)

Data weryfikacji: 2026-09-05

**Kontekst:** `Kursanci -> Profil kursanta -> Kursy (PKK) -> Dodaj kurs`  
**Źródło:** bieżący zalogowany drawer przekazany podczas audytu  
**Status:** `USER_CONFIRMED_AUTH_SCREEN`

## 1. Cel ekranu

Drawer `Dodaj kurs` tworzy nowy kurs w kontekście istniejącego kursanta. Kurs jest osobnym rekordem powiązanym z numerem PKK, kategorią, rodzajem szkolenia, godzinami, instruktorem i opcjonalną lokalizacją.

## 2. Pola formularza

Potwierdzone pola i wymagania z UI:

- `Rodzaj` — **wymagane**; opcje:
  - `Szkolenie podstawowe`,
  - `Szkolenie uzupełniające`.
- `Kategoria` — **wymagane**; potwierdzone opcje:
  - `A`, `B`, `C`, `D`, `T`, `A1`, `B1`, `C1`, `D1`, `AM`, `A2`,
  - `B+E`, `C1+E`, `C+E`, `D1+E`, `D+E`, `PT`.
- `PKK` — **wymagane**.
- `Data rozpoczęcia` — **wymagane**; osobne kontrolki daty i godziny.
- `Koszt` — widoczne pole bez oznaczenia wymagalności.
- `Godzin teorii` — pole bez oznaczenia wymagalności.
- `Teoria odbyta w innej szkole` — pole bez oznaczenia wymagalności.
- `Godzin praktyki` — **wymagane**.
- `Praktyka odbyta w innej szkole` — pole bez oznaczenia wymagalności.
- `Instruktor` — **wymagany**; wyszukiwany select.
- `Lokalizacja` — opcjonalny select.

Akcje:
- `Zapisz`,
- `Anuluj`,
- zamknięcie `X`.

## 3. Relacja z płatnością

W ekranie tworzenia kursu występuje pole `Koszt`. Po utworzeniu kursu koszt jest prezentowany w sekcji `Płatności`, a w formularzu edycji kursu UI wskazuje, że koszt edytuje się właśnie w sekcji płatności.

Dla naszego produktu:
- utworzenie kursu może jednocześnie utworzyć plan należności / rozliczenie początkowe,
- dalsza edycja ceny odbywa się w bounded context `Payments`,
- kurs nie przechowuje historii wpłat jako własnych pól.

## 4. Godziny bieżącego i poprzedniego OSK

Formularz od razu rozdziela:
- teorię realizowaną w bieżącym OSK,
- teorię odbytą w innej szkole,
- praktykę realizowaną w bieżącym OSK,
- praktykę odbytą w innej szkole.

To potwierdza, że kurs może rozpocząć się z uznanym wcześniej zakresem szkolenia.

W naszym systemie pola zagregowane powinny być wspierane przez:
- ewidencję faktycznie zrealizowanych zajęć w bieżącym OSK,
- osobne, audytowalne wpisy `recognized_external_training` dla godzin uznanych z innego OSK.

## 5. Reguły formalne i zwolnienia

Sam konkurencyjny formularz nie pokazuje osobnego pola `zwolniony z teorii`.

W naszym produkcie po wyborze rodzaju i kategorii uruchamiamy `training_requirements_engine`. Sekretariat może później zmienić podstawę wymagań/zwolnienia; nie blokujemy jej na etapie utworzenia kursu.

Przykładowo kurs `C+E` może mieć teorię niewymaganą, ale nadal musi posiadać pełny rekord kursu i ewidencję praktyki.

## 6. Minimalny model danych

`course_enrollment`:
- `student_id`,
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
- `requirements_profile_id`,
- `created_by`,
- `created_at`.

Warunki finansowe są powiązane przez osobny rekord rozliczenia.

## 7. Potwierdzone akcje

- `open_course_create_drawer`
- `select_training_type`
- `select_license_category`
- `enter_pkk_number`
- `set_course_start_datetime`
- `enter_course_cost`
- `enter_theory_hours`
- `enter_theory_hours_previous_school`
- `enter_practice_hours`
- `enter_practice_hours_previous_school`
- `select_lead_instructor`
- `select_course_location`
- `save_course`
- `cancel_course_creation`

## 8. Nieobserwowane detale

- exact walidacje numeru PKK,
- exact minimalne/maksymalne wartości pól godzinowych,
- zachowanie dla duplikatu PKK/kategorii,
- komunikaty błędów/sukcesu.

Projektujemy je po swojemu zgodnie z przepisami, audytem i spójnym silnikiem wymagań szkolenia.
