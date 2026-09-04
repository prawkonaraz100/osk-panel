# 03. Główne przepływy użytkownika

## Flow A — założenie OSK

```mermaid
flowchart TD
A[Rejestracja] --> B[Dane właściciela]
B --> C[Dane firmy]
C --> D[Akceptacja regulaminu]
D --> E[Utworzenie organizacji]
E --> F[Dashboard]
F --> G[Uzupełnij profil / pracowników / pojazdy]
```

## Flow B — nowy kursant + licencja

```mermaid
flowchart TD
A[Dodaj kursanta] --> B[Wprowadź dane]
B --> C{PKK?}
C -->|Tak| D[Pobierz i powiąż PKK]
C -->|Nie| E[Kontynuuj bez PKK]
D --> F[Przypisz kurs]
E --> F
F --> G{Licencja w puli?}
G -->|Tak| H[Przypisz licencję]
G -->|Nie| I[Zakup licencje]
I --> H
H --> J[Wyślij dostęp / utwórz login]
J --> K[Kursant aktywuje]
K --> L[Monitoring postępów]
```

## Flow C — cofnięcie nieaktywowanej licencji

1. OSK otwiera `Licencje > Zarządzaj`.
2. Filtruje status `przypisana / nieaktywna`.
3. Wybiera kursanta.
4. `Cofnij licencję`.
5. System potwierdza brak aktywacji.
6. Assignment zostaje anulowany.
7. Sztuka wraca do puli.
8. Operacja trafia do audytu.

## Flow D — egzamin wewnętrzny linkiem

1. OSK posiada dostępny egzamin w puli.
2. Wybiera kursanta i kategorię.
3. `Generuj egzamin`.
4. System tworzy jednorazowy token/link.
5. Link ma TTL i może być unieważniony przed startem.
6. Kursant uruchamia egzamin.
7. Po rozpoczęciu egzamin zostaje oznaczony `started`.
8. Po zakończeniu zapisujemy wynik i przebieg.
9. Egzamin staje się `consumed`.
10. Generowana jest karta przebiegu do PDF/drukowania.

## Flow E — egzamin stacjonarny

1. Sekretariat/instruktor wybiera kursanta.
2. `Rozpocznij egzamin stacjonarnie`.
3. System tworzy sesję na stanowisku egzaminacyjnym.
4. Po zakończeniu wynik jest zapisany.
5. Karta przebiegu dostępna cyfrowo i do druku.

## Flow F — jazda w kalendarzu

```mermaid
flowchart TD
A[Nowa jazda] --> B[Wybór kursanta]
B --> C[Wybór instruktora]
C --> D[Wybór pojazdu]
D --> E[Data i czas]
E --> F{Konflikt?}
F -->|Tak| G[Pokaż konflikty i alternatywy]
F -->|Nie| H[Zapisz]
H --> I[Powiadomienia]
I --> J[Potwierdzona]
J --> K[Odbyta / anulowana / no-show]
```

## Flow G — operacja PKK

Każda akcja zewnętrzna używa wzorca:

`request -> validation -> authorization -> audit_start -> external_call -> normalize_response -> persist -> audit_end -> notify`

Nie wolno wykonywać operacji PKK bez rekordu audytowego.

## Flow H — zakup

1. Użytkownik wybiera produkt (licencje / egzaminy / reklama).
2. Ustala ilość/wariant.
3. System liczy kwotę brutto/VAT.
4. Powstaje `Order`.
5. Użytkownik wybiera metodę płatności.
6. Dla operatora online czekamy na webhook.
7. Po potwierdzeniu powstaje `Payment` i odpowiedni inventory/entitlement.
8. Faktura/paragon zgodnie z konfiguracją księgową.
