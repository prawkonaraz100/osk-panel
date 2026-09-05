# 71. Status mapowania modułów panelu admin OSK

Data konsolidacji: 2026-09-05

> To jest szybka macierz gotowości funkcjonalnej. Aktywny kontrakt implementacyjny znajduje się w `specs/implementation-baseline-v1.yml`. Screen evidence nie narzuca technicznej semantyki hard-delete, source-of-truth godzin ani inventory lifecycle — te decyzje wynikają z `specs/legal`, `specs/design`, `docs/82` i `docs/83`.

## Statusy

- `READY_FOR_IMPLEMENTATION` — znamy główne ekrany, pola, akcje i podstawowe flow; nieobserwowalne detale można zaprojektować po swojemu zgodnie z canonical architecture.
- `PARTIAL` — znamy domenę i część ekranów, ale brakuje istotnego flow.
- `NOT_SCREEN_MAPPED` — znamy istnienie modułu/funkcje publiczne, ale nie wykonaliśmy bieżącego audytu zalogowanego UI.

## Macierz

| Moduł | Status | Co mamy |
|---|---|---|
| Panel główny | `READY_FOR_IMPLEMENTATION` | Cztery główne widgety: Licencje, Egzaminy wewnętrzne, Powiadomienia/activity feed i osadzony Kalendarz. Licencje pokazują aktywne/dostępne oraz skróty do przydzielenia/zakupu; egzaminy pokazują dostępną pulę i skróty do przeprowadzenia/zakupu; activity feed pokazuje operacje i aktora/czas; kalendarz ma dzień/tydzień/miesiąc, nawigację, pełny kalendarz i dodanie wydarzenia. |
| Integracja PKK | `READY_FOR_IMPLEMENTATION` | Strona informacyjna + operacyjny panel przy konkretnym kursie. Potwierdzone: Pobierz PKK, Podgląd PKK, Aktualizuj i zwróć PKK, historia operacji i stan danych pobranych. Canonical owner u nas: `CourseEnrollment`. Drawer `Zarządzaj PKK` konkurenta jest nieobserwowalny przez błąd strony i nie blokuje własnego bezpiecznego flow. |
| Kalendarz | `READY_FOR_IMPLEMENTATION` | Widok miesiąc/tydzień/dzień, zasoby pracownicy/pojazdy/lokalizacje, typy Wydarzenie/Jazda/Ważne daty, formularz dodania wydarzenia, własne miejsce spotkania, powiązania z kursantem/instruktorem/pojazdem/lokalizacją. |
| Kursanci | `READY_FOR_IMPLEMENTATION` | Lista, read-only podgląd, dodawanie, edycja, PESEL/data urodzenia, filtry, sortowanie, etapy szkolenia, profil, licencje, learning access, płatności, postępy, egzamin wewnętrzny, archiwizacja/usuwanie jako widoczne akcje UI. Canonical backend lifecycle wynika z `docs/83`. |
| Kursy (PKK) / formalny `CourseEnrollment` | `READY_FOR_IMPLEMENTATION` | Dodawanie, edycja i potwierdzenie usuwania; rodzaj szkolenia, kategoria, PKK, data+godzina rozpoczęcia, koszt/relacja z płatnością, pola godzin bieżącego/innego OSK, instruktor i lokalizacja. W naszym backendzie bieżące godziny wynikają z `TrainingSession + TrainingHourLedgerEntry`, a zewnętrzne z `RecognizedExternalTraining`. Usunięcie formalnego kursu po skutkach oznacza cancel/archive, nie hard-delete. |
| Licencje — zakup | `READY_FOR_IMPLEMENTATION` | 1/3/6 miesięcy, ilości, mieszany koszyk, rabaty/cennik konfigurowalny, PayU/przelew, podsumowanie. |
| Licencje — panel/generowanie | `READY_FOR_IMPLEMENTATION` | Pule 31/90/180 dni, dostępne/aktywne, przydzielenie jednemu kursantowi, języki, istniejący/nowy dostęp, nieaktywne i aktywne licencje, przedłużanie/stacking, usuwanie przed aktywacją, rozwinięcie historii, sortowanie, wielokrotny PDF dostępów. Canonical revoke przed aktywacją przywraca dokładnie jedną sztukę inventory atomowo. |
| Egzamin wewnętrzny — zakup | `READY_FOR_IMPLEMENTATION` | Zakup puli sztuk, darmowe/opłacone źródła, cena dynamiczna, PayU/przelew, podsumowanie. |
| Egzamin wewnętrzny — panel | `READY_FOR_IMPLEMENTATION` | Lista kursantów/agregat prób, generowanie, remote link/local station, edycja danych, filtry, sortowanie, wyszukiwanie, historia prób, wynik, review pytań, PDF arkusza odpowiedzi, formalne powiązanie z kursantem i kursem. Canonical inventory: reserve przy access creation, consume przy start, nie przy finish. |
| Moje wizytówki | `NOT_SCREEN_MAPPED` | Znana domena/publiczna wizytówka i help, brak pełnego bieżącego ekranu CRUD. |
| Moje reklamy | `NOT_SCREEN_MAPPED` | Zmapowana domena aukcji/reklam z publicznych źródeł, brak aktualnego zalogowanego podmenu i ekranów kampanii. |
| Wykłady | `NOT_SCREEN_MAPPED` | Znany produkt/funkcja, brak audytu bieżącego zalogowanego UI. |
| Szkolenie z instruktorem | `NOT_SCREEN_MAPPED` | Znana struktura produktu i ogólne działanie, brak audytu bieżącego zalogowanego UI. |
| Moja szkoła — Lokalizacje | `READY_FOR_IMPLEMENTATION` | Lista, typ/nazwa/adres, kalendarz, pełny create/edit flow, typy `Filia`, `Sala wykładowa`, `Plac manewrowy`, wymagane pola adresowe, wyszukiwany katalog miejscowości, widoczna akcja archiwizacji. Exact archive competitor UI jest demo-blocked; own lifecycle = archive/restore + audit + preserved history. |
| Moja szkoła — Pojazdy | `READY_FOR_IMPLEMENTATION` | Lista, szczegóły, create/edit/delete confirmation, archiwizacja widoczna/demo-blocked, rejestracja/nr boczny/marka/model/rok/pojemność/VIN/przegląd/OC/AC/kategorie/lokalizacje/zdjęcie, kalendarz. |
| Moja szkoła — Pracownicy | `READY_FOR_IMPLEMENTATION` | Lista, szczegóły, create/edit, widoczne delete/archive, rodzaj pracownika, PESEL, telefon, nr uprawnień, kategorie, lokalizacje, ważność legitymacji/badań, zdjęcie, opcjonalne konto do logowania, panel access section, kalendarz. Własny RBAC jest permission-based. |
| Konto OSK — Ustawienia | `READY_FOR_IMPLEMENTATION` | Dane podstawowe, dane firmy, dane integracji PKK, nazwa szkoły, nr ewidencyjny OSK, login OSK, link do zaakceptowanego regulaminu. |
| Konto OSK — Historia zakupów | `READY_FOR_IMPLEMENTATION` | Lista zamówień, pozycje, data, data księgowania, kwota, status, akcja `Opłać` dla nieopłaconych, paginacja/liczba pozycji. To osobny ledger od student finance. |

## Wniosek

Core operacyjny OSK jest wystarczająco zmapowany do implementacji:

`Panel główny + Integracja PKK + Kursanci + CourseEnrollment + Kalendarz + Licencje + Egzamin wewnętrzny + Lokalizacje + Pojazdy + Pracownicy + Ustawienia + Historia zakupów`.

Nieobserwowalne detale demo nie są blockerami, jeżeli mamy własną jawną politykę domenową, bezpieczeństwa i audytu.

Pozostałe niezmapowane moduły (`Moje wizytówki`, `Moje reklamy`, `Wykłady`, `Szkolenie z instruktorem`) nie blokują core admin OSK.
