# 42. Kursanci — formularz Edycja kursanta

Data weryfikacji: 2026-09-05

**Kontekst:** karta kursanta -> `Szczegóły kursanta` -> `Edytuj`  
**Źródło:** bieżący zalogowany ekran + screenshot przekazany podczas audytu  
**Status:** `USER_CONFIRMED_AUTH_SCREEN`

---

## 1. Znaczenie ekranu

Drawer `Edycja kursanta` edytuje wyłącznie podstawowy profil kursanta.

Formularz nie zawiera kontrolek kursu, PKK, licencji, egzaminu ani dostępu do nauki. Potwierdza to rozdzielenie domen:
- profil kursanta,
- kurs/enrollment,
- PKK,
- learning access,
- licencje,
- egzaminy.

---

## 2. Potwierdzone pola

1. `Imię *`
2. `Nazwisko *`
3. `Telefon`
4. `Email do kontaktu`
5. `Pesel`
6. kontrolka `Kursant nie posiada numeru PESEL`
7. `Lokalizacja` — pojedynczy select

Potwierdzona akcja główna:
- `Zapisz kursanta`

Potwierdzona kontrolka zamknięcia:
- `X`

Na obserwowanym ekranie nie było osobnego przycisku `Anuluj`.

---

## 3. Wymagane pola według markerów UI

Widoczną gwiazdką oznaczono:
- Imię,
- Nazwisko.

Pozostałe pola nie miały widocznego markera `*`.

To jest potwierdzenie na poziomie UI. Dokładne walidacje backendu pozostają do weryfikacji.

---

## 4. PESEL

Formularz wspiera dwa jawne przypadki:
- kursant posiada PESEL,
- kursant nie posiada numeru PESEL.

Własny model nie powinien wymuszać sztucznej wartości PESEL dla osoby, która go nie posiada.

Rekomendowane pola:
- `pesel nullable`,
- `no_pesel_declared boolean`.

PESEL musi być chroniony i nie powinien trafiać do zwykłych logów aplikacyjnych.

---

## 5. Lokalizacja

`Lokalizacja` jest pojedynczym selectem.

Wniosek:
- profil kursanta może mieć opcjonalną główną lokalizację OSK,
- lokalizacja profilu jest odrębna od lokalizacji konkretnego kursu lub wydarzenia kalendarza.

Nie należy z tego ekranu wnioskować many-to-many dla profilu kursanta.

---

## 6. Ważna różnica względem ekranu szczegółów

Na karcie kursanta w sekcji danych widoczna jest także `Data urodzenia`, ale pole to **nie występuje** w formularzu `Edycja kursanta`.

Dlatego:
- `birth_date` jest potwierdzone jako pole prezentowane na szczegółach,
- ale nie jest potwierdzone jako edytowalne w tym formularzu.

Możliwe źródło pochodzenia tej wartości (PESEL, PKK, import lub inny ekran) pozostaje `TO_VERIFY`.

Nie dodajemy własnej logiki wyliczania daty urodzenia bez osobnej decyzji projektowej.

---

## 7. Rozdzielenie odpowiedzialności

Formularz nie edytuje:
- PKK,
- kategorii kursu,
- rodzaju szkolenia,
- etapu szkolenia,
- licencji,
- loginu do nauki,
- języka dostępu,
- egzaminu wewnętrznego,
- płatności/należności.

Te obszary są zarządzane osobnymi flow i encjami.

---

## 8. Potwierdzone akcje

- `open_student_edit_drawer`
- `set_student_first_name`
- `set_student_last_name`
- `set_student_phone`
- `set_student_contact_email`
- `set_student_pesel`
- `toggle_student_no_pesel`
- `select_student_location`
- `save_student_changes`
- `close_student_edit_drawer`

---

## 9. Wymagania dla naszego produktu

- tenant isolation,
- walidacja lokalizacji względem bieżącego OSK,
- ochrona PESEL,
- kontaktowy e-mail oddzielony od loginu/e-maila dostępu do nauki,
- audyt zmian kluczowych danych identyfikacyjnych,
- brak możliwości zmiany kursu/licencji/PKK przez endpoint profilu,
- obsługa kursanta bez PESEL.

---

## 10. Pozostałe niewiadome

- dokładne walidacje telefonu,
- dokładne walidacje e-maila kontaktowego,
- reguły walidacji PESEL,
- zachowanie przy zmianie PESEL,
- duplikaty kursantów,
- czy zmiana lokalizacji wpływa na istniejące kursy/wydarzenia,
- źródło i sposób edycji daty urodzenia,
- success/error messages,
- zachowanie przy zamknięciu `X` z niezapisanymi zmianami.

---

## 11. Acceptance criteria

### AC-STUDENT-EDIT-01 — profile-only
Formularz edytuje podstawowy profil kursanta i nie modyfikuje kursów, PKK, licencji ani egzaminów.

### AC-STUDENT-EDIT-02 — required identity
Imię i nazwisko są wymagane.

### AC-STUDENT-EDIT-03 — no PESEL
System wspiera kursanta bez numeru PESEL bez stosowania sztucznych wartości.

### AC-STUDENT-EDIT-04 — location scope
Wybrana lokalizacja musi należeć do bieżącego OSK.

### AC-STUDENT-EDIT-05 — contact separation
`Email do kontaktu` nie jest automatycznie loginem/e-mailem konta do nauki.
