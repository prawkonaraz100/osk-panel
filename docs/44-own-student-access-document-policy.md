# 44. Własny dokument dostępu kursanta — polityka produktu

Data decyzji: 2026-09-05

**Status:** `OWN_PRODUCT_DECISION`

## Cel

Na podstawie obserwowanego PDF konkurenta przyjmujemy własny, bezpieczny dokument przekazywany kursantowi przez OSK.

## Zasady

1. Dokument jest generowany jako PDF i opcjonalnie przygotowany do druku A4.
2. Dokument jest generowany w języku dostępu kursanta.
3. Dokument zawiera:
   - logo/nazwę naszej platformy,
   - opcjonalnie dane OSK,
   - imię i nazwisko kursanta,
   - login lub e-mail dostępu,
   - adres logowania,
   - QR prowadzący do bezpiecznego adresu logowania/aktywacji,
   - krótką instrukcję aktywacji.
4. QR nigdy nie zawiera jawnego hasła.
5. Jeżeli użytkownik ma już własne hasło, PDF nie ujawnia i nie odtwarza tego hasła.
6. Dla nowego dostępu preferujemy jednorazowy token/link aktywacyjny albo jednorazowe hasło wymagające zmiany przy pierwszym logowaniu.
7. Po wykorzystaniu tokenu nie można go ponownie użyć.
8. Pobranie dokumentu jest audytowane.
9. Dokument nie powinien zawierać zbędnych danych wrażliwych takich jak PESEL lub pełne PKK, jeśli nie są potrzebne do logowania.
10. Przydzielenie licencji i aktywacja pozostają osobnymi zdarzeniami.

## Rekomendowany lifecycle

`learning_access_created -> license_assigned_not_activated -> activation_token_issued -> learner_activates -> active -> expired`

Jeżeli kursant ma już istniejące konto:

`existing_learning_access -> license_assigned_not_activated -> learner_activates -> active -> expired`

## Regeneracja dokumentu

Po ustawieniu własnego hasła ponowne pobranie PDF:
- pokazuje login,
- pokazuje URL/QR,
- informuje, że hasło jest już ustawione,
- może oferować instrukcję resetu hasła,
- nie pokazuje starego hasła ani nowego hasła w plaintext.

## Bezpieczeństwo

- hasła przechowywane wyłącznie jako bezpieczne hashe,
- tokeny aktywacyjne krótkotrwałe, losowe i jednorazowe,
- token nie powinien ujawniać identyfikatora kursanta w sposób przewidywalny,
- pobranie dokumentu wymaga uprawnienia do danych kursanta w danym OSK,
- wszystkie operacje tenant-scoped.
