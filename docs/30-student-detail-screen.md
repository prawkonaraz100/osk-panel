# 30. Kursanci — ekran szczegółów kursanta

Data weryfikacji: 2026-09-05

**Route pattern:** `/kursanci/<student_id>`  
**Źródło:** bieżący zalogowany ekran + screenshot przekazany podczas audytu  
**Status:** `USER_CONFIRMED_AUTH_SCREEN`

Dokument uzupełnia `docs/29-students-list-screen.md`. Dane osobowe, dane logowania, identyfikatory, numery telefonów i inne dane demonstracyjne nie są przepisywane jako dane referencyjne produktu.

---

## 1. Znaczenie ekranu

Karta kursanta jest centralnym agregatem operacyjnym OSK. Łączy w jednym miejscu:
- profil kursanta,
- etap szkolenia,
- egzamin wewnętrzny,
- konto/dostępy do platformy nauki,
- licencje,
- kursy / PKK,
- płatności,
- przejście do postępów.

Dla naszego produktu oznacza to, że `student` jest encją nadrzędną UI, ale dane szczegółowe pozostają rozdzielone domenowo na profile, konta, licencje, enrollments/PKK, egzaminy i płatności.

---

## 2. Nawigacja i akcje globalne

Potwierdzone elementy:
- breadcrumb `Panel główny / Kursanci / <kursant>`,
- `Powrót` -> `/kursanci`,
- imię i nazwisko kursanta,
- globalne akcje `Archiwizuj` i `Usuń`.

Dokładne lifecycle archiwizacji i usuwania pozostają do sprawdzenia. Nie zakładamy hard-delete.

---

## 3. Zakładki

Potwierdzone trzy zakładki:
1. `Profil kursanta`
2. `Egzamin wewnętrzny`
3. `Postępy`

Na obserwowanym ekranie aktywna jest zakładka `Profil kursanta`.

Do dalszego audytu:
- zawartość `Egzamin wewnętrzny`,
- zawartość `Postępy`,
- czy URL zmienia się per tab,
- czy stan zakładki jest linkowalny/deep-linkowalny.

---

## 4. Etap szkolenia — potwierdzona state machine

Ekran zawiera osobny blok zmiany etapu szkolenia.

Potwierdzona instrukcja zależności:
- `Aby edytować etap szkolenia dodaj najpierw kurs`.

Potwierdzona akcja:
- `Dodaj kurs`.

Potwierdzone etapy:
1. `Nieprzypisany`
2. `Teoria`
3. `Praktyka`
4. `Dokumentacja`
5. `Egzamin WORD`
6. `Szkolenie uzupełniające`
7. `Szkolenie zakończone`

Na obserwowanym rekordzie aktualny etap to `Nieprzypisany`.

### Wniosek domenowy
Etap szkolenia jest osobnym stanem od:
- statusu kursu/PKK,
- statusu licencji,
- statusu egzaminu wewnętrznego,
- postępu e-learningowego.

Nie należy modelować wszystkiego jednym `student.status`.

Rekomendacja dla naszego modelu:
- `student_training_stage` albo pole na aktywnym enrollment,
- jawna historia zmian etapu,
- audit trail: kto, kiedy, z jakiego na jaki etap,
- możliwość walidowania przejść zgodnie z regułami biznesowymi i prawnymi.

Dokładne reguły dozwolonych przejść w 360 nie są jeszcze potwierdzone.

---

## 5. Sekcja `Szczegóły kursanta`

Potwierdzona akcja:
- `Edytuj`.

### Podsekcja `Dane`
Potwierdzone pola:
- Imię i nazwisko,
- Data urodzenia,
- Pesel,
- Email do kontaktu,
- Telefon,
- Data dodania.

Na obserwowanym rekordzie część pól jest pusta.

### Podsekcja `Lokalizacja`
Potwierdzone, że kursant ma relację z lokalizacją.

Zaobserwowany stan pusty:
- `Brak przypisanej lokalizacji`.

To potwierdza co najmniej opcjonalną relację kursanta do lokalizacji OSK. Multiplicity i formularz wyboru pozostają do sprawdzenia.

### Podsekcja `Egzamin wewnętrzny`
Potwierdzony stan:
- `Nie przeprowadzony`.

Potwierdzona akcja:
- `Przeprowadź`.

To rozstrzyga wcześniejszą niepewność z listy: co najmniej jeden realny stan egzaminu to `not_conducted` / `Nie przeprowadzony`.

Nie utożsamiamy jednak automatycznie czerwonego `X` z listy z tym stanem bez osobnego potwierdzenia renderowania listy.

---

## 6. Sekcja `Dostępy`

Potwierdzone elementy:
- `Dane logowania prawo-jazdy-360.pl`,
- `Język`,
- widoczny login/username,
- kod języka,
- akcja `Pobierz dostępy`.

Potwierdzony wzorzec trasy pobrania:
`/licencje/pobierz?guid=<student_id>`

Wniosek:
- system potrafi wygenerować/pobrać dane dostępowe kursanta jako osobny artefakt/dokument,
- konto nauki jest powiązane z kursantem, ale pozostaje oddzielną encją od profilu OSK.

Do sprawdzenia:
- format pobieranego dokumentu,
- czy zawiera login, hasło, QR, instrukcję,
- czy pobranie jest audytowane,
- czy dostęp może istnieć bez aktywnej licencji.

---

## 7. Sekcja `Licencje`

Potwierdzona akcja:
- `Przydziel licencje`.

Potwierdzone dane w tabeli:
- data dodania,
- widoczny nagłówek `Zakończenie`,
- widoczny nagłówek `Status`,
- wartości języka, np. `EN`,
- status `Nie aktywowano`,
- akcja `Usuń` przy każdej obserwowanej nieaktywowanej licencji.

### Ważna niejednoznaczność prezentacji tabeli
Na screenshotcie/ekstrakcji występuje niespójność między nagłówkami a pozycją wartości `EN`. Nie przypisujemy na sztywno `EN` do pola `Zakończenie` ani `Status`.

Potwierdzamy semantycznie tylko, że rekord licencji zawiera co najmniej:
- `added_at`,
- `language_code`,
- `status`,
- opcjonalny/end-date element do dalszej weryfikacji,
- akcję usunięcia nieaktywowanej licencji.

Potwierdzony status:
- `Nie aktywowano`.

To jest zgodne z wcześniej potwierdzonym lifecycle licencji: nieaktywowaną licencję można usunąć/cofnąć przed aktywacją.

Do sprawdzenia:
- co dokładnie robi `Usuń` z poziomu kursanta,
- czy licencja wraca do inventory OSK,
- typ/długość licencji,
- data zakończenia po aktywacji,
- statusy aktywna/wygasła,
- modal potwierdzający.

---

## 8. Sekcja `Kursy (PKK)`

Potwierdzona akcja:
- `Dodaj kurs`.

Zaobserwowany empty state:
- `Brak przypisanych kursów`.

To potwierdza kolekcję kursów/enrollments na karcie kursanta, nie pojedyncze pole PKK w profilu.

W połączeniu z listą `/kursanci` potwierdzamy, że kurs może posiadać:
- kategorię,
- typ szkolenia,
- referencję PKK,
- ostatnią operację PKK.

Do audytu po `Dodaj kurs`:
- formularz,
- kategoria,
- rodzaj szkolenia,
- numer PKK,
- import/pobranie PKK,
- lokalizacja,
- daty,
- status kursu,
- operacje PKK.

---

## 9. Sekcja `Płatności`

Potwierdzona akcja:
- `Dodaj płatność`.

Potwierdzone kolumny:
- `Tytuł`,
- `Kwota`,
- `Pozostało`,
- `Wpłaty`,
- `Data dodania`.

Zaobserwowany empty state:
- `Brak dodanych płatności`.

Potwierdzone podsumowanie:
- `Łączne saldo`.

### Wniosek domenowy
System posiada **rozrachunki kursanta z OSK niezależne od historii zakupów konta OSK**.

To są dwa różne moduły finansowe:
- `Historia zakupów` = zakupy OSK od platformy,
- `Płatności kursanta` = należności/wpłaty kursanta wobec OSK.

U nas nie należy łączyć tych tabel ani ledgerów.

Rekomendowany model:
- `student_charges` / należności,
- `student_payments` / wpłaty,
- `amount_due`,
- `amount_paid`,
- `remaining_amount`,
- `created_at`,
- audit trail.

Dokładny formularz `Dodaj płatność`, obsługa częściowych wpłat i edycji pozostają do audytu.

---

## 10. Potwierdzone akcje ekranu

- `back_to_students`
- `archive_student`
- `delete_student`
- `open_student_profile_tab`
- `open_internal_exam_tab`
- `open_progress_tab`
- `add_student_course`
- `change_training_stage_control_present`
- `edit_student_details`
- `conduct_internal_exam`
- `download_student_access_credentials`
- `assign_student_license`
- `delete_unactivated_student_license`
- `add_student_payment`

---

## 11. Model domenowy po tym ekranie

### `students`
Profil osobowy i kontaktowy.

### `student_learning_accounts`
Login, język i lifecycle konta nauki.

### `student_licenses`
Przydzielone licencje z niezależnym statusem aktywacji.

### `student_courses` / `enrollments`
Kategoria, typ szkolenia, PKK, etap szkolenia i historia.

### `internal_exams`
Oddzielny lifecycle egzaminu wewnętrznego.

### `student_financial_accounts`
Należności i wpłaty wobec OSK.

### `locations`
Opcjonalne przypisanie kursanta do lokalizacji / zakres multiplicity do potwierdzenia.

Nie spłaszczamy tych danych do jednej tabeli `students`.

---

## 12. Czego nadal brakuje

Po tym ekranie największe luki w module Kursanci to:
- formularz `Edytuj` kursanta,
- formularz `Dodaj kursanta`,
- flow `Dodaj kursanta z PKK`,
- formularz `Dodaj kurs`,
- dokładne działanie zmiany etapu szkolenia,
- zakładka `Egzamin wewnętrzny`,
- zakładka `Postępy`,
- formularz `Przeprowadź` egzamin,
- formularz `Przydziel licencje`,
- dokładny lifecycle licencji po aktywacji,
- format `Pobierz dostępy`,
- formularz `Dodaj płatność`,
- szczegóły płatności częściowych i salda,
- lifecycle archiwizacji/usunięcia kursanta,
- filtry/sortowanie listy,
- walidacje i komunikaty success/error.

---

## 13. Acceptance criteria

### AC-STU-DET-01 — agregat operacyjny
Karta kursanta prezentuje profil, etap szkolenia, dostęp, licencje, kursy/PKK, egzamin wewnętrzny i rozrachunki.

### AC-STU-DET-02 — osobne lifecycle
Etap szkolenia, licencja, kurs/PKK i egzamin wewnętrzny są osobnymi stanami domenowymi.

### AC-STU-DET-03 — training stages
System wspiera co najmniej etapy: Nieprzypisany, Teoria, Praktyka, Dokumentacja, Egzamin WORD, Szkolenie uzupełniające, Szkolenie zakończone.

### AC-STU-DET-04 — course prerequisite
Zmiana etapu szkolenia wymaga uprzedniego dodania kursu.

### AC-STU-DET-05 — license assignment
Administrator może przydzielić kursantowi licencję i usunąć obserwowaną licencję w stanie `Nie aktywowano`.

### AC-STU-DET-06 — internal exam
Profil pokazuje stan egzaminu wewnętrznego i pozwala rozpocząć flow `Przeprowadź`.

### AC-STU-DET-07 — credentials export
Administrator może pobrać dane dostępowe kursanta jako osobny artefakt.

### AC-STU-DET-08 — student finances
Rozrachunki kursanta są oddzielone od zakupów OSK wobec platformy.

### AC-STU-DET-09 — tenant isolation
Każdy student, enrollment, licencja, egzamin i płatność są autoryzowane względem bieżącego OSK.
