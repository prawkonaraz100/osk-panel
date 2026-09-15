# 18. Konto OSK — Ustawienia konta

Data weryfikacji: 2026-09-05

**Route:** `/ustawienia`  
**Źródło:** bieżący zalogowany ekran przekazany podczas audytu  
**Status:** `USER_CONFIRMED_AUTH_SCREEN`

Ten dokument zastępuje wcześniejsze ogólne założenia dotyczące zawartości ekranu `Ustawienia`.

## 1. Cel ekranu

Ekran `Ustawienia konta` służy do edycji podstawowych danych użytkownika OSK, danych firmy oraz danych identyfikacyjnych używanych w obszarze integracji PKK. Udostępnia również link do wersjonowanego regulaminu przypisanego do konta.

Potwierdzona główna akcja:
- `Zapisz`.

Nie potwierdzono na tym ekranie osobnych zakładek ani osobnych przycisków zapisu dla poszczególnych sekcji.

## 2. Potwierdzone sekcje i pola

### A. Dane podstawowe

Pola:
1. `Imię`
2. `Nazwisko`
3. `Email`

Model domenowy:
- `user.first_name`
- `user.last_name`
- `user.email`

### B. Dane firmy

Pola:
1. `Nazwa firmy`
2. `Ulica`
3. `Nr domu`
4. `Nr lokalu`
5. `Miejscowość`
6. `Kod pocztowy`
7. `Telefon`

Model domenowy:
- `organization.legal_or_display_name`
- `organization.address.street`
- `organization.address.house_number`
- `organization.address.unit_number`
- `organization.address.city`
- `organization.address.postal_code`
- `organization.phone`

Nie potwierdzono na tym ekranie pola NIP, mimo że NIP występuje w procesie rejestracji. Nie należy zakładać, że NIP jest tutaj edytowalny.

### C. Dane API PKK

Pola:
1. `Nazwa szkoły`
2. `Numer ewidencyjny OSK`
3. `Login OSK`

Przy `Login OSK` ekran zawiera pomoc kontekstową wyjaśniającą, że jest to login użytkownika przekazany przez starostę / wydział komunikacji i że można go znaleźć na zaświadczeniu rejestracyjnym OSK.

Kluczowe rozróżnienie domenowe:

`Login OSK` **nie jest loginem do naszego panelu SaaS**. Jest identyfikatorem/parametrem integracyjnym związanym z dostępem OSK do systemu zewnętrznego PKK.

Dlatego w naszym modelu nie zapisujemy go w `users.login`. Powinien trafić do dedykowanej konfiguracji integracji, np.:

`pkk_integration_settings.external_osk_login`

Pozostałe pola:
- `pkk_integration_settings.school_name`
- `pkk_integration_settings.osk_registry_number`

Wartości integracyjne powinny być dostępne wyłącznie użytkownikom z odpowiednim permission, a ich zmiana powinna trafiać do audit logu.

## 3. Regulamin

Potwierdzona sekcja:
- `Regulamin`

Potwierdzona akcja:
- `Zobacz mój regulamin`

Zaobserwowany wzorzec trasy:

`/regulamin?v=<regulation_version>`

W obserwowanym ekranie parametr wersji wskazywał konkretną wersję regulaminu OSK. Do dokumentacji zapisujemy wzorzec, a nie przywiązujemy implementacji do jednego numeru wersji.

Wniosek dla naszego produktu:
- wersja zaakceptowanego regulaminu powinna być utrwalona przy koncie/organizacji,
- użytkownik powinien móc odtworzyć treść tej konkretnej wersji,
- aktualizacja regulaminu nie może nadpisywać historycznie zaakceptowanej treści,
- akceptacje powinny mieć co najmniej `version`, `accepted_at`, `actor_user_id` i snapshot/hash dokumentu.

## 4. Potwierdzona akcja `Zapisz`

Akcja domenowa:

`update_osk_account_settings(payload)`

Potwierdzony zakres danych ekranu:
- dane podstawowe użytkownika,
- dane firmy,
- dane identyfikacyjne integracji PKK.

Nie potwierdzono, czy ekran wysyła wszystkie sekcje jednym requestem czy kilka endpointów jest scalonych w UI. W naszym rozwiązaniu można użyć jednego formularza z transakcyjnym zapisem albo rozdzielić backend na bounded contexts przy zachowaniu jednego UX.

### Rekomendacja bezpieczeństwa

Zmiana danych PKK powinna mieć wyższy poziom audytu niż zwykła zmiana telefonu/adresu. Zalecane:
- zapisać `before/after`,
- aktora,
- timestamp,
- request id,
- nie eksponować niepotrzebnie wartości integracyjnych użytkownikom bez permission.

## 5. Czego NIE potwierdził ten ekran

Na przekazanym widoku nie występują jawnie:
- zmiana hasła,
- zarządzanie sesjami,
- MFA,
- ustawienia powiadomień,
- zgody marketingowe,
- dane rozliczeniowe/fakturowe,
- NIP,
- osobne ustawienia organizacyjne,
- zarządzanie rolami i pracownikami.

To nie znaczy, że funkcje te nie istnieją w systemie. Oznacza tylko, że nie należy przypisywać ich do `/ustawienia` bez kolejnego dowodu.

## 6. Walidacje — status

Dokładne reguły walidacji badanego systemu nadal nie są potwierdzone.

Dla naszego produktu rekomendujemy co najmniej:
- poprawny format e-mail,
- unikalność e-mail w zakresie wymaganym przez model kont,
- walidację polskiego kodu pocztowego przy adresie PL,
- normalizację telefonu,
- normalizację numeru ewidencyjnego OSK bez zgadywania jego formatu na frontendzie,
- `Nr lokalu` jako pole opcjonalne — decyzja projektowa, nie potwierdzenie zachowania 360.

## 7. Minimalny model danych

### `users`
- `id`
- `first_name`
- `last_name`
- `email`

### `organizations`
- `id`
- `name`
- `phone`

### `organization_addresses`
- `organization_id`
- `street`
- `house_number`
- `unit_number` nullable
- `city`
- `postal_code`

### `pkk_integration_settings`
- `organization_id`
- `school_name`
- `osk_registry_number`
- `external_osk_login`
- `updated_by`
- `updated_at`

### `terms_acceptances`
- `organization_id` / `user_id`
- `terms_type`
- `version`
- `accepted_at`
- `document_hash` lub snapshot reference

## 8. Historyczna rekomendacja API z audytu

- `GET /api/v1/account/settings`
- `PATCH /api/v1/account/profile`
- `PATCH /api/v1/organization/profile`
- `GET /api/v1/organization/pkk-settings`
- `PATCH /api/v1/organization/pkk-settings`
- `GET /api/v1/terms/accepted`
- `GET /api/v1/terms/{version}`

Powyższa lista była rekomendacją z etapu audytu, a nie bieżącym kontraktem runtime.

Aktualny provider-neutral kontrakt naszego produktu jest później rozstrzygnięty przez
`specs/design/organization-settings-provider-neutral.yml` i
`specs/api/paths/auth-organization.yaml`:

- `GET /api/v1/organization/settings`,
- `PATCH /api/v1/organization/settings`,
- `GET /api/v1/organization/accepted-terms`.

Dedykowane operacje PKK pozostają osobnym bounded contextem i są obecnie
`FROZEN_UNTIL_EXPLICIT_UNFREEZE`.

Frontend może nadal prezentować jeden ekran i jeden CTA `Zapisz`, ale provider-neutral zapis nie może odczytywać ani mutować PKK.

## 9. Acceptance criteria

### AC-SET-01 — odczyt
**Given** zalogowany uprawniony administrator OSK  
**When** otwiera `/ustawienia`  
**Then** widzi dane podstawowe, dane firmy, dane API PKK oraz sekcję regulaminu.

### AC-SET-02 — zapis danych konta
**Given** poprawnie zmienione dane podstawowe lub firmy  
**When** użytkownik wybierze `Zapisz`  
**Then** zmiany są utrwalone wyłącznie w jego organizacji.

### AC-SET-03 — separacja loginów
`Login OSK` z sekcji PKK nie może zmieniać loginu/e-maila używanego do logowania do aplikacji.

### AC-SET-04 — audyt PKK
Zmiana `Nazwa szkoły`, `Numer ewidencyjny OSK` lub `Login OSK` generuje wpis audytowy.

### AC-SET-05 — historyczny regulamin
Link `Zobacz mój regulamin` otwiera wersję przypisaną do użytkownika/organizacji, a nie automatycznie najnowszą wersję dokumentu.

### AC-SET-06 — tenant isolation
Administrator OSK A nie może odczytać ani zmienić ustawień firmy lub PKK należących do OSK B.

## 10. Nadrzędność decyzji implementacyjnych

Obserwacje w sekcjach 1–9 pozostają dowodem tego, co było widoczne na badanym
ekranie. Nie są przepisywane pod aktualny kod.

Późniejsza własna decyzja architektoniczna
`CORE-V1-ORGANIZATION-SETTINGS-PROVIDER-NEUTRAL-AUTHORITY-001`
(`specs/design/organization-settings-provider-neutral.yml`,
`docs/190-core-v1-organization-settings-provider-neutral-authority.md`)
kontroluje jednak semantykę naszej implementacji:

- zwykłe ustawienia muszą działać bez PKK,
- provider-neutral GET/PATCH nie może czytać ani mutować PKK,
- e-mail jest projekcją read-only do czasu osobnego identity email-change authority,
- PKK pozostaje opcjonalnym bounded contextem.

To jest świadome rozdzielenie pomiędzy obserwowanym ekranem źródłowym a
nadrzędną decyzją naszego produktu; nie oznacza usunięcia obserwacji PKK z historii.

## 11. Aktualny stan naszej implementacji — accepted

Gate: `ORGANIZATION-SETTINGS-UI-001`  
Status: `PASS_ACCEPTED`

Faktycznie zaakceptowany zakres:

- SPA route `/ustawienia`,
- komponent `resources/js/modules/OrganizationSettings/SettingsWorkspace.vue`,
- odczyt przez `GET /api/v1/organization/settings`,
- zapis przez `PATCH /api/v1/organization/settings`,
- optimistic concurrency przez `If-Match: "v<version>"`,
- idempotency key przez istniejący wspólny klient API,
- edycja imienia, nazwiska, nazwy firmy, adresu i telefonu,
- e-mail wyświetlany read-only,
- historia zaakceptowanych wersji regulaminu z projekcji settings,
- link do dokumentu tylko wtedy, gdy backend zwróci bezpieczny lokalny
  `document_url`,
- brak syntetyzowania trasy `/regulamin?v=...`,
- sekcja PKK wyświetla wyłącznie informację o zamrożonej opcjonalnej integracji;
  accepted implementation nie wywołuje dedykowanego API PKK i nie wysyła pól PKK,
- link `Ustawienia` został dodany do istniejących sidebarów bez przebudowy
  architektury routingu.

Candidate został zwalidowany na exact head `0e3466eccea19f7bb798a548a07e925dac6b6157`.

Validation evidence:
- Implementation CI `34969328994` / #669: **5/5 PASS**,
- PostgreSQL: **381 tests / 6315 assertions — PASS**,
- deterministic restore: **122 -> 122 PASS**,
- schema fingerprint: `f3cab286cc8f0aabef219971d90afe424a8dab694c47ade2f517a3d95970716d`.

Historia walidacji candidate pozostaje powyżej bez zmian.

Accepted runtime evidence:
- accepted SHA: `4006607dd2aecdc6fabb97a34ba5edf40d07729b`,
- PR #113: clean-promoted,
- Implementation CI `34971537563` / #675: **6/6 PASS**,
- PostgreSQL: **381 tests / 6315 assertions — PASS**,
- deterministic restore: **122 -> 122 PASS**,
- schema fingerprint: `f3cab286cc8f0aabef219971d90afe424a8dab694c47ade2f517a3d95970716d`,
- immutable artifact ID: `10396979267`,
- artifact name: `osk-panel-4006607dd2aecdc6fabb97a34ba5edf40d07729b`,
- release archive SHA-256: `c3ca6f4c99dd61099cfc2bb720a502e8808212384d1393a8e8accae17071b962`.

Ten gate jest zakończony dla provider-neutral UI. Nie zamyka odroczonych
funkcji PKK ani versioned document resolvera opisanych w sekcji 12.

## 12. Potwierdzone rozbieżności i pozostała praca

1. Obserwowany ekran zawiera edytowalne dane API PKK. Nasza accepted implementation ich nie
   odczytuje ani nie edytuje, ponieważ późniejsza decyzja provider-neutral i
   aktualny freeze PKK mają pierwszeństwo dla implementacji. Ta część pozostaje
   odroczona do jawnego unfreeze.
2. Obserwowany ekran ma akcję `Zobacz mój regulamin`. Obecny backend celowo
   zwraca `document_url: null` do czasu realnego versioned document resolvera
   (patrz `docs/175-core-v1-organization-accepted-terms-closure.md`).
   Accepted implementation pokazuje więc wersję i czas akceptacji oraz uczciwy stan
   niedostępności treści; nie tworzy fałszywego URL.
3. Niepotwierdzona requiredness pól ogólnego ekranu pozostaje niepotwierdzona.
   Accepted implementation nie oznacza tych obserwacyjnych unknowns jako rozstrzygnięte.
4. Browser E2E dla pełnego flow ustawień pozostaje osobnym zadaniem
   productization.
