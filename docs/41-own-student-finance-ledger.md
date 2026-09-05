# 41. Własny produkt — rozliczenia kursanta: należności, wpłaty i raty

Data decyzji: 2026-09-05

**Zakres:** panel administracyjny OSK / karta kursanta  
**Status:** `OWN_PRODUCT_DESIGN_DECISION`  
**Powód:** panel demonstracyjny konkurenta nie pozwala przejść przez zapis pozycji i obserwować późniejszego dopisywania rat. Nieznany szczegół konkurenta nie blokuje naszego projektu.

Ta decyzja opisuje **nasz produkt**, a nie potwierdzone zachowanie konkurenta.

---

## 1. Zasada główna

Rozdzielamy dwa pojęcia:

1. **Należność** — kwota, którą kursant ma zapłacić.
2. **Wpłata** — faktycznie otrzymane pieniądze.

Nie używamy jednego rekordu `payment` zarówno do określania ceny kursu, jak i do rejestrowania rat.

Przykład:

- należność: `Kurs kat. B` — 4000 zł,
- wpłata 1: 1000 zł,
- wpłata 2: 500 zł,
- wpłacono: 1500 zł,
- pozostało: 2500 zł.

---

## 2. UX sekretariatu

Na karcie kursanta sekcja finansowa powinna pokazywać co najmniej:

- `Do zapłaty`,
- `Wpłacono`,
- `Pozostało`,
- listę należności,
- historię wpłat.

Podstawowe akcje:

- `Dodaj należność`,
- `Dodaj wpłatę`,
- `Historia wpłat`.

### Dodaj należność
Minimalne pola MVP:

- Tytuł *
- Kwota *
- Powiązany kurs — opcjonalnie
- Termin płatności — opcjonalnie

### Dodaj wpłatę
Minimalne pola MVP:

- Należność *
- Kwota wpłaty *
- Data wpłaty * — domyślnie bieżąca data/czas
- Metoda płatności — opcjonalnie/konfigurowalnie
- Notatka — opcjonalnie

Rekomendowane metody płatności:

- gotówka,
- przelew,
- karta,
- inne.

Słownik metod powinien być konfigurowalny zamiast zaszyty na stałe.

---

## 3. Model domenowy

### `student_charges`

- id
- organization_id
- student_id
- course_id nullable
- title
- original_amount
- currency
- due_at nullable
- status
- created_by
- created_at
- cancelled_at nullable

Status wyliczany lub utrzymywany deterministycznie:

- `open`
- `partially_paid`
- `paid`
- `cancelled`
- opcjonalnie `overdue`

### `student_payments`

- id
- organization_id
- student_id
- charge_id
- amount
- paid_at
- payment_method nullable
- note nullable
- received_by
- created_at
- reversed_at nullable
- reversal_reason nullable

Dla MVP jedna wpłata jest przypisana do jednej należności. Model można później rozszerzyć o `payment_allocations`, jeśli jedna wpłata ma pokrywać wiele należności.

---

## 4. Saldo jako projekcja

Nie zapisujemy ręcznie edytowanego `remaining` jako źródła prawdy.

Dla należności:

`paid_amount = suma ważnych wpłat`

`remaining_amount = original_amount - paid_amount`

Dla całego kursanta:

`total_due = suma aktywnych należności`

`total_paid = suma ważnych wpłat`

`total_remaining = total_due - total_paid`

Widoczne wartości są projekcją danych źródłowych.

---

## 5. Raty

Jedna należność może mieć dowolną liczbę wpłat częściowych.

Nie wymagamy sztywnego harmonogramu rat w MVP.

Przykład:

- 4000 zł należności,
- 500 zł wpłaty,
- 1000 zł wpłaty,
- 250 zł wpłaty,
- pozostało 2250 zł.

Każda wpłata zachowuje:

- kwotę,
- datę,
- osobę rejestrującą,
- opcjonalną metodę płatności,
- opcjonalną notatkę.

---

## 6. Nadpłata

Dla MVP rekomendacja:

- domyślnie nie pozwalamy wpisać wpłaty większej niż pozostała kwota należności,
- UI pokazuje aktualne `Pozostało`,
- jeśli w przyszłości potrzebujemy nadpłat, dokładamy osobny mechanizm `student_credit` zamiast tworzyć ujemne saldo przypadkowo.

Reguła musi być sprawdzana po stronie serwera.

---

## 7. Korekty i pomyłki

Operacji finansowych nie kasujemy bez śladu.

Jeśli sekretarka pomyli się przy wpłacie:

- wpłatę oznaczamy jako `reversed`,
- zapisujemy powód korekty,
- tworzymy audit event,
- saldo przelicza się ponownie.

Analogicznie należność z historią wpłat nie powinna być dowolnie usuwana. Jej anulowanie jest audytowaną zmianą statusu.

---

## 8. Powiązanie z kosztem kursu

Formularz kursu ma pole `Koszt`.

Dla naszego produktu rekomendujemy wygodny wariant:

- przy zapisie kursu z kosztem można zaznaczyć `Utwórz należność na kwotę kursu`,
- system tworzy `student_charge` powiązany z `course_id`,
- późniejsze raty są rejestrowane jako `student_payments`.

Nie tworzymy należności drugi raz, jeżeli operacja zostanie ponowiona/retryowana — wymagana idempotentność.

Automatyczne tworzenie należności powinno być możliwe do wyłączenia przez OSK lub przez użytkownika formularza, jeśli workflow tego wymaga.

---

## 9. Uprawnienia

Minimalne permissions:

- `student_finance.view`
- `student_finance.create_charge`
- `student_finance.record_payment`
- `student_finance.reverse_payment`
- `student_finance.cancel_charge`

Nie zakładamy, że każdy instruktor może edytować finanse kursanta.

Typowo uprawnienie dostanie właściciel i administracja/biuro.

---

## 10. Audit

Każda operacja finansowa zapisuje:

- organization_id,
- student_id,
- wykonującego użytkownika,
- typ operacji,
- poprzedni i nowy stan tam, gdzie dotyczy,
- timestamp,
- opcjonalny powód korekty.

Obowiązkowo audytujemy:

- utworzenie należności,
- anulowanie należności,
- dodanie wpłaty,
- cofnięcie/korektę wpłaty.

---

## 11. Bezpieczeństwo i integralność

- pełna tenant isolation,
- operacje finansowe wykonywane transakcyjnie,
- kwoty przechowywane jako decimal/minor units, nigdy float,
- waluta jawna (`PLN` jako domyślna dla MVP),
- ochrona przed podwójnym zapisem przy retry,
- saldo liczone z ważnych rekordów, nie aktualizowane przez frontend,
- żadnego hard-delete historii finansowej po zaksięgowaniu wpłaty.

---

## 12. Decyzja MVP

W MVP wdrażamy:

1. `Dodaj należność`,
2. `Dodaj wpłatę`,
3. wiele wpłat do jednej należności,
4. automatyczne `Wpłacono` i `Pozostało`,
5. łączne saldo kursanta,
6. opcjonalne powiązanie należności z kursem,
7. korektę wpłaty przez reversal, nie delete,
8. audit,
9. brak nadpłat na pojedynczej należności w MVP,
10. osobne uprawnienia finansowe.

---

## 13. Co przestaje być blokującą niewiadomą

Nie musimy już znać dokładnego zachowania konkurenta w zakresie:

- czy jego `Dodaj płatność` tworzy należność czy wpłatę,
- gdzie dokładnie dopisuje się ratę,
- jak obsługuje częściowe wpłaty,
- jak liczy saldo,
- jak obsługuje korekty.

Te elementy są od teraz jawnymi decyzjami naszego produktu i nie blokują implementacji.