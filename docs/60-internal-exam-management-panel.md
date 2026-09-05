# 60. Egzamin wewnętrzny — panel „Zarządzaj egzaminami”

Data weryfikacji: 2026-09-05

**Route:** `/egzamin-wewnetrzny/panel`  
**Kontekst:** `Egzamin wewnętrzny -> Panel - Generuj egzamin`  
**Źródło:** bieżące zalogowane ekrany + screenshoty przekazane podczas audytu  
**Status:** `USER_CONFIRMED_AUTH_SCREEN`

Powiązane:
- zakup egzaminów: `docs/59-internal-exam-purchase-screen.md`,
- rozwinięta historia prób: `docs/61-internal-exam-expanded-attempt-history.md`.

---

## 1. Znaczenie ekranu

`Zarządzaj egzaminami` jest centralnym panelem operacyjnym OSK do:
- podglądu puli egzaminów,
- rozróżnienia puli darmowej i opłaconej,
- generowania egzaminów,
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
- `Generuj egzamin`,
- `Zaznacz widoczne`,
- `Generuj egzaminy` — widoczny przycisk nad tabelą, exact selected-row behavior nadal do sprawdzenia,
- `Ukryj egzaminy zakończone`,
- wyszukiwarka,
- `Filtruj`,
- `Sortuj`.

Nie zakładamy batch generation bez obejrzenia właściwego flow.

---

## 4. Granularność wiersza głównego

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

## 5. Status najnowszego egzaminu

W wierszu Ali status najnowszego egzaminu jest pokazany jako czerwony `x`.

Po `Rozwiń` wszystkie 7 widocznych prób Ali mają tekstowy status:
- `Niezaliczony`.

Najświeższe rekordy mają tę samą wyświetlaną datę `26-01-2026 15:43`, którą pokazuje parent row.

Dla obserwowanego wiersza potwierdzamy więc mapping:

`Niezaliczony -> czerwony x`

Wygląd statusów `Zaliczony`, oczekujący, rozpoczęty itd. pozostaje do dalszego capture.

---

## 6. `Rozwiń` — historia konkretnych prób

Rozwinięcie ma tytuł `Egzaminy` i kolumny:
- `Imię i nazwisko`,
- `Kategoria`,
- `Status`,
- `Nr ewidencyjny/PKK`,
- `Data`,
- `Język`,
- akcje.

Dla Ali widocznych jest 7 prób. Wszystkie mają:
- `Ala Nowak`,
- kat. `B`,
- `Niezaliczony`,
- `Nr ewidencyjny/PKK = 445645645646465`,
- `PL`.

Daty/godziny:
- 26-01-2026 15:43,
- 26-01-2026 15:43,
- 26-01-2026 14:07,
- 26-01-2026 14:02,
- 26-01-2026 13:15,
- 26-01-2026 13:15,
- 21-01-2026 12:44.

Dwa rekordy mogą mieć tę samą minutę, więc timestamp wyświetlany w UI nie może być identyfikatorem próby.

Pełna specyfikacja: `docs/61-internal-exam-expanded-attempt-history.md` oraz `specs/screens/internal-exam-expanded-attempts.yml`.

---

## 7. Akcje

Wiersz główny:
- `Rozwiń` / `Zwiń`,
- `Szczegóły`,
- `Pobierz wydruk`.

Każdy rekord po rozwinięciu również ma osobne:
- `Szczegóły`,
- `Pobierz wydruk`.

`Szczegóły` prowadzi do `/egzamin-wewnetrzny?pid=<token>` na froncie egzaminowym.

`Pobierz wydruk` używa `/exam/download?id=<exam_id>`.

To potwierdza, że szczegóły i dokument są per konkretna próba.

---

## 8. Historyczny snapshot danych egzaminu

Ponieważ przy każdej próbie UI osobno pokazuje:
- imię i nazwisko,
- kategorię,
- nr ewidencyjny/PKK,
- język,

dla naszego produktu egzamin powinien zachowywać historyczny snapshot tych danych. Późniejsza zmiana profilu kursanta nie może przepisać historii wykonanego egzaminu.

---

## 9. Model naszego produktu

Encje:
- `internal_exam_inventory_ledger`,
- `internal_exam_accesses`,
- `internal_exam_attempts`,
- `internal_exam_launch_tokens`,
- `internal_exam_results`,
- `internal_exam_documents`.

Wiersz główny to projekcja:
- `latest_attempt_id`,
- `latest_attempt_category`,
- `latest_attempt_status`,
- `latest_attempt_at`,
- `latest_attempt_language`,
- `attempt_count`.

Źródłem prawdy jest `internal_exam_attempt` z własnym stabilnym ID.

---

## 10. Potwierdzone akcje

- `open_internal_exam_management_panel`
- `view_free_exam_inventory`
- `view_paid_exam_inventory`
- `view_total_exam_inventory`
- `open_exam_purchase`
- `open_generate_exam_flow`
- `select_visible_exam_rows`
- `toggle_hide_finished_exams`
- `search_exam_rows`
- `open_exam_filters`
- `open_exam_sorting`
- `expand_exam_history`
- `collapse_exam_history`
- `view_exam_attempt_history`
- `view_not_passed_exam_status`
- `open_specific_exam_attempt_details`
- `download_specific_exam_attempt_printout`

---

## 11. Pozostałe niewiadome

Do dalszego capture:
- formularz `Generuj egzamin`,
- zachowanie `Generuj egzaminy` dla zaznaczonych wierszy,
- `Filtruj`,
- `Sortuj`,
- exact search fields,
- `Szczegóły` konkretnej próby,
- format `Pobierz wydruk`,
- status `Zaliczony` i statusy przed wykonaniem,
- dokładne znaczenie kolumny `Data` (wygenerowanie/start/zakończenie),
- scoring i czas trwania,
- kolejność konsumpcji darmowej/opłaconej puli,
- reset darmowej puli,
- moment konsumpcji jednostki egzaminu.
