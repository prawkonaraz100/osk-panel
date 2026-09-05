# 39. Kursanci — potwierdzenie usunięcia licencji

Data weryfikacji: 2026-09-05

**Kontekst:** karta kursanta -> sekcja `Licencje` -> akcja `Usuń` przy nieaktywowanej licencji  
**Źródło:** bieżący zalogowany ekran + screenshot przekazany podczas audytu  
**Status:** `USER_CONFIRMED_AUTH_SCREEN`

---

## 1. Znaczenie ekranu

Kliknięcie `Usuń` przy licencji nie usuwa jej natychmiast. System otwiera osobny modal potwierdzający.

Tytuł:
- `Usuwanie licencji`.

Treść potwierdzenia:
- `Czy na pewno chcesz usunąć licencje?`

Potwierdzone akcje:
- `Tak, usuń`,
- `Nie, anuluj`,
- zamknięcie `X`.

---

## 2. Potwierdzony confirmation flow

Flow na poziomie UI:

1. administrator widzi nieaktywowaną licencję na karcie kursanta,
2. wybiera `Usuń`,
3. pojawia się modal `Usuwanie licencji`,
4. administrator musi jawnie potwierdzić `Tak, usuń` albo anulować,
5. anulowanie nie powinno wykonywać mutacji.

To potwierdza, że akcja jest destrukcyjna i wymaga dodatkowego potwierdzenia.

---

## 3. Czego ekran NIE potwierdza

Sam modal nie pokazuje skutku backendowego po `Tak, usuń`.

Nadal nie potwierdzono na tym ekranie:
- czy rekord `license_assignment` jest usuwany fizycznie czy oznaczany jako revoked,
- czy dokładnie jedna sztuka wraca do puli OSK,
- czy zwrot następuje atomowo w tej samej transakcji,
- czy usuwany jest również `learning_access`, jeśli nie ma innych licencji,
- czy istnieje możliwość odtworzenia/cofnięcia tej operacji,
- jakie komunikaty success/error pojawiają się po potwierdzeniu.

Nie wolno więc na podstawie samego modala utożsamiać `Usuń licencję` z hard-delete.

---

## 4. Własna implementacja

Dla naszego produktu bezpieczniejszy model to:
- `license_assignment.status = revoked_before_activation`,
- zachowanie historii przydzielenia i cofnięcia,
- zwrot dokładnie jednej niewykorzystanej sztuki do inventory/ledgera,
- operacja atomowa,
- audit log.

Rekomendowany flow domenowy:

`not_activated -> revoke assignment -> restore exactly one inventory credit -> audit -> commit`

Jeżeli którykolwiek krok się nie powiedzie, cała operacja powinna zostać wycofana.

`learning_access` nie powinien być automatycznie usuwany tylko dlatego, że cofnięto jedną licencję. Dostęp może posiadać inne lub przyszłe licencje.

Ta część jest decyzją naszej architektury; nie przypisujemy konkurentowi technicznego sposobu implementacji bez obserwacji skutku.

---

## 5. Potwierdzone akcje

- `open_delete_unactivated_license_confirmation`
- `confirm_delete_unactivated_license`
- `cancel_delete_unactivated_license`
- `close_delete_unactivated_license_confirmation`

---

## 6. Wymagania bezpieczeństwa

Własna implementacja musi:
- potwierdzić po stronie serwera, że licencja należy do bieżącego OSK,
- potwierdzić, że przydzielenie nadal jest nieaktywowane,
- nie ufać statusowi przesłanemu przez frontend,
- wykonać revoke + inventory restore atomowo,
- być odporna na podwójne kliknięcie/retry,
- zapisać kto i kiedy cofnął przydzielenie,
- nie pozwolić przywrócić dwóch sztuk za jedno przydzielenie.

---

## 7. Pozostałe niewiadome konkurenta

- dokładny skutek po `Tak, usuń`,
- komunikat po sukcesie,
- komunikat błędu,
- czy zwrot do puli jest widoczny od razu,
- czy istnieje historia cofniętych licencji,
- zachowanie przy równoczesnej aktywacji i próbie usunięcia.

Te niewiadome nie muszą blokować naszego projektu — możemy zastosować własny audytowalny lifecycle.

---

## 8. Acceptance criteria

### AC-LIC-DEL-01 — confirmation
Usunięcie/cofnięcie nieaktywowanej licencji wymaga jawnego potwierdzenia.

### AC-LIC-DEL-02 — cancel
`Nie, anuluj` oraz `X` nie wykonują mutacji.

### AC-LIC-DEL-03 — server-side state check
Backend sprawdza, że przydzielenie nadal jest nieaktywowane w momencie potwierdzenia.

### AC-LIC-DEL-04 — atomic inventory restore
W naszym produkcie cofnięcie przed aktywacją zwraca dokładnie jedną sztukę do inventory w tej samej transakcji.

### AC-LIC-DEL-05 — audit
Operacja jest audytowana i zachowuje historię przydzielenia oraz cofnięcia.
