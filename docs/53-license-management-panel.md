# 53. Licencje — Panel „Generowanie dostępu”

Data weryfikacji: 2026-09-05

**Route:** `/licencje/panel`  
**Źródło:** bieżące zalogowane ekrany + screenshoty przekazane podczas audytu  
**Status:** `USER_CONFIRMED_AUTH_SCREEN`

Powiązane szczegóły:
- rozwinięcie pojedynczego dostępu: `docs/54-license-expanded-assignments.md`,
- formularz `Generuj dostęp dla kursanta`: `docs/55-license-generate-access-form.md`.

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

## 3. Główna akcja — `Generuj dostęp dla kursanta`

Formularz po kliknięciu został już potwierdzony.

Drawer nosi tytuł:
- `Przydzielanie licencji`.

Potwierdzone pola/elementy:
- wybór wariantu `1 miesiąc / 3 miesiące / 6 miesięcy`,
- licznik `Dostępne: N`,
- wybór języka,
- ścieżka `Dodaj nowego kursanta` -> `Email lub login`,
- separator `lub`,
- ścieżka `Wyszukaj kursanta`,
- przycisk `Przydziel licencje`.

Wyszukiwanie istniejącego kursanta obsługuje:
- imię,
- nazwisko,
- email,
- login,
- PESEL.

Wyniki pokazują:
- imię i nazwisko,
- login/dostęp.

### Języki — SOURCE_CONFLICT

Bieżący zalogowany formularz przydzielania licencji pokazuje:
- Polski,
- Angielski,
- Niemiecki,
- Rosyjski,
- Ukraiński.

Publiczny/zakupowy opis licencji mówi natomiast o 4 językach bez rosyjskiego.

Nie hardkodujemy globalnej listy. Własny produkt używa capability-driven language catalog.

Pełna specyfikacja: `specs/screens/license-generate-access.yml`.

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

To zachowanie jest potwierdzone dla dwóch stanów: nieaktywowanego i aktywnego.

### Jan Nowak — 5 nieaktywowanych licencji
Potwierdzone kolumny rozwinięcia:
- `Dane logowania prawo-jazdy-360.pl`,
- `Data dodania`,
- `Data do`,
- `Pozostało`,
- `Status`,
- akcja `Usuń`.

Dla 5 nieaktywowanych pozycji:
- `Data do` pusta,
- `Pozostało` puste,
- `Status = Nie aktywowano`,
- `Usuń` dostępne.

### Ala Nowak — 2 aktywne licencje
Zaobserwowane rekordy:

1. nowszy:
   - `Data dodania`: `23-01-2026 08:41`,
   - `Data do`: `22-01-2027 23:37`,
   - `Pozostało`: `139 dni`,
   - `Status`: `Aktywna`;

2. starszy:
   - `Data dodania`: `21-01-2026 12:36`,
   - `Data do`: `22-12-2026 23:37`,
   - `Pozostało`: `108 dni`,
   - `Status`: `Aktywna`.

Dla aktywnych pozycji w przekazanym widoku nie było widocznej akcji `Usuń`.

Wiersz nadrzędny Ali pokazuje najnowszy stan: `Aktywna, pozostało 139 dni`, co odpowiada najnowszemu rekordowi z rozwinięcia.

---

## 7. Aktywacja i kumulowanie czasu

Potwierdzone obserwacje:
- nieaktywna licencja nie ma `Data do` ani `Pozostało`,
- aktywna licencja ma konkretną datę końca i pozostały czas,
- przydzielenie i aktywacja są oddzielne,
- jeden dostęp może mieć wiele assignmentów.

Dwie aktywne licencje Ali mają daty końca różniące się dokładnie o **31 dni**:

`22-12-2026 23:37 -> 22-01-2027 23:37`

oraz pozostały czas:

`108 dni -> 139 dni`.

To bardzo mocno wspiera model, w którym kolejna licencja przedłuża istniejący aktywny dostęp.

Dla naszego produktu przyjmujemy entitlement ledger:

### pierwsza aktywacja
`new_expiry = activation_time + product_duration`

### kolejna licencja przy aktywnym dostępie
`new_expiry = current_expiry + product_duration`

Uogólnienie:

`base = max(effective_activation_time, current_expiry)`

`new_expiry = base + product_duration`

Każda zastosowana licencja przechowuje audytowo:
- `expiry_before`,
- `expiry_after`,
- `duration_days`.

Dokładny produkt przypisany do każdego z aktywnych rekordów Ali nie jest widoczny na ekranie, więc nie zapisujemy jako faktu, że nowszy rekord był produktem 31-dniowym.

---

## 8. Usuwanie nieaktywowanej licencji

Każda nieaktywna pozycja ma osobne `Usuń`.

Dla naszego produktu:
- nie robimy hard-delete historii,
- assignment przechodzi do `revoked`,
- dokładnie jedna jednostka wraca do inventory,
- operacja jest transakcyjna i audytowana.

Aktywnej licencji nie cofamy zwykłą akcją administracyjną.

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
- `license_activation_events` / entitlement ledger.

Jeden `learning_access` ma wiele `license_assignments`.

Każdy assignment powinien mieć co najmniej:
- `assigned_at`,
- `activated_at/effective_at nullable`,
- `duration_days`,
- `expiry_before nullable`,
- `expiry_after nullable`,
- `state`,
- provenance z inventory/order.

`learning_access` przechowuje lub materializuje aktualną projekcję `current_expires_at`.

Przydzielenie licencji z różnych entry pointów (`profil kursanta`, `panel licencji`, batch) powinno trafiać do jednego wspólnego backendowego command/service.

---

## 11. Pozostałe niewiadome

Do dalszego capture:
- rozwinięcie zakończonej licencji,
- dokładne opcje `Sortuj`,
- exact search fields głównej tabeli,
- exact semantics `Aktywne` w kartach inventory,
- batch `Przydziel licencje`,
- batch `Pobierz dostępy`,
- zachowanie `Ukryj licencje zakończone` dla mixed-history account,
- kolejność aktywowania kilku oczekujących licencji,
- zachowanie przy przedłużaniu już wygasłego dostępu,
- czy `Dodaj nowego kursanta` w drawerze tworzy pełny minimalny profil czy wyłącznie learning access,
- walidacja duplikatów email/login,
- pagination,
- empty states,
- success/error messages.
