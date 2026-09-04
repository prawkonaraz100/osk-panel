# 15. Aktualne menu zalogowanego panelu OSK — mapa tras i funkcji

Data: 2026-09-05

Ten dokument mapuje **dokładne pozycje bieżącego menu zalogowanego panelu**, przekazane z aktualnego interfejsu użytkownika. Odróżnia istnienie trasy/menu od dokładnej zawartości ekranu.

## Statusy

- `USER_CONFIRMED_AUTH_MENU` — pozycja/trasa widoczna w aktualnym zalogowanym menu przekazanym podczas audytu.
- `PUBLIC_CONFIRMED` — funkcjonalność potwierdzona także na aktualnej publicznej stronie/ofercie.
- `RULES_CONFIRMED` — funkcjonalność potwierdzona regulaminem.
- `HELP_CONFIRMED` — operator opublikował aktualny materiał pomocy dotyczący tego ekranu.
- `TO_VERIFY_AUTH` — dokładne pola, kolumny, przyciski, modale i walidacje wymagają przejścia ekranu w zalogowanej sesji.

## 1. Panel główny

**Route:** `/`  
**Menu:** `Panel główny`  
**Status:** `USER_CONFIRMED_AUTH_MENU / TO_VERIFY_AUTH`

Anonimowo `/` jest stroną marketingową; po zalogowaniu ta sama trasa może pełnić rolę panelu głównego. Dokładne KPI, widgety, alerty, skróty, onboarding i ostatnie aktywności pozostają do sprawdzenia.

## 2. Integracja PKK

**Route:** `/integracja-pkk`  
**Status:** `USER_CONFIRMED_AUTH_MENU / PUBLIC_CONFIRMED`

Potwierdzone funkcje biznesowe:
- pobranie Profilu Kandydata na Kierowcę,
- podgląd szczegółów PKK,
- aktualizacja danych szkolenia,
- zwrot PKK do innego OSK,
- zwrot PKK do urzędu,
- zwrot przedawnionego profilu,
- historia operacji.

Do sprawdzenia po zalogowaniu:
- formularz wyszukania/pobrania PKK,
- wymagane identyfikatory,
- statusy integracji,
- błędy i retry,
- filtry historii,
- potwierdzenia operacji zwrotu.

## 3. Kalendarz

**Route:** `/kalendarz`  
**Status:** `USER_CONFIRMED_AUTH_MENU / PUBLIC_CONFIRMED / HELP_CONFIRMED / TO_VERIFY_AUTH`

Potwierdzone biznesowo:
- współdzielony kalendarz,
- widoczność dla pracownika, biura obsługi, kadrowej i właściciela,
- umawianie jazdy kursanta sobie,
- umawianie jazdy innemu pracownikowi,
- udostępnienie kursantowi możliwości zapisania się na jazdy,
- podgląd aktywności instruktorów,
- ewidencja czasu pracy.

Do sprawdzenia:
- widok dzień/tydzień/miesiąc,
- drag&drop,
- statusy zajęć,
- powtarzalność,
- blokady czasu,
- konflikty instruktora/kursanta/pojazdu,
- formularz wydarzenia,
- anulowanie/przenoszenie/no-show.

## 4. Kursanci

**Route:** `/kursanci`  
**Status:** `USER_CONFIRMED_AUTH_MENU / PUBLIC_CONFIRMED / HELP_CONFIRMED / TO_VERIFY_AUTH`

Potwierdzone biznesowo:
- tworzenie kursantów,
- monitoring kursantów,
- śledzenie postępów wykładów, podręcznika i pytań,
- przypisanie do jazd,
- przypisanie do wykładów,
- powiązanie z licencją i egzaminem wewnętrznym.

Do sprawdzenia:
- wszystkie pola formularza,
- wyszukiwanie/filtry,
- status kursanta,
- archiwizacja/usuwanie,
- szczegóły kursanta,
- akcje zbiorcze,
- relacja z PKK i kursem.

## 5. Licencje — Wykup licencje

**Route:** `/licencje/wykup`  
**Status:** `USER_CONFIRMED_AUTH_MENU / PUBLIC_CONFIRMED / RULES_CONFIRMED / HELP_CONFIRMED / TO_VERIFY_AUTH`

Potwierdzone:
- zakup licencji do puli OSK,
- okresy dostępu prezentowane publicznie: 1, 3 i 6 miesięcy,
- ceny brutto/VAT,
- zakup wielu sztuk,
- płatności online/karta/przelew zgodnie z ofertą i regulaminem.

Do sprawdzenia:
- rabaty zależne od OSK,
- sposób wpisania ilości,
- checkout,
- komunikaty płatności,
- dostępność promocji,
- stan po nieudanej płatności.

## 6. Licencje — Panel / Generuj licencje

**Route:** `/licencje/panel`  
**Status:** `USER_CONFIRMED_AUTH_MENU / RULES_CONFIRMED / TO_VERIFY_AUTH`

Potwierdzone:
- przydzielenie licencji kursantowi,
- utworzenie dostępu przez e-mail albo login/hasło nadane przez OSK,
- wybór wariantu/okresu dostępu,
- wybór języka dostępu,
- usunięcie licencji przed aktywacją,
- automatyczny zwrot niewykorzystanej sztuki do puli,
- po aktywacji licencja jest wykorzystana,
- monitoring postępu użytkownika.

Do sprawdzenia:
- wszystkie kolumny tabeli,
- filtrowanie,
- ponowne wysyłanie danych dostępowych,
- edycja danych przed aktywacją,
- akcje masowe,
- sposób prezentacji dat ważności.

## 7. Egzamin wewnętrzny — Wykup egzaminy

**Route:** `/egzamin-wewnetrzny/wykup`  
**Status:** `USER_CONFIRMED_AUTH_MENU / PUBLIC_CONFIRMED / RULES_CONFIRMED / TO_VERIFY_AUTH`

Potwierdzone:
- zakup egzaminów do puli,
- możliwość zakupu przez panel lub przelew,
- rabaty mogą być ustalane indywidualnie,
- darmowa pula egzaminów jest elementem aktualnej oferty BIZ.

Do sprawdzenia:
- pakiety/ilości,
- aktualny mechanizm darmowego odnowienia,
- historia salda,
- checkout.

## 8. Egzamin wewnętrzny — Panel / Generuj egzamin

**Route:** `/egzamin-wewnetrzny/panel`  
**Status:** `USER_CONFIRMED_AUTH_MENU / RULES_CONFIRMED / TO_VERIFY_AUTH`

Potwierdzone:
- wygenerowanie testu dla kursanta,
- wygenerowanie linku,
- rozpoczęcie egzaminu stacjonarnie,
- wykorzystanie sztuki po wykonaniu egzaminu,
- zapis wyniku/przebiegu,
- cyfrowa karta przebiegu,
- wydruk karty.

Do sprawdzenia:
- wybór kategorii/języka,
- czas ważności linku,
- anulowanie przed startem,
- dokładne ekrany wyniku,
- historia przeprowadzonych egzaminów,
- powtórki i statusy prób.

## 9. Moje wizytówki

**Route:** `/wizytowki`  
**Status:** `USER_CONFIRMED_AUTH_MENU / PUBLIC_CONFIRMED / RULES_CONFIRMED / HELP_CONFIRMED / TO_VERIFY_AUTH`

Potwierdzone:
- OSK może posiadać/zarządzać wizytówką prezentowaną w Rankingu Szkół Jazdy,
- publiczna wizytówka zawiera informacje o OSK,
- operator posiada aktualny tutorial `Edycja wizytówki`,
- opinie są moderowane.

Wniosek z aktualnej nazwy menu `Moje wizytówki`: projekt powinien wspierać kolekcję wizytówek, a nie zakładać na sztywno jednego rekordu.

Do sprawdzenia:
- relacja `wizytówka <-> lokalizacja`,
- tworzenie/usuwanie wielu wizytówek,
- pola: nazwa, opis, kategorie, adres, kontakt, WWW, zdjęcia, godziny,
- publikacja/ukrycie,
- podgląd,
- zgłaszanie opinii.

## 10. Moje reklamy

**Menu group:** `Moje reklamy`  
**Status:** `USER_CONFIRMED_AUTH_MENU / PUBLIC_CONFIRMED / RULES_CONFIRMED / TO_VERIFY_AUTH`

Potwierdzone domenowo:
- wybór miejscowości,
- wybór placementu,
- licytacja,
- historia ofert,
- wiążąca oferta,
- płatność po wygranej,
- przesłanie kreacji desktop/mobile,
- moderacja kreacji,
- fallback tekstowy,
- emisja po płatności.

Aktualnie publiczne placementy obejmują m.in. pozycję 0, boczną górną, boczną dolną, test i kurs oraz pełnoekranową. `Wizytówka premium` jest oznaczona jako wkrótce dostępna.

Do sprawdzenia:
- dokładne podmenu `Moje reklamy`,
- lista aktywnych/wygasłych reklam,
- statystyki emisji,
- edycja kreacji,
- anulowanie/przedłużanie,
- statusy kampanii.

## 11. Wykłady

**Route:** `/wyklady`  
**Status:** `USER_CONFIRMED_AUTH_MENU / PUBLIC_CONFIRMED / TO_VERIFY_AUTH`

Potwierdzone:
- materiały wykładowe dla kategorii prawa jazdy,
- setki slajdów/animacji,
- możliwość prezentowania podczas zajęć,
- dostęp dla zweryfikowanego OSK zgodnie z ofertą.

Do sprawdzenia:
- wybór kategorii,
- lista działów,
- player/prezentacja,
- tryb pełnoekranowy,
- nawigacja między slajdami,
- przypisanie do kursanta lub kursu.

## 12. Szkolenie z instruktorem

**Route:** `/szkolenie-z-instruktorem`  
**Status:** `USER_CONFIRMED_AUTH_MENU / PUBLIC_CONFIRMED / CURRENT_PRODUCT_INFO / TO_VERIFY_AUTH`

Potwierdzone:
- lekcje pogrupowane w działy,
- materiał wideo,
- szkolenie z instruktorem i ratownikami medycznymi,
- postęp lekcji i całości szkolenia,
- pytania kontrolne na końcu działu,
- możliwość ponawiania pytań,
- możliwość pominięcia pytań kontrolnych,
- treści zależne od kategorii.

Do sprawdzenia:
- dokładna liczba działów dla każdej kategorii,
- menu OSK vs tryb prezentacyjny,
- zapis postępu w kontekście OSK.

## 13. Moja szkoła — Lokalizacje

**Route:** `/lokalizacje`  
**Status:** `USER_CONFIRMED_AUTH_MENU / HELP_CONFIRMED / TO_VERIFY_AUTH`

To był **brak w pierwszej wersji mapy**.

Operator posiada aktualny tutorial `Panel OSK ... Lokalizacje`.

Minimalny model naszego odpowiednika powinien przewidywać:
- wiele lokalizacji jednego OSK,
- nazwę/oznaczenie lokalizacji,
- adres,
- dane kontaktowe,
- aktywność/status,
- relacje do pracowników, kalendarza, wizytówek i opcjonalnie pojazdów.

Powyższe pola/relacje są rekomendacją projektową do czasu audytu ekranu. Dokładne akcje CRUD 360 pozostają `TO_VERIFY_AUTH`.

## 14. Moja szkoła — Pojazdy

**Route:** `/pojazdy`  
**Status:** `USER_CONFIRMED_AUTH_MENU / PUBLIC_CONFIRMED / HELP_CONFIRMED / TO_VERIFY_AUTH`

Potwierdzone:
- ewidencja pojazdów w panelu,
- istnieje ekran szczegółów pojazdu (aktualny materiał pomocy),
- system przypomnień obejmuje m.in. ubezpieczenia.

Do sprawdzenia:
- pola pojazdu,
- dokumenty,
- badanie techniczne,
- ubezpieczenie,
- kategoria,
- status serwis/aktywny,
- relacja z lokalizacją,
- historia zmian,
- przypisanie do kalendarza.

## 15. Moja szkoła — Pracownicy

**Route:** `/pracownicy`  
**Status:** `USER_CONFIRMED_AUTH_MENU / PUBLIC_CONFIRMED / HELP_CONFIRMED / TO_VERIFY_AUTH`

Potwierdzone:
- pracownicy są zasobem panelu,
- istnieje aktualny materiał pomocy `Pracownicy`,
- role biznesowe obejmują m.in. właściciela, instruktora, wykładowcę i administrację,
- kontekst kalendarza wymienia też biuro obsługi i kadrową,
- system przypomnień obejmuje badania pracowników.

Do sprawdzenia:
- formularz pracownika,
- szczegóły pracownika,
- role i permissions,
- uprawnienia do lokalizacji,
- dane instruktora,
- dostępność,
- dokumenty/badania,
- dezaktywacja.

## 16. Konto OSK — Ustawienia

**Route:** `/ustawienia`  
**Status:** `USER_CONFIRMED_AUTH_MENU / TO_VERIFY_AUTH`

To było wcześniej opisane zbyt ogólnie jako ustawienia konta.

Do sprawdzenia ekran po ekranie:
- dane konta,
- dane firmy,
- dane rozliczeniowe,
- hasło,
- e-mail/login,
- zgody,
- wersja zaakceptowanego regulaminu,
- bezpieczeństwo/sesje,
- powiadomienia,
- ustawienia organizacji.

Regulamin potwierdza, że w obszarze konta użytkownik ma dostęp do utrwalonej wersji zaakceptowanego regulaminu; dokładna obecna ścieżka w UI wymaga weryfikacji.

## 17. Konto OSK — Historia zakupów

**Route:** `/historia-zakupow`  
**Status:** `USER_CONFIRMED_AUTH_MENU / TO_VERIFY_AUTH`

To był **brak w pierwszej wersji mapy**. Nie należy utożsamiać tej pozycji wyłącznie z fakturami ani starą `Historią płatności` dla pojedynczego produktu.

Model naszego odpowiednika powinien obejmować wspólną historię zakupów:
- licencje,
- egzaminy wewnętrzne,
- reklamy,
- artykuły sponsorowane / inne płatne składniki,
- status zamówienia,
- kwotę brutto/VAT,
- metodę/status płatności,
- datę,
- powiązany dokument sprzedażowy, jeśli występuje.

Dokładne kolumny, filtry i akcje bieżącego 360 są `TO_VERIFY_AUTH`.

---

# Coverage check

Wszystkie aktualnie przekazane pozycje menu mają teraz osobny wpis:

- Panel główny — TAK
- Integracja PKK — TAK
- Kalendarz — TAK
- Kursanci — TAK
- Licencje / Wykup — TAK
- Licencje / Panel — TAK
- Egzamin wewnętrzny / Wykup — TAK
- Egzamin wewnętrzny / Panel — TAK
- Moje wizytówki — TAK
- Moje reklamy — TAK, podmenu nadal do zebrania
- Wykłady — TAK
- Szkolenie z instruktorem — TAK
- Lokalizacje — TAK, dodane w tym audycie
- Pojazdy — TAK
- Pracownicy — TAK
- Ustawienia — TAK
- Historia zakupów — TAK, dodane w tym audycie

## Zasada na dalszy audyt

Dla każdej z tych tras po legalnym wejściu do zalogowanego panelu zapisujemy osobno:
1. screenshot całego ekranu,
2. breadcrumb i menu,
3. wszystkie pola,
4. wszystkie przyciski/linki/menu kontekstowe,
5. filtry/sortowanie/paginację,
6. modale,
7. walidacje,
8. empty/loading/error/success states,
9. akcje masowe,
10. przejścia do ekranów szczegółów,
11. skutek biznesowy każdej akcji,
12. zmiany danych i audit requirements.
