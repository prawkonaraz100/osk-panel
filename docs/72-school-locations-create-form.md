# 72. Moja szkoła — Lokalizacje — formularz `Dodaj Lokalizacje`

Data weryfikacji: 2026-09-05

**Kontekst:** `Moja szkoła -> Lokalizacje -> Dodaj lokalizacje`  
**Źródło:** bieżący zalogowany drawer przekazany podczas audytu  
**Status:** `USER_CONFIRMED_AUTH_SCREEN`

## 1. Drawer

Tytuł: `Dodaj Lokalizacje`.

Akcje:
- `Zapisz`,
- `Anuluj`,
- zamknięcie `X`.

## 2. Pola formularza

Wszystkie zaobserwowane pola są oznaczone jako wymagane `*`:
- `Rodzaj`,
- `Nazwa`,
- `Ulica i nr`,
- `Kod pocztowy`,
- `Miejscowość`.

## 3. `Rodzaj`

Potwierdzone opcje selecta:
- `Filia`,
- `Sala wykładowa`,
- `Plac manewrowy`.

Nie dopisujemy innych typów bez kolejnego dowodu z UI.

## 4. `Miejscowość`

Pole nie jest zwykłym free-text inputem. Zaobserwowano wyszukiwanie w katalogu miejscowości i listę wyników zawierającą:
- nazwę miejscowości,
- województwo.

Przykładowe wyniki widoczne podczas audytu obejmowały m.in. Kamień Krajeński, Krajenkę, Kraków, Kramsk, Krapkowice i Krasną wraz z województwami.

Wniosek dla własnego produktu:
- miejscowość powinna być wybierana z katalogu/rejestru miejscowości,
- zapisujemy stabilny identyfikator miejscowości + snapshot nazwy/województwa,
- wyszukiwanie powinno tolerować wpisanie fragmentu nazwy,
- nie należy polegać wyłącznie na niezweryfikowanym wolnym tekście.

## 5. Model danych

Minimalny rekord `school_location`:
- `id`,
- `organization_id`,
- `type` = `branch | lecture_room | maneuvering_area`,
- `name`,
- `street_and_number`,
- `postal_code`,
- `locality_id` nullable zależnie od źródła katalogu,
- `locality_name_snapshot`,
- `voivodeship_snapshot`,
- `status`,
- `created_at`,
- `updated_at`.

Relacje z innych już zmapowanych ekranów:
- lokalizacja może pojawiać się w kalendarzu,
- lokalizacja może być wybierana przy pracowniku,
- lokalizacja może być wybierana przy pojeździe,
- lokalizacja może być przypisana kursantowi/kursowi.

## 6. Walidacje własnego produktu

Rekomendowane:
- `type` wymagany i ze słownika,
- `name` wymagane,
- `street_and_number` wymagane,
- `postal_code` walidowany w formacie PL,
- `locality` wymagane,
- tenant scope po `organization_id`,
- ostrzeżenie o potencjalnym duplikacie tej samej lokalizacji w jednej organizacji.

Nie zakładamy jeszcze dokładnego zachowania walidacji konkurenta, bo nie zostały wywołane błędy formularza.

## 7. Potwierdzone akcje

- `open_add_school_location_drawer`
- `select_school_location_type`
- `enter_school_location_name`
- `enter_school_location_street_and_number`
- `enter_school_location_postal_code`
- `search_school_location_locality`
- `select_school_location_locality_from_catalog`
- `save_school_location`
- `cancel_school_location_creation`

## 8. Pozostałe niewiadome

- formularz `Edytuj lokalizację`,
- czy edycja ma identyczne pola,
- akcja `Usuń` lub `Archiwizuj`,
- potwierdzenie usunięcia,
- co dzieje się z powiązanymi wydarzeniami/kursami/pracownikami/pojazdami przy usunięciu,
- exact walidacje i komunikaty błędów,
- możliwość zmiany rodzaju istniejącej lokalizacji,
- exact źródło katalogu miejscowości.
