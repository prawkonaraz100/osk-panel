# 20. Pojazdy — lista i powiązania operacyjne

Data weryfikacji: 2026-09-05

**Route:** `/pojazdy`  
**Źródło:** bieżący zalogowany ekran przekazany podczas audytu  
**Status:** `USER_CONFIRMED_AUTH_SCREEN`

Dokument opisuje listę pojazdów. Ekran szczegółów jest zmapowany osobno w `docs/25-vehicle-detail-screen.md`.

## 1. Cel ekranu

`Pojazdy` jest centralną listą pojazdów OSK wykorzystywanych operacyjnie w szkoleniu i kalendarzu.

Każdy rekord posiada UUID używany co najmniej do:
- ekranu szczegółów,
- filtrowania kalendarza,
- powiązania zdjęcia.

Konkretnych UUID i danych demonstracyjnych nie zapisujemy w dokumentacji.

## 2. Akcja główna

Potwierdzona:
- `Dodaj pojazd`.

Formularz dodawania nadal wymaga audytu.

## 3. Potwierdzone kolumny listy

1. `Zdjęcie`
2. `Nr rejestracyjny`
3. `Marka i model` — wcześniejsza ekstrakcja tekstowa zwróciła `Maria i model`; screenshot szczegółów potwierdza prawidłową semantykę `Marka i model`
4. `Kategorie`
5. `Dokumenty`

## 4. Dane i dokumenty widoczne na liście

Potwierdzone:
- zdjęcie,
- numer rejestracyjny,
- marka/model,
- kolumna kategorii,
- komunikat `Przegląd wygasł <data>`.

Lista potwierdza automatyczną ocenę terminu przeglądu i prezentację alertu po wygaśnięciu.

Ekran szczegółów dodatkowo potwierdził obsługę terminów:
- Przegląd,
- OC,
- AC.

Szczegóły: `docs/25-vehicle-detail-screen.md`.

## 5. Potwierdzone akcje wiersza

### `Kalendarz`
Wzorzec:
`/kalendarz?v=<vehicle_uuid>`

Pojazd jest pełnoprawnym zasobem kalendarza.

### `Zobacz`
Wzorzec:
`/pojazdy/<vehicle_uuid>`

Ekran szczegółów jest już zweryfikowany.

### `Podgląd`
Tekst występujący po `Zobacz` pozostaje `TO_VERIFY_VISUAL` — może być tooltipem/accessibility label, nie uznajemy go za osobną akcję.

## 6. Potwierdzone akcje z ekranu szczegółów

Dzięki audytowi `/pojazdy/<uuid>` potwierdzono również:
- `Archiwizuj`,
- `Usuń`,
- `Edytuj`,
- `Pełny kalendarz`,
- `Dodaj wydarzenie`,
- kalendarz Miesiąc/Tydzień/Dzień,
- Dzisiaj,
- poprzedni/następny okres.

Dokładny lifecycle archiwizacji i usuwania nadal wymaga sprawdzenia.

## 7. Identyfikacja i tenant isolation

UUID pojazdu jest używany w:
- szczegółach `/pojazdy/<uuid>`,
- filtrze `/kalendarz?v=<uuid>`,
- ścieżce assetu zdjęcia.

Każda operacja musi server-side sprawdzać `organization_id`; sam UUID nie jest mechanizmem autoryzacji.

## 8. Model pojazdu — potwierdzone minimum

Po audycie listy i szczegółów model powinien obsługiwać co najmniej:
- `id` UUID,
- `organization_id`,
- `make`,
- `model`,
- `registration_number`,
- `side_number` nullable,
- `vin` nullable do czasu potwierdzenia walidacji,
- `engine_capacity_cm3` nullable,
- `production_year` nullable,
- `photo_asset_id` nullable,
- `created_at`,
- `archived_at` nullable.

Pole `Data dodania` na rekordzie demonstracyjnym pokazało `01-01-0001`; traktujemy to jako anomalię/sentinel danych demo, nie wartość do odwzorowania.

## 9. Terminy/dokumenty pojazdu

Potwierdzone typy:
- `technical_inspection` — Przegląd,
- `oc_insurance` — OC,
- `ac_insurance` — AC.

Każdy ma niezależny `valid_until` i status. Nie używamy jednego statusu dla wszystkich dokumentów pojazdu.

Rekomendowany model:
- `vehicle_documents` albo `vehicle_validities`,
- `vehicle_id`, `type`, `valid_until`, `status`, opcjonalnie numer dokumentu i plik.

## 10. Relacje

Potwierdzone:
- vehicle -> photo,
- vehicle -> validity/documents,
- vehicle -> calendar context,
- vehicle -> details screen,
- pole `Kategorie` istnieje.

Do potwierdzenia:
- multiplicity kategorii poprzez formularz,
- vehicle -> location,
- vehicle -> instructors,
- okresy serwisowe/niedostępności.

## 11. Rekomendowane API

- `GET /api/v1/vehicles`
- `POST /api/v1/vehicles`
- `GET /api/v1/vehicles/{vehicle}`
- `PATCH /api/v1/vehicles/{vehicle}`
- `POST /api/v1/vehicles/{vehicle}/archive`
- `GET /api/v1/vehicles/{vehicle}/documents`
- `GET /api/v1/calendar/events?vehicle_id={vehicle}`

Akcja trwałego/soft usunięcia jest w UI potwierdzona jako `Usuń`, ale dokładną semantykę endpointu ustalimy po zbadaniu lifecycle.

## 12. Acceptance criteria

### AC-VEH-01 — lista
Administrator widzi zdjęcie, numer rejestracyjny, markę/model, kategorie i stan dokumentów.

### AC-VEH-02 — termin
Przeterminowany przegląd jest oznaczany wraz z datą.

### AC-VEH-03 — kalendarz
`Kalendarz` otwiera kontekst wskazanego pojazdu.

### AC-VEH-04 — szczegóły
`Zobacz` otwiera `/pojazdy/{uuid}`.

### AC-VEH-05 — tenant isolation
Podmiana UUID nie daje dostępu do pojazdu innego OSK.

### AC-VEH-06 — niezależne terminy
Przegląd, OC i AC są modelowane niezależnie.

## 13. Pozostałe luki

Do pełnego zamknięcia `Pojazdy` pozostają:
- `Dodaj pojazd`,
- formularz `Edytuj`,
- wymagane pola i walidacje,
- selector/multiplicity kategorii,
- upload/usuwanie zdjęcia,
- relacja z lokalizacją, jeśli występuje,
- lifecycle `Archiwizuj`,
- lifecycle `Usuń`,
- formularz `Dodaj wydarzenie`,
- filtry/wyszukiwanie/sortowanie listy,
- komunikaty success/error.

Nie są już luką:
- ekran szczegółów,
- Przegląd/OC/AC,
- Nr boczny,
- VIN jako pole,
- pojemność,
- rok produkcji,
- archiwizacja jako dostępna akcja,
- usuwanie jako dostępna akcja,
- osadzony kalendarz.
