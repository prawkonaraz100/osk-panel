# 60. Egzamin wewnętrzny — panel „Zarządzaj egzaminami”

Data weryfikacji: 2026-09-05

**Route:** `/egzamin-wewnetrzny/panel`  
**Kontekst:** `Egzamin wewnętrzny -> Panel - Generuj egzamin`  
**Źródło:** bieżące zalogowane ekrany + screenshoty przekazane podczas audytu  
**Status:** `USER_CONFIRMED_AUTH_SCREEN`

Powiązane:
- zakup egzaminów: `docs/59-internal-exam-purchase-screen.md`,
- rozwinięta historia prób: `docs/61-internal-exam-expanded-attempt-history.md`,
- arkusz odpowiedzi PDF: `docs/62-internal-exam-answer-sheet-pdf.md`,
- szczegóły/wynik próby: `docs/63-internal-exam-result-details-screen.md`,
- review pojedynczego pytania: `docs/64-internal-exam-question-review.md`,
- generowanie dostępu/uruchomienie: `docs/65-internal-exam-generation-access-flow.md`.

---

## 1. Znaczenie ekranu

`Zarządzaj egzaminami` jest centralnym panelem operacyjnym OSK do:
- podglądu puli egzaminów,
- rozróżnienia puli darmowej i opłaconej,
- generowania egzaminu dla pojedynczego kandydata,
- przeglądania historii prób,
- filtrowania, sortowania i wyszukiwania,
- przechodzenia do szczegółów konkretnej próby,
- pobierania wydruku konkretnego egzaminu.

---

## 2. Inventory egzaminów

Panel pokazuje:
- `Darmowe` — obserwowane 10, opis `Egzaminy darmowe w tym miesiącu`,
- `Opłacone` — obserwowane 150,
- `Wszystkie` — obserwowane 160.

Snapshot jest spójny:

`10 + 150 = 160`

Wniosek dla naszego modelu: inventory musi zachowywać źródło jednostki (`free_monthly`, `paid`), a `total_available` jest projekcją.

`Dokup egzaminy` prowadzi do `/egzamin-wewnetrzny/wykup`.

---

## 3. Sekcja `Przydzielone egzaminy wewnętrzne`

Potwierdzone kontrolki:
- zielony `Generuj egzamin`,
- wybór jednego wiersza kursanta checkboxem,
- stan `ZAZNACZONE (1)`,
- `Generuj egzaminy (1)` dla wybranego kursanta,
- `Ukryj egzaminy zakończone`,
- wyszukiwarka,
- `Filtruj`,
- `Sortuj`.

### Dwa potwierdzone wejścia do generowania

#### A. Z zaznaczonym kursantem

`zaznacz 1 -> ZAZNACZONE (1) -> Generuj egzaminy (1)`

Drawer otwiera się z już wybranym kandydatem.

#### B. Bez zaznaczenia

`Generuj egzamin`

Drawer otwiera się bez kandydata i pozwala:
- wyszukać istniejącego kursanta po imieniu, nazwisku, emailu, loginie lub PESEL,
- albo wpisać dane nowego kandydata bezpośrednio w formularzu.

W obu wariantach generowanie jest jednoosobowe.

Pełny flow: `docs/65-internal-exam-generation-access-flow.md`.

---

## 4. Drawer `Generuj dostęp do egzaminu wewnętrznego`

Na górze:
- `Dostępne egzaminy: 160` w obserwowanym stanie.

### Stan bez kandydata

Sekcja `Wyszukaj kursanta` oraz alternatywa `Dodaj nowego kursanta`.

Pola nowego kandydata:
- `Kategoria egzaminu`,
- `Język egzaminu`,
- `Imię`,
- `Nazwisko`,
- `Numer ewidencyjny/PKK`,
- `Pesel`,
- `Email`.

Email jest opisany jako opcjonalny i służący do wysłania linku egzaminacyjnego.

Nie potwierdzono jeszcze, czy `Dodaj nowego kursanta` tworzy trwały rekord w module `Kursanci`, czy tylko kandydata egzaminacyjnego w bieżącym flow.

### Stan z kandydatem

Zaobserwowano kartę Ali Nowak zawierającą:
- kategorię,
- język,
- imię,
- nazwisko,
- PKK,
- PESEL,
- email,
- `Usuń`,
- `Edytuj dane`.

### Dwa tryby uruchomienia

`Udostępnij link do egzaminu`:
- generuje link,
- pozwala wykonać egzamin zdalnie,
- wysyła link na email.

`Rozpocznij egzamin teraz`:
- uruchamia egzamin na bieżącym stanowisku,
- UI informuje: `Dostępne tylko dla 1 kursanta jednocześnie.`

Dla naszego produktu są to dwa tryby `internal_exam_launch`, nie dwa różne typy egzaminu.

---

## 5. Granularność wiersza głównego

Jeden wiersz reprezentuje kursanta / kontekst egzaminacyjny wraz z agregatem jego prób, a nie pojedynczy egzamin.

Potwierdzone kolumny:
- `Email lub login`,
- `Imię i nazwisko`,
- `Najnowszy egzamin`: `Kat.`, `Status`, `Data`, `Język`,
- `Liczba egzaminów`,
- akcje.

Zaobserwowano:
- Ala Nowak — 7 egzaminów,
- Paweł Kowalski — 2 egzaminy.

---

## 6. Status najnowszego egzaminu

Dla Ali czerwony `x` odpowiada statusowi `Niezaliczony` potwierdzonemu po rozwinięciu historii.

Statusy pozytywne i stany przed wykonaniem pozostają do dalszego capture.

---

## 7. `Rozwiń` — historia konkretnych prób

Rozwinięcie pokazuje każdą próbę osobno z:
- imieniem i nazwiskiem,
- kategorią,
- statusem,
- nr ewidencyjnym/PKK,
- datą,
- językiem,
- `Szczegóły`,
- `Pobierz wydruk`.

Timestamp nie jest identyfikatorem próby — zaobserwowano różne rekordy z tą samą wyświetlaną minutą.

---

## 8. `Szczegóły` i review wyniku

`Szczegóły` prowadzi do tokenizowanego frontu:

`/egzamin-wewnetrzny?pid=<opaque_token>`

Dla zakończonej próby pokazuje:
- wynik punktowy,
- rezultat,
- nawigację po 32 pytaniach,
- statystyki,
- review pytanie-po-pytaniu.

Review pokazuje media, treść pytania, wartość punktową, kategorię, odpowiedź poprawną i odpowiedź wybraną przez kursanta.

---

## 9. `Pobierz wydruk`

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

## 10. Model naszego produktu

Encje:
- `internal_exam_inventory_ledger`,
- `internal_exam_accesses`,
- `internal_exam_attempts`,
- `internal_exam_attempt_questions`,
- `internal_exam_launches`,
- `internal_exam_launch_tokens`,
- `internal_exam_results`,
- `internal_exam_documents`.

Dodatkowo kandydat może pochodzić z:
- `existing_student`,
- `ad_hoc_candidate`.

`student_id` może być nullable dla kandydata wprowadzonego wyłącznie do egzaminu.

`internal_exam_attempt` jest źródłem prawdy dla próby, a `internal_exam_launch` opisuje sposób uruchomienia: `remote_link` lub `local_station`.

Każdy zakończony attempt zachowuje immutable snapshot danych kandydata, pytań, kolejności, punktacji, odpowiedzi i wyniku.

---

## 11. Potwierdzone akcje

- `open_internal_exam_management_panel`
- `view_free_exam_inventory`
- `view_paid_exam_inventory`
- `view_total_exam_inventory`
- `open_exam_purchase`
- `open_standalone_generate_exam_drawer`
- `search_existing_student_for_exam`
- `enter_ad_hoc_exam_candidate`
- `select_one_student_for_exam_generation`
- `open_generate_exam_for_selected_student`
- `view_candidate_exam_generation_data`
- `share_exam_link_by_email`
- `start_exam_on_current_station`
- `toggle_hide_finished_exams`
- `search_exam_rows`
- `open_exam_filters`
- `open_exam_sorting`
- `expand_exam_history`
- `collapse_exam_history`
- `view_exam_attempt_history`
- `view_not_passed_exam_status`
- `open_specific_exam_attempt_details`
- `review_specific_exam_questions`
- `download_specific_exam_attempt_printout`

---

## 12. Pozostałe niewiadome

Do dalszego capture:
- `Edytuj dane` w drawerze generowania egzaminu,
- czy ad-hoc kandydat trafia do trwałej listy kursantów,
- pełna lista kategorii i języków egzaminu,
- `Filtruj`,
- `Sortuj`,
- exact search fields,
- status `Zaliczony` i statusy przed wykonaniem,
- dokładne znaczenie kolumny `Data`,
- czas trwania egzaminu,
- kolejność konsumpcji darmowej/opłaconej puli,
- reset darmowej puli,
- moment konsumpcji jednostki egzaminu,
- TTL/revocation zdalnego linku,
- stany sukcesu/błędu po wygenerowaniu linku i uruchomieniu lokalnym.
