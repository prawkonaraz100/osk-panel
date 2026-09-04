# 17. Konto OSK — Historia zakupów

Data weryfikacji: 2026-09-05

**Route:** `/historia-zakupow`  
**Źródło:** bieżący zalogowany ekran przekazany podczas audytu  
**Status:** `USER_CONFIRMED_AUTH_SCREEN`

Ten dokument zastępuje wcześniejsze założenie, że dokładne kolumny i podstawowe akcje ekranu `Historia zakupów` są nieznane.

## 1. Cel ekranu

`Historia zakupów` jest wspólną tabelą zamówień OSK. Nie jest ograniczona do jednego rodzaju produktu.

Na potwierdzonym ekranie występują co najmniej:
- egzaminy wewnętrzne,
- licencje kursanta o różnych okresach dostępu.

Jedno zamówienie może zawierać **wiele pozycji produktu** i wiele wariantów tego samego rodzaju produktu.

Przykład strukturalny bez kopiowania danych transakcyjnych:
- Licencje 31 dni, ilość: N,
- Licencje 90 dni, ilość: N,
- Licencje 180 dni, ilość: N.

## 2. Potwierdzone kolumny tabeli

Kolejność widoczna na ekranie:

1. `Number`
2. `Zamówienie`
3. `Data`
4. `Data księgowania`
5. `Kwota`
6. `Status`

Dodatkowo dla zamówienia wymagającego płatności występuje akcja wiersza `Opłać`.

### Semantyka danych

#### Number
- numer zamówienia / identyfikator prezentacyjny,
- liczbowy w obserwowanym widoku,
- nie powinien być technicznym kluczem bazy danych w naszym systemie.

#### Zamówienie
- lista `order_items`,
- każda pozycja zawiera nazwę/typ wariantu produktu oraz ilość,
- jeden order może posiadać kilka pozycji,
- potwierdzone typy: `Egzaminy wewnętrzne`, `Licencje 31 dni`, `Licencje 90 dni`, `Licencje 180 dni`.

#### Data
- data utworzenia/złożenia zamówienia,
- prezentowana w formacie dziennym.

#### Data księgowania
- osobna od daty zamówienia,
- dla zamówień `Nieopłacone` pole może być puste,
- w naszym modelu musi być `nullable`.

#### Kwota
- łączna kwota zamówienia,
- prezentowana w PLN,
- przykłady ekranu potwierdzają kwoty z dokładnością do 2 miejsc oraz kwoty bez części dziesiętnej,
- w bazie przechowujemy pieniądze jako integer minor units albo decimal o kontrolowanej precyzji — nigdy float.

#### Status
Potwierdzony stan:
- `Nieopłacone`.

Innych stanów nie uznajemy jeszcze za potwierdzone przez ten ekran. Projektowo przewidujemy state machine płatności, ale dokładne polskie etykiety 360 dla pozostałych stanów pozostają do zebrania.

## 3. Potwierdzona akcja `Opłać`

Dla zamówienia w stanie `Nieopłacone` dostępny jest link/przycisk:

`Opłać`

Wzorzec docelowej trasy badanego systemu:

`/transakcja?pid=<opaque_payment_id>`

### Wymaganie naszego systemu

Nie używamy numeru zamówienia jako publicznego identyfikatora pozwalającego opłacić dowolne zamówienie.

Powinien istnieć osobny bezpieczny identyfikator płatności / payment intent:
- nieprzewidywalny,
- powiązany z tenantem,
- sprawdzany server-side,
- nie może umożliwiać IDOR,
- płatność musi być idempotentna.

Akcja domenowa:

`pay_unpaid_order(order_id)`

Preconditions:
- order należy do bieżącego OSK,
- order ma saldo do zapłaty,
- status pozwala rozpocząć/ponowić płatność.

Skutek:
- utworzenie albo wznowienie payment attempt,
- przejście do ekranu/operatora płatności,
- order nie może zostać uznany za opłacony wyłącznie na podstawie powrotu użytkownika; finalizacja po wiarygodnym potwierdzeniu operatora / księgowania.

## 4. Paginacja / liczba pozycji

Potwierdzony selektor liczby rekordów:
- 10,
- 25,
- 50,
- 100 pozycji.

Wniosek implementacyjny:
- `per_page` musi obsługiwać co najmniej `[10, 25, 50, 100]`,
- tabela jest stronicowana.

Na końcu przekazanego widoku występuje tekst `12`, który może być renderingiem numerów stron `1 2`; bez obrazu/DOM nie oznaczamy dokładnej postaci kontrolki jako potwierdzonej.

## 5. Potwierdzone właściwości modelu zamówienia

Minimalne encje:

### `orders`
- `id`
- `organization_id`
- `display_number`
- `ordered_at`
- `booked_at` nullable
- `currency` = PLN dla obserwowanego ekranu
- `gross_total_minor`
- `payment_status`
- `created_at`
- `updated_at`

### `order_items`
- `id`
- `order_id`
- `product_type`
- `product_variant`
- `display_name_snapshot`
- `quantity`
- `unit_price_minor`
- `line_total_minor`

Snapshot nazwy i ceny jest ważny: zmiana cennika w przyszłości nie może zmieniać historycznego zamówienia.

### `payment_attempts`
- `id`
- `organization_id`
- `order_id`
- `public_payment_reference`
- `provider`
- `status`
- `amount_minor`
- `started_at`
- `confirmed_at` nullable
- `failed_at` nullable

## 6. Ważne wnioski biznesowe

### Jedna historia dla wielu produktów

Potwierdzony ekran pokazuje w jednej tabeli zarówno licencje, jak i egzaminy. Dlatego u nas `Historia zakupów` powinna być centralnym modułem Commerce, a nie osobnymi tabelami płatności per produkt.

### Wiele SKU w jednym zamówieniu

Jedno zamówienie może zawierać jednocześnie licencje 31/90/180 dni. Koszyk i model `order_items` są więc wymagane.

### Zamówienie może istnieć przed płatnością

Wiele rekordów pozostaje w stanie `Nieopłacone`, a użytkownik może później wejść w `Opłać`. Zamówienia nie wolno usuwać tylko dlatego, że płatność nie nastąpiła natychmiast.

### Data księgowania jest osobnym zdarzeniem

`Data` i `Data księgowania` są osobnymi kolumnami, więc model nie może używać jednego pola timestamp dla utworzenia i zaksięgowania płatności.

## 7. Rekomendowane API naszego odpowiednika

- `GET /api/v1/purchases`
  - paginacja,
  - `per_page` 10/25/50/100,
  - zwraca order + order items.
- `GET /api/v1/purchases/{order}`
- `POST /api/v1/purchases/{order}/payment-attempts`
- `GET /api/v1/purchases/{order}/payment-attempts`
- `POST /api/v1/payments/webhooks/{provider}`

Filtrowanie i wyszukiwanie nie zostały jeszcze potwierdzone na tym ekranie, więc nie opisujemy ich jako parytetu 360.

## 8. Acceptance criteria

### AC-PUR-01 — lista wielu typów produktu
**Given** OSK kupiło licencje i egzaminy  
**When** administrator otworzy `Historia zakupów`  
**Then** oba typy zamówień są dostępne w jednej wspólnej historii.

### AC-PUR-02 — wiele pozycji w zamówieniu
**Given** jeden order zawiera warianty licencji 31/90/180 dni  
**When** jest renderowany w tabeli  
**Then** wszystkie pozycje i ich ilości są pokazane pod jednym numerem zamówienia.

### AC-PUR-03 — nieopłacone
**Given** order nie został opłacony  
**Then** status pokazuje odpowiednik `Nieopłacone`, `booked_at` jest puste i dostępna jest akcja `Opłać`.

### AC-PUR-04 — bezpieczne ponowienie płatności
**Given** administrator wybiera `Opłać`  
**When** system tworzy payment attempt  
**Then** sprawdza tenant ownership i aktualny status orderu, a identyfikator publiczny płatności jest nieprzewidywalny.

### AC-PUR-05 — księgowanie
**Given** operator płatności wiarygodnie potwierdzi płatność  
**When** webhook/reconciliation zostanie przetworzony  
**Then** aktualizowane są `payment_status` i `booked_at`, operacja jest idempotentna i trafia do audytu.

### AC-PUR-06 — page size
Administrator może przełączać liczbę rekordów pomiędzy 10, 25, 50 i 100.

## 9. Co nadal pozostaje do potwierdzenia

Nie znamy jeszcze z przekazanego fragmentu:
- czy kolumny są sortowalne,
- czy ekran posiada wyszukiwarkę,
- czy są filtry po statusie/dacie/produkcie,
- jakie poza `Nieopłacone` etykiety statusów są wyświetlane,
- czy dla opłaconego orderu pojawia się `Szczegóły`, `Faktura`, `Pobierz` lub inna akcja,
- czy nieopłacone zamówienie można anulować/usunąć,
- jak wygląda ekran `/transakcja`,
- czy historia obejmuje reklamy i inne produkty poza licencjami/egzaminami,
- dokładne zachowanie paginacji stron.

Te punkty pozostają `TO_VERIFY_AUTH` i będziemy je zamykać kolejnymi materiałami z panelu.
