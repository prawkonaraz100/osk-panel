# ADR-0005 — Permission-based RBAC zamiast sztywnych ról

Status: Accepted  
Data: 2026-09-05

## Context

Materiały referencyjne pokazują konteksty Owner/Instruktor/Wykładowca/Biuro/Kadry, ale nie potwierdzają jednej zamkniętej technicznej macierzy ról. Własny produkt musi pozwalać OSK delegować uprawnienia bez mnożenia specjalnych ról.

## Decision

Backend używa permissions jako source of truth. Role są jedynie template'ami startowymi.

`staff_type != permission != role_template`.

Authorization order:
1. authenticated user,
2. active membership,
3. tenant validation,
4. permission,
5. data scope,
6. domain state/rule,
7. optional re-auth/MFA.

## Consequences

- zmiana rodzaju pracownika nie daje automatycznie nowych praw,
- OfficeAdmin/HR/Instructor mogą mieć różne scope,
- permission changes są audytowane,
- frontend nie jest security boundary.

## Rejected alternatives

- hardcoded `if role == owner` rozsiane po aplikacji,
- staff type jako bezpośredni mechanizm autoryzacji.
