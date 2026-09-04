# OSK Biz — mapa funkcjonalna i dokumentacja odtworzeniowa

Dokumentacja clean-room do zbudowania systemu klasy **OSK Business Management Platform** o funkcjonalności porównywalnej z publicznie dostępnymi i zindeksowanymi funkcjami serwisu `biz.prawo-jazdy-360.pl`.

> Cel: odtworzyć **funkcjonalność, logikę biznesową, role, przepływy i architekturę**, a nie kopiować kod źródłowy, branding, teksty marketingowe, grafiki ani chroniony układ wizualny konkurencyjnego serwisu.

## Status audytu

Data mapowania: **2026-09-05**

Źródła:
- publiczna strona marketingowa i logowania,
- publiczny cennik,
- publiczny regulamin i polityka prywatności,
- publicznie zindeksowane starsze menu panelu,
- informacje o modułach publikowane przez operatora serwisu.

### Oznaczenia pewności

- `CONFIRMED` — potwierdzone na aktualnej publicznej stronie/regulaminie.
- `INDEXED` — potwierdzone w publicznie zindeksowanym widoku/menu, ale niezweryfikowane w bieżącej sesji zalogowanej.
- `INFERRED` — wynik logiczny potrzebny do zbudowania równoważnej funkcjonalności.
- `TO_VERIFY` — wymaga inspekcji po zalogowaniu do rzeczywistego panelu lub DEMO.

## Dokumenty

1. [01-feature-map.md](docs/01-feature-map.md) — pełna mapa modułów i akcji.
2. [02-screen-inventory.md](docs/02-screen-inventory.md) — ekrany, przyciski, stany i przejścia.
3. [03-user-flows.md](docs/03-user-flows.md) — przepływy użytkownika.
4. [04-roles-permissions.md](docs/04-roles-permissions.md) — RBAC i zakres dostępu.
5. [05-domain-model.md](docs/05-domain-model.md) — model danych i relacje.
6. [06-api-contract.md](docs/06-api-contract.md) — projekt API i zdarzeń domenowych.
7. [07-architecture.md](docs/07-architecture.md) — architektura techniczna Laravel + Vue.
8. [08-security-compliance.md](docs/08-security-compliance.md) — bezpieczeństwo, audyt, RODO i retencja.
9. [09-roadmap.md](docs/09-roadmap.md) — kolejność implementacji.
10. [10-gap-register.md](docs/10-gap-register.md) — lista elementów do dalszej weryfikacji.
11. [11-source-evidence.md](docs/11-source-evidence.md) — źródła i dowody.
12. [functional-requirements.yml](specs/functional-requirements.yml) — wymagania w formacie maszynowym.

## Docelowy zakres produktu

System powinien obejmować:

- konto i organizację OSK,
- pracowników i role,
- kursantów i kursy,
- integrację PKK,
- pojazdy i terminy formalne,
- kalendarz jazd i wykładów,
- licencje e-learningowe,
- monitoring postępów,
- egzaminy wewnętrzne,
- wykłady i zasoby edukacyjne,
- płatności, historię i faktury,
- wizytówkę OSK i ranking,
- reklamy i kampanie,
- widok kursanta,
- obsługę wielu języków,
- audyt operacji, powiadomienia i bezpieczeństwo.

## Zasada implementacyjna

Każdy moduł implementujemy jako niezależny bounded context z własną polityką uprawnień, logiem audytowym oraz API. Nie wiążemy krytycznej logiki formalnej z UI. Dzięki temu można wymieniać interfejs bez ryzyka naruszenia logiki kursu, egzaminu, rozliczeń lub PKK.
