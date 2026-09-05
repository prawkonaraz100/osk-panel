# 63. Egzamin wewnętrzny — ekran „Szczegóły” zakończonej próby

Data weryfikacji: 2026-09-05

**Wejście:** `/egzamin-wewnetrzny/panel` -> konkretna próba -> `Szczegóły`  
**Frontend:** `https://www.prawo-jazdy-360.pl/egzamin-wewnetrzny?pid=<opaque_token>`  
**Źródło:** bieżące screenshoty strony wynikowej przekazane podczas audytu  
**Status:** `USER_CONFIRMED_AUTH/RESULT_SCREEN`

Powiązany review konkretnego pytania:
- `docs/64-internal-exam-question-review.md`
- `specs/screens/internal-exam-question-review.yml`

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

Zaobserwowano m.in. dwa historyczne stany prób:
- `0 z 74 pkt.` — wynik negatywny,
- `9 z 74 pkt.` — wynik negatywny.

Potwierdzona maksymalna punktacja prezentowana przez ten egzamin:
- `74 pkt.`

Komunikat dla wyniku negatywnego:
- `Niestety nie udało Ci się, spróbuj ponownie rozwiązać test.`

Nie potwierdzono jeszcze progu zaliczenia z samego ekranu; nie hardkodujemy go na podstawie screena.

---

## 3. Nawigacja po pytaniach

Ekran zawiera komunikat:
- `Wybierz numer pytania, aby sprawdzić odpowiedzi:`

Numery są podzielone na:
- `Pytania podstawowe`: 1–20,
- `Pytania specjalistyczne`: 21–32.

Potwierdzone:
- 20 pytań podstawowych,
- 12 pytań specjalistycznych,
- 32 pytania łącznie,
- każdy numer jest oddzielnym elementem nawigacyjnym.

### Semantyka stanów numerów pytań

Na ekranie wyniku `9/74` zaobserwowano:
- pytanie 1 jako aktualnie otwarte — ciemny stan zaznaczenia,
- pytania 2–4 — zielone,
- pytania 5–32 — czerwone.

Po otwarciu pytania 1 potwierdzono, że było ono rozwiązane niepoprawnie.

Wniosek:
- zielony = poprawna odpowiedź,
- czerwony = niepoprawna odpowiedź,
- ciemny = aktualnie otwarte pytanie.

Pełne mapowanie review pojedynczego pytania: `docs/64-internal-exam-question-review.md`.

---

## 4. Statystyki

Sekcja `Twoje statystyki` ma trzy karty:

### Pytania podstawowe
- liczba poprawnych,
- liczba niepoprawnych,
- wykres pierścieniowy.

### Pytania specjalistyczne
- liczba poprawnych,
- liczba niepoprawnych,
- wykres pierścieniowy.

### Wszystkie
- liczba poprawnych,
- liczba niepoprawnych,
- wykres pierścieniowy.

Dla obserwowanego przypadku `0/74`:
- podstawowe: 0 poprawnych / 20 niepoprawnych,
- specjalistyczne: 0 poprawnych / 12 niepoprawnych,
- wszystkie: 0 poprawnych / 32 niepoprawne.

---

## 5. Review konkretnego pytania — potwierdzone

Kliknięcie numeru pytania otwiera pełną historyczną pozycję egzaminu.

Potwierdzone elementy:
- media pytania; obserwowany przypadek zawiera wideo,
- pełna treść pytania,
- wartość punktowa pytania,
- kategoria,
- warianty odpowiedzi,
- poprawna odpowiedź,
- odpowiedź wybrana przez kursanta,
- komunikat poprawności.

W obserwowanym pytaniu:
- wartość: 3 pkt,
- kategoria: B,
- typ: TAK/NIE,
- poprawna odpowiedź: `Tak`,
- kursant wybrał: `Nie`,
- wybrana odpowiedź ma etykietę `WYBRANO`,
- wynik: `Niepoprawna odpowiedź`.

Poprawna odpowiedź jest oznaczona na zielono, błędnie wybrana odpowiedź na czerwono.

Pełna specyfikacja: `specs/screens/internal-exam-question-review.yml`.

---

## 6. Relacja do PDF „Arkusz odpowiedzi”

Ekran wynikowy i PDF reprezentują ten sam historyczny attempt, ale mają różne przeznaczenie:

### Ekran `Szczegóły`
- czytelny UI,
- wynik punktowy,
- nawigacja po pytaniach,
- statystyki,
- interaktywny przegląd odpowiedzi i treści pytania.

### PDF `Arkusz odpowiedzi`
- dokument do druku/archiwizacji,
- identyfikatory pytań,
- odpowiedzi i punktacja per pozycja,
- suma punktów i wynik,
- miejsca na podpisy.

Oba widoki muszą korzystać z tego samego niezmiennego snapshotu próby.

---

## 7. Model naszego produktu

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

Każdy `attempt_question` musi zachować dane potrzebne do późniejszego review: treść/wersję pytania, media lub versioned reference, warianty odpowiedzi, odpowiedź poprawną, odpowiedź kursanta i scoring.

Nie przeliczamy starego wyniku ponownie na podstawie aktualnej bazy pytań.

---

## 8. Tokenized frontend

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

## 9. Potwierdzone akcje

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
- `render_exam_question_media`
- `render_exam_question_text`
- `render_correct_answer`
- `render_candidate_selected_answer`
- `render_incorrect_answer_state`

---

## 10. Pozostałe niewiadome

- review pytania odpowiedzianego poprawnie,
- review pytania bez odpowiedzi,
- wariant A/B/C,
- obraz zamiast wideo,
- czy istnieje wyjaśnienie odpowiedzi niżej poza aktualnym viewportem,
- wygląd wyniku pozytywnego,
- próg zaliczenia prezentowany w UI,
- lifecycle/TTL tokenu dla historycznego wyniku.
