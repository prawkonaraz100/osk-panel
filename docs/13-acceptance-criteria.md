# 13. Kryteria akceptacji — core OSK v1

Data konsolidacji: 2026-09-05

> Kryteria opisują nasz własny produkt. Nowsze `specs/legal`, `specs/design` i `specs/screens` mają pierwszeństwo. Każde AC powinno być możliwe do pokrycia testem integracyjnym lub E2E.

---

# Identity / tenant

## AC-TENANT-01 — izolacja odczytu
**Given** użytkownik należy do OSK A  
**When** próbuje odczytać zasób OSK B przez podmianę ID/UUID  
**Then** backend odrzuca dostęp niezależnie od UI.

## AC-TENANT-02 — izolacja zapisu
**Given** request zawiera relacyjne ID pracownika, pojazdu, lokalizacji, kursanta lub kursu  
**When** którykolwiek zasób należy do innej organizacji  
**Then** mutacja jest odrzucona przed zapisem.

## AC-AUTH-01 — reset przez e-mail lub login
**Given** użytkownik otwiera reset hasła  
**When** poda e-mail albo login  
**Then** system przyjmuje identyfikator i rozpoczyna bezpieczny proces bez ujawniania, czy konto istnieje.

## AC-AUTH-02 — bezpieczny ReturnUrl
**Given** użytkownik trafia na chronioną lokalną trasę  
**When** poprawnie się zaloguje  
**Then** wraca na dozwoloną lokalną trasę  
**And** zewnętrzny/złośliwy ReturnUrl jest odrzucony.

---

# Kursant / learning access

## AC-STU-01 — student i learning account są oddzielne
**Given** istnieje `Student`  
**When** nie ma konta edukacyjnego  
**Then** nadal może istnieć formalna kartoteka/kurs  
**And** system nie wymaga automatycznie loginu edukacyjnego do każdej operacji formalnej.

## AC-STU-02 — hasło nie jest odzyskiwalne
**Given** OSK ustawiło hasło kursantowi  
**When** hasło zostało zapisane  
**Then** baza przechowuje wyłącznie bezpieczny hash  
**And** system nie ma funkcji odczytania starego hasła.

## AC-STU-03 — ponowny wydruk z hasłem wymaga resetu
**Given** sekretariat chce ponownie wygenerować kartkę z jawnym hasłem  
**When** stare hasło nie jest dostępne  
**Then** ustawia/generuje nowe hasło  
**And** dopiero nowe hasło może zostać jednorazowo pokazane i wydrukowane.

---

# CourseEnrollment / formalny kurs

## AC-COURSE-01 — formalny kurs wymaga trwałego kursanta
**Given** użytkownik chce utworzyć formalne szkolenie  
**When** nie istnieje `Student` w tej organizacji  
**Then** najpierw musi zostać utworzony trwały rekord kursanta.

## AC-COURSE-02 — wymagania wylicza rule engine
**Given** kursant, kategoria, rodzaj szkolenia i ewentualna podstawa zwolnienia  
**When** powstaje lub zmienia się `CourseEnrollment`  
**Then** system wylicza wymagania szkolenia przez wersjonowany rule engine  
**And** zapisuje wersję/profil reguły.

## AC-COURSE-03 — manual override nie obniża prawa dowolnie
**Given** użytkownik posiada permission korekty  
**When** koryguje podstawę zwolnienia lub fakty wejściowe  
**Then** system ponownie wylicza wymagania  
**And** nie pozwala arbitralnie ustawić ustawowego minimum poniżej reguły bez legalnie dopuszczonej podstawy.

## AC-TIME-01 — bieżące godziny OSK wynikają z ledgeru
**Given** kurs posiada sesje szkoleniowe  
**When** UI pokazuje liczbę wykonanych godzin w bieżącym OSK  
**Then** wartość jest projekcją z `TrainingHourLedgerEntry`  
**And** nie pochodzi z niezależnego ręcznie edytowalnego agregatu.

## AC-TIME-02 — zewnętrzne godziny są osobnym uznaniem
**Given** część szkolenia odbyła się w innym OSK  
**When** uprawniony pracownik uznaje ten czas  
**Then** powstaje `RecognizedExternalTraining` z actor/time/reason/evidence  
**And** wpis nie nadpisuje historii sesji bieżącego OSK.

## AC-TIME-03 — jednostki czasu
Teoria jest liczona po 45 minut na godzinę szkoleniową, a praktyka po 60 minut. Canonical storage czasu używa minut.

---

# PKK

## AC-PKK-01 — PKK należy do kursu
**Given** jeden kursant ma więcej niż jeden `CourseEnrollment`  
**When** wykonywana jest operacja PKK  
**Then** request wskazuje konkretny `course_enrollment_id`  
**And** historia PKK nie miesza operacji pomiędzy kursami.

## AC-PKK-02 — audyt każdej próby
**Given** wykonywana jest operacja PKK  
**When** zewnętrzny provider zwróci sukces, błąd lub timeout  
**Then** system zapisuje operację i techniczną próbę wraz z rezultatem/korelacją.

## AC-PKK-03 — idempotencja mutacji
**Given** ten sam nieodwracalny command PKK zostanie wysłany ponownie z tym samym idempotency key  
**When** poprzednia próba już zakończyła się biznesowym sukcesem  
**Then** system nie wykonuje drugiej nieodwracalnej operacji.

## AC-PKK-04 — retry tylko dla bezpiecznych klas błędów
Błąd walidacji/biznesowy nie jest automatycznie ponawiany jak timeout/bezpieczny błąd transportowy.

---

# Kalendarz

## AC-CAL-01 — konflikt instruktora
Nie można zapisać dwóch nakładających się aktywnych jazd tego samego instruktora, chyba że typ wydarzenia jawnie dopuszcza overlap.

## AC-CAL-02 — konflikt pojazdu
Nie można zapisać dwóch nakładających się jazd wymagających tego samego pojazdu.

## AC-CAL-03 — konflikt kursanta
Nie można zapisać dwóch nakładających się aktywności kursanta, jeśli konfiguracja nie dopuszcza overlapu.

## AC-CAL-04 — resource tenant validation
Każdy student, pracownik, pojazd i lokalizacja przypisane do wydarzenia muszą należeć do tej samej organizacji.

## AC-CAL-05 — slot self-booking jest atomowy
**Given** jeden slot jest dostępny dla kursantów  
**When** dwóch kursantów próbuje go zarezerwować jednocześnie  
**Then** maksymalnie jedna rezerwacja kończy się sukcesem.

---

# Lokalizacje / pojazdy / pracownicy

## AC-LOC-01 — archiwizacja zachowuje historię
**Given** lokalizacja jest użyta w historycznym kursie/wydarzeniu  
**When** zostaje zarchiwizowana  
**Then** historyczne referencje pozostają  
**And** lokalizacja nie jest dostępna dla nowych przypisań.

## AC-VEH-01 — niezależne ważności dokumentów
Przegląd, OC i AC są przechowywane jako niezależne wartości/rekordy i mogą generować osobne alerty.

## AC-STAFF-01 — pracownik może istnieć bez loginu
`StaffProfile` może istnieć bez `User/Membership`.

## AC-STAFF-02 — staff type nie nadaje automatycznie permissions
Zmiana typu pracownika nie może niejawnie ominąć backendowej macierzy permissions.

---

# Student finance

## AC-FIN-01 — należność i wpłata są oddzielne
**Given** kurs kosztuje 4000 zł  
**When** kursant wpłaci 1000 zł  
**Then** `StudentCharge` pozostaje 4000 zł  
**And** istnieje `StudentPayment` 1000 zł  
**And** saldo wynosi 3000 zł.

## AC-FIN-02 — brak hard-delete historii finansowej
Po wystąpieniu wpłaty korekta odbywa się przez reversal/correction, a nie fizyczne usunięcie historii.

## AC-FIN-03 — money nie jest float
Kwoty są przechowywane jako minor units albo typ decimal o ustalonej precyzji.

---

# Licencje

## AC-LIC-01 — przydzielenie
**Given** OSK ma co najmniej jedną sztukę inventory  
**When** uprawniony pracownik przydzieli licencję  
**Then** konkretna sztuka zostaje alokowana do assignmentu  
**And** operacja jest audytowana.

## AC-LIC-02 — dwa kanały dostępu
Learning access może korzystać z e-maila albo loginu/generowanych credentials zgodnie z polityką produktu.

## AC-LIC-03 — język jako capability
Język jest walidowany względem capability produktu, nie globalnej zahardkodowanej listy.

## AC-LIC-04 — cofnięcie przed aktywacją
**Given** assignment nie jest aktywowany  
**When** uprawniony pracownik go cofa  
**Then** dokładnie jedna sztuka inventory wraca do puli w tej samej transakcji.

## AC-LIC-05 — brak cofnięcia po aktywacji
Po aktywacji ścieżka `revoke_unactivated` jest odrzucona kodem domenowym.

## AC-LIC-06 — race activation vs revoke
Równoczesna aktywacja i cofnięcie tego samego assignmentu nie mogą oba zakończyć się sukcesem; inventory nie może zostać podwójnie przywrócone ani zużyte.

---

# Egzamin wewnętrzny

## AC-EX-01 — formalna próba wymaga kursanta i kursu
**Given** generowany jest formalny egzamin OSK  
**Then** `student_id` i `course_enrollment_id` są wymagane  
**And** rule engine potwierdza, że dana część egzaminu jest wymagana.

## AC-EX-02 — rezerwacja przy utworzeniu dostępu
**Given** OSK ma wolną sztukę egzaminu  
**When** tworzy remote/local access do próby  
**Then** jedna sztuka zostaje zarezerwowana  
**And** nie jest jeszcze skonsumowana.

## AC-EX-03 — konsumowanie przy starcie
**Given** istnieje ważna rezerwacja i access  
**When** kursant faktycznie rozpoczyna egzamin  
**Then** inventory jest konsumowane atomowo **w momencie startu**  
**And** attempt przechodzi do `in_progress`.

## AC-EX-04 — finish nie konsumuje drugi raz
**Given** attempt jest `in_progress` i inventory zostało już skonsumowane  
**When** kursant kończy egzamin  
**Then** zapisujemy odpowiedzi, wynik i dokumenty  
**And** nie wykonujemy drugiej konsumpcji inventory.

## AC-EX-05 — niewykorzystany access może zwolnić rezerwację
**Given** access nie został uruchomiony  
**When** wygasa, jest anulowany lub cofnięty  
**Then** reservation wraca do dostępnej puli zgodnie z polityką.

## AC-EX-06 — technical abort po starcie
**Given** egzamin został rozpoczęty  
**When** wystąpi awaria techniczna  
**Then** attempt może otrzymać `technical_abort`  
**And** inventory pozostaje skonsumowane  
**And** przywrócenie sztuki wymaga osobnej audytowanej korekty.

## AC-EX-07 — jeden access nie startuje równolegle dwa razy
Równoległe starty tego samego accessu są blokowane serwerowo.

## AC-EX-08 — immutable attempt snapshot
Po zakończeniu próby zmiana bieżącej treści pytania, danych kursanta lub reguły nie zmienia historycznego snapshotu próby/PDF.

## AC-EX-09 — bezpieczny remote token
Remote token jest nieprzewidywalny, jednorazowo związany z konkretnym access/attempt i nie zawiera wrażliwych danych jawnie.

---

# Platform orders / payments

## AC-ORDER-01 — snapshot ceny
Zmiana cennika po zakupie nie zmienia ceny/VAT historycznego `OrderItem`.

## AC-PAY-01 — webhook idempotentny
Ten sam event płatniczy przetworzony wiele razy nie może podwójnie utworzyć inventory/entitlementu.

## AC-ACT-01 — paid != activated
Dla `activation_mode=explicit` potwierdzona płatność daje `activation_available`, a nie natychmiast `activated`.

## AC-ACT-02 — aktywacja idempotentna
Wielokrotne kliknięcie aktywacji tworzy maksymalnie jedną `ServiceActivation` i jeden początek okresu usługi.

---

# Audit

## AC-AUD-01 — formalne mutacje są audytowane
Zmiana PKK, kursu, godzin, egzaminu, licencji, płatności, roli/permission, archiwizacja i korekty zapisują actor, organization, action, entity, timestamp i request/correlation id.

## AC-AUD-02 — audit nie zawiera plaintext password
Logowanie ustawienia/resetu hasła nie zapisuje wartości hasła.

---

# Testy obowiązkowe per moduł

Każdy moduł core przed merge musi posiadać:
- policy/authorization tests,
- cross-tenant negative tests,
- happy-path integration test,
- validation/error test,
- audit assertion dla krytycznej mutacji,
- race/idempotency test tam, gdzie istnieje inventory/payment/external command,
- E2E dla krytycznego flow użytkownika.
