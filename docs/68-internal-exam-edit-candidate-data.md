# 68. Egzamin wewnętrzny — `Edytuj dane` kursanta w drawerze generowania

Data weryfikacji: 2026-09-05

**Kontekst:** `/egzamin-wewnetrzny/panel` -> `Generuj dostęp do egzaminu wewnętrznego` -> wybrany kursant -> `Edytuj dane`  
**Źródło:** bieżący zalogowany ekran + screenshot przekazany podczas audytu  
**Status:** `USER_CONFIRMED_AUTH_SCREEN`

---

## 1. Znaczenie stanu

Akcja `Edytuj dane` nie opuszcza drawera generowania egzaminu. Karta podsumowująca kursanta przechodzi w tryb edycji pól potrzebnych do przygotowania egzaminu.

Na górze nadal widoczny jest licznik dostępnej puli egzaminów (`Dostępne egzaminy: 160` w obserwowanym stanie), a pod formularzem nadal pozostają dwie akcje uruchomienia:
- `Udostępnij link do egzaminu`,
- `Rozpocznij egzamin teraz`.

---

## 2. Potwierdzone pola edycji

Formularz `Edytuj dane` zawiera:
- `Kategoria egzaminu` — select,
- `Język egzaminu` — select,
- `Imię` — input,
- `Nazwisko` — input,
- `Pesel` — input,
- `PKK` — input,
- `Email` — input.

Przy emailu widoczny jest opis:
- `do wysłania linku egzminu (opcjonalnie)`.

Zaobserwowane wartości dla Pawła Kowalskiego:
- kategoria `B`,
- język `Polski`,
- imię `Paweł`,
- nazwisko `Kowalski`,
- PESEL `030303030030`,
- PKK `445645645646455`,
- email `pawelkowalski@prawo-jazdy-360.pl`.

---

## 3. Akcje formularza

Potwierdzone:
- `Anuluj`,
- `Zapisz dane`.

Po anulowaniu należy wrócić do stanu karty kursanta bez zatwierdzania zmian.

Screenshot nie potwierdza jeszcze, czy `Zapisz dane`:
- aktualizuje główny trwały profil kursanta,
- aktualizuje wyłącznie dane kontekstu egzaminu,
- czy synchronizuje oba miejsca.

To pozostaje `TO_VERIFY_COMPETITOR`.

---

## 4. Relacja do formalnego profilu kursanta — nasz produkt

Po decyzji prawnej `course-first` z `docs/66-formal-student-record-and-theory-exemptions.md` nie traktujemy tego formularza jako niezależnego „kandydata egzaminacyjnego”. Egzamin jest powiązany z istniejącym `student` i `course_enrollment`.

Dla naszego produktu pola należy podzielić na dwa poziomy:

### Dane źródłowe kursanta/kursu
- imię,
- nazwisko,
- PESEL albo data urodzenia, gdy PESEL nie został nadany,
- PKK / numer ewidencyjny w odpowiednim kontekście,
- dane kontaktowe,
- kurs i kategoria.

### Parametry bieżącej próby
- język egzaminu,
- tryb uruchomienia,
- snapshot danych użytych do konkretnej próby.

Jeżeli operator poprawia dane źródłowe kursanta z poziomu egzaminu, zmiana powinna aktualizować formalny profil/kurs i zostawić audit log. Konkretna próba po utworzeniu zachowuje własny snapshot historyczny.

Nie powinniśmy tworzyć drugiego, rozjeżdżającego się zestawu imienia/PESEL/PKK wyłącznie dla egzaminu bez jasnej relacji do profilu kursanta.

---

## 5. Walidacja PESEL / data urodzenia

Na pokazanym formularzu konkurenta widoczny jest tylko input `Pesel`; nie pokazano przełącznika `brak PESEL -> data urodzenia`.

Dla naszego formalnego produktu zachowujemy wcześniejszą regułę z modułu kursanta:
- PESEL, jeśli został nadany,
- w przeciwnym razie jawne oznaczenie braku PESEL i data urodzenia.

Nie kopiujemy ograniczenia konkurenta, jeśli drawer egzaminu nie udostępnia tej alternatywy.

---

## 6. Kategoria i wymagania szkolenia

`Kategoria egzaminu` jest edytowalna w tym drawerze konkurenta.

Dla naszego produktu zmiana kategorii nie może być tylko kosmetycznym parametrem egzaminu. Musi być spójna z formalnym `course_enrollment` i regułami szkolenia.

Jeżeli operator wybierze inną kategorię niż aktywny kurs:
- system powinien zażądać wskazania właściwego enrollmentu / kursu,
- przeliczyć wymagania zgodnie z rule engine,
- nie dopuścić do wygenerowania formalnej teorii dla kursu, dla którego teoria nie jest wymagana.

---

## 7. Email

UI konkurenta oznacza email jako opcjonalny `do wysłania linku egzminu`.

Dla naszego produktu:
- `local_station` — email nie jest wymagany,
- `remote_link` — poprawny email jest wymagany w chwili wysyłki linku.

---

## 8. Potwierdzone akcje

- `open_exam_candidate_edit_state`
- `edit_exam_category`
- `edit_exam_language`
- `edit_candidate_first_name`
- `edit_candidate_last_name`
- `edit_candidate_pesel`
- `edit_candidate_pkk`
- `edit_candidate_email`
- `cancel_candidate_edit`
- `save_candidate_edit`

---

## 9. Pozostałe niewiadome

- zakres opcji `Kategoria egzaminu`,
- zakres opcji `Język egzaminu`,
- walidacje formatów PESEL/PKK/email u konkurenta,
- zachowanie `Zapisz dane` względem głównego profilu kursanta,
- komunikaty błędów,
- obsługa osoby bez PESEL w tym konkretnym drawerze konkurenta.
