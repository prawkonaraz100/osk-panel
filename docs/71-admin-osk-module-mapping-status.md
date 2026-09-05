# 71. Status mapowania modułów panelu admin OSK

Data: 2026-09-05

Cel: szybka macierz pokazująca, które moduły bieżącego panelu OSK są już wystarczająco zmapowane do projektowania/implementacji własnego odpowiednika, które są częściowe, a które wymagają dalszego audytu ekran po ekranie.

## Statusy

- `READY_FOR_IMPLEMENTATION` — znamy główne ekrany, pola, akcje i podstawowe lifecycle; pozostałe niewidoczne w demo detale mogą być zaprojektowane elastycznie po naszemu.
- `PARTIAL` — znamy domenę i część ekranów, ale brakuje istotnych CRUD-ów lub stanów.
- `NOT_SCREEN_MAPPED` — znamy istnienie modułu / funkcje publiczne, ale nie mamy jeszcze bieżącego audytu zalogowanego ekranu.

## Macierz

| Moduł | Status | Co mamy |
|---|---|---|
| Panel główny | `NOT_SCREEN_MAPPED` | Potwierdzone menu/trasa, brak pełnego dashboardu, KPI, alertów i skrótów. |
| Integracja PKK | `PARTIAL` | Potwierdzone funkcje biznesowe PKK i konfiguracja danych OSK; brak pełnego audytu operacyjnego ekranu `/integracja-pkk`. |
| Kalendarz | `READY_FOR_IMPLEMENTATION` | Widok kalendarza, zasoby: pracownicy/pojazdy/lokalizacje, typy Wydarzenie/Jazda/Ważne daty, formularz dodania wydarzenia, własne miejsce spotkania, powiązania z kursantem/instruktorem/pojazdem/lokalizacją. Nieobserwowalne operacje po utworzeniu projektujemy elastycznie. |
| Kursanci | `READY_FOR_IMPLEMENTATION` | Lista, podgląd, dodawanie, edycja, PESEL/data urodzenia, filtry, sortowanie, etapy szkolenia, profil, kursy/PKK, licencje, dostępy, płatności, postępy, egzamin wewnętrzny, archiwizacja/usuwanie. |
| Licencje — zakup | `READY_FOR_IMPLEMENTATION` | 1/3/6 miesięcy, ilości, mieszany koszyk, rabaty/cennik konfigurowalny, PayU/przelew, podsumowanie. |
| Licencje — panel/generowanie | `READY_FOR_IMPLEMENTATION` | Pule 31/90/180 dni, dostępne/aktywne, przydzielenie 1 kursantowi, języki, istniejący/nowy dostęp, nieaktywne i aktywne licencje, przedłużanie/stacking, usuwanie przed aktywacją, rozwinięcie historii, sortowanie, wielokrotny PDF dostępów. |
| Egzamin wewnętrzny — zakup | `READY_FOR_IMPLEMENTATION` | Zakup puli sztuk, cena dynamiczna, PayU/przelew, podsumowanie. |
| Egzamin wewnętrzny — panel | `READY_FOR_IMPLEMENTATION` | Pule darmowe/opłacone, lista kursantów/agregat prób, generowanie, remote link/local station, edycja danych, filtry, sortowanie, wyszukiwanie, historia prób, wynik, review pytań, PDF arkusza odpowiedzi, formalne powiązanie z kursantem i kursem, elastyczne zwolnienia z teorii. |
| Moje wizytówki | `NOT_SCREEN_MAPPED` | Znana domena/publiczna wizytówka i help, brak pełnego bieżącego ekranu CRUD. |
| Moje reklamy | `NOT_SCREEN_MAPPED` | Zmapowana domena aukcji/reklam z publicznych źródeł, brak aktualnego zalogowanego podmenu i bieżących ekranów kampanii. |
| Wykłady | `NOT_SCREEN_MAPPED` | Znany produkt/funkcja, brak audytu bieżącego zalogowanego UI. |
| Szkolenie z instruktorem | `NOT_SCREEN_MAPPED` | Znana struktura produktu i ogólne działanie, brak audytu bieżącego zalogowanego UI. |
| Moja szkoła — Lokalizacje | `PARTIAL` | Lista, typ, nazwa, adres, powiązanie z kalendarzem i edycją. Brakuje pełnego formularza dodaj/edytuj oraz zachowania usuwania/archiwizacji. |
| Moja szkoła — Pojazdy | `READY_FOR_IMPLEMENTATION` | Lista, szczegóły, dodaj, edytuj, usuń, archiwizacja obserwowana jako zablokowana w demo, rejestracja/nr boczny/marka/model/rok/pojemność/VIN/przegląd/OC/AC/kategorie/lokalizacje/zdjęcie, kalendarz. |
| Moja szkoła — Pracownicy | `READY_FOR_IMPLEMENTATION` | Lista, szczegóły, dodaj, edytuj, usuń/archiwizuj, rodzaj pracownika, PESEL, telefon, nr uprawnień, kategorie, lokalizacje, ważność legitymacji/badań, zdjęcie, konto do logowania, dostęp do panelu, kalendarz. |
| Konto OSK — Ustawienia | `READY_FOR_IMPLEMENTATION` | Dane podstawowe, dane firmy, dane API PKK, nazwa szkoły, nr ewidencyjny OSK, login OSK, link do zaakceptowanego regulaminu. |
| Konto OSK — Historia zakupów | `READY_FOR_IMPLEMENTATION` | Lista zamówień, pozycje, data, data księgowania, kwota, status, akcja Opłać dla nieopłaconych, paginacja/liczba pozycji. |

## Wniosek

Największy rdzeń operacyjny OSK jest już zmapowany: `Kursanci + Kalendarz + Licencje + Egzamin wewnętrzny + Pojazdy + Pracownicy + Ustawienia + Historia zakupów`.

Najbliższe brakujące audyty ekranowe: `Panel główny`, `Integracja PKK`, `Lokalizacje` (dokończenie CRUD), `Moje wizytówki`, `Moje reklamy`, `Wykłady`, `Szkolenie z instruktorem`.
