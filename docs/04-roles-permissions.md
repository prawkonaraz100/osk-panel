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

### 2.1. Template nie jest runtime źródłem prawa

Role template jest wyłącznie:
- presetem podczas nadawania dostępu,
- provenance dla UI/audytu.

Backend w czasie requestu nie autoryzuje na podstawie `role_template_code`.

Przy zastosowaniu template materializujemy bieżące decyzje do `membership_permissions`. Zmiana definicji template w przyszłości nie zmienia istniejących membershipów automatycznie.

Nowy permission dodany po utworzeniu membership jest domyślnie `DENY`, dopóki nie zostanie jawnie nadany albo template nie zostanie jawnie ponownie zastosowany.

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

## 6. Data scope — canonical model

### 6.1. Scope jest przypisany do permission, nie do całego membership

Stare uproszczenie:

`OrganizationMembership.data_scope`

jest **SUPERSEDED** i nie może być użyte jako runtime authorization shortcut.

Canonical model:

`OrganizationMembership -> membership_permissions -> membership_permission_scopes`

Każde `granted=true` musi mieć co najmniej jeden legalny scope. Brak scope dla przyznanego permission = **DENY fail-closed**.

### 6.2. Canonical scope codes

- `organization` — cały zasób danej organizacji po wcześniejszej walidacji tenantu,
- `own` — tylko zasób należący do bieżącego `User` albo `StaffProfile` aktywnie połączonego z membership,
- `assigned_students` — tylko zasoby rozwiązywane do kursantów przypisanych temu pracownikowi,
- `assigned_locations` — tylko zasoby rozwiązywane do lokalizacji przypisanych temu pracownikowi.

Scope nigdy nie rozszerza permission. Najpierw musi istnieć przyznana capability, dopiero potem scope ogranicza rekordy/komendę.

### 6.3. Fizyczne tabele scope

`data_scopes`
- katalog dozwolonych scope codes.

`permission_scope_options`
- whitelist legalnych par `permission_code + scope_code`,
- zawiera `resolver_code`.

`membership_permission_scopes`
- current scope rows dla konkretnego `membership_id + permission_code`,
- wiele rekordów dla jednego permission jest dozwolone,
- wiele scope'ów łączy się przez **OR**, ale dopiero po walidacji tego samego tenant.

### 6.4. `organization`

Nie znaczy „globalnie”. Warunek `target.organization_id == active_membership.organization_id` musi być sprawdzony przed scope resolverem.

### 6.5. `own`

Permission-specific resolver musi udowodnić relację do bieżącego `User` albo `StaffProfile`. Jeśli domena nie ma canonical ownership relation, `own` zwraca false.

### 6.6. `assigned_locations`

Canonical ścieżka:

`OrganizationMembership -> active StaffMembershipLink -> StaffProfile -> StaffLocationAssignments`

Brak aktywnego staff linku lub pasującej lokalizacji = DENY / pusty zbiór.

### 6.7. `assigned_students`

Canonical ścieżka zaczyna się od:

`OrganizationMembership -> active StaffMembershipLink -> StaffProfile`

Kursant jest przypisany, gdy istnieje scope-eligible `CourseEnrollment` z pasującym `lead_instructor_id` albo scope-eligible `TrainingSession` z pasującym `instructor_id`.

### 6.8. Create / update / list / export

Ten sam scope policy obowiązuje dla list, GET by id, search, count, update/cancel/archive, bulk, eksportów i PDF. Filtrowanie po pobraniu danych w Vue nie jest zabezpieczeniem.

### 6.9. Materializacja przez role template

Template zapisuje `membership_permissions` oraz scope rows dla granted permissions. Explicit grant bez jawnego, dozwolonego scope jest niedozwolony.

### 6.10. Owner governance i privilege escalation

`Owner` jako role template oraz **owner governance marker** to dwie różne rzeczy.

Canonical membership posiada `is_owner`. Jest to znacznik odpowiedzialności/governance, a nie skrót autoryzacyjny. Backend nadal sprawdza materializowane `membership_permissions` i `membership_permission_scopes`.

Membership z `is_owner=true` musi zachowywać jawny protected baseline:
- `organization.view` + `organization`,
- `organization.members.manage` + `organization`,
- `staff.permissions.manage` + `organization`,
- `sessions.manage.organization` + `organization`.

Nie wolno odebrać tych praw pozostawiając `is_owner=true`. Jeżeli mają zostać odebrane, demotion Ownera musi nastąpić w tej samej serializowanej transakcji.

Zmiany Ownera są serializowane per organizacja. Transakcja, która po commit zostawiłaby zero aktywnych Ownerów, jest odrzucana. Transfer Ownera jest jednym atomowym flow: najpierw następca otrzymuje owner marker + protected baseline, a poprzednik jest degradowany w tej samej transakcji.

Normalny panel administracyjny nie pozwala na self-escalation:
- nie można samemu nadać sobie nowego permission,
- nie można rozszerzyć własnego scope,
- nie można samemu ustawić `is_owner=true`,
- nie można zastosować sobie template'u, jeśli zwiększyłby privileges.

Self-restriction jest dozwolone tylko, jeśli nie łamie protected owner baseline ani last-owner invariant.

Grant ceiling:
- administrator nie może nadać permission, którego sam aktualnie nie posiada w tym OSK,
- nie może delegować scope szerszego niż własny scope dla tego samego permission,
- `organization` może delegować dozwolony węższy scope; scope nieporównywalny lub nieznany -> DENY,
- grant/broadening elevated permission wymaga aktywnego Ownera jako aktora.

Każda zmiana permission/scope/owner zwiększa `organization_memberships.authorization_version`. Runtime nie przechowuje trwałego snapshotu permissions w sesji. Jeżeli używany jest cache, musi być związany z bieżącym `authorization_version`.

Dzięki temu odebranie elevated permission jest skuteczne najpóźniej przy następnym autoryzowanym request. Sesja może nadal być zalogowana, ale nie zachowuje starego prawa. Pełny suspend/revoke/session invalidation lifecycle zamyka osobno `DB-IAM-005`.

## 7. Backend policy order

Kolejność:

1. authenticated user,
2. active organization membership,
3. tenant ownership / relation validation,
4. authoritative `membership_permissions` decision,
5. `membership_permission_scopes` + permission-specific resolver,
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
- old/new scope set,
- role template jeśli użyto,
- owner marker before/after jeśli dotyczy,
- reason opcjonalnie/wymagane dla elevated changes,
- request_id,
- timestamp.

Szczegółowy model historii/version/concurrency zamykamy w `DB-IAM-005`.

## 10. Testy obowiązkowe

Dla każdej grupy permissions:
- allow test,
- deny test,
- cross-tenant deny,
- scope deny,
- granted permission bez scope -> deny,
- unsupported permission/scope pair -> reject,
- list i GET-by-id dają ten sam zakres danych,
- `own` bez ownership relation -> deny,
- `assigned_students` bez staff link -> pusty zbiór,
- `assigned_locations` bez staff link -> pusty zbiór,
- wiele scope rows dla jednego permission działa jako OR,
- normal admin self-escalation jest blokowane,
- grant ponad własny permission/scope ceiling jest blokowany,
- nie da się pozostawić organizacji bez aktywnego Ownera,
- concurrent owner demotions nie mogą oba zakończyć się powodzeniem,
- stale authorization cache nie przeżywa zmiany `authorization_version`,
- revoke elevated permission obowiązuje najpóźniej przy następnym autoryzowanym request.
