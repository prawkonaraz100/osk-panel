# 65. Egzamin wewnętrzny — generowanie dostępu do egzaminu

Data weryfikacji: 2026-09-05

**Kontekst:** `/egzamin-wewnetrzny/panel` -> wybór kursanta -> `Generuj egzaminy (1)` -> drawer `Generuj dostęp do egzaminu wewnętrznego`  
**Źródło:** bieżące zalogowane ekrany + opis użytkownika podczas audytu  
**Status:** `USER_CONFIRMED_AUTH_SCREEN`

---

## 1. Wejście z listy egzaminów

Jedna z potwierdzonych ścieżek uruchomienia flow:
1. operator zaznacza jednego kursanta w tabeli `Przydzielone egzaminy wewnętrzne`,
2. UI pokazuje `ZAZNACZONE (1)`,
3. aktywuje się przycisk `Generuj egzaminy (1)`,
4. po kliknięciu otwiera się drawer `Generuj dostęp do egzaminu wewnętrznego`.

### Kardynalność

Użytkownik potwierdził, że w tym flow wybiera się tylko jednego kursanta naraz.

Nie traktujemy kontrolki `Generuj egzaminy (1)` jako batch generation wielu egzaminów dla wielu kursantów.

Dla naszego produktu:
- `target_cardinality = exactly_one_student_per_generation_flow`.

Checkbox/select UI może być zachowane dla wygody wyboru w tabeli, ale logika uruchomienia egzaminu jest jednoosobowa.

---

## 2. Drawer `Generuj dostęp do egzaminu wewnętrznego`

Nagłówek:
- `Generuj dostęp do egzaminu wewnętrznego`.

Na górze widoczny jest licznik:
- `Dostępne egzaminy: 160` w obserwowanym stanie.

Licznik odpowiada dostępnej puli egzaminów widocznej wcześniej w panelu (`Darmowe + Opłacone = Wszystkie`).

---

## 3. Karta kursanta

Zaobserwowany kursant:
- `Ala Nowak`.

Karta pokazuje:
- `Kategoria` — w obserwowanym stanie `B`, kontrolka wyboru,
- `Język` — w obserwowanym stanie `Polski`, kontrolka wyboru,
- `Imię` — `Ala`,
- `Nazwisko` — `Nowak`,
- `PKK` — w obserwowanym stanie puste,
- `Pesel` — `000000000000`,
- `Email` — `alanowak@prawo-jazdy-360.pl`.

Dostępne akcje na karcie:
- `Usuń` — usuwa kursanta z bieżącego flow/formularza; nie interpretujemy jako usunięcia profilu kursanta,
- `Edytuj dane` — pozwala poprawić dane potrzebne do egzaminu.

Nad kartą widoczny jest czerwony komunikat:
- `Uzupełnij dane kursanta`.

Nie przesądzamy z samego screena, które dokładnie pole powoduje ten komunikat ani czy jest to komunikat stały/instrukcyjny.

---

## 4. Dwa tryby uruchomienia egzaminu

Drawer potwierdza dwa różne tryby realizacji tej samej próby egzaminacyjnej.

### A. Zdalny link do egzaminu

Opis UI:
- przycisk generuje link do egzaminu wewnętrznego,
- kursant może wykonać egzamin w dowolnym miejscu z dostępem do Internetu,
- link zostaje przesłany na adres e-mail kursanta.

Akcja:
- `Udostępnij link do egzaminu`.

Model dla naszego produktu:
- tworzony jest tokenowany `internal_exam_launch_link`,
- link jest powiązany z konkretnym kandydatem/próbą,
- wysyłka e-mail jest osobnym zdarzeniem/audytem,
- link nie powinien zawierać prostego/przewidywalnego `attempt_id`.

Dokładny TTL, możliwość ponownej wysyłki i moment zużycia jednostki inventory pozostają do własnej decyzji / dalszej weryfikacji.

### B. Egzamin na bieżącym stanowisku

Opis UI:
- przycisk rozpoczyna egzamin wewnętrzny przy tym stanowisku komputerowym.

Akcja:
- `Rozpocznij egzamin teraz`.

Dodatkowy komunikat:
- `Dostępne tylko dla 1 kursanta jednocześnie.`

To potwierdza lokalny/stacjonarny workflow:
- pracownik OSK przygotowuje egzamin,
- uruchamia go na aktualnym komputerze/stanowisku,
- jedno stanowisko obsługuje w tym flow jednego kursanta jednocześnie.

Dla naszego produktu ten wariant jest szczególnie ważny dla scenariusza sekretariat + komputer egzaminacyjny.

---

## 5. Wspólny model domenowy

Oba przyciski nie powinny tworzyć dwóch różnych typów egzaminu domenowo. To dwa tryby dostarczenia/uruchomienia tej samej klasy `internal_exam_attempt`.

Rekomendowane rozdzielenie:

`internal_exam_attempt`
- candidate/student snapshot,
- category,
- language,
- PKK/registry snapshot,
- status,
- question set snapshot,
- score/result.

`internal_exam_launch`
- `mode = remote_link | local_station`,
- token/session,
- created_at,
- expires_at nullable,
- launched_at nullable,
- revoked_at nullable,
- created_by,
- station/device metadata nullable.

---

## 6. Rekomendowany lifecycle własnego produktu

Próba:

`draft/prepared -> ready -> started -> completed`

Alternatywne końce:
- `cancelled`,
- `expired` dla niewykorzystanego linku, jeśli wdrożymy TTL.

Launch:

### Remote
`created -> sent -> opened -> started -> consumed/closed`

### Local station
`created -> started_on_station -> consumed/closed`

Nie hardkodujemy jeszcze, kiedy dokładnie zmniejsza się inventory egzaminów; ta reguła musi być atomowa i audytowana.

---

## 7. Walidacje dla naszego produktu

Przed wygenerowaniem/uruchomieniem:
- organizacja ma co najmniej 1 dostępną jednostkę egzaminu,
- wybrany jest dokładnie 1 kursant,
- kategoria jest ustawiona,
- język jest ustawiony,
- wymagane dane identyfikacyjne są kompletne zgodnie z naszymi zasadami formalnymi,
- operator ma uprawnienie `internal_exam.generate` / `internal_exam.launch`,
- operacja jest tenant-scoped.

Dla `remote_link` dodatkowo:
- poprawny e-mail, jeśli system ma wysłać link mailem.

Dla `local_station`:
- blokada równoległego uruchomienia więcej niż jednego kursanta w tej samej sesji/stanowisku zgodnie z wybraną polityką.

---

## 8. Potwierdzone akcje

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

## 9. Pozostałe niewiadome / kolejne capture

- druga ścieżka wejścia przez zielony przycisk `Generuj egzamin` bez wcześniejszego wyboru kursanta — czy otwiera wyszukiwarkę/dodawanie kursanta i kończy się tym samym drawerem,
- dokładny drawer `Edytuj dane`,
- wymagane vs opcjonalne pola przed uruchomieniem,
- komunikaty walidacji,
- ekran po `Udostępnij link do egzaminu`,
- format i treść wysłanego e-maila,
- ekran tuż przed/po `Rozpocznij egzamin teraz`,
- TTL/revocation linku,
- możliwość ponownej wysyłki linku,
- zachowanie niewykorzystanego linku,
- dokładny moment konsumpcji jednostki egzaminu,
- priorytet konsumpcji puli darmowej vs opłaconej.
