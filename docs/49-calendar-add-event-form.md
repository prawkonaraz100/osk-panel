# 49. Kalendarz — formularz „Dodaj wydarzenie”

Data weryfikacji: 2026-09-05

**Kontekst:** `/kalendarz` -> `Dodaj wydarzenie`  
**Źródło:** bieżący zalogowany ekran + screenshoty rozwiniętych selektorów + ręczne przełączenie rodzaju `Wydarzenie` / `Jazda` + otwarcie trybu `Inne?` przy miejscu spotkania podczas audytu  
**Status:** `USER_CONFIRMED_AUTH_SCREEN`

---

## 1. Znaczenie ekranu

Drawer `Dodaj wydarzenie` tworzy ręczny wpis kalendarza i pozwala powiązać go z zasobami OSK.

Zaobserwowane ręcznie tworzone rodzaje:
- `Wydarzenie`,
- `Jazda`.

`Ważne daty` są widoczne jako filtr na głównym kalendarzu, ale **nie występują w selektorze rodzaju tego formularza**.

Wniosek:
- `important_date` nie jest zwykłym ręcznym eventem tworzonym tym formularzem,
- dokładne źródło `Ważnych dat` pozostaje `TO_VERIFY`.

---

## 2. Zachowanie po wyborze rodzaju

### Potwierdzone 2026-09-05

Po wybraniu `Jazda`:
- formularz **nie zmienia układu**,
- nie pojawiają się dodatkowe pola,
- nie znikają żadne pola,
- etykiety `Kursant (opcjonalnie)`, `Instruktor (opcjonalnie)`, `Pojazd (opcjonalnie)` i `Miejsce spotkania (opcjonalnie)` pozostają bez zmian.

To samo oznacza, że z punktu widzenia obserwowanego UI `Wydarzenie` i `Jazda` korzystają z **jednego wspólnego formularza**, a `Rodzaj wydarzenia` jest dyskryminatorem typu rekordu.

### Ważna granica

Nie potwierdzono zachowania backendu po kliknięciu `Zapisz` dla typu `Jazda`.

Nie wolno więc automatycznie zakładać, że backend konkurenta:
- nie ma dodatkowych walidacji,
- nie sprawdza wymaganych zasobów,
- nie stosuje innej logiki po zapisie.

Potwierdzamy tylko **parytet widocznego formularza**.

---

## 3. Pola formularza

### 3.1. Rodzaj wydarzenia *

Kontrolka:
- single select.

Potwierdzone opcje:
- `Wydarzenie`,
- `Jazda`.

Pole oznaczone `*`.

### 3.2. Nazwa wydarzenia (opcjonalnie)

Pole tekstowe.

### 3.3. Data rozpoczęcia *

Dwie kontrolki:
- data (`dd.mm.rrrr`),
- godzina (`--:--`).

Pole oznaczone `*`.

Potwierdzony model czasu:
- start ma datę i godzinę.

### 3.4. Ilość godzin

Kontrolka czasu/trwania (`--:--`).

Brak widocznego `*` na obserwowanym ekranie.

Najsilniejsza interpretacja:
- jest to czas trwania wydarzenia/jazdy,
- formularz nie pokazuje osobnego pola `Data zakończenia`.

Dla naszego modelu rekomendowane:
- `starts_at`,
- `duration_minutes`,
- `ends_at` wyliczane lub materializowane pomocniczo.

Dokładna walidacja i możliwość pustej wartości pozostają `TO_VERIFY`.

### 3.5. Kursant (opcjonalnie)

Searchable single select.

Zaobserwowany projection w dropdownie:
- imię i nazwisko,
- login/dostęp do nauki.

Przykłady:
- Jan Nowak + `jannowakprawojazdy360`,
- Paweł Kowalski + `pawelkowalskiprawojazdy360`,
- Ala Nowak + `alanowakprawojazdy360`.

### 3.6. Instruktor (opcjonalnie)

Searchable single select.

Zaobserwowany projection:
- imię i nazwisko,
- e-mail,
- ostrzeżenia o ważności dokumentów.

Potwierdzone ostrzeżenia w dropdownie:
- `Legitymacja wygasła <data>`,
- `Badania lekarskie wygasły <data>`,
- `Badania psychologiczne wygasły <data>`.

Nie potwierdzono jeszcze, czy wygasły dokument:
- tylko ostrzega,
- czy blokuje zapis jazdy.

### 3.7. Pojazd (opcjonalnie)

Searchable single select.

Zaobserwowany projection:
- marka i model,
- numer rejestracyjny,
- ostrzeżenia dokumentowe.

Potwierdzone ostrzeżenia:
- `AC wygasło <data>`,
- `OC wygasło <data>`,
- `Przegląd wygasł <data>`.

Nie potwierdzono, czy pojazd z wygasłym OC/przeglądem jest tylko ostrzegany czy technicznie blokowany.

### 3.8. Miejsce spotkania (opcjonalnie)

Pole ma dwa potwierdzone tryby.

#### Tryb A — zapisana lokalizacja OSK

Domyślnie renderowany jest single select.

Lista jest grupowana według typu lokalizacji.

Zaobserwowane grupy i wpisy:
- `Sala wykładowa` -> `Sala wykładowa`,
- `Plac manewrowy` -> `Plan nauki jazdy`.

Widoczna akcja/link:
- `Inne?`

#### Tryb B — niestandardowe miejsce tekstowe

Po kliknięciu `Inne?`:
- select zapisanych lokalizacji znika,
- w jego miejscu pojawia się zwykłe pole tekstowe,
- link `Inne?` zmienia etykietę na `Wróć`,
- operator może wpisać dowolny tekst jako miejsce spotkania.

Po kliknięciu `Wróć` należy oczekiwać powrotu do trybu wyboru zapisanej lokalizacji; samo istnienie tego przełącznika zostało potwierdzone w UI, natomiast zachowanie z zachowaniem/wyczyszczeniem wcześniej wpisanej wartości nie było dalej testowane.

### Potwierdzona semantyka domenowa

Pojedynczy event może wskazywać:
- `meeting_location_id` **albo**
- `custom_meeting_place`.

Nie powinny być jednocześnie aktywne w tym samym formularzu.

Dla naszego produktu rekomendacja:
- traktować te pola jako wzajemnie wykluczające się,
- przy przełączeniu trybu jawnie czyścić lub potwierdzać zmianę, aby nie zapisać dwóch sprzecznych źródeł miejsca,
- `custom_meeting_place` przechowywać jako tekst użytkownika, nie tworzyć z niego automatycznie stałej lokalizacji OSK.

---

## 4. Akcje

Potwierdzone:
- `Zapisz`,
- `Anuluj`,
- zamknięcie `X`,
- `Inne?` — przejście z zapisanej lokalizacji do pola tekstowego,
- `Wróć` — kontrolka powrotu z trybu tekstowego do trybu lokalizacji.

---

## 5. Kardynalność zasobów

Na tym formularzu każdy selektor jest pojedynczy:
- max 1 kursant,
- max 1 instruktor,
- max 1 pojazd,
- max 1 miejsce spotkania.

Miejsce spotkania może być reprezentowane jako:
- 1 zapisana lokalizacja,
- albo 1 niestandardowy tekst.

To potwierdza kardynalność **dla pojedynczego ręcznie tworzonego eventu w tym ekranie**.

Nie oznacza to, że cały model domenowy nigdy nie będzie potrzebował wielu uczestników, np. dla wykładu grupowego.

---

## 6. Ostrzeżenia compliance w selektorach

Formularz integruje dane z modułów:
- `Pracownicy`,
- `Pojazdy`.

Przy wyborze zasobu operator od razu widzi wygasłe terminy.

Dla naszego produktu wymagane:
- ostrzeżenia wyliczane z jednej wspólnej domeny ważności dokumentów,
- bez duplikowania dat w kalendarzu,
- server-side walidacja także przy zapisie,
- polityka `warning vs hard_block` konfigurowalna / zależna od typu dokumentu i reguł prawnych.

---

## 7. Własny model wydarzenia

Rekomendowany rdzeń:

- `calendar_events`
  - `type` (`general_event`, `driving_lesson`),
  - `name nullable`,
  - `starts_at`,
  - `duration_minutes nullable`,
  - `student_id nullable`,
  - `instructor_id nullable`,
  - `vehicle_id nullable`,
  - `location_id nullable`,
  - `custom_meeting_place nullable`,
  - `status`,
  - `created_by`,
  - audit timestamps.

Constraint domenowy:
- `location_id` i `custom_meeting_place` są alternatywnymi źródłami miejsca spotkania,
- formularz nie powinien zapisywać obu naraz.

### Decyzja implementacyjna

`Wydarzenie` i `Jazda` powinny korzystać z jednego komponentu formularza i jednego głównego modelu `calendar_events`, z polem `type` jako dyskryminatorem.

Nie budujemy dwóch niemal identycznych formularzy.

Backend naszego produktu może jednak nakładać własne, sensowne reguły biznesowe zależne od `type`, bez zmiany struktury formularza.

---

## 8. Conflict/compliance engine dla naszego produktu

Przed zapisem server powinien sprawdzić co najmniej:
- konflikt instruktora,
- konflikt pojazdu,
- konflikt lokalizacji,
- konflikt kursanta,
- status/aktywność zasobów,
- ważność wymaganych dokumentów pojazdu,
- ważność wymaganych dokumentów/uprawnień pracownika,
- tenant ownership wszystkich zasobów.

UI może pokazać ostrzeżenie przed zapisem, ale walidacja krytyczna musi być wykonywana również server-side.

Dla `custom_meeting_place` nie wykonujemy konfliktu zasobu lokalizacji, bo nie jest to zarządzany zasób OSK.

---

## 9. Potwierdzone akcje

- `open_add_calendar_event_drawer`
- `select_calendar_event_type`
- `set_calendar_event_name`
- `set_calendar_event_start_date`
- `set_calendar_event_start_time`
- `set_calendar_event_duration`
- `search_and_select_student`
- `search_and_select_instructor`
- `search_and_select_vehicle`
- `select_meeting_location`
- `switch_to_custom_meeting_place`
- `set_custom_meeting_place_text`
- `switch_back_to_saved_meeting_location`
- `save_calendar_event`
- `cancel_calendar_event_create`
- `close_calendar_event_drawer`

---

## 10. Pozostałe niewiadome

Najważniejsze:
- dokładna semantyka `Ilość godzin`,
- minimalny/maksymalny czas trwania,
- czy backend wymaga kursanta/instruktora/pojazdu dla typu `Jazda` mimo braku zmiany UI,
- czy system blokuje wygasłe dokumenty czy tylko ostrzega,
- conflict messages,
- recurrence,
- notifications,
- status po zapisie,
- edit/detail flow po utworzeniu,
- czy `Wróć` zachowuje czy czyści wcześniej wpisane niestandardowe miejsce.
