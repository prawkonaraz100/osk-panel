# 63. Egzamin wewnętrzny — ekran „Szczegóły” zakończonej próby

Data weryfikacji: 2026-09-05

**Wejście:** `/egzamin-wewnetrzny/panel` -> konkretna próba -> `Szczegóły`  
**Frontend:** `https://www.prawo-jazdy-360.pl/egzamin-wewnetrzny?pid=<opaque_token>`  
**Źródło:** bieżący screenshot strony wynikowej przekazany podczas audytu  
**Status:** `USER_CONFIRMED_AUTH/RESULT_SCREEN`

---

## 1. Znaczenie ekranu

`Szczegóły` dla zakończonej próby otwiera ekran wyniku i przeglądu odpowiedzi konkretnego egzaminu.

Nie jest to klasyczny widok administracyjny w BIZ ani formularz uruchamiania egzaminu.

Ekran pełni funkcje:
- prezentacji wyniku punktowego,
- prezentacji rezultatu próby,
- nawigacji po 32 pytaniach,
- wejścia do przeglądu odpowiedzi pytanie-po-pytaniu,
- prezentacji statystyk poprawnych/niepoprawnych odpowiedzi.

---

## 2. Wynik główny

Zaobserwowany ekran pokazuje:
- nagłówek `Twój wynik`,
- wynik `0 z 74 pkt.`,
- komunikat negatywny: `Niestety nie udało Ci się, spróbuj ponownie rozwiązać test.`

To potwierdza maksymalną punktację prezentowaną przez ten egzamin:
- `74 pkt.`

W obserwowanym przypadku:
- `score = 0`,
- `max_score = 74`,
- rezultat = negatywny / niezaliczony.

Nie potwierdzono jeszcze progu zaliczenia z tego ekranu; nie hardkodujemy go na podstawie samego screena.

---

## 3. Nawigacja po pytaniach

Ekran zawiera komunikat:
- `Wybierz numer pytania, aby sprawdzić odpowiedzi:`

Numery są podzielone na:
- `Pytania podstawowe`: 1–20,
- `Pytania specjalistyczne`: 21–32.

W obserwowanym negatywnym przypadku wszystkie przyciski 1–32 są oznaczone kolorem błędu.

Potwierdzone:
- 20 pytań podstawowych,
- 12 pytań specjalistycznych,
- 32 pytania łącznie,
- każdy numer jest oddzielnym elementem nawigacyjnym.

### Ważna granica

Na podstawie tego screena nie potwierdzono jeszcze zawartości widoku po kliknięciu konkretnego numeru pytania.

Nie wiemy jeszcze, czy pokazuje:
- pełną treść pytania,
- media,
- odpowiedź kursanta,
- poprawną odpowiedź,
- punktację,
- wyjaśnienie.

To wymaga kolejnego capture po kliknięciu numeru pytania.

---

## 4. Statystyki

Sekcja `Twoje statystyki` ma trzy karty:

### Pytania podstawowe
- Poprawne: `0`,
- Niepoprawne: `20`.

### Pytania specjalistyczne
- Poprawne: `0`,
- Niepoprawne: `12`.

### Wszystkie
- Poprawne: `0`,
- Niepoprawne: `32`.

Każda karta zawiera wykres pierścieniowy/donut będący wizualną projekcją proporcji poprawnych i niepoprawnych odpowiedzi.

W tym przypadku statystyki są arytmetycznie spójne:
- `20 + 12 = 32`,
- `0 poprawnych + 32 niepoprawne = 32`.

---

## 5. Relacja do PDF „Arkusz odpowiedzi”

Ekran wynikowy i PDF reprezentują ten sam historyczny attempt, ale mają różne przeznaczenie:

### Ekran `Szczegóły`
- czytelny UI,
- wynik punktowy,
- nawigacja po pytaniach,
- statystyki,
- możliwość interaktywnego przeglądu odpowiedzi.

### PDF `Arkusz odpowiedzi`
- dokument do druku/archiwizacji,
- identyfikatory pytań,
- odpowiedzi i punktacja per pozycja,
- suma punktów i wynik,
- miejsca na podpisy.

Oba widoki muszą korzystać z tego samego niezmiennego snapshotu próby.

---

## 6. Model naszego produktu

Dla zakończonego `internal_exam_attempt` frontend wyniku powinien pobierać immutable result projection:
- `score`,
- `max_score`,
- `result`,
- `basic_correct_count`,
- `basic_incorrect_count`,
- `specialized_correct_count`,
- `specialized_incorrect_count`,
- `total_correct_count`,
- `total_incorrect_count`,
- uporządkowaną listę `attempt_questions`.

Nie przeliczamy starego wyniku ponownie na podstawie aktualnej bazy pytań.

---

## 7. Tokenized frontend

Ekran jest dostępny przez:

`/egzamin-wewnetrzny?pid=<opaque_token>`

Dla naszego produktu:
- `attempt.id` pozostaje wewnętrznym ID,
- zewnętrzny token jest osobnym zasobem,
- token nie zawiera danych osobowych,
- token jest losowy/nieprzewidywalny,
- można go unieważnić,
- dostęp jest audytowany.

Dla historycznego wyniku możemy mieć osobną politykę tokenu niż dla linku uruchamiającego jeszcze niewykonany egzamin.

---

## 8. Potwierdzone akcje

- `open_completed_exam_result_details`
- `view_exam_score`
- `view_exam_max_score`
- `view_failed_exam_message`
- `view_basic_question_navigation`
- `view_specialized_question_navigation`
- `select_question_number_for_answer_review`
- `view_basic_answer_statistics`
- `view_specialized_answer_statistics`
- `view_total_answer_statistics`

---

## 9. Pozostałe niewiadome

- ekran po kliknięciu konkretnego numeru pytania,
- sposób pokazania poprawnej vs udzielonej odpowiedzi,
- media pytania w review,
- czy pokazuje wyjaśnienie,
- wygląd wyniku pozytywnego,
- próg zaliczenia prezentowany w UI,
- możliwość ponownego uruchomienia egzaminu bezpośrednio z tego ekranu,
- lifecycle/TTL tokenu dla historycznego wyniku.
