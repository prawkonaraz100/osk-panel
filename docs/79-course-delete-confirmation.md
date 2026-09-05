# 79. Usuwanie kursu — potwierdzenie i zależności

Data weryfikacji: 2026-09-05

**Kontekst:** `Kursanci -> Profil kursanta -> Kursy (PKK) -> Usuń`  
**Źródło:** bieżący zalogowany ekran przekazany podczas audytu  
**Status:** `USER_CONFIRMED_AUTH_SCREEN`

## 1. Potwierdzony ekran

Po kliknięciu `Usuń` przy kursie otwierany jest drawer/modal `Usuwanie kursu` z pytaniem:

`Czy na pewno chcesz usunąć kurs z kursanta?`

W potwierdzeniu prezentowane są dane zależne od usuwanego kursu:
- `PKK`,
- `Rodzaj`,
- `Przypisana płatność`,
- `Wpłaty`.

Zaobserwowany przykład:
- PKK: `445645645646455`,
- Rodzaj: `Szkolenie podstawowe`,
- Przypisana płatność: `4000 zł`,
- Wpłaty: `Brak`.

Akcje:
- `Tak, usuń`,
- `Nie, anuluj`.

## 2. Wniosek domenowy

Usunięcie kursu nie jest traktowane jako proste skasowanie jednego rekordu bez kontekstu. UI przed usunięciem pokazuje zależności finansowe kursu, co wskazuje, że operator ma zostać ostrzeżony o skutkach usunięcia.

Kurs może być powiązany co najmniej z:
- numerem PKK,
- przypisaną płatnością / należnością,
- wpłatami,
- historią PKK,
- etapem szkolenia,
- instruktorem,
- lokalizacją,
- ewidencją godzin.

## 3. Decyzja dla własnego produktu

W naszym systemie operacja widoczna użytkownikowi jako `Usuń kurs` powinna domyślnie być operacją logiczną / kontrolowanym usunięciem, a nie fizycznym kasowaniem danych formalnych.

Rekomendacja:
- kurs nie znika z audytu,
- historyczne płatności i wpłaty nie są kasowane,
- operacje PKK nie są kasowane,
- ewidencja godzin nie jest kasowana,
- zapisujemy `deleted_at` / `cancelled_at`, aktora i powód,
- UI przed potwierdzeniem pokazuje zależności i ostrzeżenia,
- jeżeli kurs ma formalne operacje PKK lub zakończone zajęcia, można wymagać trybu `Korekta/Anulowanie` zamiast prostego usunięcia.

Dokładne zachowanie konkurenta po kliknięciu `Tak, usuń` nie zostało wykonane podczas audytu i pozostaje niepotwierdzone.

## 4. Acceptance criteria

- użytkownik widzi numer PKK i rodzaj kursu przed usunięciem,
- użytkownik widzi powiązaną należność/płatność i wpłaty,
- anulowanie zamyka drawer bez zmian,
- potwierdzenie wymaga świadomej akcji,
- system zapisuje pełny audit trail,
- dane finansowe i formalne nie mogą zostać utracone przez fizyczne cascade delete.
