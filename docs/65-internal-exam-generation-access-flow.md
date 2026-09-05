# 65. Egzamin wewnętrzny — generowanie dostępu do egzaminu

Data weryfikacji: 2026-09-05

**Kontekst:** `/egzamin-wewnetrzny/panel` -> drawer `Generuj dostęp do egzaminu wewnętrznego`  
**Źródło:** bieżące zalogowane ekrany + opis użytkownika podczas audytu  
**Status:** `USER_CONFIRMED_AUTH_SCREEN`

Powiązana decyzja prawna dla naszego produktu:
- `docs/66-formal-student-record-and-theory-exemptions.md`,
- `specs/legal/training-theory-exemptions.yml`.

---

## 1. Dwa potwierdzone wejścia do tego samego flow

Drawer można otworzyć na dwa sposoby.

### A. Z wcześniej zaznaczonym kursantem
1. operator zaznacza dokładnie jednego kursanta,
2. UI pokazuje `ZAZNACZONE (1)`,
3. aktywuje `Generuj egzaminy (1)`,
4. po kliknięciu drawer otwiera się z wybranym kursantem.

### B. Bez wcześniejszego zaznaczenia kursanta
1. operator klika duży zielony `Generuj egzamin`,
2. otwiera się ten sam drawer,
3. konkurent pozwala:
   - wyszukać istniejącego kursanta,
   - albo użyć sekcji `Dodaj nowego kursanta`.

### Kardynalność
- dokładnie 1 kursant na flow,
- brak batch generation wielu kursantów,
- komunikat UI: `Dostępne tylko dla 1 kursanta jednocześnie.`

---

## 2. Korekta domenowa po weryfikacji przepisów

Dla **naszego formalnego panelu OSK** nie wdrażamy tymczasowego `ad_hoc_candidate` bez trwałego rekordu kursanta.

Przepisy wymagają formalnej ewidencji osoby szkolonej oraz dokumentowania jej szkolenia i godzin. Dlatego:

`internal_exam_attempt -> student_id REQUIRED -> course_enrollment_id REQUIRED`

Jeżeli operator wybierze u nas `Dodaj nowego kursanta` z poziomu egzaminu, system powinien:
1. utworzyć trwały rekord `student`,
2. utworzyć lub podpiąć formalny `course_enrollment`,
3. ustalić kategorię i wymagania kursu,
4. dopiero potem wrócić do generowania egzaminu.

Nie dopuszczamy formalnego egzaminu wewnętrznego dla osoby istniejącej wyłącznie jako jednorazowy rekord egzaminacyjny.

---

## 3. Stan bez wybranego kursanta — obserwacja konkurenta

### `Wyszukaj kursanta`
Wyszukiwanie po:
- imieniu,
- nazwisku,
- emailu,
- loginie,
- PESEL.

### `Dodaj nowego kursanta`
Zaobserwowane pola:
- Kategoria egzaminu,
- Język egzaminu,
- Imię,
- Nazwisko,
- Numer ewidencyjny/PKK,
- PESEL,
- Email.

Email ma opis: `do wysłania linku egzminu (opcjonalnie)`.

Nie ustalono, czy konkurent tworzy z tego trwały rekord w module `Kursanci`. Dla naszego produktu ta niewiadoma nie jest blokująca — własna implementacja ma tworzyć trwałego kursanta i formalny rekord szkolenia.

---

## 4. Stan z wybranym kursantem

Zaobserwowana karta Ali Nowak pokazuje:
- kategorię,
- język,
- imię,
- nazwisko,
- PKK,
- PESEL,
- email.

Akcje:
- `Usuń` — usuwa kursanta z bieżącego formularza,
- `Edytuj dane` — edycja danych potrzebnych do egzaminu.

Widoczny komunikat:
- `Uzupełnij dane kursanta`.

Exact trigger komunikatu pozostaje do sprawdzenia.

---

## 5. Dwa tryby uruchomienia

### `Udostępnij link do egzaminu`
- generuje link,
- kursant może wykonać egzamin zdalnie,
- link jest wysyłany na email.

U nas:
- `launch.mode = remote_link`,
- email wymagany dopiero przy wysyłce,
- token nieprzewidywalny,
- wysyłka audytowana.

### `Rozpocznij egzamin teraz`
- start na bieżącym stanowisku,
- jeden kursant jednocześnie.

U nas:
- `launch.mode = local_station`,
- email nie jest wymagany,
- stanowisko i sesja są audytowane.

---

## 6. Egzamin jest częścią formalnego przebiegu kursu

Po weryfikacji przepisów własny model jest **course-first**:

`organization -> student -> course_enrollment -> training sessions/hours -> required exam parts -> internal_exam_attempt`

System ma przed udostępnieniem egzaminu sprawdzić rule engine dla kategorii i sytuacji kursanta.

Przykład:
- kursant posiada C,
- realizuje C+E,
- formalnie pozostaje w bazie i ma ewidencjonowane 25 godzin praktyki,
- teoria nie jest wymagana,
- teoretyczny egzamin wewnętrzny nie jest wymagany,
- wymagany jest praktyczny przebieg szkolenia i praktyczny egzamin wewnętrzny.

Zatem frontend nie może pokazywać przycisku `Generuj teoretyczny egzamin wewnętrzny`, jeśli reguły formalne dla konkretnego enrollmentu wskazują `internal_theory_exam_required = false`.

---

## 7. Model domenowy

`internal_exam_attempt`
- `organization_id`,
- `student_id` — wymagane,
- `course_enrollment_id` — wymagane,
- `exam_part = theory | practical`,
- category snapshot,
- language snapshot,
- registry/PKK snapshot,
- PESEL/date-of-birth snapshot,
- requirement/exemption basis,
- status,
- immutable question/result snapshot dla teorii.

`internal_exam_launch`
- `mode = remote_link | local_station`,
- token/session reference,
- created_at,
- expires_at nullable,
- launched_at nullable,
- revoked_at nullable,
- created_by,
- station/device metadata nullable.

---

## 8. Walidacje naszego produktu

Przed generowaniem formalnego egzaminu:
- istnieje dokładnie 1 trwały kursant,
- istnieje formalny `course_enrollment`,
- rule engine wskazuje, że dana część egzaminu jest wymagana,
- dostępna jest jednostka egzaminu,
- kategoria i język są ustawione,
- wymagane dane identyfikacyjne są kompletne,
- operator ma uprawnienie,
- tenant scope jest poprawny.

Dla remote link:
- poprawny email w chwili wysyłki.

Dla local station:
- brak równoległego egzaminu na tym samym stanowisku.

---

## 9. Potwierdzone akcje konkurenta

- `open_standalone_generate_exam_drawer`
- `search_existing_student_for_exam`
- `competitor_enter_new_student_data_in_drawer`
- `select_one_student_for_exam_generation`
- `open_generate_exam_for_selected_student`
- `view_available_exam_inventory_in_generation_drawer`
- `view_candidate_exam_data`
- `change_exam_category`
- `change_exam_language`
- `remove_candidate_from_generation_form`
- `open_candidate_data_edit`
- `share_exam_link_by_email`
- `start_exam_on_current_station`

---

## 10. Pozostałe niewiadome

- drawer `Edytuj dane`,
- pełna lista kategorii/języków,
- exact persistence zachowania `Dodaj nowego kursanta` u konkurenta,
- komunikaty walidacji,
- sukces/błąd po wysłaniu linku,
- email template,
- ekran przejścia do local station,
- TTL/revocation/resend,
- moment konsumpcji inventory,
- kolejność zużycia puli darmowej/opłaconej.
