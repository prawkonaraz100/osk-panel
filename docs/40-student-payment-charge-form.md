# 40. Kursanci — formularz „Dodaj płatność” / należność kursanta

Data weryfikacji: 2026-09-05

**Kontekst:** karta kursanta -> sekcja `Płatności` -> `Dodaj płatność`  
**Źródło:** bieżący zalogowany ekran + screenshot przekazany podczas audytu  
**Status:** `USER_CONFIRMED_AUTH_SCREEN_WITH_DOMAIN_INTERPRETATION`

---

## 1. Potwierdzony ekran

Drawer ma tytuł:
- `Dodaj płatność`.

Potwierdzone pola:
1. `Tytuł *`
2. `Kwota *`
   - waluta renderowana jako `zł`.

Potwierdzone akcje:
- `Zapisz`,
- `Anuluj`,
- zamknięcie `X`.

Oba pola mają widoczny marker wymagania `*`.

---

## 2. Kontekst z karty kursanta

Na karcie kursanta sekcja `Płatności` ma potwierdzone kolumny:
- `Tytuł`,
- `Kwota`,
- `Pozostało`,
- `Wpłaty`,
- `Data dodania`,

oraz podsumowanie:
- `Łączne saldo`.

Ta struktura jest kluczowa dla interpretacji funkcji formularza.

---

## 3. Najbardziej prawdopodobna semantyka

Pomimo etykiety UI `Dodaj płatność`, połączenie formularza `Tytuł + Kwota` z tabelą zawierającą osobno `Kwota`, `Pozostało` i `Wpłaty` silnie sugeruje, że formularz tworzy **należność / zobowiązanie kursanta**, a nie pojedynczą zarejestrowaną wpłatę raty.

Przykład interpretacji:
- Tytuł: `Kurs kat. B`,
- Kwota: `3500 zł`,
- Pozostało: `3500 zł`,
- Wpłaty: brak na początku.

Następnie do tej należności mogą być dopisywane kolejne rzeczywiste wpłaty kursanta, np.:
- 1000 zł,
- 1000 zł,
- 1500 zł,

a `Pozostało` maleje do zera.

### Poziom pewności
To jest **silna interpretacja domenowa na podstawie struktury UI**, ale nie została jeszcze bezpośrednio potwierdzona ekranem istniejącej pozycji płatności z wpłatami.

Status:
- `STRONGLY_INFERRED_FROM_TABLE_STRUCTURE`.

Nie zapisujemy jako faktu konkurenta, że `Zapisz` oznacza utworzenie należności, dopóki nie zobaczymy wypełnionej pozycji lub akcji dopisywania wpłaty.

---

## 4. Wniosek dla naszego produktu

Dla naszego panelu warto rozdzielić pojęcia jasno i poprawnie księgowo-operacyjnie:

### `student_charge` / należność
Pozycja określająca, ile kursant ma zapłacić.

Minimalne pola:
- id,
- organization_id,
- student_id,
- title,
- original_amount,
- currency,
- outstanding_amount (projekcja),
- status,
- created_at,
- created_by.

### `student_payment` / wpłata
Faktyczna wpłata kursanta na poczet należności.

Minimalne pola:
- id,
- organization_id,
- student_id,
- charge_id,
- amount,
- paid_at,
- payment_method nullable,
- note nullable,
- received_by,
- created_at.

### Saldo
`student_balance` powinno być projekcją:

`sum(charges) - sum(valid payments/credits)`

Nie przechowujemy ręcznie edytowanego pola `saldo` jako jedynego źródła prawdy.

---

## 5. Raty

Model powinien naturalnie wspierać spłatę kursu w ratach.

Przykład:
- należność: 3500 zł,
- wpłata 1: 1000 zł,
- wpłata 2: 500 zł,
- pozostało: 2000 zł.

Nie trzeba z góry definiować sztywnego harmonogramu rat, chyba że produkt później będzie tego wymagał.

Możemy opcjonalnie rozbudować model o:
- termin płatności,
- harmonogram rat,
- status `overdue`,
- przypomnienia,
- numer dokumentu/rachunku,
- metodę płatności,
- zwroty/korekty.

---

## 6. UX naszego produktu

Dla uniknięcia niejasności lepiej nie kopiować nazwy `Dodaj płatność` do czynności tworzącej należność.

Rekomendowane nazwy:
- `Dodaj należność` — tworzy kwotę do zapłaty,
- `Dodaj wpłatę` — rejestruje faktycznie otrzymaną ratę/wpłatę.

Na karcie kursanta można wtedy pokazać:
- Do zapłaty,
- Wpłacono,
- Pozostało,
- historię wpłat,
- saldo całkowite.

To jest czytelniejsze dla sekretariatu OSK.

---

## 7. Relacja z kosztem kursu

Formularz `Dodaj kurs` posiada osobne pole `Koszt`.

Nie potwierdzono jeszcze, czy konkurent automatycznie tworzy pozycję w sekcji `Płatności` po zapisaniu kursu z kosztem.

W naszym produkcie rekomendowana opcja:
- przy zapisie kursu z kosztem administrator może mieć zaznaczone `Utwórz należność na kwotę kursu`,
- system tworzy `student_charge` transakcyjnie,
- późniejsze raty są dopisywane jako `student_payment`.

Nie należy jednak automatyzować tego bez jawnej reguły produktu.

---

## 8. Potwierdzone akcje

- `open_add_student_payment_drawer`
- `set_payment_or_charge_title`
- `set_payment_or_charge_amount`
- `save_payment_or_charge`
- `cancel_payment_or_charge`
- `close_payment_or_charge_drawer`

Semantyka biznesowa `payment_or_charge` pozostaje do pełnego potwierdzenia na podstawie ekranu z istniejącą pozycją.

---

## 9. Pozostałe niewiadome

- czy formularz tworzy należność czy faktyczną wpłatę,
- ekran istniejącej pozycji,
- sposób dodawania kolejnych wpłat/rat,
- możliwość częściowej wpłaty,
- możliwość nadpłaty,
- korekta/anulowanie wpłaty,
- zwrot pieniędzy,
- metoda płatności,
- data faktycznej wpłaty,
- powiązanie z konkretnym kursem,
- automatyczne utworzenie należności z pola `Koszt` kursu,
- success/error messages.

---

## 10. Acceptance criteria dla naszego produktu

### AC-FIN-01 — separate charge and payment
Należność kursanta i faktyczna wpłata są oddzielnymi rekordami.

### AC-FIN-02 — installments
Jedna należność może mieć wiele częściowych wpłat.

### AC-FIN-03 — balance
Pozostała kwota i łączne saldo są wyliczane z ledgera, a nie ręcznie edytowane.

### AC-FIN-04 — audit
Każda należność, wpłata, korekta i zwrot mają audit trail.

### AC-FIN-05 — tenant isolation
Operacje finansowe można wykonywać wyłącznie na kursantach bieżącego OSK.