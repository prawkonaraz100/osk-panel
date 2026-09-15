# 02. Inwentarz ekranów — stan skonsolidowany

Data konsolidacji: 2026-09-05

> Ten dokument jest agregatem. Dla szczegółów konkretnego ekranu pierwszeństwo mają `specs/screens/*.yml` oraz odpowiadające im nowsze dokumenty `docs/17-...` i późniejsze. Status gotowości modułów jest utrzymywany w `docs/71-admin-osk-module-mapping-status.md`.

## Statusy

- `READY_FOR_IMPLEMENTATION` — znamy ekran/flow w zakresie wystarczającym do budowy własnego odpowiednika; niewidoczne detale demo projektujemy po swojemu.
- `PARTIAL` — znamy istotną część modułu, ale brak jeszcze ważnego flow lub podwidoku.
- `NOT_SCREEN_MAPPED` — znamy funkcję/domenę, ale nie wykonaliśmy bieżącego audytu zalogowanego UI.
- `PUBLIC_CONFIRMED` — funkcja potwierdzona publicznie, niekoniecznie na poziomie zalogowanego ekranu.
- `HISTORICAL_INDEX` — ślad historyczny; nie jest bieżącym wymaganiem bez nowego potwierdzenia.
- `OWN_PRODUCT_DECISION` — jawna decyzja naszego produktu, a nie odtworzenie zachowania 360.

---

## Publiczne / dostępne bez zalogowania

| ID | Ekran | Status | Główne akcje |
|---|---|---|---|
| PUB-01 | Strona główna B2B | `PUBLIC_CONFIRMED` | rejestracja, logowanie, prezentacja modułów |
| PUB-02 | Logowanie / rejestracja | `PUBLIC_CONFIRMED` | login, social login, reset hasła, remember me |
| PUB-03 | Przypomnienie hasła | `PUBLIC_CONFIRMED` | e-mail lub login |
| PUB-04 | Cennik | `PUBLIC_CONFIRMED` | licencje, egzaminy, reklamy |
| PUB-05 | Oferta reklamowa / aukcje | `PUBLIC_CONFIRMED` | miejscowość, placement, licytacja |
| PUB-06 | Regulamin | `PUBLIC_CONFIRMED` | zasady konta, licencji, egzaminów, płatności, reklam |
| PUB-07 | Polityka prywatności | `PUBLIC_CONFIRMED` | przeglądanie |

---

# Core panelu administratora OSK

## APP-01 — Panel główny `/`

**Status:** `READY_FOR_IMPLEMENTATION`  
**Spec:** `specs/screens/main-dashboard.yml`  
**Dokument:** `docs/74-main-dashboard.md`

Potwierdzone:
- widget `Licencje`,
- aktywne i dostępne licencje,
- `Przydziel licencje`, `Kup licencje`, `Więcej`,
- widget `Egzaminy wewnętrzne`,
- dostępna pula egzaminów,
- `Przeprowadź egzamin`, `Kup egzamin`, `Więcej`,
- `Powiadomienia` / activity feed,
- zapis operacji wraz z czasem, aktorem i kontekstem,
- osadzony kalendarz,
- widok miesiąc/tydzień/dzień,
- `Pełny kalendarz`, `Dodaj wydarzenie`.

Nie hardkodować przykładowych liczników z konta demo.

## APP-02 — Integracja PKK `/integracja-pkk`

**Status:** `READY_FOR_IMPLEMENTATION`  
**Specy:** `specs/screens/pkk-entrypoints-and-student-list.yml`, `specs/screens/pkk-course-operational-panel.yml`  
**Dokumenty:** `docs/75-pkk-entrypoints-and-student-list.md`, `docs/76-pkk-course-operational-panel.md`, `docs/77-pkk-management-flow-fallback-design.md`

Potwierdzone:
- strona `/integracja-pkk` jest ekranem informacyjno-nawigacyjnym,
- operacyjna obsługa PKK odbywa się w kontekście kursanta i konkretnego kursu,
- `Pobierz PKK`, `Podgląd PKK`, `Aktualizuj i zwróć PKK`,
- karta kursu: PKK, kategoria, rodzaj szkolenia, data rozpoczęcia, ostatnia operacja,
- historia operacji PKK,
- stan danych pobranych z PKK.

Drawer `Zarządzaj PKK` jest nieobserwowalny z powodu błędu strony referencyjnej; nie jest blockerem implementacji własnego bezpiecznego flow.

## APP-03 — Kursanci `/kursanci`

**Status:** `READY_FOR_IMPLEMENTATION`  
**Spec główny:** `specs/screens/students.yml`

Potwierdzone ekrany/flow:
- lista kursantów,
- wyszukiwanie,
- filtry,
- sortowanie,
- podgląd,
- szczegóły kursanta,
- dodawanie ręczne,
- wejście `Dodaj kursanta z PKK`,
- edycja kursanta,
- archiwizacja/usuwanie,
- dane dostępowe,
- licencje,
- kursy (PKK),
- płatności kursanta,
- postępy,
- egzamin wewnętrzny,
- pobranie dokumentu dostępowego.

Powiązane specy: `student-*.yml`, `course-*.yml`, `pkk-*.yml`.

## APP-04 — Kurs formalny kursanta / Kursy (PKK)

**Status:** `READY_FOR_IMPLEMENTATION`  
**Specy:** `specs/screens/course-create.yml`, `course-edit.yml`, `course-delete.yml`  
**Dokumenty:** `docs/78-course-edit-form.md`, `docs/79-course-delete-confirmation.md`, `docs/80-course-create-form.md`

Potwierdzone:
- rodzaj szkolenia: podstawowe / uzupełniające,
- kategoria,
- PKK,
- data i godzina rozpoczęcia,
- koszt,
- teoria w bieżącym OSK,
- teoria odbyta w innej szkole,
- praktyka w bieżącym OSK,
- praktyka odbyta w innej szkole,
- instruktor prowadzący,
- lokalizacja,
- edycja,
- potwierdzenie usunięcia z informacją o powiązanej płatności i wpłatach.

Uwaga architektoniczna: w naszym produkcie bieżące godziny OSK mają wynikać z ewidencji zajęć/ledgera; wartości z innej szkoły są osobnym audytowalnym uznaniem.

## APP-05 — Kalendarz `/kalendarz`

**Status:** `READY_FOR_IMPLEMENTATION`  
**Specy:** `specs/screens/calendar.yml`, `calendar-add-event.yml`  
**Dokumenty:** `docs/48-calendar-main-screen.md`, `docs/49-calendar-add-event-form.md`, `docs/50-own-calendar-event-lifecycle.md`, `docs/51-calendar-important-dates.md`

Potwierdzone:
- miesiąc / tydzień / dzień,
- dziś / poprzedni / następny okres,
- typy filtrowania: wydarzenie, jazda, ważne daty,
- filtrowanie zasobów: pracownicy, pojazdy, lokalizacje,
- tworzenie wydarzenia/jazdy,
- kursant, instruktor, pojazd,
- zapisane lub własne miejsce spotkania,
- data, czas, długość wydarzenia.

Nieobserwowalne w demo: exact edit/cancel/drag&drop. Własny lifecycle jest zdefiniowany osobno.

## APP-06 — Lokalizacje `/lokalizacje`

**Status:** `READY_FOR_IMPLEMENTATION`  
**Specy:** `specs/screens/locations.yml`, `school-locations-create.yml`, `school-locations-edit.yml`  
**Dokumenty:** `docs/19-locations-screen.md`, `docs/72-school-locations-create-form.md`, `docs/73-school-locations-edit-form.md`

Potwierdzone typy:
- `Filia`,
- `Sala wykładowa`,
- `Plac manewrowy`.

Potwierdzone pola formularza:
- rodzaj,
- nazwa,
- ulica i nr,
- kod pocztowy,
- miejscowość z wyszukiwanym katalogiem.

Potwierdzone akcje:
- dodanie,
- edycja,
- przejście do kalendarza lokalizacji,
- widoczna akcja archiwizacji.

Archiwizacja była zablokowana w demo; u nas stosujemy własny soft-archive z audytem.

## APP-07 — Pojazdy `/pojazdy`

**Status:** `READY_FOR_IMPLEMENTATION`

Potwierdzone:
- lista,
- szczegóły,
- dodawanie,
- edycja,
- usuwanie,
- archiwizacja widoczna, lecz demo-blocked,
- zdjęcie,
- numer rejestracyjny i boczny,
- marka/model/rok/pojemność/VIN,
- kategorie,
- lokalizacje,
- przegląd,
- OC,
- AC,
- kontekst kalendarza.

Specy: `vehicle-*.yml`, `vehicles.yml`.

## APP-08 — Pracownicy `/pracownicy`

**Status:** `READY_FOR_IMPLEMENTATION`

Potwierdzone:
- lista,
- tworzenie,
- szczegóły,
- edycja,
- rodzaj pracownika,
- PESEL,
- telefon,
- numer uprawnień,
- kategorie,
- lokalizacje,
- legitymacja/badania medyczne/psychologiczne,
- zdjęcie,
- opcjonalne konto do logowania,
- kontekst dostępu do panelu,
- kalendarz.

RBAC badanego produktu nie jest kopiowany; u nas obowiązuje permission-based RBAC.

## APP-09 — Licencje — zakup `/licencje/wykup`

**Status:** `READY_FOR_IMPLEMENTATION`

Potwierdzone:
- warianty 31/90/180 dni odpowiadające ofercie 1/3/6 miesięcy,
- ilość,
- mieszany koszyk,
- rabaty/ceny jako dane konfiguracyjne,
- PayU/przelew,
- podsumowanie zamówienia.

## APP-10 — Licencje — zarządzanie `/licencje/panel`

**Status:** `READY_FOR_IMPLEMENTATION`

Potwierdzone:
- pule licencji,
- aktywne/dostępne,
- generowanie/przydział,
- istniejący lub nowy dostęp kursanta,
- język,
- dostęp przez e-mail/login,
- nieaktywna vs aktywna licencja,
- usunięcie przed aktywacją,
- przedłużanie/stacking,
- rozwijana historia,
- sortowanie,
- zbiorczy PDF dostępów.

Własna reguła integralności: cofnięcie nieaktywowanego assignmentu atomowo przywraca dokładnie jedną sztukę inventory.

## APP-11 — Egzamin wewnętrzny — zakup `/egzamin-wewnetrzny/wykup`

**Status:** `READY_FOR_IMPLEMENTATION`

Potwierdzone:
- zakup sztuk egzaminów,
- pule darmowe/opłacone,
- cena dynamiczna,
- PayU/przelew,
- podsumowanie.

## APP-12 — Egzamin wewnętrzny — panel `/egzamin-wewnetrzny/panel`

**Status:** `READY_FOR_IMPLEMENTATION`

Potwierdzone:
- lista kursantów i agregat prób,
- generowanie egzaminu,
- dokładnie jeden kursant na operację generowania,
- remote link,
- start na bieżącym stanowisku,
- edycja danych kandydata,
- wyszukiwanie,
- filtry,
- sortowanie,
- historia wielu prób,
- wynik,
- szczegóły pytań,
- PDF arkusza odpowiedzi,
- formalne powiązanie z kursantem i kursem.

Własny lifecycle inventory jest opisany w `specs/design/internal-exam-lifecycle.yml` i ma pierwszeństwo nad starszymi uproszczonymi opisami.

## APP-13 — Ustawienia `/ustawienia`

**Status:** `READY_FOR_IMPLEMENTATION`  
**Spec:** `specs/screens/settings.yml`  
**Dokument:** `docs/18-settings-screen.md`

Potwierdzone:
- dane podstawowe,
- dane firmy,
- konfiguracja API PKK,
- nazwa szkoły,
- numer ewidencyjny OSK,
- login OSK,
- dostęp do zaakceptowanego regulaminu.

## APP-14 — Historia zakupów `/historia-zakupow`

**Status:** `READY_FOR_IMPLEMENTATION`  
**Spec:** `specs/screens/purchase-history.yml`  
**Dokument:** `docs/17-history-purchases-screen.md`

Potwierdzone:
- lista zamówień,
- pozycje zamówienia,
- data,
- data księgowania,
- kwota,
- status,
- `Opłać` dla nieopłaconych,
- paginacja/liczba pozycji.

Historia zakupów platformy jest osobna od płatności kursanta za szkolenie.

---

# Moduły poza core — nie blokują implementacji v1

| Moduł | Route | Status |
|---|---|---|
| Moje wizytówki | `/wizytowki` | `NOT_SCREEN_MAPPED` |
| Moje reklamy | podmenu do weryfikacji | `NOT_SCREEN_MAPPED` |
| Wykłady | `/wyklady` | `NOT_SCREEN_MAPPED` |
| Szkolenie z instruktorem | `/szkolenie-z-instruktorem` | `NOT_SCREEN_MAPPED` |

Ich domena jest znana z publicznych materiałów, ale nie powinny blokować budowy operacyjnego core OSK.

---

# Elementy historyczne / opcjonalne

| Ekran/funkcja | Status | Decyzja |
|---|---|---|
| Faktury `/faktury` | `HISTORICAL_INDEX` | nie traktować jako wymogu parytetu v1 |
| Przeglądaj jako kursant | `HISTORICAL_INDEX` | ewentualny własny bezpieczny tryb impersonacji |
| Eksport postępów | `OWN_PRODUCT_DECISION` | brak potwierdzenia u konkurenta |
| Refund z panelu | `OWN_PRODUCT_DECISION` | proces finansowy, nie potwierdzony panelowy przycisk |

---

## Wspólne wymagania dla wszystkich ekranów naszego produktu

Każdy ekran danych musi uwzględniać:
- loading,
- empty,
- error,
- success feedback,
- walidację backendową,
- tenant isolation,
- autoryzację permission-based,
- confirmation dla operacji destrukcyjnych,
- audit dla danych formalnych/finansowych,
- paginację/filtry dla rosnących kolekcji,
- responsywność,
- brak hard-code danych zmiennych prawnie lub produktowo.
