# 53. Licencje — Panel „Generowanie dostępu”

Data weryfikacji: 2026-09-05

**Route:** `/licencje/panel`  
**Źródło:** bieżący zalogowany ekran + screenshot przekazany podczas audytu  
**Status:** `USER_CONFIRMED_AUTH_SCREEN`

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
3. **license assignments** — kolekcja licencji przypisanych do danego dostępu.

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

Te liczby są danymi konta/demo i nie są wartościami stałymi produktu.

### Wniosek domenowy
Inventory musi być agregowane per wariant licencji, co najmniej:
- available_count,
- active_count.

Nie utożsamiamy `active_count` z `assigned_count`; exact aggregation semantics dla konkurenta nie są w 100% widoczne z jednego ekranu.

---

## 3. Główna akcja generowania dostępu

Potwierdzona akcja:
- `Generuj dostęp dla kursanta do prawo-jazdy-360.pl`.

To jest wejście do flow tworzenia/przypisywania dostępu kursantowi z poziomu panelu licencji.

Dokładny formularz po kliknięciu należy zweryfikować osobno; podobne zachowanie znamy już z formularzy przydzielania licencji na karcie kursanta.

---

## 4. Sekcja „Przydzielone licencje”

Sekcja przedstawia wiersze per **dostęp kursanta / learning access**, nie per pojedynczą licencję.

Potwierdzone kontrolki nagłówka:
- `Dodaj nowego kursanta`,
- `Zaznacz widoczne`,
- batch action `Pobierz dostępy`,
- batch action `Przydziel licencje`,
- switch `Ukryj licencje zakończone`,
- wyszukiwarka,
- `Sortuj`.

W obserwowanym stanie batch actions były nieaktywne bez zaznaczonych rekordów.

### Wniosek
Panel obsługuje bulk workflow dla zaznaczonych widocznych dostępów.

---

## 5. Kolumny tabeli

Potwierdzone kolumny / grupy danych:
- `Dane logowania prawo-jazdy-360.pl`,
- `Imię i nazwisko`,
- grupa `Najnowsza licencja`, w której widoczne są:
  - `Data wygenerowania`,
  - `Język`,
  - `Status`,
- `Przypisane licencje`,
- akcje wiersza.

### Dane logowania
Zaobserwowano:
- flagę/kod języka,
- login.

### Przypisane licencje
Zaobserwowano wartości licznikowe większe niż 1:
- 5,
- 2,
- 2.

Przy liczniku znajduje się akcja `Rozwiń`.

**Kluczowy wniosek:** jeden `learning_access` może mieć kolekcję wielu `license_assignments`.

Nie modelujemy pojedynczego pola `license_id` bezpośrednio na kursancie/dostępie.

---

## 6. Status najnowszej licencji

Potwierdzone prezentacje:
- `Nie aktywowano`,
- `Aktywna, pozostało 139 dni`.

### Wniosek
Panel potwierdza co najmniej dwa stany/projekcje:
- assigned/not activated,
- active with remaining days.

To wspiera wcześniej ustalony lifecycle:

`assigned_not_activated -> activated -> expired`

Liczba pozostałych dni jest projekcją runtime i nie powinna być przechowywana jako ręcznie aktualizowane pole.

---

## 7. Akcje wiersza

Potwierdzone:
- `Dostęp` -> pobranie dokumentu dostępowego `/licencje/pobierz?guid=<student_id>`,
- `Profil` -> przejście do `/kursanci/<student_id>`,
- `Rozwiń` -> rozwinięcie kolekcji przypisanych licencji (zawartość po rozwinięciu nadal do weryfikacji).

---

## 8. Wyszukiwanie i sortowanie

Potwierdzona jest obecność:
- wyszukiwarki,
- drawer/akcji `Sortuj`.

Dokładne pola sortowania pozostają do osobnego capture.

---

## 9. Ukrywanie licencji zakończonych

Potwierdzony switch:
- `Ukryj licencje zakończone`.

Najsilniejsza interpretacja:
- filtruje zakończone/wygasłe pozycje licencyjne lub dostępy, aby uprościć widok bieżący.

Dokładne zachowanie wiersza mającego jednocześnie aktywne i zakończone licencje należy traktować jako `TO_VERIFY`.

---

## 10. Model danych dla naszego produktu

Rekomendowane encje:

### `license_products`
- duration_days,
- status,
- pricing/catalog metadata.

### `license_inventory_entries`
- organization_id,
- license_product_id,
- state,
- purchased_order_id,
- assignment_id nullable.

### `learning_accesses`
- student_id,
- login/email identifier,
- language,
- account status.

### `license_assignments`
- learning_access_id,
- inventory_entry_id,
- assigned_at,
- activated_at nullable,
- expires_at nullable,
- state.

Jeden `learning_access` ma wiele `license_assignments`.

---

## 11. Potwierdzone akcje

- `open_license_management_panel`
- `view_license_inventory_by_duration`
- `open_license_purchase_from_inventory_card`
- `generate_student_learning_access`
- `add_new_student_from_license_panel`
- `select_visible_learning_accesses`
- `bulk_download_access_credentials`
- `bulk_assign_license`
- `toggle_hide_finished_licenses`
- `search_license_accesses`
- `open_license_sorting`
- `expand_assigned_licenses`
- `download_student_access_credentials`
- `open_student_profile_from_license_panel`

---

## 12. Wymagania dla naszego produktu

- inventory liczone transakcyjnie i per wariant,
- brak możliwości zejścia inventory poniżej zera,
- każda przydzielona sztuka ma identyfikowalny provenance z zakupu,
- jeden dostęp może mieć wiele kolejnych licencji,
- aktywacja i przydzielenie pozostają oddzielne,
- `remaining_days` wyliczane z `expires_at`,
- bulk actions tenant-scoped i permission-scoped,
- pobieranie danych dostępowych audytowane,
- search/sort server-side dla dużych OSK,
- filtrowanie zakończonych licencji nie może usuwać danych historycznych.

---

## 13. Pozostałe niewiadome

Do dalszego capture:
- zawartość po `Rozwiń`,
- formularz `Generuj dostęp dla kursanta`,
- dokładne opcje `Sortuj`,
- exact search fields,
- exact semantics `Aktywne` w kartach inventory,
- batch `Przydziel licencje` flow,
- batch `Pobierz dostępy` output,
- zachowanie `Ukryj licencje zakończone` dla mixed-history account,
- pagination,
- empty states,
- success/error messages.
