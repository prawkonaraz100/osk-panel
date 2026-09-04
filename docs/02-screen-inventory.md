# 02. Inwentarz ekranów

## Publiczne

| ID | Ekran | Status | Główne akcje |
|---|---|---|---|
| PUB-01 | Strona główna B2B | CONFIRMED | rejestracja, logowanie, demo, przejście do cennika |
| PUB-02 | Logowanie / rejestracja | CONFIRMED | login, social login, rejestracja OSK, reset hasła |
| PUB-03 | Przypomnienie hasła | CONFIRMED | wysłanie linku resetującego |
| PUB-04 | Cennik | CONFIRMED | wybór liczby licencji, zakup egzaminów/reklamy |
| PUB-05 | O nas | CONFIRMED | informacyjny |
| PUB-06 | Regulamin | CONFIRMED | przeglądanie/pobranie |
| PUB-07 | Polityka prywatności | CONFIRMED | przeglądanie/pobranie |
| PUB-08 | Pozycjonowanie OSK | CONFIRMED | kontakt/lead |

## Panel OSK

| ID | Ekran | Status | Elementy |
|---|---|---|---|
| APP-01 | Dashboard | TO_VERIFY | KPI, alerty, ostatnie akcje, skróty |
| APP-02 | Kursanci | INFERRED | lista, wyszukiwarka, status, kurs, licencja, postęp |
| APP-03 | Kursant — szczegóły | INFERRED | dane, PKK, kurs, jazdy, licencja, postęp, egzaminy |
| APP-04 | Pracownicy | INFERRED | role, dostępność, terminy badań |
| APP-05 | Pojazdy | INFERRED | status, dokumenty, ubezpieczenie, badanie techniczne |
| APP-06 | Kalendarz | CONFIRMED | jazdy, wykłady, instruktorzy, pojazdy, dostępne sloty |
| APP-07 | PKK | CONFIRMED | pobierz, aktualizuj, zwróć, historia |
| APP-08 | Licencje — wykup | INDEXED | pakiet, ilość, cena, płatność |
| APP-09 | Licencje — zarządzaj | INDEXED | pula, przypisane, aktywowane, cofnięcie nieaktywnych |
| APP-10 | Postępy w nauce | INDEXED | statystyki kursantów |
| APP-11 | Licencje — historia płatności | INDEXED | transakcje |
| APP-12 | Egzaminy — wykup | INDEXED | pula egzaminów, płatność |
| APP-13 | Egzaminy — zarządzaj | INDEXED | generuj, link, rozpocznij stacjonarnie |
| APP-14 | Przeprowadzone egzaminy | INDEXED | wynik, karta przebiegu, druk |
| APP-15 | Egzaminy — historia płatności | INDEXED | transakcje |
| APP-16 | Wykłady | INDEXED/CONFIRMED | wybór kategorii, prezentacja materiałów |
| APP-17 | Profil OSK — edycja | INDEXED | dane wizytówki |
| APP-18 | Profil OSK — podgląd | INDEXED | publiczna wersja |
| APP-19 | Reklamy | INDEXED/CONFIRMED | typ reklamy, region, kreacja, zakup/licytacja |
| APP-20 | Historia płatności reklam | INDEXED | transakcje i emisje |
| APP-21 | Faktury | INDEXED | lista, pobranie |
| APP-22 | Przeglądaj jako kursant | INDEXED | bezpieczna impersonacja |
| APP-23 | Ustawienia konta | INFERRED | dane, hasło, bezpieczeństwo, zgody |
| APP-24 | Powiadomienia | INFERRED | alerty i przypomnienia |
| APP-25 | Audyt / historia | INFERRED | krytyczne operacje |

## Minimalny układ nawigacji panelu

1. Start
2. Kursanci
3. Kalendarz
4. PKK
5. Kursy / Wykłady
6. Licencje
   - Wykup
   - Zarządzaj
   - Postępy
   - Historia płatności
7. Egzaminy wewnętrzne
   - Wykup
   - Zarządzaj
   - Przeprowadzone
   - Historia płatności
8. Pracownicy
9. Pojazdy
10. Profil OSK / Ranking
11. Reklama
12. Faktury
13. Ustawienia

## Wspólne stany ekranów

Każdy widok danych musi obsługiwać:
- loading,
- empty,
- error,
- partial failure (np. PKK niedostępne, ale reszta profilu działa),
- success toast,
- confirmation modal dla nieodwracalnych operacji,
- optimistic UI tylko dla odwracalnych zmian,
- filtr/paginację dla list,
- dziennik ostatniej modyfikacji w danych formalnych.
