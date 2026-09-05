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
- filtrowanie: `docs/69-internal-exam-filtering.md`,
- sortowanie: `docs/70-internal-exam-sorting.md`.

## 1. Znaczenie ekranu

`Zarządzaj egzaminami` jest centralnym panelem operacyjnym OSK do podglądu puli egzaminów, generowania egzaminu dla jednego kursanta, przeglądania historii prób, wyszukiwania, filtrowania, sortowania, przechodzenia do szczegółów oraz pobierania wydruku.

Dla naszego produktu egzamin jest funkcją istniejącego kursanta i formalnego `course_enrollment`.

## 2. Inventory egzaminów

Panel pokazuje:
- `Darmowe` — obserwowane 10,
- `Opłacone` — obserwowane 150,
- `Wszystkie` — obserwowane 160.

Własny model zachowuje źródło jednostki (`free_monthly`, `paid`), a `total_available` jest projekcją.

## 3. Lista `Przydzielone egzaminy wewnętrzne`

Potwierdzone kontrolki:
- `Generuj egzamin`,
- wybór dokładnie jednego wiersza kursanta,
- `ZAZNACZONE (1)`,
- `Generuj egzaminy (1)`,
- `Ukryj egzaminy zakończone`,
- wyszukiwarka,
- `Filtruj`,
- `Sortuj`.

Jeden wiersz jest agregatem kursanta i jego prób.

Potwierdzone kolumny:
- `Email lub login`,
- `Imię i nazwisko`,
- `Najnowszy egzamin`: `Kat.`, `Status`, `Data`, `Język`,
- `Liczba egzaminów`,
- akcje.

## 4. Wyszukiwanie

Użytkownik potwierdził działanie wyszukiwarki na głównej liście dla fragmentów danych kursanta.

Potwierdzone pola wyszukiwania:
- imię,
- nazwisko,
- email,
- login.

Wyszukiwanie po PESEL w tym konkretnym polu głównej listy nie zostało osobno potwierdzone.

## 5. Generowanie egzaminu

Są dwa wejścia do drawera `Generuj dostęp do egzaminu wewnętrznego`:
- zaznaczenie jednego kursanta i `Generuj egzaminy (1)`,
- duży `Generuj egzamin` bez wcześniejszego zaznaczenia.

Dla naszego produktu formalny egzamin wymaga trwałego `student_id` i `course_enrollment_id`.

## 6. Dane egzaminacyjne i edycja

Zaobserwowane pola:
- kategoria egzaminu,
- język egzaminu,
- imię,
- nazwisko,
- PESEL,
- PKK / nr ewidencyjny,
- email.

`Edytuj dane` otwiera edytowalny formularz tych pól z `Anuluj` i `Zapisz dane`.

## 7. Tryby uruchomienia

`Udostępnij link do egzaminu` — generuje link i wysyła go na email.

`Rozpocznij egzamin teraz` — uruchamia egzamin na bieżącym stanowisku, dla jednego kursanta jednocześnie.

## 8. Statusy i filtrowanie

Potwierdzone statusy filtra:
- `Brak przypisanego`,
- `Niezaliczony`,
- `Nie przeprowadzony`,
- `Zaliczony`.

`Niezaliczony` odpowiada czerwonemu `x` w obserwowanym wierszu głównym.

## 9. Sortowanie

Kierunki:
- `Rosnąco`,
- `Malejąco`.

Pola:
- Email lub login,
- Imię i nazwisko,
- Kat.,
- Status,
- Data,
- Język,
- Liczba egzaminów.

## 10. Historia prób

`Rozwiń` pokazuje każdą próbę osobno z kategorią, statusem, PKK/nr ewidencyjnym, datą, językiem, `Szczegóły` i `Pobierz wydruk`.

## 11. Szczegóły i review

`Szczegóły` prowadzi do tokenizowanego frontu `/egzamin-wewnetrzny?pid=<opaque_token>` z wynikiem, statystykami i review 32 pytań.

## 12. Wydruk

`Pobierz wydruk` generuje per-attempt PDF `Arkusz odpowiedzi` z pełnym snapshotem próby i miejscami na podpisy.

## 13. Model naszego produktu

Formalny attempt wymaga:
- `organization_id`,
- `student_id`,
- `course_enrollment_id`,
- wymaganej części egzaminu,
- snapshotu danych kursanta/kursu,
- dla teorii immutable snapshotu pytań, kolejności, odpowiedzi i punktacji.

## 14. Pozostałe niewiadome

Do dalszego capture:
- pełna lista kategorii i języków egzaminu,
- wizualizacja statusu `Zaliczony` w tabeli,
- dokładne znaczenie kolumny `Data`,
- zachowanie sortowania dla pustych wartości,
- czas trwania egzaminu,
- kolejność konsumpcji darmowej/opłaconej puli,
- reset darmowej puli,
- moment konsumpcji jednostki egzaminu,
- TTL/revocation/resend zdalnego linku,
- stany sukcesu/błędu po wysłaniu linku i uruchomieniu lokalnym.
