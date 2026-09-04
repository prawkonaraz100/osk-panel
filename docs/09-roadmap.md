# 09. Roadmap wdrożenia

> Roadmap po drugim audycie. Najpierw budujemy rdzeń operacyjny i integralność stanów, później moduły komercyjne. `TO_VERIFY_AUTH` nie blokuje stworzenia własnego odpowiednika, ale blokuje twierdzenie, że implementujemy dokładnie bieżące UI 360.

## Etap 0 — fundament
- tenant + auth,
- login social,
- reset hasła przez e-mail/login,
- bezpieczny ReturnUrl,
- permission-based RBAC,
- organization profile,
- audit log,
- notifications/event bus,
- wspólny design system,
- feature flags/config/capabilities,
- polityka sesji konfigurowalna per typ konta.

## Etap 1 — operacyjne OSK
- kursanci,
- pracownicy,
- role/permissions,
- pojazdy,
- kalendarz,
- planowanie jazdy sobie/innemu pracownikowi,
- self-booking kursanta,
- ewidencja czasu pracy,
- przypomnienia.

Przed zamknięciem etapu zweryfikować `GAP-02..05` przy legalnym dostępie do panelu referencyjnego, jeśli dostęp jest dostępny.

## Etap 2 — szkolenie i postępy
- kursy,
- wykłady,
- postępy,
- preferencja domyślnej kategorii,
- `Szkolenie z instruktorem`,
- lekcje wideo,
- postęp programu,
- pytania kontrolne,
- retry i skip,
- capability matrix języków/kategorii,
- własny widok kursanta.

Liczby działów/lekcji/slajdów są CMS/config.

## Etap 3 — licencje, inventory i sprzedaż
- katalog produktów/licencji,
- inventory niewykorzystanych sztuk,
- przydzielenia,
- provisioning e-mail,
- provisioning login+hasło,
- jawny wybór języka,
- aktywacja,
- atomowe cofnięcie nieaktywowanego przydziału + restore inventory,
- płatności,
- transfer confirmation,
- entitlement lifecycle `ordered -> paid -> activation_available -> activated -> expired`,
- historia zakupów/płatności.

### Faktury
Nie traktować jako wymagania parytetu v1. Status badanego modułu: `HISTORICAL_INDEX / TO_VERIFY_AUTH`. Możemy wdrożyć własne faktury, jeśli wynikają z naszych potrzeb księgowych.

## Etap 4 — egzamin wewnętrzny
- inventory egzaminów,
- generowanie,
- flow linkiem,
- flow stacjonarne,
- sesja egzaminu,
- wynik,
- cyfrowa karta przebiegu,
- PDF/druk,
- historia,
- języki jako capability/config.

TTL/unieważnianie linku projektować bezpiecznie jako własną politykę do czasu potwierdzenia detali panelu.

## Etap 5 — PKK
- sandbox/fake provider,
- adapter/provider interface,
- wszystkie potwierdzone commandy PKK,
- log operacji,
- idempotency,
- retry policy,
- reconciliation/diagnostics,
- dopiero potem rzeczywista integracja po formalnym dostępie/uprawnieniach.

## Etap 6 — publiczny profil, opinie i ranking
- publiczna wizytówka,
- oceny/opinie,
- moderacja,
- zgłaszanie opinii przez OSK,
- ranking snapshots,
- własny transparentny algorytm rankingu.

Nie kopiować algorytmu konkurenta; publiczny opis uśredniania bayesowskiego jest inspiracją funkcjonalną, nie kodem/specyfikacją do skopiowania.

## Etap 7 — reklamy i aukcje
- regiony/miejscowości,
- placementy,
- aukcje,
- opening bid/min increment,
- wiążące bidy,
- settlement z tie-breakiem po kolejności,
- winning order,
- płatność,
- upload desktop/mobile creative,
- moderacja kreacji,
- text fallback jako konfigurowalna polityka,
- harmonogram/emisja kampanii,
- historia ofert,
- request ukrycia nazwy oferenta,
- request odrzucenia bidu przez operatora.

`Wizytówka premium` pozostaje feature flag `coming_soon` do czasu własnej decyzji produktowej.

## Etap 8 — produkty promocyjne
- artykuły sponsorowane,
- editorial workflow,
- partner banner,
- commercial services / lead generation,
- SEO/WWW jako osobny pion handlowy, nie zależność operacyjnego OSK.

## Etap 9 — hardening / zgodność
- security review,
- RODO/retencja,
- session/device controls,
- performance,
- backup/restore,
- observability,
- reconciliation płatności,
- disaster recovery,
- pełne E2E krytycznych state machines.

## Równoległy tor — autoryzowana weryfikacja panelu referencyjnego

Po uzyskaniu legalnego konta/demo:
1. Dashboard.
2. Kursanci.
3. Pracownicy/RBAC.
4. Pojazdy.
5. Kalendarz.
6. PKK UI.
7. Licencje i języki.
8. Postępy.
9. Egzaminy.
10. Reklamy po wygranej.
11. Impersonacja.
12. Faktury/historie płatności.

Każde potwierdzenie aktualizuje `10-gap-register.md` i confidence level w YAML.

## Definition of Done modułu

Każdy moduł przed uznaniem za gotowy posiada:
1. wymagania i acceptance criteria,
2. oznaczony confidence/source status,
3. policy tests,
4. tenant isolation tests,
5. audit coverage,
6. testy idempotencji/race conditions dla krytycznych stanów,
7. error/empty/loading states,
8. mobile QA,
9. dokumentację API,
10. testy krytycznych flow E2E,
11. migracje/reconciliation plan,
12. aktualizację `functional-requirements.yml`.
