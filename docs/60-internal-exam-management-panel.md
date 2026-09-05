# 60. Egzamin wewnętrzny — panel „Zarządzaj egzaminami”

Data weryfikacji: 2026-09-05

**Route:** `/egzamin-wewnetrzny/panel`  
**Kontekst:** `Egzamin wewnętrzny -> Panel - Generuj egzamin`  
**Źródło:** bieżące zalogowane ekrany + screenshoty i dokumenty przekazane podczas audytu  
**Status:** `USER_CONFIRMED_AUTH_SCREEN`

Powiązane:
- zakup egzaminów: `docs/59-internal-exam-purchase-screen.md`,
- rozwinięta historia prób: `docs/61-internal-exam-expanded-attempt-history.md`,
- arkusz odpowiedzi PDF: `docs/62-internal-exam-answer-sheet-pdf.md`,
- wynik i szczegóły próby: `docs/63-internal-exam-result-details-screen.md`,
- review pojedynczego pytania: `docs/64-internal-exam-question-review.md`,
- generowanie dostępu/uruchomienie: `docs/65-internal-exam-generation-access-flow.md`,
- formalna ewidencja i zwolnienia z teorii: `docs/66-formal-student-record-and-theory-exemptions.md`,
- edytowalne wymagania kursu: `docs/67-editable-training-requirements-and-theory-exemption.md`,
- edycja danych przed egzaminem: `docs/68-internal-exam-edit-candidate-data.md`,
- filtrowanie: `docs/69-internal-exam-filtering.md`.

---

## 1. Znaczenie ekranu

`Zarządzaj egzaminami` jest centralnym panelem operacyjnym OSK do:
- podglądu puli egzaminów,
- rozróżnienia puli darmowej i opłaconej,
- generowania egzaminu dla jednego kursanta,
- przeglądania historii prób,
- filtrowania, sortowania i wyszukiwania,
- przechodzenia do wyniku/szczegółów konkretnej próby,
- pobierania formalnego wydruku próby.

Dla naszego produktu egzamin jest funkcją istniejącego kursanta i formalnego `course_enrollment`, a nie niezależnym narzędziem dla osoby ad hoc.

---

## 2. Inventory egzaminów

Panel pokazuje:
- `Darmowe` — obserwowane 10, opis `Egzaminy darmowe w tym miesiącu`,
- `Opłacone` — obserwowane 150,
- `Wszystkie` — obserwowane 160.

Snapshot: `10 + 150 = 160`.

Własny model zachowuje źródło jednostki (`free_monthly`, `paid`), a `total_available` jest projekcją.

`Dokup egzaminy` prowadzi do `/egzamin-wewnetrzny/wykup`.

---

## 3. Lista `Przydzielone egzaminy wewnętrzne`

Potwierdzone kontrolki:
- zielony `Generuj egzamin`,
- wybór dokładnie jednego wiersza kursanta,
- stan `ZAZNACZONE (1)`,
- `Generuj egzaminy (1)`,
- `Ukryj egzaminy zakończone`,
- wyszukiwarka,
- `Filtruj`,
- `Sortuj`.

Jeden wiersz jest agregatem kursanta i jego prób, nie pojedynczą próbą.

Potwierdzone kolumny:
- `Email lub login`,
- `Imię i nazwisko`,
- `Najnowszy egzamin`: `Kat.`, `Status`, `Data`, `Język`,
- `Liczba egzaminów`,
- akcje.

Zaobserwowano m.in.:
- Ala Nowak — 7 egzaminów,
- Paweł Kowalski — 2 egzaminy.

---

## 4. Generowanie egzaminu

Są dwa wejścia do tego samego drawera `Generuj dostęp do egzaminu wewnętrznego`:

### A. Z zaznaczonym kursantem
`zaznacz 1 -> ZAZNACZONE (1) -> Generuj egzaminy (1)`

Drawer otwiera się z gotowym kursantem.

### B. Bez zaznaczenia
`Generuj egzamin`

Konkurent pozwala wtedy wyszukać istniejącego kursanta albo użyć sekcji `Dodaj nowego kursanta`.

**Decyzja naszego produktu:** formalny egzamin wymaga trwałego `student_id` i `course_enrollment_id`. Jeśli sekretarka dodaje osobę z poziomu egzaminu, najpierw tworzymy trwałego kursanta/kurs, a dopiero potem wracamy do generowania.

Pełny flow: `docs/65-internal-exam-generation-access-flow.md`.

---

## 5. Dane egzaminacyjne i edycja

Zaobserwowane dane kursanta w flow:
- kategoria egzaminu,
- język egzaminu,
- imię,
- nazwisko,
- PESEL,
- PKK / nr ewidencyjny,
- email.

`Edytuj dane` otwiera edytowalny formularz tych pól z akcjami `Anuluj` i `Zapisz dane`.

Dla naszego produktu dane formalne są źródłowo powiązane z profilem kursanta i enrollmentem, a konkretna utworzona próba dostaje immutable snapshot.

---

## 6. Dwa tryby uruchomienia

### `Udostępnij link do egzaminu`
- generuje link,
- pozwala wykonać egzamin zdalnie,
- link jest wysyłany na email.

### `Rozpocznij egzamin teraz`
- uruchamia egzamin na bieżącym stanowisku,
- UI informuje: `Dostępne tylko dla 1 kursanta jednocześnie.`

Własny model: dwa tryby `internal_exam_launch` (`remote_link`, `local_station`), nie dwa typy egzaminu.

---

## 7. Statusy i filtrowanie

Potwierdzony mapping z historii:
- `Niezaliczony` -> czerwony `x` w wierszu głównym.

Drawer `Filtrowanie` zawiera:
- `Kategoria kursu` — multi-select,
- statusy:
  - `Brak przypisanego`,
  - `Niezaliczony`,
  - `Nie przeprowadzony`,
  - `Zaliczony`.

To potwierdza, że `Brak przypisanego` oraz `Nie przeprowadzony` są dwoma odrębnymi stanami UI. Dokładny wewnętrzny lifecycle konkurenta pozostaje nieobserwowany.

W naszym produkcie `Brak przypisanego` nie może oznaczać braku teoretycznego egzaminu tam, gdzie teoria zgodnie z rule engine nie jest wymagana (np. właściwy przebieg C+E).

Szczegóły: `docs/69-internal-exam-filtering.md`.

---

## 8. `Rozwiń` — historia prób

Każda próba jest osobnym rekordem z:
- imieniem i nazwiskiem,
- kategorią,
- statusem,
- nr ewidencyjnym/PKK,
- datą,
- językiem,
- `Szczegóły`,
- `Pobierz wydruk`.

Timestamp nie jest identyfikatorem próby — zaobserwowano różne rekordy z tą samą minutą.

---

## 9. `Szczegóły` i review wyniku

`Szczegóły` prowadzi do tokenizowanego frontu `/egzamin-wewnetrzny?pid=<opaque_token>`.

Dla zakończonej próby pokazuje:
- wynik punktowy,
- rezultat,
- nawigację po 32 pytaniach,
- statystyki,
- review pytanie po pytaniu.

Review pokazuje media, treść pytania, wartość punktową, kategorię, poprawną odpowiedź i odpowiedź wybraną przez kursanta.

---

## 10. `Pobierz wydruk`

Generuje per-attempt PDF `Arkusz odpowiedzi` zawierający:
- dane kandydata,
- datę i kategorię,
- 32 pozycje egzaminu,
- identyfikatory pytań,
- punktację,
- odpowiedzi,
- punkty uzyskane,
- sumę i wynik,
- miejsca na podpisy.

---

## 11. Model naszego produktu

Encje:
- `internal_exam_inventory_ledger`,
- `internal_exam_attempts`,
- `internal_exam_attempt_questions`,
- `internal_exam_launches`,
- `internal_exam_launch_tokens`,
- `internal_exam_results`,
- `internal_exam_documents`.

Formalny attempt wymaga:
- `organization_id`,
- `student_id`,
- `course_enrollment_id`,
- części egzaminu wymaganej przez aktualny rule engine,
- snapshotu danych kursanta/kursu,
- dla teorii immutable snapshotu pytań, kolejności, odpowiedzi i punktacji.

Nie dopuszczamy w naszym formalnym workflow `student_id = null` ani ephemeral `ad_hoc_candidate`.

Wymagania teorii/praktyki są edytowalne na różnych etapach kursu i przeliczane na podstawie faktów formalnych, z pełnym audytem.

---

## 12. Potwierdzone akcje

- `open_internal_exam_management_panel`
- `view_free_exam_inventory`
- `view_paid_exam_inventory`
- `view_total_exam_inventory`
- `open_exam_purchase`
- `open_standalone_generate_exam_drawer`
- `search_existing_student_for_exam`
- `competitor_enter_new_student_data_in_drawer`
- `select_one_student_for_exam_generation`
- `open_generate_exam_for_selected_student`
- `view_candidate_exam_generation_data`
- `edit_candidate_exam_data`
- `share_exam_link_by_email`
- `start_exam_on_current_station`
- `toggle_hide_finished_exams`
- `search_exam_rows`
- `open_exam_filters`
- `filter_by_multiple_course_categories`
- `filter_by_exam_status`
- `open_exam_sorting`
- `expand_exam_history`
- `collapse_exam_history`
- `view_exam_attempt_history`
- `view_not_passed_exam_status`
- `open_specific_exam_attempt_details`
- `review_specific_exam_questions`
- `download_specific_exam_attempt_printout`

---

## 13. Pozostałe niewiadome

Do dalszego capture:
- pełna lista kategorii i języków egzaminu,
- `Sortuj`,
- exact search fields,
- wizualizacja statusu `Zaliczony` w tabeli,
- dokładne znaczenie kolumny `Data`,
- czas trwania egzaminu,
- kolejność konsumpcji darmowej/opłaconej puli,
- reset darmowej puli,
- moment konsumpcji jednostki egzaminu,
- TTL/revocation/resend zdalnego linku,
- stany sukcesu/błędu po wysłaniu linku i uruchomieniu lokalnym.
