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

PESEL i inne dane wrażliwe operacyjnie:
- maskować tam, gdzie pełna wartość nie jest potrzebna,
- nie logować w całości bez potrzeby,
- ograniczać permissionami.

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

## 5. ReturnUrl / OAuth

Return URL:
- tylko lokalna/dozwolona ścieżka,
- brak schematów zewnętrznych/javascript,
- whitelist/normalization przed redirect,
- ta sama ochrona po OAuth/social callback.

## 6. Hasła i learning accounts

Canonical model:
- `Student`,
- `StudentLearningAccount`,
- auth identity/hash,
- `StudentAccessHandoff`.

Reguły:
- plaintext password może być pokazane jednorazowo przy create/reset w OSK-managed flow,
- po zapisie przechowujemy wyłącznie hash,
- nie istnieje `show old password`,
- audit zapisuje fakt resetu, nie wartość hasła,
- access handoff/PDF jest audytowany,
- ponowny PDF z hasłem wymaga nowego hasła.

QR nie zawiera plaintext password.

## 7. Permission-based RBAC

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

## 8. Operacje wysokiego ryzyka

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
- reversal student payment,
- manual payment/order correction.

## 9. Lifecycle zamiast destrukcyjnego delete

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

## 10. Licencje — integralność inventory

Revoke przed aktywacją:
1. lock assignment/inventory,
2. sprawdź `activated_at IS NULL`,
3. revoke assignment,
4. restore dokładnie jedną sztukę,
5. audit/outbox,
6. commit.

Race activation vs revoke musi dać jeden zwycięski stan.

Po aktywacji ta ścieżka jest zabroniona.

## 11. Egzamin — bezpieczeństwo i inventory

Źródło prawdy: `specs/design/internal-exam-lifecycle.yml`.

Remote access:
- opaque cryptographically strong token,
- token nie zawiera PII,
- expiry,
- revoke przed startem,
- brak parallel double start.

Inventory:
- reserve przy access creation,
- **consume atomowo przy start**,
- release przed startem po revoke/expiry/cancel,
- po start technical_abort nie oddaje automatycznie sztuki,
- correction po starcie tylko z elevated permission + reason + audit.

Attempt snapshot:
- pytania,
- odpowiedzi,
- dane kandydata,
- requirement basis,
- category/language,
- wynik
muszą zachować historyczną integralność.

## 12. PKK

PKK jest course-first.

Wymagania:
- credentials/secrets poza repo,
- minimalizacja payload logging,
- idempotency dla mutacji,
- operation + attempt model,
- retry matrix,
- request/correlation ID,
- reconciliation po timeout/unknown state,
- audit wszystkich rezultatów.

Błąd walidacji/biznesowy nie jest automatycznie retryowany jak timeout.

## 13. Student finance

- money decimal/minor units,
- server-side balance,
- payment recording idempotent,
- reversal zamiast destrukcyjnej edycji,
- no hard-delete historii finansowej,
- tenant/course/student relation validation,
- elevated permission dla reversal.

## 14. Platform payments

- brak danych kart w aplikacji,
- provider tokenization,
- webhook signature verification,
- idempotent event handling,
- provider event ID uniqueness,
- reconciliation job,
- order item price/VAT snapshot,
- audyt manual adjustments.

Dla `activation_mode=explicit` payment success nie oznacza automatycznej aktywacji usługi.

## 15. Kalendarz

Backend waliduje:
- tenant resources,
- resource conflicts,
- permitted status transitions.

Self-booking:
- atomic booking,
- brak podwójnej rezerwacji slotu.

Nieważny dokument pracownika/pojazdu może być warning albo hard block zależnie od formalnej reguły/config — decyzja musi być jawna, nie wyłącznie frontendowa.

## 16. Uploads

Dla zdjęć/PDF/załączników:
- presigned upload,
- whitelist MIME,
- real content sniffing,
- size limits,
- tenant ownership,
- purpose-bound upload,
- malware scan tam, gdzie uzasadnione,
- brak wykonywalnych plików z user content.

## 17. Audit log

Minimum:
- `actor_user_id`,
- `organization_id`,
- `action`,
- `entity_type`,
- `entity_id`,
- before/after lub domain-specific diff,
- `request_id`,
- reason dla elevated correction,
- IP/hash zgodnie z polityką,
- user agent jeśli potrzebny,
- timestamp.

Audit:
- nie jest edytowalny przez zwykły panel,
- nie zawiera plaintext passwords/secrets,
- ma retencję zgodną z legal/privacy policy.

## 18. Logs / observability privacy

Application logs nie są drugim audit logiem i nie powinny zawierać pełnych payloadów osobowych.

Maskować/tokenizować:
- PESEL,
- e-mail, jeśli pełny niepotrzebny,
- PKK/identyfikatory wrażliwe według potrzeb,
- tokens.

## 19. Secrets / config

- żadnych `.env` z sekretami w repo,
- secret manager / secure environment injection,
- rotacja,
- osobne secrets per environment,
- provider credentials z least privilege.

## 20. Backup / DR

Przed produkcją:
- automatyczne backupy,
- restore tests,
- RPO/RTO,
- object storage protection,
- runbook incident/recovery.

Szczegóły: `docs/85-production-operations.md`.

## 21. Elementy regulaminowe konkurenta, których nie kopiujemy bez własnej decyzji

- dokładne terminy zwrotów/reklamacji,
- retencja konkurenta,
- pojedyncza sesja dla każdego typu konta,
- moderator workflow konkurenta,
- szczegółowe polityki reklamowe niepotrzebne core.

Są dowodem na istnienie klasy problemu, nie gotową polityką PrawkoNaRaz.
