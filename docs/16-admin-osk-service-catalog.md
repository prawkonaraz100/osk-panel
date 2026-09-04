# 16. Admin OSK — katalog usług i mapa pełnego parytetu funkcjonalnego

Data: 2026-09-05

## Cel

Od tego dokumentu głównym zakresem projektu jest **panel administracyjny OSK**. Celem nie jest kopiowanie UI ani kodu konkurencyjnego serwisu, tylko zbudowanie u siebie produktu, który pozwala OSK realizować tę samą klasę usług i procesów biznesowych.

Funkcje kursanta są opisywane wyłącznie wtedy, gdy administrator OSK nimi zarządza: tworzenie dostępu, licencja, egzamin, postęp, zapis na jazdę, szkolenie i dokumentacja.

## Definicja „zmapowane w całości”

Dla każdego modułu administratora musimy znać i udokumentować:

1. route/menu,
2. listę ekranów i podwidoków,
3. pola formularzy,
4. kolumny tabel,
5. filtry/sortowanie/wyszukiwanie/paginację,
6. przyciski i menu kontekstowe,
7. akcje pojedyncze i zbiorcze,
8. walidacje,
9. modale potwierdzające,
10. wszystkie statusy encji,
11. przejścia między statusami,
12. uprawnienia/role,
13. zależności między modułami,
14. operacje finansowe,
15. powiadomienia,
16. dokumenty/PDF/druk,
17. import/eksport, jeśli istnieje,
18. integracje zewnętrzne,
19. przypadki błędów i retry,
20. audit trail,
21. wymagania danych/retencji,
22. acceptance criteria.

Jeżeli znamy tylko istnienie route lub nazwę menu, ekran NIE jest uznany za w pełni zmapowany.

---

# A. CORE OSK — zarządzanie ośrodkiem

## A1. Panel główny

Route: `/`

Cel usługi u nas:
- operacyjny dashboard OSK,
- alerty formalne i organizacyjne,
- szybkie skróty do najczęstszych procesów,
- przegląd aktywności ośrodka.

Potencjalne obszary danych do potwierdzenia w 360:
- kursanci,
- dzisiejsze jazdy,
- instruktorzy,
- pojazdy,
- kończące się dokumenty,
- saldo licencji,
- saldo egzaminów,
- operacje PKK,
- ostatnie zakupy.

Status szczegółów: `TO_VERIFY_AUTH`.

## A2. Integracja PKK

Route: `/integracja-pkk`

Potwierdzone usługi administracyjne:
- pobierz PKK,
- pokaż szczegóły profilu,
- aktualizuj dane szkolenia,
- zwróć PKK do innego OSK,
- zwróć PKK do urzędu,
- zwróć przedawniony profil,
- pokaż historię wszystkich operacji.

Wymagania naszego odpowiednika:
- adapter integracyjny,
- status operacji,
- idempotency,
- retry,
- audit log,
- potwierdzenie operacji nieodwracalnych,
- bezpieczne przechowywanie odpowiedzi integracji.

## A3. Kalendarz

Route: `/kalendarz`

Potwierdzone usługi administracyjne:
- wspólny kalendarz OSK,
- widok dla właściciela/pracownika/biura/kadr,
- umów jazdę kursanta sobie,
- umów jazdę innemu pracownikowi,
- udostępnij kursantowi możliwość zapisu,
- szybki podgląd aktywności instruktorów,
- ewidencja czasu pracy.

Nasz pełny model powinien obsłużyć:
- instruktor,
- kursant,
- pojazd,
- lokalizacja,
- rodzaj wydarzenia,
- data/czas,
- status,
- konflikt zasobów,
- anulowanie/przeniesienie,
- historia zmian,
- opcjonalne no-show,
- self-booking,
- zakres widoczności per rola.

Dokładne UI, statusy i recurring events: `TO_VERIFY_AUTH`.

## A4. Kursanci

Route: `/kursanci`

Usługa biznesowa:
- centralna ewidencja kursantów,
- dostęp do procesów szkolenia,
- przypisywanie usług i zasobów,
- monitoring nauki.

Administrator musi docelowo móc:
- utworzyć kursanta,
- edytować dane,
- wyszukać/filtrować,
- otworzyć szczegóły,
- przypisać kurs/kategorię,
- powiązać PKK,
- przypisać licencję,
- utworzyć dostęp,
- przypisać egzamin,
- przypisać wykład/jazdę,
- sprawdzić postęp,
- archiwizować/zamykać relację.

Dokładne pola, statusy, akcje zbiorcze: `TO_VERIFY_AUTH`.

## A5. Lokalizacje

Route: `/lokalizacje`

To jest osobny zasób panelu OSK, a nie tylko adres firmy.

Nasz odpowiednik powinien wspierać multi-location:
- wiele oddziałów/punktów OSK,
- nazwa lokalizacji,
- adres,
- dane kontaktowe,
- status aktywności,
- przypisanie pracowników,
- przypisanie wizytówek,
- powiązanie kalendarza,
- opcjonalne przypisanie pojazdów,
- domyślna lokalizacja.

Dokładny CRUD i pola 360: `TO_VERIFY_AUTH`.

## A6. Pojazdy

Route: `/pojazdy`

Potwierdzone:
- pojazdy są centralnym zasobem panelu,
- istnieje ekran szczegółów pojazdu,
- system przypomina m.in. o ubezpieczeniu.

Nasz odpowiednik powinien obsługiwać:
- CRUD pojazdu,
- numer rejestracyjny,
- marka/model,
- kategoria,
- status,
- lokalizacja,
- dokumenty,
- ubezpieczenie,
- badanie techniczne,
- daty ważności,
- przypomnienia,
- niedostępność/serwis,
- wykorzystanie w kalendarzu,
- konflikt rezerwacji.

Dokładne pola 360: `TO_VERIFY_AUTH`.

## A7. Pracownicy

Route: `/pracownicy`

Potwierdzony obszar:
- właściciel,
- instruktor,
- wykładowca,
- administracja,
- kontekst kalendarza wymienia również biuro obsługi i kadry,
- przypomnienia mogą dotyczyć badań pracowników.

Nasz odpowiednik powinien obsługiwać:
- konto pracownika,
- role/permissions,
- dane osobowe i kontaktowe,
- status aktywny/nieaktywny,
- uprawnienia instruktora,
- przypisane kategorie,
- dostępność,
- lokalizacje,
- kalendarz,
- dokumenty/badania,
- daty ważności,
- przypomnienia,
- ograniczenie dostępu do finansów, PKK i ustawień.

Dokładny formularz 360: `TO_VERIFY_AUTH`.

---

# B. EDUKACJA I OBSŁUGA KURSANTA PRZEZ OSK

## B1. Licencje — zakup

Route: `/licencje/wykup`

Usługa:
- OSK kupuje pulę dostępów dla kursantów,
- zakup wielu sztuk,
- okresy 1/3/6 miesięcy w aktualnej ofercie,
- ceny brutto/VAT,
- niewykorzystane sztuki pozostają w puli do późniejszego użycia.

Wymagany model:
- produkt,
- wariant okresu,
- quantity,
- inventory,
- order/payment,
- historia zakupów,
- aktywacja entitlement po potwierdzeniu płatności.

## B2. Licencje — generowanie/przydział

Route: `/licencje/panel`

Potwierdzone akcje:
- wybierz kursanta,
- przydziel licencję,
- utwórz dostęp przez e-mail,
- albo wygeneruj login/hasło,
- wybierz język,
- usuń nieaktywowany dostęp,
- automatycznie zwróć sztukę do inventory,
- po aktywacji oznacz jako wykorzystaną,
- monitoruj postęp.

Własny state machine:
`inventory -> assigned -> activated -> expired`

Odwracalne tylko:
`assigned + not_activated -> inventory`.

Do sprawdzenia:
- resend credentials,
- edycja danych przed aktywacją,
- akcje masowe,
- filtry/statusy tabeli,
- dokładne daty ważności.

## B3. Postęp nauki

Nie musi być osobną pozycją menu w obecnym menu przekazanym przez użytkownika, ale jest elementem usługi licencji i obsługi kursanta.

Potwierdzone biznesowo:
- OSK może śledzić statystyki kursantów i postęp nauki.

Nasz odpowiednik powinien pokazywać co najmniej:
- aktywacja licencji,
- ważność,
- postęp materiałów,
- pytania/testy,
- statystyki wyniku,
- ostatnią aktywność.

Dokładny zakres 360: `TO_VERIFY_AUTH`.

## B4. Egzamin wewnętrzny — zakup

Route: `/egzamin-wewnetrzny/wykup`

Usługa:
- OSK kupuje sztuki egzaminów,
- może posiadać darmową odnawianą pulę według oferty,
- rabaty mogą być indywidualne,
- zakup przez panel lub przelew zgodnie z regulaminem.

Wymagany model:
- exam inventory,
- paid/free grant,
- quantity,
- balance,
- order/payment,
- historia zakupów.

## B5. Egzamin wewnętrzny — generowanie i przeprowadzenie

Route: `/egzamin-wewnetrzny/panel`

Potwierdzone akcje:
- wybierz kursanta,
- wygeneruj egzamin,
- wygeneruj link,
- rozpocznij egzamin lokalnie/stacjonarnie,
- zapisz wynik,
- zużyj sztukę po przeprowadzeniu,
- wygeneruj kartę przebiegu,
- zachowaj kartę cyfrowo,
- wydrukuj kartę.

Własny state machine:
`available -> generated/assigned -> started -> finished -> consumed`.

Do sprawdzenia:
- język/kategoria,
- ważność linku,
- unieważnienie przed startem,
- ponowny egzamin,
- historia prób,
- dokładny raport.

## B6. Wykłady

Route: `/wyklady`

Usługa administracyjna:
- OSK otrzymuje zasoby wykładowe do prowadzenia zajęć,
- wybór kategorii,
- prezentowanie slajdów/animacji/materiałów.

Nasz odpowiednik powinien oddzielić:
- katalog materiałów,
- kategorię,
- dział/lekcję,
- tryb prezentacyjny,
- pełny ekran,
- nawigację,
- ewentualne przypisanie do kursu/uczestników.

## B7. Szkolenie z instruktorem

Route: `/szkolenie-z-instruktorem`

Potwierdzone:
- lekcje w działach,
- wideo,
- postęp lekcji i szkolenia,
- pytania kontrolne,
- możliwość ponowienia,
- możliwość pominięcia pytań,
- treści zależne od kategorii.

Dla panelu OSK istotne jest:
- umożliwienie korzystania z materiału podczas szkolenia,
- dostęp do kategorii,
- ewentualny podgląd postępu kursanta,
- przypisanie do szkolenia, jeśli występuje.

Dokładne działania administratora 360: `TO_VERIFY_AUTH`.

---

# C. MARKETING I POZYSKIWANIE KLIENTÓW DLA OSK

## C1. Moje wizytówki

Route: `/wizytowki`

Usługa:
- wizytówka OSK w rankingu,
- publiczna prezentacja szkoły,
- kontakt z potencjalnymi kursantami,
- opinie i ranking.

Ze względu na nazwę `Moje wizytówki` nasz model powinien wspierać wiele rekordów, np. per lokalizacja.

Do zbudowania:
- collection/list,
- create/edit,
- powiązanie z lokalizacją,
- dane kontaktowe,
- opis,
- kategorie,
- zdjęcia,
- godziny,
- status publikacji,
- publiczny preview,
- opinie,
- zgłoszenie opinii do moderacji.

Dokładne pola 360: `TO_VERIFY_AUTH`.

## C2. Moje reklamy

Menu group: `Moje reklamy`

Usługa handlowa:
- lokalne placementy reklamowe,
- wybór miejscowości,
- aukcja,
- zakup emisji,
- dostarczenie kreacji,
- moderacja,
- emisja.

Potwierdzone placementy publiczne:
- pozycja 0,
- boczna górna,
- boczna dolna,
- reklama w teście i kursie,
- cała strona,
- premium wizytówka jako `COMING_SOON`.

Aukcja powinna obsługiwać:
- start/end,
- opening price,
- minimum increment,
- binding bid,
- highest bid,
- tie-break earlier bid,
- history,
- winner,
- payment deadline,
- creative deadline,
- desktop/mobile creative,
- moderation,
- text fallback,
- activation only after payment.

Child-routes aktualnego menu: `TO_VERIFY_AUTH`.

## C3. Ranking/opinie

Powiązany z wizytówkami.

Potwierdzone:
- oceny 1–5,
- moderacja,
- możliwość zgłoszenia opinii przez OSK,
- liczba i aktualność opinii wpływają na ranking,
- ranking używa mechanizmu uśredniania,
- organicznej pozycji nie kupuje się bezpośrednio.

Nasz odpowiednik:
- review moderation workflow,
- report review,
- ranking score history,
- lokalizacja/ranking per miasto,
- public profile.

## C4. Artykuł sponsorowany

Usługa płatna potwierdzona regulaminem.

Nasz odpowiednik powinien pozwalać:
- kupić usługę,
- dostarczyć treść albo zlecić przygotowanie,
- dostarczyć zdjęcia,
- przekazać do moderacji/redakcji,
- publikować przez ustalony okres,
- pozostawić w archiwum zgodnie z warunkami produktu.

Nie musi być główną pozycją menu v1, ale musi istnieć w katalogu usług OSK, jeśli chcemy mieć parytet oferty.

## C5. Baner partnerski na stronę OSK

Usługa partnerska:
- gotowy materiał,
- pobranie,
- umieszczenie na własnej stronie,
- reguły użycia i linkowania.

Dla naszego produktu może to być generator widgetu/bannera partnerskiego.

## C6. Usługi SEO/WWW/Google Ads

Publiczna oferta 360 obejmuje również:
- audyt strony,
- optymalizację SEO,
- Google Ads,
- budowę strony z panelem CMS,
- bonusy licencyjne/egzaminacyjne/promocyjne.

To nie jest core SaaS OSK, lecz osobna kategoria `commercial_services`. Jeśli chcemy oferować te same typy usług handlowych, trzeba mieć lead/service-order workflow, ale nie mieszać go z rdzeniem panelu.

---

# D. KONTO, ROZLICZENIA I ADMINISTRACJA

## D1. Ustawienia

Route: `/ustawienia`

Administrator OSK powinien mieć:
- dane użytkownika,
- dane organizacji,
- dane kontaktowe,
- dane rozliczeniowe,
- hasło/logowanie,
- zgody,
- bezpieczeństwo/sesje,
- ustawienia powiadomień,
- ustawienia organizacyjne.

Dokładne zakładki 360: `TO_VERIFY_AUTH`.

## D2. Historia zakupów

Route: `/historia-zakupow`

To jest osobny ekran aktualnego menu i musi być traktowany jako **wspólny ledger zakupów OSK**, nie tylko historia jednego produktu.

Nasz odpowiednik powinien łączyć:
- licencje,
- egzaminy,
- reklamy,
- artykuły sponsorowane,
- inne płatne usługi,
- order id,
- data,
- produkt,
- ilość,
- netto/brutto/VAT,
- metoda płatności,
- status płatności,
- status realizacji,
- dokument sprzedażowy, jeśli dotyczy.

Dokładne kolumny i przyciski 360: `TO_VERIFY_AUTH`.

## D3. Faktury/dokumenty sprzedażowe

Starszy indeks wskazywał osobną pozycję `Faktury`, ale aktualna stara trasa nie jest potwierdzona jako działająca.

Decyzja dla naszego produktu:
- dokumenty sprzedażowe powinny być dostępne z Historii zakupów,
- osobny moduł faktur jest opcjonalny,
- nie zakładamy identycznej aktualnej nawigacji 360 bez audytu zalogowanego panelu.

---

# E. USŁUGI SYSTEMOWE WYMAGANE DO PARYTETU PRODUKTOWEGO

Poniższe funkcje nie muszą mieć osobnej pozycji menu, ale bez nich panel nie będzie kompletnym produktem OSK:

## E1. Role i uprawnienia
- Owner,
- administracja/biuro,
- instruktor,
- wykładowca,
- kadry,
- opcjonalna księgowość.

Permissions powinny być granularne per moduł i lokalizacja.

## E2. Powiadomienia/przypomnienia
- ubezpieczenie pojazdu,
- badanie techniczne,
- badania pracownika,
- jazda/zmiana terminu,
- płatność,
- licencja,
- egzamin,
- błąd PKK.

## E3. Audit log
Wymagany dla naszego systemu dla:
- PKK,
- licencji,
- egzaminów,
- finansów,
- ról,
- kalendarza,
- zmian formalnych danych.

## E4. Multi-tenancy
Każde OSK jest tenantem. Brak możliwości dostępu do zasobów innego OSK przez ID/API.

## E5. Dokumenty i retencja
- dokumenty pracowników,
- pojazdów,
- kart egzaminu,
- dokumenty zakupu,
- historia formalnych operacji.

---

# F. Mapa oferty, którą możemy sprzedawać OSK

Po pełnym wdrożeniu nasz produkt może oferować OSK następujące klasy usług:

1. **Darmowy/abonamentowy panel zarządzania OSK** — kursanci, pracownicy, pojazdy, lokalizacje, kalendarz.
2. **Integracja PKK** — procesy formalne bez przełączania systemu.
3. **Licencje e-learningowe** — hurtowy zakup i wydawanie dostępów kursantom.
4. **Monitoring nauki kursantów**.
5. **Egzaminy wewnętrzne online/stacjonarne** + karta przebiegu.
6. **Wykłady i szkolenie z instruktorem** jako materiały dla OSK.
7. **Wizytówki/ranking/opinie** jako pozyskiwanie klientów.
8. **Reklamy lokalne** z aukcją i emisją.
9. **Artykuły sponsorowane / promocja OSK**.
10. **Historia zakupów i rozliczenia**.
11. **Usługi dodatkowe SEO/WWW/Google Ads** jako osobny pion handlowy, jeśli chcemy pełnego parytetu oferty.

---

# G. Co jest jeszcze konieczne przed rozpoczęciem implementacji „1:1 funkcjonalnej” admina

Największe luki wymagające autoryzowanego przejścia ekranów:

1. Dashboard — wszystkie widgety i quick actions.
2. Kursanci — dokładny formularz, tabela, menu akcji, statusy.
3. Lokalizacje — CRUD i relacje.
4. Pojazdy — pełny formularz i dokumenty.
5. Pracownicy — pola, role, permissions, dokumenty.
6. Kalendarz — exact event editor, widoki, statusy, drag/drop, recurring.
7. PKK — exact inputs, modal confirmations, error states.
8. Licencje panel — tabela, filtry, credentials, resend, batch actions.
9. Egzamin panel — tabela, język/kategoria, link lifecycle, historia prób.
10. Wizytówki — multi-record behavior, pola, zdjęcia, publikacja.
11. Moje reklamy — child menu, active/history, creatives, statystyki.
12. Wykłady — player i wybór materiałów.
13. Szkolenie z instruktorem — admin actions vs student presentation.
14. Ustawienia — zakładki i pola.
15. Historia zakupów — kolumny, dokumenty, filtry, szczegóły zamówienia.

Dopiero po zamknięciu powyższych punktów możemy powiedzieć, że **panel admina OSK jest zmapowany button-po-button**.
