# 129. PKK deferred pending PWPW guidance

Data: 2026-09-12  
Status: **DEFERRED_PENDING_PWPW_GUIDANCE**

## Decyzja

Dalsza implementacja modułu PKK adapter/integration zostaje odroczona. Moduł ma zostać wznowiony dopiero po otrzymaniu i zweryfikowaniu autorytatywnych wytycznych, dokumentacji integracyjnej albo kontraktu technicznego PWPW.

Powodem jest to, że provider-specific warstwa PKK jest zależna od formalnych i technicznych wymagań zewnętrznego operatora. Nie będziemy uzupełniać brakujących zasad przez domysły ani implementować zachowania na podstawie niezweryfikowanych założeń.

## Co pozostaje w repozytorium

Nie cofamy wykonanej pracy projektowej ani Gate 1.

Zachowane pozostają:

- reverse-engineering evidence i screen specs PKK,
- Stage-4 provider-neutral database authority,
- course-scoped PKK identity,
- provider-neutral expand schema,
- 14 tabel PKK dodanych w Gate 1 oraz wcześniej istniejący pkk_profiles,
- migration registry w stanie 99/170,
- execution identity f9116683fbfecbd32cfe46c13b5025e60c22c3197ea87b9f8933e796c1b4f48e.

Gate 1 został zwalidowany na accepted commit:

389ebc6d7cefba4f1221406ac25a2439bb9fd464

Exact tree:

a67db63a7d6d7ddc3e3c033447710591cebe7b9c

Implementation CI run 34696258224:

- 5/5 jobs PASS,
- PostgreSQL: 153 tests / 2482 assertions,
- secret scan: PASS.

Ten groundwork **nie oznacza**, że system posiada działającą integrację z PWPW. Aktualny runtime zachowuje lokalną, zaszyfrowaną identity PKK dla konkretnego `CourseEnrollment` i pozwala wprowadzić numer ręcznie. Nie istnieje natomiast import PWPW, provider controller/service, fizyczne PKK route'y HTTP ani operacyjny UI providera.

## Czego nie implementujemy przed wznowieniem

Do czasu otrzymania zweryfikowanych wytycznych PWPW nie implementujemy:

- live calls do PWPW,
- provider-specific request/response DTO,
- provider-specific status catalog,
- assumptions dotyczących native idempotency,
- assumptions dotyczących retry/reconciliation i external-effect detection,
- zasad podpisu/XAdES lub dokładnego XML submission flow,
- sposobu przechowywania lub używania realnych danych logowania operatora,
- mutujących operacji PKK dostępnych użytkownikowi,
- zachowania test-connection bez znanego kontraktu operatora.

## Warunki wznowienia

PKK można ponownie rozpocząć dopiero po spełnieniu co najmniej następujących warunków:

1. otrzymano autorytatywne wytyczne, dokumentację albo kontrakt PWPW,
2. zweryfikowano wymagania formalne dotyczące dostępu i onboardingu,
3. znane są dokładne request/response fields i status semantics,
4. znane są zasady idempotency, retry i reconciliation,
5. znane są wymagania podpisu i wymiany dokumentów, jeżeli występują,
6. wykonano aktualizację threat model / security review dla zweryfikowanego kontraktu,
7. istniejące provider-neutral specs/API/DB zostaną porównane z nowym kontraktem przed implementacją.

## Wpływ na kolejność core v1

PKK pozostaje zachowanym bounded contextem formalnym, ale provider-specific integracja PWPW jest odroczona i nie blokuje pozostałego zakresu ani uruchomienia Core. Brak importu PWPW nie blokuje ręcznego utworzenia lokalnej identity PKK wymaganej przez obecny formalny `CourseEnrollment`.

Historyczny następny slice zapisany podczas podjęcia tej decyzji został już zrealizowany. Bieżący backlog znajduje się wyłącznie w `docs/227-current-project-status-authority.md` oraz `specs/current-project-status.yml`.

Po otrzymaniu materiałów PWPW należy wrócić do tej decyzji, porównać autorytatywny kontrakt z zachowanym provider-neutralnym API/DB/security modelem, wykonać ponowną diagnozę PKK i dopiero potem rozpocząć provider-specific runtime.
