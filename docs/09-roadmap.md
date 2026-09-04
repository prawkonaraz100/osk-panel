# 09. Roadmap wdrożenia

## Etap 0 — fundament
- tenant + auth,
- RBAC,
- organization profile,
- audit log,
- notifications,
- wspólny design system.

## Etap 1 — operacyjne OSK
- kursanci,
- pracownicy,
- pojazdy,
- kalendarz,
- przypomnienia.

## Etap 2 — szkolenie
- kursy,
- wykłady,
- postępy,
- widok kursanta.

## Etap 3 — licencje i sprzedaż
- inventory licencji,
- przypisania,
- płatności,
- historia,
- faktury.

## Etap 4 — egzamin wewnętrzny
- inventory egzaminów,
- generowanie,
- link/stacjonarny,
- karta przebiegu,
- historia.

## Etap 5 — PKK
- sandbox/fake provider,
- adapter,
- log operacji,
- retry/idempotency,
- dopiero potem rzeczywista integracja po formalnym dostępie.

## Etap 6 — profil/ranking/reklamy
- publiczna wizytówka,
- opinie,
- reklamy,
- kampanie,
- rejonizacja.

## Definition of Done modułu

Każdy moduł przed uznaniem za gotowy posiada:
1. wymagania i acceptance criteria,
2. policy tests,
3. tenant isolation tests,
4. audit coverage,
5. error/empty/loading states,
6. mobile QA,
7. dokumentację API,
8. testy krytycznych flow E2E.
