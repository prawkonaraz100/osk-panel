# 13. Kryteria akceptacji kluczowych funkcji

> Kryteria opisują nasz bezpieczny odpowiednik funkcjonalny. Jeżeli detal jest projektowy, nie należy przedstawiać go jako potwierdzone zachowanie 360.

## Auth

### AC-AUTH-01 — reset przez e-mail lub login
**Given** użytkownik otwiera reset hasła  
**When** poda e-mail albo login  
**Then** system przyjmuje identyfikator i rozpoczyna bezpieczny proces resetu bez ujawniania, czy konto istnieje.

### AC-AUTH-02 — bezpieczny ReturnUrl
**Given** niezalogowany użytkownik wszedł na chronioną lokalną trasę  
**When** poprawnie się zaloguje  
**Then** wraca do tej trasy.  
**And** zewnętrzny/złośliwy ReturnUrl jest odrzucony.

## Licencje

### AC-LIC-01 — przydzielenie
**Given** OSK ma co najmniej 1 licencję w puli  
**When** uprawniony pracownik przypisze ją kursantowi  
**Then** pula zmniejsza się o 1, powstaje assignment i wpis audytowy.

### AC-LIC-02 — dwa kanały dostępu
**Given** powstaje dostęp kursanta  
**When** OSK wybierze sposób provisioningu  
**Then** system obsługuje `email` albo `generated_credentials`  
**And** nie zapisuje hasła jawnie.

### AC-LIC-03 — jawny język
**Given** produkt ma więcej niż jeden obsługiwany język  
**When** pracownik tworzy dostęp  
**Then** język musi zostać jawnie wybrany albo wynikać z jednoznacznej zapisanej preferencji produktu/użytkownika  
**And** nie zakładamy globalnie domyślnego PL.

### AC-LIC-04 — cofnięcie przed aktywacją
**Given** licencja jest przypisana i nieaktywna  
**When** uprawniony pracownik ją usuwa/cofa  
**Then** assignment zostaje zakończony  
**And** dokładnie jedna sztuka wraca do puli w tej samej transakcji.

### AC-LIC-05 — brak cofnięcia po aktywacji
**Given** licencja została aktywowana  
**When** pracownik próbuje użyć ścieżki cofnięcia nieaktywowanej licencji  
**Then** API odrzuca operację kodem domenowym `LICENSE_ALREADY_ACTIVATED`.

### AC-LIC-06 — race activation vs revoke
**Given** aktywacja i cofnięcie są wykonywane równocześnie  
**When** oba requesty konkurują o ten sam assignment  
**Then** tylko jeden może wygrać  
**And** inventory nigdy nie staje się ujemne ani podwójnie zwiększone.

## Płatność i jawna aktywacja

### AC-ACT-01 — paid != activated
**Given** produkt ma `activation_mode=explicit`  
**When** płatność zostanie potwierdzona  
**Then** entitlement ma stan `activation_available`, a nie `activated`.

### AC-ACT-02 — aktywacja idempotentna
**Given** entitlement jest `activation_available`  
**When** użytkownik kliknie `Aktywuj dostęp` wielokrotnie  
**Then** powstaje tylko jedna aktywacja i jeden początek okresu dostępu.

## Egzamin wewnętrzny

### AC-EX-01 — link
**Given** dostępny egzamin w puli  
**When** pracownik generuje egzamin dla kursanta  
**Then** system tworzy bezpieczny, nieprzewidywalny dostęp/link do konkretnego egzaminu.

> TTL linku jest decyzją bezpieczeństwa naszego produktu; nie jest obecnie publicznie potwierdzonym detalem 360.

### AC-EX-02 — konsumpcja
**Given** egzamin rozpoczęty  
**When** zostanie zakończony  
**Then** egzamin jest zużyty, wynik zapisany, a karta przebiegu możliwa do zachowania/generowania.

### AC-EX-03 — stacjonarnie
**Given** kursant/egzamin jest wybrany w panelu  
**When** pracownik wybiera stacjonarne rozpoczęcie  
**Then** system tworzy lokalną sesję bez konieczności wysłania linku kursantowi.

### AC-EX-04 — karta
**Given** egzamin jest zakończony  
**When** uprawniony pracownik otwiera kartę przebiegu  
**Then** może ją zachować cyfrowo i przygotować do druku.

### AC-EX-05 — język jako capability
Lista języków egzaminu jest pobierana z konfiguracji/capabilities, nie z globalnie zahardkodowanej listy.

## Szkolenie z instruktorem

### AC-TR-01 — postęp
**Given** użytkownik ogląda lekcję  
**When** system zapisuje aktywność  
**Then** aktualizuje postęp lekcji i agregat programu.

### AC-TR-02 — pytania kontrolne wielokrotnie
**Given** dział ma pytania kontrolne  
**When** użytkownik chce podejść ponownie  
**Then** może utworzyć kolejną próbę bez nadpisywania poprzednich prób.

### AC-TR-03 — pominięcie
**Given** użytkownik jest na pytaniach kontrolnych  
**When** wybiera pominięcie  
**Then** może przejść do kolejnej lekcji/działu zgodnie z konfiguracją programu.

### AC-TR-04 — zmiana kategorii
**Given** użytkownik ma dostęp do wielu kategorii  
**When** zmienia kategorię domyślną  
**Then** test, kurs i statystyki używają nowej preferencji  
**And** szkolenie ładuje treść właściwą dla tej kategorii.

## Kalendarz

### AC-CAL-01 — konflikt instruktora
W naszym systemie nie można zapisać dwóch nachodzących aktywnych jazd tego samego instruktora, chyba że jawnie istnieje typ zdarzenia dopuszczający nakładanie.

### AC-CAL-02 — konflikt pojazdu
Nie można zapisać dwóch nachodzących jazd wymagających tego samego pojazdu.

### AC-CAL-03 — konflikt kursanta
Nie można zapisać dwóch nachodzących aktywności kursanta, jeśli typy aktywności nie dopuszczają konfliktu.

### AC-CAL-04 — planowanie innemu pracownikowi
**Given** użytkownik ma permission organizacyjne  
**When** tworzy jazdę dla innego pracownika  
**Then** zdarzenie jest przypisane wskazanemu pracownikowi, a zmiana jest audytowalna.

### AC-CAL-05 — slot dla kursanta
**Given** uprawniony pracownik publikuje slot do samodzielnego zapisu  
**When** kursant poprawnie go rezerwuje  
**Then** slot nie może zostać jednocześnie przydzielony innemu kursantowi.

## PKK

### AC-PKK-01 — audyt
Każda próba operacji PKK zapisuje rozpoczęcie i wynik niezależnie od sukcesu zewnętrznego API.

### AC-PKK-02 — idempotencja
Ponowienie requestu z tym samym idempotency key nie może wykonać drugiej nieodwracalnej operacji.

### AC-PKK-03 — zakres operacji
System przewiduje osobne commandy dla: pobrania, aktualizacji szkolenia, zwrotu do OSK, zwrotu do urzędu i zwrotu profilu przedawnionego.

## Aukcje reklam

### AC-AD-01 — minimalne przebicie
**Given** aukcja jest otwarta  
**When** OSK składa ofertę  
**Then** backend atomowo sprawdza stawkę początkową/minimalne przebicie i zapisuje serwerowy timestamp.

### AC-AD-02 — brak samodzielnego delete bid
**Given** oferta została złożona jako wiążąca  
**When** klient chce ją wycofać  
**Then** tworzy request do operatora  
**And** nie usuwa sam rekordu bid.

### AC-AD-03 — tie-break
**Given** dwie ważne oferty mają tę samą najwyższą kwotę  
**When** aukcja jest rozstrzygana  
**Then** wygrywa oferta z wcześniejszą serwerową kolejnością złożenia.

### AC-AD-04 — emisja po spełnieniu warunków
**Given** OSK wygrało aukcję  
**When** kampania ma ruszyć  
**Then** system wymaga potwierdzonej płatności i zaakceptowanej kreacji albo dozwolonego fallbacku tekstowego.

### AC-AD-05 — warianty kreacji
System pozwala przechować oddzielne wersje wymagane dla placementu, np. desktop/mobile, bez nadpisywania historii moderacji.

### AC-AD-06 — prywatność historii ofert
Wniosek o ukrycie nazwy oferenta wpływa na prezentację historii, ale nie usuwa technicznego/audytowego powiązania oferty z OSK.

## Ranking / opinie

### AC-RANK-01 — zgłoszenie opinii
**Given** OSK widzi opinię dotyczącą swojej szkoły  
**When** zgłasza ją do ponownej analizy  
**Then** powstaje moderacyjny `review_report`, a sama opinia nie jest automatycznie usuwana przez OSK.

## Multi-tenancy

### AC-TENANT-01
Użytkownik OSK A nie może pobrać ani zmodyfikować zasobu OSK B przez podmianę ID w URL/API.

### AC-TENANT-02
Globalny zasób operatora (np. placement/aukcja) może być czytany zgodnie z polityką, ale bid/order/campaign zawsze zachowuje jednoznaczne `organization_id`.

## Impersonacja

### AC-IMP-01
Podczas własnego odpowiednika widoku kursanta panel pokazuje stale widoczny banner trybu podglądu i możliwość zakończenia.

### AC-IMP-02
Start i koniec impersonacji są zapisane w audycie.

### AC-IMP-03
Impersonacja nie pozwala wykonywać działań finansowych, zmiany hasła ani innych operacji nieodwracalnych bez jawnie zaprojektowanego osobnego permission/flow.
