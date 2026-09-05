# 52. Licencje — Wykup licencje

Data weryfikacji: 2026-09-05

**Route:** `/licencje/wykup`  
**Kontekst:** `Licencje -> Wykup licencje`  
**Źródło:** bieżący zalogowany ekran + screenshot przekazany podczas audytu  
**Status:** `USER_CONFIRMED_AUTH_SCREEN`

---

## 1. Znaczenie ekranu

Ekran służy do zakupu puli licencji przez OSK.

Zakup puli jest oddzielnym procesem od późniejszego:
- tworzenia dostępu kursanta,
- przypisania licencji kursantowi,
- aktywacji licencji.

Własny model musi rozdzielać:

`license_order -> paid license inventory -> license assignment -> activation`

---

## 2. Warianty licencji

Zaobserwowane trzy warianty czasu dostępu:
- `1 miesiąc`,
- `3 miesiące`,
- `6 miesięcy`.

Każdy wariant ma:
- widoczną cenę bazową/przekreśloną,
- widoczną cenę po rabacie,
- pole ilości licencji,
- bieżącą wartość ceny pozycji,
- poziomy slider-like quantity control / regulator ilości widoczny w UI.

### Ceny zaobserwowane na koncie demonstracyjnym

- 1 miesiąc: `29.00 zł` -> `14.50 zł`,
- 3 miesiące: `38.00 zł` -> `19.00 zł`,
- 6 miesięcy: `59.00 zł` -> `29.50 zł`.

Na ekranie podsumowania widoczny był rabat `50.00%`.

### Ważna granica

Nie traktujemy:
- powyższych cen,
- rabatu 50%,
- limitów ilości

jako stałych reguł produktu.

To wartości obserwowane dla konkretnego bieżącego kontekstu konta/oferty. Cennik i rabaty muszą być konfigurowalne.

---

## 3. Koszyk / wielowariantowe zamówienie

Ekran pozwala ustawić ilości niezależnie dla wszystkich trzech wariantów.

To wspiera model jednego zamówienia zawierającego wiele pozycji, np.:
- 5 x licencja 1 miesiąc,
- 8 x licencja 3 miesiące,
- 9 x licencja 6 miesięcy.

Jest to spójne z wcześniej zaobserwowaną `Historią zakupów`, gdzie jedno zamówienie mogło zawierać kilka wariantów licencji.

Rekomendowany model:
- `orders`,
- `order_items`,
- `license_products`,
- `license_inventory_entries` po opłaceniu/zaksięgowaniu.

---

## 4. Podsumowanie

Prawy panel `Podsumowanie` zawiera:
- `Liczba pakietów`,
- `Cena z VAT`,
- `Rabat <percent>`,
- wartość rabatu,
- `Do zapłaty`.

W obserwowanym stanie początkowym:
- liczba pakietów: 0,
- cena z VAT: 0 zł,
- rabat: 50.00%,
- do zapłaty: 0 zł.

Wniosek dla naszego produktu:
- sumy muszą być wyliczane server-side z aktualnego cennika i reguł rabatowych,
- frontend może pokazywać podgląd, ale nie jest źródłem prawdy dla wartości zamówienia.

---

## 5. Metody płatności

Potwierdzone opcje radio:
- `Płatności online PayU`,
- `Przelew bezpośredni`.

Potwierdzona akcja:
- `Kup teraz`.

Nie potwierdzono w demo:
- dalszego ekranu PayU,
- danych do przelewu,
- momentu utworzenia zamówienia,
- momentu dodania licencji do puli,
- zachowania przy anulowanej/nieudanej płatności.

Te elementy pozostają `UNOBSERVABLE_OR_TO_VERIFY`, ale nie blokują własnej architektury płatności.

---

## 6. Zakres produktu licencyjnego widoczny na ekranie

Ekran opisuje, że licencja obejmuje m.in.:
- pełną bazę pytań na prawo jazdy,
- aplikacje mobilne iOS / Android / Huawei,
- oficjalne testy, kurs oraz statystyki,
- szkolenia online z instruktorem i ratownikami medycznymi,
- podręcznik kursanta z lektorem,
- wykłady z lektorem oraz statystyki,
- pytania jak na egzaminie WORD,
- obsługę kategorii A, B, C, D, T, AM, A1, A2, B1, C1, D1,
- możliwość przydzielania dostępów w 4 językach.

Zaobserwowane języki:
- Polski,
- Angielski,
- Niemiecki,
- Ukraiński.

### Ważna granica

Treści marketingowych i liczbowych (`ponad 8 godzin`, `18 działów`, `ponad 70 stron`, `ponad 750...`) nie należy traktować jako niezmiennej struktury technicznej.

Powinny pochodzić z katalogu/CMS i mogą zmieniać się w czasie.

---

## 7. Obsługa większych wolumenów

Ekran zawiera osobny box informacyjny zachęcający do kontaktu z obsługą klienta kluczowego w celu zwiększenia liczby licencji.

Potwierdza to istnienie co najmniej procesu handlowego dla większych wolumenów.

Nie potwierdzono:
- dokładnego limitu samoobsługowego,
- czy limit jest techniczny, handlowy czy zależny od konta,
- mechanizmu indywidualnej ceny.

Dla naszego produktu rekomendowane:
- standardowy self-service purchase,
- opcjonalne account-level pricing / rabat,
- możliwość ręcznej oferty dla dużego OSK,
- brak hardkodowanego publicznego limitu bez biznesowej potrzeby.

---

## 8. Model cen i rabatów dla naszego produktu

Rekomendowane encje:
- `license_products`
  - duration_days / duration_months,
  - base_price_gross,
  - active,
- `price_lists`,
- `organization_price_rules`,
- `discount_rules`,
- `orders`,
- `order_items`,
- `payments`,
- `license_inventory_entries`.

Reguła:
- order item zapisuje snapshot ceny, VAT i rabatu z momentu zakupu,
- późniejsza zmiana cennika nie może zmieniać historii zamówienia.

---

## 9. Potwierdzone akcje

- `open_license_purchase`
- `set_one_month_license_quantity`
- `set_three_month_license_quantity`
- `set_six_month_license_quantity`
- `select_online_payment`
- `select_direct_bank_transfer`
- `submit_license_purchase`
- `view_order_summary`

---

## 10. Wymagania bezpieczeństwa i spójności

- tenant-scoped order,
- ceny i rabaty liczone server-side,
- nie ufać wartościom ceny przesłanym z frontendu,
- idempotentne utworzenie zamówienia,
- idempotentne księgowanie płatności,
- pula licencji zwiększana dokładnie raz,
- pełny audit zmian stanu zamówienia i płatności,
- snapshot VAT/ceny/rabatu na pozycji zamówienia,
- brak dodania inventory przy niepotwierdzonej płatności, chyba że świadomie wspieramy kredyt kupiecki/przelew z odroczonym księgowaniem.

---

## 11. Pozostałe niewiadome

- maksymalna ilość w self-service,
- step/max slidera ilości,
- dokładne reguły rabatu,
- zachowanie po `Kup teraz`,
- PayU redirect/callback UX,
- dane i instrukcja dla przelewu bezpośredniego,
- statusy oczekującej płatności,
- anulowanie zamówienia,
- moment udostępnienia inventory przy przelewie,
- dokument sprzedaży/faktura dla tego flow.

Nie są to luki blokujące własny projekt; można je zaprojektować zgodnie z naszym payment/order lifecycle.
