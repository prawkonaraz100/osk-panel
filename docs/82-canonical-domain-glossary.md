# 82. Canonical Domain Glossary — core OSK v1

Data: 2026-09-05

**Status:** `ACTIVE_CANONICAL_MODEL`

Cel: usunąć wieloznaczne nazwy encji przed migracjami i implementacją. Jeżeli starszy dokument używa innej nazwy dla tego samego pojęcia, implementacja używa nazw z tego glossary, chyba że późniejszy ADR jawnie je zmieni.

Ten glossary nie zastępuje szczegółowych screen specs. Zakres reverse-engineered jest chroniony przez `docs/96-reverse-engineering-preservation-contract.md`.

## Identity / Tenant

### `Organization`
Jeden tenant / jeden podmiot OSK w systemie.

Klucz: `organization_id`.

### `User`
Globalna tożsamość osoby mogącej logować się do aplikacji. Nie oznacza automatycznie pracownika ani kursanta.

Jedna osoba może być powiązana z więcej niż jednym OSK przez osobne `OrganizationMembership`.

### `OrganizationMembership`
Powiązanie `User <-> Organization`, zawierające stan członkostwa i permissions/role assignment.

### `StaffProfile`
Profil pracownika OSK. Może istnieć bez konta `User` i bez aktywnego `OrganizationMembership`.

**Nie utożsamiać:** `staff_type` z permissions.

### `StaffMembershipLink`
Historyczne/audytowalne powiązanie `StaffProfile <-> OrganizationMembership`.

Dlaczego nie zwykłe `StaffProfile -> User unique`:
- `User` jest globalny,
- ta sama osoba może pracować w dwóch OSK,
- permission scope należy do membership w konkretnym tenantcie,
- odłączenie dostępu panelowego nie może usuwać profilu pracownika ani jego historii.

W danej chwili `StaffProfile` ma najwyżej jeden aktywny link do membership swojej organizacji, ale historyczne linki pozostają zachowane.

---

# Student / learning access

### `Student`
Trwała kartoteka osoby szkolonej w konkretnym OSK. Jest rekordem formalnym/CRM-owym organizacji.

`Student` nie jest loginem do platformy edukacyjnej.

### `StudentLearningAccount`
Konto/dostęp kursanta do produktu edukacyjnego. Jeden `Student` może mieć zero lub więcej historycznych/technicznych dostępów, zależnie od polityki produktu.

Przechowuje m.in.:
- identyfikator logowania / e-mail,
- język,
- powiązanie z `User/AuthIdentity`, jeśli używamy wspólnego identity layer,
- stan dostępu.

### `StudentAccessHandoff`
Audytowalny fakt wygenerowania/przekazania danych dostępowych lub PDF. Nie przechowuje odwracalnego starego hasła.

**Zakazane jako canonical names:**
- `student_accounts` jako nieprecyzyjne określenie,
- `student_access_credentials` jako magazyn hasła.

---

# Formalny kurs

### `CourseEnrollment`
Canonical owner formalnego szkolenia konkretnego kursanta w konkretnej kategorii/trybie.

To nie jest ogólny katalog treści. Zawiera m.in.:
- `organization_id`,
- `student_id`,
- rodzaj szkolenia,
- kategorię,
- datę rozpoczęcia,
- instruktora prowadzącego,
- lokalizację,
- etap/status szkolenia,
- zakończenie/przerwanie.

**W core v1 używamy `CourseEnrollment`, a nie ogólnego `Course`, gdy mówimy o kursie konkretnego kursanta.**

Zaobserwowany formularz nadal posiada pola godzin teorii/praktyki i godzin odbytych w innej szkole. Nie usuwamy ich z UI tylko dlatego, że canonical backend rozdziela źródła prawdy. Mapowanie opisuje preservation manifest.

### `TrainingRequirementProfile`
Wersjonowany wynik rule engine dla `CourseEnrollment`.

Canonical current profile = najnowszy rekord, którego `superseded_at IS NULL`. Nie wymagamy dwukierunkowego/circular FK z `course_enrollments` do „current profile”.

### `CourseExemptionDecision`
Audytowalna podstawa zwolnienia/uznania teorii lub innego wymogu.

### `TrainingSession`
Pojedyncze rzeczywiste zajęcia w bieżącym OSK.

### `TrainingHourLedgerEntry`
Niezmienny/audytowalny zapis formalnie zaliczanego czasu wynikający z `TrainingSession` albo dozwolonej korekty.

### `RecognizedExternalTraining`
Audytowalny rekord godzin/zakresu szkolenia uznanego z innego OSK.

Początkowe wartości wpisane podczas tworzenia/edycji kursu mogą być zmaterializowane jako rekordy `RecognizedExternalTraining` ze źródłem `course_form_initial`. Późniejsze zmiany nie nadpisują historii bez śladu — używają nowego rekordu/reversal/correction zgodnie z lifecycle.

**Source of truth:**
- bieżące OSK -> `TrainingSession` + `TrainingHourLedgerEntry`,
- poprzednie OSK -> `RecognizedExternalTraining`.

Nie utrzymujemy ręcznie edytowalnego agregatu godzin bieżącego OSK jako równoległego źródła prawdy.

---

# PKK

### `PkkProfile`
Lokalny snapshot/stan PKK **dla konkretnego `CourseEnrollment`**.

Relacja canonical:

`Organization -> Student -> CourseEnrollment -> PkkProfile`

Nie modelujemy `Student -> PkkProfile` jako jedynej relacji, ponieważ jedna osoba może mieć wiele szkoleń/PKK w czasie.

### `PkkOperation`
Audytowalna operacja biznesowa na PKK konkretnego `CourseEnrollment`.

### `PkkOperationAttempt`
Techniczna próba wykonania `PkkOperation` do zewnętrznego provider API.

---

# Kalendarz / zasoby

### `CalendarEvent`
Wspólna encja czasu dla wydarzeń operacyjnych OSK.

### `DrivingLesson`
Domena jazdy praktycznej; może być specjalizacją/agregatem opartym o `CalendarEvent` zależnie od implementacji.

### `AvailabilitySlot`
Slot dostępności/self-booking.

### `Location`
Osobny zasób organizacji: `branch`, `lecture_room`, `maneuvering_area` i ewentualne kolejne typy z config.

### `Vehicle`
Pojazd OSK będący zasobem kalendarza.

### Assignment tables dla zasobów
Relacje many-to-many zaobserwowane w formularzach nie są przechowywane w JSON:
- `StaffCategoryAssignment`,
- `StaffLocationAssignment`,
- `VehicleCategoryAssignment`,
- `VehicleLocationAssignment`.

---

# Student finance

### `StudentCharge`
Należność kursanta wobec OSK za szkolenie/usługę.

### `StudentPayment`
Wpłata przypisana do należności.

**Nie mylić z:** `Order`/`Payment` platformy, które opisują zakupy OSK u operatora PrawkoNaRaz.

---

# Licencje

### `LicenseProduct`
Konfigurowalny produkt/licencja.

### `LicenseInventoryEntry`
Jedna dostępna sztuka należąca do OSK.

### `LicenseAssignment`
Historyczny fakt przypisania konkretnej sztuki do `StudentLearningAccount`/kursanta.

Jedna sztuka inventory może mieć **wiele historycznych assignmentów** w czasie, jeżeli wcześniejsze przypisanie zostało cofnięte przed aktywacją i sztuka wróciła do puli. Constraint zabrania dwóch równoczesnych/current assignmentów, a nie historii.

### `LicenseActivation`
Jednorazowy moment rozpoczęcia wykorzystania konkretnego `LicenseAssignment`.

Canonical lifecycle:

`inventory -> assigned -> activated -> expired`

Gałąź odwracalna wyłącznie przed aktywacją:

`assigned + not_activated -> revoked -> inventory`

---

# Egzamin wewnętrzny

### `InternalExamInventoryEntry`
Jedna sztuka/credit egzaminu w puli OSK.

### `InternalExamReservation`
Historyczna rezerwacja sztuki dla planowanej próby/dostępu przed rozpoczęciem.

Inventory może mieć wiele historycznych reservation records, ale najwyżej jedną aktywną rezerwację w danej chwili. To samo dotyczy jednej próby.

### `InternalExamAccess`
Sposób udostępnienia konkretnej próby: remote link albo local station.

### `InternalExamAttempt`
Formalna próba egzaminu powiązana z:
- `organization_id`,
- `student_id`,
- `course_enrollment_id`,
- kategorią,
- częścią egzaminu,
- snapshotem danych i reguł.

### `InternalExamAttemptQuestion`
Niezmienny snapshot pytania/odpowiedzi danej próby.

### `InternalExamResult`
Wynik próby.

### `InternalExamDocument`
Wersjonowany dokument/PDF wynikający z konkretnej próby.

Domyślna polityka core v1:
- reserve inventory przy stworzeniu dostępu,
- consume inventory atomowo przy starcie próby,
- przed startem można zwolnić rezerwację,
- po starcie przywrócenie tylko przez audytowaną korektę.

---

# Platform commerce

### `Order`
Zakup OSK u operatora platformy.

### `OrderItem`
Pozycja zamówienia ze snapshotem ceny/VAT/produktu.

### `Payment`
Płatność za `Order`.

### `PaymentEvent`
Niezmienny event od providera płatności. Deduplikacja canonical: `(provider, provider_event_id)`.

### `ServiceEntitlement`
Prawo do usługi wynikające z zakupu/grantu.

### `ServiceActivation`
Jednorazowa aktywacja entitlementu, gdy produkt używa `activation_mode=explicit`.

---

# Audit / communication

### `AuditLog`
Niezmienny techniczno-biznesowy zapis krytycznej zmiany.

### `Notification`
Komunikat do użytkownika/operatora wynikający z domenowego zdarzenia.

### `OutboxMessage`
Techniczny rekord gwarantujący publikację krytycznych zdarzeń po commit DB.

---

# Nazwy, których nie należy mieszać

| Niejasne określenie | Canonical |
|---|---|
| kurs kursanta | `CourseEnrollment` |
| konto kursanta | `StudentLearningAccount` |
| dostęp/login kursanta | `StudentLearningAccount` + identity/auth |
| historia wygenerowanych danych | `StudentAccessHandoff` |
| płatność kursanta za kurs | `StudentPayment` |
| zakup licencji przez OSK | `Order` + `Payment` + `LicenseInventoryEntry` |
| egzamin przydzielony | `InternalExamReservation` / `InternalExamAccess` |
| wykonany egzamin | `InternalExamAttempt` |
| PKK kursanta | `PkkProfile` należący do `CourseEnrollment` |
| godziny kursu | projekcja z ledgeru + recognized external training |
| dostęp pracownika do panelu | `OrganizationMembership` powiązany przez `StaffMembershipLink` |

## Reguła migracji dokumentacji

Starszych nazw nie trzeba mechanicznie usuwać z historycznych opisów audytu. Każdy nowy kontrakt API, model Eloquent, migracja, test i ADR ma jednak używać canonical names z tego dokumentu.
