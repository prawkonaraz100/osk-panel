# OSK Biz — mapa funkcjonalna i dokumentacja odtworzeniowa

Dokumentacja clean-room do zbudowania systemu klasy **OSK Business Management Platform** o funkcjonalności porównywalnej z publicznie dostępnymi, regulaminowo opisanymi i historycznie zindeksowanymi funkcjami serwisu `biz.prawo-jazdy-360.pl`.

> Cel: odtworzyć **funkcjonalność, logikę biznesową, role, przepływy i architekturę**, a nie kopiować kod źródłowy, branding, teksty marketingowe, grafiki ani chroniony układ wizualny konkurencyjnego serwisu.

## Status audytu

Pierwsze mapowanie: **2026-09-05**  
Ponowna niezależna weryfikacja: **2026-09-05**

Źródła:
- aktualna publiczna strona BIZ,
- logowanie, rejestracja i reset hasła,
- aktualny cennik,
- publiczny regulamin i polityka prywatności,
- aktualna publiczna oferta reklam,
- aktualności produktowe operatora,
- zachowanie aktualnych chronionych tras i `ReturnUrl`,
- starsze publicznie zindeksowane menu panelu — wyłącznie jako materiał historyczny.

## Oznaczenia pewności

- `CURRENT_CONFIRMED` — potwierdzone w aktualnej publicznej stronie/trasie/ofercie.
- `RULES_CONFIRMED` — potwierdzone w aktualnie publikowanym regulaminie.
- `HISTORICAL_INDEX` — widoczne w starszym indeksie/menu/aktualności; nie oznacza automatycznie funkcji istniejącej dziś.
- `INFERRED` — nasza logiczna/architektoniczna funkcja potrzebna do zbudowania solidnego odpowiednika.
- `TO_VERIFY_AUTH` — dokładne zachowanie wymaga legalnego wejścia do aktualnego panelu OSK.
- `SOURCE_CONFLICT` — publiczne materiały podają różne wartości; implementacja ma używać konfiguracji zamiast hard-code.

## Najważniejsza zasada

**Codex nie może traktować `HISTORICAL_INDEX`, `INFERRED` ani `TO_VERIFY_AUTH` jako dowodu na aktualny ekran lub akcję konkurencyjnego panelu.**

Jeśli funkcjonalność jest potrzebna naszemu produktowi, implementujemy własne rozwiązanie i opisujemy ją jako decyzję projektową.

## Dokumenty

1. [01-feature-map.md](docs/01-feature-map.md) — pełna mapa modułów i akcji po ponownej weryfikacji.
2. [02-screen-inventory.md](docs/02-screen-inventory.md) — ekrany, stany oraz poziom pewności.
3. [03-user-flows.md](docs/03-user-flows.md) — przepływy użytkownika i state transitions.
4. [04-roles-permissions.md](docs/04-roles-permissions.md) — RBAC i zakres dostępu.
5. [05-domain-model.md](docs/05-domain-model.md) — model danych i relacje.
6. [06-api-contract.md](docs/06-api-contract.md) — projekt API i zdarzeń domenowych.
7. [07-architecture.md](docs/07-architecture.md) — architektura techniczna Laravel + Vue.
8. [08-security-compliance.md](docs/08-security-compliance.md) — bezpieczeństwo, audyt, RODO i integralność operacji.
9. [09-roadmap.md](docs/09-roadmap.md) — kolejność implementacji.
10. [10-gap-register.md](docs/10-gap-register.md) — elementy nadal wymagające zalogowanego audytu.
11. [11-source-evidence.md](docs/11-source-evidence.md) — źródła i materiał dowodowy.
12. [12-action-matrix.md](docs/12-action-matrix.md) — akcja -> warunek -> skutek -> audit -> notification.
13. [13-acceptance-criteria.md](docs/13-acceptance-criteria.md) — kryteria akceptacji krytycznych flow.
14. [14-reverification-audit-2026-09-05.md](docs/14-reverification-audit-2026-09-05.md) — raport różnic znalezionych w drugim audycie.
15. [functional-requirements.yml](specs/functional-requirements.yml) — wymagania w formacie maszynowym dla agentów.

## Docelowy zakres produktu

System powinien obejmować:
- konto i organizację OSK,
- pracowników, permissions, biuro i HR,
- kursantów i provisionowanie dostępu,
- integrację PKK,
- pojazdy i terminy formalne,
- kalendarz jazd, self-booking i ewidencję czasu,
- licencje e-learningowe z inventory/przydziałem/aktywacją,
- monitoring postępów,
- kurs, wykłady i szkolenie z instruktorem,
- zmienną kategorię domyślną użytkownika,
- egzaminy wewnętrzne linkiem i stacjonarnie,
- płatności oraz jawne `Aktywuj dostęp` dla produktów, które tego wymagają,
- publiczną wizytówkę, opinie i ranking,
- reklamy z rejonizacją i aukcjami,
- kreacje reklamowe i moderację,
- artykuły sponsorowane,
- historycznie obserwowany widok kursanta/impersonację jako funkcję wymagającą dalszej weryfikacji,
- obsługę wielu języków per moduł,
- audyt operacji, powiadomienia i bezpieczeństwo.

### Niepewne / opcjonalne
- aktualny moduł faktur — starszy indeks go zawierał, ale stara trasa obecnie zwraca 404; `HISTORICAL_INDEX / TO_VERIFY_AUTH`,
- eksport postępów — `INFERRED`,
- klientowe pauzowanie kampanii — `TO_VERIFY_AUTH`,
- panelowy refund — niepotwierdzony; proces reklamacji/odstąpienia nie jest tym samym co przycisk refund.

## Zasada implementacyjna

Każdy moduł implementujemy jako niezależny bounded context z własną polityką uprawnień, logiem audytowym oraz API. Nie wiążemy krytycznej logiki formalnej z UI. Dzięki temu można wymieniać interfejs bez ryzyka naruszenia logiki kursu, egzaminu, rozliczeń, aukcji, licencji lub PKK.

Wartości zmienne w czasie — języki, kategorie, liczba lekcji, parametry reklam, okresy produktów — muszą być danymi konfiguracyjnymi/CMS, nie stałymi zaszytymi w kodzie.
