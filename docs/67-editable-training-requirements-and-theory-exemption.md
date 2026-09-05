# 67. Edytowalne wymagania kursu i zwolnienie z teorii

Data decyzji produktowej: 2026-09-05

**Zakres:** panel admin OSK / kursant / kurs / wymagania teorii i praktyki  
**Status:** `OWN_PRODUCT_DECISION` oparty na zweryfikowanych regułach prawnych z `docs/66-formal-student-record-and-theory-exemptions.md`.

## 1. Główna zasada

Wymagania kursu nie mogą zostać nieodwracalnie zamrożone w chwili utworzenia kursanta lub kursu.

Pracownik OSK (np. sekretariat) musi móc na późniejszym etapie poprawić podstawę prawną/stan faktyczny wpływający na wymagania szkolenia, np.:
- kurs `C+E` przy posiadanej kategorii `C`,
- `B+E` po `B`,
- `C1+E` po `C1`,
- `D1+E` po `D1`,
- `D+E` po `D`,
- `A2` po `A1`,
- `A` po `A1/A2`,
- `B` po `B1`,
- `C` po `C1`,
- `D` po `D1`,
- wcześniejszy pozytywny wynik państwowego egzaminu teoretycznego, gdy ma zastosowanie.

Zmiana podstawy powoduje ponowne wyliczenie wymagań kursu.

## 2. UX

Na profilu kursu należy udostępnić sekcję `Wymagania szkolenia / Zwolnienia`.

Proponowane pola:
- `Kategoria kursu`,
- `Posiadane uprawnienia / kategorie`,
- `Podstawa zwolnienia z teorii`,
- `Państwowa teoria zdana wcześniej` — tak/nie,
- `Data / referencja dowodu` opcjonalnie zgodnie z polityką OSK,
- wynik wyliczenia:
  - `Teoria wymagana: tak/nie`,
  - `Minimalna teoria`,
  - `Teoretyczny egzamin wewnętrzny wymagany: tak/nie`,
  - `Minimalna praktyka`,
  - `Praktyczny egzamin wewnętrzny wymagany: tak/nie`.

Dla kategorii +E system może automatycznie proponować właściwą regułę, ale pracownik nadal ma możliwość poprawienia danych źródłowych, jeżeli kurs został wcześniej założony błędnie.

## 3. Edycja na różnych etapach

Zmiana podstawy zwolnienia jest dozwolona:
- zaraz po utworzeniu kursu,
- w trakcie teorii,
- przed rozpoczęciem praktyki,
- w trakcie praktyki,
- przed egzaminem wewnętrznym,
- po błędnym wcześniejszym skonfigurowaniu kursu.

System nie powinien blokować korekty tylko dlatego, że kurs został już rozpoczęty.

Po formalnym zakończeniu i zamknięciu/wyeksportowaniu dokumentacji zmiana powinna przejść przez tryb `korekta`, z audytem i ponownym wygenerowaniem właściwych dokumentów/projekcji.

## 4. Zasada: przeliczaj, nie kasuj historii

Jeżeli kurs początkowo miał teorię wymaganą, a później ustawiono prawidłową podstawę zwolnienia:
- istniejące wpisy zajęć teoretycznych NIE są usuwane,
- istniejące czasy i obecności pozostają w historii,
- system przestaje wymagać dalszej realizacji minimalnej teorii,
- system blokuje wymaganie/generowanie teoretycznego egzaminu wewnętrznego, jeżeli po przeliczeniu nie jest wymagany,
- wcześniejsze niepotrzebnie wykonane czynności pozostają w audycie i nie są przepisywane wstecz.

Analogicznie, jeżeli korekta usuwa błędnie ustawione zwolnienie i teoria staje się wymagana, system ponownie pokazuje brakujące wymagania zamiast tworzyć fikcyjne zaliczenia.

## 5. Manualna decyzja nie może być bezpodstawna

Sekretarka może zmienić ustawienie, ale nie powinna mieć prostego, nieaudytowanego checkboxa `zwolnij z teorii` bez kontekstu.

UI powinno pozwalać wybrać dane/podstawę, np.:
- `kategoria +E / teoria niewymagana dla kategorii`,
- `uznanie wcześniejszej teorii z niższej kategorii`,
- `państwowa teoria zdana wcześniej`,
- `inna dozwolona podstawa skonfigurowana w rule engine`.

System na tej podstawie wylicza flagi wymagań.

Jeżeli wprowadzamy funkcję ręcznego override, musi ona wymagać:
- uprawnienia,
- powodu,
- użytkownika dokonującego zmiany,
- daty,
- wartości przed i po,
- opcjonalnej referencji/dowodu.

## 6. Model danych

Rekomendowane encje/pola:

`course_requirement_context`
- `course_enrollment_id`,
- `target_category`,
- `held_categories`,
- `state_theory_passed`,
- `exemption_basis_code`,
- `evidence_reference nullable`,
- `effective_from`,
- `updated_by`.

`course_requirement_decision`
- immutable audit event każdej kalkulacji/zmiany,
- snapshot inputs,
- snapshot outputs,
- reason,
- actor,
- timestamp.

Wyliczane outputy:
- `theory_training_required`,
- `minimum_theory_minutes`,
- `internal_theory_exam_required`,
- `practical_training_required`,
- `minimum_practical_minutes`,
- `internal_practical_exam_required`.

## 7. Przykład C+E

1. Sekretarka tworzy kursanta i przez pomyłkę zakłada pełny przebieg.
2. Kursant ma kategorię `C` i robi `C+E`.
3. Sekretarka później w sekcji `Wymagania szkolenia / Zwolnienia` zaznacza posiadane `C` / właściwą podstawę dla `C+E`.
4. Rule engine przelicza kurs:
   - teoria wymagana: `nie`,
   - teoretyczny egzamin wewnętrzny: `nie`,
   - praktyka: zgodnie z aktualną regułą dla `C+E`,
   - praktyczny egzamin wewnętrzny: `tak`.
5. Wcześniejsze dane historyczne pozostają, ale nie blokują prawidłowego dalszego przebiegu.

## 8. Warunki blokujące

Korekta nie może:
- usuwać historycznych zajęć,
- zmieniać podpisanych/zamkniętych dokumentów bez trybu korekty,
- fałszować faktycznie odbytych godzin,
- automatycznie oznaczać nieodbytych zajęć jako zaliczone,
- usuwać historii wykonanych egzaminów.

## 9. Decyzja architektoniczna

Wymagania kursu są **wersjonowaną projekcją reguł**, nie stałym zestawem flag zapisanym raz przy tworzeniu kursu.

`student/course facts -> rule engine -> current requirement projection`

Każda zmiana faktów może przeliczyć projekcję, a historia zmian pozostaje audytowalna.
