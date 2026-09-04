# 28. Pojazdy — potwierdzenie usunięcia pojazdu

Data weryfikacji: 2026-09-05

**Kontekst:** akcja `Usuń` na ekranie szczegółów pojazdu `/pojazdy/<vehicle_uuid>`  
**Źródło:** bieżący zalogowany ekran + screenshot przekazany podczas audytu  
**Status:** `USER_CONFIRMED_AUTH_SCREEN`

Dane konkretnego pojazdu z konta demonstracyjnego nie są przepisywane jako dane referencyjne produktu.

---

## 1. Forma UI

Akcja `Usuń` otwiera osobny modal/drawer potwierdzający zatytułowany:
- `Usuwanie pojazdu`

Widoczna jest również akcja zamknięcia `X`.

Nie kopiujemy layoutu 1:1; odwzorowujemy zachowanie i bezpieczeństwo procesu.

---

## 2. Treść potwierdzenia

Potwierdzony komunikat semantyczny:
- użytkownik jest pytany, czy na pewno chce usunąć pojazd ze swojej organizacji.

Modal pokazuje dane identyfikujące rekord przed wykonaniem destrukcyjnej operacji:
- numer rejestracyjny,
- marka i model,
- kategorie obsługiwane przez pojazd.

Na obserwowanym ekranie występuje etykieta `Maria i model`, którą traktujemy jako literówkę/błąd prezentacyjny konkurenta. W naszym UI używamy poprawnej etykiety `Marka i model`.

---

## 3. Potwierdzone akcje

- `confirm_delete_vehicle` — przycisk `Tak, usuń`
- `cancel_delete_vehicle` — przycisk `Nie, anuluj`
- `close_delete_vehicle_modal` — `X`

To potwierdza, że usunięcie nie jest wykonywane natychmiast po kliknięciu `Usuń`; wymaga osobnego potwierdzenia użytkownika.

---

## 4. Potwierdzony flow

`vehicle_detail -> click_delete -> delete_confirmation -> confirm | cancel`

### Cancel
Po `Nie, anuluj` operacja nie powinna zmieniać rekordu.

### Confirm
Po `Tak, usuń` system inicjuje operację usunięcia.

Nie znamy jeszcze końcowej semantyki backendowej:
- hard delete,
- soft delete,
- usunięcie powiązania z organizacją,
- blokada przy istniejących wydarzeniach,
- automatyczna archiwizacja zamiast fizycznego usunięcia.

Dlatego status backend semantics pozostaje `TO_VERIFY_AFTER_CONFIRM`.

---

## 5. Wniosek architektoniczny dla naszego produktu

Ze względu na powiązania pojazdu z:
- kalendarzem,
- historią jazd,
- dokumentami/terminami,
- audytem,

nasza implementacja nie powinna fizycznie usuwać danych historycznych bez kontroli zależności.

Rekomendowane zachowanie:
1. sprawdź tenant ownership,
2. sprawdź zależności historyczne i przyszłe,
3. jeśli są zależności wymagające zachowania historii — soft-delete lub archiwizacja,
4. usuń/wyłącz przyszłą dostępność zasobu zgodnie z regułami,
5. zapisz audit event,
6. zwróć jednoznaczny rezultat operacji.

To jest rekomendacja naszego produktu; nie przypisujemy tego mechanizmu konkurentowi bez obserwacji po kliknięciu `Tak, usuń`.

---

## 6. Acceptance criteria

### AC-VEH-DEL-01 — confirmation required
Kliknięcie `Usuń` nie usuwa pojazdu natychmiast; system wymaga potwierdzenia.

### AC-VEH-DEL-02 — record identity
Modal pokazuje dane pozwalające użytkownikowi zweryfikować, który pojazd usuwa.

### AC-VEH-DEL-03 — cancel
`Nie, anuluj` oraz zamknięcie `X` nie zmieniają rekordu.

### AC-VEH-DEL-04 — confirm
`Tak, usuń` uruchamia destrukcyjną operację dopiero po potwierdzeniu.

### AC-VEH-DEL-05 — tenant safety
Nie można potwierdzić usunięcia pojazdu należącego do innego OSK przez podmianę identyfikatora.

### AC-VEH-DEL-06 — history safety
Nasza implementacja nie może utracić historycznych wydarzeń i audytu wskutek prostego usunięcia zasobu.

---

## 7. Pozostałe niewiadome

- rezultat po `Tak, usuń`,
- hard vs soft delete,
- zachowanie przy istniejących przyszłych wydarzeniach,
- zachowanie przy historycznych wydarzeniach,
- komunikat sukcesu,
- komunikaty błędów,
- możliwość przywrócenia po usunięciu.
