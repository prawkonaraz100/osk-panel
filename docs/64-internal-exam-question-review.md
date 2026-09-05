# 64. Egzamin wewnętrzny — review pojedynczego pytania po zakończeniu próby

Data weryfikacji: 2026-09-05

**Wejście:** zakończona próba -> `Szczegóły` -> kliknięcie numeru pytania  
**Frontend:** tokenizowany ekran `/egzamin-wewnetrzny?pid=<opaque_token>`  
**Źródło:** bieżący screenshot przekazany podczas audytu  
**Status:** `USER_CONFIRMED_RESULT_REVIEW_SCREEN`

---

## 1. Znaczenie

Po kliknięciu numeru pytania na ekranie wyniku system pokazuje pełny review konkretnej pozycji egzaminacyjnej.

Nie jest to skrócona tabela odpowiedzi. UI renderuje właściwe pytanie wraz z mediami i wariantami odpowiedzi.

Potwierdza to, że historyczny `internal_exam_attempt_question` musi przechowywać lub jednoznacznie wskazywać snapshot treści potrzebnej do późniejszego odtworzenia review.

---

## 2. Potwierdzone elementy pytania

Zaobserwowany review pokazuje:
- numer wybranego pytania,
- media pytania — w obserwowanym przypadku wideo z przyciskiem play,
- treść pytania,
- `Wartość pkt. pytania` — obserwowane `3`,
- `Kategoria` — obserwowane `B`,
- warianty odpowiedzi,
- oznaczenie odpowiedzi poprawnej,
- oznaczenie odpowiedzi wybranej przez kursanta,
- komunikat o poprawności odpowiedzi.

Zaobserwowane pytanie:
- typ odpowiedzi: `TAK / NIE`,
- poprawna odpowiedź: `Tak`,
- odpowiedź wybrana: `Nie`,
- wynik pytania: `Niepoprawna odpowiedź`.

---

## 3. Prezentacja odpowiedzi

Potwierdzony stan dla błędnej odpowiedzi:
- poprawna odpowiedź ma zielone tło i ikonę potwierdzenia,
- błędnie wybrana odpowiedź ma czerwone tło,
- przy wybranej odpowiedzi występuje etykieta `WYBRANO`,
- pod odpowiedziami wyświetlany jest komunikat `Niepoprawna odpowiedź`.

To oznacza, że UI rozróżnia co najmniej:
- `correct_answer`,
- `selected_answer`,
- `answer_is_correct`.

Nie potwierdzono jeszcze osobnego widoku dla poprawnie udzielonej odpowiedzi, ale nawigacja wskazuje, które pytania zostały rozwiązane poprawnie.

---

## 4. Nawigacja po pytaniach — semantyka kolorów

Na ekranie z wynikiem `9/74` zaobserwowano:
- pytanie `1` — ciemne/czarne, ponieważ jest aktualnie otwarte,
- pytania `2, 3, 4` — zielone,
- pytania `5–32` — czerwone.

Review pytania `1` pokazuje, że jest ono niepoprawne.

Wniosek:
- `green` = odpowiedź poprawna,
- `red` = odpowiedź niepoprawna,
- `dark/current` = aktualnie wybrane pytanie, niezależnie od jego statusu.

W obserwowanym przypadku trzy zielone pytania odpowiadają łącznie wynikowi `9 pkt`, co jest spójne z punktacją po `3 pkt` dla tych pozycji. Nie używamy jednak samego koloru do rekonstrukcji historycznej punktacji — źródłem prawdy są zapisane punkty per attempt-question.

---

## 5. Media

Pytanie może zawierać media.

W obserwowanym przypadku:
- widoczny jest kadr wideo,
- na środku znajduje się przycisk odtwarzania.

Dla naszego produktu historyczne review musi umieć renderować co najmniej:
- pytanie bez mediów,
- obraz,
- wideo.

Nie wolno zakładać, że aktualny plik w bibliotece będzie zawsze identyczny z materiałem użytym podczas historycznej próby. W zależności od polityki wersjonowania potrzebny jest snapshot wersji media assetu lub stabilne versioned reference.

---

## 6. Relacja do arkusza PDF

PDF zapisuje techniczny/historical answer sheet:
- identyfikator pytania,
- punktację,
- odpowiedź,
- uzyskane punkty.

Interaktywny review pokazuje tę samą próbę w formie przyjaznej użytkownikowi:
- treść,
- media,
- warianty odpowiedzi,
- poprawną odpowiedź,
- wybór kursanta,
- status poprawności.

Oba widoki muszą korzystać z tego samego immutable attempt snapshot.

---

## 7. Model naszego produktu

`internal_exam_attempt_questions` powinien przechowywać co najmniej:
- `id`,
- `attempt_id`,
- `position`,
- `section` (`basic` / `specialized`),
- `question_id_snapshot` / external identifier,
- `question_version_id` lub immutable content snapshot,
- `question_text_snapshot`,
- `media_snapshot/version_reference nullable`,
- `answer_type_snapshot`,
- `answer_options_snapshot`,
- `correct_answer_snapshot`,
- `selected_answer nullable`,
- `max_points`,
- `awarded_points`,
- `is_correct`,
- `category_snapshot`.

Dzięki temu review nie zależy od późniejszej edycji pytania w bazie.

---

## 8. Własny UX

Dla naszego produktu zachowujemy funkcjonalnie:
- pasek wyniku,
- numery 1–32,
- rozróżnienie poprawne/niepoprawne,
- kliknięcie numeru otwiera pytanie,
- media,
- pełną treść,
- punktację,
- kategorię,
- wybraną odpowiedź,
- poprawną odpowiedź,
- jasny komunikat poprawna/niepoprawna.

Nie kopiujemy layoutu ani stylistyki konkurenta.

Warto dodatkowo pokazać:
- `0/3 pkt` albo `3/3 pkt` przy pytaniu,
- numer/ID pytania tylko dla uprawnionego administratora, jeśli potrzebne diagnostycznie,
- opcjonalne wyjaśnienie edukacyjne wyłącznie poza formalnym trybem egzaminacyjnym, jeśli polityka produktu na to pozwala.

---

## 9. Potwierdzone akcje

- `select_exam_question_for_review`
- `render_exam_question_media`
- `render_exam_question_text`
- `render_exam_question_point_value`
- `render_exam_question_category`
- `render_correct_answer`
- `render_candidate_selected_answer`
- `render_incorrect_answer_state`
- `navigate_between_exam_questions_by_number`

---

## 10. Pozostałe niewiadome

- review pytania odpowiedzianego poprawnie,
- review pytania bez odpowiedzi (`BRAK`),
- wariant A/B/C w review,
- obraz zamiast wideo,
- czy występuje tekstowe wyjaśnienie odpowiedzi poniżej aktualnego viewportu,
- możliwość przejścia poprzednie/następne poza klikaniem numerów,
- zachowanie historycznych mediów po ich późniejszej zmianie w głównej bazie.
