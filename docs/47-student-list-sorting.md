# 47. Kursanci — Sortowanie listy

Data weryfikacji: 2026-09-05

**Kontekst:** `/kursanci` -> `Sortuj`  
**Źródło:** bieżący zalogowany ekran + screenshot przekazany podczas audytu  
**Status:** `USER_CONFIRMED_AUTH_SCREEN`

## 1. Drawer

Tytuł: `Sortowanie`.

Potwierdzone kontrolki:
- `Kierunek sortowania` — select,
- `Kolumna` — pojedynczy wybór radio,
- przycisk `Sortuj`,
- zamknięcie `X`.

## 2. Kierunek sortowania

Potwierdzone opcje:
- `Rosnąco`,
- `Malejąco`.

W obserwowanym stanie wybrane było:
- `Malejąco`.

Nie traktujemy tego jako trwałej wartości domyślnej aplikacji bez dodatkowego dowodu; jest to stan zaobserwowany.

## 3. Kolumny sortowania

Potwierdzone opcje:
1. `Imię i Nazwisko`
2. `Dane logowania prawo-jazdy-360.pl`
3. `Telefon`
4. `Data dodania`
5. `Egzamin wewnętrzny`

W obserwowanym stanie zaznaczona była:
- `Data dodania`.

## 4. Semantyka dla naszego produktu

Backend listy kursantów powinien wspierać jawne, whitelisted pola sortowania, np.:
- `full_name`,
- `learning_identifier`,
- `phone`,
- `created_at`,
- `internal_exam_projection`.

Kierunek:
- `asc`,
- `desc`.

Nie wolno przekazywać dowolnej nazwy kolumny SQL z klienta.

## 5. Wymagania

- sortowanie wykonywane server-side dla dużych list,
- tenant scope zachowany przed sortowaniem,
- stabilne sortowanie z dodatkowym tie-breakerem, np. `id`,
- sortowanie po projekcji egzaminu wewnętrznego musi mieć jednoznacznie zdefiniowaną kolejność statusów w naszym produkcie,
- stan sortowania może być utrzymany w query string / stanie widoku.

## 6. Pozostałe niewiadome

- czy `Malejąco + Data dodania` jest faktycznym defaultem całego modułu,
- dokładna semantyka kolejności dla `Egzamin wewnętrzny`,
- zachowanie sortowania przy pustych wartościach,
- czy sortowanie utrzymuje się po ponownym wejściu na ekran.
