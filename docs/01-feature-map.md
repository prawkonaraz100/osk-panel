# 01. Mapa funkcjonalności

## 1. Uwierzytelnianie i konto OSK

**Status:** `CONFIRMED`

### Funkcje
- logowanie e-mail + hasło,
- „nie wylogowuj mnie”,
- reset hasła,
- logowanie społecznościowe: Google, Apple, Facebook,
- rejestracja właściciela/OSK,
- dane osoby: imię, nazwisko, e-mail, hasło,
- dane firmy: nazwa, NIP, adres, telefon,
- akceptacja regulaminu,
- opcjonalna zgoda marketingowa,
- powiadomienia e-mail/telefoniczne związane z kontem.

### Akcje
`register`, `login`, `social_login`, `logout`, `request_password_reset`, `reset_password`, `update_account`, `update_company`, `withdraw_marketing_consent`.

---

## 2. Organizacja OSK i profil firmy

**Status:** `CONFIRMED / INDEXED`

### Funkcje
- dane identyfikacyjne OSK,
- profil publiczny/wizytówka,
- edycja profilu w rankingu,
- podgląd publicznego profilu,
- powiązanie profilu z opiniami,
- dane kontaktowe i lokalizacja,
- konfiguracja widoczności.

### Akcje
`edit_school_profile`, `preview_school_profile`, `publish_school_profile`, `view_reviews`, `report_review`.

---

## 3. Integracja PKK

**Status:** `CONFIRMED`

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
- operacja powinna posiadać status (`requested`, `processing`, `success`, `failed`),
- zapisywać aktora, timestamp, payload wejściowy i bezpieczną odpowiedź,
- operacje zewnętrzne muszą być idempotentne.

### Akcje
`fetch_pkk`, `view_pkk`, `update_pkk_training`, `return_pkk_to_school`, `return_pkk_to_authority`, `return_expired_pkk`, `view_pkk_history`, `retry_failed_pkk_operation`.

---

## 4. Kursanci

**Status:** `CONFIRMED / INFERRED`

### Funkcje
- tworzenie kursanta,
- przypisywanie kursanta do kursu,
- tworzenie dostępu przez e-mail lub login/hasło,
- przypisywanie licencji,
- przypisywanie jazd,
- przypisywanie wykładów,
- przypisywanie egzaminu wewnętrznego,
- monitoring postępów,
- archiwizacja/zakończenie relacji.

### Akcje
`create_student`, `edit_student`, `archive_student`, `enroll_student`, `assign_license`, `assign_lecture`, `schedule_drive`, `assign_internal_exam`, `view_student_progress`, `impersonate_student_view`.

---

## 5. Pracownicy / instruktorzy / administracja

**Status:** `CONFIRMED / INFERRED`

Marketing serwisu wskazuje pracowników, instruktorów, wykładowców, administrację oraz współdzielenie kalendarza.

### Funkcje
- tworzenie kont pracowników,
- role i uprawnienia,
- przypisanie instruktora do jazd i kursantów,
- dostęp do kalendarza,
- ewidencja czasu pracy,
- przypomnienia o badaniach/terminach pracowniczych,
- ograniczenie dostępu do danych finansowych i administracyjnych.

### Akcje
`create_staff`, `edit_staff`, `deactivate_staff`, `assign_role`, `set_availability`, `view_staff_calendar`, `record_work_time`, `set_employee_reminder`.

---

## 6. Pojazdy

**Status:** `CONFIRMED / INFERRED`

Publiczna oferta wymienia centralny podgląd pojazdów i przypomnienia o ubezpieczeniach.

### Funkcje
- ewidencja pojazdów,
- kategoria/uprawnienia pojazdu,
- status aktywny/serwis/wycofany,
- terminy ubezpieczenia,
- terminy badania technicznego,
- przypisanie do jazd,
- blokowanie rezerwacji pojazdu w konflikcie.

### Akcje
`create_vehicle`, `edit_vehicle`, `archive_vehicle`, `assign_vehicle_to_drive`, `set_vehicle_reminder`, `mark_vehicle_unavailable`.

---

## 7. Kalendarz i organizacja jazd

**Status:** `CONFIRMED`

### Funkcje
- kalendarz widoczny dla właściciela, pracownika/biura i innych uprawnionych osób,
- umawianie jazd kursanta dla siebie lub pracownika,
- udostępnienie kursantowi możliwości zapisu,
- szybki podgląd aktywności instruktorów,
- ewidencja czasu pracy,
- współdzielenie kalendarza z instruktorami i kursantami.

### Wymagana logika
- wykrywanie konfliktu instruktora,
- wykrywanie konfliktu kursanta,
- wykrywanie konfliktu pojazdu,
- strefa czasowa,
- statusy: `draft`, `reserved`, `confirmed`, `completed`, `cancelled`, `no_show`,
- historia zmian terminu.

### Akcje
`create_calendar_event`, `move_event`, `cancel_event`, `confirm_event`, `complete_event`, `publish_slot`, `book_slot`, `assign_instructor`, `assign_vehicle`.

---

## 8. Kursy i wykłady

**Status:** `CONFIRMED`

### Funkcje
- tworzenie kursów,
- wszystkie kategorie prawa jazdy,
- wykłady online,
- zasoby slajdowe/animacje/filmy,
- szkolenie online z instruktorem i ratownikami,
- przypisywanie kursantów do wykładów,
- statystyki postępu.

### Akcje
`create_course`, `edit_course`, `assign_students`, `open_lecture`, `present_lecture`, `track_lecture_progress`, `complete_lecture_module`.

---

## 9. Licencje dla kursantów

**Status:** `CONFIRMED / INDEXED`

### Funkcje
- zakup licencji,
- pakiety czasowe,
- licznik dostępnych licencji,
- przydzielenie licencji kursantowi,
- aktywacja przez kursanta,
- usunięcie nieaktywowanej licencji i zwrot do puli,
- historia płatności,
- zarządzanie licencjami,
- postępy w nauce.

### Stany licencji
`inventory -> assigned -> activated -> expired`

Dodatkowo:
- `assigned` ale nieaktywowaną można cofnąć do `inventory`,
- po aktywacji licencja jest traktowana jako wykorzystana.

### Akcje
`purchase_licenses`, `assign_license`, `revoke_unactivated_license`, `activate_license`, `view_license_inventory`, `view_license_history`, `view_learning_progress`.

---

## 10. Postępy w nauce i statystyki

**Status:** `CONFIRMED / INDEXED`

### Funkcje
- postęp w wykładach,
- postęp w podręczniku,
- liczba rozwiązanych pytań,
- statystyki kursanta,
- filtrowanie po kursancie/kursie/kategorii,
- podgląd przez instruktora/OSK.

### Akcje
`view_progress_dashboard`, `view_student_progress`, `filter_progress`, `export_progress`.

---

## 11. Egzaminy wewnętrzne

**Status:** `CONFIRMED / INDEXED`

### Funkcje
- zakup puli egzaminów,
- zarządzanie dostępną pulą,
- wygenerowanie egzaminu dla kursanta,
- wygenerowanie linku do egzaminu,
- rozpoczęcie egzaminu stacjonarnie,
- zapis przeprowadzonego egzaminu,
- historia przeprowadzonych egzaminów,
- cyfrowa karta przebiegu,
- wydruk karty przebiegu,
- historia płatności,
- miesięczna pula darmowych egzaminów wg oferty handlowej.

### Stany
`available -> assigned/generated -> started -> finished -> consumed`

### Akcje
`purchase_exams`, `generate_exam`, `generate_exam_link`, `start_local_exam`, `start_exam`, `submit_exam`, `finish_exam`, `view_exam_result`, `download_exam_card`, `print_exam_card`, `view_exam_history`.

---

## 12. Widok kursanta / impersonacja

**Status:** `INDEXED / CONFIRMED`

### Funkcje
- „Przeglądaj jako kursant”,
- dostęp kursanta do platformy WWW,
- aplikacje mobilne,
- testy,
- kurs,
- podręcznik,
- wykłady,
- statystyki,
- wiele języków.

### Wymagania bezpieczeństwa
- impersonacja musi być wyraźnie oznaczona,
- brak możliwości wykonywania nieodwracalnych działań jako kursant bez dodatkowej autoryzacji,
- rozpoczęcie/koniec impersonacji w logu audytowym.

---

## 13. Profil OSK i ranking szkół

**Status:** `CONFIRMED / INDEXED`

### Funkcje
- edycja wizytówki,
- publiczny podgląd,
- opinie użytkowników,
- moderacja opinii po stronie operatora rankingu,
- mechanizmy antyspamowe,
- ranking zależny od ocen, liczby opinii, aktualności i aktywności.

W naszym produkcie ranking powinien być osobnym modułem od zarządzania formalnym OSK.

---

## 14. Reklamy i promocja OSK

**Status:** `CONFIRMED / INDEXED`

### Typy reklam widoczne w źródłach
- pozycja 0,
- boczna górna,
- boczna dolna,
- reklama w teście i kursie,
- pełnoekranowa,
- rejonizacja lokalna,
- historia płatności,
- licytacja wybranych emisji,
- artykuł sponsorowany.

### Akcje
`create_ad_order`, `select_placement`, `select_region`, `upload_creative`, `bid_for_slot`, `pay_order`, `activate_campaign`, `pause_campaign`, `view_campaign_history`.

---

## 15. Płatności, zamówienia i faktury

**Status:** `CONFIRMED / INDEXED`

### Funkcje
- koszyk/wybór liczby licencji,
- zakup egzaminów,
- zakup reklam,
- szybkie płatności,
- karta,
- przelew bankowy,
- historia płatności,
- faktury,
- jednorazowe płatności,
- VAT,
- aktywacja zakupionego pakietu po płatności.

### Akcje
`create_order`, `add_order_item`, `calculate_total`, `start_payment`, `confirm_payment`, `upload_transfer_confirmation`, `activate_package`, `view_payment_history`, `download_invoice`.

---

## 16. Powiadomienia i przypomnienia

**Status:** `CONFIRMED / INFERRED`

### Przykłady
- koniec ubezpieczenia pojazdu,
- badanie techniczne,
- badanie pracownika,
- termin jazdy,
- zmiana terminu,
- nowy przydzielony egzamin,
- aktywacja licencji,
- płatność,
- błąd integracji PKK.

Kanały: in-app + e-mail; SMS jako opcjonalna integracja.

---

## 17. Obsługa i kontakt

**Status:** `CONFIRMED`

### Funkcje
- formularz kontaktowy,
- załącznik,
- captcha/kod weryfikacyjny,
- zgoda informacyjna RODO,
- zgłoszenie problemu,
- kontakt telefoniczny/e-mail.

---

## 18. Analityka, audyt i bezpieczeństwo

**Status:** `CONFIRMED / INFERRED`

Serwis deklaruje utrzymywanie sesji, analitykę zachowania oraz analizę aktywności. Dla naszego systemu wymagamy:

- audit log krytycznych operacji,
- logowanie operacji PKK,
- logowanie przydziału licencji,
- logowanie egzaminów,
- logowanie zmian w kalendarzu,
- logowanie zmian ról,
- rejestr zgód,
- monitoring błędów integracji,
- limit aktywnych sesji w obszarach ryzyka,
- mechanizm wykrywania nienaturalnego współdzielenia kont.
