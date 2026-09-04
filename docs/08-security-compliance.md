# 08. Bezpieczeństwo i compliance

## Clean-room / prawa własności

Publiczny regulamin analizowanego serwisu zastrzega prawa do treści, materiałów, elementów graficznych i całości strony. Dlatego:

- nie kopiujemy HTML/CSS/JS,
- nie kopiujemy tekstów marketingowych,
- nie kopiujemy logo, ikon i grafik,
- nie odtwarzamy pixel-perfect chronionego trade dress,
- dokumentujemy funkcję i budujemy własny UX,
- publiczne pytania egzaminacyjne traktujemy osobno, zgodnie z ich podstawą prawną i źródłem.

## RODO / prywatność

Wymagane:
- rejestr zgód (`consents`),
- informacja o celu przetwarzania,
- minimalizacja danych,
- realizacja żądań dostępu/sprostowania/usunięcia/ograniczenia,
- obsługa żądania zamknięcia konta jako kontrolowany proces, nie `DELETE user` z UI,
- retencja zależna od typu danych i obowiązków prawnych,
- DPA z dostawcami,
- rejestr naruszeń,
- szyfrowanie danych w tranzycie i backupów.

### Dane ofert reklamowych
Publiczny regulamin opisuje historię ofert aukcyjnych pokazującą nazwę OSK oraz możliwość wnioskowania o ukrycie tej nazwy. Własny model musi zatem rozdzielać:
- wartość oferty i czas — dane systemowe,
- identyfikację oferenta — warstwa prezentacyjna z polityką prywatności,
- request ukrycia nazwy — audytowalny proces, nie usunięcie rekordu bid.

## Sesje i logowanie

### Potwierdzone źródłowo
Regulamin opisuje zasadę jednej aktywnej sesji dla opłaconego konta użytkownika: uruchomienie nowej sesji kończy poprzednią. Nie mamy jednak dowodu, że identyczna polityka dotyczy wszystkich kont personelu BIZ.

### Nasze wymagania
- MFA dla Owner/Admin — rekomendowane,
- secure/httpOnly/sameSite cookies,
- rotacja session ID po logowaniu i zmianie uprawnień,
- device/session management,
- rate limiting logowania i resetu hasła,
- alert o nietypowych logowaniach,
- możliwość unieważnienia wszystkich sesji,
- configurable concurrent session policy per account type,
- log `SessionCreated`, `SessionRevoked`, `SessionReplaced`.

### ReturnUrl
Chronione trasy zachowują ReturnUrl. Własna implementacja musi:
- akceptować wyłącznie lokalne/dozwolone ścieżki,
- odrzucać URL-e zewnętrzne i schematy `javascript:` itp.,
- nie przepuszczać otwartego przekierowania po OAuth/social login.

## Hasła / dane dostępowe kursanta

Ponieważ OSK może tworzyć dostęp przez login i hasło:
- nigdy nie przechowujemy hasła jawnie,
- hasło jednorazowe/tymczasowe powinno wymuszać bezpieczny lifecycle,
- jeśli dane dostępowe muszą być pokazane/drukowane, ich odtworzenie powinno być kontrolowane; preferowany jest mechanizm ustawienia nowego hasła zamiast odczytu starego,
- logujemy utworzenie dostępu, ale nie wartość hasła,
- kanał e-mail i kanał `generated_credentials` są różnymi metodami provisioningu.

## Operacje wysokiego ryzyka

Dodatkowe potwierdzenie i audit log dla:
- zmiany Ownera,
- zmiany ról/permissions,
- zwrotu PKK,
- ręcznej korekty wyników/dokumentów,
- usuwania/archiwizacji kursanta,
- cofnięcia nieaktywowanej licencji,
- zamknięcia/blokady konta,
- impersonacji kursanta,
- ręcznych operacji finansowych,
- akcji operatora na wiążącej ofercie reklamowej,
- akceptacji/odrzucenia kreacji reklamowej.

`refund` jest procesem własnego systemu finansowego, a nie potwierdzonym przyciskiem badanego panelu.

## Licencje — integralność inventory

Operacja usunięcia nieaktywowanego przydziału musi być atomowa:

1. lock assignment/inventory,
2. sprawdzenie `activated_at IS NULL`,
3. zmiana statusu assignment,
4. przywrócenie jednej sztuki inventory,
5. audit log,
6. commit.

Race condition między aktywacją a cofnięciem musi kończyć się jednym jednoznacznym rezultatem. Po aktywacji cofnięcie tą ścieżką jest zabronione.

## Jawna aktywacja dostępu po płatności

Dla produktów z `activation_mode = explicit`:
- webhook płatności ustawia `paid/activation_available`, nie `activated`,
- użytkownik wykonuje osobną akcję aktywacji,
- aktywacja ma timestamp i actor,
- wielokrotne kliknięcie musi być idempotentne,
- nie można rozpocząć okresu dostępu dwa razy.

## Aukcje reklam — integralność i wiążące oferty

### Bid placement
- bid zapisuje serwerowy timestamp,
- sprawdzenie `auction.status = open`,
- atomowa kontrola minimalnego przebicia,
- brak możliwości zwykłego `DELETE bid` przez klienta,
- prośba o odrzucenie oferty jest osobnym requestem do operatora,
- tie-break przy identycznej wartości oparty o kolejność serwerową,
- zamknięcie aukcji powinno być transakcją/lockiem uniemożliwiającym late bid.

### Wygrana
- rozdzielić `winner selected`, `payment confirmed`, `creative received`, `creative approved`, `campaign active`,
- emisja nie może ruszyć bez warunków produktowych,
- deadline płatności/kreacji jest parametrem reguły produktu, nawet jeśli bieżący regulamin opisuje 3 dni robocze,
- każda zmiana wyniku aukcji przez operatora wymaga audytu.

### Kreacje
- skan plików,
- whitelist MIME + real content sniffing,
- limity rozmiaru/wymiarów,
- moderacja,
- wersjonowanie desktop/mobile,
- prawa do materiałów są odpowiedzialnością biznesową klienta, ale system powinien przechowywać potwierdzenie oświadczenia/warunków.

## Egzamin wewnętrzny

- link/token egzaminu powinien być nieprzewidywalny,
- dokładny TTL/unieważnianie nie jest publicznie potwierdzone — to nasza decyzja bezpieczeństwa,
- sesja egzaminu jest jednorazowa,
- odpowiedzi/wynik zapisujemy z integralnością i timestampami,
- karta przebiegu jest dokumentem generowanym z wersjonowanych danych,
- egzamin zostaje wykorzystany zgodnie z potwierdzonym flow po przeprowadzeniu.

## PKK

- sekrety i dane uwierzytelniające integracji poza repo,
- minimalizacja logowania payloadów z danymi osobowymi,
- idempotency key dla operacji nieodwracalnych,
- retry tylko przy bezpiecznych klasach błędów,
- korelacja request/response przez `request_id`,
- pełny audit rezultatów.

## Impersonacja

`Przeglądaj jako kursant` jest historycznie zindeksowaną funkcją; dokładny zakres jest `TO_VERIFY_AUTH`.

Własny bezpieczny odpowiednik:
- osobny permission,
- stale widoczny banner,
- start/stop w audycie,
- zakaz eskalacji uprawnień,
- jawne blokowanie akcji płatniczych, zmian hasła, aktywacji usług i innych działań nieodwracalnych, chyba że istnieje osobny uzasadniony tryb supportowy.

## Audyt

`audit_logs`:
- `actor_user_id`,
- `organization_id`,
- `action`,
- `entity_type`,
- `entity_id`,
- `before_json`,
- `after_json`,
- `request_id`,
- `ip_hash`/IP wg polityki,
- `user_agent`,
- `created_at`.

Log audytowy nie powinien być edytowalny przez zwykły panel.

## Płatności

- nie przechowujemy danych kart,
- korzystamy z tokenizacji/operatora płatności,
- webhook signature verification,
- idempotency key,
- reconciliation job,
- osobny ledger zdarzeń płatniczych,
- potwierdzenie przelewu nie może samo zastępować właściwego reconciliation bez odpowiedniej reguły/operatora,
- ceny i VAT wersjonowane na `order_item`, aby późniejsza zmiana cennika nie zmieniała historycznego zamówienia.

## Elementy regulaminowe, których nie kopiujemy jako polityki bez decyzji biznesowej

- dokładne limity/terminy zwrotów,
- dokładny okres archiwizacji danych statystycznych,
- konkretne terminy płatności reklam,
- limity znaków/liczby zdjęć artykułu,
- pojedyncza sesja dla wszystkich typów kont,
- zasady moderatora/operatora konkurencyjnego serwisu.

Są one dowodem na potrzebę obsługi danych stanów/procesów, ale polityka PrawkoNaRaz musi mieć własną podstawę prawną i własny regulamin.
