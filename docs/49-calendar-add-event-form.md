# 49. Kalendarz — formularz „Dodaj wydarzenie”

Data weryfikacji: 2026-09-05

**Kontekst:** `/kalendarz` -> `Dodaj wydarzenie`  
**Źródło:** bieżący zalogowany ekran + screenshoty rozwiniętych selektorów przekazane podczas audytu  
**Status:** `USER_CONFIRMED_AUTH_SCREEN`

---

## 1. Znaczenie ekranu

Drawer `Dodaj wydarzenie` tworzy ręczny wpis kalendarza i pozwala powiązać go z zasobami OSK.

Zaobserwowane ręcznie tworzone rodzaje:
- `Wydarzenie`,
- `Jazda`.

`Ważne daty` są widoczne jako filtr na głównym kalendarzu, ale **nie występują w selektorze rodzaju tego formularza**.

Wniosek:
- `important_date` nie powinno być traktowane jako zwykły ręczny event tworzony tym formularzem,
- dokładne źródło `Ważnych dat` pozostaje `TO_VERIFY`.

---

## 2. Pola formularza

### 2.1. Rodzaj wydarzenia *

Kontrolka:
- single select.

Potwierdzone opcje:
- `Wydarzenie`,
- `Jazda`.

Pole oznaczone `*`.

### 2.2. Nazwa wydarzenia (opcjonalnie)

Pole tekstowe.

### 2.3. Data rozpoczęcia *

Dwie kontrolki:
- data (`dd.mm.rrrr`),
- godzina (`--:--`).

Pole oznaczone `*`.

Potwierdzony model czasu:
- start ma datę i godzinę.

### 2.4. Ilość godzin

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

### 2.5. Kursant (opcjonalnie)

Searchable single select.

Zaobserwowany projection w dropdownie:
- imię i nazwisko,
- login/dostęp do nauki.

Przykłady:
- Jan Nowak + `jannowakprawojazdy360`,
- Paweł Kowalski + `pawelkowalskiprawojazdy360`,
- Ala Nowak + `alanowakprawojazdy360`.

### 2.6. Instruktor (opcjonalnie)

Searchable single select.

Zaobserwowany projection:
- imię i nazwisko,
- e-mail,
- ostrzeżenia o ważności dokumentów.

Potwierdzone ostrzeżenia w dropdownie:
- `Legitymacja wygasła <data>`,
- `Badania lekarskie wygasły <data>`,
- `Badania psychologiczne wygasły <data>`.

Przykłady:
- Anna Nowak,
- Jan Nowak.

### Ważny wniosek
Wybór instruktora pokazuje operatorowi ryzyko formalne **już w momencie planowania wydarzenia**.

Nie potwierdzono jeszcze, czy wygasły dokument:
- tylko ostrzega,
- czy blokuje zapis jazdy.

### 2.7. Pojazd (opcjonalnie)

Searchable single select.

Zaobserwowany projection:
- marka i model,
- numer rejestracyjny,
- ostrzeżenia dokumentowe.

Potwierdzone ostrzeżenia:
- `AC wygasło <data>`,
- `OC wygasło <data>`,
- `Przegląd wygasł <data>`.

Przykłady:
- Yamaha MT-07 / PO XYZ360,
- Hyundai i20 / PO XYZ321.

### Ważny wniosek
Wybór pojazdu pokazuje ważność dokumentów już w flow planowania.

Nie potwierdzono, czy pojazd z wygasłym OC/przeglądem jest tylko ostrzegany czy technicznie blokowany.

### 2.8. Miejsce spotkania (opcjonalnie)

Single select.

Lista jest grupowana według typu lokalizacji.

Zaobserwowane grupy i wpisy:
- `Sala wykładowa` -> `Sala wykładowa`,
- `Plac manewrowy` -> `Plan nauki jazdy`.

Widoczna jest także akcja/link:
- `Inne?`

Zachowanie `Inne?` pozostaje `TO_VERIFY` — najprawdopodobniej pozwala podać niestandardowe miejsce spotkania, ale nie zapisujemy tego jako potwierdzony fakt bez otwarcia kontrolki.

---

## 3. Akcje

Potwierdzone:
- `Zapisz`,
- `Anuluj`,
- zamknięcie `X`.

---

## 4. Kardynalność zasobów

Na tym formularzu każdy selektor jest pojedynczy:
- max 1 kursant,
- max 1 instruktor,
- max 1 pojazd,
- max 1 miejsce spotkania.

To potwierdza kardynalność **dla pojedynczego ręcznie tworzonego eventu w tym ekranie**.

Nie oznacza to, że cały model domenowy nigdy nie będzie potrzebował wielu uczestników (np. wykład grupowy). W naszym projekcie warto zachować możliwość rozszerzenia eventu o wielu uczestników bez łamania modelu.

---

## 5. Ostrzeżenia compliance w selektorach

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

## 6. Własny model wydarzenia

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
  - `custom_meeting_place nullable` (jeżeli potwierdzimy `Inne?`),
  - `status`,
  - `created_by`,
  - audit timestamps.

`ends_at` może być wyliczane jako `starts_at + duration_minutes` lub utrzymywane jako wartość pochodna z odpowiednią spójnością.

---

## 7. Conflict/compliance engine dla naszego produktu

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

---

## 8. Potwierdzone akcje

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
- `open_other_meeting_place_control_visible_behavior_unknown`
- `save_calendar_event`
- `cancel_calendar_event_create`
- `close_calendar_event_drawer`

---

## 9. Pozostałe niewiadome

Najważniejsze:
- czy wybranie `Jazda` zmienia/rozszerza pola formularza,
- czy wybranie `Wydarzenie` zmienia pola formularza,
- zachowanie `Inne?`,
- dokładna semantyka `Ilość godzin`,
- minimalny/maksymalny czas trwania,
- czy kursant/instruktor/pojazd stają się wymagani dla typu `Jazda`,
- czy system blokuje wygasłe dokumenty czy tylko ostrzega,
- conflict messages,
- recurrence,
- notifications,
- status po zapisie,
- edit/detail flow po utworzeniu.
