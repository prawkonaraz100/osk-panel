# 10. Gap register — aktualne luki po audycie ekranowym

Data konsolidacji: 2026-09-05

> Ten dokument nie jest już listą „czego nie znamy z panelu 360” sprzed audytu. Po zmapowaniu core OSK przechowuje wyłącznie realne luki, które nadal mogą wpływać na projekt albo testy. Status gotowości modułów znajduje się w `docs/71-admin-osk-module-mapping-status.md`.

## 1. Core uznany za wystarczająco zmapowany

Następujące obszary NIE są już otwartymi blockerami audytu:

- Panel główny,
- Integracja PKK — entrypoint i panel operacyjny przy kursie,
- Kursanci — lista, szczegóły, create/edit, filtry, sortowanie, postępy, płatności, licencje i egzamin,
- Kursy (PKK) — create/edit/delete confirmation,
- Kalendarz — główny ekran i formularz dodania wydarzenia,
- Lokalizacje — lista, create, edit, kalendarz,
- Pojazdy — lista, create, detail, edit, delete confirmation,
- Pracownicy — lista, create, detail, edit,
- Licencje — zakup i panel zarządzania,
- Egzaminy wewnętrzne — zakup, panel, generowanie, historia prób, wynik, review pytań i PDF,
- Ustawienia,
- Historia zakupów.

Brak obserwacji pojedynczego zachowania w demo nie oznacza automatycznie blokady implementacji. Jeżeli funkcja jest już dobrze osadzona domenowo, stosujemy jawnie opisany własny lifecycle.

---

# 2. Luki P0 — muszą zostać rozstrzygnięte przed szeroką implementacją backendu

| ID | Obszar | Problem | Decyzja/akcja |
|---|---|---|---|
| DEV-P0-01 | Dokumentacja | stare agregaty mają niższy confidence niż nowe screen specs | konsolidacja agregatów + jeden implementation baseline |
| DEV-P0-02 | PKK | starsze API wiąże PKK bezpośrednio ze studentem | przebudować na `course_enrollment -> PKK` |
| DEV-P0-03 | Egzaminy | starsze docs raz zużywają inventory przy finish, raz przy start | utrzymać jedną politykę z `specs/design/internal-exam-lifecycle.yml` |
| DEV-P0-04 | Godziny szkolenia | formularz kursu ma agregaty, a formalny model wymaga ledgeru | bieżące OSK z ledgeru; poprzednie OSK jako audytowalne uznanie |
| DEV-P0-05 | Encje | częściowo nakładające się nazwy `student_accounts`, `student_learning_accounts`, `student_access_credentials` | utworzyć canonical domain glossary |
| DEV-P0-06 | API | brak pełnego technicznego kontraktu i wspólnych standardów | skonsolidować API + przygotować OpenAPI 3.1 |

---

# 3. Luki P1 — potrzebne przed zamknięciem modułów produkcyjnych

## GAP-CAL — Kalendarz

Pozostało do decyzji lub testów:
- dokładna walidacja długości wydarzenia,
- które zasoby są obowiązkowe dla `Jazda`,
- warning vs hard block przy nieważnym dokumencie instruktora/pojazdu,
- recurrence,
- powiadomienia,
- self-booking kursanta,
- ewidencja czasu pracy w UI,
- permissions,
- zachowanie wyboru zasobów między sesjami.

Nieobserwowalne u konkurenta, ale nieblokujące:
- drag&drop,
- exact cancel/move UI,
- dokładny status po zapisie,
- komunikaty konfliktu.

## GAP-STUDENT — Kursanci

Pozostało:
- ostateczna polityka archive vs delete,
- dokładne walidacje duplikatu PESEL/e-mail/login/PKK,
- exact success/error messages,
- paginacja dla dużej bazy,
- zasady resetu loginu/hasła,
- ostateczna polityka częściowych wpłat i korekt finansowych,
- definicje metryk postępu i dokładne wzory procentów.

## GAP-PKK — Integracja PKK

Pozostało:
- rzeczywisty kontrakt dostawcy PKK,
- autoryzacja/credential management,
- klasyfikacja błędów zewnętrznych,
- retry matrix,
- XML signing/upload flow,
- reconciliation po timeoutach,
- exact wymagania instytucjonalne dla dostępu do integracji.

Drawer konkurenta `Zarządzaj PKK` jest nieobserwowalny z powodu błędu strony i NIE jest blockerem budowy własnego flow.

## GAP-LICENSE — Licencje

Pozostało:
- kanoniczna macierz języków per produkt,
- dokładne zasady renewal/stacking,
- aktywacja przez OSK vs kursanta jako konfigurowalny policy,
- jak długo utrzymywać historię credential handoff,
- exact restore/reconciliation przy błędzie płatności/aktywacji.

## GAP-EXAM — Egzamin wewnętrzny

Pozostało:
- macierz języków/kategorii,
- czas egzaminu i formalne reguły wersjonowane,
- TTL/revoke/resend remote linku — własna polityka security,
- kolejność zużycia darmowej vs płatnej puli,
- polityka miesięcznego resetu darmowej puli,
- recovery po `technical_abort`,
- exact zakres danych PDF względem obowiązujących wzorów formalnych.

Własny domyślny lifecycle jest już opisany w `specs/design/internal-exam-lifecycle.yml`.

## GAP-STAFF — Pracownicy/RBAC

Pozostało:
- pełny słownik `staff_type`,
- permission matrix dla własnego produktu,
- provisioning konta pracownika,
- default permissions,
- lifecycle wyłączenia/archiwizacji pracownika,
- zachowanie istniejących wydarzeń po dezaktywacji,
- reset hasła i polityka sesji personelu.

## GAP-VEH — Pojazdy

Pozostało:
- dokładne walidacje VIN/rejestracji/roku/pojemności,
- zasady pliku zdjęcia,
- lifecycle archiwizacji i przywracania,
- zachowanie kalendarza po archiwizacji,
- polityka blokowania pojazdu z nieważnymi dokumentami.

## GAP-LOC — Lokalizacje

Pozostało:
- lifecycle archiwizacji i restore,
- zachowanie powiązanych przyszłych wydarzeń,
- wpływ archiwizacji na kursy/pracowników/pojazdy,
- dokładne źródło słownika miejscowości,
- walidacja kodu pocztowego/adresu.

---

# 4. Moduły świadomie poza core v1

| Obszar | Status |
|---|---|
| Moje wizytówki | `NOT_SCREEN_MAPPED` |
| Moje reklamy | `NOT_SCREEN_MAPPED` |
| Wykłady | `NOT_SCREEN_MAPPED` |
| Szkolenie z instruktorem | `NOT_SCREEN_MAPPED` |

Ich brak nie blokuje rozpoczęcia implementacji core admin OSK.

---

# 5. Elementy historyczne / niepotwierdzone

| Obszar | Status | Zasada |
|---|---|---|
| Faktury | `HISTORICAL_INDEX` | nie wymagane do parytetu v1; implementujemy tylko jeśli potrzebne biznesowo |
| Przeglądaj jako kursant | `HISTORICAL_INDEX` | własna impersonacja opcjonalna i silnie audytowana |
| `export_progress` | `OWN_PRODUCT_DECISION` | brak dowodu funkcji konkurenta |
| `pause_campaign` | `TO_VERIFY_AUTH` | nie uznawać za potwierdzoną akcję klienta |
| `refund` | `OWN_PRODUCT_DECISION` | proces finansowy, nie potwierdzony panelowy przycisk |

---

# 6. Konflikty źródeł, które pozostają konfiguracją

| ID | Obszar | Decyzja |
|---|---|---|
| CON-01 | języki | capability matrix per produkt/moduł |
| CON-02 | liczba sekcji szkolenia | CMS/config |
| CON-03 | liczba slajdów | CMS/config |
| CON-04 | czas fullscreen ad | parametr placementu |
| CON-05 | faktury | historyczne / własna decyzja biznesowa |

---

# 7. Reguła zamykania gapu

Gap może zostać zamknięty przez jeden z trzech sposobów:

1. `USER_CONFIRMED_AUTH_SCREEN` — zachowanie zaobserwowane na aktualnym ekranie,
2. `LEGAL_VERIFIED` — zachowanie wynika z aktualnej podstawy prawnej,
3. `OWN_PRODUCT_DECISION` — świadomie projektujemy własne zachowanie, bo detal konkurenta jest nieobserwowalny lub nieistotny.

Nie utrzymujemy w nieskończoność `TO_VERIFY_AUTH` dla detali, które nie są blockerem biznesowym ani prawnym. Jeżeli potrafimy bezpiecznie zaprojektować własny lifecycle, dokumentujemy decyzję i przechodzimy dalej.
