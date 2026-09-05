# 65. Egzamin wewnętrzny — generowanie dostępu do egzaminu

Data weryfikacji: 2026-09-05

**Kontekst:** `/egzamin-wewnetrzny/panel` -> drawer `Generuj dostęp do egzaminu wewnętrznego`  
**Źródło:** bieżące zalogowane ekrany + opis użytkownika podczas audytu  
**Status:** `USER_CONFIRMED_AUTH_SCREEN`

---

## 1. Dwa potwierdzone wejścia do tego samego flow

Drawer `Generuj dostęp do egzaminu wewnętrznego` można otworzyć na dwa sposoby.

### A. Z wcześniej zaznaczonym kursantem

1. operator zaznacza dokładnie jednego kursanta w tabeli `Przydzielone egzaminy wewnętrzne`,
2. UI pokazuje `ZAZNACZONE (1)`,
3. aktywuje się przycisk `Generuj egzaminy (1)`,
4. po kliknięciu otwiera się drawer z już wybranym kandydatem.

### B. Bez wcześniejszego zaznaczenia kursanta

1. operator klika duży zielony przycisk `Generuj egzamin`,
2. otwiera się ten sam drawer,
3. operator może:
   - wyszukać istniejącego kursanta,
   - albo wprowadzić dane nowego kandydata bezpośrednio w formularzu.

Oba warianty kończą się tym samym etapem: przygotowaniem jednej osoby do jednego flow wygenerowania/uruchomienia egzaminu.

### Kardynalność

Potwierdzone:
- `target_cardinality = exactly_one_candidate_per_generation_flow`,
- brak potwierdzonego batch generation wielu kursantów,
- komunikat UI: `Dostępne tylko dla 1 kursanta jednocześnie.`

---

## 2. Licznik dostępnych egzaminów

Na górze drawera widoczny jest licznik:
- `Dostępne egzaminy: 160` w obserwowanym stanie.

Jest to projekcja wspólnej dostępnej puli egzaminów widocznej wcześniej w panelu.

---

## 3. Stan bez wybranego kursanta

Po wejściu przez duży zielony `Generuj egzamin` drawer pokazuje dwie alternatywne ścieżki.

### 3.1. `Wyszukaj kursanta`

Pole wyszukiwania z instrukcją:
- `Wpisz imię, nazwisko, email, login lub pesel kursanta.`

Potwierdzone pola wyszukiwania:
- imię,
- nazwisko,
- email,
- login,
- PESEL.

Po wybraniu istniejącego kursanta formularz przechodzi do stanu karty kandydata opisanego w sekcji 4.

### 3.2. `Dodaj nowego kursanta`

Formularz zawiera:
- `Kategoria egzaminu`,
- `Język egzaminu`,
- `Imię`,
- `Nazwisko`,
- `Numer ewidencyjny/PKK`,
- `Pesel`,
- `Email`.

Przy emailu widoczna jest informacja:
- `do wysłania linku egzminu (opcjonalnie)`.

### Ważna granica domenowa

Etykieta `Dodaj nowego kursanta` nie potwierdza jeszcze, czy ten formularz tworzy pełny trwały profil kursanta w module `Kursanci`, czy tylko tymczasowy/kontekstowy profil kandydata do egzaminu.

Dla naszego produktu rekomendowane rozdzielenie:
- istniejący `student_profile` można wyszukać i wykorzystać,
- kandydat egzaminacyjny może również zostać wprowadzony ad hoc,
- utworzenie trwałego profilu kursanta powinno być świadomą decyzją biznesową, a nie ukrytym skutkiem wygenerowania egzaminu.

---

## 4. Stan z wybranym kandydatem

Zaobserwowany kandydat: `Ala Nowak`.

Karta pokazuje:
- `Kategoria` — obserwowane `B`, kontrolka wyboru,
- `Język` — obserwowane `Polski`, kontrolka wyboru,
- `Imię` — `Ala`,
- `Nazwisko` — `Nowak`,
- `PKK`,
- `Pesel`,
- `Email`.

Dostępne akcje:
- `Usuń` — usuwa kandydata z bieżącego formularza,
- `Edytuj dane` — otwiera edycję danych potrzebnych do egzaminu.

Widoczny był komunikat:
- `Uzupełnij dane kursanta`.

Nie potwierdzono jeszcze dokładnej walidacji, która wywołuje ten komunikat.

---

## 5. Dwa tryby uruchomienia egzaminu

### A. `Udostępnij link do egzaminu`

Opis UI potwierdza:
- wygenerowanie linku do egzaminu wewnętrznego,
- możliwość wykonania egzaminu w dowolnym miejscu z Internetem,
- wysłanie linku na adres email kandydata.

Dla naszego produktu:
- `launch.mode = remote_link`,
- token jest nieprzewidywalny i oddzielony od `attempt.id`,
- wysyłka email jest audytowana,
- email może być opcjonalny na etapie wpisywania danych, ale musi zostać zwalidowany, jeśli użytkownik wybierze wysyłkę linku mailem.

### B. `Rozpocznij egzamin teraz`

Opis UI potwierdza:
- uruchomienie egzaminu na bieżącym stanowisku komputerowym,
- ograniczenie do jednej osoby jednocześnie.

Dla naszego produktu:
- `launch.mode = local_station`,
- email nie powinien być wymagany do lokalnego uruchomienia,
- stanowisko/sesja musi być audytowalne.

---

## 6. Wspólny model domenowy

Obie ścieżki wejścia i oba tryby uruchomienia prowadzą do tej samej klasy próby egzaminacyjnej.

`internal_exam_attempt`
- candidate/student snapshot,
- category,
- language,
- registry/PKK snapshot,
- PESEL snapshot nullable zgodnie z polityką,
- status,
- question set snapshot,
- score/result.

`internal_exam_launch`
- `mode = remote_link | local_station`,
- token/session reference,
- created_at,
- expires_at nullable,
- launched_at nullable,
- revoked_at nullable,
- created_by,
- station/device metadata nullable.

### Źródło kandydata

Dla naszego modelu warto zapisać:
- `candidate_source = existing_student | ad_hoc_candidate`,
- `student_id nullable`.

Dzięki temu egzamin może być przeprowadzony także dla osoby, której nie chcemy automatycznie tworzyć jako pełnego kursanta w CRM OSK.

---

## 7. Rekomendowany lifecycle własnego produktu

Próba:

`draft/prepared -> ready -> started -> completed`

Alternatywne końce:
- `cancelled`,
- `expired` dla niewykorzystanego linku, jeśli wdrożymy TTL.

Launch remote:
`created -> sent -> opened -> started -> consumed/closed`

Launch local:
`created -> started_on_station -> consumed/closed`

Moment konsumpcji jednostki inventory pozostaje osobną decyzją i musi być atomowy oraz audytowany.

---

## 8. Walidacje dla naszego produktu

Wspólne:
- dostępna co najmniej 1 jednostka egzaminu,
- dokładnie 1 kandydat,
- kategoria ustawiona,
- język ustawiony,
- wymagane dane identyfikacyjne kompletne,
- operator ma odpowiednie uprawnienie,
- tenant scope.

Dla `remote_link`:
- poprawny email wymagany w chwili wysyłki linku.

Dla `local_station`:
- brak konieczności emaila,
- brak równoległego egzaminu na tym samym stanowisku zgodnie z polityką.

---

## 9. Potwierdzone akcje

- `open_standalone_generate_exam_drawer`
- `search_existing_student_for_exam`
- `enter_ad_hoc_exam_candidate`
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

## 10. Pozostałe niewiadome / kolejne capture

- dokładny drawer `Edytuj dane`,
- pełna lista kategorii egzaminu,
- pełna lista języków egzaminu,
- dokładne wymagane vs opcjonalne pola,
- czy `Dodaj nowego kursanta` tworzy trwały rekord w module `Kursanci`,
- komunikaty walidacji,
- ekran po `Udostępnij link do egzaminu`,
- format i treść wysłanego emaila,
- ekran tuż przed/po `Rozpocznij egzamin teraz`,
- TTL/revocation linku,
- możliwość ponownej wysyłki,
- zachowanie niewykorzystanego linku,
- dokładny moment konsumpcji jednostki egzaminu,
- priorytet konsumpcji puli darmowej vs opłaconej.
