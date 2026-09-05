# 04. Role i uprawnienia — core OSK v1

Data konsolidacji: 2026-09-05

**Status:** `OWN_PRODUCT_DECISION`

> Publiczne materiały konkurenta potwierdzają konteksty właściciela, instruktora, wykładowcy, pracownika, biura i kadr, ale nie potwierdzają kompletnej technicznej macierzy RBAC. W naszym produkcie canonical source of truth jest permission-based RBAC.

## 1. Zasada nadrzędna

`staff_type != permission != role_template`

- `StaffProfile.staff_type` opisuje funkcję zawodową/organizacyjną pracownika,
- permissions decydują, co może zrobić backend,
- role template jest tylko wygodnym pakietem startowym permissions.

Frontend może ukrywać niedostępne akcje, ale **autoryzacja zawsze odbywa się na backendzie**.

## 2. Role templates własnego produktu

Startowe template'y:
- `Owner`,
- `OfficeAdmin`,
- `HR`,
- `Instructor`,
- `Lecturer`,
- `Accounting`.

To nie jest twierdzenie, że 360 ma identyczne techniczne role.

Owner może modyfikować permissions w granicach polityki bezpieczeństwa.

## 3. Permission groups

### Organization
- `organization.view`
- `organization.edit`
- `organization.settings.manage`
- `organization.integrations.manage`
- `organization.members.manage`
- `organization.audit.view`

### Students
- `students.view`
- `students.create`
- `students.edit`
- `students.archive`
- `students.restore`
- `students.progress.view`

### Learning access
- `student_access.view`
- `student_access.create`
- `student_access.manage_credentials`
- `student_access.reset_password`
- `student_access.download_credentials_pdf`

### Course enrollment / training
- `courses.view`
- `courses.create`
- `courses.edit`
- `courses.cancel`
- `courses.restore`
- `courses.stage.change`
- `training_sessions.view`
- `training_sessions.create`
- `training_sessions.edit`
- `training_sessions.cancel`
- `training_hours.correct`
- `external_training.recognize`
- `course_requirements.correct`

### PKK
- `pkk.view`
- `pkk.fetch`
- `pkk.training.update_and_return`
- `pkk.return.school`
- `pkk.return.authority`
- `pkk.return.expired`
- `pkk.retry`

### Calendar
- `calendar.view`
- `calendar.manage.own`
- `calendar.manage.organization`
- `calendar.publish_student_slots`
- `calendar.book_for_student`
- `worktime.view.own`
- `worktime.view.organization`
- `worktime.manage`

### Locations
- `locations.view`
- `locations.create`
- `locations.edit`
- `locations.archive`
- `locations.restore`

### Vehicles
- `vehicles.view`
- `vehicles.create`
- `vehicles.edit`
- `vehicles.archive`
- `vehicles.restore`

### Staff
- `staff.view`
- `staff.create`
- `staff.edit`
- `staff.archive`
- `staff.restore`
- `staff.accounts.manage`
- `staff.permissions.manage`

### Licenses
- `licenses.view`
- `licenses.purchase`
- `licenses.assign`
- `licenses.activate`
- `licenses.revoke_unactivated`
- `licenses.progress.view`
- `licenses.access_documents.download`

### Internal exams
- `exams.view`
- `exams.purchase`
- `exams.generate`
- `exams.access.send`
- `exams.start.local`
- `exams.results.view`
- `exams.documents.download`
- `exams.inventory.adjust`
- `exams.attempt.invalidate`

### Student finance
- `student_finance.view`
- `student_finance.create_charge`
- `student_finance.cancel_charge`
- `student_finance.record_payment`
- `student_finance.reverse_payment`

### Platform purchases
- `purchases.view`
- `purchases.create`
- `purchases.pay`

### Security / support
- `sessions.manage.own`
- `sessions.manage.organization`
- `impersonation.start` — opcjonalne, nie core requirement

## 4. Rekomendowane role templates

`RW` poniżej oznacza domyślny template, nie niezmienną rolę systemową.

| Obszar | Owner | OfficeAdmin | HR | Instructor | Lecturer | Accounting |
|---|---:|---:|---:|---:|---:|---:|
| Ustawienia OSK | RW | R/RW* | R | - | - | R |
| Kursanci | RW | RW | R* | R* | R* | - |
| Kursy/etapy | RW | RW | - | RW* | R* | - |
| PKK | RW | RW* | - | R/RW* | - | - |
| Kalendarz organizacji | RW | RW | R | own/RW* | own | - |
| Czas pracy | RW | R/RW* | RW | own | own | - |
| Lokalizacje | RW | RW | R | R | R | - |
| Pojazdy | RW | RW | R | R | - | - |
| Pracownicy | RW | R/RW* | RW | self | self | - |
| Licencje | RW | RW | - | R* | - | R payments |
| Egzaminy | RW | RW | - | RW* | R* | R payments |
| Płatności kursanta | RW | RW* | - | - | - | RW |
| Zakupy OSK | RW | R/RW* | - | - | - | RW |
| Audyt | R | R limited | R domain | self actions | self actions | finance |

`*` — zależne od zakresu delegowanego przez Ownera.

## 5. Wymagania bezpieczeństwa

- każda permission jest egzekwowana przez Policy/Gate po stronie backendu,
- tenant scope jest niezależny od permission,
- posiadanie permission nie daje dostępu do zasobu innej organizacji,
- Owner nie może usunąć/dezaktywować ostatniego aktywnego Ownera bez bezpiecznego transferu,
- zmiana permissions wymaga audytu,
- permissions wysokiego ryzyka mogą wymagać re-auth/MFA,
- `exams.inventory.adjust`, `training_hours.correct`, `student_finance.reverse_payment`, `staff.permissions.manage`, PKK return commands są elevated.

## 6. Scope danych

Niektóre permissions wymagają dodatkowego data scope:
- `own`,
- `assigned_students`,
- `assigned_locations`,
- `organization`.

Przykład:
`calendar.manage.own` nie pozwala automatycznie edytować wydarzeń innego instruktora.

## 7. Backend policy order

Przykładowa kolejność:

1. authenticated user,
2. active organization membership,
3. tenant ownership / relation validation,
4. permission,
5. data scope,
6. domain rule/state,
7. optional re-auth/MFA for high-risk action.

## 8. Staff login provisioning

`StaffProfile` może istnieć bez `User`.

Utworzenie konta:
- osobna akcja,
- nie nadaje automatycznie pełnych permissions,
- wymaga jawnego template/permission assignment,
- aktywne sesje są kontrolowane po zmianie permissions.

## 9. Audyt permissions

Dla każdej zmiany zapisujemy:
- actor,
- target staff/user,
- old permissions,
- new permissions,
- role template jeśli użyto,
- reason opcjonalnie/wymagane dla elevated changes,
- request_id,
- timestamp.

## 10. Testy obowiązkowe

Dla każdej grupy permissions:
- allow test,
- deny test,
- cross-tenant deny,
- scope deny (`own` vs `organization`),
- revoked permission takes effect immediately lub zgodnie z jasno udokumentowaną polityką cache/session.
