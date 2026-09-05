# 35. Kursanci — formularz Dodaj kurs

Data weryfikacji: 2026-09-05

**Kontekst:** akcja `Dodaj kurs` na karcie kursanta  
**Źródło:** bieżący zalogowany ekran + screenshot przekazany podczas audytu  
**Status:** `USER_CONFIRMED_AUTH_SCREEN`

---

## 1. Znaczenie formularza

Formularz `Dodaj kurs` tworzy kurs/enrollment przypisany do konkretnego kursanta. Jest kluczowym elementem karty kursanta, ponieważ dopiero po dodaniu kursu system pozwala przejść do zarządzania etapem szkolenia.

Kurs nie jest pojedynczym polem na profilu kursanta. Jest osobną encją z własnym rodzajem szkolenia, kategorią, PKK, datą rozpoczęcia, godzinami, instruktorem, lokalizacją i kosztem.

---

## 2. Potwierdzone pola

1. `Rodzaj *`
2. `Kategoria *`
3. `PKK *`
4. `Data rozpoczęcia *`
   - kontrolka daty,
   - kontrolka czasu,
5. `Koszt`
6. `Godzin teorii`
7. `Teoria odbyta w innej szkole`
8. `Godzin praktyki *`
9. `Praktyka odbyta w innej szkole`
10. `Instruktor *`
11. `Lokalizacja`

Potwierdzone akcje:
- `Zapisz`,
- `Anuluj`,
- zamknięcie drawer przez `X`.

---

## 3. Pola wymagane według widocznych markerów UI

Widoczną gwiazdką oznaczono:
- Rodzaj,
- Kategoria,
- PKK,
- Data rozpoczęcia,
- Godzin praktyki,
- Instruktor.

Nie oznaczono gwiazdką:
- Koszt,
- Godzin teorii,
- Teoria odbyta w innej szkole,
- Praktyka odbyta w innej szkole,
- Lokalizacja.

To jest potwierdzenie wymagania na poziomie UI. Dokładne backendowe reguły walidacji pozostają do sprawdzenia.

`Data rozpoczęcia` jest prezentowana jako grupa date + time. Nie rozdzielamy bez dalszej walidacji wymagania na dwa niezależne pola — domenowo przechowujemy jeden `starts_at`.

---

## 4. Rodzaj szkolenia

Potwierdzony select `Rodzaj` zawiera:
- `Szkolenie podstawowe`,
- `Szkolenie uzupełniające`.

Modelować jako stabilny kod domenowy + lokalizowaną etykietę, np.:
- `basic_training`,
- `supplementary_training`.

Nie przechowywać tekstu UI jako klucza biznesowego.

---

## 5. Kategorie

Potwierdzony select `Kategoria` zawiera obserwowane opcje:
- A,
- B,
- C,
- D,
- T,
- A1,
- B1,
- C1,
- D1,
- AM,
- A2,
- B+E,
- C1+E,
- C+E,
- D1+E,
- D+E,
- PT.

W formularzu jest to pojedynczy select — jeden kurs ma jedną wybraną kategorię.

Lista kategorii powinna pochodzić ze słownika/configuration, nie być zaszyta w formularzu.

---

## 6. PKK

Pole `PKK` jest widoczne jako wymagane w tym formularzu.

Własny model powinien rozdzielać:
- wartość/referencję PKK,
- profil PKK pobrany z integracji,
- historię operacji PKK.

Nie traktować numeru PKK jako identyfikatora technicznego rekordu kursu.

Dokładne walidacje formatu PKK i powiązanie z integracją pozostają do audytu.

---

## 7. Data rozpoczęcia

Formularz posiada:
- date picker,
- time picker.

Domenowo rekomendowane pole:
- `starts_at` z poprawną strefą czasową OSK.

Do weryfikacji:
- czy czas jest obowiązkowy razem z datą,
- czy możliwa jest data w przeszłości,
- minimalna/maksymalna data,
- wpływ na dokumentację kursu i kalendarz.

---

## 8. Koszt

Potwierdzone pole `Koszt` bez widocznej gwiazdki wymagania.

Nie potwierdzono:
- waluty w samym formularzu,
- netto/brutto,
- automatycznego tworzenia pozycji w sekcji `Płatności`,
- możliwości późniejszej zmiany kosztu,
- rabatów.

Dla naszego produktu koszt kursu i ledger płatności kursanta pozostają osobnymi pojęciami. Ewentualne automatyczne utworzenie należności powinno być jawną regułą biznesową.

---

## 9. Godziny szkolenia i transfer z innej szkoły

Potwierdzone są cztery osobne pola:
- `Godzin teorii`,
- `Teoria odbyta w innej szkole`,
- `Godzin praktyki`,
- `Praktyka odbyta w innej szkole`.

To potwierdza, że system rozróżnia co najmniej:
- zakres teorii/praktyki przypisany do bieżącego kursu,
- część szkolenia odbywaną/zaliczaną z innej szkoły.

### Wniosek domenowy
Nie modelować godzin jako jednego `hours_total`.

Minimalny model kursu powinien rozdzielać:
- `theory_hours_planned`,
- `theory_hours_external`,
- `practice_hours_planned`,
- `practice_hours_external`.

Własna implementacja może później rozdzielić dodatkowo:
- wymagane minimum,
- wykonane w naszym OSK,
- uznane zewnętrzne,
- skorygowane przez instruktora,
- pozostałe do wykonania.

Dokładna semantyka liczb wpisywanych w formularzu konkurenta nie jest jeszcze potwierdzona poza etykietami UI.

---

## 10. Instruktor

Pole `Instruktor *` jest wymagane.

Kontrolka ma placeholder:
- `Zacznij wpisywać by wyszukać`.

To potwierdza wyszukiwany select/autocomplete pracowników/instruktorów.

Własne API musi:
- zwracać tylko pracowników bieżącego OSK,
- filtrować kandydatów zgodnie z uprawnieniami/kategorią tam, gdzie wymagają tego reguły produktu,
- nie ufać `instructor_id` z frontendu bez tenant validation.

Nie potwierdzono jeszcze, czy UI konkurenta filtruje instruktorów automatycznie według wybranej kategorii.

---

## 11. Lokalizacja

Pole `Lokalizacja` jest pojedynczym selectem i nie ma widocznej gwiazdki.

W obserwowanym dropdownie widoczne były istniejące rekordy lokalizacji OSK, w tym nazwy odpowiadające wcześniej zaobserwowanym lokalizacjom.

Wniosek:
- kurs może opcjonalnie wskazywać lokalizację OSK,
- formularz korzysta z kolekcji `locations` organizacji.

Nie wnioskujemy na podstawie tego dropdownu o `type` lokalizacji — wyświetlana wartość może być nazwą rekordu.

---

## 12. Model domenowy

Rekomendowana encja `student_courses` / `enrollments`:
- id,
- organization_id,
- student_id,
- training_type,
- category_id,
- pkk_reference,
- starts_at,
- course_cost nullable,
- theory_hours_planned nullable,
- theory_hours_external nullable,
- practice_hours_planned,
- practice_hours_external nullable,
- instructor_id,
- location_id nullable,
- training_stage,
- created_at,
- updated_at.

Relacje:
- belongsTo student,
- belongsTo category,
- belongsTo instructor/staff,
- belongsTo location nullable,
- may reference PKK aggregate,
- has history of training-stage changes.

---

## 13. Potwierdzone akcje

- `open_add_student_course`
- `select_training_type`
- `select_course_category`
- `enter_pkk`
- `pick_course_start_date`
- `pick_course_start_time`
- `enter_course_cost`
- `enter_theory_hours`
- `enter_external_theory_hours`
- `enter_practice_hours`
- `enter_external_practice_hours`
- `search_select_instructor`
- `select_course_location`
- `save_student_course`
- `cancel_student_course_create`
- `close_student_course_drawer`

---

## 14. Czego nadal nie znamy

- exact backend validations,
- format/validation PKK,
- zakres i format pól godzinowych,
- czy godziny mogą być ułamkowe,
- czy `Koszt` tworzy automatycznie należność,
- czy instruktor jest filtrowany wg kategorii,
- czy lokalizacja jest filtrowana wg typu,
- czy można mieć kilka aktywnych kursów jednocześnie,
- zachowanie przy duplikacie kursu/kategorii/PKK,
- success/error messages,
- ekran kursu po zapisaniu,
- wpływ zapisu na początkowy etap szkolenia.

---

## 15. Acceptance criteria

### AC-COURSE-01 — required fields
Formularz wymaga co najmniej rodzaju, kategorii, PKK, daty rozpoczęcia, godzin praktyki i instruktora zgodnie z potwierdzonym UI.

### AC-COURSE-02 — training type
Administrator może wybrać szkolenie podstawowe lub uzupełniające.

### AC-COURSE-03 — category
Jeden enrollment ma jedną kategorię ze słownika obsługiwanych kategorii.

### AC-COURSE-04 — external training hours
System przechowuje teorię i praktykę z rozróżnieniem wartości kursu oraz części odbytej w innej szkole.

### AC-COURSE-05 — instructor
Kurs jest przypisany do instruktora należącego do bieżącego OSK.

### AC-COURSE-06 — optional location
Kurs może wskazać lokalizację organizacji.

### AC-COURSE-07 — tenant isolation
Nie można przypisać instruktora, lokalizacji, kategorii lub kursanta spoza dozwolonego kontekstu organizacji.
