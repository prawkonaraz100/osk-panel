# 22. Pracownicy — formularz `Dodaj pracownika`

Data weryfikacji: 2026-09-05

**Źródło:** bieżący zalogowany formularz przekazany podczas audytu  
**Status:** `USER_CONFIRMED_AUTH_SCREEN`

Ten dokument uzupełnia `docs/21-staff-screen.md` o dokładny formularz tworzenia pracownika.

## 1. Potwierdzone pola formularza

W formularzu `Dodaj pracownika` występują:

1. `E-mail`
2. `Imię`
3. `Nazwisko`
4. `Rodzaj pracownika`
5. `Pesel`
6. `Telefon`
7. `Numer uprawnień`
8. `Kategorie`
9. `Lokalizacje`
10. `Ważność legitymacji`
11. `Ważność badań lekarskich`
12. `Ważność badań psychologicznych`
13. `Dodaj zdjęcie (opcjonalnie)` / wybór pliku
14. `Utwórz konto do logowania`

Potwierdzone akcje końcowe:
- `Zapisz`
- `Anuluj`

## 2. Pola wielokrotnego wyboru

### `Rodzaj pracownika`
UI zawiera opis:
`Wybierz dowolną ilość`.

Wniosek: jeden pracownik może mieć więcej niż jeden rodzaj/funkcję. Nie modelujemy tego jako pojedynczego enum `staff.type`.

Rekomendowany model:
- `staff_types`
- `staff_staff_type` / `staff_type_assignments`

Dokładna lista dostępnych typów pozostaje do zebrania.

### `Kategorie`
UI zawiera opis:
`Wybierz dowolną ilość kategorii`.

Potwierdza relację many-to-many:
- `staff <-> driving_categories`.

### `Lokalizacje`
UI zawiera opis:
`Wybierz dowolną ilość lokalizacji`.

Potwierdza relację many-to-many:
- `staff <-> locations`.

To zamyka wcześniejszą niewiadomą z listy pracowników: pracownik może być przypisany do wielu lokalizacji.

## 3. Dane osobowe i kontaktowe

Potwierdzone pola:
- email,
- first_name,
- last_name,
- pesel,
- phone.

### PESEL
PESEL jest danymi osobowymi wymagającymi podwyższonej ochrony. W naszym systemie:
- dostęp tylko dla uprawnionych ról,
- brak ekspozycji w listach i logach technicznych,
- maskowanie tam, gdzie pełna wartość nie jest potrzebna,
- audyt odczytu/zmiany, jeśli wymaga tego polityka bezpieczeństwa,
- walidacja formatu i sumy kontrolnej jako decyzja implementacyjna naszego systemu.

Dokładna walidacja konkurencyjnego formularza pozostaje do sprawdzenia.

## 4. Uprawnienia zawodowe

Potwierdzone pole:
- `Numer uprawnień`.

Nie zakładamy jeszcze, że pole jest wymagane dla każdego rodzaju pracownika. Powinno być możliwe warunkowe wymaganie w zależności od wybranych rodzajów pracownika.

`Numer uprawnień` nie jest tym samym co termin ważności `Legitymacji`; modelujemy je osobno.

## 5. Dokumenty i terminy

Potwierdzone osobne pola dat:
- `Ważność legitymacji`,
- `Ważność badań lekarskich`,
- `Ważność badań psychologicznych`.

To potwierdza typy dokumentów/terminów z listy pracowników i ich niezależny lifecycle.

Rekomendowane mapowanie:
- `staff_documents(type=card_or_license, valid_until=...)`
- `staff_documents(type=medical_exam, valid_until=...)`
- `staff_documents(type=psychological_exam, valid_until=...)`

Nie przechowujemy tych trzech dat jako przypadkowych kolumn na `staff_profiles`, jeśli system ma być rozszerzalny o kolejne dokumenty.

## 6. Zdjęcie

Etykieta jednoznacznie wskazuje:
- zdjęcie jest opcjonalne,
- formularz zawiera file picker `Wybierz zdjęcie...`.

W naszym systemie:
- upload do object storage,
- walidacja MIME/rozmiaru/bezpiecznego dekodowania,
- `photo_asset_id` nullable,
- zapis pracownika nie może wymagać zdjęcia.

Dokładne limity pliku w 360 pozostają `TO_VERIFY_AUTH`.

## 7. `Utwórz konto do logowania`

Jest to jawna opcja formularza tworzenia pracownika.

Potwierdza:
- utworzenie `staff_profile` i utworzenie konta aplikacyjnego są dwoma różnymi skutkami,
- administrator może utworzyć pracownika bez konta,
- opcja w formularzu steruje dodatkowym provisioningiem konta do logowania.

Rekomendowany proces domenowy:

`create_staff_profile(payload, create_login_account=false)`

Jeśli `false`:
- zapisujemy profil pracownika,
- nie powstaje `user`/membership do panelu.

Jeśli `true`:
- profil pracownika powstaje,
- system inicjuje proces utworzenia/powiązania konta użytkownika,
- polityka hasła, zaproszenia, aktywacji i roli wymaga dalszej weryfikacji.

Nie zakładamy jeszcze, czy konkurencyjny system wysyła e-mail aktywacyjny, generuje hasło, czy tworzy konto natychmiast.

## 8. Model danych

### `staff_profiles`
Potwierdzone lub bezpośrednio wynikające z formularza:
- `id` UUID
- `organization_id`
- `email`
- `first_name`
- `last_name`
- `pesel`
- `phone`
- `authorization_number`
- `photo_asset_id` nullable
- `user_id` nullable / membership relation
- timestamps

### Many-to-many
- `staff_type_assignments(staff_id, staff_type_id)`
- `staff_category_assignments(staff_id, category_id)`
- `staff_location_assignments(staff_id, location_id)`

### Dokumenty
- `staff_documents(staff_id, type, valid_until, ...)`

## 9. Zależności formularza

Formularz wymaga danych słownikowych:
- rodzaje pracownika,
- kategorie prawa jazdy,
- lokalizacje należące do bieżącego OSK.

Każdy przesłany `location_id`, `category_id` oraz `staff_type_id` musi być walidowany server-side. Dla lokalizacji dodatkowo wymagane jest sprawdzenie `organization_id`, aby uniemożliwić przypisanie pracownika do lokalizacji innego tenanta.

## 10. Potwierdzone akcje

### `Zapisz`
Tworzy pracownika z aktualnie wybranymi relacjami i danymi.

Dokładne komunikaty sukces/błąd oraz zachowanie po zapisie pozostają do zweryfikowania.

### `Anuluj`
Rezygnuje z tworzenia. Dokładny route powrotu i zachowanie dla niezapisanych zmian pozostają do zweryfikowania.

## 11. Czego formularz nadal nie potwierdza

- które pola są wymagane,
- dokładna lista `Rodzaj pracownika`,
- walidacja email/PESEL/telefonu/numeru uprawnień,
- minimalne/maksymalne daty,
- zależności wymaganych pól od rodzaju pracownika,
- czy konto do logowania wymaga emaila,
- sposób tworzenia hasła lub zaproszenia,
- domyślne role/permissions dla utworzonego konta,
- limity zdjęcia,
- komunikaty błędów,
- route formularza create,
- czy `Anuluj` ostrzega o niezapisanych zmianach.

## 12. Acceptance criteria

### AC-STF-CREATE-01 — wiele rodzajów
Administrator może przypisać jednemu pracownikowi wiele rodzajów pracownika.

### AC-STF-CREATE-02 — wiele kategorii
Administrator może przypisać jednemu pracownikowi wiele kategorii prawa jazdy.

### AC-STF-CREATE-03 — wiele lokalizacji
Administrator może przypisać jednego pracownika do wielu lokalizacji tego samego OSK.

### AC-STF-CREATE-04 — dokumenty
Trzy terminy: legitymacji, badań lekarskich i psychologicznych są zapisywane niezależnie i generują niezależne statusy.

### AC-STF-CREATE-05 — zdjęcie opcjonalne
Brak zdjęcia nie blokuje utworzenia pracownika.

### AC-STF-CREATE-06 — bez konta
Gdy `Utwórz konto do logowania` jest wyłączone, pracownik powstaje bez konta aplikacyjnego.

### AC-STF-CREATE-07 — z kontem
Gdy opcja jest włączona, system tworzy profil i uruchamia provisioning konta zgodnie z polityką bezpieczeństwa, bez duplikowania encji pracownika i użytkownika.

### AC-STF-CREATE-08 — izolacja lokalizacji
Nie można przez manipulację requestem przypisać pracownika do lokalizacji należącej do innego OSK.
