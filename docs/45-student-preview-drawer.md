# 45. Kursanci — szybki `Podgląd` z listy

Data weryfikacji: 2026-09-05

**Kontekst:** `/kursanci` -> akcja `Podgląd` przy wierszu kursanta  
**Źródło:** bieżący zalogowany ekran + screenshot przekazany podczas audytu  
**Status:** `USER_CONFIRMED_AUTH_SCREEN`

---

## 1. Rola ekranu

`Podgląd` otwiera boczny, lekki drawer tylko do odczytu. Nie przenosi administratora na pełną kartę kursanta.

To jest szybki podgląd podstawowych informacji bez wchodzenia w pełny workflow kursanta.

Różnica względem `Przejdź do`:
- `Podgląd` = szybki read-only summary,
- `Przejdź do` = pełna karta operacyjna z zakładkami, kursami, PKK, licencjami, egzaminami, postępami i finansami.

---

## 2. Potwierdzone pola

Drawer pokazuje:
- `Imię i nazwisko`,
- `Data urodzenia`,
- `Pesel`,
- `Email do kontaktu`,
- `Telefon`,
- `Data dodania`,
- `Kursy`,
- `Płatności`.

Zaobserwowany przykład:
- imię i nazwisko: Jan Nowak,
- telefon: 258369147,
- data dodania: 21-01-2026,
- kursy: `Brak`,
- płatności: `Brak`.

Puste pola są renderowane jako puste, bez specjalnego komunikatu.

---

## 3. Potwierdzone akcje

- otwarcie podglądu z listy,
- zamknięcie przez `X`.

Nie zaobserwowano w drawerze:
- `Edytuj`,
- `Usuń`,
- `Archiwizuj`,
- `Dodaj kurs`,
- `Przydziel licencję`,
- `Dodaj płatność`,
- `Rozpocznij egzamin`,
- linku do postępów.

Dlatego drawer traktujemy jako read-only.

---

## 4. Znaczenie dla UX naszego produktu

Warto zachować ten wzorzec, bo sekretariat może szybko sprawdzić dane kursanta bez opuszczania listy.

Rekomendacja:
- drawer szerokości kompaktowej,
- bez formularzy,
- bez ciężkich sekcji,
- opcjonalny pojedynczy link `Otwórz pełną kartę` jako nasze rozszerzenie UX.

Nie kopiujemy wyglądu konkurenta 1:1.

---

## 5. Model danych

Podgląd jest projekcją istniejących domen, nie osobną encją.

Źródła danych:
- `student_profile` -> dane osobowe,
- `student_courses` -> agregat `Kursy`,
- `student_finance` -> agregat `Płatności`.

Nie należy tworzyć osobnej tabeli tylko dla quick preview.

---

## 6. Bezpieczeństwo

- tenant isolation,
- podgląd kursanta tylko dla bieżącego OSK,
- PESEL traktowany jako dane wrażliwe,
- brak mutacji z poziomu quick preview,
- nie ujawniać danych logowania ani haseł w szybkim podglądzie.

---

## 7. Acceptance criteria

### AC-STUDENT-PREVIEW-01 — open without navigation
Kliknięcie `Podgląd` otwiera szybki drawer bez przejścia na pełną kartę kursanta.

### AC-STUDENT-PREVIEW-02 — read only
Drawer nie zawiera akcji modyfikujących kursanta.

### AC-STUDENT-PREVIEW-03 — summary fields
Drawer pokazuje podstawowe dane osobowe oraz skrót kursów i płatności.

### AC-STUDENT-PREVIEW-04 — close
Drawer można zamknąć przez `X`.

### AC-STUDENT-PREVIEW-05 — authorization
Backend zwraca dane tylko wtedy, gdy kursant należy do bieżącej organizacji OSK.
