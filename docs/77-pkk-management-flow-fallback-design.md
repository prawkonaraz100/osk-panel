# 77. Integracja PKK — nieobserwowalny drawer `Zarządzaj PKK` i własny flow


> **Current runtime boundary (2026-09-15):** ten dokument zachowuje reverse-engineering/evidence i przyszłą capability PKK. Nie jest dowodem aktywnej integracji PWPW. Aktualny Core ma lokalną, ręcznie wprowadzaną course-scoped identity PKK; provider-specific import, live calls, konfiguracja połączenia i operacyjny UI pozostają `FROZEN_UNTIL_EXPLICIT_UNFREEZE` do czasu autorytatywnych wytycznych PWPW.
Data: 2026-09-05

## Status obserwacji

Przycisk `Zarządzaj PKK` na bieżącym panelu konkurenta został znaleziony i jest widoczny przy konkretnym kursie PKK, ale podczas audytu nie dało się otworzyć jego zawartości z powodu błędu/zawieszania strony demo/serwisu.

Nie traktujemy tego jako braku domenowego. Funkcje zostały już potwierdzone na aktualnej stronie informacyjnej integracji PKK i przez akcje widoczne przy kursie.

## Potwierdzone operacje PKK

Dla konkretnego `course_enrollment` system musi wspierać co najmniej:
- pobranie PKK,
- podgląd szczegółów pobranego profilu,
- aktualizację danych szkolenia i zwrot PKK,
- zwrot PKK do innego OSK,
- zwrot PKK do urzędu,
- zwrot profilu przedawnionego,
- historię operacji PKK.

Dodatkowo operacyjny ekran kursu potwierdza akcje:
- `Pobierz PKK`,
- `Podgląd PKK`,
- `Aktualizuj i zwróć PKK`,
- `Zarządzaj PKK`.

## Decyzja produktowa

Nie blokujemy implementacji na dokładnym wyglądzie uszkodzonego drawera konkurenta. Projektujemy własny bezpieczny drawer `Zarządzaj PKK` jako listę operacji zależnych od bieżącego stanu profilu.

Rekomendowany układ:
1. nagłówek kursanta i kursu: imię/nazwisko, kategoria, numer PKK,
2. aktualny stan integracji i ostatnia operacja,
3. sekcja `Dostępne operacje`,
4. osobny opis konsekwencji każdej operacji,
5. wymagane dane/formularz dopiero po wyborze operacji,
6. potwierdzenie przed operacją zwrotu,
7. pełny audit trail.

## Własny lifecycle operacji

Każda operacja PKK jest osobnym rekordem `pkk_operation` z polami m.in.:
- `id`,
- `organization_id`,
- `student_id`,
- `course_enrollment_id`,
- `pkk_profile_link_id`,
- `operation_type`,
- `status = draft | pending | requires_signature | submitted | success | failed | cancelled`,
- `request_payload_snapshot`,
- `response_payload_snapshot`,
- `external_reference`,
- `error_code`,
- `error_message`,
- `created_by`,
- `created_at`,
- `completed_at`.

## XML i podpis

Aktualna strona informacyjna konkurenta potwierdza flow aktualizacji przez podpis XML:
- pobierz plik XML,
- podpisz na `podpis.gov.pl`,
- wgraj podpisany plik z powrotem.

Własny system powinien traktować to jako jawny stan `requires_signature`, a nie jako zwykły upload bez kontekstu.

## Zasady bezpieczeństwa

- żadna operacja zwrotu nie może być wykonywana bez potwierdzenia,
- retry nie może tworzyć podwójnych skutków po stronie zewnętrznej,
- wszystkie request/response i błędy zapisujemy do historii operacji,
- dane pobrane z PKK zapisujemy jako snapshot integracyjny,
- dane kursanta nie są bezwarunkowo nadpisywane przez odpowiedź integracji,
- operacje są zawsze scoped do konkretnego kursu, nie tylko do osoby.

## Wniosek

Dokładny konkurencyjny drawer pozostaje `UNOBSERVABLE_UPSTREAM_ERROR`, ale zakres domenowy jest wystarczająco potwierdzony, aby moduł PKK uznać za gotowy do projektowania i implementacji po naszemu.
