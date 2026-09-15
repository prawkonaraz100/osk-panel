# 91. Documentation authority matrix

Data: 2026-09-05

Cel: jednoznacznie wskazać developerowi, który dokument wygrywa przy konflikcie **bez utraty potwierdzonego reverse-engineered scope'u**.

| Typ decyzji | Źródło nadrzędne | Przykład |
|---|---|---|
| Prawo/formal requirements | `specs/legal/*.yml` | czy teoria jest wymagana |
| Własny lifecycle | `specs/design/*.yml` | exam consume-on-start |
| Zachowanie zakresu RE | `docs/96` + `specs/reverse-engineering-manifest.yml` | nie zgubić pól/filtrów/flow |
| Zweryfikowany ekran/flow | `specs/screens/*.yml` + `docs/17-80` | pola formularza, opcje, akcje |
| RBAC | `specs/security/*.yml` | permission names |
| API conventions | `specs/api/common-contract.yml` | errors, money, idempotency |
| Wymagana kompletność API | `specs/api/required-operations-v1.yml` | każde potwierdzone flow ma capability |
| API paths/schemas | `specs/api/openapi-v1.yaml` + `docs/06` | course-first PKK |
| Physical DB blueprint | `specs/database/core-schema.yml` | relacje, partial unique, constraints |
| Settings bounded-context DB detail | `specs/database/organization-settings.yml` | ownership pól `/ustawienia`, adres firmy, primary email, PKK settings |
| Narrative DB | `docs/87` | opis tabel; machine spec wygrywa przy rozjeździe |
| Settings narrative model | `docs/100-osk-settings-domain-model.md` | transakcja ustawień, ownership i concurrency |
| Gotowość modułu | `docs/71` + implementation baseline | READY_FOR_IMPLEMENTATION |
| Canonical naming | `docs/82` | CourseEnrollment |
| Cross-module summary | `specs/implementation-baseline-v1.yml` | core invariants |
| Historyczny audyt/public evidence | starsze docs/source evidence | evidence/context; confidence zachowany |

## Dwie różne osie pierwszeństwa

Nie wolno mylić:

### 1. Scope/capability
Na pytanie **„co ma istnieć jako funkcja?”** odpowiada przede wszystkim potwierdzony screen evidence i preservation manifest.

Jeżeli użytkownik zaobserwował filtr, sortowanie, PDF, drugi entry point albo osobną akcję, krótszy API/DB aggregate nie może tej funkcji usunąć. W takim przypadku to API/DB jest niekompletne.

### 2. Semantyka implementacji
Na pytanie **„jak to bezpiecznie i poprawnie zbudować?”** odpowiadają legal/design/security/domain/API/DB contracts.

Możemy więc zachować widoczną zdolność biznesową, ale wdrożyć ją inaczej wewnętrznie.

## Zasada bounded-context detail

Jeżeli istnieje dedykowany machine-readable spec dla konkretnego bounded contextu, np. `specs/database/organization-settings.yml`, to:
- rozszerza on ogólny `core-schema.yml`,
- wygrywa w szczegółach tego bounded contextu,
- nie może usuwać żadnej capability z reverse-engineering manifest,
- przed migracjami jego decyzje muszą zostać przeniesione do finalnego physical schema/migrations.

To nie jest obejście niespójności. To kontrolowany sposób etapowego projektowania bez przepisywania całego dużego blueprintu po każdej małej decyzji.

## Conflict examples

### Stary dokument mówi `/students/{id}/pkk`
Wygrywa current API/domain contract:
`/course-enrollments/{id}/pkk`.

Zdolność pobrania/zwrotu/historii PKK nie znika — zmienia się właściciel domenowy i endpoint.

### Stary dokument mówi exam consumed at finish
Wygrywa `specs/design/internal-exam-lifecycle.yml`: consume at start.

Wszystkie zaobserwowane entry points, historia prób, result review i PDF nadal muszą istnieć.

### Screen pokazuje ręczne pole godzin
Formalny source of truth nadal wynika z legal/domain model: current OSK hours są projection z ledgeru.

Ale pola godzin zaobserwowane w create/edit course **nie mogą zostać skasowane z UX bez jawnego mapowania**. Muszą działać jako projection/import/correction context zgodnie z manifestem.

### Konkurent ma przycisk `Usuń`
Nie oznacza to automatycznie hard-delete w naszym backendzie. Wygrywa core lifecycle policy.

Operator nadal otrzymuje równoważną akcję zakończenia/anulowania, a historia pozostaje zachowana.

### OpenAPI nie ma jeszcze endpointu dla potwierdzonej akcji
Nie wolno uznać akcji za zbędną. `specs/api/required-operations-v1.yml` wskazuje brak pokrycia i OpenAPI trzeba rozszerzyć.

### DB ma globalne UNIQUE, które blokuje historyczny flow
Historyczne lifecycle ma pierwszeństwo. Przykład: cofnięta nieaktywna licencja zwraca inventory do puli, więc jedna sztuka może mieć wiele historycznych assignmentów. Constraint ma blokować tylko **dwa bieżące przypisania**, nie całą historię.

### Ekran Ustawienia łączy dane użytkownika, firmy i PKK
Nie tworzymy jednego JSON-a ani duplikatów. `specs/database/organization-settings.yml` wskazuje canonical ownership:
- imię/nazwisko -> `users`,
- email -> primary `auth_login_identifiers`,
- firma/telefon -> `organizations`,
- adres firmy -> `organization_contact_addresses`,
- dane PKK -> `pkk_integration_settings`,
- regulamin -> `terms_acceptances + legal_documents`.

UI nadal ma jeden formularz i jeden `Zapisz`; backend realizuje to atomową transakcją agregatu.

## Zasada końcowa

Screen evidence odpowiada na pytanie **co użytkownik widział i mógł zrobić**. Legal/design/domain contracts odpowiadają na pytanie **jak bezpiecznie i poprawnie implementujemy to u siebie**.

Żaden refactor dokumentacji nie może po cichu zmienić pierwszej odpowiedzi przez skrócenie aggregate.
