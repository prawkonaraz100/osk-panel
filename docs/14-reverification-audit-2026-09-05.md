# 14. Ponowna weryfikacja funkcjonalności — 2026-09-05

## Cel

Drugi audyt został wykonany niezależnie od pierwszej mapy. Ponownie sprawdzono publiczną stronę BIZ, aktualne przekierowania chronionych tras, cennik, regulamin, ofertę reklamową, stronę resetu hasła, aktualności produktowe oraz starsze wyniki indeksowania panelu.

## Klasy pewności

- `CURRENT_CONFIRMED` — potwierdzone na aktualnej stronie lub aktualnym działającym endpointcie.
- `RULES_CONFIRMED` — potwierdzone w aktualnie publikowanym regulaminie.
- `HISTORICAL_INDEX` — widoczne w starszym zindeksowanym panelu; nie wolno zakładać, że nadal istnieje.
- `INFERRED` — wymagane projektowo, ale niepotwierdzone jako funkcja badanego systemu.
- `TO_VERIFY_AUTH` — wymaga wejścia do panelu po zalogowaniu.
- `SOURCE_CONFLICT` — publiczne źródła podają niespójne informacje.

## Najważniejsze nowe ustalenia

### Uwierzytelnianie

Aktualnie potwierdzone:
- logowanie e-mail + hasło,
- pamiętanie sesji,
- Google / Apple / Facebook,
- rejestracja OSK,
- reset hasła przyjmujący **e-mail lub login**,
- zachowanie `ReturnUrl` po wejściu na chronioną trasę.

Nowe akcje do modelu:
- `request_password_reset_by_email_or_login`,
- `resume_return_url_after_auth`.

### Licencje

Aktualny regulamin potwierdza dwa sposoby utworzenia dostępu kursanta:
1. konto z adresem e-mail,
2. nadany przez OSK login i hasło.

Nieaktywowany dostęp można skasować, a sztuka automatycznie wraca do puli. Po aktywacji jest wykorzystana.

Ważne rozróżnienie domenowe:
- **sztuka licencji w puli OSK** może czekać na wykorzystanie,
- **przydzielony pakiet kursanta** ma wybrany okres dostępu (publiczny cennik pokazuje 1, 3 i 6 miesięcy).

Aktualności produktowe z 2024 r. potwierdzają, że przy generowaniu dostępu wybór języka nie powinien mieć automatycznego domyślnego PL; użytkownik OSK musi jawnie wybrać język.

Nowe akcje:
- `create_student_access_by_email`,
- `create_student_access_by_credentials`,
- `select_license_language`,
- `delete_unactivated_assignment_and_restore_inventory`.

### Pakiet i aktywacja po zakupie

Regulamin opisuje dodatkowy stan pomiędzy płatnością a rozpoczęciem dostępu: po zaksięgowaniu płatności użytkownik może wykonać akcję **„Aktywuj dostęp”**. Aktywacja rozpoczyna dostarczanie treści/usługi i wiąże się z komunikatem o skutkach prawnych.

Model powinien rozdzielać:
`ordered -> paid -> activation_available -> activated -> expired`.

### Egzamin wewnętrzny

Potwierdzone są dwa osobne flow:
- wygenerowanie linku dla kursanta,
- stacjonarne `Rozpocznij egzamin wewnętrzny`.

Po wykonaniu egzamin jest wykorzystany. Kartę przebiegu można zachować cyfrowo i wydrukować.

Aktualność z 21.03.2024 potwierdza historycznie możliwość egzaminów PL/EN/DE/UA/RU przy raporcie generowanym po polsku. Ponieważ bieżący publiczny cennik eksponuje cztery języki przy licencjach, aktualny zakres językowy egzaminu oznaczamy `TO_VERIFY_AUTH`.

### Szkolenie z instruktorem

Aktualność z 15.01.2026 potwierdza osobny moduł użytkownika `Szkolenie z instruktorem`:
- lekcje w działach,
- materiał wideo,
- pasek postępu lekcji odnoszący się także do całości szkolenia,
- pytania kontrolne na końcu działu,
- możliwość wielokrotnego rozwiązywania pytań kontrolnych,
- możliwość pominięcia pytań i przejścia do następnej lekcji,
- treść zależną od kategorii prawa jazdy,
- możliwość zmiany kategorii konta.

Nowe akcje:
- `open_instructor_training`,
- `play_training_lesson`,
- `record_training_progress`,
- `start_control_questions`,
- `retry_control_questions`,
- `skip_control_questions`,
- `change_default_driving_category`.

### Kategorie

W aktualnym serwisie kursanta zmiana kategorii ustawia kategorię domyślną konta, a test, kurs i statystyki są następnie automatycznie filtrowane według tej kategorii. Pakiet daje dostęp do wszystkich obsługiwanych kategorii i kategorię można zmieniać.

To wymaga modelu `user_learning_preference.default_category` zamiast przypisywania jednej niezmiennej kategorii do konta.

### Kalendarz i role organizacyjne

Bieżąca strona główna wymienia widoczność kalendarza dla:
- pracownika,
- biura obsługi,
- kadrowej,
- właściciela.

Potwierdzone akcje:
- umów jazdę kursanta sobie,
- umów jazdę innemu pracownikowi,
- opublikuj możliwość samodzielnego zapisu,
- podgląd aktywności instruktorów,
- ewidencja czasu pracy.

`HR/kadrowa` i `office/biuro` należy traktować jako osobne profile uprawnień albo zestawy permissions, nawet jeśli finalnie technicznie będą rolami konfigurowalnymi.

### Reklamy — pełny mechanizm aukcyjny

Drugi audyt potwierdził znacznie więcej akcji niż pierwsza mapa.

Aktualna publiczna oferta wymaga wyboru miejscowości przed licytowaniem i pokazuje placementy:
- pozycja 0,
- boczna górna,
- boczna dolna,
- test i kurs,
- cała strona,
- `Wizytówka premium` — oznaczona jako „wkrótce dostępne”.

Regulamin potwierdza:
- aukcja tylko dla zarejestrowanego konta OSK,
- czas startu i końca,
- stawkę początkową,
- minimalne przebicie,
- wiążącą ofertę po `Licytuj`,
- wygrywa najwyższa oferta >= minimum,
- przy remisie wygrywa wcześniejsza oferta,
- e-mail z potwierdzeniem wygranej,
- 3 dni robocze na zapłatę,
- 3 dni robocze na przesłanie grafiki,
- brak grafiki może skutkować emisją tekstową,
- możliwość odpłatnego zlecenia przygotowania/modyfikacji kreacji,
- moderację/akceptację kreacji,
- emisję dopiero po płatności,
- historię licytacji z nazwą OSK (z możliwością wnioskowania o ukrycie),
- możliwość poproszenia sprzedawcy o odrzucenie złożonej oferty.

Nowe akcje:
- `select_ad_city`,
- `view_auction_terms`,
- `place_binding_bid`,
- `request_bid_rejection`,
- `view_bid_history`,
- `request_bidder_name_hiding`,
- `upload_desktop_creative`,
- `upload_mobile_creative`,
- `request_creative_service`,
- `approve_or_reject_creative` (operator),
- `activate_text_fallback`,
- `activate_paid_campaign`.

### Artykuł sponsorowany

Pierwsza mapa wymieniała usługę, ale nie flow. Regulamin potwierdza:
- tekst może przygotować operator lub klient może dostarczyć treść do redakcji,
- limity treści/zdjęć,
- moderację,
- ekspozycję sponsorowaną przez miesiąc,
- dalszą dostępność artykułu w archiwum aktualności.

Moduł traktujemy jako osobny produkt promocyjny, nie wariant zwykłego banera.

### Baner na stronę OSK

Regulamin potwierdza zasady użycia materiału partnerskiego:
- pobranie gotowej grafiki,
- bez edycji materiału,
- wymagany link DoFollow do serwisu,
- opcjonalna pomoc operatora we wdrożeniu HTML/grafiki.

### Płatności

Potwierdzone:
- płatności jednorazowe, nie subskrypcja,
- szybki przelew internetowy,
- karta,
- przelew bankowy,
- regulamin opisuje także przekaz/przelew pocztowy,
- możliwość przesłania potwierdzenia przelewu,
- ceny brutto/VAT.

### Konto — zamknięcie, blokady, pojedyncza sesja

Regulamin potwierdza:
- możliwość zażądania zamknięcia konta,
- reklamacje,
- blokowanie kont/adresów przy naruszeniach,
- dla opłaconego konta użytkownika zasadę jednej aktywnej sesji; nowa sesja wylogowuje poprzednią.

Nie należy automatycznie przenosić zasady jednej sesji na wszystkie konta pracowników OSK — dokładny zakres sprawdzamy po zalogowaniu.

### Ranking i opinie

Potwierdzone:
- skala 1–5,
- liczba i aktualność opinii wpływają na ranking,
- uśrednienie bayesowskie,
- okresowe przeliczenie rankingu,
- zamknięte OSK bez pozycji,
- moderacja opinii,
- możliwość zgłoszenia opinii przez OSK do ponownej analizy,
- brak możliwości kupienia wyższej organicznej pozycji rankingu.

### Pozycjonowanie

Publiczna oferta zawiera dodatkowy produkt/usługę:
- audyt strony,
- optymalizację,
- SEO,
- Google Ads,
- możliwość stworzenia strony i panelu treści,
- bonusy w postaci licencji, egzaminów i artykułów sponsorowanych.

To nie jest rdzeń zarządzania OSK; powinno zostać wydzielone jako `commercial_services / lead_generation`.

## Korekty względem pierwszej wersji dokumentacji

1. `Faktury` — **nie są już CURRENT_CONFIRMED**. Były widoczne w starszym indeksie panelu, ale obecny `/faktury` zwraca 404. Status: `HISTORICAL_INDEX / TO_VERIFY_AUTH`.
2. `export_progress` — brak publicznego dowodu. Status: `INFERRED`, nie `CONFIRMED`.
3. `pause_campaign` — brak publicznego dowodu po stronie klienta. Usunięte z listy potwierdzonych akcji.
4. `refund` jako funkcja panelu — brak potwierdzenia. Regulamin opisuje odstąpienie/reklamacje, ale nie panelowy przycisk refundacji.
5. `dashboard`, dokładne listy kursantów, pracowników i pojazdów — nadal wymagają autoryzowanego wejścia.
6. Publiczny przycisk `DEMO` w crawlerze nie otwiera panelu demonstracyjnego — wraca do strony publicznej.

## Konflikty źródeł

| Temat | Źródło A | Źródło B | Decyzja projektowa |
|---|---|---|---|
| liczba języków | strona główna: „aż 5” | cennik licencji: 4 (PL/EN/DE/UA) | macierz języków per moduł; nie globalna liczba |
| rosyjski w egzaminie | aktualność 2024: PL/EN/DE/UA/RU | bieżący cennik nie eksponuje RU | `TO_VERIFY_AUTH` |
| liczba działów szkolenia | marketing BIZ: 18 | aktualność 2026: 16 dla opisywanego szkolenia | treść CMS/config, nie hard-code |
| liczba slajdów | różne miejsca: 740 / 750+ | różne daty publikacji | nie hard-code; dane katalogowe |
| reklama pełnoekranowa | strona główna wspomina 10 s | oferta/regulamin mówi 30 s | `SOURCE_CONFLICT`, wartość konfigurowalna |
| faktury | starszy indeks: menu Faktury | obecny `/faktury`: 404 | historyczne, nie wymaganie v1 |

## Co pozostaje niemożliwe do potwierdzenia bez logowania

- dokładny dashboard,
- formularz kursanta,
- formularz pracownika i pełne RBAC,
- formularz pojazdu,
- szczegóły UI PKK i komunikaty integracji,
- kolumny/filtry list licencji,
- aktualna macierz języków egzaminu,
- konfiguracja i ekran wyniku egzaminu,
- aktualne trasy `Postępy`, `Historia płatności`, `Przeprowadzone egzaminy`,
- czy `Faktury` zostały przeniesione pod inną trasę,
- dokładny zakres `Przeglądaj jako kursant`,
- aktualny panel zarządzania kampanią po wygranej aukcji.

Nie wolno implementować tych detali jako „identycznych z 360” bez potwierdzenia. Można natomiast zaprojektować własny odpowiednik funkcjonalny zgodny z wymaganiami PrawkoNaRaz.
