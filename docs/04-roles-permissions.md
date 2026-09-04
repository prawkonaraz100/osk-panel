# 04. Role i uprawnienia

## Proponowany RBAC

| Funkcja | Owner | Admin biura | Instruktor | Wykładowca | Księgowość | Kursant |
|---|---:|---:|---:|---:|---:|---:|
| Dane OSK | RW | R | - | - | R | - |
| Kursanci | RW | RW | R* | R* | - | self |
| PKK | RW | RW | R* | - | - | - |
| Kalendarz | RW | RW | RW* | R* | - | R/self-book* |
| Pracownicy | RW | R | self | self | - | - |
| Pojazdy | RW | RW | R | - | - | - |
| Licencje | RW | RW | R | - | R | self status |
| Postępy | RW | RW | R* | R* | - | self |
| Egzaminy | RW | RW | RW* | R* | R payments | self attempt |
| Wykłady | RW | RW | R | RW | - | R assigned |
| Płatności | RW | R | - | - | RW | - |
| Faktury | RW | R | - | - | RW | - |
| Profil/ranking | RW | RW | - | - | - | public |
| Reklamy | RW | RW | - | - | R | - |
| Audyt | R | R limited | self actions | self actions | finance | - |

`*` — tylko zakres przypisanych kursantów/zasobów.

## Zasady

- polityki uprawnień po stronie backendu, nie tylko UI,
- tenant isolation po `organization_id`,
- Owner nie może przypadkowo usunąć ostatniego aktywnego Ownera,
- operacje finansowe, PKK i zmiany ról wymagają podwyższonego poziomu audytu,
- impersonacja kursanta ma osobne uprawnienie.
