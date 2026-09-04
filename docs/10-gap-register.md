# 10. Gap register — elementy do weryfikacji po zalogowaniu

Stan po drugim audycie: 2026-09-05.

## Zamknięte / istotnie doprecyzowane w drugim audycie

| ID | Obszar | Wynik |
|---|---|---|
| RES-01 | Reset hasła | potwierdzone: identyfikator może być e-mailem lub loginem |
| RES-02 | Chronione trasy | potwierdzone zachowanie `ReturnUrl` |
| RES-03 | Dostęp kursanta | potwierdzone dwa tryby: e-mail albo login+hasło nadane przez OSK |
| RES-04 | Licencja nieaktywna | potwierdzone automatyczne przywrócenie sztuki do puli po skasowaniu nieaktywowanego przydziału |
| RES-05 | Egzamin | potwierdzone osobne flow linkiem oraz stacjonarne `Rozpocznij egzamin wewnętrzny` |
| RES-06 | Karta egzaminu | potwierdzone zachowanie cyfrowe + druk |
| RES-07 | Szkolenie z instruktorem | potwierdzone lekcje, wideo, postęp, pytania kontrolne, retry, skip, zależność od kategorii |
| RES-08 | Domyślna kategoria | potwierdzone przełączanie kategorii i wpływ na test/kurs/statystyki |
| RES-09 | Kalendarz | potwierdzone konteksty: pracownik, biuro, kadrowa/HR, właściciel; planowanie sobie/innemu pracownikowi; self-booking kursanta; aktywność instruktorów; czas pracy |
| RES-10 | Reklamy | potwierdzona pełniejsza mechanika aukcyjna, historia ofert, deadlines, kreacje, moderacja, text fallback, prośba o odrzucenie bidu i ukrycie nazwy |
| RES-11 | Ranking | potwierdzone 1–5, aktualność/liczba opinii, Bayesian average, moderacja i zgłaszanie opinii |
| RES-12 | Konto | potwierdzone żądanie zamknięcia, blokada przy naruszeniach i opis pojedynczej sesji dla opłaconego konta użytkownika |

## Otwarte luki wymagające autoryzowanego wejścia

| ID | Obszar | Co trzeba sprawdzić | Priorytet |
|---|---|---|---|
| GAP-01 | Dashboard | dokładne KPI, widgety, alerty, quick actions | high |
| GAP-02 | Kursanci | pełne pola formularza, kolumny listy, statusy, wyszukiwanie, masowe akcje | critical |
| GAP-03 | Pracownicy | dokładne role, formularze, permission matrix; czy biuro/HR są rolami czy permissions | critical |
| GAP-04 | Pojazdy | pola, dokumenty, statusy, przypomnienia i powiązanie z jazdami | high |
| GAP-05 | Kalendarz | dzień/tydzień/miesiąc, drag&drop, konflikty, exact statusy, anulowanie/no-show, sloty kursanta | critical |
| GAP-06 | PKK | dokładne formularze, wymagane pola, komunikaty, statusy błędów, retry | critical |
| GAP-07 | Licencje | aktualne kolumny/filtry, bulk actions, dokładny ekran generowania dostępu | critical |
| GAP-08 | Licencje — języki | bieżąca lista języków i czy język jest per assignment/licencja/użytkownik | high |
| GAP-09 | Postępy | dokładne metryki, filtry, progi, czy istnieje eksport | high |
| GAP-10 | Egzamin | aktualna konfiguracja testu, języki, token/link lifecycle, wynik, zawartość karty | critical |
| GAP-11 | Wykłady | aktualna struktura materiałów, player, przypisywanie i tracking | high |
| GAP-12 | Impersonacja | dokładny zakres `Przeglądaj jako kursant`, dozwolone działania i banner | high |
| GAP-13 | Reklamy | panel po wygranej: statusy kampanii, upload kreacji, moderacja, historia emisji | high |
| GAP-14 | Faktury | czy funkcja została usunięta, przeniesiona na inną trasę lub nadal istnieje tylko po zalogowaniu | medium |
| GAP-15 | Historia płatności | bieżące trasy i zawartość dla licencji/egzaminów/reklam | medium |
| GAP-16 | Powiadomienia | czy istnieje inbox/centrum powiadomień, kanały i ustawienia | medium |
| GAP-17 | Sesje personelu | czy zasada jednej aktywnej sesji dotyczy Owner/pracowników BIZ czy tylko kont użytkowników końcowych | high |
| GAP-18 | Aktywacja dostępu | które dokładnie produkty używają `Aktywuj dostęp` i jak wygląda panelowy stan `paid but inactive` | high |
| GAP-19 | Zamknięcie konta | czy jest przycisk w panelu czy proces kontaktowy | low |
| GAP-20 | Wizytówka premium | aktualnie `COMING_SOON`; sprawdzić dopiero gdy produkt zostanie uruchomiony | low |

## Korekty pewności po drugim audycie

### Faktury
W starszym indeksie panelu istniała pozycja `Faktury`, ale obecna stara trasa `/faktury` zwraca 404. Nie jest to dowód, że funkcja definitywnie zniknęła — mogła zostać przeniesiona. Status:

`HISTORICAL_INDEX / TO_VERIFY_AUTH`.

### Eksport postępów
Brak publicznego dowodu. `export_progress` jest funkcją projektową, nie odwzorowaną funkcją potwierdzoną.

### Pauzowanie reklamy
Brak publicznego dowodu na akcję klienta `pause_campaign`. Nie traktować jako odwzorowanej funkcji.

### Refund
Regulamin potwierdza procesy reklamacyjne/odstąpienia, ale nie potwierdza panelowej akcji `refund`. Jeśli wdrażamy refund, jest to nasza funkcja finansowa.

## Konflikty źródeł wymagające konfiguracji

| ID | Obszar | Konflikt | Decyzja |
|---|---|---|---|
| CON-01 | Języki | marketing: 5; bieżący cennik licencji: 4; historyczna aktualność egzaminów: 5 | konfiguracja per moduł/product |
| CON-02 | Szkolenie | publiczne materiały podają różną liczbę działów | CMS/config |
| CON-03 | Slajdy | różne publiczne liczby w różnych datach | CMS/config |
| CON-04 | Fullscreen ad | 10 s vs 30 s | parametr placementu/kampanii |
| CON-05 | Faktury | stary indeks menu vs 404 starej trasy | historyczne / auth verification |

## Jak kontynuować audyt po uzyskaniu legalnego dostępu

Dla każdego ekranu:

1. screenshot,
2. aktualny URL/route,
3. breadcrumb/menu,
4. role, które widzą ekran,
5. pola i typy danych,
6. przyciski/CTA,
7. modale,
8. walidacje client/server,
9. loading/empty/error/success,
10. request/response z DevTools wyłącznie dla własnej sesji i w granicach uprawnień,
11. efekt w bazie/logice biznesowej,
12. powiadomienia/wiadomości e-mail,
13. możliwość cofnięcia akcji,
14. audit/security consequence,
15. aktualizacja `01-feature-map.md`, `02-screen-inventory.md`, `03-user-flows.md`, `06-api-contract.md`, `12-action-matrix.md` i `functional-requirements.yml`.

## Zasada implementacyjna

Dopóki luka ma `TO_VERIFY_AUTH`, Codex nie powinien tworzyć rzekomo identycznego zachowania 360 na podstawie zgadywania. Może zaimplementować własny, sensowny odpowiednik PrawkoNaRaz, ale musi oznaczyć decyzję jako projektową.
