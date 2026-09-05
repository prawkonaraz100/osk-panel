# 03. Główne przepływy użytkownika — core OSK v1

Data konsolidacji: 2026-09-05

> Szczegóły ekranowe są w `specs/screens/*.yml`. Ten dokument opisuje przepływy biznesowe naszego produktu. Canonical lifecycle egzaminu: `specs/design/internal-exam-lifecycle.yml`.

---

# Flow A — wejście do panelu

```mermaid
flowchart TD
A[Chroniony URL] --> B{Zalogowany?}
B -->|Tak| C[Autoryzacja tenant + permission]
B -->|Nie| D[Logowanie z bezpiecznym ReturnUrl]
D --> C
C -->|OK| E[Docelowy ekran]
C -->|Brak dostępu| F[403/404 wg polityki]
```

ReturnUrl przyjmuje wyłącznie lokalne/dozwolone ścieżki.

---

# Flow B — nowy pracownik

```mermaid
flowchart TD
A[Dodaj pracownika] --> B[StaffProfile]
B --> C{Potrzebuje loginu?}
C -->|Nie| D[Pracownik bez konta]
C -->|Tak| E[Utwórz User/Membership]
E --> F[Wybierz role template / permissions]
F --> G[Aktywny dostęp panelowy]
```

`staff_type` nie nadaje automatycznie permissions.

---

# Flow C — nowy kursant

```mermaid
flowchart TD
A[Dodaj kursanta] --> B[Student]
B --> C{Dodaj kurs od razu?}
C -->|Tak| D[CourseEnrollment]
C -->|Nie| E[Sam profil kursanta]
D --> F[Rule engine wymagań]
F --> G[PKK / zajęcia / egzamin zależnie od kursu]
```

Student może istnieć bez learning account i bez licencji.

---

# Flow D — dodanie formalnego kursu

1. Otwórz kursanta.
2. `Dodaj kurs`.
3. Wybierz rodzaj szkolenia i kategorię.
4. Podaj PKK, datę rozpoczęcia, instruktora i opcjonalną lokalizację.
5. Podaj/uznaj dane wejściowe dotyczące wcześniejszego szkolenia, jeżeli dotyczą.
6. Backend tworzy `CourseEnrollment`.
7. Rule engine zapisuje `TrainingRequirementProfile`.
8. Jeżeli podano koszt, StudentFinance może utworzyć powiązaną należność zgodnie z polityką produktu.

Pola godzin widoczne w audytowanym formularzu nie oznaczają, że ręczny agregat ma być source of truth w naszym systemie.

---

# Flow E — ewidencja zajęć i godzin

```mermaid
flowchart TD
A[Zaplanuj zajęcia] --> B[CalendarEvent / TrainingSession]
B --> C[Realizacja + attendance]
C --> D{Zaliczone formalnie?}
D -->|Tak| E[TrainingHourLedgerEntry]
D -->|Nie| F[Brak credit]
E --> G[Course totals projection]
```

Dla czasu uznanego z innego OSK:

`RecognizedExternalTraining -> course totals projection`

Teoria: 45 min / godzina szkoleniowa.  
Praktyka: 60 min / godzina szkoleniowa.

---

# Flow F — zmiana podstawy zwolnienia / wymagań

1. Użytkownik zmienia fakty wejściowe lub podstawę zwolnienia.
2. System zapisuje actor/reason/evidence.
3. Rule engine przelicza requirement profile.
4. Wykonane wcześniej zajęcia nie są niszczone.
5. Przyszłe wymagania są aktualizowane.
6. Closed course wymaga correction mode.

Nie ma dowolnego checkboxa „zwolnij z teorii” omijającego reguły.

---

# Flow G — PKK

Operacja zawsze w kontekście kursu:

`Student -> CourseEnrollment -> PKK`

```mermaid
flowchart TD
A[Otwórz kurs] --> B[PKK panel]
B --> C[Wybierz command]
C --> D[Authorize + validate + Idempotency]
D --> E[Zapis PkkOperation intent]
E --> F[Provider call]
F --> G{Rezultat}
G -->|Success| H[Persist normalized state + audit]
G -->|Retryable failure| I[Operation failed/retryable]
G -->|Business error| J[Operation failed/non-retryable]
I --> K[Kontrolowany retry -> new attempt]
```

Historia nie miesza operacji kilku kursów tego samego kursanta.

---

# Flow H — learning account + licencja

```mermaid
flowchart TD
A[Student] --> B{Learning account istnieje?}
B -->|Nie| C[Utwórz StudentLearningAccount]
B -->|Tak| D[Wybierz istniejący]
C --> E[Ustaw/generuj credentials]
E --> F[Opcjonalny AccessHandoff/PDF]
D --> G[Wybierz license product]
F --> G
G --> H{Inventory dostępne?}
H -->|Nie| I[Zakup/grant inventory]
H -->|Tak| J[Assign]
I --> J
J --> K[assigned / not activated]
K --> L{Aktywacja}
L -->|Tak| M[LicenseActivation -> active period]
L -->|Nie| N[Można revoke unactivated]
N --> O[Dokładnie 1 sztuka wraca do inventory]
```

Hasło jawne może być pokazane jednorazowo w handoff flow, ale nie jest później odzyskiwalne.

---

# Flow I — student finance

```mermaid
flowchart TD
A[StudentCharge] --> B[Saldo należności]
B --> C[Record StudentPayment]
C --> D{Pozostało > 0?}
D -->|Tak| E[partially_paid]
D -->|Nie| F[paid]
C --> G{Korekta wpłaty?}
G -->|Tak| H[Reverse payment]
H --> I[Nowy poprawny payment jeśli potrzebny]
```

Płatności kursanta nie są zakupami OSK na platformie.

---

# Flow J — kalendarz / jazda

1. Użytkownik otwiera kalendarz.
2. Tworzy `Wydarzenie` albo `Jazdę`.
3. Wybiera datę/czas/duration.
4. Opcjonalnie wskazuje kursanta, instruktora, pojazd, lokalizację/miejsce własne.
5. Backend waliduje tenant oraz konflikty zasobów.
6. Zapis jest audytowany.
7. Późniejszy move/cancel działa według własnego lifecycle.

Self-booking używa atomowo rezerwowanych `AvailabilitySlot`.

---

# Flow K — zakup licencji/egzaminów przez OSK

```mermaid
flowchart TD
A[Wybór produktu/ilości] --> B[Order + OrderItems]
B --> C[Payment]
C --> D{Potwierdzona?}
D -->|Nie| E[pending/failed]
D -->|Tak| F[Grant inventory/entitlement]
F --> G[Historia zakupów]
```

Cena/VAT są snapshotowane na `OrderItem`.

---

# Flow L — egzamin wewnętrzny remote

```mermaid
flowchart TD
A[CourseEnrollment] --> B{Rule engine: część wymagana?}
B -->|Nie| C[Brak generowania tej części]
B -->|Tak| D[Create InternalExamAttempt]
D --> E[Reserve 1 exam inventory]
E --> F[Create remote access/token]
F --> G[Wyślij/udostępnij link]
G --> H{Kursant startuje?}
H -->|Nie, access wygasa/cofnięty| I[Release reservation]
H -->|Tak| J[Atomowo consume inventory + attempt in_progress]
J --> K[Odpowiedzi]
K --> L[Submit/finish]
L --> M[Result + immutable snapshot + PDF]
```

**Inventory jest konsumowane przy start, nie przy finish.**

---

# Flow M — egzamin lokalny

1. Wybierz kursanta i `CourseEnrollment`.
2. Rule engine potwierdza wymaganą część.
3. Utwórz attempt/access i reservation.
4. Użytkownik uruchamia egzamin na stanowisku.
5. Start atomowo konsumuje inventory.
6. Kursant wykonuje egzamin.
7. Submit zapisuje wynik i snapshot.
8. PDF/dokument powstaje z historycznego snapshotu.

---

# Flow N — awaria egzaminu po starcie

1. Attempt jest już `in_progress`.
2. Występuje awaria techniczna.
3. Attempt -> `technical_abort`.
4. Inventory pozostaje consumed.
5. Jeżeli biznes decyduje o zwrocie sztuki, uprawniony użytkownik tworzy audytowaną compensating adjustment.

Brak automatycznego „oddania sztuki” po zamknięciu przeglądarki.

---

# Flow O — archiwizacja zasobu

Dla student/staff/location/vehicle:

1. sprawdź permission,
2. sprawdź aktywne zależności,
3. pokaż konsekwencje,
4. ustaw archived state,
5. zablokuj nowe przypisania,
6. zachowaj historię,
7. rozwiąż przyszłe wydarzenia jawnie,
8. audit.

Szczegóły: `docs/83-core-lifecycle-policy.md`.

---

# Flow P — dashboard

Dashboard jest projekcją, nie source of truth.

Agreguje:
- licencje,
- egzaminy,
- activity feed,
- kalendarz.

Kliknięcia prowadzą do właściwych bounded contexts; dashboard nie implementuje duplikatu ich logiki.
