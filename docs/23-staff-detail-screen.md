# 23. Pracownicy — ekran szczegółów pracownika

Data weryfikacji: 2026-09-05

**Route pattern:** `/pracownicy/<staff_uuid>`  
**Źródło:** bieżący zalogowany ekran + screenshot przekazany podczas audytu  
**Status:** `USER_CONFIRMED_AUTH_SCREEN`

Ten dokument opisuje aktualny ekran szczegółów pracownika i uzupełnia `docs/21-staff-screen.md` oraz `docs/22-staff-create-form.md`.

Dane osobowe i UUID z audytowanego konta nie są przepisywane do dokumentacji.

---

## 1. Struktura ekranu

Ekran składa się z:

1. breadcrumb `Panel główny / Pracownicy / <pracownik>`,
2. akcji `Powrót`,
3. zdjęcia i imienia/nazwiska pracownika,
4. globalnych akcji rekordu `Archiwizuj` i `Usuń`,
5. sekcji `Szczegóły pracownika`,
6. sekcji `Dostęp do panelu`,
7. sekcji `Kalendarz`.

To potwierdza rozdzielenie trzech obszarów domenowych:
- profil/ewidencja pracownika,
- konto i autoryzacja,
- planowanie pracy.

---

## 2. Potwierdzone akcje globalne

### `Powrót`
Prowadzi do:
`/pracownicy`

### `Archiwizuj`
Potwierdzona akcja rekordu.

Wniosek dla naszego systemu:
- archiwizacja jest osobnym stanem/procesem od trwałego usunięcia,
- należy zachować historię powiązań pracownika z wydarzeniami, dokumentami i audytem,
- dokładny modal potwierdzający i skutek dla kalendarza pozostają do sprawdzenia.

### `Usuń`
Potwierdzona osobna akcja rekordu.

Nie zakładamy jeszcze:
- czy jest to hard delete,
- soft delete,
- czy usunięcie jest blokowane przy istniejących powiązaniach,
- jaki komunikat potwierdzenia jest używany.

Dla naszego systemu rekomendacja bezpieczeństwa danych: preferować soft-delete/archiwizację, a trwałe usunięcie dopuszczać tylko gdy polityka i zależności na to pozwalają.

---

## 3. Sekcja `Szczegóły pracownika`

Posiada osobną akcję:
- `Edytuj`

### Podsekcja `Dane`
Potwierdzone pola prezentowane w szczegółach:
- Imię i nazwisko,
- Rodzaj pracownika,
- Email,
- Telefon,
- Pesel,
- Numer uprawnień,
- Kategorie,
- Data dodania.

Na obserwowanym rekordzie część pól może być pusta.

### `Rodzaj pracownika`
Potwierdzona konkretna wartość techniczna/etykieta:
- `OfficeWorker`

To jest pierwszy dokładnie zaobserwowany typ pracownika. Nie traktujemy go jeszcze jako pełnej listy słownika.

### `Data dodania`
Potwierdzone, że system zachowuje i pokazuje datę utworzenia rekordu pracownika.

W naszym modelu:
- `created_at` jako timestamp,
- warstwa prezentacji może pokazywać tylko datę.

---

## 4. Sekcja `Ważność`

Potwierdzone pozycje:
- Legitymacji,
- Badań lekarskich,
- Badań psychologicznych.

Każda prezentuje własną datę ważności.

Na obserwowanym ekranie przeterminowane terminy są oznaczone ikoną ostrzegawczą.

Potwierdza to wcześniejszy model niezależnych `staff_documents` / terminów ważności.

Nie łączymy tych trzech terminów w jeden status pracownika.

---

## 5. Sekcja `Dostęp do panelu`

Posiada osobną akcję:
- `Edytuj`

### Potwierdzone pole informacyjne
`Czy pracownik ma dostęp do panelu?`

Zaobserwowana wartość:
- `Nie`

To ostatecznie potwierdza rozdzielenie:
- `staff_profile`
- opcjonalnego `user/account`.

Osobna akcja `Edytuj` oznacza, że dostęp do panelu ma własny lifecycle niezależny od edycji danych pracownika.

Do dalszego audytu po kliknięciu `Edytuj`:
- włączenie/wyłączenie dostępu,
- login/e-mail konta,
- hasło lub zaproszenie,
- role i permissions,
- zakres dostępu per lokalizacja/moduł,
- sposób odebrania dostępu,
- reset hasła,
- komunikaty i walidacje.

---

## 6. Wbudowany `Kalendarz`

Ekran szczegółów zawiera osadzony kalendarz pracownika.

Potwierdzone elementy:
- tytuł `Kalendarz`,
- akcja `Pełny kalendarz`,
- akcja `Dodaj wydarzenie`,
- nawigacja poprzedni/następny okres,
- przycisk `Dzisiaj`,
- przełączniki widoku:
  - `Miesiąc`,
  - `Tydzień`,
  - `Dzień`,
- widok miesięczny z siatką dni.

Na screenshotcie aktywny jest widok `Miesiąc`.

### `Pełny kalendarz`
Potwierdzony link:
`/kalendarz`

Nie przesądzamy jeszcze, czy po przejściu zachowany jest filtr konkretnego pracownika, ponieważ przekazany URL nie zawiera `?w=<uuid>`.

### `Dodaj wydarzenie`
Potwierdzona akcja z poziomu szczegółów pracownika.

Bardzo prawdopodobne jest wstępne powiązanie wydarzenia z pracownikiem, ale bez otwarcia formularza nie oznaczamy tego jako potwierdzone.

Do audytu po kliknięciu:
- formularz wydarzenia,
- typ wydarzenia,
- data i czas,
- pracownik,
- kursant,
- pojazd,
- lokalizacja,
- status,
- konflikty,
- notatki,
- powtarzalność,
- zapis/anulowanie.

---

## 7. Potwierdzone akcje ekranu szczegółów

- back_to_staff_list
- archive_staff
- delete_staff
- edit_staff_details
- edit_panel_access
- open_full_calendar
- add_calendar_event
- calendar_previous_period
- calendar_next_period
- calendar_today
- calendar_view_month
- calendar_view_week
- calendar_view_day

---

## 8. Aktualizacja modelu domenowego

### `staff_profiles`
Minimalnie:
- id UUID
- organization_id
- first_name
- last_name
- email nullable
- phone nullable
- pesel nullable
- authorization_number nullable
- created_at
- archived_at nullable — uzasadnione potwierdzoną akcją `Archiwizuj`
- deleted_at nullable — rekomendowane dla naszego systemu, dokładna implementacja 360 niepotwierdzona

### relacje
Potwierdzone przez formularz i szczegóły:
- staff <-> staff_types many-to-many
- staff <-> categories many-to-many
- staff <-> locations many-to-many
- staff -> documents/validity records
- staff -> optional panel account
- staff -> calendar/events context

---

## 9. Wnioski dla RBAC

Ponieważ `Dostęp do panelu` ma własną sekcję i osobną akcję `Edytuj`, permissions nie powinny być skutkiem samego typu pracownika.

Model naszego produktu powinien rozdzielać:
- funkcję biznesową pracownika (`staff_types`),
- konto uwierzytelniające (`user`),
- membership w organizacji,
- role/permissions.

Przykład: `OfficeWorker` nie powinien automatycznie oznaczać pełnego dostępu administracyjnego bez osobnej polityki uprawnień.

---

## 10. Czego nadal nie znamy

Po tym ekranie w module Pracownicy pozostają głównie:
- formularz `Edytuj` szczegółów i jego walidacje,
- ekran `Edytuj` w `Dostęp do panelu`,
- pełny słownik `Rodzaj pracownika`,
- dokładny lifecycle archiwizacji,
- dokładny lifecycle usuwania,
- formularz `Dodaj wydarzenie`,
- wpływ archiwizacji/usunięcia na przyszłe wydarzenia,
- permissions po utworzeniu konta,
- walidacje PESEL/e-mail/numeru uprawnień,
- komunikaty success/error.

---

## 11. Acceptance criteria

### AC-STF-DET-01 — szczegóły
Po wejściu na `/pracownicy/{id}` administrator widzi profil pracownika, terminy dokumentów, stan dostępu do panelu i kalendarz.

### AC-STF-DET-02 — niezależna edycja konta
Edycja danych pracownika i edycja dostępu do panelu są osobnymi procesami.

### AC-STF-DET-03 — archiwizacja
Administrator ma osobną akcję archiwizacji pracownika niezależną od usunięcia.

### AC-STF-DET-04 — usunięcie
Administrator ma osobną akcję usunięcia; nasza implementacja musi chronić integralność historycznych danych i zależności.

### AC-STF-DET-05 — dokumenty
Każdy z trzech terminów ważności jest prezentowany niezależnie i może być oznaczony alertem po wygaśnięciu.

### AC-STF-DET-06 — calendar views
Kalendarz na karcie pracownika obsługuje co najmniej widoki miesiąc/tydzień/dzień oraz nawigację poprzedni/następny/dzisiaj.

### AC-STF-DET-07 — add event
Z poziomu szczegółów pracownika dostępna jest akcja utworzenia wydarzenia kalendarza.
