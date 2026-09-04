# 08. Bezpieczeństwo i compliance

## Clean-room / prawa własności

Publiczny regulamin analizowanego serwisu zastrzega prawa do treści, materiałów, elementów graficznych i całości strony. Dlatego:

- nie kopiujemy HTML/CSS/JS,
- nie kopiujemy tekstów marketingowych,
- nie kopiujemy logo, ikon i grafik,
- nie odtwarzamy pixel-perfect chronionego trade dress,
- dokumentujemy funkcję i budujemy własny UX,
- publiczne pytania egzaminacyjne traktujemy osobno, zgodnie z ich podstawą prawną i źródłem.

## RODO

Wymagane:
- rejestr zgód (`consents`),
- informacja o celu przetwarzania,
- minimalizacja danych,
- eksport danych osoby,
- realizacja żądań dostępu/sprostowania/usunięcia/ograniczenia,
- retencja zależna od typu danych i obowiązków prawnych,
- DPA z dostawcami,
- rejestr naruszeń,
- szyfrowanie danych w tranzycie i backupów.

## Sesje i logowanie

- MFA dla Owner/Admin — rekomendowane,
- secure/httpOnly/sameSite cookies,
- rotacja sesji po logowaniu i zmianie uprawnień,
- device/session management,
- rate limiting logowania,
- alert o nietypowych logowaniach,
- możliwość unieważnienia wszystkich sesji.

## Operacje wysokiego ryzyka

Dodatkowe potwierdzenie i audit log dla:
- zmiany Ownera,
- zmiany ról,
- zwrotu PKK,
- ręcznej korekty wyników/dokumentów,
- usuwania/archiwizacji kursanta,
- refundów,
- impersonacji kursanta.

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

Log audytowy nie powinien być edytowalny przez panel.

## Płatności

- nie przechowujemy danych kart,
- korzystamy z tokenizacji/operatora płatności,
- webhook signature verification,
- idempotency key,
- reconciliation job,
- osobny ledger zdarzeń płatniczych.
