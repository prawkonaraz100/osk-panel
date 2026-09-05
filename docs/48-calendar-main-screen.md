# 48. Kalendarz — główny ekran

Data weryfikacji: 2026-09-05

**Route:** `/kalendarz`  
**Źródło:** bieżący zalogowany ekran + screenshot przekazany podczas audytu  
**Status:** `USER_CONFIRMED_AUTH_SCREEN`

---

## 1. Znaczenie modułu

Główny `Kalendarz` jest centralnym planerem zasobów OSK.

Na jednym ekranie łączy:
- pracowników,
- pojazdy,
- lokalizacje,
- wydarzenia kalendarzowe,
- jazdy,
- ważne daty.

To nie jest wyłącznie kalendarz kursanta ani wyłącznie grafik instruktora.

---

## 2. Górne filtry typu wpisu

Potwierdzone kontrolki typu checkbox:
- `Wydarzenie`,
- `Jazda`,
- `Ważne daty`.

Najsilniejsza interpretacja UI: kontrolki filtrują widoczne typy wpisów w kalendarzu.

Dokładna semantyka `Ważne daty` i źródła automatycznych wpisów pozostają do dalszej weryfikacji.

Potwierdzona akcja:
- `Dodaj wydarzenie`.

---

## 3. Lewy panel zasobów

### 3.1. Pracownicy

Sekcja `Pracownicy` zawiera:
- `Zaznacz wszystko`,
- checkbox przy każdym pracowniku,
- imię i nazwisko,
- opcjonalne oznaczenia kategorii przy pracowniku,
- wizualny wskaźnik `!` przy części pracowników,
- akcję `Dodaj pracownika`.

Zaobserwowane przykłady:
- `Jan Kowalski` — kategorie `A, B`,
- `Jan Nowak`,
- `Anna Nowak`.

### Uwaga o `!`
Wskaźnik `!` jest widoczny, ale ten ekran nie opisuje jego dokładnego znaczenia. W kontekście wcześniej zaobserwowanych terminów dokumentów może oznaczać ostrzeżenie, ale nie zapisujemy tego jako potwierdzonej semantyki bez osobnego dowodu.

### 3.2. Pojazdy

Sekcja `Pojazdy` zawiera:
- `Zaznacz wszystko`,
- checkbox przy każdym pojeździe,
- marka/model,
- opcjonalne oznaczenie kategorii,
- wizualny wskaźnik `!` przy części pojazdów,
- akcję `Dodaj pojazd`.

Zaobserwowane:
- `Hyundai i20` — kategoria `B`,
- `Yamaha MT-07`.

### 3.3. Lokalizacje

Sekcja `Lokalizacje` zawiera:
- `Zaznacz wszystko`,
- grupowanie według rodzaju lokalizacji,
- checkbox przy lokalizacji,
- nazwę lokalizacji,
- adres,
- akcję `Dodaj lokalizację`.

Zaobserwowane grupy/typy:
- `Sala wykładowa`,
- `Plac manewrowy`.

Zaobserwowane lokalizacje:
- `Sala wykładowa` — `Bielerzewskiej 4B`,
- `Plan nauki jazdy` — `Bielerzewskiej 4B`.

---

## 4. Semantyka zaznaczeń zasobów

Każdy zasób ma checkbox, a każda grupa ma `Zaznacz wszystko`.

Najsilniejsza interpretacja:
- zaznaczone zasoby określają, czyje/które grafiki są renderowane w kalendarzu,
- możliwe jest jednoczesne porównywanie wielu pracowników, pojazdów i lokalizacji.

To powinno być u nas modelowane jako wielowymiarowy filtr zasobów, a nie jako osobne trzy kalendarze.

Nie potwierdzono jeszcze:
- czy brak zaznaczeń oznacza `pokaż wszystkie` czy `pokaż żadne`,
- czy wybory są zapamiętywane między sesjami,
- jak renderowane są konflikty wielu zasobów.

---

## 5. Widok kalendarza

Potwierdzone elementy nawigacji:
- poprzedni okres,
- następny okres,
- `Dzisiaj`,
- `Miesiąc`,
- `Tydzień`,
- `Dzień`.

Obserwowany widok:
- `Miesiąc`,
- `wrzesień 2026`.

Kalendarz renderuje siatkę dni tygodnia i dni poprzedniego/następnego miesiąca.

---

## 6. Połączenia z innymi modułami

Potwierdzony wzorzec całego panelu:
- szczegóły pracownika mają osadzony kalendarz,
- szczegóły pojazdu mają osadzony kalendarz,
- lista lokalizacji ma link do kalendarza konkretnej lokalizacji,
- główny `/kalendarz` agreguje wszystkie te zasoby.

Własny model powinien mieć jeden silnik kalendarza z relacjami do zasobów, zamiast osobnych implementacji dla pracownika/pojazdu/lokalizacji.

Rekomendowane relacje wydarzenia:
- `event <-> staff`,
- `event <-> vehicles`,
- `event <-> locations`,
- `event <-> students` (jeżeli typ wydarzenia tego wymaga).

Relacja z kursantem jest wymagana biznesowo dla jazd, ale sam lewy panel tego ekranu nie pokazuje kursantów jako głównego filtra zasobów.

---

## 7. Własny model typów wpisów

Na podstawie UI należy co najmniej rozdzielić:
- `general_event`,
- `driving_lesson`,
- `important_date`.

Nie należy kodować `important_date` jako zwykłego ręcznie dodanego wydarzenia, dopóki nie sprawdzimy źródła tych wpisów. Może to być projekcja terminów dokumentów lub inna automatyzacja.

---

## 8. Potwierdzone akcje

- `open_calendar`
- `toggle_event_type_general`
- `toggle_event_type_drive`
- `toggle_event_type_important_dates`
- `add_calendar_event`
- `toggle_staff_resource`
- `select_all_staff_resources`
- `add_staff_from_calendar`
- `toggle_vehicle_resource`
- `select_all_vehicle_resources`
- `add_vehicle_from_calendar`
- `toggle_location_resource`
- `select_all_location_resources`
- `add_location_from_calendar`
- `calendar_previous_period`
- `calendar_next_period`
- `calendar_today`
- `calendar_view_month`
- `calendar_view_week`
- `calendar_view_day`

---

## 9. Wymagania dla naszego produktu

- jeden centralny model wydarzeń i rezerwacji,
- zasoby tenant-scoped,
- event może wiązać wiele typów zasobów,
- filtrowanie wielu zasobów jednocześnie,
- server-side conflict detection,
- blokada nakładania jazd tego samego instruktora lub pojazdu według reguł biznesowych,
- strefa czasowa organizacji,
- audyt zmian wydarzeń,
- bezpieczne przenoszenie/anulowanie wydarzeń,
- projekt gotowy na self-booking kursanta bez przebudowy modelu.

---

## 10. Pozostałe niewiadome

Najważniejsze do dalszego audytu:
- formularz `Dodaj wydarzenie`,
- czy `Jazda` ma osobny formularz lub pola warunkowe,
- dokładna semantyka `Ważne daty`,
- sposób wyboru kursanta,
- przypisywanie wielu pracowników/pojazdów/lokalizacji,
- godzina początku/końca i czas trwania,
- powtarzalność,
- statusy wydarzeń i jazd,
- anulowanie/przenoszenie,
- konflikty zasobów,
- notyfikacje,
- drag & drop,
- event detail/edit drawer,
- self-booking,
- rejestracja czasu pracy pracownika,
- znaczenie wskaźnika `!`,
- kolory i legenda wpisów,
- permissions.
