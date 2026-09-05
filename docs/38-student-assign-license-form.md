# 38. Kursanci — formularz Przydziel licencję

Data weryfikacji: 2026-09-05

**Kontekst:** karta kursanta -> sekcja `Licencje` -> `Przydziel licencje`  
**Źródło:** bieżący zalogowany ekran + dwa screenshoty przekazane podczas audytu  
**Status:** `USER_CONFIRMED_AUTH_SCREEN`

---

## 1. Znaczenie formularza

Formularz `Przydzielanie licencji` przypisuje jedną sztukę licencji z puli OSK do wybranego dostępu kursanta.

Kluczowa obserwacja:
- licencja może zostać przypisana do **istniejącego dostępu** kursanta,
- albo administrator może podczas tego samego flow utworzyć **nowy dostęp**.

To oznacza, że `license_assignment` i `learning_access` są odrębnymi pojęciami domenowymi.

---

## 2. Wybór rodzaju licencji

Potwierdzony element:
- `Wybierz rodzaj licencji` — select.

Na obserwowanym ekranie dostępna była opcja:
- `1 miesiąc`.

Obok selecta system pokazuje:
- `Dostępne: <N>`.

To jest projekcja stanu puli danego wariantu licencji w OSK.

Nie należy hardkodować obserwowanej liczby ani ograniczać modelu do jednego wariantu. Z wcześniejszych ekranów i oferty wiemy, że istnieją także inne okresy licencji.

---

## 3. Istniejący dostęp kursanta

Sekcja:
- `Dostępy do prawo-jazdy-360.pl`.

Zaobserwowano istniejący dostęp pokazany jako:
- login/identyfikator,
- język dostępu.

Przy istniejącym dostępie widoczna jest kontrolka wyboru typu radio.

Po wybraniu istniejącego dostępu administrator może wykonać:
- `Przydziel licencje`.

### Wniosek domenowy
Istniejący `learning_access` może otrzymywać kolejne licencje/okresy dostępu bez konieczności tworzenia nowego loginu.

---

## 4. Dodanie nowego dostępu

Potwierdzona opcja:
- `Dodaj nowy dostęp`.

Po jej wybraniu rozwija się blok:
- `Wprowadź dane dostępu`.

Potwierdzone pola:
1. `Email lub login`
2. `Język`

`Język` jest selectem.

Potwierdzona akcja końcowa:
- `Przydziel licencje`.

### Wniosek domenowy
Nowy dostęp powinien być tworzony jako osobna encja `student_learning_access` / `learning_account`, a następnie licencja jest przypisywana do tego dostępu.

Nie przechowujemy loginu i języka bezpośrednio na rekordzie licencji jako jedynym źródle prawdy.

---

## 5. Confirmed branching

Flow ma co najmniej dwa warianty:

### A. `assign_to_existing_access`
1. wybierz wariant licencji,
2. wybierz istniejący dostęp kursanta,
3. kliknij `Przydziel licencje`.

### B. `create_new_access_and_assign`
1. wybierz wariant licencji,
2. wybierz `Dodaj nowy dostęp`,
3. podaj `Email lub login`,
4. wybierz język,
5. kliknij `Przydziel licencje`.

---

## 6. Relacja z pulą licencji

Widoczny licznik `Dostępne: <N>` potwierdza inventory na poziomie OSK i wariantu licencji.

Własny model powinien rozdzielać:
- `license_product` / wariant okresu,
- `license_inventory_item` lub ledger puli,
- `learning_access`,
- `license_assignment`,
- `license_activation`.

Nie należy pomniejszać prostego licznika po stronie frontendu. Przydzielenie musi być atomowe i sprawdzać stan puli po stronie serwera.

---

## 7. Relacja z wcześniejszym formularzem Dodaj kursanta

Formularz `Dodaj kursanta` również pozwalał od razu przydzielić licencję z polami:
- rodzaj licencji,
- `Email lub login`,
- język.

Obecny ekran potwierdza, że jest to ten sam koncept biznesowy, ale karta istniejącego kursanta dodatkowo pozwala wybrać już istniejący dostęp.

Wniosek dla naszego produktu:
- logika przydzielania licencji powinna być wspólnym serwisem domenowym,
- create-flow kursanta może jedynie wywoływać ten serwis jako krok orkiestracji.

---

## 8. Potwierdzone akcje

- `open_assign_license_drawer`
- `select_license_variant`
- `select_existing_learning_access`
- `select_create_new_learning_access`
- `set_new_learning_identifier_email_or_login`
- `select_new_learning_access_language`
- `assign_license`
- `close_assign_license_drawer`

---

## 9. Wymagania dla naszego produktu

- tenant isolation,
- sprawdzenie, że kursant należy do OSK,
- sprawdzenie, że istniejący dostęp należy do tego kursanta / OSK,
- atomowe sprawdzenie i pobranie sztuki z puli,
- brak podwójnego przydzielenia tej samej sztuki,
- idempotentność requestu,
- audit przydzielenia i ewentualnego cofnięcia,
- rozdzielenie `contact_email` kursanta od `learning_identifier`,
- rozdzielenie `learning_access` od `license_assignment`,
- język należy do dostępu lub kontekstu nauki, nie do danych kontaktowych kursanta.

---

## 10. Czego nadal nie znamy

- pełna lista wariantów licencji w tym konkretnym drawerze,
- pełna lista języków,
- walidacja `Email lub login`,
- czy login musi być globalnie unikalny,
- czy przy wpisaniu e-maila system wysyła zaproszenie,
- jak generowane/ustalane jest hasło,
- komunikat po sukcesie,
- komunikaty błędów,
- co dzieje się przy braku puli w momencie zatwierdzenia,
- czy można wybrać więcej niż jeden istniejący dostęp jednocześnie,
- dokładny moment powstania `license_assignment`,
- dokładny moment aktywacji licencji,
- czy przydzielenie do istniejącego dostępu może przedłużać już aktywną licencję czy tworzy kolejny okres.

---

## 11. Acceptance criteria

### AC-LIC-ASSIGN-01 — inventory projection
Administrator widzi dostępny stan puli dla wybranego wariantu licencji.

### AC-LIC-ASSIGN-02 — existing access
Administrator może przypisać licencję do istniejącego dostępu kursanta.

### AC-LIC-ASSIGN-03 — new access
Administrator może utworzyć nowy dostęp podczas przydzielania licencji, podając `Email lub login` oraz język.

### AC-LIC-ASSIGN-04 — atomic assignment
Przydzielenie jest atomowe: nie można zużyć tej samej sztuki licencji dwa razy.

### AC-LIC-ASSIGN-05 — identity separation
Dane kontaktowe kursanta i identyfikator dostępu do nauki pozostają rozdzielone.

### AC-LIC-ASSIGN-06 — tenant security
Nie można przydzielić licencji do kursanta lub dostępu należącego do innego OSK.
