# 61. Egzamin wewnętrzny — rozwinięta historia prób kursanta

Data weryfikacji: 2026-09-05

**Kontekst:** `/egzamin-wewnetrzny/panel` -> `Rozwiń` przy kursancie  
**Źródło:** bieżący zalogowany ekran + screenshot przekazany podczas audytu  
**Status:** `USER_CONFIRMED_AUTH_SCREEN`

Powiązane materiały konkretnej próby:
- `docs/62-internal-exam-answer-sheet-pdf.md` — potwierdzony PDF `Pobierz wydruk`,
- `docs/63-internal-exam-result-details-screen.md` — potwierdzony ekran `Szczegóły` zakończonej próby.

---

## 1. Znaczenie

Akcja `Rozwiń` w wierszu kursanta pokazuje tabelę `Egzaminy` zawierającą konkretne próby/egzaminy przypisane temu kursantowi.

Potwierdza to model:

`student/internal_exam_context -> many internal_exam_attempts`

Licznik `Liczba egzaminów` w wierszu głównym odpowiada liczbie rekordów widocznych w rozwinięciu.

W obserwowanym przykładzie:
- kursant: `Ala Nowak`,
- liczba egzaminów w wierszu głównym: `7`,
- liczba widocznych rekordów w rozwinięciu: `7`.

---

## 2. Kolumny rozwinięcia

Tabela `Egzaminy` ma potwierdzone kolumny:
- `Imię i nazwisko`,
- `Kategoria`,
- `Status`,
- `Nr ewidencyjny/PKK`,
- `Data`,
- `Język`,
- akcje.

Każdy rekord ma akcje:
- `Szczegóły`,
- `Pobierz wydruk`.

---

## 3. Zaobserwowana historia Ali Nowak

Wszystkie 7 widocznych prób mają:
- imię i nazwisko: `Ala Nowak`,
- kategorię: `B`,
- status tekstowy: `Niezaliczony`,
- nr ewidencyjny/PKK: `445645645646465`,
- język: `PL`.

Zaobserwowane daty/godziny:
- `26-01-2026 15:43`,
- `26-01-2026 15:43`,
- `26-01-2026 14:07`,
- `26-01-2026 14:02`,
- `26-01-2026 13:15`,
- `26-01-2026 13:15`,
- `21-01-2026 12:44`.

Dwa różne rekordy mogą mieć tę samą minutę utworzenia/wyświetlanej daty. Nie wolno więc traktować `timestamp` z dokładnością do minuty jako unikalnego identyfikatora egzaminu.

---

## 4. Znaczenie czerwonego `x` w wierszu głównym

Wiersz główny Ali pokazuje dla `Najnowszy egzamin`:
- kategoria `B`,
- czerwony symbol `x`,
- data `26-01-2026 15:43`,
- język `PL`.

Rozwinięcie pokazuje, że najnowsze próby z tą datą mają status tekstowy `Niezaliczony`.

Dlatego dla obserwowanego panelu czerwony `x` jest potwierdzoną projekcją statusu:

`Niezaliczony -> red x`

Nie przesądzamy jeszcze, jak prezentowany jest egzamin:
- zaliczony,
- oczekujący,
- rozpoczęty,
- przerwany/anulowany,
- wygasły link.

---

## 5. Wiersz nadrzędny jako agregat najnowszej próby

Wiersz kursanta nie jest samym egzaminem. Pokazuje projekcję `Najnowszy egzamin` oraz `Liczba egzaminów`.

Dla Ali:
- latest category: `B`,
- latest status projection: czerwony `x` = `Niezaliczony`,
- latest displayed date: `26-01-2026 15:43`,
- latest language: `PL`,
- total attempts: `7`.

Ponieważ dwie próby mają identyczny wyświetlany timestamp `15:43`, nie można ustalić z samego UI, która z nich jest dokładnie `latest_attempt_id` bez identyfikatora backendowego.

---

## 6. Nr ewidencyjny/PKK

`Nr ewidencyjny/PKK` jest prezentowany osobno przy każdej próbie.

To wspiera wymaganie, aby egzamin posiadał historyczny snapshot danych identyfikacyjnych użytych przy jego przeprowadzeniu, zamiast polegać wyłącznie na aktualnej wartości w profilu kursanta.

Dla naszego produktu rekomendowane snapshoty egzaminu:
- `candidate_full_name_snapshot`,
- `category_snapshot`,
- `pkk_or_registry_number_snapshot`,
- `language_snapshot`.

Zmiana danych kursanta po egzaminie nie powinna zmieniać dokumentacji historycznej wykonanej próby.

---

## 7. Akcja `Szczegóły`

Każda konkretna próba ma osobny link `Szczegóły`.

Potwierdzone zachowanie po kliknięciu:
- użytkownik opuszcza panel BIZ/admin OSK,
- następuje przejście do serwisu egzaminacyjnego na host `www.prawo-jazdy-360.pl`,
- route ma postać `/egzamin-wewnetrzny?pid=<opaque_token>`,
- `pid` jest długim nieprzewidywalnym tokenem, a nie prostym numerycznym ID próby,
- dla zakończonej próby strona pokazuje ekran wyniku i przeglądu odpowiedzi.

Potwierdzony ekran zakończonej próby zawiera:
- wynik punktowy (`0 z 74 pkt.` w obserwowanym przykładzie),
- komunikat wyniku negatywnego,
- nawigację po 32 pytaniach: 1–20 podstawowe, 21–32 specjalistyczne,
- statystyki poprawnych/niepoprawnych odpowiedzi dla pytań podstawowych, specjalistycznych i wszystkich.

Szczegółowe mapowanie: `docs/63-internal-exam-result-details-screen.md`.

### Wniosek

`Szczegóły` nie jest klasycznym adminowym `exam/{id}`. To osobny front wynikowy/przeglądowy konkretnej próby, dostępny przez token.

Rozdzielamy:
- `internal_exam_attempt.id` — stabilne wewnętrzne ID,
- `internal_exam_launch/view_token` — zewnętrzny opaque token.

### Własny produkt

U nas nie wystawiamy przewidywalnego `attempt_id` jako sekretu dostępowego.

Rekomendowane:
- opaque random token,
- token związany z konkretną próbą,
- możliwość unieważnienia,
- opcjonalny TTL zależny od rodzaju linku,
- audyt użycia,
- brak danych osobowych w URL,
- osobne polityki dla linku do rozpoczęcia egzaminu i historycznego wyniku.

---

## 8. Akcja `Pobierz wydruk`

Każda konkretna próba ma osobny `Pobierz wydruk`.

Zaobserwowane endpointy mają wzorzec:
- `/exam/download?id=<exam_id>`.

Różne próby mają różne `exam_id`.

Format i zawartość wydruku są potwierdzone w:
- `docs/62-internal-exam-answer-sheet-pdf.md`,
- `specs/screens/internal-exam-answer-sheet-pdf.yml`.

Potwierdzony wydruk zawiera m.in.:
- dane kandydata,
- datę i kategorię egzaminu,
- wszystkie pozycje testowe,
- identyfikatory pytań,
- punktację per pytanie,
- udzielone odpowiedzi,
- punkty uzyskane,
- sumę punktów,
- wynik,
- miejsce na podpis kursanta,
- miejsce na podpis i pieczątkę osoby egzaminującej.

---

## 9. Model naszego produktu

`internal_exam_attempt` powinien być niezmiennym historycznym rekordem próby i mieć m.in.:
- `id`,
- `organization_id`,
- `student_id`,
- `exam_access_id nullable`,
- `category`,
- `language`,
- `candidate_full_name_snapshot`,
- `pkk_or_registry_number_snapshot`,
- `status`,
- `generated_at`,
- `started_at nullable`,
- `completed_at nullable`,
- `score nullable`,
- `max_score nullable`,
- `result nullable`,
- `inventory_source`,
- `inventory_ledger_entry_id`,
- `created_by`,
- audit metadata.

Próba posiada kolekcję niezmiennych pozycji `internal_exam_attempt_questions` z identyfikatorem pytania, kolejnością, punktacją, odpowiedzią i punktami uzyskanymi.

Token/link nie jest identyfikatorem domenowym próby. Jest osobnym zasobem z relacją do attemptu.

Wiersz główny panelu jest projekcją/agregatem nad próbami kursanta, a nie źródłem prawdy.

---

## 10. Potwierdzone akcje

- `expand_student_exam_attempt_history`
- `collapse_student_exam_attempt_history`
- `view_exam_attempt_category`
- `view_exam_attempt_status_text`
- `view_exam_attempt_pkk_or_registry_number`
- `view_exam_attempt_datetime`
- `view_exam_attempt_language`
- `open_specific_exam_attempt_details`
- `redirect_to_tokenized_exam_frontend`
- `view_completed_exam_result`
- `view_exam_result_question_navigation`
- `view_exam_result_statistics`
- `download_specific_exam_attempt_printout`
- `download_specific_exam_answer_sheet_pdf`

---

## 11. Wymagania dla naszego produktu

- każdy egzamin/próba ma własny stabilny ID,
- timestamp nie jest identyfikatorem,
- historyczne dane egzaminu są snapshotowane,
- zestaw/kolejność pytań i odpowiedzi próby są snapshotowane,
- statusy są jawne tekstowo w szczegółach/historii,
- dokument i ekran wyniku korzystają z tego samego immutable attempt snapshot,
- token zewnętrzny jest oddzielony od domenowego ID próby,
- tenant isolation i autoryzacja per attempt,
- wynik ukończonego egzaminu nie jest nadpisywany przez kolejną próbę,
- ponowne wygenerowanie arkusza/historycznego wyniku nie zależy od aktualnego profilu kursanta ani aktualnej bazy pytań.

---

## 12. Pozostałe niewiadome

- wygląd/stany `Zaliczony`,
- statusy egzaminu przed wykonaniem,
- zachowanie egzaminu rozpoczętego i niedokończonego,
- czas trwania egzaminu,
- widok po kliknięciu konkretnego numeru pytania na ekranie wyniku,
- dokładne znaczenie pola `Data`,
- moment zużycia jednostki inventory,
- wygląd arkusza dla wyniku pozytywnego,
- sposób renderowania rzeczywiście udzielonych odpowiedzi,
- lifecycle/TTL/revocation tokenu `pid` u konkurenta.
