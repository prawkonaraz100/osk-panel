# 08. Bezpieczeństwo i compliance — core OSK v1

Data konsolidacji: 2026-09-05

## 1. Clean-room / prawa własności

Budujemy własny produkt funkcjonalnie odpowiadający klasie usług OSK.

Nie kopiujemy:
- HTML/CSS/JS,
- backendu/API konkurenta,
- layoutu pixel-perfect,
- logo, ikon, grafik,
- treści marketingowych,
- chronionych assetów.

Mapujemy procesy biznesowe, wymagania formalne i zachowania użytkowe.

Pełny reverse-engineered scope jest chroniony przez:
- `docs/96-reverse-engineering-preservation-contract.md`,
- `specs/reverse-engineering-manifest.yml`.

Security design może zmienić sposób wykonania operacji, ale nie może po cichu usunąć potwierdzonej funkcji biznesowej.

## 2. Tenant isolation

Najważniejsza granica bezpieczeństwa aplikacji.

Każdy tenant-owned resource:
- posiada/dziedziczy `organization_id`,
- jest autoryzowany backendowo,
- waliduje wszystkie relacyjne ID względem tej samej organizacji.

UUID/ULID nie zastępuje autoryzacji.

Wymagane testy cross-tenant dla każdego modułu core.

## 3. RODO / prywatność

Wymagane:
- minimalizacja danych,
- rejestr podstaw/zgód tam, gdzie dotyczy,
- prawo dostępu/sprostowania/ograniczenia,
- controlled account closure,
- retencja per typ danych,
- rozdzielenie prawa do usunięcia od obowiązku przechowywania formalnej dokumentacji,
- DPA z procesorami,
- rejestr naruszeń,
- szyfrowanie w tranzycie i backupów.

PESEL, PKK i inne dane wrażliwe operacyjnie:
- szyfrować aplikacyjnie tam, gdzie pełna wartość musi być odtwarzalna,
- exact lookup realizować przez keyed HMAC lub równoważny mechanizm, nie zwykły SHA,
- maskować tam, gdzie pełna wartość nie jest potrzebna,
- nie logować w całości bez potrzeby,
- ograniczać permissionami.

Przed produkcją wymagany jest jawny retention schedule per data class.

## 4. Auth / sesje

Wymagania:
- secure/httpOnly/SameSite cookies,
- rotacja session ID po logowaniu i zmianach privilege,
- rate limiting login/reset,
- session management,
- możliwość revoke wszystkich sesji,
- konfigurowalna concurrent-session policy,
- alerting dla nietypowych security events.

MFA/re-auth:
- rekomendowane dla Ownera i wysokiego ryzyka,
- może być wymagane przy permissions change, PKK return, inventory adjustment, finance reversal.

Dokładnej polityki jednej sesji konkurenta nie kopiujemy jako obowiązkowej dla całego staff.

## 5. Login identity — globalna jednoznaczność

Zaobserwowany workflow drukuje kursantowi `login lub e-mail` i prowadzi do ogólnej strony logowania bez jawnego wyboru OSK.

Dlatego canonical auth layer używa globalnego `User` i `AuthLoginIdentifier`:
- jeden bieżący `identifier_normalized` rozwiązuje się do najwyżej jednego `User`,
- ten sam `User` może być powiązany z wieloma OSK,
- `StudentLearningAccount` pozostaje tenantowym kontekstem produktu, ale login resolution nie zależy od zgadywania tenant ID,
- custom username i email korzystają z tej samej przestrzeni resolution lub równoważnego jednoznacznego resolvera,
- revoked identifier nie jest automatycznie przejmowany przez inną osobę bez jawnej policy/reverification.

Nie ufamy samemu loginowi do autoryzacji zasobu — po loginie nadal obowiązuje membership/ownership/policy.

## 6. ReturnUrl / OAuth

Return URL:
- tylko lokalna/dozwolona ścieżka,
- brak schematów zewnętrznych/javascript,
- whitelist/normalization przed redirect,
- ta sama ochrona po OAuth/social callback.

Social identity:
- provider subject jest unikalny w scope providera,
- linking istniejącego konta wymaga bezpiecznej weryfikacji,
- nie łączymy kont wyłącznie po niezweryfikowanym e-mailu.

## 7. Hasła i learning accounts

Canonical model:
- `Student`,
- `StudentLearningAccount`,
- globalny `User/AuthLoginIdentifier`,
- hash hasła,
- `StudentAccessHandoff`.

Reguły:
- plaintext password może być pokazane jednorazowo przy create/reset w OSK-managed flow,
- po zapisie przechowujemy wyłącznie hash,
- nie istnieje `show old password`,
- audit zapisuje fakt resetu, nie wartość hasła,
- access handoff/PDF jest audytowany,
- ponowny PDF z hasłem wymaga nowego hasła,
- generic idempotency store nie przechowuje one-time plaintext password response.

QR nie zawiera plaintext password.

## 8. Permission-based RBAC

Źródło: `docs/04-roles-permissions.md`, `specs/security/permissions.yml`.

Backend policy order:
1. auth,
2. active membership,
3. tenant validation,
4. permission,
5. data scope,
6. domain state/rule,
7. optional re-auth/MFA.

Zmiana staff type nie może omijać permissions.

`StaffProfile` nie jest kontem logowania. Dostęp pracownika do panelu jest wiązany przez `OrganizationMembership`, dzięki czemu ten sam globalny `User` może legalnie pracować w kilku OSK bez mieszania tenant scope.

## 9. Operacje wysokiego ryzyka

Wymagają rozszerzonego audytu; część może wymagać re-auth/MFA:
- zmiana Ownera/permissions,
- PKK return/update-and-return,
- korekta formalnych godzin,
- zmiana podstawy zwolnienia,
- archiwizacja/anulowanie formalnego kursu,
- reset credentials kursanta,
- aktywacja/cofnięcie licencji,
- exam inventory adjustment,
- invalidation exam attempt,
- techniczny transfer rozpoczętego egzaminu na inne stanowisko,
- reversal student payment,
- manual payment/order correction.

## 10. Lifecycle zamiast destrukcyjnego delete

Źródło: `docs/83-core-lifecycle-policy.md`, `specs/design/core-lifecycle.yml`.

Dane formalne/finansowe:
- archive,
- cancel,
- revoke,
- reverse,
- correct,
- invalidate,
zamiast hard-delete.

Audit history pozostaje immutable.

## 11. Licencje — integralność inventory i stacking

### Revoke przed aktywacją

1. lock assignment/inventory,
2. sprawdź brak `LicenseActivation`,
3. revoke assignment,
4. restore dokładnie jedną sztukę,
5. audit/outbox,
6. commit.

Race activation vs revoke musi dać jeden zwycięski stan.

Po aktywacji ta ścieżka jest zabroniona.

### Historyczne assignmenty

Ta sama sztuka inventory może mieć wiele **historycznych** assignmentów, jeżeli wcześniejsze nieaktywowane przypisanie zostało cofnięte. Constraint blokuje tylko więcej niż jeden bieżący assignment.

### Przedłużanie / stacking

Zaobserwowano wiele aktywnych okresów przypisanych do jednego learning access. Własny model zapisuje przy aktywacji:
- `activated_at`,
- `effective_from`,
- `effective_to`.

Aktywacja/przedłużenie blokuje `StudentLearningAccount`, oblicza current entitlement end i dopiero wtedy wyznacza kolejny okres. Dwa równoległe przedłużenia nie mogą zgubić dni dostępu.

## 12. Egzamin — bezpieczeństwo, inventory i stanowisko

Źródło prawdy: `specs/design/internal-exam-lifecycle.yml`.

Remote access:
- opaque cryptographically strong token,
- token nie zawiera PII,
- raw token przechowywany co najwyżej jednorazowo w response/handoff, DB trzyma hash,
- expiry,
- revoke przed startem,
- brak parallel double start.

Inventory:
- reserve przy access creation,
- **consume atomowo przy start**,
- release przed startem po revoke/expiry/cancel,
- released reservation nie blokuje późniejszego wykorzystania tej samej sztuki,
- po start technical_abort nie oddaje automatycznie sztuki,
- correction po starcie tylko z elevated permission + reason + audit.

### Stanowisko lokalne

Zaobserwowany panel komunikuje, że lokalny egzamin jest dostępny dla jednego kursanta jednocześnie.

Własny model:
- lokalny start rozwiązuje stabilne `ExamStation`,
- jedno stanowisko ma najwyżej jedną aktywną `InternalExamStationSession`,
- jedna próba ma najwyżej jedną aktywną station session,
- awaria przed startem może skutkować revoke/reissue na inne stanowisko,
- failover **po starcie** wymaga jawnego `technical_transfer`, zamknięcia starej station session, otwarcia nowej i pełnego audytu,
- transfer nie konsumuje drugiej sztuki egzaminu.

Attempt snapshot:
- pytania,
- odpowiedzi,
- dane kandydata,
- requirement basis,
- category/language,
- wynik
muszą zachować historyczną integralność.

## 13. PKK — payloady i integracja

PKK jest course-first.

Wymagania:
- credentials/secrets poza repo,
- pełny numer PKK szyfrowany, exact lookup przez keyed hash,
- minimalizacja payload logging,
- `profile_snapshot` z danymi osobowymi nie może być bezrefleksyjnie trzymany jako plaintext JSONB,
- preferowany model: minimalne normalizowane pola + encrypted full snapshot tylko, jeśli jest biznesowo potrzebny,
- UI może korzystać z redacted/minimized projection,
- provider response w integration logu jest redacted,
- encrypted raw response tylko, gdy retencja jest uzasadniona,
- idempotency dla mutacji,
- operation + attempt model,
- retry matrix,
- request/correlation ID,
- reconciliation po timeout/unknown state,
- audit wszystkich rezultatów bez kopiowania wrażliwego provider payloadu do audit log.

Błąd walidacji/biznesowy nie jest automatycznie retryowany jak timeout.

Flow wymagający podpisania XML może posiadać bezpieczny handoff `download -> external signature -> upload`, ale szczegóły nieobserwowalnego konkurencyjnego drawera nie są odtwarzane jako fakt.

## 14. Student finance

- money decimal/minor units,
- server-side balance,
- payment recording idempotent,
- reversal zamiast destrukcyjnej edycji,
- no hard-delete historii finansowej,
- tenant/course/student relation validation,
- elevated permission dla reversal.

## 15. Platform payments

- brak danych kart w aplikacji,
- provider tokenization,
- webhook signature verification,
- idempotent event handling,
- provider event dedup po `(provider, provider_event_id)`,
- event zapisany/deduplicated przed business effect,
- reconciliation job,
- order item price/VAT snapshot,
- audyt manual adjustments.

Dla `activation_mode=explicit` payment success nie oznacza automatycznej aktywacji usługi.

## 16. Wspólna idempotency persistence

Sam nagłówek `Idempotency-Key` nie wystarcza.

Wymagany store, np. `idempotency_records`:
- tenant/operation/key,
- request hash,
- processing/completed state,
- bezpieczny result reference,
- expiry/retention.

Reguły:
- pierwszy request atomowo claimuje key,
- ten sam key + ten sam payload nie powtarza skutku biznesowego,
- ten sam key + inny payload -> conflict,
- response snapshot nie zawiera plaintext password, tokenów ani pełnych danych wrażliwych.

## 17. Kalendarz

Backend waliduje:
- tenant resources,
- resource conflicts,
- permitted status transitions.

Self-booking:
- atomic booking,
- brak podwójnej rezerwacji slotu.

Nieważny dokument pracownika/pojazdu może być warning albo hard block zależnie od formalnej reguły/config — decyzja musi być jawna, nie wyłącznie frontendowa.

## 18. Uploads / FileAsset

Dla zdjęć/PDF/załączników:
- `FileAsset` jest osobnym tenant-aware rekordem,
- storage key jest niezgadywalny i nie jest nazwą pliku od użytkownika,
- presigned upload tam, gdzie stosowany,
- whitelist MIME + real content sniffing,
- size limits,
- purpose-bound upload,
- malware/content scan tam, gdzie uzasadnione,
- business entity może przypiąć plik dopiero w stanie `ready`,
- brak wykonywalnych plików z user content,
- download zawsze przechodzi authorization albo krótkotrwały signed URL,
- usunięcie business entity nie może przypadkiem skasować formalnego dokumentu wymagającego retencji.

## 19. Audit log vs dashboard activity feed

### AuditLog

Minimum:
- `actor_user_id`,
- `organization_id`,
- `action`,
- `entity_type`,
- `entity_id`,
- redacted/allow-listed before/after lub domain-specific diff,
- `request_id`,
- reason dla elevated correction,
- IP/hash zgodnie z polityką,
- user agent jeśli potrzebny,
- timestamp.

Audit:
- nie jest edytowalny przez zwykły panel,
- nie zawiera plaintext passwords/secrets/tokens,
- pełny PESEL/PKK jest domyślnie zabroniony,
- ma retencję zgodną z legal/privacy policy.

### OrganizationActivityEvent

Dashboardowy activity feed **nie czyta surowego audit payloadu bezpośrednio**.

Wymagamy osobnej safe projection:
- allow-listed `event_type`,
- actor,
- subject/related student/entity,
- bezpieczny opis,
- safe metadata,
- timestamp,
- opcjonalny redacted before/after dla zaobserwowanego `Rozwiń`.

Projection jest tworzona z committed domain/outbox eventu. PESEL, PKK, hasła, tokeny i provider secrets są zabronione w safe payload.

## 20. Logs / observability privacy

Application logs nie są drugim audit logiem i nie powinny zawierać pełnych payloadów osobowych.

Maskować/tokenizować:
- PESEL,
- e-mail, jeśli pełny niepotrzebny,
- PKK/identyfikatory wrażliwe według potrzeb,
- tokens.

Provider request/response logging używa redaction policy.

## 21. Organization settings i accepted terms

- ustawienia organizacji są osobnym tenant-owned rekordem/projekcją,
- wersje dokumentów prawnych mają immutable hash/version,
- akceptacja regulaminu tworzy append-only `TermsAcceptance`,
- zmiana aktualnego regulaminu nie nadpisuje historii starej akceptacji,
- UI może pobrać/wyświetlić konkretną zaakceptowaną wersję.

## 22. Secrets / config

- żadnych `.env` z sekretami w repo,
- secret manager / secure environment injection,
- rotacja,
- osobne secrets per environment,
- provider credentials z least privilege.

## 23. Backup / DR

Przed produkcją:
- automatyczne backupy,
- restore tests,
- RPO/RTO,
- object storage protection,
- runbook incident/recovery.

Szczegóły: `docs/85-production-operations.md`.

## 24. Elementy regulaminowe konkurenta, których nie kopiujemy bez własnej decyzji

- dokładne terminy zwrotów/reklamacji,
- retencja konkurenta,
- pojedyncza sesja dla każdego typu konta,
- moderator workflow konkurenta,
- szczegółowe polityki reklamowe niepotrzebne core.

Są dowodem na istnienie klasy problemu, nie gotową polityką PrawkoNaRaz.
