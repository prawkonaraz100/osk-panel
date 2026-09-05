# 36. Kursanci — formularz Dodaj kursanta

Data weryfikacji: 2026-09-05

**Kontekst:** akcja `Dodaj kursanta` na `/kursanci`  
**Źródło:** bieżący zalogowany ekran + screenshot przekazany podczas audytu  
**Status:** `USER_CONFIRMED_AUTH_SCREEN`

Dane demonstracyjne i liczniki inventory nie są kopiowane jako stałe wartości produktu.

---

## 1. Znaczenie formularza

Formularz `Dodaj kursanta` jest orkiestratorem kilku domen. W jednym flow administrator może:
- utworzyć profil kursanta,
- opcjonalnie dodać od razu kurs/PKK,
- opcjonalnie przypisać lokalizację,
- opcjonalnie przydzielić licencję do platformy nauki i utworzyć dane dostępowe.

Własna implementacja nie powinna spłaszczać tego do jednej tabeli `students`. Operacja zapisu może być jedną transakcją biznesową, ale tworzy/wiąże odrębne encje.

---

## 2. Dane podstawowe kursanta

Potwierdzone pola:
1. `Imię *`
2. `Nazwisko *`
3. `Telefon`
4. `Email do kontaktu`
5. `Pesel`
6. kontrolka `Kursant nie posiada numeru PESEL`

Widoczną gwiazdką oznaczono jako wymagane:
- Imię,
- Nazwisko.

Nie oznaczono gwiazdką:
- Telefon,
- Email do kontaktu,
- PESEL.

### PESEL
System jawnie wspiera przypadek kursanta bez numeru PESEL przez osobną kontrolkę.

Dla naszego produktu rekomendujemy:
- `pesel` nullable,
- jawny powód/brak PESEL jako stan biznesowy zamiast sztucznego numeru,
- walidację wzajemnego wykluczenia: poprawny PESEL albo oznaczenie braku PESEL,
- ochronę PESEL w logach, audycie i eksporcie.

Dokładne zachowanie po zaznaczeniu checkboxa (np. disable/clear pola PESEL) pozostaje do sprawdzenia.

---

## 3. Opcjonalny blok `Kurs (PKK)`

Sekcja jest jawnie oznaczona jako:
- `Kurs (PKK) (opcjonalnie)`.

Potwierdzone pola:
- `PKK`,
- `Kategoria` — pojedynczy select.

Placeholder kategorii:
- `Wybierz kategorię...`.

Na obserwowanym formularzu pola w tym bloku nie mają widocznych gwiazdek wymagania.

### Wniosek
Ręczne utworzenie kursanta może nastąpić bez tworzenia kursu i bez podania PKK.

Jeżeli administrator poda dane kursu, własna implementacja powinna tworzyć osobny enrollment/course powiązany z profilem kursanta.

Dokładna lista kategorii w tym konkretnym selectcie nie została rozwinięta na obserwowanym ekranie; korzystamy ze wspólnego słownika kategorii, nie z hard-code w formularzu.

---

## 4. Lokalizacja

Potwierdzone pole:
- `Lokalizacja` — pojedynczy select.

Na ekranie brak gwiazdki wymagania.

Formularz korzysta z lokalizacji należących do bieżącego OSK.

Do weryfikacji:
- czy zapisuje lokalizację bezpośrednio na profilu kursanta czy na tworzonym kursie,
- czy relacja może być później wielokrotna,
- zachowanie gdy OSK nie ma lokalizacji.

Na karcie kursanta istnieje osobna projekcja `Lokalizacja`, więc przypisanie jest częścią modelu kursanta/enrollmentu i musi pozostać tenant-scoped.

---

## 5. Przydzielenie licencji podczas tworzenia kursanta

Formularz zawiera przełącznik/kontrolkę:
- `Przydziel licencje do prawo-jazdy-360.pl`.

Na obserwowanym ekranie sekcja licencji jest aktywna i widoczna.

Potwierdzone elementy:
- `Wybierz rodzaj licencji`,
- select rodzaju licencji,
- widoczny licznik `Dostępne: <N>`,
- `Email lub login *`,
- `Język *`.

Zaobserwowana wartość rodzaju licencji:
- `1 miesiąc`.

Nie traktujemy jej jako pełnej listy wariantów — inne długości były potwierdzone w module zakupu licencji, ale nie były rozwinięte w tym konkretnym formularzu.

### Ważny wniosek
Pole `Email lub login` potwierdza, że przy tworzeniu dostępu system dopuszcza identyfikator logowania w formie:
- adresu e-mail lub
- loginu.

Nie utożsamiamy tego pola z `Email do kontaktu` kursanta. Są to dwa różne pojęcia:
- kontakt do kursanta,
- identyfikator konta nauki.

### Wymaganie warunkowe
Widoczne gwiazdki przy:
- `Email lub login`,
- `Język`

są interpretowane jako wymagane w aktywnym flow przydzielania licencji. Nie zakładamy, że są wymagane, gdy sekcja licencji jest wyłączona.

---

## 6. Inventory licencji

Widoczny licznik:
- `Dostępne: <N>`.

To jest projekcja dostępnej puli konkretnego rodzaju licencji w OSK.

Nie hardkodujemy obserwowanej liczby.

Własny zapis powinien atomowo:
1. zweryfikować dostępność wybranego wariantu,
2. utworzyć kursanta,
3. utworzyć/wiązać konto nauki,
4. przydzielić licencję,
5. zmniejszyć/zaalokować inventory zgodnie z lifecycle licencji,
6. zapisać audit.

W razie błędu nie może zostać połowiczny stan typu `student_created_but_license_lost`.

---

## 7. Akcja zapisu

Potwierdzona akcja:
- `Zapisz kursanta`.

Potwierdzone zamknięcie drawer:
- `X`.

Na obserwowanym ekranie nie ma osobnego przycisku `Anuluj`; zamknięcie jest realizowane przez `X`.

Dokładne komunikaty success/error i zachowanie po zapisie pozostają do weryfikacji.

---

## 8. Potwierdzone pola wymagane na poziomie UI

### Zawsze widoczne wymagane
- `first_name`,
- `last_name`.

### Warunkowo wymagane przy aktywnym przydzielaniu licencji
- `learning_identifier` (`Email lub login`),
- `language_code`.

### Niewymagane według widocznych markerów
- phone,
- contact_email,
- pesel,
- optional_course_pkk,
- optional_course_category,
- location.

Dokładna walidacja backendu nadal wymaga własnej implementacji zgodnej z wymaganiami formalnymi i domenowymi.

---

## 9. Model domenowy

### `students`
- first_name
- last_name
- phone nullable
- contact_email nullable
- pesel nullable
- no_pesel flag/reason according to own design

### `student_courses` / `enrollments` — opcjonalnie w tym flow
- student_id
- pkk_reference nullable/required only when creating PKK course according to rules
- category_id
- further course fields may be completed later in full `Dodaj kurs`

### `student_location_assignment`
- student/enrollment relation to location depending on final domain decision

### `student_learning_accounts` — gdy licencja włączona
- student_id
- login/email identifier
- language_code

### `student_license_assignments`
- student_id
- learning_account_id
- license_inventory_item/product
- status

---

## 10. Transakcyjność własnego flow

Rekomendowany command:
- `CreateStudentWithOptionalCourseAndLicense`.

Jedna komenda biznesowa, ale wewnętrznie osobne serwisy domenowe.

Minimalne zasady:
- tenant validation lokalizacji i kategorii,
- walidacja unikalności/formatu identyfikatora konta nauki,
- walidacja PESEL gdy podany,
- atomowa rezerwacja/przydzielenie licencji,
- rollback całej operacji przy błędzie krytycznym,
- audit utworzenia profilu, enrollmentu i licencji.

---

## 11. Czego nadal nie znamy

- zachowanie po wyłączeniu przełącznika licencji,
- pełna lista wariantów licencji w tym selectcie,
- pełna lista języków w tym formularzu,
- czy `Email lub login` automatycznie rozpoznaje format,
- jak generowane jest hasło dla loginu,
- czy e-mail kontaktowy może automatycznie wypełnić identyfikator dostępu,
- dokładna lista kategorii w opcjonalnym bloku PKK,
- zależności walidacyjne PKK <-> Kategoria,
- dokładne znaczenie lokalizacji w create-flow,
- duplikat PESEL / telefonu / e-maila / loginu,
- komunikaty success/error,
- redirect po utworzeniu.

---

## 12. Acceptance criteria

### AC-STU-CREATE-01 — profil bez kursu
Administrator może utworzyć profil kursanta bez obowiązkowego PKK/kursu.

### AC-STU-CREATE-02 — brak PESEL
System wspiera kursanta bez numeru PESEL bez tworzenia sztucznej wartości.

### AC-STU-CREATE-03 — opcjonalny kurs
Administrator może opcjonalnie podać PKK i kategorię podczas tworzenia kursanta.

### AC-STU-CREATE-04 — opcjonalna licencja
Administrator może w tym samym flow przydzielić licencję lub utworzyć kursanta bez licencji.

### AC-STU-CREATE-05 — inventory
Przy aktywnym przydzielaniu licencji system pokazuje dostępne inventory wybranego wariantu i nie pozwala na race-condition powodujący ujemny stan.

### AC-STU-CREATE-06 — access identity
Kontaktowy e-mail kursanta i identyfikator logowania do platformy są osobnymi polami domenowymi.

### AC-STU-CREATE-07 — atomicity
Jeżeli wieloetapowy zapis nie powiedzie się, system nie pozostawia niespójnego profilu/kursu/licencji.

### AC-STU-CREATE-08 — tenant isolation
Kategorie, lokalizacje i inventory muszą należeć do bieżącego kontekstu OSK lub do dozwolonego globalnego słownika.
