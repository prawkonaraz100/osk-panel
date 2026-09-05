# 53. Licencje — Panel „Generowanie dostępu”

Data weryfikacji: 2026-09-05

**Route:** `/licencje/panel`  
**Źródło:** bieżące zalogowane ekrany + screenshoty przekazane podczas audytu  
**Status:** `USER_CONFIRMED_AUTH_SCREEN`

Pełne rozwinięcie pojedynczego dostępu opisano w `docs/54-license-expanded-assignments.md`.

---

## 1. Znaczenie modułu

`Panel - Generuj licencje` jest centralnym ekranem operacyjnym OSK do zarządzania:
- pulą zakupionych licencji,
- dostępami kursantów do platformy,
- licencjami przypisanymi do poszczególnych dostępów,
- statusem aktywacji/licencji,
- pobieraniem danych dostępowych,
- przejściem do profilu kursanta.

Ekran rozdziela wyraźnie:
1. **inventory OSK** — ile licencji danego wariantu jest dostępnych/aktywnych,
2. **learning access / kursant** — konto/login,
3. **license assignments** — kolekcja konkretnych licencji przypisanych do danego dostępu.

---

## 2. Sekcja „Dostępne licencje”

Potwierdzone trzy warianty:
- `Licencje na 31 dni`,
- `Licencje na 90 dni`,
- `Licencje na 180 dni`.

Każda karta pokazuje osobno:
- `Dostępne`,
- `Aktywne`,
- akcję `Dokup licencje` prowadzącą do `/licencje/wykup`.

Zaobserwowany snapshot demo:
- 31 dni: dostępne 1, aktywne 9,
- 90 dni: dostępne 36, aktywne 4,
- 180 dni: dostępne 56, aktywne 4.

Te liczby są danymi konta/demo, nie wartościami stałymi produktu.

Nie utożsamiamy automatycznie `active_count` z `assigned_count`; dokładna agregacja konkurenta nadal nie jest potwierdzona.

---

## 3. Główna akcja

Potwierdzona akcja:
- `Generuj dostęp dla kursanta do prawo-jazdy-360.pl`.

Dokładny formularz po kliknięciu pozostaje do osobnego capture.

---

## 4. Sekcja „Przydzielone licencje”

Wiersz główny reprezentuje **learning access**, a nie pojedynczą licencję.

Potwierdzone kontrolki:
- `Dodaj nowego kursanta`,
- `Zaznacz widoczne`,
- batch `Pobierz dostępy`,
- batch `Przydziel licencje`,
- `Ukryj licencje zakończone`,
- wyszukiwarka,
- `Sortuj`.

Po zaznaczeniu rekordów panel pokazuje licznik zaznaczenia i aktywuje akcje batch.

---

## 5. Kolumny głównej tabeli

Potwierdzone:
- `Dane logowania prawo-jazdy-360.pl`,
- `Imię i nazwisko`,
- grupa `Najnowsza licencja`:
  - `Data wygenerowania`,
  - `Język`,
  - `Status`,
- `Przypisane licencje`,
- akcje.

Statusy zaobserwowane:
- `Nie aktywowano`,
- `Aktywna, pozostało 139 dni`.

Jeden dostęp może mieć wiele licencji — zaobserwowano liczniki `5`, `2`, `2`.

---

## 6. Rozwinięcie `Rozwiń`

To zachowanie jest już potwierdzone.

Dla dostępu Jana Nowaka licznik `5` rozwinął dokładnie 5 rekordów licencji.

Potwierdzone kolumny rozwinięcia:
- `Dane logowania prawo-jazdy-360.pl`,
- `Data dodania`,
- `Data do`,
- `Pozostało`,
- `Status`,
- akcja `Usuń`.

Każda licencja ma własny `Data dodania`.

Zaobserwowane timestampy:
- 23-01-2026 10:04,
- 23-01-2026 08:41,
- 22-01-2026 13:22,
- 22-01-2026 13:04,
- 21-01-2026 14:02.

Wszystkie obserwowane rekordy miały status `Nie aktywowano`.

Dla nich:
- `Data do` była pusta,
- `Pozostało` było puste,
- `Usuń` było dostępne.

Timestamp najnowszego rekordu (`23-01-2026 10:04`) odpowiada timestampowi `Najnowszej licencji` w wierszu nadrzędnym. To silnie wspiera model, że główny wiersz pokazuje projekcję najnowszego assignmentu.

---

## 7. Wniosek o aktywacji i ważności

Połączenie kilku obserwacji daje spójny model:
- licencję można przypisać bez aktywacji,
- nieaktywowana licencja nie ma widocznej daty końca ani pozostałego czasu,
- aktywna licencja pokazuje liczbę pozostałych dni,
- wcześniej pobrany dokument dostępu potwierdził osobny krok aktywacji.

Dla naszego produktu przyjmujemy więc jawnie:

`assigned_not_activated -> active -> expired`

oraz:
- `assigned_at` ustawiane przy przydzieleniu,
- `activated_at = null` przed aktywacją,
- `expires_at = null` przed aktywacją,
- przy aktywacji `expires_at = activated_at + duration produktu`,
- `remaining_days` wyliczane z `expires_at`.

---

## 8. Usuwanie nieaktywowanej licencji

Każda nieaktywna pozycja ma osobne `Usuń`.

Dla naszego produktu:
- nie robimy hard-delete historii,
- assignment przechodzi do `revoked`,
- dokładnie jedna jednostka wraca do inventory,
- operacja jest transakcyjna i audytowana.

---

## 9. Akcje wiersza

Potwierdzone:
- `Dostęp` -> `/licencje/pobierz?guid=<student_id>`,
- `Profil` -> `/kursanci/<student_id>`,
- `Rozwiń` / `Zwiń`.

---

## 10. Model naszego produktu

Rekomendowane encje:
- `license_products`,
- `license_inventory_entries`,
- `learning_accesses`,
- `license_assignments`,
- `license_activations` / event aktywacji.

Jeden `learning_access` ma wiele `license_assignments`.

Każdy assignment ma własne:
- `assigned_at`,
- `activated_at nullable`,
- `expires_at nullable`,
- `state`,
- informacje o provenance z inventory/order.

---

## 11. Pozostałe niewiadome

Do dalszego capture:
- rozwinięcie dostępu mającego aktywną licencję,
- rozwinięcie zakończonej licencji,
- formularz `Generuj dostęp dla kursanta`,
- dokładne opcje `Sortuj`,
- exact search fields,
- exact semantics `Aktywne` w kartach inventory,
- batch `Przydziel licencje`,
- batch `Pobierz dostępy`,
- zachowanie `Ukryj licencje zakończone` dla mixed-history account,
- pagination,
- empty states,
- success/error messages.
