# 132. Legal driving-entitlement dictionary

Data weryfikacji: 2026-09-12

Status: **LEGAL VERIFIED / ACTIVE HARDENING AUTHORITY**

## 1. Cel

Ten dokument rozstrzyga otwarty P0 z `docs/95-open-items-severity.md`: jaki słownik może być aktywnym wejściem do produkcyjnego rule-engine kategorii prawa jazdy.

## 2. Zweryfikowany słownik kategorii prawa jazdy

Aktualne źródło Ministerstwa Infrastruktury oraz obowiązujący tekst ustawy o kierujących pojazdami potwierdzają następujące kategorie prawa jazdy:

`AM, A1, A2, A, B1, B, B+E, C1, C, C1+E, C+E, D1, D, D1+E, D+E, T`.

To dokładnie **16** aktywnych kodów produkcyjnego słownika `driving_categories`.

## 3. Tramwaj nie jest siedemnastą kategorią prawa jazdy

Ustawa rozdziela prawo jazdy od dokumentu **„pozwolenie na kierowanie tramwajem”**. Pozwolenie jest odrębnym dokumentem/uprawnieniem.

W clean-room evidence formularza kursu zaobserwowano opcję techniczną `PT`. Tego dowodu nie usuwamy. Interpretujemy go jednak wyłącznie jako alias UI/historyczny ślad uprawnienia tramwajowego:

- `PT` pozostaje rekordem zachowującym evidence,
- `PT.active = false`,
- `PT.metadata.entitlement_kind = tram_permit`,
- `PT.metadata.rule_engine_eligible = false`,
- aktywny endpoint katalogu kategorii nie zwraca `PT`,
- walidacja kursu oraz held-categories odrzuca `PT` przez istniejącą zasadę `active = true`.

Jeżeli produkt będzie później obsługiwał formalne szkolenie tramwajowe, wymaga to osobnego modelu entitlement/training i osobnej bramki prawnej. Nie należy wciskać tramwaju do obecnego rule-engine kategorii prawa jazdy.

## 4. Źródła urzędowe

Zweryfikowano 12 września 2026 r.:

- Ministerstwo Infrastruktury, Gov.pl — „Kategorie prawa jazdy”,
- ISAP — Dz.U. 2025 poz. 1226, jednolity tekst ustawy o kierujących pojazdami; w tym odrębny reżim pozwolenia na kierowanie tramwajem.

Repozytorium nie kopiuje pełnych opisów uprawnień z tych źródeł; utrzymuje jedynie własny słownik kodów i klasyfikację potrzebną do walidacji.

## 5. Granice tej decyzji

Ten gate nie rozstrzyga:

- minimalnego wieku i wyjątków związanych z kwalifikacją zawodową,
- mapowania kategorii do zewnętrznego providera PKK,
- runtime PKK/PWPW,
- formalnego kursu tramwajowego,
- szczegółowych minimów szkoleniowych już objętych osobnym legal rule artifact.

Provider-specific PKK nadal pozostaje odroczony zgodnie z `docs/129-pkk-deferred-pending-pwpw-guidance.md`.

## 6. Executable evidence

`FoundationReferenceCatalogTest` wymaga:

- dokładnie 16 aktywnych kategorii,
- dokładnego zestawu kodów,
- nieaktywnego `PT` sklasyfikowanego jako `tram_permit`,
- braku `PT` w produkcyjnym `ResourceCatalogService::drivingCategories()`.

Dzięki temu historyczny alias nie może przypadkiem zostać aktywowany jako legalna kategoria rule-engine.

## 7. Closure evidence

Hardening H2 is closed on accepted implementation commit:

- accepted commit: `f7c531748f0fad9508c6ff881cf551d11def552b`,
- accepted tree: `2478766f1deaf0237f63549f28060f728bcff6ea`,
- validation helper: `11e169ad41ce5f77f18dcba0d303cc54f857586a`,
- helper Implementation CI #251: **5/5 PASS**,
- helper API Contract Gate #302: **PASS**,
- accepted Implementation CI #252: **5/5 PASS**,
- accepted API Contract Gate #303: **PASS**,
- accepted PostgreSQL suite: **169 tests / 2614 assertions**,
- backend Pint + PHPStan: **PASS**,
- frontend lint + typecheck + build + audit: **PASS**,
- secret scan: **PASS**,
- changed-module traceability: **PASS**.

No Stage-4 database authority, training minima, age rules or provider-specific PKK behavior changed in this gate.

Next hardening gate: **privacy / retention schedule**.

