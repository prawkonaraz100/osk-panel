# 01. Mapa funkcjonalności

> Druga weryfikacja publicznych źródeł: 2026-09-05. Szczegółowy protokół: `14-reverification-audit-2026-09-05.md`.
>
> Klasy pewności: `CURRENT_CONFIRMED`, `RULES_CONFIRMED`, `HISTORICAL_INDEX`, `INFERRED`, `TO_VERIFY_AUTH`, `SOURCE_CONFLICT`.

## 1. Uwierzytelnianie i konto OSK

**Status:** `CURRENT_CONFIRMED`

### Funkcje
- logowanie e-mail + hasło,
- „nie wylogowuj mnie”,
- reset hasła przyjmujący **e-mail lub login**,
- logowanie społecznościowe: Google, Apple, Facebook,
- rejestracja właściciela/OSK,
- dane osoby: imię, nazwisko, e-mail, hasło,
- dane firmy: nazwa, NIP, adres, telefon,
- akceptacja regulaminu,
- opcjonalna zgoda marketingowa,
- zachowanie `ReturnUrl` przy wejściu na chroniony ekran przed logowaniem,
- wylogowanie,
- możliwość zgłoszenia żądania zamknięcia konta,
- możliwość blokady konta przy naruszeniach regulaminu.

### Akcje
`register`, `login`, `social_login`, `logout`, `request_password_reset_by_email_or_login`, `reset_password`, `resume_return_url_after_auth`, `update_account`, `update_company`, `withdraw_marketing_consent`, `request_account_closure`.

### Ważna reguła sesji
Regulamin potwierdza dla opłaconego konta użytkownika zasadę jednej aktywnej sesji — uruchomienie kolejnej powoduje wylogowanie wcześniejszej. Zakres tej zasady dla kont pracowników OSK jest `TO_VERIFY_AUTH`; nie należy automatycznie rozszerzać jej na cały personel.

---

## 2. Organizacja OSK i profil firmy

**Status:** `CURRENT_CONFIRMED / HISTORICAL_INDEX`

### Funkcje
- dane identyfikacyjne OSK,
- profil publiczny/wizytówka,
- edycja profilu w rankingu (`HISTORICAL_INDEX` dla dokładnego ekranu),
- podgląd publicznego profilu,
- powiązanie profilu z opiniami,
- dane kontaktowe i lokalizacja,
- konfiguracja widoczności (`TO_VERIFY_AUTH` dla pól i zakresu).

### Akcje
`edit_school_profile`, `preview_school_profile`, `publish_school_profile`, `view_reviews`, `report_review`.

---

## 3. Integracja PKK

**Status:** `CURRENT_CONFIRMED`

### Funkcje
- pobranie Profilu Kandydata na Kierowcę,
- podgląd szczegółów profilu,
- aktualizacja danych szkolenia,
- zwrot PKK do innego OSK,
- zwrot PKK do urzędu,
- zwrot profilu przedawnionego,
- pełna historia operacji PKK.

### Wymagana logika projektowa
- każda operacja PKK musi być audytowalna,
- status operacji: `requested`, `processing`, `success`, `failed`,
- zapis aktora, timestampu, payloadu wejściowego oraz bezpiecznej odpowiedzi,
- operacje zewnętrzne idempotentne,
- kontrolowany retry i rozróżnienie błędów walidacji od błędów integracji.

### Akcje
`fetch_pkk`, `view_pkk`, `update_pkk_training`, `return_pkk_to_school`, `return_pkk_to_authority`, `return_expired_pkk`, `view_pkk_history`, `retry_failed_pkk_operation` (`retry` jest `INFERRED` jako wymaganie architektoniczne).

---

## 4. Kursanci

**Status:** `CURRENT_CONFIRMED / TO_VERIFY_AUTH`

### Funkcje potwierdzone
- tworzenie kursanta/konta dostępowego w kontekście licencji,
- dwa sposoby dostępu kursanta:
  1. przez jego adres e-mail,
  2. przez login i hasło nadane przez OSK,
- przypisywanie licencji,
- przypisywanie jazd,
- przypisywanie wykładów,
- przypisywanie egzaminu wewnętrznego,
- monitoring postępów,
- wybór języka przy generowaniu dostępu — historyczna aktualizacja produktu potwierdza jawny wybór zamiast automatycznego PL.

### Szczegóły nadal do weryfikacji
- komplet pól kartoteki kursanta,
- statusy formalne kartoteki,
- masowe akcje,
- archiwizacja i skutki archiwizacji poza opisanymi w regulaminie statystykami.

### Akcje
`create_student`, `edit_student` (`TO_VERIFY_AUTH`), `archive_student` (`TO_VERIFY_AUTH`), `enroll_student`, `create_student_access_by_email`, `create_student_access_by_credentials`, `select_license_language`, `assign_license`, `assign_lecture`, `schedule_drive`, `assign_internal_exam`, `view_student_progress`, `impersonate_student_view` (`HISTORICAL_INDEX`).

---

## 5. Pracownicy / instruktorzy / biuro / kadry

**Status:** `CURRENT_CONFIRMED / TO_VERIFY_AUTH`

Publiczna oferta potwierdza co najmniej konteksty: właściciel, instruktor, wykładowca, pracownik, biuro obsługi, kadrowa/HR i kursant. Nie przesądza to jeszcze, czy są to sztywne role techniczne, czy zestawy uprawnień.

### Funkcje potwierdzone publicznie
- wspólny kalendarz,
- umawianie jazdy sobie lub innemu pracownikowi,
- podgląd aktywności instruktorów,
- ewidencja czasu pracy,
- przypomnienia o terminach pracowniczych/badaniach.

### Funkcje projektowe do weryfikacji
- tworzenie kont pracowników,
- granularne role i uprawnienia,
- dostępność pracownika,
- przypisanie instruktora do kursantów,
- ograniczenia finansowe/administracyjne.

### Akcje
`create_staff`, `edit_staff`, `deactivate_staff`, `assign_role`, `set_availability`, `view_staff_calendar`, `record_work_time`, `set_employee_reminder` — dokładne formularze/uprawnienia są `TO_VERIFY_AUTH`.

---

## 6. Pojazdy

**Status:** `CURRENT_CONFIRMED / TO_VERIFY_AUTH`

### Funkcje potwierdzone
- centralny podgląd pojazdów,
- przypomnienia m.in. o ubezpieczeniu,
- użycie pojazdu jako zasobu organizacyjnego OSK.

### Funkcje projektowe wymagające potwierdzenia panelowego
- formularz pojazdu,
- kategorie/uprawnienia,
- statusy aktywny/serwis/wycofany,
- badania techniczne,
- blokowanie rezerwacji konfliktowych,
- dokumenty pojazdu.

### Akcje projektowe
`create_vehicle`, `edit_vehicle`, `archive_vehicle`, `assign_vehicle_to_drive`, `set_vehicle_reminder`, `mark_vehicle_unavailable` — `TO_VERIFY_AUTH` dla dokładnego zachowania panelu.

---

## 7. Kalendarz i organizacja jazd

**Status:** `CURRENT_CONFIRMED`

### Funkcje
- kalendarz dostępny w zależności od uprawnień dla pracownika, biura obsługi, kadrowej/HR i właściciela,
- umawianie jazd kursanta dla siebie lub innego pracownika,
- udostępnienie kursantowi możliwości samodzielnego zapisu,
- szybki podgląd aktywności instruktorów,
- ewidencja czasu pracy,
- współdzielenie kalendarza z instruktorami i kursantami.

### Wymagana logika własnego rozwiązania
- konflikty instruktora, kursanta i pojazdu,
- strefa czasowa,
- historia zmian terminu,
- statusy operacyjne (`draft`, `reserved`, `confirmed`, `completed`, `cancelled`, `no_show`) są `INFERRED` — nie traktować ich jako skopiowanych statusów 360 bez panelowej weryfikacji.

### Akcje potwierdzone na poziomie biznesowym
`schedule_drive_for_self`, `schedule_drive_for_employee`, `publish_student_self_booking`, `view_instructor_activity`, `record_work_time`.

### Akcje implementacyjne własnego systemu
`create_calendar_event`, `move_event`, `cancel_event`, `confirm_event`, `complete_event`, `publish_slot`, `book_slot`, `assign_instructor`, `assign_vehicle`.

---

## 8. Kursy, wykłady i szkolenie z instruktorem

**Status:** `CURRENT_CONFIRMED`

### Wykłady / kurs
- wszystkie obsługiwane kategorie prawa jazdy,
- zasoby slajdowe/animacje/filmy,
- szkolenie online z instruktorem i ratownikami,
- monitorowanie postępu,
- zawartość zależna od kategorii.

### Osobny moduł „Szkolenie z instruktorem” — potwierdzony aktualnością 15.01.2026
- lista lekcji w działach,
- materiał wideo,
- pasek postępu lekcji / całego szkolenia,
- pytania kontrolne na końcu działu,
- wielokrotne rozwiązywanie pytań kontrolnych,
- możliwość pominięcia pytań kontrolnych i przejścia dalej,
- treść oraz liczba części zależne od kategorii,
- możliwość zmiany kategorii konta.

### Reguła kategorii
Wybrana kategoria może być kategorią domyślną konta dla testu, kursu i statystyk; pakiet może obejmować wszystkie kategorie, a użytkownik może przełączać kategorię. Projektować jako preferencję użytkownika, nie niezmienną cechę konta.

### Akcje
`create_course` (`TO_VERIFY_AUTH`), `edit_course` (`TO_VERIFY_AUTH`), `assign_students`, `open_lecture`, `present_lecture`, `track_lecture_progress`, `open_instructor_training`, `play_training_lesson`, `record_training_progress`, `start_control_questions`, `retry_control_questions`, `skip_control_questions`, `change_default_driving_category`.

### Uwaga o liczbach
Publiczne źródła podają różne liczby działów/slajdów w różnych datach. Nie hardkodować liczby działów, godzin, slajdów ani materiałów — powinny być konfiguracją/CMS.

---

## 9. Licencje dla kursantów

**Status:** `CURRENT_CONFIRMED / RULES_CONFIRMED`

### Funkcje
- zakup licencji,
- pakiety czasowe — publiczny cennik pokazuje 1, 3 i 6 miesięcy,
- licznik dostępnych licencji,
- sztuki w puli OSK nie tracą ważności przed wykorzystaniem według aktualnej strony,
- przydzielenie licencji kursantowi,
- utworzenie dostępu e-mail albo login/hasło,
- jawny wybór języka przy generowaniu dostępu (potwierdzenie historyczną aktualizacją produktu; aktualna lista języków per moduł może się zmieniać),
- aktywacja przez kursanta,
- usunięcie nieaktywowanej licencji i **automatyczny zwrot sztuki do puli**,
- po aktywacji licencja jest wykorzystana,
- monitoring postępu,
- historia płatności była widoczna w starszym indeksie panelu (`HISTORICAL_INDEX`).

### Stany domenowe
Dla inventory OSK:
`inventory -> assigned -> activated/consumed -> expired`

Odwracalna gałąź:
`assigned + not_activated -> deleted/revoked -> inventory`

Nie utożsamiać okresu pakietu użytkownika z ważnością niewykorzystanej sztuki inventory.

### Akcje
`purchase_licenses`, `assign_license`, `create_student_access_by_email`, `create_student_access_by_credentials`, `select_license_language`, `delete_unactivated_assignment_and_restore_inventory`, `activate_license`, `view_license_inventory`, `view_license_history` (`HISTORICAL_INDEX` dla dokładnego widoku), `view_learning_progress`.

---

## 10. Postępy w nauce i statystyki

**Status:** `CURRENT_CONFIRMED / HISTORICAL_INDEX`

### Funkcje
- postęp w wykładach,
- postęp w podręczniku,
- liczba rozwiązanych pytań,
- statystyki kursanta,
- podgląd przez OSK/instruktora,
- zindeksowany historycznie osobny ekran „Postępy w nauce”.

### Akcje
`view_progress_dashboard`, `view_student_progress`, `filter_progress` (`TO_VERIFY_AUTH` dla dokładnych filtrów), `export_progress` (`INFERRED` — brak publicznego potwierdzenia eksportu).

---

## 11. Egzaminy wewnętrzne

**Status:** `CURRENT_CONFIRMED / RULES_CONFIRMED / HISTORICAL_INDEX`

### Funkcje
- zakup puli egzaminów,
- zarządzanie dostępną pulą,
- wygenerowanie egzaminu dla kursanta,
- wygenerowanie linku do egzaminu,
- osobny stacjonarny start przez `Rozpocznij egzamin wewnętrzny`,
- zużycie egzaminu po przeprowadzeniu,
- zapis przeprowadzonego egzaminu,
- cyfrowa karta przebiegu,
- możliwość wydruku karty przebiegu,
- historycznie zindeksowane: `Wykup`, `Zarządzaj`, `Przeprowadzone`, `Historia płatności`,
- oferta publiczna wskazuje pulę darmowych egzaminów w ramach modelu handlowego.

### Języki
Aktualność z 2024 r. potwierdzała egzaminy PL/EN/DE/UA/RU i raport po polsku. Bieżący zakres języków egzaminu jest `TO_VERIFY_AUTH`, bo aktualne publiczne materiały nie są całkowicie spójne.

### Stany implementacyjne
`available -> generated/assigned -> started -> finished -> consumed`.

### Akcje
`purchase_exams`, `generate_exam`, `generate_exam_link`, `start_local_exam`, `start_exam`, `submit_exam`, `finish_exam`, `view_exam_result`, `download_exam_card`, `print_exam_card`, `view_exam_history`.

---

## 12. Widok kursanta / impersonacja

**Status:** `HISTORICAL_INDEX / TO_VERIFY_AUTH`

### Potwierdzenie
W starszym indeksie panelu występowała pozycja `Przeglądaj jako kursant`. Bieżący zakres tego trybu wymaga zalogowania.

### Własne wymagania bezpieczeństwa
- wyraźny banner impersonacji,
- osobne uprawnienie,
- start/stop w audycie,
- ograniczenie działań nieodwracalnych.

### Akcje
`start_student_view`, `stop_student_view`.

---

## 13. Profil OSK, opinie i ranking szkół

**Status:** `CURRENT_CONFIRMED / RULES_CONFIRMED / HISTORICAL_INDEX`

### Funkcje potwierdzone
- publiczna wizytówka OSK,
- opinie użytkowników w skali 1–5,
- liczba i aktualność opinii wpływają na wynik,
- uśrednianie bayesowskie,
- okresowe przeliczenie rankingu,
- zamknięte OSK bez pozycji,
- moderacja opinii,
- możliwość zgłoszenia opinii przez OSK do ponownej analizy,
- brak możliwości zakupu wyższej **organicznej** pozycji rankingu.

### Historycznie zindeksowane
- `Edytuj profil`,
- `Zobacz profil`,
- `Ranking szkół`.

### Akcje
`edit_school_profile`, `preview_school_profile`, `view_reviews`, `report_review`, `view_ranking`.

---

## 14. Reklamy i promocja OSK

**Status:** `CURRENT_CONFIRMED / RULES_CONFIRMED`

### Aktualnie eksponowane placementy
- pozycja 0,
- boczna górna,
- boczna dolna,
- reklama w teście i kursie,
- reklama pełnoekranowa,
- wybór miejscowości/rejonizacji,
- `Wizytówka premium` — **COMING_SOON**, nie traktować jako działającej funkcji.

### Mechanizm aukcyjny
- licytować może zarejestrowane OSK,
- aukcja ma start, koniec, stawkę początkową i minimalne przebicie,
- kliknięcie `Licytuj` składa wiążącą ofertę,
- wygrywa najwyższa oferta co najmniej równa minimum,
- przy remisie decyduje wcześniejsza oferta,
- wygrana potwierdzana e-mailem,
- płatność w terminie określonym regulaminem (publiczny regulamin wskazuje 3 dni robocze),
- kreacja również przekazywana w terminie (3 dni robocze),
- brak kreacji może uruchomić wariant tekstowy,
- możliwe odpłatne przygotowanie/modyfikowanie kreacji,
- operator moderuje/akceptuje kreację,
- emisja dopiero po płatności,
- historia licytacji pokazuje nazwę OSK, z możliwością wniosku o jej ukrycie,
- oferent może poprosić operatora o odrzucenie złożonej oferty.

### Akcje klienta
`select_ad_city`, `select_placement`, `view_auction_terms`, `place_binding_bid`, `request_bid_rejection`, `view_bid_history`, `request_bidder_name_hiding`, `pay_ad_order`, `upload_desktop_creative`, `upload_mobile_creative`, `request_creative_service`, `activate_text_fallback`, `view_campaign_history` (`HISTORICAL_INDEX` dla dokładnego panelu).

### Akcje operatora
`approve_creative`, `reject_creative`, `remove_bid_on_request`, `activate_paid_campaign`, `schedule_campaign`, `end_campaign`.

`pause_campaign` nie jest publicznie potwierdzone jako akcja klienta.

### Konflikt źródeł
Czas reklamy pełnoekranowej jest opisany niespójnie (10 s vs 30 s w różnych publicznych materiałach). Powinien być wartością konfigurowalną, nie hardkodowaną.

---

## 15. Artykuł sponsorowany

**Status:** `RULES_CONFIRMED`

To osobny produkt promocyjny, nie zwykły placement banerowy.

### Funkcje
- klient może przekazać własny materiał do redakcji,
- operator może przygotować treść,
- moderacja/redakcja materiału,
- obsługa zdjęć,
- czasowa ekspozycja sponsorowana,
- późniejsza obecność w archiwum aktualności.

### Akcje
`order_sponsored_article`, `submit_article_content`, `submit_article_assets`, `request_copywriting`, `moderate_article`, `approve_article`, `publish_article`, `archive_article`.

---

## 16. Baner partnerski „na twoją stronę”

**Status:** `RULES_CONFIRMED / HISTORICAL_INDEX`

### Funkcje
- pobranie gotowego materiału,
- użycie bez modyfikowania grafiki,
- link DoFollow do wskazanego serwisu,
- opcjonalna pomoc operatora przy wdrożeniu HTML/grafiki.

### Akcje
`download_partner_banner`, `request_banner_implementation_help`.

---

## 17. Płatności, zamówienia i aktywacja dostępu

**Status:** `CURRENT_CONFIRMED / RULES_CONFIRMED`

### Funkcje
- płatności jednorazowe, nie subskrypcja,
- zakup licencji, egzaminów i reklam,
- szybki przelew internetowy,
- karta,
- przelew bankowy,
- regulamin opisuje również przekaz/przelew pocztowy,
- możliwość przesłania potwierdzenia przelewu,
- ceny brutto/VAT,
- po zaksięgowaniu płatności może pojawić się osobna akcja **`Aktywuj dostęp`**.

### Ważny lifecycle usługi cyfrowej
`ordered -> paid -> activation_available -> activated -> expired`

Nie wolno traktować `paid` jako automatycznie równoważnego `activated`, jeśli dany produkt korzysta z jawnej aktywacji.

### Faktury
Pozycja `Faktury` występowała w starszym indeksie panelu, ale stara publiczna trasa `/faktury` obecnie zwraca 404. Status: `HISTORICAL_INDEX / TO_VERIFY_AUTH`, a nie bieżąco potwierdzona funkcja.

### Akcje
`create_order`, `add_order_item`, `calculate_total`, `start_payment`, `confirm_payment`, `upload_transfer_confirmation`, `make_access_activation_available`, `activate_access`, `view_payment_history` (`HISTORICAL_INDEX` dla dokładnych paneli), `download_invoice` (`HISTORICAL_INDEX / TO_VERIFY_AUTH`).

`refund` nie jest potwierdzoną akcją panelu klienta; reklamacje/odstąpienie istnieją jako proces regulaminowy.

---

## 18. Powiadomienia i przypomnienia

**Status:** `CURRENT_CONFIRMED / INFERRED`

### Potwierdzone konteksty
- ubezpieczenie pojazdu,
- terminy/badania pracowników,
- e-mail po wygranej licytacji.

### Własny system powinien dodatkowo obsługiwać
- termin jazdy,
- zmianę terminu,
- przydzielenie egzaminu,
- aktywację licencji,
- płatność,
- błąd integracji PKK.

Kanały dodatkowe poza potwierdzonymi publicznie wymagają projektowej decyzji; SMS nie jest traktowany jako pewnik badanego panelu.

---

## 19. Obsługa, reklamacje i kontakt

**Status:** `CURRENT_CONFIRMED / RULES_CONFIRMED`

### Funkcje
- formularz kontaktowy,
- załącznik,
- captcha/kod weryfikacyjny,
- zgoda informacyjna RODO,
- kontakt telefoniczny/e-mail,
- reklamacja,
- wniosek o zamknięcie konta,
- kontakt w sprawach aukcji (np. odrzucenie oferty / ukrycie nazwy oferenta).

### Akcje
`submit_contact_request`, `submit_complaint`, `request_account_closure`, `request_bid_rejection`, `request_bidder_name_hiding`.

---

## 20. Usługi komercyjne: pozycjonowanie / strona WWW

**Status:** `CURRENT_CONFIRMED`

Publiczna oferta zawiera usługę wykraczającą poza rdzeń panelu OSK:
- audyt strony,
- optymalizacja,
- SEO,
- Google Ads,
- stworzenie strony WWW i panelu treści,
- bonusy handlowe (np. licencje/egzaminy/artykuły sponsorowane zależnie od oferty).

W naszym produkcie traktować jako osobny moduł `commercial_services / lead_generation`, nie jako warunek MVP panelu operacyjnego.

### Akcje
`view_commercial_offer`, `request_seo_contact`, `request_website_contact`.

---

## 21. Analityka, audyt i bezpieczeństwo

**Status:** `RULES_CONFIRMED / INFERRED`

Serwis opisuje utrzymywanie sesji, cookies/analitykę oraz analizę aktywności. Dla naszego systemu wymagamy:
- audit log krytycznych operacji,
- logowanie operacji PKK,
- logowanie przydziału/cofnięcia licencji,
- logowanie egzaminów,
- logowanie zmian w kalendarzu,
- logowanie zmian ról,
- rejestr zgód,
- monitoring błędów integracji,
- wykrywanie nienaturalnego współdzielenia kont,
- jawne rozróżnienie blokady konta, zakończenia sesji i zamknięcia konta.

---

## 22. Konflikty źródeł — obowiązkowo konfigurowalne

| Obszar | Niespójność | Zasada implementacyjna |
|---|---|---|
| języki | marketing mówi „5”, bieżący cennik licencji pokazuje 4, aktualność egzaminów z 2024 r. podaje 5 | macierz języków per produkt/moduł |
| szkolenie | różne publiczne materiały podają różną liczbę działów | CMS/config |
| slajdy | różne liczby w zależności od daty publikacji | CMS/config |
| pełnoekranowa reklama | 10 s vs 30 s | parametr kampanii/placementu |
| faktury | stary indeks menu vs obecne 404 starej trasy | `HISTORICAL_INDEX / TO_VERIFY_AUTH` |
