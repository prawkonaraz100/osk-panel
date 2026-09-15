# 133. Privacy and retention schedule

Data weryfikacji: 2026-09-12

Status: **ACTIVE HARDENING AUTHORITY**

## 1. Cel i odpowiedzialność

Polityka określa, jak długo PrawkoNaRaz/OSK może przechowywać poszczególne klasy danych w core v1.

Dla danych kursanta związanych z formalnym szkoleniem administratorem co do zasady jest OSK, a platforma wykonuje przetwarzanie w granicach ustalonej roli i umowy. Dla danych stricte platformowych rola administratora może należeć do operatora platformy.

Nie stosujemy jednego globalnego okresu usuwania.

## 2. Zasada RODO

Art. 5 ust. 1 lit. e RODO wymaga przechowywania danych umożliwiających identyfikację tylko tak długo, jak jest to niezbędne do celu. UODO wskazuje również, że brak ustawowego terminu nie pozwala na przechowywanie bezterminowe — administrator powinien ustalić konkretny, uzasadniony termin i procedurę przeglądu/usuwania.

W systemie każda klasa danych ma własny clock, legal hold lub incident hold blokuje zwykły purge, a po wygaśnięciu podstawy pozostaje tylko minimalny zakres wymagany przez następny cel lub podstawę.

## 3. Formalna dokumentacja OSK

### Książka ewidencji osób szkolonych — 10 lat

§ 18 ust. 1 rozporządzenia szkoleniowego wymaga przechowywania książki ewidencji osób szkolonych przez 10 lat od daty ostatniego wpisu.

W modelu elektronicznym oznacza to zachowanie minimalnego formalnego zestawu pozwalającego odtworzyć ewidencję. Nie oznacza to 10-letniej retencji każdego pola kontaktowego, logu technicznego, tokenu ani pełnego payloadu integracji.

### Karta przeprowadzonych zajęć — 24 miesiące

§ 18 ust. 2–3 wymaga przechowywania kart przeprowadzonych zajęć i ich odpowiedników przez 24 miesiące od ostatniego wpisu, a następnie zniszczenia. Przed zniszczeniem wymagane podsumowanie godzin musi znaleźć się w ewidencji.

## 4. Finanse

Dokumentacja objęta ustawą o rachunkowości ma co najmniej okres wymagany w art. 74. Dla podstawowych ksiąg i dowodów polityka przyjmuje 5 lat liczonych zgodnie z ustawową regułą od początku roku następującego po właściwym roku obrotowym.

Legal hold, postępowanie lub nierozliczona transakcja może wydłużyć przechowywanie.

## 5. Purpose-based i techniczne klasy danych

Własne wartości polityki, nie przedstawiane jako ustawowe terminy:

| Klasa | Domyślny termin | Anchor |
|---|---:|---|
| dane kontaktowe kursanta | 30 dni | zakończenie wszystkich operacyjnych relacji |
| lokalny pełny PKK/HMAC | 24 miesiące | terminalny stan kursu |
| szczegóły próby egzaminu | 24 miesiące | terminalny stan próby |
| audit/security evidence | 36 miesięcy | zdarzenie |
| auth sessions | 90 dni | revoke/expiry/terminal last_seen |
| activity + notifications | 12 miesięcy | created/occurred |
| idempotency record | 30 dni | completed |
| published outbox | 30 dni | published_at; tylko terminalne published |
| unattached temporary asset | 7 dni | created_at |
| application logs | 30 dni | event |
| security logs | 90 dni | event |

Te wartości są konfiguracyjną decyzją privacy-by-design. Każde przedłużenie dla konkretnego procesu wymaga udokumentowanej podstawy.

## 6. Minimalizacja zamiast kasowania formalnej historii

Nie wolno rozwiązywać retencji przez destrukcyjne usunięcie całego Student/Course, jeżeli część pól nadal jest potrzebna do ustawowej ewidencji.

Docelowy executor powinien ustalić data class, odczytać wersję polityki, policzyć cutoff po stronie serwera, sprawdzić hold, wykonać dry-run, a następnie usunąć lub zanonimizować wyłącznie zakres, którego podstawa wygasła.

## 7. Audit, outbox i projekcje

Audit pozostaje append-only dla zwykłych ról. Legalna retencja jest osobnym uprzywilejowanym procesem.

Outbox może być czyszczony tylko dla publication_state=published. Pending, leased i reconciliation nie są usuwane przez TTL. Purge outbox nie może kaskadowo usuwać business authority.

Activity i notification są projekcjami; ich retencja nie zmienia źródłowych faktów biznesowych.

## 8. PKK provider

Provider-specific runtime pozostaje odroczony. Do czasu wiążących wytycznych lub umowy PWPW nie zbieramy surowych provider payloadów na zapas i nie ustalamy zmyślonej retencji provider response.

## 9. Executor poza historycznym H3

H3 zamknął decyzję o terminach i klasach danych, ale nie upoważnił zwykłego runtime do hard-delete.

Po finalnej materializacji Stage 4 tabela `data_retention_execution_runs` jest już obecna i pozostaje append-only dla normalnego runtime. Późniejszy gate `PROD-RETENTION-EXECUTOR-001` może materializować wyłącznie dedykowany privileged path w granicach jawnego allowlistu.

Pierwszy zakres tego executora jest ograniczony do technical TTL `idempotency_records`. Nie daje on zwykłym rolom aplikacyjnym prawa do purge, nie omija write-fence outbox/audit/domain-event i nie autoryzuje kasowania formalnej historii kursanta.

## 10. Źródła urzędowe

Zweryfikowane 12 września 2026:
- UODO — zasada ograniczenia przechowywania i obowiązek ustalenia terminów/procedur: https://uodo.gov.pl/pl/676/4260
- ELI — rozporządzenie szkoleniowe, § 18: https://eli.gov.pl/api/acts/DU/2018/1885/text.html
- ELI — obowiązujący tekst jednolity ustawy o rachunkowości z 2026 r.: https://eli.gov.pl/eli/DU/2026/522/ogl
- ELI — Kodeks cywilny art. 118 jako kontekst legal-hold/roszczeń: https://eli.gov.pl/api/acts/DU/2024/1061/text.html

## 11. Machine authority

- runtime-readable policy: config/retention.php
- machine specification: specs/privacy/retention-schedule.yml
- executable contract: Tests\\Unit\\PrivacyRetentionPolicyTest

Polityka musi być wersjonowana. Zmiana terminu nie może po cichu przepisać historycznych business facts ani ominąć legal hold.

## 12. Closure evidence

Hardening H3 is closed on accepted implementation commit:

- accepted commit: ecf56cc19106a4a8d37d3c79425d8b8adf5a6692
- accepted tree: 9cc5466b9a84fdd1b84be0a7b56fc8dcc699cb90
- validation helper: e08656115d42607a71277651b35b978b5abbd9dd
- helper Implementation CI #256: 5/5 PASS
- accepted Implementation CI #257: 5/5 PASS
- accepted PostgreSQL suite: 173 tests / 2635 assertions
- backend Pint + PHPStan: PASS
- frontend lint + typecheck + build + audit: PASS
- secret scan: PASS
- contracts and traceability: PASS

H3 resolves the final open P0 privacy/retention schedule decision. It does not materialize the privileged retention executor and does not authorize ordinary application roles to purge retained business history.

Next production-hardening tranche: RPO/RTO and backup/restore authority.

