# 32. Kursanci — zakładka Egzamin wewnętrzny

Data weryfikacji: 2026-09-05

**Kontekst:** zakładka `Egzamin wewnętrzny` na `/kursanci/<student_id>`  
**Źródło:** bieżący zalogowany ekran + screenshot przekazany podczas audytu  
**Status:** `USER_CONFIRMED_AUTH_SCREEN`

Dane osobowe i identyfikatory kursanta z konta demonstracyjnego nie są przepisywane jako dane referencyjne produktu.

---

## 1. Struktura ekranu

Zakładka `Egzamin wewnętrzny` jest jedną z trzech głównych zakładek karty kursanta:
- Profil kursanta,
- Egzamin wewnętrzny,
- Postępy.

Na obserwowanym ekranie aktywna jest zakładka `Egzamin wewnętrzny`.

Potwierdzony nagłówek treści:
- `Egzaminy wewnętrzne`.

---

## 2. Ostatni egzamin wewnętrzny

Ekran posiada osobny blok:
- `Ostatni egzamin wewnętrzny`.

Zaobserwowany empty state:
- `Brak przeprowadzenego egzaminu`.

Potwierdzona akcja:
- `Rozpocznij egzamin`.

Nie znamy jeszcze formularza/flow po kliknięciu `Rozpocznij egzamin`.

---

## 3. Statystyki wszystkich egzaminów

Ekran posiada blok `Wszystkie egzaminy` z podsumowaniem:
- `Zdawalność` w procentach,
- liczba `Zdane egzaminy`,
- liczba `Niezdane egzaminy`.

Zaobserwany pusty stan danych:
- zdawalność 0%,
- 0 zdanych,
- 0 niezdanych.

To potwierdza, że egzamin wewnętrzny jest historią wielu prób, a nie pojedynczym booleanem na kursancie.

Rekomendowany model:
- `internal_exam_attempts`,
- każda próba ma własny wynik/status,
- agregat zdawalności jest wyliczany z historii prób.

---

## 4. Historia wszystkich egzaminów

Potwierdzona tabela `Wszystkie egzaminy`.

Potwierdzone kolumny:
- `Data i godzina`,
- `Kat.`,
- `Status`.

Na obserwowanym ekranie brak rekordów.

Nie znamy jeszcze:
- dodatkowych kolumn przy istniejących próbach,
- akcji wiersza,
- szczegółów wyniku,
- liczby punktów,
- przyczyn niezaliczenia,
- możliwości pobrania/drukowania karty egzaminu z tego widoku.

---

## 5. Potwierdzony lifecycle na poziomie UI

Na podstawie obecnego ekranu potwierdzamy co najmniej stan:
- brak przeprowadzonego egzaminu.

Akcja `Rozpocznij egzamin` rozpoczyna osobny flow przeprowadzenia egzaminu.

Nie przypisujemy jeszcze konkurentowi dokładnych technicznych stanów `generated/started/finished/consumed` z innych dokumentów do tego konkretnego ekranu bez dalszej obserwacji.

---

## 6. Wniosek dla naszego produktu

Karta kursanta powinna mieć osobny agregat egzaminów wewnętrznych:
- ostatnia próba,
- pełna historia prób,
- wynik zbiorczy/zdawalność,
- liczba prób zdanych i niezdanych,
- możliwość rozpoczęcia nowej próby.

Egzamin wewnętrzny nie może być jednym polem `student.internal_exam_passed`.

Minimalne encje:
- `internal_exam_attempts`,
- `student_id`,
- `category_id`,
- `started_at`,
- `finished_at`,
- `status`,
- `result` / pass-fail,
- opcjonalne szczegóły punktowe zgodnie z naszym silnikiem egzaminacyjnym,
- audit trail.

---

## 7. Potwierdzone akcje

- `open_student_internal_exam_tab`
- `start_student_internal_exam`
- `view_internal_exam_pass_rate`
- `view_passed_internal_exam_count`
- `view_failed_internal_exam_count`
- `view_internal_exam_history`

---

## 8. Czego nadal nie znamy

Najważniejsze braki:
- flow po `Rozpocznij egzamin`,
- wybór kategorii przed startem,
- wybór języka,
- link vs egzamin lokalny,
- czy zużywany jest egzamin z puli OSK,
- dokładne statusy próby,
- wygląd wpisu historii po zdanym egzaminie,
- wygląd wpisu historii po niezdanym egzaminie,
- szczegóły wyniku,
- karta egzaminu / druk / PDF,
- ponawianie egzaminu,
- zachowanie przerwanej próby,
- komunikaty błędów i success.

---

## 9. Acceptance criteria

### AC-STU-EXAM-01 — empty state
Gdy kursant nie ma żadnej próby, ekran pokazuje jednoznaczny empty state ostatniego egzaminu.

### AC-STU-EXAM-02 — start
Administrator ma akcję rozpoczęcia nowego egzaminu wewnętrznego dla kursanta.

### AC-STU-EXAM-03 — aggregate statistics
Ekran pokazuje zdawalność oraz liczby zdanych i niezdanych egzaminów.

### AC-STU-EXAM-04 — history
Historia wszystkich prób ma co najmniej datę i godzinę, kategorię i status.

### AC-STU-EXAM-05 — multi-attempt model
System wspiera wiele prób jednego kursanta i wylicza agregaty z historii prób.

### AC-STU-EXAM-06 — tenant isolation
Nie można odczytać ani rozpocząć egzaminu dla kursanta należącego do innego OSK.
