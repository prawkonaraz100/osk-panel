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
| Panel główny | `READY_FOR_IMPLEMENTATION` | Cztery główne widgety: Licencje, Egzaminy wewnętrzne, Powiadomienia/activity feed i osadzony Kalendarz. Licencje pokazują aktywne/dostępne oraz skróty do przydzielenia/zakupu; egzaminy pokazują dostępną pulę i skróty do przeprowadzenia/zakupu; activity feed rejestruje operacje na kursantach, kursach, płatnościach, licencjach, egzaminach, wydarzeniach i reklamach wraz z czasem, aktorem i linkami; kalendarz ma dzień/tydzień/miesiąc, nawigację, pełny kalendarz i dodanie wydarzenia. |
| Integracja PKK | `READY_FOR_IMPLEMENTATION` | Strona informacyjna potwierdza operacje: pobranie profilu, podgląd, aktualizacja szkolenia i zwrot, zwrot do innego OSK, zwrot do urzędu, zwrot profilu przedawnionego i historia operacji. Operacyjny panel przy konkretnym kursie potwierdza model `kursant -> kurs -> PKK -> historia`, pola karty kursu, `Pobierz PKK`, `Podgląd PKK`, `Aktualizuj i zwróć PKK`, historię operacji i stan danych pobranych. Drawer `Zarządzaj PKK` jest obecnie nieobserwowalny z powodu błędu/zawieszania strony konkurenta; projektujemy go bezpiecznie po swojemu i nie traktujemy tego jako blocker. |
| Kalendarz | `READY_FOR_IMPLEMENTATION` | Widok kalendarza, zasoby: pracownicy/pojazdy/lokalizacje, typy Wydarzenie/Jazda/Ważne daty, formularz dodania wydarzenia, własne miejsce spotkania, powiązania z kursantem/instruktorem/pojazdem/lokalizacją. Nieobserwowalne operacje po utworzeniu projektujemy elastycznie. |
| Kursanci | `READY_FOR_IMPLEMENTATION` | Lista, podgląd, dodawanie, edycja, PESEL/data urodzenia, filtry, sortowanie, etapy szkolenia, profil, licencje, dostępy, płatności, postępy, egzamin wewnętrzny, archiwizacja/usuwanie. |
| Kursy (PKK) / kurs formalny kursanta | `READY_FOR_IMPLEMENTATION` | Dodawanie kursu, edycja i potwierdzenie usuwania; rodzaj szkolenia, kategoria, PKK, data+godzina rozpoczęcia, koszt/relacja z płatnością, godziny teorii i praktyki w bieżącym OSK oraz odbyte w innej szkole, instruktor i lokalizacja. Karta kursu pokazuje kategorię/PKK, ostatnią operację PKK, historię operacji i akcje PKK. Usunięcie kursu pokazuje powiązaną płatność i wpłaty; we własnym systemie stosujemy soft-delete/anulowanie z audytem. |
| Licencje — zakup | `READY_FOR_IMPLEMENTATION` | 1/3/6 miesięcy, ilości, mieszany koszyk, rabaty/cennik konfigurowalny, PayU/przelew, podsumowanie. |
| Licencje — panel/generowanie | `READY_FOR_IMPLEMENTATION` | Pule 31/90/180 dni, dostępne/aktywne, przydzielenie 1 kursantowi, języki, istniejący/nowy dostęp, nieaktywne i aktywne licencje, przedłużanie/stacking, usuwanie przed aktywacją, rozwinięcie historii, sortowanie, wielokrotny PDF dostępów. |
| Egzamin wewnętrzny — zakup | `READY_FOR_IMPLEMENTATION` | Zakup puli sztuk, cena dynamiczna, PayU/przelew, podsumowanie. |
| Egzamin wewnętrzny — panel | `READY_FOR_IMPLEMENTATION` | Pule darmowe/opłacone, lista kursantów/agregat prób, generowanie, remote link/local station, edycja danych, filtry, sortowanie, wyszukiwanie, historia prób, wynik, review pytań, PDF arkusza odpowiedzi, formalne powiązanie z kursantem i kursem, elastyczne zwolnienia z teorii. |
| Moje wizytówki | `NOT_SCREEN_MAPPED` | Znana domena/publiczna wizytówka i help, brak pełnego bieżącego ekranu CRUD. |
| Moje reklamy | `NOT_SCREEN_MAPPED` | Zmapowana domena aukcji/reklam z publicznych źródeł, brak aktualnego zalogowanego podmenu i bieżących ekranów kampanii. |
| Wykłady | `NOT_SCREEN_MAPPED` | Znany produkt/funkcja, brak audytu bieżącego zalogowanego UI. |
| Szkolenie z instruktorem | `NOT_SCREEN_MAPPED` | Znana struktura produktu i ogólne działanie, brak audytu bieżącego zalogowanego UI. |
| Moja szkoła — Lokalizacje | `READY_FOR_IMPLEMENTATION` | Lista, typ/nazwa/adres, link do kalendarza, pełny create i edit flow, typy `Filia`, `Sala wykładowa`, `Plac manewrowy`, wymagane pola adresowe, wyszukiwany katalog miejscowości, możliwość zmiany rodzaju, widoczna akcja archiwizacji. Archiwizacja jest zablokowana w demo, więc dokładny modal konkurenta jest nieobserwowalny; u nas przyjmujemy soft-archive z audytem, zachowaniem historii, filtrem zarchiwizowanych i możliwością przywrócenia. |
| Moja szkoła — Pojazdy | `READY_FOR_IMPLEMENTATION` | Lista, szczegóły, dodaj, edytuj, usuń, archiwizacja obserwowana jako zablokowana w demo, rejestracja/nr boczny/marka/model/rok/pojemność/VIN/przegląd/OC/AC/kategorie/lokalizacje/zdjęcie, kalendarz. |
| Moja szkoła — Pracownicy | `READY_FOR_IMPLEMENTATION` | Lista, szczegóły, dodaj, edytuj, usuń/archiwizuj, rodzaj pracownika, PESEL, telefon, nr uprawnień, kategorie, lokalizacje, ważność legitymacji/badań, zdjęcie, konto do logowania, dostęp do panelu, kalendarz. |
| Konto OSK — Ustawienia | `READY_FOR_IMPLEMENTATION` | Dane podstawowe, dane firmy, dane API PKK, nazwa szkoły, nr ewidencyjny OSK, login OSK, link do zaakceptowanego regulaminu. |
| Konto OSK — Historia zakupów | `READY_FOR_IMPLEMENTATION` | Lista zamówień, pozycje, data, data księgowania, kwota, status, akcja Opłać dla nieopłaconych, paginacja/liczba pozycji. |

## Wniosek

Core operacyjny OSK jest już wystarczająco zmapowany do rozpoczęcia projektowania i implementacji:

`Panel główny + Integracja PKK + Kursanci + Kursy (PKK) + Kalendarz + Licencje + Egzamin wewnętrzny + Lokalizacje + Pojazdy + Pracownicy + Ustawienia + Historia zakupów`.

Nieobserwowalne detale zablokowane przez tryb demo lub błędy serwisu konkurenta nie są blockerami — dla takich miejsc stosujemy własny bezpieczny lifecycle, audit trail i odwracalne operacje tam, gdzie ma to sens.

Pozostałe niezmapowane moduły (`Moje wizytówki`, `Moje reklamy`, `Wykłady`, `Szkolenie z instruktorem`) nie blokują budowy core admin OSK.
