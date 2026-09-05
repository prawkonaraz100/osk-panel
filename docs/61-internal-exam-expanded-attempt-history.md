# 61. Egzamin wewnętrzny — rozwinięta historia prób kursanta

Data weryfikacji: 2026-09-05

**Kontekst:** `/egzamin-wewnetrzny/panel` -> `Rozwiń` przy kursancie  
**Źródło:** bieżący zalogowany ekran + screenshot przekazany podczas audytu  
**Status:** `USER_CONFIRMED_AUTH_SCREEN`

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

Każda konkretna próba ma osobny link `Szczegóły` z własnym tokenem/parametrem `pid` prowadzącym do modułu egzaminu wewnętrznego.

Potwierdza to, że szczegóły są per-attempt, a nie tylko per-student.

Nie potwierdzono jeszcze zawartości widoku po wejściu w `Szczegóły`.

---

## 8. Akcja `Pobierz wydruk`

Każda konkretna próba ma osobny `Pobierz wydruk`.

Zaobserwowane endpointy mają wzorzec:
- `/exam/download?id=<exam_id>`.

Różne próby mają różne `exam_id` (np. 72479, 72478, 72468 itd.).

Format i zawartość wydruku należy zmapować z pobranego dokumentu.

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
- `result nullable`,
- `inventory_source`,
- `inventory_ledger_entry_id`,
- `created_by`,
- audit metadata.

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
- `download_specific_exam_attempt_printout`

---

## 11. Wymagania dla naszego produktu

- każdy egzamin/próba ma własny stabilny ID,
- timestamp nie jest identyfikatorem,
- historyczne dane egzaminu są snapshotowane,
- statusy są jawne tekstowo w szczegółach/historii,
- wiersz główny może używać ikony jako skrótu, ale musi mieć dostępny tekst statusu dla accessibility,
- dokument i szczegóły są przypięte do konkretnego attemptu,
- tenant isolation i autoryzacja per attempt,
- wynik ukończonego egzaminu nie jest nadpisywany przez kolejną próbę.

---

## 12. Pozostałe niewiadome

- wygląd/stany `Zaliczony`,
- statusy egzaminu przed wykonaniem,
- zachowanie egzaminu rozpoczętego i niedokończonego,
- scoring/wynik punktowy,
- czas trwania egzaminu,
- zawartość `Szczegóły`,
- zawartość `Pobierz wydruk`,
- dokładne znaczenie pola `Data` (wygenerowanie/start/zakończenie) — ekran sam tego nie opisuje,
- moment zużycia jednostki inventory.
