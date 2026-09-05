# 54. Licencje — rozwinięcie „Przypisane licencje”

Data weryfikacji: 2026-09-05

**Kontekst:** `/licencje/panel` -> wiersz dostępu kursanta -> `Rozwiń`  
**Źródło:** bieżący zalogowany ekran + screenshot przekazany podczas audytu  
**Status:** `USER_CONFIRMED_AUTH_SCREEN`

---

## 1. Znaczenie

Akcja `Rozwiń` przy rekordzie dostępu kursanta pokazuje listę **konkretnych licencji przypisanych do tego samego learning access**.

W obserwowanym przypadku dostęp Jana Nowaka miał licznik `5`, a po rozwinięciu pojawiło się dokładnie 5 osobnych pozycji.

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
- akcja `Usuń`.

### Dane logowania
W każdej pozycji powtórzony jest:
- kod/flaga języka,
- login dostępu.

### Data dodania
Każda licencja ma własny timestamp przypisania/dodania.

Zaobserwowane przykłady:
- 23-01-2026 10:04,
- 23-01-2026 08:41,
- 22-01-2026 13:22,
- 22-01-2026 13:04,
- 21-01-2026 14:02.

---

## 3. Nieaktywowane licencje

Wszystkie 5 obserwowanych pozycji miało status:
- `Nie aktywowano`.

Dla tych pozycji:
- `Data do` była pusta,
- `Pozostało` było puste,
- akcja `Usuń` była dostępna.

### Kluczowy wniosek
To jest silny dowód UI, że data końca/pozostały czas **nie są ustalane w chwili samego przypisania licencji**.

W połączeniu z wcześniej zaobserwowaną osobną aktywacją kursanta wspiera model:

`assigned_not_activated -> activated -> expires_at established -> expired`

Nie zapisujemy jako faktu konkurenta dokładnej implementacji timestampu, ale dla naszego produktu przyjmujemy jawnie:

`expires_at = activated_at + product_duration`

z zasadami kalendarzowymi zdefiniowanymi per produkt.

---

## 4. Usuwanie

Każda nieaktywna pozycja ma własną akcję `Usuń`.

Osobny confirmation modal usuwania licencji został wcześniej zmapowany w:
- `docs/39-student-license-delete-confirmation.md`,
- `specs/screens/student-license-delete.yml`.

Własny produkt:
- nie wykonuje hard-delete historii,
- cofnięcie nieaktywnej licencji zmienia stan assignmentu na revoked/cancelled,
- dokładnie jedna jednostka wraca do inventory,
- operacja jest transakcyjna i audytowana.

---

## 5. Powiązanie z wierszem nadrzędnym

Wiersz nadrzędny pokazuje `Najnowszą licencję`.

W obserwowanym przypadku:
- timestamp w wierszu nadrzędnym: `23-01-2026 10:04`,
- ten sam timestamp ma pierwszy/najnowszy rekord w rozwinięciu.

To silnie wspiera interpretację, że kolumny `Najnowsza licencja` w głównej tabeli są projekcją najnowszego `license_assignment` dla learning access.

---

## 6. Model dla naszego produktu

`license_assignments` powinno zawierać co najmniej:
- `id`,
- `organization_id`,
- `learning_access_id`,
- `inventory_entry_id`,
- `license_product_id`,
- `assigned_at`,
- `activated_at nullable`,
- `expires_at nullable`,
- `state`,
- `revoked_at nullable`,
- `revoked_by nullable`,
- audit metadata.

### Reguły
- `assigned_at` istnieje od momentu przydzielenia,
- dla `assigned_not_activated`: `activated_at = null`, `expires_at = null`,
- po aktywacji ustawiamy `activated_at` i `expires_at`,
- `remaining_days` jest projekcją z `expires_at`, nie kolumną ręcznie aktualizowaną,
- cofnięcie jest dozwolone tylko w stanach określonych polityką produktu.

---

## 7. Potwierdzone akcje

- `expand_learning_access_license_assignments`
- `collapse_learning_access_license_assignments`
- `view_assignment_created_at`
- `view_assignment_expiry_date_column`
- `view_assignment_remaining_time_column`
- `view_assignment_status`
- `delete_unactivated_license_assignment`

---

## 8. Pozostałe niewiadome

- jak wygląda rozwinięcie dla aktywnej licencji,
- dokładny format `Data do` po aktywacji,
- dokładny format `Pozostało` po aktywacji,
- jak pokazywane są zakończone licencje,
- czy wariant 31/90/180 dni jest widoczny w rozwinięciu w innych stanach,
- kolejność rekordów przy mixed active/expired/unactivated history.
