# 70. Egzamin wewnętrzny — sortowanie listy

Data weryfikacji: 2026-09-05

**Kontekst:** `/egzamin-wewnetrzny/panel` -> `Sortuj`  
**Źródło:** bieżący zalogowany drawer przekazany podczas audytu  
**Status:** `USER_CONFIRMED_AUTH_SCREEN`

## 1. Drawer `Sortowanie`

Drawer pozwala wybrać:

### Kierunek sortowania
- `Rosnąco`,
- `Malejąco`.

### Kolumna
Jedna kolumna wybierana radiobuttonem:
- `Email lub login`,
- `Imię i nazwisko`,
- `Kat.`,
- `Status`,
- `Data`,
- `Język`,
- `Liczba egzaminów`.

Akcja końcowa:
- `Sortuj`.

## 2. Zaobserwowany stan

Na przekazanym ekranie zaznaczone były:
- kierunek `Rosnąco`,
- kolumna `Data`.

Nie traktujemy tego jako pewnego globalnego ustawienia domyślnego. To wyłącznie stan obserwowany w tej sesji.

## 3. Semantyka sortowanych pól

Sortowanie dotyczy głównej projekcji wiersza kursanta / kontekstu egzaminacyjnego, nie rozwiniętej historii pojedynczych prób.

Pola odpowiadają kolumnom widocznym w tabeli:
- identyfikator kontaktowy/logowania,
- imię i nazwisko,
- kategoria najnowszego egzaminu,
- status najnowszego egzaminu,
- data najnowszego egzaminu,
- język najnowszego egzaminu,
- liczba wszystkich egzaminów przypisanych do wiersza.

Dokładne techniczne znaczenie `Data` pozostaje takie samo jak w tabeli głównej i nadal nie zostało rozstrzygnięte ponad to, że jest prezentowana w sekcji `Najnowszy egzamin`.

## 4. Model naszego produktu

Dla własnego API rekomendowane pola sortowania:
- `identity_or_login`,
- `student_full_name`,
- `latest_exam_category`,
- `latest_exam_status`,
- `latest_exam_at`,
- `latest_exam_language`,
- `exam_count`.

Kierunek:
- `asc`,
- `desc`.

Backend powinien stosować stabilne sortowanie. Przy równych wartościach należy użyć stabilnego drugiego klucza, np. `student_id` lub innego niezmiennego identyfikatora projekcji, żeby kolejność nie zmieniała się losowo między stronami.

## 5. Potwierdzone akcje

- `open_internal_exam_sort_drawer`
- `sort_internal_exam_rows_ascending`
- `sort_internal_exam_rows_descending`
- `sort_by_email_or_login`
- `sort_by_student_name`
- `sort_by_latest_exam_category`
- `sort_by_latest_exam_status`
- `sort_by_latest_exam_date`
- `sort_by_latest_exam_language`
- `sort_by_exam_count`
- `apply_internal_exam_sort`

## 6. Pozostałe niewiadome

- czy `Rosnąco + Data` jest rzeczywistym domyślnym stanem po wejściu na ekran,
- zachowanie sortowania wobec pustych wartości (`Brak przypisanego`),
- dokładne techniczne pole czasu odpowiadające kolumnie `Data`,
- utrwalanie sortowania w URL / sesji,
- interakcja sortowania z paginacją.