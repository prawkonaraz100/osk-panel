# 54. Licencje — rozwinięcie „Przypisane licencje”

Data weryfikacji: 2026-09-05

**Kontekst:** `/licencje/panel` -> wiersz dostępu kursanta -> `Rozwiń`  
**Źródło:** bieżące zalogowane ekrany Jana Nowaka i Ali Nowak + screenshoty przekazane podczas audytu  
**Status:** `USER_CONFIRMED_AUTH_SCREEN`

---

## 1. Znaczenie

Akcja `Rozwiń` przy rekordzie dostępu kursanta pokazuje listę **konkretnych licencji przypisanych do tego samego learning access**.

Zaobserwowano:
- Jan Nowak: licznik `5` -> dokładnie 5 osobnych pozycji,
- Ala Nowak: licznik `2` -> dokładnie 2 osobne pozycje.

To potwierdza relację:

`learning_access 1 -> N license_assignments`

Nie jest to pojedyncza licencja nadpisywana kolejną.

---

## 2. Kolumny rozwiniętej tabeli

Potwierdzone kolumny:
- `Dane logowania prawo-jazdy-360.pl`,
- `Data dodania`,
- `Data do`,
- `Pozostało`,
- `Status`,
- akcja `Usuń` dla pozycji, dla których jest dostępna.

### Dane logowania
W każdej pozycji powtórzony jest:
- kod/flaga języka,
- login dostępu.

### Data dodania
Każda licencja ma własny timestamp przypisania/dodania.

---

## 3. Nieaktywowane licencje

Dla Jana Nowaka wszystkie 5 obserwowanych pozycji miało status:
- `Nie aktywowano`.

Dla tych pozycji:
- `Data do` była pusta,
- `Pozostało` było puste,
- akcja `Usuń` była dostępna.

### Wniosek
To silnie potwierdza, że samo przypisanie nie uruchamia okresu ważności.

Stan UI:

`assigned_not_activated`

ma:
- `assigned_at != null`,
- brak widocznej daty końca,
- brak pozostałego czasu.

---

## 4. Aktywne licencje

Dla Ali Nowak zaobserwowano dwie aktywne pozycje:

### Nowsza pozycja
- `Data dodania`: `23-01-2026 08:41`,
- `Data do`: `22-01-2027 23:37`,
- `Pozostało`: `139 dni`,
- `Status`: `Aktywna`.

### Starsza pozycja
- `Data dodania`: `21-01-2026 12:36`,
- `Data do`: `22-12-2026 23:37`,
- `Pozostało`: `108 dni`,
- `Status`: `Aktywna`.

W wierszu nadrzędnym najnowsza licencja pokazuje:
- `Aktywna, pozostało 139 dni`.

To jest zgodne z najnowszym rekordem z rozwinięcia.

---

## 5. Bardzo ważny wniosek — kumulowanie/przedłużanie czasu

Różnica między dwoma aktywnymi rekordami Ali wynosi dokładnie:
- `22-12-2026 23:37` -> `22-01-2027 23:37` = **31 dni**,
- `108 dni` -> `139 dni` = **31 dni**.

To bardzo mocno wspiera interpretację, że kolejna licencja może **przedłużać istniejący aktywny dostęp**, zamiast uruchamiać drugi niezależny równoległy okres.

Najbardziej spójna interpretacja domenowa:

`current_access_expires_at + next_license_duration -> new_access_expires_at`

jeżeli dostęp jest już aktywny i jego aktualny koniec jest w przyszłości.

### Granica dowodowa
Nie widzimy na ekranie pola `Rodzaj licencji` przy każdym assignmentcie, więc nie zapisujemy jako twardego faktu, że nowszy rekord Ali był właśnie produktem 31-dniowym.

Jednak dokładna różnica 31 dni pomiędzy obiema datami końca i `Pozostało` jest bardzo silnym dowodem na mechanizm kumulowania/przedłużania.

---

## 6. Korekta wcześniejszego uproszczenia

Wcześniejsze założenie:

`expires_at = activated_at + product_duration`

jest niewystarczające dla przypadku kolejnej licencji do już aktywnego dostępu.

Dla naszego produktu przyjmujemy regułę bazową:

### Pierwsza aktywacja
Jeżeli dostęp nie ma aktywnego czasu:

`base = activated_at`

`new_expires_at = base + product_duration`

### Przedłużenie aktywnego dostępu
Jeżeli obecny dostęp kończy się w przyszłości:

`base = current_access_expires_at`

`new_expires_at = base + product_duration`

Czyli ogólnie:

`base = max(activation_effective_at, current_access_expires_at)`

`new_expires_at = base + product_duration`

Dokładne reguły kalendarzowe per produkt muszą być centralnie zdefiniowane.

---

## 7. Znaczenie `Data do` na rekordzie assignmentu

Zaobserwowane dane sugerują, że `Data do` na historycznym rekordzie może reprezentować **stan końca dostępu po zastosowaniu danego assignmentu**, a nie wyłącznie niezależny koniec życia tej jednej sztuki.

Dlatego w naszym modelu warto rozdzielić:
- `license_assignment` — fakt przydzielenia jednostki,
- `access_entitlement_period` / `access_expiry_effect` — efekt tej licencji na czas dostępu.

Minimalnie assignment powinien przechowywać:
- `assigned_at`,
- `activated_at/effective_at`,
- `duration_days`,
- `expiry_before`,
- `expiry_after`,
- `state`.

Dzięki temu można audytować, o ile konkretna licencja przedłużyła dostęp.

---

## 8. Usuwanie

Każda obserwowana nieaktywna pozycja Jana ma własną akcję `Usuń`.

Osobny confirmation modal został wcześniej zmapowany w:
- `docs/39-student-license-delete-confirmation.md`,
- `specs/screens/student-license-delete.yml`.

Własny produkt:
- nie wykonuje hard-delete historii,
- cofnięcie nieaktywnej licencji zmienia stan assignmentu na revoked/cancelled,
- dokładnie jedna jednostka wraca do inventory,
- operacja jest transakcyjna i audytowana.

Dla aktywnych pozycji Ali akcja `Usuń` nie była widoczna w przekazanym ekranie.

Nie zapisujemy jednak bez dodatkowego testu absolutnej reguły konkurenta `active cannot be deleted`; dla naszego produktu taka blokada jest rekomendowana.

---

## 9. Powiązanie z wierszem nadrzędnym

Wiersz nadrzędny pokazuje `Najnowszą licencję`.

Potwierdzono dla obu przypadków, że projekcja odpowiada najnowszej pozycji z rozwinięcia:
- Jan: timestamp `23-01-2026 10:04`, status `Nie aktywowano`,
- Ala: timestamp `23-01-2026 08:41`, status `Aktywna, pozostało 139 dni`.

Główna tabela jest więc agregatem/projekcją najnowszego assignmentu dla learning access.

---

## 10. Model dla naszego produktu

### `license_assignments`
Co najmniej:
- `id`,
- `organization_id`,
- `learning_access_id`,
- `inventory_entry_id`,
- `license_product_id`,
- `assigned_at`,
- `activated_at nullable`,
- `effective_at nullable`,
- `duration_days`,
- `expiry_before nullable`,
- `expiry_after nullable`,
- `state`,
- `revoked_at nullable`,
- `revoked_by nullable`,
- audit metadata.

### `learning_accesses`
Powinno mieć bieżącą projekcję:
- `current_expires_at nullable`,
- `status`,
- `language`,
- identifier.

`current_expires_at` może być materializowaną projekcją wyliczaną z ledgeru assignmentów.

---

## 11. Potwierdzone akcje

- `expand_learning_access_license_assignments`
- `collapse_learning_access_license_assignments`
- `view_assignment_created_at`
- `view_assignment_expiry_date_column`
- `view_assignment_remaining_time_column`
- `view_assignment_status`
- `delete_unactivated_license_assignment`
- `view_active_assignment_expiry`
- `view_active_assignment_remaining_days`

---

## 12. Pozostałe niewiadome

- dokładna data/czas pierwszej aktywacji Ali,
- dokładny produkt/duration przypisany do każdego z dwóch aktywnych rekordów,
- jak wygląda rekord `Zakończona/Wygasła`,
- czy każda kolejna licencja zawsze stackuje czas czy istnieją wyjątki,
- zachowanie przy przypisaniu kolejnej licencji do już wygasłego dostępu,
- dokładna kolejność i moment efektywności kilku nieaktywowanych licencji,
- mixed active/expired/unactivated history.
