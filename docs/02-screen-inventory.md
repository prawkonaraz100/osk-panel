# 02. Inwentarz ekranów

> Stan po drugiej weryfikacji publicznych źródeł: 2026-09-05. `HISTORICAL_INDEX` oznacza ekran/menu widoczny w starszym indeksie, ale niepotwierdzony jako aktualny ekran zalogowanego panelu.

## Publiczne / dostępne bez zalogowania

| ID | Ekran | Status | Główne akcje |
|---|---|---|---|
| PUB-01 | Strona główna B2B | CURRENT_CONFIRMED | rejestracja, logowanie, przejście do oferty/cennika, prezentacja modułów |
| PUB-02 | Logowanie / rejestracja | CURRENT_CONFIRMED | login, social login, rejestracja OSK, reset hasła, remember me |
| PUB-03 | Przypomnienie hasła | CURRENT_CONFIRMED | podanie **e-maila lub loginu**, wysłanie instrukcji, powrót do logowania |
| PUB-04 | Cennik | CURRENT_CONFIRMED | wybór okresu licencji, zakup licencji/egzaminów/reklam, prezentacja zakresu pakietu |
| PUB-05 | Oferta reklamowa / aukcje | CURRENT_CONFIRMED | wybór miejscowości, placementu, przejście do licytacji |
| PUB-06 | Regulamin | CURRENT_CONFIRMED | przegląd zasad konta, licencji, egzaminów, płatności, reklam, rankingu |
| PUB-07 | Polityka prywatności | CURRENT_CONFIRMED | przeglądanie |
| PUB-08 | Pozycjonowanie OSK | CURRENT_CONFIRMED | kontakt/lead, oferta SEO/WWW/Google Ads |
| PUB-09 | Aktualności produktu | CURRENT_CONFIRMED | dokumentowanie zmian funkcjonalnych i zakresu modułów |

## Ekrany uwierzytelniania / stany wejściowe

| ID | Ekran / stan | Status | Elementy |
|---|---|---|---|
| AUTH-01 | Logowanie z ReturnUrl | CURRENT_CONFIRMED | chroniona trasa -> logowanie -> powrót do żądanej trasy |
| AUTH-02 | Reset hasła | CURRENT_CONFIRMED | e-mail lub login |
| AUTH-03 | Dostęp opłacony, oczekujący na aktywację | RULES_CONFIRMED | CTA `Aktywuj dostęp`; dokładny wygląd `TO_VERIFY_AUTH` |
| AUTH-04 | Blokada / zakończenie poprzedniej sesji | RULES_CONFIRMED | zasada pojedynczej sesji dla opłaconego konta użytkownika; dokładny komunikat `TO_VERIFY_AUTH` |

## Panel OSK

| ID | Ekran | Status | Elementy / akcje |
|---|---|---|---|
| APP-01 | Dashboard | TO_VERIFY_AUTH | dokładne KPI, alerty, skróty i widgety niepotwierdzone |
| APP-02 | Kursanci | TO_VERIFY_AUTH | lista i dokładne kolumny do sprawdzenia |
| APP-03 | Kursant — szczegóły | TO_VERIFY_AUTH | dane, PKK, kurs, jazdy, licencja, postęp, egzaminy — kontekst biznesowy potwierdzony, UI nie |
| APP-04 | Pracownicy / role | TO_VERIFY_AUTH | właściciel, instruktor, wykładowca, pracownik; biuro/HR potwierdzone w kontekście kalendarza |
| APP-05 | Pojazdy | TO_VERIFY_AUTH | centralny podgląd i przypomnienia potwierdzone, dokładny formularz nie |
| APP-06 | Kalendarz | CURRENT_CONFIRMED / TO_VERIFY_AUTH | biznesowo: jazdy, inni pracownicy, self-booking kursanta, aktywność instruktorów, czas pracy; layout do weryfikacji |
| APP-07 | PKK | CURRENT_CONFIRMED / TO_VERIFY_AUTH | pobierz, szczegóły, aktualizuj szkolenie, zwroty, historia; dokładne formularze/komunikaty do weryfikacji |
| APP-08 | Licencje — wykup | CURRENT_CONFIRMED / HISTORICAL_INDEX | zakup puli, okresy; dokładny zalogowany ekran historycznie indeksowany |
| APP-09 | Licencje — zarządzaj | RULES_CONFIRMED / HISTORICAL_INDEX | pula, przypisanie, dostęp e-mail lub login/hasło, język, cofnięcie nieaktywowanej |
| APP-10 | Postępy w nauce | CURRENT_CONFIRMED / HISTORICAL_INDEX | statystyki postępu; dokładne filtry/kolumny do sprawdzenia |
| APP-11 | Licencje — historia płatności | HISTORICAL_INDEX / TO_VERIFY_AUTH | dokładna bieżąca trasa niepotwierdzona |
| APP-12 | Egzaminy — wykup | CURRENT_CONFIRMED / HISTORICAL_INDEX | zakup puli egzaminów |
| APP-13 | Egzaminy — zarządzaj | RULES_CONFIRMED / HISTORICAL_INDEX | generuj link, stacjonarne `Rozpocznij egzamin wewnętrzny` |
| APP-14 | Przeprowadzone egzaminy | RULES_CONFIRMED / HISTORICAL_INDEX | wynik, cyfrowa karta przebiegu, druk |
| APP-15 | Egzaminy — historia płatności | HISTORICAL_INDEX / TO_VERIFY_AUTH | dokładna bieżąca trasa niepotwierdzona |
| APP-16 | Wykłady | CURRENT_CONFIRMED | chroniona aktualna trasa; wybór/prezentacja materiałów, szczegóły UI do weryfikacji |
| APP-17 | Profil OSK — edycja | HISTORICAL_INDEX / TO_VERIFY_AUTH | dane wizytówki |
| APP-18 | Profil OSK — podgląd | CURRENT_CONFIRMED / HISTORICAL_INDEX | publiczna wizytówka + historyczny panelowy link |
| APP-19 | Reklamy — wybór/aukcja | CURRENT_CONFIRMED / RULES_CONFIRMED | miejscowość, placement, parametry aukcji, `Licytuj` |
| APP-20 | Reklamy — po wygranej | RULES_CONFIRMED / TO_VERIFY_AUTH | płatność, przesłanie desktop/mobile kreacji, moderacja, tekst fallback, emisja |
| APP-21 | Historia licytacji | RULES_CONFIRMED / TO_VERIFY_AUTH | nazwa oferenta, możliwość wniosku o ukrycie nazwy, prośba o usunięcie oferty |
| APP-22 | Historia płatności reklam | HISTORICAL_INDEX / TO_VERIFY_AUTH | transakcje/emisje — bieżący ekran niepotwierdzony |
| APP-23 | Faktury | HISTORICAL_INDEX / TO_VERIFY_AUTH | stary indeks zawierał pozycję; stara trasa `/faktury` obecnie 404 |
| APP-24 | Przeglądaj jako kursant | HISTORICAL_INDEX / TO_VERIFY_AUTH | zakres impersonacji do sprawdzenia |
| APP-25 | Ustawienia konta | TO_VERIFY_AUTH | dane, hasło, zgody, bezpieczeństwo |
| APP-26 | Powiadomienia | TO_VERIFY_AUTH | dokładny inbox/ustawienia niepotwierdzone |
| APP-27 | Audyt / historia | INFERRED | wymagany dla własnego systemu; nie twierdzimy, że istnieje taki ekran 360 |

## Powiązany obszar użytkownika/kursanta

Nie jest to koniecznie część panelu BIZ OSK, ale jest częścią produktu, który panel przydziela kursantowi.

| ID | Ekran | Status | Elementy |
|---|---|---|---|
| STUD-01 | Test | CURRENT_CONFIRMED | filtrowanie wg domyślnej kategorii |
| STUD-02 | Kurs / materiały | CURRENT_CONFIRMED | kategoria domyślna, treści edukacyjne |
| STUD-03 | Statystyki | CURRENT_CONFIRMED | kategoria domyślna |
| STUD-04 | Szkolenie z instruktorem | CURRENT_CONFIRMED | lekcje/działy, wideo, postęp całości |
| STUD-05 | Pytania kontrolne | CURRENT_CONFIRMED | start, wielokrotne ponowienie, możliwość pominięcia |
| STUD-06 | Zmiana kategorii | CURRENT_CONFIRMED | ustawia kategorię domyślną dla test/kurs/statystyki; wpływa na szkolenie |

## Promocja i usługi dodatkowe

| ID | Ekran / produkt | Status | Elementy |
|---|---|---|---|
| MKT-01 | Artykuł sponsorowany | RULES_CONFIRMED | treść klienta lub copywriting, zdjęcia, moderacja, publikacja, archiwum |
| MKT-02 | Baner na stronę OSK | RULES_CONFIRMED / HISTORICAL_INDEX | gotowa grafika, wymóg DoFollow, pomoc wdrożeniowa |
| MKT-03 | Pozycjonowanie / WWW | CURRENT_CONFIRMED | audyt, SEO, Google Ads, strona i CMS, formularz kontaktowy |
| MKT-04 | Wizytówka premium | COMING_SOON | nie implementować jako potwierdzonego działającego placementu 360 |

## Minimalny układ nawigacji dla naszego odpowiednika

To rekomendacja architektoniczna, nie twierdzenie o aktualnym menu 360:

1. Start
2. Kursanci
3. Kalendarz
4. PKK
5. Kursy / Wykłady
6. Licencje
   - Kup
   - Zarządzaj
   - Postępy
   - Historia zakupów
7. Egzaminy wewnętrzne
   - Kup
   - Zarządzaj
   - Przeprowadzone
8. Pracownicy
9. Pojazdy
10. Profil OSK / Opinie
11. Reklama
12. Rozliczenia
13. Ustawienia

## Wspólne stany ekranów — wymaganie naszego systemu

Każdy widok danych powinien obsługiwać:
- loading,
- empty,
- error,
- partial failure,
- success feedback,
- confirmation modal dla nieodwracalnych operacji,
- optimistic UI tylko dla bezpiecznie odwracalnych zmian,
- filtr/paginację dla dużych list,
- historię ostatniej modyfikacji dla danych formalnych.
