# 60. Egzamin wewnętrzny — panel „Zarządzaj egzaminami”

Data weryfikacji: 2026-09-05

**Route:** `/egzamin-wewnetrzny/panel`  
**Kontekst:** `Egzamin wewnętrzny -> Panel - Generuj egzamin`  
**Źródło:** bieżący zalogowany ekran + screenshot przekazany podczas audytu  
**Status:** `USER_CONFIRMED_AUTH_SCREEN`

---

## 1. Znaczenie ekranu

`Zarządzaj egzaminami` jest centralnym panelem operacyjnym OSK do:
- podglądu dostępnej puli egzaminów,
- rozróżnienia puli darmowej i opłaconej,
- generowania egzaminów dla kursantów,
- przeglądania egzaminów już przypisanych/utworzonych,
- filtrowania, sortowania i wyszukiwania,
- przechodzenia do szczegółów egzaminu,
- pobierania wydruku egzaminu.

---

## 2. Dostępne egzaminy wewnętrzne

Panel pokazuje trzy agregaty:

### Darmowe
- etykieta: `Darmowe`,
- opis: `Egzaminy darmowe w tym miesiącu`,
- obserwowany licznik: `10`.

Dodatkowy komunikat sugeruje możliwość indywidualnego zwiększenia darmowej miesięcznej puli po kontakcie z obsługą.

### Opłacone
- etykieta: `Opłacone`,
- obserwowany licznik: `150`.

### Wszystkie
- etykieta: `Wszystkie`,
- obserwowany licznik: `160`.

Obserwacja jest arytmetycznie spójna:

`10 darmowych + 150 opłaconych = 160 wszystkich`

### Wniosek domenowy

Pula egzaminów ma co najmniej dwa źródła:
- `free_monthly`,
- `paid`.

Nie powinniśmy modelować egzaminów wyłącznie jako jednego pola `available_exams` bez źródła/provenance.

Dla naszego produktu rekomendowany jest ledger inventory, gdzie każda jednostka lub partia ma źródło.

---

## 3. Dokup egzaminy

Potwierdzona akcja:
- `Dokup egzaminy` -> `/egzamin-wewnetrzny/wykup`.

Zakup puli jest osobnym flow opisanym w `docs/59-internal-exam-purchase-screen.md`.

---

## 4. Sekcja „Przydzielone egzaminy wewnętrzne”

Potwierdzone kontrolki:
- `Generuj egzamin`,
- `Zaznacz widoczne`,
- `Generuj egzaminy` (przycisk nad tabelą; w obserwowanym stanie nieaktywny bez wyboru),
- `Ukryj egzaminy zakończone`,
- wyszukiwarka,
- `Filtruj`,
- `Sortuj`.

### Ważna granica

Sama obecność checkboxów i przycisku `Generuj egzaminy` sugeruje operację na zaznaczonych rekordach, ale dokładna kardynalność i zachowanie batch flow nie są jeszcze potwierdzone.

Nie wpisujemy batch generation jako potwierdzonej funkcji dopóki nie zobaczymy formularza po zaznaczeniu kilku rekordów.

---

## 5. Granularność wiersza

Wiersz główny reprezentuje kursanta / dostęp egzaminacyjny z agregatem jego egzaminów, a nie pojedynczą próbę.

Wskazuje na to kolumna:
- `Liczba egzaminów`

oraz akcja:
- `Rozwiń`.

Zaobserwowano:
- Ala Nowak -> `7`,
- Paweł Kowalski -> `2`.

To bardzo mocno wspiera model:

`student/internal_exam_access -> many internal_exam_attempts`

---

## 6. Kolumny tabeli

Potwierdzone kolumny:
- `Email lub login`,
- `Imię i nazwisko`,
- grupa `Najnowszy egzamin`:
  - `Kat.`,
  - `Status`,
  - `Data`,
  - `Język`,
- `Liczba egzaminów`,
- akcje.

Zaobserwowane przykłady:

### Ala Nowak
- email/login: `alanowak@prawo-jazdy-360.pl`,
- kategoria: `B`,
- status: czerwony symbol `x` bez tekstowej etykiety,
- data: `26-01-2026 15:43`,
- język: `PL`,
- liczba egzaminów: `7`.

### Paweł Kowalski
- email/login: `pawelkowalski@prawo-jazdy-360.pl`,
- kategoria: `B`,
- status: czerwony symbol `x` bez tekstowej etykiety,
- data: `23-01-2026 12:56`,
- język: `PL`,
- liczba egzaminów: `2`.

### Status `x`

Nie interpretujemy jeszcze czerwonego `x` jako `niezdany`, `nieukończony` albo inny konkretny stan. Na samym ekranie brak legendy/tekstu.

Status pozostaje `TO_VERIFY` do czasu rozwinięcia lub szczegółów egzaminu.

---

## 7. Akcje wiersza

Potwierdzone:
- `Rozwiń` / `Zwiń`,
- `Szczegóły`,
- `Pobierz wydruk`.

### Szczegóły

Link prowadzi do publicznego/frontowego modułu egzaminu wewnętrznego z parametrem `pid`.

Nie przesądzamy jeszcze, czy otwierany widok jest:
- szczegółami wykonanego egzaminu,
- ekranem uruchomienia,
- wynikiem,
- linkiem egzaminacyjnym.

Wymaga osobnego capture.

### Pobierz wydruk

Potwierdzony endpoint wzorca:
- `/exam/download?id=<exam_id>`.

Format i zawartość dokumentu pozostają do zbadania poprzez pobrany plik.

---

## 8. Filtr „Ukryj egzaminy zakończone”

Potwierdzony toggle:
- `Ukryj egzaminy zakończone`.

To potwierdza istnienie lifecycle z co najmniej stanem/projekcją `finished/completed`.

Nie potwierdzono:
- jakie dokładnie statusy są uznawane za zakończone,
- czy `zdany` i `niezdany` są osobnymi zakończonymi stanami,
- jak zachowuje się mixed history jednego kursanta.

---

## 9. Model inventory dla naszego produktu

Rekomendowane źródła puli:
- `free_monthly`,
- `paid`,
- opcjonalnie `manual_adjustment/promo` w przyszłości.

Rekomendowane pola ledgeru:
- `organization_id`,
- `source`,
- `quantity_delta`,
- `effective_at`,
- `expires_at nullable` dla puli darmowej, jeśli reset miesięczny jest realizowany przez wygasanie,
- `order_id nullable`,
- `reason`,
- `created_by`.

### Projekcje

- `free_available`,
- `paid_available`,
- `total_available`.

Nie hardkodujemy miesięcznego resetu implementacyjnie bez osobnej polityki; może to być quota projection zamiast fizycznych rekordów jednostkowych.

---

## 10. Model egzaminu dla naszego produktu

Rekomendowane encje:
- `internal_exam_accesses` / kontekst kursanta,
- `internal_exam_attempts`,
- `internal_exam_inventory_ledger`,
- `internal_exam_launch_tokens`,
- `internal_exam_results`,
- `internal_exam_documents`.

Jeden kursant może mieć wiele prób egzaminu.

Wiersz panelu może przechowywać/materializować projekcję:
- `latest_attempt_id`,
- `latest_attempt_category`,
- `latest_attempt_status`,
- `latest_attempt_at`,
- `latest_attempt_language`,
- `attempt_count`.

---

## 11. Potwierdzone akcje

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
- `open_exam_details`
- `download_exam_printout`

---

## 12. Pozostałe niewiadome

Do dalszego capture:
- `Rozwiń` przy kursancie — historia prób,
- znaczenie czerwonego `x`,
- dokładny formularz `Generuj egzamin` z poziomu panelu,
- zachowanie `Generuj egzaminy` po zaznaczeniu rekordów,
- filtry,
- sortowanie,
- exact search fields,
- format `Pobierz wydruk`,
- ekran `Szczegóły`,
- kolejność zużywania puli darmowej vs opłaconej,
- reset/odnowienie darmowej puli,
- moment konsumpcji jednostki egzaminu,
- pagination,
- empty states,
- success/error messages.
