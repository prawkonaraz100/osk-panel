# 11. Źródła i materiał dowodowy

Data pierwszego audytu: 2026-09-05.  
Data ponownej niezależnej weryfikacji: 2026-09-05.

## Metoda

Dokumentacja rozróżnia:
- `CURRENT_CONFIRMED` — aktualnie widoczna strona/funkcja lub aktualne przekierowanie chronionej trasy,
- `RULES_CONFIRMED` — zachowanie opisane w aktualnie publikowanym regulaminie,
- `HISTORICAL_INDEX` — starszy wynik indeksowania/menu; może być nieaktualny,
- `TO_VERIFY_AUTH` — wymaga legalnego wejścia na konto OSK,
- `SOURCE_CONFLICT` — różne publiczne materiały są niespójne.

Nie traktujemy snippetów starego indeksu jako dowodu na aktualny UI.

## Źródła publiczne

### Strona główna BIZ
`https://biz.prawo-jazdy-360.pl/`

Potwierdza m.in.:
- konteksty: właściciel, instruktor, wykładowca, kursant, administracja,
- pracowników, pojazdy, kalendarz i przypomnienia,
- monitoring postępów,
- kursy i kursantów,
- przypisanie jazd, wykładów i egzaminów,
- integrację PKK,
- licencje,
- publiczną wizytówkę/ranking,
- ewidencję czasu pracy,
- egzaminy wewnętrzne,
- reklamy,
- wielojęzyczność i aplikacje mobilne.

#### PKK — aktualnie opisane działania
- pobierz profil,
- pokaż szczegóły,
- aktualizuj dane szkolenia,
- zwróć do innego OSK,
- zwróć do urzędu,
- zwróć profil przedawniony,
- pokaż pełną historię operacji.

#### Kalendarz — aktualnie opisane zachowania
- widoczność m.in. dla pracownika, biura obsługi, kadrowej/HR i właściciela,
- umów jazdę kursanta sobie,
- umów jazdę innemu pracownikowi,
- udostępnij kursantowi samodzielny zapis,
- podgląd aktywności instruktorów,
- ewidencja czasu pracy.

#### Licencje
Strona opisuje, że sztuki zakupione do puli OSK nie tracą ważności przed wykorzystaniem oraz eksponuje postępy/statystyki i wielojęzyczność.

### Logowanie / rejestracja
`https://biz.prawo-jazdy-360.pl/logowanie`

Potwierdza:
- login e-mail/hasło,
- `nie wylogowuj mnie`,
- Google/Apple/Facebook,
- reset hasła,
- rejestrację właściciela i danych firmy,
- akceptację regulaminu i opcjonalną zgodę handlową.

### Przypomnienie hasła
`https://biz.prawo-jazdy-360.pl/przypomnienie-hasla`

Aktualna strona potwierdza:
- pole `Email lub login`,
- akcję inicjującą wysłanie wiadomości do zmiany hasła,
- powrót do logowania.

### Zachowanie ReturnUrl
Aktualne chronione trasy niezalogowanego użytkownika przekierowują do logowania z ReturnUrl. Zweryfikowano publicznie m.in. wejścia na obszary licencji, egzaminów, kalendarza i wykładów.

Własna implementacja powinna zachować UX powrotu do docelowej trasy, ale zabezpieczyć go przed open redirect.

### Cennik
`https://biz.prawo-jazdy-360.pl/cennik`

Potwierdza m.in.:
- licencje czasowe 1 / 3 / 6 miesięcy w aktualnym cenniku,
- zakup wielu sztuk,
- egzaminy jako produkt,
- reklamę jako produkt,
- zakres pakietu kursanta,
- PKK,
- szkolenie online z instruktorem/ratownikiem,
- podręcznik/wykłady/statystyki,
- wszystkie kategorie,
- aktualnie eksponowane 4 języki dla licencji: PL/EN/DE/UA,
- aplikacje mobilne iOS/Android/Huawei.

### Regulamin
`https://biz.prawo-jazdy-360.pl/regulamin`

Potwierdza m.in.:

#### Licencje
- zakup przez panel lub przelew,
- przypisanie dostępu kursantowi z adresem e-mail **albo** z loginem/hasłem nadanym przez OSK,
- możliwość usunięcia nieaktywowanego dostępu,
- automatyczny zwrot takiej sztuki do puli,
- wykorzystanie licencji po aktywacji.

#### Egzamin wewnętrzny
- zakup puli,
- wygenerowanie linku,
- alternatywne stacjonarne `Rozpocznij egzamin wewnętrzny`,
- wykorzystanie egzaminu po przeprowadzeniu,
- cyfrowe zachowanie karty przebiegu,
- możliwość wydruku karty.

#### Płatności / aktywacja
- płatności jednorazowe,
- szybkie płatności/karta/przelew,
- możliwość posługiwania się potwierdzeniem przelewu w opisanym procesie,
- w części produktu opisane jest osobne `Aktywuj dostęp` po zaksięgowaniu płatności.

#### Reklamy — aukcja
- licytować może zarejestrowane konto OSK,
- czas startu i końca aukcji,
- stawka początkowa i minimalne przebicie,
- `Licytuj` = wiążąca oferta,
- najwyższa poprawna oferta wygrywa,
- przy remisie wcześniejsza oferta ma pierwszeństwo,
- e-mail po wygranej,
- termin płatności i przesłania kreacji,
- kreacje desktop/mobile zależnie od placementu,
- moderacja kreacji,
- brak emisji przed płatnością,
- możliwość płatnej pomocy w przygotowaniu/modyfikacji grafiki,
- możliwa emisja tekstowa, jeśli spełnione są warunki opisane regulaminem,
- historia ofert z nazwą OSK,
- możliwość wniosku o ukrycie nazwy,
- możliwość poproszenia operatora o odrzucenie własnej oferty.

#### Artykuł sponsorowany
- treść klienta do redakcji lub przygotowanie treści przez operatora,
- materiały graficzne,
- moderacja/redakcja,
- czasowa promocja,
- późniejsza obecność w archiwum.

#### Baner partnerski
- użycie gotowego materiału,
- zakaz jego modyfikowania,
- link DoFollow,
- możliwość pomocy wdrożeniowej.

#### Ranking
- skala 1–5,
- wpływ liczby i aktualności opinii,
- opis uśredniania bayesowskiego,
- okresowe przeliczanie,
- moderacja,
- możliwość zgłoszenia opinii przez OSK,
- organicznej pozycji nie można po prostu kupić.

#### Konto / sesje
- możliwość zgłoszenia zamknięcia konta,
- możliwość blokowania przy naruszeniach,
- opis jednej aktywnej sesji dla opłaconego konta użytkownika.

### Aktualna oferta reklamowa
Publiczny ekran wyboru reklamy potwierdza:
- wybór miejscowości przed licytowaniem,
- pozycję 0,
- boczną górną,
- boczną dolną,
- test i kurs,
- pełnoekranową,
- CTA `Licytuj`,
- `Wizytówka premium` jako **„wkrótce dostępne”**.

### Aktualność produktu — 21.03.2024 „Zmiany w prawo-jazdy-360”
Źródło historyczne, ale precyzyjne funkcjonalnie. Potwierdzało wówczas:
- egzamin wewnętrzny w PL/EN/DE/UA/RU,
- raport egzaminu po polsku,
- w `Licencje -> Generuj dostęp` konieczność **jawnego wyboru języka** zamiast domyślnego PL.

Ze względu na różnicę między tym źródłem a bieżącym cennikiem, bieżąca macierz języków ma status `TO_VERIFY_AUTH` i powinna być konfigurowalna.

### Aktualność produktu — 15.01.2026 „Szkolenie z instruktorem”
Potwierdza aktualny/nowy moduł użytkownika:
- pozycję/menu `Szkolenie z instruktorem`,
- działy i lekcje,
- materiał wideo,
- pasek postępu szkolenia,
- pytania kontrolne po dziale,
- wielokrotne podejścia,
- możliwość pominięcia pytań,
- zależność programu od kategorii,
- możliwość zmiany kategorii,
- ustawianie wybranej kategorii jako domyślnej dla testu, kursu i statystyk.

### Pozycjonowanie OSK
Aktualna oferta publiczna potwierdza osobny obszar komercyjny:
- audyt strony,
- optymalizację/SEO,
- Google Ads,
- możliwość przygotowania strony WWW i panelu treści,
- bonusy handlowe zależne od oferty.

Nie jest to rdzeń operacyjny panelu OSK.

## Starszy indeks panelu — używać tylko jako `HISTORICAL_INDEX`

W starszych wynikach indeksowania widoczne były pozycje:
- Licencje: Wykup / Zarządzaj / Postępy / Historia płatności,
- Egzamin wewnętrzny: Wykup / Zarządzaj / Przeprowadzone / Historia płatności,
- Wykłady,
- reklamy w wielu placementach,
- profil w rankingu: Edytuj / Zobacz,
- Ranking szkół,
- Baner na twoją stronę,
- Pozycjonowanie szkoły,
- Faktury,
- Przeglądaj jako kursant,
- Wyloguj się.

### Ważna korekta: Faktury
Stara pozycja `Faktury` była widoczna w indeksie, ale obecny stary URL `/faktury` zwraca 404. Nie oznacza to na pewno usunięcia funkcji — mogła się przenieść — lecz status musi być:

`HISTORICAL_INDEX / TO_VERIFY_AUTH`, nie `CURRENT_CONFIRMED`.

### Wykłady
Stara pozycja była indeksowana, a aktualna trasa `/wyklady` nadal zachowuje się jak chroniony obszar wymagający logowania. To mocniejsze potwierdzenie aktualnego modułu niż w przypadku faktur.

## Znane konflikty publicznych źródeł

| Temat | Materiał A | Materiał B | Wniosek |
|---|---|---|---|
| języki | marketing: „5 języków” | bieżący cennik licencji: 4 | config per moduł |
| rosyjski w egzaminie | aktualność 2024: RU dostępny | bieżący cennik licencji nie eksponuje RU | egzamin language matrix `TO_VERIFY_AUTH` |
| liczba działów szkolenia | BIZ: jedna liczba | aktualność 2026: inna liczba dla opisywanego programu | nie hard-code |
| liczba slajdów | różne liczby w materiałach z różnych okresów | — | CMS/config |
| reklama pełnoekranowa | jeden materiał podaje 10 s | oferta/regulamin opisuje 30 s | parametr placementu |
| faktury | starsze menu | obecny stary URL 404 | historyczne / auth verification |

## Ograniczenie badania

Publiczny przycisk/flow DEMO nie daje wiarygodnego dostępu do aktualnego panelu w crawlerze. Bez legalnie uwierzytelnionego konta OSK nie można uczciwie potwierdzić:
- dokładnych formularzy,
- wszystkich aktualnych route names,
- układu dashboardu,
- pełnego RBAC,
- requestów XHR/API,
- kolumn/filtrów list,
- modalów i komunikatów błędów,
- pełnego zakresu impersonacji,
- bieżącego panelu po wygranej aukcji.

Dlatego te elementy pozostają `TO_VERIFY_AUTH` zamiast być zgadywane.
