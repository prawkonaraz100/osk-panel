# 13. Kryteria akceptacji kluczowych funkcji

## Licencje

### AC-LIC-01 — przydzielenie
**Given** OSK ma co najmniej 1 licencję w puli  
**When** uprawniony pracownik przypisze ją kursantowi  
**Then** pula zmniejsza się o 1, powstaje assignment i wpis audytowy.

### AC-LIC-02 — cofnięcie przed aktywacją
**Given** licencja jest przypisana i nieaktywna  
**When** uprawniony pracownik ją cofnie  
**Then** assignment jest anulowany, a sztuka wraca do puli.

### AC-LIC-03 — brak cofnięcia po aktywacji
**Given** licencja została aktywowana  
**When** pracownik próbuje ją cofnąć  
**Then** API odrzuca operację kodem domenowym `LICENSE_ALREADY_ACTIVATED`.

## Egzamin wewnętrzny

### AC-EX-01 — link
**Given** dostępny egzamin w puli  
**When** pracownik generuje egzamin dla kursanta  
**Then** system tworzy unikalny link/token z czasem ważności.

### AC-EX-02 — konsumpcja
**Given** egzamin rozpoczęty  
**When** zostanie zakończony  
**Then** egzamin jest zużyty, wynik zapisany, a karta przebiegu możliwa do wygenerowania.

### AC-EX-03 — stacjonarnie
**Given** kursant jest wybrany w panelu  
**When** pracownik wybiera `Rozpocznij stacjonarnie`  
**Then** system tworzy sesję bez konieczności wysyłania linku kursantowi.

## Kalendarz

### AC-CAL-01 — konflikt instruktora
Nie można zapisać dwóch nachodzących jazd tego samego instruktora.

### AC-CAL-02 — konflikt pojazdu
Nie można zapisać dwóch nachodzących jazd tego samego pojazdu.

### AC-CAL-03 — konflikt kursanta
Nie można zapisać dwóch nachodzących aktywności kursanta.

## PKK

### AC-PKK-01 — audyt
Każda próba operacji PKK zapisuje rozpoczęcie i wynik niezależnie od sukcesu zewnętrznego API.

### AC-PKK-02 — idempotencja
Ponowienie requestu z tym samym idempotency key nie może wykonać drugiej nieodwracalnej operacji.

## Multi-tenancy

### AC-TENANT-01
Użytkownik OSK A nie może pobrać ani zmodyfikować zasobu OSK B przez podmianę ID w URL/API.

## Impersonacja

### AC-IMP-01
Podczas widoku kursanta panel pokazuje stale widoczny banner „Tryb podglądu kursanta” i przycisk zakończenia.

### AC-IMP-02
Start i koniec impersonacji są zapisane w audycie.
