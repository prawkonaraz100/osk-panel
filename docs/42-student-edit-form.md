# 42. Kursanci — formularz Edycja kursanta

Data weryfikacji: 2026-09-05

**Kontekst:** karta kursanta -> `Szczegóły kursanta` -> `Edytuj`  
**Źródło:** bieżący zalogowany ekran + screenshot + potwierdzone zachowanie interakcyjne przekazane podczas audytu  
**Status:** `USER_CONFIRMED_AUTH_SCREEN`

---

## 1. Znaczenie ekranu

Drawer `Edycja kursanta` edytuje podstawowy profil kursanta.

Formularz nie zawiera kontrolek kursu, PKK, licencji, egzaminu ani dostępu do nauki. Potwierdza to rozdzielenie domen:
- profil kursanta,
- kurs/enrollment,
- PKK,
- learning access,
- licencje,
- egzaminy.

---

## 2. Potwierdzone pola

W stanie, gdy kursant ma ścieżkę z PESEL, widoczne są:

1. `Imię *`
2. `Nazwisko *`
3. `Telefon`
4. `Email do kontaktu`
5. `Pesel`
6. kontrolka `Kursant nie posiada numeru PESEL`
7. `Lokalizacja` — pojedynczy select

Po zaznaczeniu `Kursant nie posiada numeru PESEL` system wymaga dodatkowo:
8. `Data urodzenia` — pole warunkowe, wymagane dla kursanta bez PESEL.

Potwierdzona akcja główna:
- `Zapisz kursanta`

Potwierdzona kontrolka zamknięcia:
- `X`

Na obserwowanym ekranie nie było osobnego przycisku `Anuluj`.

---

## 3. Wymagane pola i walidacja warunkowa

Zawsze wymagane według widocznych markerów UI:
- Imię,
- Nazwisko.

Dodatkowa potwierdzona reguła biznesowa:
- jeśli kursant **nie posiada PESEL**, trzeba podać `Datę urodzenia`.

Model walidacji powinien więc obsługiwać co najmniej dwa warianty:

### Wariant A — kursant posiada PESEL
- `pesel` podany,
- ręczne pole `birth_date` nie jest wymagane w obserwowanym stanie formularza.

### Wariant B — kursant nie posiada PESEL
- `no_pesel_declared = true`,
- `pesel = null`,
- `birth_date` jest wymagane.

Nie potwierdzono jeszcze technicznie, czy przy wariancie A data urodzenia jest automatycznie wyliczana z PESEL, pobierana z PKK czy pochodzi z innego mechanizmu. Nie przypisujemy konkurentowi konkretnej implementacji bez dalszej obserwacji.

---

## 4. PESEL i data urodzenia

Formularz wspiera dwa jawne przypadki:
- kursant posiada PESEL,
- kursant nie posiada numeru PESEL i wtedy podaje datę urodzenia ręcznie.

Własny model powinien wspierać:
- `pesel nullable`,
- `no_pesel_declared boolean`,
- `birth_date date` z walidacją warunkową.

Rekomendowana reguła własnego produktu:
- wymagaj `pesel` albo jawnego `no_pesel_declared=true`,
- jeśli `no_pesel_declared=true`, wymagaj `birth_date`,
- nie zapisuj sztucznych numerów PESEL.

PESEL musi być chroniony i nie powinien trafiać do zwykłych logów aplikacyjnych.

---

## 5. Lokalizacja

`Lokalizacja` jest pojedynczym selectem.

Wniosek:
- profil kursanta może mieć opcjonalną główną lokalizację OSK,
- lokalizacja profilu jest odrębna od lokalizacji konkretnego kursu lub wydarzenia kalendarza.

Nie należy z tego ekranu wnioskować many-to-many dla profilu kursanta.

---

## 6. Relacja z ekranem szczegółów

Na karcie kursanta w sekcji danych widoczna jest `Data urodzenia`.

Po korekcie audytu wiemy już, że data urodzenia **może być edytowana/wprowadzana warunkowo**:
- pole pojawia się lub staje się wymagane, gdy zaznaczono brak PESEL.

To wyjaśnia, dlaczego pole nie było widoczne na przesłanym podstawowym stanie formularza.

Nadal nie znamy dokładnego źródła `birth_date` dla kursanta posiadającego PESEL.

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
- `set_student_birth_date_when_no_pesel`
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
- obsługa kursanta bez PESEL,
- warunkowa walidacja `birth_date` dla kursanta bez PESEL.

---

## 10. Pozostałe niewiadome

- dokładne walidacje telefonu,
- dokładne walidacje e-maila kontaktowego,
- reguły walidacji PESEL,
- zachowanie przy zmianie z `ma PESEL` na `brak PESEL` i odwrotnie,
- duplikaty kursantów,
- czy zmiana lokalizacji wpływa na istniejące kursy/wydarzenia,
- źródło daty urodzenia dla kursanta posiadającego PESEL,
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

### AC-STUDENT-EDIT-04 — conditional birth date
Jeśli kursant nie posiada PESEL, data urodzenia jest wymagana.

### AC-STUDENT-EDIT-05 — location scope
Wybrana lokalizacja musi należeć do bieżącego OSK.

### AC-STUDENT-EDIT-06 — contact separation
`Email do kontaktu` nie jest automatycznie loginem/e-mailem konta do nauki.
