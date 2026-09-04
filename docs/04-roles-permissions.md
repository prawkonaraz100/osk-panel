# 04. Role i uprawnienia

> Publiczne materiały potwierdzają konteksty organizacyjne, ale nie pełną techniczną macierz RBAC. Poniższa tabela jest projektem naszego systemu. Wiersze oznaczone `*` są szczególnie zależne od przypisanego zakresu danych.

## Role / profile dostępu potwierdzone kontekstowo

Publiczna oferta wskazuje lub opisuje co najmniej:
- właściciela,
- instruktora,
- wykładowcę,
- pracownika,
- biuro obsługi,
- kadrową / HR,
- kursanta.

Nie mamy publicznego dowodu, czy `biuro` i `kadrowa` są osobnymi sztywnymi rolami w bazie 360, czy konfigurowalnymi uprawnieniami. W naszym systemie rekomendowane jest permission-based RBAC.

`Księgowość` poniżej jest **rolą projektową**, nie publicznie potwierdzoną rolą badanego panelu.

## Proponowany RBAC PrawkoNaRaz

| Funkcja | Owner | Admin biura | HR/Kadry | Instruktor | Wykładowca | Księgowość* | Kursant |
|---|---:|---:|---:|---:|---:|---:|---:|
| Dane OSK | RW | R/RW* | R | - | - | R | - |
| Kursanci | RW | RW | R* | R* | R* | - | self |
| PKK | RW | RW* | - | R/RW* | - | - | - |
| Kalendarz | RW | RW | R | RW* | R* | - | R/self-book* |
| Ewidencja czasu pracy | RW | R/RW* | RW | self | self | - | - |
| Pracownicy | RW | R/RW* | RW | self | self | - | - |
| Pojazdy | RW | RW | R | R | - | - | - |
| Licencje | RW | RW | R | R* | - | R payments | self status |
| Postępy | RW | RW | - | R* | R* | - | self |
| Egzaminy | RW | RW | - | RW* | R* | R payments | self attempt |
| Wykłady | RW | RW | - | R | RW | - | R assigned |
| Płatności | RW | R* | - | - | - | RW | - |
| Reklamy | RW | RW* | - | - | - | R payments | - |
| Profil/ranking | RW | RW* | - | - | - | - | public |
| Audyt | R | R limited | self/domain | self actions | self actions | finance | - |
| Impersonacja | explicit | explicit* | - | explicit* | - | - | - |

`*` — zakres jest decyzją konfiguracyjną własnego produktu i musi zostać zweryfikowany przed wdrożeniem jako odpowiednik aktualnego panelu 360.

## Permission groups

Zamiast wiązać logikę wyłącznie z nazwą roli, projektować permissions:

### Organization
- `organization.view`
- `organization.edit`
- `organization.members.manage`
- `organization.profile.manage`

### Students / learning
- `students.view`
- `students.create`
- `students.edit`
- `students.archive`
- `students.progress.view`
- `students.impersonate`

### PKK
- `pkk.view`
- `pkk.fetch`
- `pkk.training.update`
- `pkk.return.school`
- `pkk.return.authority`
- `pkk.return.expired`

### Calendar / work time
- `calendar.view`
- `calendar.manage.own`
- `calendar.manage.organization`
- `calendar.publish_student_slots`
- `worktime.view.own`
- `worktime.view.organization`
- `worktime.manage`

### Licenses / exams
- `licenses.purchase`
- `licenses.assign`
- `licenses.revoke_unactivated`
- `licenses.progress.view`
- `exams.purchase`
- `exams.generate`
- `exams.start_local`
- `exams.results.view`

### Finance / ads
- `payments.view`
- `ads.bid`
- `ads.creative.manage`
- `ads.history.view`

## Zasady bezpieczeństwa RBAC

- egzekwowanie uprawnień po stronie backendu, nie tylko UI,
- tenant isolation po `organization_id`,
- Owner nie może przypadkowo usunąć ostatniego aktywnego Ownera,
- operacje PKK, finansowe, zmiany ról, cofnięcia licencji i impersonacja wymagają podwyższonego audytu,
- `students.impersonate` jest osobnym permission,
- role `biuro` i `HR` nie powinny automatycznie dziedziczyć dostępu finansowego,
- zasada jednej aktywnej sesji opisana w regulaminie dla opłaconego konta użytkownika nie powinna być automatycznie kopiowana na wszystkie konta personelu bez `TO_VERIFY_AUTH`.
