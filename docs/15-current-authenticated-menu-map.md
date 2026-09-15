# 15. Aktualne menu zalogowanego panelu OSK — mapa tras i statusów

Data konsolidacji: 2026-09-05

> Ten dokument mapuje **bieżące pozycje menu**, a nie pełny kontrakt implementacyjny. Dla szczegółów ekranów używamy `specs/screens/*.yml`, `docs/71-admin-osk-module-mapping-status.md` i `specs/implementation-baseline-v1.yml`.

## Statusy

- `READY_FOR_IMPLEMENTATION` — ekran/flow core wystarczająco zmapowany do budowy własnego odpowiednika,
- `NOT_SCREEN_MAPPED` — znamy domenę/menu, ale nie wykonaliśmy pełnego audytu zalogowanego UI,
- `USER_CONFIRMED_AUTH_MENU` — pozycja menu/route potwierdzona przez aktualny zalogowany panel.

---

## 1. Panel główny

**Route:** `/`  
**Menu:** `Panel główny`  
**Status:** `USER_CONFIRMED_AUTH_MENU / READY_FOR_IMPLEMENTATION`

Potwierdzone widgety:
- Licencje,
- Egzaminy wewnętrzne,
- Powiadomienia/activity feed,
- Kalendarz.

Spec: `specs/screens/main-dashboard.yml`  
Doc: `docs/74-main-dashboard.md`

---

## 2. Integracja PKK

**Route:** `/integracja-pkk`  
**Status:** `USER_CONFIRMED_AUTH_MENU / READY_FOR_IMPLEMENTATION`

`/integracja-pkk` jest ekranem informacyjno-nawigacyjnym. Operacyjna obsługa PKK odbywa się przy konkretnym `CourseEnrollment` kursanta.

Potwierdzone:
- pobierz PKK,
- podgląd PKK,
- aktualizuj szkolenie i zwróć,
- zwrot do innego OSK,
- zwrot do urzędu,
- zwrot profilu przedawnionego,
- historia operacji.

Specy: `pkk-*.yml`  
Docs: `docs/75-77`.

---

## 3. Kalendarz

**Route:** `/kalendarz`  
**Status:** `USER_CONFIRMED_AUTH_MENU / READY_FOR_IMPLEMENTATION`

Potwierdzone:
- miesiąc/tydzień/dzień,
- filtry typów,
- zasoby pracownik/pojazd/lokalizacja,
- formularz dodania wydarzenia/jazdy,
- kursant, instruktor, pojazd, miejsce,
- własne miejsce spotkania.

Specy: `specs/screens/calendar*.yml`.

---

## 4. Kursanci

**Route:** `/kursanci`  
**Status:** `USER_CONFIRMED_AUTH_MENU / READY_FOR_IMPLEMENTATION`

Potwierdzone:
- lista,
- search/filter/sort,
- podgląd,
- create/edit,
- profil kursanta,
- etapy szkolenia,
- kursy (PKK),
- licencje,
- learning access,
- płatności kursanta,
- postępy,
- egzamin wewnętrzny,
- archiwizacja/usuwanie jako widoczny flow.

Aggregate spec: `specs/screens/students.yml`.

---

# Licencje

## 5. Wykup licencje

**Route:** `/licencje/wykup`  
**Status:** `USER_CONFIRMED_AUTH_MENU / READY_FOR_IMPLEMENTATION`

Potwierdzone:
- warianty 31/90/180 dni,
- quantity,
- mixed cart,
- cena/rabat dynamiczny,
- PayU/przelew,
- podsumowanie.

## 6. Panel — Generuj licencje

**Route:** `/licencje/panel`  
**Status:** `USER_CONFIRMED_AUTH_MENU / READY_FOR_IMPLEMENTATION`

Potwierdzone:
- inventory,
- przydział,
- istniejący/nowy learning account,
- język,
- aktywacja,
- revoke przed aktywacją,
- restore inventory,
- historia/rozwinięcie,
- sortowanie,
- PDF dostępów.

---

# Egzamin wewnętrzny

## 7. Wykup egzaminy

**Route:** `/egzamin-wewnetrzny/wykup`  
**Status:** `USER_CONFIRMED_AUTH_MENU / READY_FOR_IMPLEMENTATION`

Potwierdzone:
- zakup sztuk,
- pule darmowe/opłacone,
- cena dynamiczna,
- PayU/przelew.

## 8. Panel — Generuj egzamin

**Route:** `/egzamin-wewnetrzny/panel`  
**Status:** `USER_CONFIRMED_AUTH_MENU / READY_FOR_IMPLEMENTATION`

Potwierdzone:
- lista/agregat kursantów,
- wyszukiwanie,
- filtrowanie,
- sortowanie,
- generowanie,
- remote link,
- local station,
- edycja danych kandydata,
- historia prób,
- wynik,
- review pytań,
- PDF arkusza.

Własny formalny model wymaga `Student + CourseEnrollment`.

---

## 9. Moje wizytówki

**Route:** `/wizytowki`  
**Status:** `USER_CONFIRMED_AUTH_MENU / NOT_SCREEN_MAPPED`

Domena/publiczna funkcja znana. Nie blokuje core v1.

---

## 10. Moje reklamy

**Menu group:** `Moje reklamy`  
**Status:** `USER_CONFIRMED_AUTH_MENU / NOT_SCREEN_MAPPED`

Domena aukcji/reklam jest opisana historycznie/publicznie, ale bieżące zalogowane child routes nie zostały zmapowane ekranowo.

Nie blokuje core v1.

---

## 11. Wykłady

**Route:** `/wyklady`  
**Status:** `USER_CONFIRMED_AUTH_MENU / NOT_SCREEN_MAPPED`

Produkt/funkcja znane publicznie; brak pełnego bieżącego screen mapping.

---

## 12. Szkolenie z instruktorem

**Route:** `/szkolenie-z-instruktorem`  
**Status:** `USER_CONFIRMED_AUTH_MENU / NOT_SCREEN_MAPPED`

Znane ogólne działanie produktu; brak pełnego audytu bieżącego zalogowanego UI.

---

# Moja szkoła

## 13. Lokalizacje

**Route:** `/lokalizacje`  
**Status:** `USER_CONFIRMED_AUTH_MENU / READY_FOR_IMPLEMENTATION`

Potwierdzone:
- lista,
- create,
- edit,
- typy `Filia`, `Sala wykładowa`, `Plac manewrowy`,
- nazwa,
- ulica i nr,
- kod pocztowy,
- wyszukiwana miejscowość,
- link do kalendarza,
- widoczna archiwizacja (demo-blocked).

Specy: `locations.yml`, `school-locations-*.yml`.

## 14. Pojazdy

**Route:** `/pojazdy`  
**Status:** `USER_CONFIRMED_AUTH_MENU / READY_FOR_IMPLEMENTATION`

Potwierdzone:
- lista/create/detail/edit/delete confirmation,
- rejestracja, nr boczny, marka/model/rok/pojemność/VIN,
- kategorie/lokalizacje,
- przegląd/OC/AC,
- zdjęcie,
- kalendarz,
- archiwizacja widoczna/demo-blocked.

## 15. Pracownicy

**Route:** `/pracownicy`  
**Status:** `USER_CONFIRMED_AUTH_MENU / READY_FOR_IMPLEMENTATION`

Potwierdzone:
- lista/create/detail/edit,
- rodzaj pracownika,
- PESEL/telefon,
- numer uprawnień,
- kategorie/lokalizacje,
- terminy dokumentów,
- zdjęcie,
- opcjonalne konto panelowe,
- kalendarz.

Własny RBAC jest permission-based.

---

# Konto OSK

## 16. Ustawienia

**Route:** `/ustawienia`  
**Status:** `USER_CONFIRMED_AUTH_MENU / READY_FOR_IMPLEMENTATION`

Potwierdzone:
- dane podstawowe,
- dane firmy,
- konfiguracja PKK,
- nazwa szkoły,
- nr ewidencyjny OSK,
- login OSK,
- zaakceptowany regulamin.

## 17. Historia zakupów

**Route:** `/historia-zakupow`  
**Status:** `USER_CONFIRMED_AUTH_MENU / READY_FOR_IMPLEMENTATION`

Potwierdzone:
- zamówienia,
- pozycje,
- data,
- data księgowania,
- kwota,
- status,
- `Opłać` dla nieopłaconych,
- paginacja/liczba pozycji.

To jest ledger zakupów OSK na platformie, nie płatności kursanta.

---

# Wniosek

Aktualne menu zawiera 17 pozycji/grup opisanych wyżej.

Core gotowy do implementacji:

`Panel główny + PKK + Kalendarz + Kursanci + Licencje + Egzaminy + Lokalizacje + Pojazdy + Pracownicy + Ustawienia + Historia zakupów`.

Poza core pozostają:

`Moje wizytówki + Moje reklamy + Wykłady + Szkolenie z instruktorem`.
