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
- generowania egzaminów dla pojedynczego kursanta,
- przeglądania historii prób kursantów,
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
- wybór wiersza kursanta checkboxem,
- stan `ZAZNACZONE (1)`,
- `Generuj egzaminy (1)` dla wybranego kursanta,
- `Ukryj egzaminy zakończone`,
- wyszukiwarka,
- `Filtruj`,
- `Sortuj`.

### `Generuj egzaminy (1)` — zachowanie potwierdzone

Po zaznaczeniu jednego kursanta i kliknięciu `Generuj egzaminy (1)` otwiera się drawer:
- `Generuj dostęp do egzaminu wewnętrznego`.

Użytkownik potwierdził, że generowanie jest **jednoosobowe** — wybiera się jednego kursanta na raz. Nie traktujemy tej funkcji jako batch generation wielu kursantów.

Pełny flow: `docs/65-internal-exam-generation-access-flow.md`.

Zielony przycisk `Generuj egzamin` bez wcześniejszego wyboru kursanta jest widoczny, ale exact pierwszy stan tego wariantu nadal wymaga osobnego capture.

---

## 4. Drawer generowania dostępu

Zaobserwowany stan dla Ali Nowak pokazuje:
- `Dostępne egzaminy: 160`,
- kategorię `B` z możliwością zmiany,
- język `Polski` z możliwością zmiany,
- imię,
- nazwisko,
- PKK,
- PESEL,
- email,
- akcje `Usuń` i `Edytuj dane`.

Widoczny jest komunikat `Uzupełnij dane kursanta`; exact trigger pozostaje do sprawdzenia.

Potwierdzone są dwa tryby uruchomienia:

### `Udostępnij link do egzaminu`
- generuje link egzaminacyjny,
- kursant może wykonać egzamin zdalnie z dostępem do Internetu,
- link zostaje wysłany na email kursanta.

### `Rozpocznij egzamin teraz`
- uruchamia egzamin na bieżącym stanowisku komputerowym,
- UI informuje: `Dostępne tylko dla 1 kursanta jednocześnie.`

Dla własnego produktu są to dwa tryby `internal_exam_launch`, nie dwa różne typy egzaminu.

---

## 5. Granularność wiersza głównego

Jeden wiersz reprezentuje kursanta / kontekst egzaminacyjny wraz z agregatem jego prób, a nie pojedynczy egzamin.

Potwierdzone kolumny:
- `Email lub login`,
- `Imię i nazwisko`,
- `Najnowszy egzamin`:
  - `Kat.`,
  - `Status`,
  - `Data`,
  - `Język`,
- `Liczba egzaminów`,
- akcje.

Zaobserwowano:
- Ala Nowak — 7 egzaminów,
- Paweł Kowalski — 2 egzaminy.

Dla Ali rozwinięcie rzeczywiście pokazuje 7 konkretnych rekordów, więc `Liczba egzaminów` odpowiada historii prób.

---

## 6. Status najnowszego egzaminu

W wierszu Ali status najnowszego egzaminu jest pokazany jako czerwony `x`.

Po `Rozwiń` wszystkie 7 widocznych prób Ali mają tekstowy status:
- `Niezaliczony`.

Dla obserwowanego wiersza potwierdzamy mapping:

`Niezaliczony -> czerwony x`

Wygląd statusów `Zaliczony`, oczekujący, rozpoczęty itd. pozostaje do dalszego capture.

---

## 7. `Rozwiń` — historia konkretnych prób

Rozwinięcie ma tytuł `Egzaminy` i kolumny:
- `Imię i nazwisko`,
- `Kategoria`,
- `Status`,
- `Nr ewidencyjny/PKK`,
- `Data`,
- `Język`,
- akcje.

Dla Ali widocznych jest 7 prób. Wszystkie mają status `Niezaliczony`.

Dwa rekordy mogą mieć tę samą wyświetlaną minutę, więc timestamp nie może być identyfikatorem próby.

Pełna specyfikacja: `docs/61-internal-exam-expanded-attempt-history.md` oraz `specs/screens/internal-exam-expanded-attempts.yml`.

---

## 8. `Szczegóły` i wynik próby

`Szczegóły` prowadzi do tokenizowanego frontu:

`/egzamin-wewnetrzny?pid=<opaque_token>`

Dla zakończonej próby pokazuje:
- wynik punktowy,
- rezultat,
- nawigację po 32 pytaniach,
- statystyki,
- review pytanie-po-pytaniu.

Review pytania pokazuje m.in. media, treść, wartość punktową, kategorię, odpowiedź poprawną i odpowiedź wybraną przez kursanta.

Szczegóły: `docs/63-internal-exam-result-details-screen.md` i `docs/64-internal-exam-question-review.md`.

---

## 9. `Pobierz wydruk`

`Pobierz wydruk` generuje per-attempt PDF `Arkusz odpowiedzi` z:
- danymi kandydata,
- datą i kategorią,
- 32 pozycjami egzaminu,
- identyfikatorami pytań,
- punktacją,
- odpowiedziami,
- punktami uzyskanymi,
- sumą i wynikiem,
- miejscami na podpisy.

Pełny opis: `docs/62-internal-exam-answer-sheet-pdf.md`.

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

Wiersz główny to projekcja najnowszej próby i liczby prób.

`internal_exam_attempt` jest źródłem prawdy dla konkretnego egzaminu, a `internal_exam_launch` opisuje sposób uruchomienia:
- `remote_link`,
- `local_station`.

Każdy zakończony attempt zachowuje immutable snapshot danych kandydata, pytań, kolejności, punktacji, odpowiedzi i wyniku.

---

## 11. Potwierdzone akcje

- `open_internal_exam_management_panel`
- `view_free_exam_inventory`
- `view_paid_exam_inventory`
- `view_total_exam_inventory`
- `open_exam_purchase`
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
- pierwszy stan zielonego `Generuj egzamin` bez zaznaczenia kursanta,
- `Edytuj dane` w drawerze generowania egzaminu,
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
