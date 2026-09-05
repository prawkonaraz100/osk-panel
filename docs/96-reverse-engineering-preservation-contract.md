# 96. Reverse-engineering preservation contract

Data: 2026-09-05

**Status:** `AUTHORITATIVE_PROCESS_RULE`

## Cel

Repozytorium powstało w wyniku clean-room reverse engineeringu funkcjonalnego panelu OSK. Konsolidacja techniczna ma usuwać sprzeczności, poprawiać model domenowy i przygotowywać implementację, ale **nie może redukować zakresu funkcjonalnego, który został rzeczywiście zaobserwowany i zapisany podczas audytu**.

To jest kontrakt obowiązujący agentów, developerów i przyszłe refaktoryzacje dokumentacji.

## 1. Zasada preservation-first

Każda funkcja oznaczona w szczegółowych materiałach jako potwierdzona obserwacją bieżącego panelu, dokumentu, publicznego źródła lub regulaminu pozostaje częścią knowledge base.

W szczególności nie wolno zgubić podczas konsolidacji:
- route i entry pointów,
- ekranów, zakładek, drawerów, modali i podwidoków,
- wszystkich pól formularzy,
- required/optional/conditional fields,
- wszystkich wartości/select options, które faktycznie zaobserwowano,
- kolumn tabel,
- wyszukiwania,
- filtrów i ich opcji,
- sortowania i jego opcji,
- przełączników/toggle,
- akcji wiersza,
- akcji zbiorczych,
- stanów pustych,
- statusów i oznaczeń,
- lifecycle/state transitions,
- dokumentów PDF/druków,
- downloadów,
- cross-module links,
- ostrzeżeń i alertów,
- zależności finansowych,
- relacji PKK/kurs/kursant,
- wejść do egzaminu i wariantów uruchomienia,
- przypisań licencji i wariantów dostępu,
- konfiguracji pracowników/pojazdów/lokalizacji,
- zaobserwowanych flow oraz decyzji użytkownika na każdym kroku.

High-level summary może być krótszy, ale **nigdy nie zastępuje szczegółowego wymagania**.

## 2. Co oznacza „ma być zrobione”

Dla core v1 każda potwierdzona funkcja reverse-engineered ma otrzymać co najmniej:

1. wpis w screen/domain spec,
2. model danych albo jawnie wskazaną projection,
3. API capability lub lokalną funkcję frontendową, jeśli nie wymaga API,
4. permission/auth check,
5. audit requirement, jeśli mutuje dane formalne, finansowe, dostępowe, PKK, licencje albo egzaminy,
6. acceptance criterion,
7. test co najmniej integration/feature dla mutacji i policy test dla authorization,
8. obsługę loading/empty/error/success tam, gdzie ma to zastosowanie.

Nie wystarcza samo odwzorowanie wyglądu ekranu.

## 3. Hierarchia: observation vs own product decision

### `USER_CONFIRMED_AUTH_SCREEN`
To zaobserwowane zachowanie bieżącego zalogowanego panelu. Dla core v1 traktujemy je jako wymaganie funkcjonalne parytetu, chyba że niższy punkt tej sekcji stanowi inaczej.

### `LEGAL_VERIFIED`
Ma pierwszeństwo nad obserwacją konkurenta, jeśli własny system musi zachowywać się inaczej z przyczyn prawnych/formalnych.

### `OWN_PRODUCT_DECISION`
Może poprawić bezpieczeństwo, architekturę, nazewnictwo albo UX, ale nie może po cichu usuwać potwierdzonej zdolności biznesowej.

Przykład: konkurent pokazuje „Usuń kurs”, ale nasz backend może wykonać audited cancellation/soft lifecycle zamiast physical DELETE. **Zdolność operatora do wykonania odpowiednika akcji zostaje zachowana.**

### `SOURCE_CONFLICT`
Nie wybieramy przypadkowo jednej liczby/wartości. Modelujemy capability/configuration i zachowujemy wszystkie potwierdzone warianty jako evidence.

### `DEMO_BLOCKED` / `UNOBSERVABLE_IN_DEMO_NONBLOCKING`
Nie wymyślamy zachowania konkurenta. Projektujemy własny bezpieczny flow, zachowując znany business intent i entry point.

### demo data / anomaly
Przykładowe rekordy, ceny, daty sentinel, liczniki chwilowe i dane osobowe z demo nie są requirementami biznesowymi.

## 4. Źródła szczegółowego evidence

Dla reverse-engineeringu UI źródłem prawdy są przede wszystkim:
- `docs/17-80`,
- odpowiadające `specs/screens/*.yml`,
- `docs/11-source-evidence.md`,
- `docs/14-reverification-audit-2026-09-05.md`,
- `docs/15-current-authenticated-menu-map.md`.

Dokumenty `docs/81+` służą konsolidacji developerskiej i nie mogą usuwać scope'u z powyższych materiałów.

`specs/implementation-baseline-v1.yml` wyznacza aktywny core i architekturę, ale nie jest skróconym zamiennikiem screen specs.

## 5. Anti-loss rule dla agentów

Przed oznaczeniem modułu jako zaimplementowany agent MUSI wykonać traceability pass:

`observed evidence -> functional requirement -> domain/data -> API/use case -> permission -> UI -> acceptance test`

Jeżeli choć jeden potwierdzony element screen spec nie ma odpowiednika implementacyjnego, moduł nie jest `DONE`.

Dozwolone powody świadomego braku implementacji:
- moduł jest jawnie poza core v1,
- funkcja jest `HISTORICAL_INDEX` bez bieżącego potwierdzenia,
- zachowanie zostało zastąpione z powodu `LEGAL_VERIFIED`,
- zachowanie jest błędem/anomalią demo, nie funkcją,
- product owner jawnie odroczył funkcję i zapisano to w baseline/decision log.

Każdy taki wyjątek musi być zapisany, nie domniemany.

## 6. Clean-room boundary

Implementujemy:
- capability,
- workflow,
- dane,
- role/uprawnienia,
- state machine,
- dokumenty wymagane procesem,
- relacje i skutki biznesowe.

Nie kopiujemy:
- kodu źródłowego,
- prywatnych endpointów konkurenta,
- nazw wewnętrznych klas/metod,
- chronionych assetów,
- brandingu,
- marketing copy,
- pixel-perfect layoutu.

Własny UI ma być autorski.

## 7. Zakaz „konsolidacji przez kasowanie”

Przy porządkowaniu dokumentacji:
- nie kasuj szczegółowej screen spec dlatego, że istnieje aggregate,
- nie zamieniaj listy pól na ogólne „CRUD”,
- nie zamieniaj konkretnych filtrów na samo „filtering”,
- nie zamieniaj kilku entry points w jeden, jeśli zaobserwowano różne flow,
- nie usuwaj stanów empty/error/demo-blocked,
- nie usuwaj source conflicts — modeluj je jako capability/config,
- nie kasuj historycznego evidence; oznacz jego confidence.

## 8. Gate dla PR

PR implementujący moduł core powinien zawierać sekcję „Reverse-engineering traceability” i wskazać:
- użyte `docs/*`,
- użyte `specs/screens/*`,
- wszystkie zaimplementowane confirmed actions,
- wszystkie świadomie niezaimplementowane elementy wraz z reason/status,
- testy pokrywające kluczowe flow.

Brak tej sekcji oznacza niekompletny PR dla modułu zmapowanego reverse-engineeringiem.
