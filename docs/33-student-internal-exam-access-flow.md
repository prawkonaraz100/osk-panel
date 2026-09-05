# 33. Kursanci — generowanie dostępu do egzaminu wewnętrznego

Data weryfikacji: 2026-09-05

**Kontekst:** akcja `Rozpocznij egzamin` z zakładki `Egzamin wewnętrzny` na karcie kursanta  
**Źródło:** bieżący zalogowany ekran + screenshot przekazany podczas audytu  
**Status:** `USER_CONFIRMED_AUTH_SCREEN`

Dane osobowe kursanta nie są przepisywane jako dane referencyjne produktu.

---

## 1. Tytuł i stan puli

Flow otwiera osobny drawer/modal:
- `Generuj dostęp do egzaminu wewnętrznego`.

Potwierdzony jest licznik:
- `Dostępne egzaminy: <N>`.

To jest projekcja dostępnej puli egzaminów OSK.

Nie potwierdzono jeszcze momentu zużycia sztuki z puli:
- przy wygenerowaniu linku,
- przy otwarciu linku,
- przy rozpoczęciu egzaminu,
- przy ukończeniu egzaminu.

Status: `TO_VERIFY_INVENTORY_CONSUMPTION_POINT`.

---

## 2. Dane egzaminowanego

Potwierdzony blok danych kursanta zawiera:
- imię i nazwisko,
- `Kategoria` — kontrolka wyboru,
- `Język` — kontrolka wyboru,
- Imię,
- Nazwisko,
- PKK,
- PESEL,
- Email.

Na obserwowanym ekranie:
- kategoria ma wybraną wartość,
- język ma wybraną wartość,
- część danych identyfikacyjnych/kontaktowych jest pusta,
- widoczny jest czerwony komunikat `Uzupełnij dane kursanta`.

Potwierdzone akcje w bloku:
- `Usuń`,
- `Edytuj dane`.

Dokładna semantyka `Usuń` wymaga dalszej obserwacji; nie zakładamy, że usuwa rekord kursanta.

### Ważna granica
Nie potwierdzono jeszcze dokładnego zestawu pól wymaganych do:
- wygenerowania linku,
- uruchomienia egzaminu lokalnie.

Szczególnie wiadomo, że tryb linkowy odwołuje się do adresu e-mail, ale exact validation pozostaje `TO_VERIFY`.

---

## 3. Tryb 1 — link do egzaminu

Potwierdzona instrukcja semantyczna:
- przycisk generuje link do egzaminu wewnętrznego,
- kursant może wykonać egzamin w dowolnym miejscu z dostępem do Internetu,
- link zostanie przesłany na adres e-mail kursanta.

Potwierdzona akcja:
- `Udostępnij link do egzaminu`.

### Model domenowy
To jest tryb `remote_link`.

Minimalny własny flow:
1. zweryfikuj OSK, kursanta, kategorię i wymagane dane,
2. sprawdź dostępność puli egzaminów,
3. utwórz bezpieczny, nieprzewidywalny dostęp do konkretnej próby,
4. powiąż dostęp z kursantem i OSK,
5. wyślij powiadomienie e-mail,
6. zapisuj audit zdarzeń utworzenia i użycia linku.

Exact TTL, możliwość ponownego wysłania, unieważnienia i liczba użyć linku nie są jeszcze potwierdzone dla konkurenta.

---

## 4. Tryb 2 — egzamin na bieżącym stanowisku

Potwierdzona instrukcja semantyczna:
- przycisk rozpoczyna egzamin wewnętrzny przy tym stanowisku komputerowym.

Potwierdzona akcja:
- `Rozpocznij egzamin teraz`.

Potwierdzone ograniczenie UI:
- `Dostępne tylko dla 1 kursanta jednocześnie`.

### Model domenowy
To jest tryb `local_workstation`.

Ograniczenie sugeruje blokadę/lease aktywnej sesji egzaminacyjnej dla stanowiska lub bieżącego kontekstu panelu.

Nie przypisujemy bez dalszej obserwacji dokładnego zakresu blokady:
- jedno stanowisko,
- jedna sesja przeglądarki,
- jedno konto OSK,
- cały ośrodek.

Dla naszego produktu rekomendujemy jawny model `exam_station_session` zamiast przypadkowego stanu w frontendzie.

---

## 5. Potwierdzone dwa kanały uruchomienia

System wspiera co najmniej:
1. `remote_link` — link wysyłany kursantowi,
2. `local_workstation` — natychmiastowy start przy komputerze OSK.

To jest istotne dla własnego projektu, ponieważ oba tryby mogą korzystać z tego samego silnika egzaminu, ale różnią się sposobem autoryzacji i uruchomienia.

---

## 6. Relacja z pulą egzaminów

Widoczny licznik `Dostępne egzaminy` potwierdza istnienie inventory/balance egzaminów na poziomie OSK.

Minimalny model:
- `exam_inventory_balance` lub ledger grantów/zakupów,
- rezerwacja/zużycie powiązane z próbą egzaminacyjną,
- idempotentne rozliczenie,
- audit.

Nie hardkodujemy liczby z ekranu demonstracyjnego.

---

## 7. Potwierdzone akcje

- `open_generate_internal_exam_access`
- `select_exam_category`
- `select_exam_language`
- `edit_student_exam_data`
- `remove_exam_candidate_context_visible` — semantyka do weryfikacji
- `share_internal_exam_link`
- `start_internal_exam_on_current_workstation`
- `close_exam_access_drawer`

---

## 8. Stan danych i walidacja

Potwierdzony jest stan ostrzegawczy:
- `Uzupełnij dane kursanta`.

Nie potwierdzamy jeszcze:
- które dokładnie pola są wymagane,
- czy wymagania są różne dla linku i startu lokalnego,
- czy brak e-mail blokuje tylko tryb linkowy,
- czy PKK jest obowiązkowe dla wszystkich kategorii/przypadków,
- jakie komunikaty błędów występują po kliknięciu akcji.

---

## 9. Bezpieczeństwo dla naszego produktu

Własna implementacja powinna wymagać:
- tenant isolation,
- autoryzacji kursanta i kategorii względem OSK,
- nieprzewidywalnych tokenów dostępu,
- ograniczonego czasu ważności linków zdalnych,
- jednokrotnego lub kontrolowanego użycia tokenu,
- unieważniania/revocation,
- ochrony przed równoległym podwójnym zużyciem inventory,
- idempotentnego rozliczenia egzaminu,
- audit trail dla generowania, wysyłki, startu, zakończenia i anulowania,
- server-side enforcement limitu sesji lokalnych.

To są wymagania naszego produktu; nie przypisujemy konkurentowi dokładnych mechanizmów technicznych bez dowodu.

---

## 10. Pozostałe niewiadome

- exact required candidate fields,
- wartości listy kategorii,
- wartości listy języków,
- moment zużycia egzaminu z puli,
- stan po `Udostępnij link do egzaminu`,
- format/TTL linku,
- resend/revoke linku,
- czy email jest jedynym kanałem dostarczenia,
- dokładny zakres `1 kursant jednocześnie`,
- ekran po `Rozpocznij egzamin teraz`,
- lifecycle przerwania/anulowania,
- zachowanie `Usuń`,
- komunikaty success/error.

---

## 11. Acceptance criteria

### AC-EXAM-ACCESS-01 — inventory
Administrator widzi liczbę dostępnych egzaminów przed wygenerowaniem dostępu.

### AC-EXAM-ACCESS-02 — candidate context
Przed uruchomieniem widoczny jest kursant, kategoria, język i dane identyfikacyjne/kontaktowe.

### AC-EXAM-ACCESS-03 — incomplete data
System sygnalizuje niekompletne dane kursanta przed wykonaniem flow.

### AC-EXAM-ACCESS-04 — remote mode
Administrator może uruchomić flow zdalny generujący link dla kursanta.

### AC-EXAM-ACCESS-05 — local mode
Administrator może rozpocząć egzamin bezpośrednio na bieżącym stanowisku.

### AC-EXAM-ACCESS-06 — concurrency
Tryb lokalny posiada ograniczenie jednej równoczesnej osoby zgodnie z potwierdzonym komunikatem UI; exact scope blokady pozostaje konfigurowalny do czasu pełnej weryfikacji.

### AC-EXAM-ACCESS-07 — tenant security
Nie można wygenerować ani uruchomić egzaminu dla kursanta innego OSK przez podmianę identyfikatora.
